<?php
/**
 * Appends the delivery code to the customer's WooCommerce order email.
 *
 * @package SnapPay
 */

declare(strict_types=1);

namespace SnapPay\Email;

use SnapPay\Http\ApiException;
use SnapPay\OrderRepository;
use SnapPay\PlatformApiClient;
use SnapPay\Plugin;
use SnapPay\Support\Logger;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * The second of the two delivery-code channels.
 *
 * The platform emails the customer directly; this adds the same code to the
 * WooCommerce order email so a single spam filter cannot strand the order
 * (DELIVERY-CODE-FLOW.md section 4).
 *
 * The plaintext code lives only for the duration of the email render. It is never
 * written to order meta, a transient, the session or a log line
 * (SECURITY.md section 4, AGENTS.md F5).
 */
final class DeliveryCodeAppender {

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
	 * Order mirror.
	 *
	 * @var OrderRepository
	 */
	private OrderRepository $orders;

	/**
	 * @param Plugin            $plugin Configuration.
	 * @param PlatformApiClient $api    API client.
	 * @param OrderRepository   $orders Order mirror.
	 */
	public function __construct( Plugin $plugin, PlatformApiClient $api, OrderRepository $orders ) {
		$this->plugin = $plugin;
		$this->api    = $api;
		$this->orders = $orders;
	}

	/**
	 * Hooks in.
	 */
	public function register(): void {
		add_action( 'woocommerce_email_order_details', array( $this, 'append' ), 20, 4 );
	}

	/**
	 * Appends the code block to customer-facing order emails.
	 *
	 * @param mixed $order         WC_Order being emailed.
	 * @param bool  $sent_to_admin Whether this copy goes to the store admin.
	 * @param bool  $plain_text    Whether this is the plain-text variant.
	 * @param mixed $email         The WC_Email instance.
	 */
	public function append( $order, $sent_to_admin = false, $plain_text = false, $email = null ): void {
		// Never to the merchant. The merchant must obtain the code from the customer -
		// that is the whole control (DELIVERY-CODE-FLOW.md section 4.2).
		if ( $sent_to_admin ) {
			return;
		}

		if ( ! $order instanceof WC_Order || ! $this->orders->is_snappay_order( $order ) ) {
			return;
		}

		// Only while funds are held and a code is outstanding.
		if ( 'succeeded' !== $this->orders->string_meta( $order, OrderRepository::META_STATE ) ) {
			return;
		}

		$code = $this->fetch_code( $order );

		if ( '' === $code ) {
			return;
		}

		$this->render( $code, (bool) $plain_text );

		$order->update_meta_data( OrderRepository::META_CODE_EMAILED, gmdate( 'c' ) );
		$order->save();
	}

	/**
	 * Fetches the plaintext code for this order.
	 *
	 * Returns '' on any failure. A missing code must not block the order email - the
	 * platform's own email still carries it (DELIVERY-CODE-FLOW.md section 13).
	 */
	private function fetch_code( WC_Order $order ): string {
		try {
			$response = $this->api->get_delivery_code( (string) $order->get_id(), $order->get_order_key() );
		} catch ( ApiException $e ) {
			// Logs the failure, never the code.
			Logger::warning(
				'Could not fetch delivery code for order email',
				array( 'order_id' => $order->get_id(), 'error' => $e->error_code(), 'status' => $e->status() )
			);

			return '';
		}

		$code = isset( $response['code'] ) ? strtoupper( trim( (string) $response['code'] ) ) : '';

		if ( 1 !== preg_match( '/^[A-Z0-9]{8}$/', $code ) ) {
			return '';
		}

		if ( isset( $response['expires_at'] ) ) {
			$order->update_meta_data( OrderRepository::META_CODE_EXPIRES_AT, (string) $response['expires_at'] );
		}

		if ( isset( $response['status'] ) ) {
			$order->update_meta_data( OrderRepository::META_CODE_STATUS, (string) $response['status'] );
		}

		return $code;
	}

	/**
	 * Outputs the code block.
	 *
	 * @param string $code       Plaintext code, held only for this render.
	 * @param bool   $plain_text Whether to emit the plain-text variant.
	 */
	private function render( string $code, bool $plain_text ): void {
		$heading     = __( 'Delivery confirmation code', 'snap-pay-for-woocommerce' );
		$instruction = __( 'Share this code with the seller ONLY AFTER you have received your order. Once they enter it, your payment is released to them.', 'snap-pay-for-woocommerce' );
		$expiry      = __( 'This code expires in 14 days.', 'snap-pay-for-woocommerce' );

		$template = apply_filters( 'snappay_delivery_code_email_template', '' );

		if ( is_string( $template ) && '' !== $template && is_readable( $template ) ) {
			include $template;

			return;
		}

		if ( $plain_text ) {
			echo "\n" . esc_html( strtoupper( $heading ) ) . "\n\n";
			echo esc_html( $code ) . "\n\n";
			echo esc_html( $instruction ) . "\n";
			echo esc_html( $expiry ) . "\n\n";

			return;
		}

		printf(
			'<div style="margin:24px 0;padding:16px;border:2px solid #2c7a4b;border-radius:6px;">
				<h2 style="margin:0 0 8px;font-size:16px;">%1$s</h2>
				<p style="margin:0 0 12px;font-size:28px;letter-spacing:4px;font-weight:700;font-family:monospace;">%2$s</p>
				<p style="margin:0 0 6px;">%3$s</p>
				<p style="margin:0;font-size:12px;color:#555;">%4$s</p>
			</div>',
			esc_html( $heading ),
			esc_html( $code ),
			esc_html( $instruction ),
			esc_html( $expiry )
		);
	}
}
