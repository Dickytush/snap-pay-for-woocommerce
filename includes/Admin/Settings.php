<?php
/**
 * Setup wizard and connection settings.
 *
 * @package SnapPay
 */

declare(strict_types=1);

namespace SnapPay\Admin;

use SnapPay\Http\ApiException;
use SnapPay\PlatformApiClient;
use SnapPay\Plugin;
use SnapPay\Support\Countries;
use SnapPay\Support\Logger;
use SnapPay\Support\Money;
use SnapPay\WebhookController;

defined( 'ABSPATH' ) || exit;

/**
 * The single place credentials are entered, and the connection gate that decides
 * whether Snap Pay renders at checkout at all.
 *
 * Until "Validate and connect" succeeds against GET /merchant/wallet, no gateway is
 * offered. An unattended major-version auto-update therefore fails closed
 * (ADR 0002 decision item 2).
 */
final class Settings {

	private const PAGE_SLUG   = 'snappay-settings';
	private const NONCE_SAVE  = 'snappay_save_settings';

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
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_snappay_save_settings', array( $this, 'handle_save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SNAPPAY_PLUGIN_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Adds the WooCommerce submenu entry.
	 */
	public function add_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Snap Pay', 'snap-pay-for-woocommerce' ),
			__( 'Snap Pay', 'snap-pay-for-woocommerce' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Adds a Settings link on the Plugins screen.
	 *
	 * @param array<int, string> $links Existing links.
	 *
	 * @return array<int, string>
	 */
	public function action_links( array $links ): array {
		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ),
				esc_html__( 'Settings', 'snap-pay-for-woocommerce' )
			)
		);

		return $links;
	}

	/**
	 * Loads admin styles on our screens.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue( $hook ): void {
		if ( ! is_string( $hook ) ) {
			return;
		}

		if ( false === strpos( $hook, self::PAGE_SLUG ) && 'shop_order' !== get_post_type() && false === strpos( $hook, 'wc-orders' ) ) {
			return;
		}

		wp_enqueue_style( 'snappay-admin', Plugin::asset_url( 'assets/css/admin.css' ), array(), SNAPPAY_VERSION );
	}

	/* ---------------------------------------------------------------------
	 * Save
	 * ------------------------------------------------------------------ */

	/**
	 * Persists settings, then re-validates the connection.
	 */
	public function handle_save(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to change Snap Pay settings.', 'snap-pay-for-woocommerce' ), 403 );
		}

		check_admin_referer( self::NONCE_SAVE );

		$environment = isset( $_POST['snappay_environment'] ) && 'live' === $_POST['snappay_environment'] ? 'live' : 'test';
		$store_url   = isset( $_POST['snappay_store_url'] ) ? esc_url_raw( wp_unslash( (string) $_POST['snappay_store_url'] ) ) : '';
		$api_key     = isset( $_POST['snappay_api_key'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['snappay_api_key'] ) ) : '';
		$secret      = isset( $_POST['snappay_webhook_secret'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['snappay_webhook_secret'] ) ) : '';

		$providers = array();

		if ( isset( $_POST['snappay_providers'] ) && is_array( $_POST['snappay_providers'] ) ) {
			$submitted = array_map( 'sanitize_text_field', wp_unslash( $_POST['snappay_providers'] ) );
			$providers = array_values( array_intersect( $submitted, array_keys( Countries::providers() ) ) );
		}

		update_option( Plugin::OPTION_ENVIRONMENT, $environment );
		update_option( Plugin::OPTION_PROVIDERS, $providers );

		// API base URL, stored per environment so a beta sandbox host can never be
		// inherited by live. Blank clears the override and restores the contract host.
		$bases = get_option( Plugin::OPTION_API_BASE, array() );
		$bases = is_array( $bases ) ? $bases : array();

		$submitted_base = isset( $_POST['snappay_api_base_url'] )
			? esc_url_raw( trim( sanitize_text_field( wp_unslash( (string) $_POST['snappay_api_base_url'] ) ) ) )
			: '';

		if ( '' === $submitted_base ) {
			unset( $bases[ $environment ] );
		} elseif ( 0 === strpos( $submitted_base, 'https://' ) ) {
			$bases[ $environment ] = trailingslashit( $submitted_base );
		}

		update_option( Plugin::OPTION_API_BASE, $bases );

		if ( '' !== $store_url ) {
			update_option( Plugin::OPTION_STORE_URL, untrailingslashit( $store_url ) );
		}

		// Blank means "leave unchanged": credentials are write-only and are never
		// echoed back into the form (SECURITY.md section 1).
		if ( '' !== $api_key ) {
			update_option( Plugin::OPTION_API_KEY, $api_key );
		}

		if ( '' !== $secret ) {
			update_option( Plugin::OPTION_WEBHOOK_SECRET, $secret );
		}

		// Any credential or environment change forces re-validation before checkout.
		$this->plugin->invalidate_connection();

		$result = $this->validate_connection();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'             => self::PAGE_SLUG,
					'snappay_notice'   => $result['ok'] ? 'connected' : 'failed',
					'snappay_message'  => rawurlencode( $result['message'] ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Calls GET /merchant/wallet and records the outcome.
	 *
	 * @return array{ok: bool, message: string}
	 */
	private function validate_connection(): array {
		if ( ! $this->plugin->key_matches_environment() ) {
			return array(
				'ok'      => false,
				'message' => __( 'The API key prefix does not match the selected environment. Test keys start with sk_test_ and live keys with sk_live_.', 'snap-pay-for-woocommerce' ),
			);
		}

		try {
			$wallet = $this->api->get_wallet();
		} catch ( ApiException $e ) {
			Logger::warning( 'Connection validation failed', array( 'error' => $e->error_code(), 'status' => $e->status() ) );

			return array( 'ok' => false, 'message' => $e->getMessage() );
		}

		update_option( Plugin::OPTION_VALIDATED, 'yes' );
		update_option( Plugin::OPTION_MERCHANT, $this->safe_wallet_snapshot( $wallet ) );
		delete_option( Plugin::OPTION_LEGACY_UPGRADE );

		return array( 'ok' => true, 'message' => __( 'Connected to Snap Pay.', 'snap-pay-for-woocommerce' ) );
	}

	/**
	 * Keeps only non-sensitive wallet fields for display.
	 *
	 * @param array<string, mixed> $wallet Wallet response.
	 *
	 * @return array<string, mixed>
	 */
	private function safe_wallet_snapshot( array $wallet ): array {
		$keep = array(
			'available_balance_minor',
			'actual_balance_minor',
			'reserve_minor',
			'withdrawable_minor',
			'currency',
			'held_orders_count',
			'last_updated',
		);

		$snapshot = array();

		foreach ( $keep as $key ) {
			if ( isset( $wallet[ $key ] ) ) {
				$snapshot[ $key ] = $wallet[ $key ];
			}
		}

		if ( isset( $wallet['fee_schedule'] ) && is_array( $wallet['fee_schedule'] ) ) {
			$snapshot['fee_schedule'] = $wallet['fee_schedule'];
		}

		return $snapshot;
	}

	/* ---------------------------------------------------------------------
	 * Render
	 * ------------------------------------------------------------------ */

	/**
	 * Renders the settings screen.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice rendering.
		$notice = isset( $_GET['snappay_notice'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['snappay_notice'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only notice rendering; the value is URL-decoded and then passed through sanitize_text_field().
		$message = isset( $_GET['snappay_message'] ) ? sanitize_text_field( rawurldecode( wp_unslash( (string) $_GET['snappay_message'] ) ) ) : '';

		$environment = $this->plugin->environment();
		$connected   = $this->plugin->is_connected();
		$providers   = $this->plugin->enabled_providers();
		$webhook_url = rest_url( WebhookController::REST_NAMESPACE . '/' . WebhookController::REST_ROUTE );

		echo '<div class="wrap snappay-settings">';
		echo '<h1>' . esc_html__( 'Snap Pay', 'snap-pay-for-woocommerce' ) . '</h1>';

		if ( 'connected' === $notice ) {
			echo '<div class="notice notice-success"><p>' . esc_html( $message ) . '</p></div>';
		} elseif ( 'failed' === $notice ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
		}

		if ( 'yes' === get_option( Plugin::OPTION_LEGACY_UPGRADE, 'no' ) ) {
			echo '<div class="notice notice-warning"><p>';
			echo esc_html__( 'This is a major upgrade. Snap Pay now holds payments in escrow until the customer confirms delivery with a code. Checkout stays switched off until you re-enter your credentials below and connect.', 'snap-pay-for-woocommerce' );
			echo '</p></div>';
		}

		printf(
			'<p class="snappay-status %1$s"><strong>%2$s</strong> %3$s</p>',
			esc_attr( $connected ? 'is-connected' : 'is-disconnected' ),
			esc_html( $connected ? __( 'Connected.', 'snap-pay-for-woocommerce' ) : __( 'Not connected.', 'snap-pay-for-woocommerce' ) ),
			esc_html(
				$connected
					? sprintf(
						/* translators: %s: environment name. */
						__( 'Running in %s mode.', 'snap-pay-for-woocommerce' ),
						'live' === $environment ? __( 'live', 'snap-pay-for-woocommerce' ) : __( 'test', 'snap-pay-for-woocommerce' )
					)
					: __( 'Snap Pay payment methods are hidden at checkout until you connect.', 'snap-pay-for-woocommerce' )
			)
		);

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE_SAVE );
		echo '<input type="hidden" name="action" value="snappay_save_settings" />';

		echo '<table class="form-table" role="presentation"><tbody>';

		// Environment.
		echo '<tr><th scope="row">' . esc_html__( 'Environment', 'snap-pay-for-woocommerce' ) . '</th><td>';
		printf(
			'<label><input type="radio" name="snappay_environment" value="test" %s /> %s</label><br />',
			checked( 'test', $environment, false ),
			esc_html__( 'Test (sandbox) - keys start with sk_test_', 'snap-pay-for-woocommerce' )
		);
		printf(
			'<label><input type="radio" name="snappay_environment" value="live" %s /> %s</label>',
			checked( 'live', $environment, false ),
			esc_html__( 'Live - keys start with sk_live_. Real money moves.', 'snap-pay-for-woocommerce' )
		);
		echo '<p class="description">' . esc_html__( 'In test mode the delivery code is always TEST0000, the fee is 1.0% and the reserve is 5.0%.', 'snap-pay-for-woocommerce' ) . '</p>';
		echo '</td></tr>';

		// Credentials (write-only).
		$this->render_secret_row(
			'snappay_api_key',
			__( 'API key', 'snap-pay-for-woocommerce' ),
			'' !== $this->plugin->api_key(),
			__( 'From Snap Pay dashboard: Channels, E-commerce, WooCommerce. Shown once at generation.', 'snap-pay-for-woocommerce' )
		);

		$this->render_secret_row(
			'snappay_webhook_secret',
			__( 'Webhook secret', 'snap-pay-for-woocommerce' ),
			'' !== $this->plugin->webhook_secret(),
			__( 'Used to verify that events really came from Snap Pay.', 'snap-pay-for-woocommerce' )
		);

		// Store URL.
		printf(
			'<tr><th scope="row"><label for="snappay_store_url">%1$s</label></th><td>
			<input type="url" class="regular-text code" id="snappay_store_url" name="snappay_store_url" value="%2$s" />
			<p class="description">%3$s</p></td></tr>',
			esc_html__( 'Registered store URL', 'snap-pay-for-woocommerce' ),
			esc_attr( $this->plugin->store_url() ),
			esc_html__( 'Must match the store URL you registered in the Snap Pay dashboard.', 'snap-pay-for-woocommerce' )
		);

		// Corridors.
		echo '<tr><th scope="row">' . esc_html__( 'Payment methods', 'snap-pay-for-woocommerce' ) . '</th><td>';

		foreach ( Countries::providers() as $provider_id => $definition ) {
			printf(
				'<label><input type="checkbox" name="snappay_providers[]" value="%1$s" %2$s /> %3$s (%4$s)</label><br />',
				esc_attr( (string) $provider_id ),
				checked( in_array( (string) $provider_id, $providers, true ), true, false ),
				esc_html( $definition['label'] ),
				esc_html( $definition['country'] )
			);
		}

		echo '<p class="description">' . esc_html__( 'Enable only the corridors Snap Pay has activated for your account. A method is shown to a customer only when their billing country matches.', 'snap-pay-for-woocommerce' ) . '</p>';
		echo '</td></tr>';

		// Webhook URL (read-only).
		printf(
			'<tr><th scope="row">%1$s</th><td><code class="snappay-webhook-url">%2$s</code><p class="description">%3$s</p></td></tr>',
			esc_html__( 'Webhook URL', 'snap-pay-for-woocommerce' ),
			esc_html( $webhook_url ),
			esc_html__( 'Register this URL in the Snap Pay dashboard. It must be HTTPS.', 'snap-pay-for-woocommerce' )
		);

		// API base URL. Always shows the URL actually in use, so a mis-pointed site is
		// diagnosable from this screen alone.
		$api            = $this->api;
		$effective_base = $api->base_url();
		$locked         = defined( 'SNAPPAY_API_BASE_URL' );

		printf(
			'<tr><th scope="row"><label for="snappay_api_base_url">%1$s</label></th><td>
			<input type="url" class="regular-text code" id="snappay_api_base_url" name="snappay_api_base_url" value="%2$s" placeholder="%3$s" %4$s />
			<p class="description">%5$s</p>
			<p class="description"><strong>%6$s</strong> <code>%7$s</code>%8$s</p></td></tr>',
			esc_html__( 'API base URL', 'snap-pay-for-woocommerce' ),
			esc_attr( $locked ? '' : $this->plugin->api_base_override() ),
			esc_attr( $api->default_base_url() ),
			$locked ? 'disabled' : '',
			esc_html__( 'Leave blank to use the standard Snap Pay endpoint. Set this only if Snap Pay support gave you a different address, for example during a beta.', 'snap-pay-for-woocommerce' ),
			esc_html__( 'Currently using:', 'snap-pay-for-woocommerce' ),
			esc_html( $effective_base ),
			$locked
				? ' ' . esc_html__( '(fixed by SNAPPAY_API_BASE_URL in wp-config.php)', 'snap-pay-for-woocommerce' )
				: ( $api->is_using_custom_base() ? ' ' . esc_html__( '(custom)', 'snap-pay-for-woocommerce' ) : '' )
		);

		echo '</tbody></table>';

		submit_button( __( 'Save and connect', 'snap-pay-for-woocommerce' ) );
		echo '</form>';

		$this->render_wallet();

		echo '</div>';
	}

	/**
	 * Renders a write-only credential field.
	 *
	 * The stored value is never sent back to the browser; an empty submission leaves
	 * it unchanged.
	 */
	private function render_secret_row( string $name, string $label, bool $has_value, string $description ): void {
		printf(
			'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td>
			<input type="password" class="regular-text code" id="%1$s" name="%1$s" value="" autocomplete="new-password" placeholder="%3$s" />
			<p class="description">%4$s</p></td></tr>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr(
				$has_value
					? __( 'Saved - leave blank to keep the current value', 'snap-pay-for-woocommerce' )
					: __( 'Not set', 'snap-pay-for-woocommerce' )
			),
			esc_html( $description )
		);
	}

	/**
	 * Renders the read-only wallet summary captured at connection time.
	 */
	private function render_wallet(): void {
		$snapshot = get_option( Plugin::OPTION_MERCHANT, array() );

		if ( ! is_array( $snapshot ) || array() === $snapshot ) {
			return;
		}

		$currency = isset( $snapshot['currency'] ) ? (string) $snapshot['currency'] : $this->plugin->store_currency();

		echo '<h2>' . esc_html__( 'Wallet', 'snap-pay-for-woocommerce' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Read-only snapshot from Snap Pay, taken when you last connected. Withdrawals are made from the Snap Pay dashboard.', 'snap-pay-for-woocommerce' ) . '</p>';
		echo '<table class="widefat striped snappay-wallet"><tbody>';

		$rows = array(
			'available_balance_minor' => __( 'Held in escrow (gross)', 'snap-pay-for-woocommerce' ),
			'actual_balance_minor'    => __( 'Settled balance', 'snap-pay-for-woocommerce' ),
			'reserve_minor'           => __( 'Reserve held', 'snap-pay-for-woocommerce' ),
			'withdrawable_minor'      => __( 'Withdrawable now', 'snap-pay-for-woocommerce' ),
		);

		foreach ( $rows as $key => $label ) {
			if ( ! isset( $snapshot[ $key ] ) ) {
				continue;
			}

			printf(
				'<tr><th scope="row">%1$s</th><td>%2$s</td></tr>',
				esc_html( $label ),
				wp_kses_post( Money::format( (int) $snapshot[ $key ], $currency ) )
			);
		}

		if ( isset( $snapshot['fee_schedule']['collection_fee_percent'], $snapshot['fee_schedule']['reserve_percent'] ) ) {
			printf(
				'<tr><th scope="row">%1$s</th><td>%2$s</td></tr>',
				esc_html__( 'Current rates', 'snap-pay-for-woocommerce' ),
				esc_html(
					sprintf(
						/* translators: 1: collection fee percent, 2: reserve percent. */
						__( '%1$s%% collection fee, %2$s%% reserve held 30 days', 'snap-pay-for-woocommerce' ),
						(string) $snapshot['fee_schedule']['collection_fee_percent'],
						(string) $snapshot['fee_schedule']['reserve_percent']
					)
				)
			);
		}

		echo '</tbody></table>';
	}
}
