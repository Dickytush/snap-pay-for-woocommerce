<?php
/**
 * Gateway registration and country filtering.
 *
 * @package SnapPay
 */

declare(strict_types=1);

namespace SnapPay\Gateway;

use SnapPay\Plugin;
use SnapPay\Support\Countries;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the corridor gateways and removes any that do not serve the customer's
 * billing country or that the merchant has not enabled on the platform
 * (CUSTOMER-COUNTRY-DETECTION.md section 7.1).
 */
final class GatewayFilter {

	/**
	 * Plugin configuration.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * @param Plugin $plugin Configuration.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Hooks in.
	 */
	public function register(): void {
		add_filter( 'woocommerce_payment_gateways', array( $this, 'register_gateways' ) );
		add_filter( 'woocommerce_available_payment_gateways', array( $this, 'filter_by_country' ) );
		add_action( 'woocommerce_checkout_update_order_review', array( $this, 'refresh_customer_country' ) );
	}

	/**
	 * Adds the corridor gateways to WooCommerce.
	 *
	 * @param array<int, string> $gateways Registered gateway classes.
	 *
	 * @return array<int, string>
	 */
	public function register_gateways( array $gateways ): array {
		$gateways[] = MpesaKe::class;
		$gateways[] = AirtelKe::class;
		$gateways[] = MtnUg::class;
		$gateways[] = AirtelUg::class;

		return $gateways;
	}

	/**
	 * Removes Snap Pay gateways that cannot serve this customer.
	 *
	 * Rule 1: does the provider serve the billing country?
	 * Rule 2: has the merchant enabled the provider on the platform?
	 *
	 * @param array<string, \WC_Payment_Gateway> $gateways Available gateways.
	 *
	 * @return array<string, \WC_Payment_Gateway>
	 */
	public function filter_by_country( array $gateways ): array {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $gateways;
		}

		$country           = $this->billing_country();
		$enabled_providers = $this->plugin->enabled_providers();

		foreach ( $gateways as $gateway_id => $gateway ) {
			if ( 0 !== strpos( (string) $gateway_id, 'snappay_' ) ) {
				continue;
			}

			$provider_id = Countries::provider_id_for_gateway( (string) $gateway_id );

			if ( null === $provider_id ) {
				unset( $gateways[ $gateway_id ] );
				continue;
			}

			if ( ! in_array( strtoupper( $country ), Countries::provider_countries( $provider_id ), true ) ) {
				unset( $gateways[ $gateway_id ] );
				continue;
			}

			if ( array() !== $enabled_providers && ! in_array( $provider_id, $enabled_providers, true ) ) {
				unset( $gateways[ $gateway_id ] );
			}
		}

		return $gateways;
	}

	/**
	 * Keeps the session country in step when the customer changes the billing country
	 * dropdown, so payment methods and FX refresh over AJAX without a page reload.
	 *
	 * @param string $post_data Serialised checkout form data.
	 */
	public function refresh_customer_country( $post_data ): void {
		if ( ! is_string( $post_data ) || null === WC()->session ) {
			return;
		}

		parse_str( $post_data, $fields );

		$country = isset( $fields['billing_country'] ) ? strtoupper( sanitize_text_field( (string) $fields['billing_country'] ) ) : '';

		if ( '' !== $country ) {
			WC()->session->set( 'snappay_customer_country', $country );
		}
	}

	/**
	 * Billing country from the customer session.
	 */
	private function billing_country(): string {
		if ( ! function_exists( 'WC' ) || null === WC()->customer ) {
			return '';
		}

		return strtoupper( (string) WC()->customer->get_billing_country() );
	}
}
