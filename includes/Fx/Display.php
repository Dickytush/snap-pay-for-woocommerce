<?php
/**
 * Customer country detection and checkout FX rate locking.
 *
 * @package SnapPay
 */

declare(strict_types=1);

namespace SnapPay\Fx;

use SnapPay\Http\ApiException;
use SnapPay\PlatformApiClient;
use SnapPay\Plugin;
use SnapPay\Support\Countries;
use SnapPay\Support\Logger;
use SnapPay\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Shows the customer what they will pay in their own currency, and locks the rate
 * before payment so the amount cannot move underneath them.
 *
 * Scope note
 * ----------
 * Product-page and cart local-currency display needs a 5-minute-cached display rate
 * from GET /merchant/fx/display-rate. That endpoint is referenced by
 * END-TO-END-FLOW.md but is NOT in the 10-endpoint contract in PLATFORM-API.md, and
 * OPEN-QUESTIONS.md B1 marks it "blocking build - do not implement until resolved".
 *
 * So: the checkout rate lock below is fully implemented against the documented
 * POST /merchant/fx/lock-rate. Product/cart display is written but held behind
 * snappay_enable_display_rate (default false) and will stay dark until B1 is answered.
 */
final class Display {

	public const SESSION_COUNTRY   = 'snappay_customer_country';
	public const SESSION_LOCK_ID   = 'snappay_fx_lock_id';
	public const SESSION_RATE      = 'snappay_fx_rate';
	public const SESSION_EXPIRES   = 'snappay_fx_expires';
	public const SESSION_CURRENCY  = 'snappay_display_currency';
	public const SESSION_AMOUNT    = 'snappay_display_amount';
	public const SESSION_BASIS     = 'snappay_fx_lock_basis_minor';

	/**
	 * Plugin configuration.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * API client.
	 *
	 * @var PlatformApiClient
	 */
	private PlatformApiClient $api;

	/**
	 * @param Plugin            $plugin Configuration.
	 * @param PlatformApiClient $api    API client.
	 */
	public function __construct( Plugin $plugin, PlatformApiClient $api ) {
		$this->plugin = $plugin;
		$this->api    = $api;
	}

	/**
	 * Hooks in.
	 */
	public function register(): void {
		add_action( 'wp', array( $this, 'detect_customer_country' ) );

		// Checkout: lock the rate once totals are final, and render the locked figures.
		add_action( 'woocommerce_review_order_before_order_total', array( $this, 'render_checkout_fx' ), 10 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_checkout_assets' ) );

		// Blocks checkout asks for the lock over REST.
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		// Held behind B1 until the display-rate endpoint is agreed.
		if ( $this->display_rate_enabled() ) {
			add_filter( 'woocommerce_get_price_html', array( $this, 'display_local_price' ), 10, 2 );
		}
	}

	/* ---------------------------------------------------------------------
	 * Country detection (three tiers)
	 * ------------------------------------------------------------------ */

	/**
	 * Resolves the customer's country as early as possible.
	 *
	 * Priority: saved/entered billing country, then the session from an earlier page,
	 * then GeoIP, then browser language. The billing country always wins later at
	 * checkout — GeoIP is only ever an opening guess for display
	 * (CUSTOMER-COUNTRY-DETECTION.md section 3.1).
	 */
	public function detect_customer_country(): void {
		if ( is_admin() || ! function_exists( 'WC' ) || null === WC()->session || null === WC()->customer ) {
			return;
		}

		$billing = strtoupper( (string) WC()->customer->get_billing_country() );

		if ( '' !== $billing ) {
			WC()->session->set( self::SESSION_COUNTRY, $billing );

			return;
		}

		if ( '' !== (string) WC()->session->get( self::SESSION_COUNTRY, '' ) ) {
			return;
		}

		$supported = Countries::supported_countries();

		if ( class_exists( 'WC_Geolocation' ) ) {
			$geo     = \WC_Geolocation::geolocate_ip();
			$country = isset( $geo['country'] ) ? strtoupper( (string) $geo['country'] ) : '';

			if ( '' !== $country && in_array( $country, $supported, true ) ) {
				// Only the ISO country code is stored — no IP, no city (SECURITY.md section 5).
				WC()->session->set( self::SESSION_COUNTRY, $country );

				return;
			}
		}

		$header = isset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) : '';
		$lang   = strtolower( substr( $header, 0, 2 ) );
		$map    = (array) apply_filters( 'snappay_language_country_map', array( 'sw' => 'KE', 'lg' => 'UG' ) );

		if ( isset( $map[ $lang ] ) && in_array( (string) $map[ $lang ], $supported, true ) ) {
			WC()->session->set( self::SESSION_COUNTRY, (string) $map[ $lang ] );
		}
	}

	/**
	 * The customer's resolved country, or '' when unknown.
	 */
	public static function customer_country(): string {
		if ( ! function_exists( 'WC' ) || null === WC()->customer ) {
			return '';
		}

		$billing = strtoupper( (string) WC()->customer->get_billing_country() );

		if ( '' !== $billing ) {
			return $billing;
		}

		return null !== WC()->session ? strtoupper( (string) WC()->session->get( self::SESSION_COUNTRY, '' ) ) : '';
	}

	/**
	 * The currency the customer should be charged in, or null when it matches the store.
	 */
	public function target_currency(): ?string {
		$country = self::customer_country();

		if ( '' === $country ) {
			return null;
		}

		$currency = Countries::currency_for_country( $country );

		if ( null === $currency || $currency === $this->plugin->store_currency() ) {
			return null;
		}

		return $currency;
	}

	/* ---------------------------------------------------------------------
	 * Checkout rate lock
	 * ------------------------------------------------------------------ */

	/**
	 * Locks a rate for the current cart total, reusing a live lock when one exists.
	 *
	 * Timing decision
	 * ---------------
	 * The source docs sketch four different lock triggers (add-to-cart,
	 * before_calculate_totals, before_checkout_form, and on "Place Order"). We lock at
	 * checkout render, once shipping and taxes are in the total, and re-lock whenever
	 * the store-currency total changes. Locking earlier would quote a rate against a
	 * total that shipping is about to change, so the customer would not pay the exact
	 * amount they were shown - which is the whole point of the lock.
	 *
	 * @return array{lock_id: string, rate: string, currency: string, amount_minor: int, expires_at: string}|null
	 */
	public function ensure_lock(): ?array {
		$target = $this->target_currency();

		if ( null === $target || ! $this->plugin->is_connected() ) {
			return null;
		}

		if ( ! function_exists( 'WC' ) || null === WC()->cart || null === WC()->session ) {
			return null;
		}

		$store_currency = $this->plugin->store_currency();
		$basis_minor    = Money::to_minor( WC()->cart->get_total( 'edit' ), $store_currency );

		if ( $basis_minor <= 0 ) {
			return null;
		}

		$existing = self::current_lock();

		// Reuse only if the lock is live, for this currency, and for this exact total.
		if ( null !== $existing
			&& $existing['currency'] === $target
			&& (int) WC()->session->get( self::SESSION_BASIS, 0 ) === $basis_minor
		) {
			return $existing;
		}

		try {
			$lock = $this->api->lock_fx_rate( $store_currency, $target, $basis_minor );
		} catch ( ApiException $e ) {
			// FX unavailable is not fatal: the customer simply sees store currency
			// (CUSTOMER-COUNTRY-DETECTION.md section 10).
			Logger::warning( 'FX rate lock failed', array( 'error' => $e->error_code(), 'from' => $store_currency, 'to' => $target ) );
			self::clear_lock();

			return null;
		}

		$lock_id    = (string) ( $lock['lock_id'] ?? '' );
		$rate       = (string) ( $lock['rate'] ?? '' );
		$converted  = (int) ( $lock['converted_amount_minor'] ?? 0 );
		$expires_at = (string) ( $lock['expires_at'] ?? '' );

		if ( '' === $lock_id || '' === $rate || $converted <= 0 ) {
			return null;
		}

		WC()->session->set( self::SESSION_LOCK_ID, $lock_id );
		WC()->session->set( self::SESSION_RATE, $rate );
		WC()->session->set( self::SESSION_EXPIRES, $expires_at );
		WC()->session->set( self::SESSION_CURRENCY, $target );
		WC()->session->set( self::SESSION_AMOUNT, $converted );
		WC()->session->set( self::SESSION_BASIS, $basis_minor );

		do_action( 'snappay_fx_rate_locked', null, $lock_id, $rate, $expires_at );

		return array(
			'lock_id'      => $lock_id,
			'rate'         => $rate,
			'currency'     => $target,
			'amount_minor' => $converted,
			'expires_at'   => $expires_at,
		);
	}

	/**
	 * The current session lock, or null when absent or expired.
	 *
	 * @return array{lock_id: string, rate: string, currency: string, amount_minor: int, expires_at: string}|null
	 */
	public static function current_lock(): ?array {
		if ( ! function_exists( 'WC' ) || null === WC()->session ) {
			return null;
		}

		$lock_id = (string) WC()->session->get( self::SESSION_LOCK_ID, '' );

		if ( '' === $lock_id ) {
			return null;
		}

		$expires_at = (string) WC()->session->get( self::SESSION_EXPIRES, '' );

		if ( '' !== $expires_at && strtotime( $expires_at ) < time() ) {
			self::clear_lock();

			return null;
		}

		return array(
			'lock_id'      => $lock_id,
			'rate'         => (string) WC()->session->get( self::SESSION_RATE, '' ),
			'currency'     => (string) WC()->session->get( self::SESSION_CURRENCY, '' ),
			'amount_minor' => (int) WC()->session->get( self::SESSION_AMOUNT, 0 ),
			'expires_at'   => $expires_at,
		);
	}

	/**
	 * Reads a raw session value.
	 *
	 * @return mixed
	 */
	public static function session_get( string $key ) {
		if ( ! function_exists( 'WC' ) || null === WC()->session ) {
			return '';
		}

		return WC()->session->get( $key, '' );
	}

	/**
	 * Drops the lock from the session. Called after a successful collect and whenever
	 * the platform reports the lock expired.
	 */
	public static function clear_lock(): void {
		if ( ! function_exists( 'WC' ) || null === WC()->session ) {
			return;
		}

		foreach ( array( self::SESSION_LOCK_ID, self::SESSION_RATE, self::SESSION_EXPIRES, self::SESSION_CURRENCY, self::SESSION_AMOUNT, self::SESSION_BASIS ) as $key ) {
			WC()->session->set( $key, null );
		}
	}

	/* ---------------------------------------------------------------------
	 * Checkout rendering
	 * ------------------------------------------------------------------ */

	/**
	 * Renders the locked total and rate rows in the classic checkout totals table.
	 */
	public function render_checkout_fx(): void {
		if ( ! is_checkout() ) {
			return;
		}

		$lock = $this->ensure_lock();

		if ( null === $lock ) {
			return;
		}

		$remaining = max( 0, (int) ( strtotime( $lock['expires_at'] ) - time() ) );

		printf(
			'<tr class="snappay-fx-total"><th>%1$s</th><td data-snappay-fx-amount="1"><strong>%2$s</strong></td></tr>',
			esc_html(
				sprintf(
					/* translators: %s: customer's local currency code. */
					__( 'You will pay (%s)', 'snap-pay-for-woocommerce' ),
					$lock['currency']
				)
			),
			wp_kses_post( Money::format( $lock['amount_minor'], $lock['currency'] ) )
		);

		printf(
			'<tr class="snappay-fx-rate"><th>%1$s</th><td>%2$s<br><small class="snappay-fx-countdown" data-snappay-fx-expires="%3$d">%4$s</small></td></tr>',
			esc_html__( 'Exchange rate', 'snap-pay-for-woocommerce' ),
			esc_html(
				sprintf(
					'1 %s = %s %s',
					$this->plugin->store_currency(),
					number_format( (float) $lock['rate'], 2 ),
					$lock['currency']
				)
			),
			absint( $remaining ),
			esc_html(
				sprintf(
					/* translators: %s: mm:ss countdown. */
					__( 'Rate locked for %s', 'snap-pay-for-woocommerce' ),
					gmdate( 'i:s', $remaining )
				)
			)
		);
	}

	/**
	 * Front-end countdown script, checkout only.
	 */
	public function enqueue_checkout_assets(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}

		wp_enqueue_style(
			'snappay-checkout',
			Plugin::asset_url( 'assets/css/checkout.css' ),
			array(),
			SNAPPAY_VERSION
		);

		wp_enqueue_script(
			'snappay-checkout',
			Plugin::asset_url( 'assets/js/checkout.js' ),
			array(),
			SNAPPAY_VERSION,
			true
		);

		wp_localize_script(
			'snappay-checkout',
			'SnapPayCheckout',
			array(
				// No secrets are ever localized to the browser (SECURITY.md section 1).
				'expiredMessage' => __( 'Rate expired - refresh for a new rate', 'snap-pay-for-woocommerce' ),
				'lockedPrefix'   => __( 'Rate locked for', 'snap-pay-for-woocommerce' ),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * REST (Blocks checkout)
	 * ------------------------------------------------------------------ */

	/**
	 * Registers the Blocks-facing lock endpoint.
	 */
	public function register_rest_routes(): void {
		register_rest_route(
			'snappay/v1',
			'/fx-lock',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_lock_rate' ),
				'permission_callback' => static function (): bool {
					// Front-end customer route: no capability, but the WooCommerce Store
					// API nonce must be present, so it cannot be driven cross-site.
					return wp_verify_nonce(
						sanitize_text_field( wp_unslash( (string) ( $_SERVER['HTTP_X_WP_NONCE'] ?? '' ) ) ),
						'wp_rest'
					) > 0;
				},
			)
		);
	}

	/**
	 * Returns the current lock for the Blocks checkout to display.
	 *
	 * @return \WP_REST_Response
	 */
	public function rest_lock_rate() {
		$lock = $this->ensure_lock();

		if ( null === $lock ) {
			return new \WP_REST_Response( array( 'locked' => false ), 200 );
		}

		return new \WP_REST_Response(
			array(
				'locked'       => true,
				'currency'     => $lock['currency'],
				'rate'         => $lock['rate'],
				'amountMinor'  => $lock['amount_minor'],
				'amountLabel'  => wp_strip_all_tags( Money::format( $lock['amount_minor'], $lock['currency'] ) ),
				'expiresIn'    => max( 0, (int) ( strtotime( $lock['expires_at'] ) - time() ) ),
				'storeCurrency' => $this->plugin->store_currency(),
			),
			200
		);
	}

	/* ---------------------------------------------------------------------
	 * Product-page display (gated on OPEN-QUESTIONS B1)
	 * ------------------------------------------------------------------ */

	/**
	 * Whether product/cart local-currency display is switched on.
	 *
	 * Default false. Turning this on requires GET /merchant/fx/display-rate to exist
	 * in PLATFORM-API.md first (OPEN-QUESTIONS.md B1).
	 */
	public function display_rate_enabled(): bool {
		return (bool) apply_filters( 'snappay_enable_display_rate', false );
	}

	/**
	 * Appends the local-currency price to the store-currency price.
	 *
	 * Only reachable when display_rate_enabled() is true. Falls back silently to the
	 * store price whenever a rate is not available.
	 *
	 * @param string      $price_html Rendered price HTML.
	 * @param \WC_Product $product    Product being rendered.
	 */
	public function display_local_price( $price_html, $product ): string {
		$price_html = (string) $price_html;

		if ( ! $product instanceof \WC_Product ) {
			return $price_html;
		}

		$target = $this->target_currency();
		$rate   = $this->cached_display_rate();

		if ( null === $target || null === $rate ) {
			return $price_html;
		}

		$store_currency = $this->plugin->store_currency();
		$store_minor    = Money::to_minor( (float) $product->get_price(), $store_currency );

		if ( $store_minor <= 0 ) {
			return $price_html;
		}

		$local_minor = (int) round( $store_minor * $rate );

		return sprintf(
			'<span class="snappay-local-price">%1$s</span> <span class="snappay-store-price">~ %2$s</span><br><small class="snappay-rate-note">%3$s</small>',
			wp_kses_post( Money::format( $local_minor, $target ) ),
			wp_kses_post( $price_html ),
			esc_html( sprintf( '1 %s = %s %s', $store_currency, number_format( $rate, 2 ), $target ) )
		);
	}

	/**
	 * Five-minute cached display rate.
	 *
	 * Returns null until B1 is resolved and a real fetch is wired in here. The cache
	 * key and TTL are already the documented ones so only the fetch call needs adding.
	 */
	private function cached_display_rate(): ?float {
		$target = $this->target_currency();

		if ( null === $target ) {
			return null;
		}

		$key    = sprintf( 'snappay_fx_display:%s:%s', $this->plugin->store_currency(), $target );
		$cached = get_transient( $key );

		if ( is_numeric( $cached ) ) {
			return (float) $cached;
		}

		/*
		 * Intentionally not implemented: GET /merchant/fx/display-rate is not part of
		 * the 10-endpoint contract yet (OPEN-QUESTIONS.md B1, "do not implement until
		 * resolved"). When it lands, fetch here and:
		 *   set_transient( $key, $rate, (int) apply_filters( 'snappay_fx_display_cache_duration', 300 ) );
		 */
		return null;
	}
}
