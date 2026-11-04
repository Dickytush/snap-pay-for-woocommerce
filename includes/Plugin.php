<?php
/**
 * Plugin bootstrap and service container.
 *
 * @package SnapPay
 */

declare(strict_types=1);

namespace SnapPay;

use SnapPay\Admin\Notices;
use SnapPay\Admin\SettlementController;
use SnapPay\Admin\Settings;
use SnapPay\Blocks\BlocksIntegration;
use SnapPay\Cron\PollJob;
use SnapPay\Email\DeliveryCodeAppender;
use SnapPay\Fx\Display as FxDisplay;
use SnapPay\Gateway\GatewayFilter;
use SnapPay\Support\Countries;

defined( 'ABSPATH' ) || exit;

/**
 * Wires every component together. Holds no business logic of its own.
 */
final class Plugin {

	public const OPTION_ENVIRONMENT   = 'snappay_environment';
	public const OPTION_API_KEY       = 'snappay_api_key';
	public const OPTION_WEBHOOK_SECRET = 'snappay_webhook_secret';
	public const OPTION_STORE_URL     = 'snappay_store_url';
	public const OPTION_VALIDATED     = 'snappay_connection_validated';
	public const OPTION_PROVIDERS     = 'snappay_enabled_providers';
	public const OPTION_MERCHANT      = 'snappay_merchant_snapshot';
	public const OPTION_DB_VERSION    = 'snappay_db_version';
	public const OPTION_LEGACY_UPGRADE = 'snappay_upgraded_from_v1';
	public const OPTION_API_BASE      = 'snappay_api_base_url';

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Shared API client.
	 *
	 * @var PlatformApiClient|null
	 */
	private ?PlatformApiClient $api = null;

	/**
	 * Shared order repository.
	 *
	 * @var OrderRepository|null
	 */
	private ?OrderRepository $orders = null;

	/**
	 * Boot once.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}

		return self::$instance;
	}

	/**
	 * Registers hooks. WooCommerce is required; without it the plugin stays inert
	 * apart from an admin notice.
	 */
	private function boot(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			( new Notices() )->register_missing_woocommerce();

			return;
		}

		$api    = $this->api();
		$orders = $this->orders();

		// Checkout surfaces.
		( new GatewayFilter( $this ) )->register();
		( new BlocksIntegration( $this ) )->register();
		( new FxDisplay( $this, $api ) )->register();

		// Inbound platform events.
		( new WebhookController( $this, $api, $orders ) )->register();

		// Merchant-facing admin.
		( new Settings( $this, $api ) )->register();
		( new SettlementController( $this, $api, $orders ) )->register();
		( new Notices() )->register();

		// Order email code append + drift repair.
		( new DeliveryCodeAppender( $this, $api, $orders ) )->register();
		( new PollJob( $this, $api, $orders ) )->register();

		do_action( 'snappay_loaded', $this );
	}

	/**
	 * Lazily built API client.
	 */
	public function api(): PlatformApiClient {
		if ( null === $this->api ) {
			$this->api = new PlatformApiClient( $this );
		}

		return $this->api;
	}

	/**
	 * Lazily built order repository.
	 */
	public function orders(): OrderRepository {
		if ( null === $this->orders ) {
			$this->orders = new OrderRepository();
		}

		return $this->orders;
	}

	/* ---------------------------------------------------------------------
	 * Configuration accessors
	 * ------------------------------------------------------------------ */

	/**
	 * Configured environment: 'test' or 'live'.
	 */
	public function environment(): string {
		return 'live' === get_option( self::OPTION_ENVIRONMENT, 'test' ) ? 'live' : 'test';
	}

	/**
	 * Merchant API key for the configured environment.
	 */
	public function api_key(): string {
		return (string) get_option( self::OPTION_API_KEY, '' );
	}

	/**
	 * Webhook signing secret.
	 */
	public function webhook_secret(): string {
		return (string) get_option( self::OPTION_WEBHOOK_SECRET, '' );
	}

	/**
	 * Canonical registered store URL.
	 *
	 * Read from settings, never from the Host header — a spoofed Host must not be
	 * able to redirect webhook routing (SECURITY.md section 6).
	 */
	public function store_url(): string {
		$stored = (string) get_option( self::OPTION_STORE_URL, '' );

		return '' !== $stored ? untrailingslashit( $stored ) : untrailingslashit( home_url() );
	}

	/**
	 * Site-level override for the API base URL, or '' to use the contract host.
	 *
	 * Stored per environment so a beta sandbox host cannot leak into live. Only ever
	 * settable by someone who can already edit plugin settings, and forced to HTTPS
	 * on save (PlatformApiClient refuses anything else anyway).
	 */
	public function api_base_override(): string {
		$stored = get_option( self::OPTION_API_BASE, array() );

		if ( ! is_array( $stored ) ) {
			return '';
		}

		$value = isset( $stored[ $this->environment() ] ) ? (string) $stored[ $this->environment() ] : '';

		return 0 === strpos( $value, 'https://' ) ? $value : '';
	}

	/**
	 * Provider IDs the merchant has enabled, as reported by the platform.
	 *
	 * @return string[]
	 */
	public function enabled_providers(): array {
		$stored = get_option( self::OPTION_PROVIDERS, array() );

		return is_array( $stored ) ? array_values( array_filter( array_map( 'strval', $stored ) ) ) : array();
	}

	/**
	 * True once the setup wizard has validated credentials against GET /merchant/wallet.
	 *
	 * ADR 0002 decision item 2: the gateway is not offered to customers until this
	 * is true. An unattended auto-update therefore fails closed, not open.
	 */
	public function is_connected(): bool {
		if ( '' === $this->api_key() || '' === $this->webhook_secret() ) {
			return false;
		}

		return 'yes' === get_option( self::OPTION_VALIDATED, 'no' );
	}

	/**
	 * Whether the configured key prefix matches the configured environment.
	 *
	 * Fail-closed check performed before any HTTP call (SECURITY.md section 2).
	 */
	public function key_matches_environment(): bool {
		$key = $this->api_key();

		if ( '' === $key ) {
			return false;
		}

		return 'live' === $this->environment()
			? 0 === strpos( $key, 'sk_live_' )
			: 0 === strpos( $key, 'sk_test_' );
	}

	/**
	 * Marks the connection unvalidated. Called whenever credentials or environment change.
	 */
	public function invalidate_connection(): void {
		update_option( self::OPTION_VALIDATED, 'no' );
	}

	/**
	 * Store currency, e.g. 'KES'.
	 */
	public function store_currency(): string {
		return strtoupper( get_woocommerce_currency() );
	}

	/**
	 * Store country, e.g. 'KE'.
	 */
	public function store_country(): string {
		$base = wc_get_base_location();

		return isset( $base['country'] ) ? strtoupper( (string) $base['country'] ) : 'KE';
	}

	/* ---------------------------------------------------------------------
	 * Lifecycle
	 * ------------------------------------------------------------------ */

	/**
	 * Activation: detect a v1 upgrade and fail closed until re-validated.
	 *
	 * ADR 0002 decision item 1.
	 */
	public static function activate(): void {
		$previous = get_option( self::OPTION_DB_VERSION, '' );

		// v1 stored its credentials under these keys and had no escrow concept.
		$looks_like_v1 = '' === $previous
			&& ( false !== get_option( 'woocommerce_snappay_settings', false ) || false !== get_option( 'snappay_api_secret', false ) );

		if ( $looks_like_v1 ) {
			update_option( self::OPTION_LEGACY_UPGRADE, 'yes' );
		}

		// Any activation forces re-validation before checkout renders.
		update_option( self::OPTION_VALIDATED, 'no' );
		update_option( self::OPTION_DB_VERSION, SNAPPAY_VERSION );

		PollJob::schedule();
	}

	/**
	 * Deactivation: clear our cron only. Platform state is untouched — deactivating
	 * never strands funds (ARCHITECTURE.md invariant 4).
	 */
	public static function deactivate(): void {
		PollJob::unschedule();
	}

	/**
	 * Country/currency/provider reference data.
	 */
	public function countries(): Countries {
		return new Countries();
	}

	/**
	 * URL of a bundled asset.
	 *
	 * Deliberately resolved per call via plugins_url() rather than read from the
	 * SNAPPAY_PLUGIN_URL constant. The constant is computed once when the plugin
	 * file loads, so it freezes whatever scheme is_ssl() reported at that moment.
	 * On a site behind a reverse proxy or CDN — where HTTPS is terminated upstream
	 * and is_ssl() can read false on a genuinely secure request — that bakes an
	 * http:// URL into every asset, and the browser then blocks them as mixed
	 * content on an https checkout. plugins_url() is evaluated per request and
	 * respects the filters proxy-aware plugins install.
	 *
	 * @param string $relative Path relative to the plugin root, e.g. 'assets/js/blocks.js'.
	 */
	public static function asset_url( string $relative ): string {
		return plugins_url( ltrim( $relative, '/' ), SNAPPAY_PLUGIN_FILE );
	}
}
