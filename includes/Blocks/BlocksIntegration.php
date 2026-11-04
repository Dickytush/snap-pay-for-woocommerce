<?php
/**
 * Blocks checkout wiring.
 *
 * @package SnapPay
 */

declare(strict_types=1);

namespace SnapPay\Blocks;

use SnapPay\Plugin;
use SnapPay\Support\Countries;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Blocks payment method types and bridges the Store API's payment
 * data into the shape the classic gateway already reads.
 */
final class BlocksIntegration {

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
		add_action( 'woocommerce_blocks_payment_method_type_registration', array( $this, 'register_types' ) );
		add_action( 'woocommerce_rest_checkout_process_payment_with_context', array( $this, 'bridge_payment_data' ), 5 );
	}

	/**
	 * Registers one payment method type per corridor.
	 *
	 * @param mixed $registry Blocks payment method registry.
	 */
	public function register_types( $registry ): void {
		if ( ! is_object( $registry ) || ! method_exists( $registry, 'register' ) ) {
			return;
		}

		if ( ! class_exists( \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class ) ) {
			return;
		}

		foreach ( array_keys( Countries::providers() ) as $provider_id ) {
			$registry->register( new PaymentMethodType( (string) $provider_id ) );
		}
	}

	/**
	 * Copies the Blocks payment_data into $_POST so the gateway's process_payment()
	 * reads the phone number the same way on both checkouts.
	 *
	 * Only our own whitelisted field is copied, and it is sanitised on read.
	 *
	 * @param mixed $context Store API payment context.
	 */
	public function bridge_payment_data( $context ): void {
		if ( ! is_object( $context ) || ! isset( $context->payment_method ) ) {
			return;
		}

		$method = (string) $context->payment_method;

		if ( 0 !== strpos( $method, 'snappay_' ) ) {
			return;
		}

		$data = isset( $context->payment_data ) && is_array( $context->payment_data ) ? $context->payment_data : array();
		$key  = $method . '_phone';

		if ( isset( $data[ $key ] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Store API verified the request; value is sanitised on read.
			$_POST[ $key ] = sanitize_text_field( (string) $data[ $key ] );
		} elseif ( isset( $data['phone'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Store API verified the request; value is sanitised on read.
			$_POST[ $key ] = sanitize_text_field( (string) $data['phone'] );
		}
	}
}
