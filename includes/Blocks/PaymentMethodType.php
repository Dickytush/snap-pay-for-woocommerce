<?php
/**
 * Blocks checkout payment method type.
 *
 * @package SnapPay
 */

declare(strict_types=1);

namespace SnapPay\Blocks;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use SnapPay\Gateway\AbstractGateway;
use SnapPay\Support\Countries;

defined( 'ABSPATH' ) || exit;

/**
 * One instance per corridor. Mirrors the server-side availability rules so the
 * Blocks checkout offers exactly what the classic checkout would.
 */
final class PaymentMethodType extends AbstractPaymentMethodType {

	/**
	 * Platform provider_id.
	 *
	 * @var string
	 */
	private string $provider_id;

	/**
	 * The matching gateway instance, if WooCommerce has one loaded.
	 *
	 * @var AbstractGateway|null
	 */
	private ?AbstractGateway $gateway = null;

	/**
	 * @param string $provider_id Platform provider_id.
	 */
	public function __construct( string $provider_id ) {
		$this->provider_id = $provider_id;

		$definition = Countries::provider( $provider_id );
		$this->name = null !== $definition ? $definition['gateway_id'] : 'snappay_' . $provider_id;
	}

	/**
	 * Loads gateway settings.
	 */
	public function initialize(): void {
		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();

		if ( isset( $gateways[ $this->name ] ) && $gateways[ $this->name ] instanceof AbstractGateway ) {
			$this->gateway = $gateways[ $this->name ];
		}

		$this->settings = get_option( 'woocommerce_' . $this->name . '_settings', array() );
	}

	/**
	 * Whether the method may be offered at all.
	 */
	public function is_active(): bool {
		return null !== $this->gateway && $this->gateway->is_available();
	}

	/**
	 * Script handles for the checkout bundle.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles(): array {
		$handle = 'snappay-blocks';

		if ( ! wp_script_is( $handle, 'registered' ) ) {
			wp_register_script(
				$handle,
				\SnapPay\Plugin::asset_url( 'assets/js/blocks.js' ),
				array( 'wc-blocks-registry', 'wp-element', 'wp-i18n', 'wp-html-entities' ),
				SNAPPAY_VERSION,
				true
			);

			/*
			 * The FX-lock route is a plain WP REST route, so it needs a `wp_rest`
			 * nonce. The Store API nonce is a different token and will not satisfy it —
			 * without this the rate lookup silently 403s and the customer sees no local
			 * currency at all.
			 */
			wp_localize_script(
				$handle,
				'SnapPayBlocks',
				array(
					'fxLockUrl' => rest_url( 'snappay/v1/fx-lock' ),
					'nonce'     => wp_create_nonce( 'wp_rest' ),
				)
			);

			wp_set_script_translations( $handle, 'snap-pay-for-woocommerce' );
		}

		return array( $handle );
	}

	/**
	 * Data handed to the front end.
	 *
	 * Contains no secrets — only labels and the corridor's country
	 * (CODING-STANDARDS.md section 4).
	 *
	 * @return array<string, mixed>
	 */
	public function get_payment_method_data(): array {
		$definition = Countries::provider( $this->provider_id );

		return array(
			'title'       => null !== $this->gateway ? $this->gateway->get_title() : ( null !== $definition ? $definition['label'] : 'Snap Pay' ),
			'description' => null !== $this->gateway ? $this->gateway->get_description() : '',
			'providerId'  => $this->provider_id,
			'country'     => null !== $definition ? $definition['country'] : '',
			'icon'        => Countries::icon_url( $this->provider_id ),
			'supports'    => null !== $this->gateway ? array_filter( $this->gateway->supports, array( $this->gateway, 'supports' ) ) : array( 'products' ),
		);
	}
}
