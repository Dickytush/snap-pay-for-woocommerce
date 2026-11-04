<?php
/**
 * The escrow panel on the WooCommerce order screen.
 *
 * @package SnapPay
 */

declare(strict_types=1);

namespace SnapPay\Admin;

use SnapPay\Http\ApiException;
use SnapPay\OrderRepository;
use SnapPay\PlatformApiClient;
use SnapPay\Plugin;
use SnapPay\Support\Logger;
use SnapPay\Support\Money;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Where the merchant enters the customer's delivery code to release escrowed funds.
 *
 * The plugin never generates, stores, hashes or validates a code beyond a cheap
 * shape check. It passes the plaintext straight to the platform and discards it
 * (SECURITY.md section 4).
 */
final class SettlementController {

	private const NONCE_VERIFY = 'snappay_verify_code';

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
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'admin_post_snappay_verify_code', array( $this, 'handle_verify' ) );
	}

	/**
	 * Registers the metabox on both legacy and HPOS order screens.
	 */
	public function add_meta_box(): void {
		$screens = array( 'shop_order' );

		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screens[] = wc_get_page_screen_id( 'shop-order' );
		}

		foreach ( array_unique( array_filter( $screens ) ) as $screen ) {
			add_meta_box(
				'snappay-escrow',
				__( 'Snap Pay escrow', 'snap-pay-for-woocommerce' ),
				array( $this, 'render' ),
				$screen,
				'side',
				'high'
			);
		}
	}

	/**
	 * Renders the panel.
	 *
	 * @param mixed $post_or_order Post object or WC_Order, depending on screen.
	 */
	public function render( $post_or_order ): void {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order );

		if ( ! $order instanceof WC_Order || ! $this->orders->is_snappay_order( $order ) ) {
			echo '<p>' . esc_html__( 'This order was not paid with Snap Pay.', 'snap-pay-for-woocommerce' ) . '</p>';

			return;
		}

		$state    = $this->orders->string_meta( $order, OrderRepository::META_STATE );
		$ref      = $this->orders->string_meta( $order, OrderRepository::META_TRANSACTION_REF );
		$currency = $this->orders->string_meta( $order, OrderRepository::META_CURRENCY );
		$currency = '' !== $currency ? $currency : $order->get_currency();

		echo '<div class="snappay-escrow-box">';

		printf(
			'<p class="snappay-escrow-state"><strong>%1$s</strong><br /><span class="snappay-ref">%2$s</span></p>',
			esc_html( $this->state_label( $state ) ),
			esc_html( '' !== $ref ? $ref : __( 'No transaction reference yet', 'snap-pay-for-woocommerce' ) )
		);

		$this->render_amounts( $order, $currency, $state );

		if ( 'succeeded' === $state ) {
			$this->render_code_form( $order );
		} elseif ( in_array( $state, array( 'delivered', 'settled', 'withdrawn' ), true ) ) {
			$verified_by = $this->orders->string_meta( $order, OrderRepository::META_VERIFIED_BY );

			if ( 'support' === $verified_by ) {
				echo '<p class="snappay-hint">' . esc_html__( 'Released by Snap Pay support override.', 'snap-pay-for-woocommerce' ) . '</p>';
			}
		}

		echo '</div>';
	}

	/**
	 * Renders the money breakdown. Every figure is a platform-reported value read back
	 * from order meta; nothing here is recomputed (ARCHITECTURE.md invariant 2).
	 */
	private function render_amounts( WC_Order $order, string $currency, string $state ): void {
		$gross = $this->orders->int_meta( $order, OrderRepository::META_AMOUNT_MINOR );

		echo '<table class="snappay-amounts"><tbody>';

		if ( $gross > 0 ) {
			$this->amount_row( __( 'Gross (held)', 'snap-pay-for-woocommerce' ), $gross, $currency );
		}

		$display_currency = $this->orders->string_meta( $order, OrderRepository::META_DISPLAY_CURRENCY );
		$display_amount   = $this->orders->int_meta( $order, OrderRepository::META_DISPLAY_AMOUNT );

		if ( '' !== $display_currency && $display_amount > 0 ) {
			$this->amount_row( __( 'Customer paid', 'snap-pay-for-woocommerce' ), $display_amount, $display_currency );

			$rate = $this->orders->string_meta( $order, OrderRepository::META_FX_RATE );

			if ( '' !== $rate ) {
				printf(
					'<tr><th>%1$s</th><td>%2$s</td></tr>',
					esc_html__( 'FX rate (locked)', 'snap-pay-for-woocommerce' ),
					esc_html( sprintf( '1 %s = %s %s', $currency, number_format( (float) $rate, 2 ), $display_currency ) )
				);
			}
		}

		if ( in_array( $state, array( 'delivered', 'settled', 'withdrawn' ), true ) ) {
			$fee       = $this->orders->int_meta( $order, OrderRepository::META_FEE_MINOR );
			$reserve   = $this->orders->int_meta( $order, OrderRepository::META_RESERVE_MINOR );
			$available = $this->orders->int_meta( $order, OrderRepository::META_AVAILABLE_MINOR );

			if ( $fee > 0 ) {
				$this->amount_row( __( 'Snap Pay fee', 'snap-pay-for-woocommerce' ), $fee, $currency );
			}

			if ( $reserve > 0 ) {
				$this->amount_row( __( 'Reserve (30 days)', 'snap-pay-for-woocommerce' ), $reserve, $currency );
			}

			if ( $available > 0 ) {
				$this->amount_row( __( 'Available to you', 'snap-pay-for-woocommerce' ), $available, $currency );
			}
		}

		echo '</tbody></table>';
	}

	/**
	 * One money row.
	 */
	private function amount_row( string $label, int $minor, string $currency ): void {
		printf(
			'<tr><th>%1$s</th><td>%2$s</td></tr>',
			esc_html( $label ),
			wp_kses_post( Money::format( $minor, $currency ) )
		);
	}

	/**
	 * Renders the code entry form.
	 */
	private function render_code_form( WC_Order $order ): void {
		$expires = $this->orders->string_meta( $order, OrderRepository::META_CODE_EXPIRES_AT );

		echo '<hr />';
		echo '<p class="snappay-hint">' . esc_html__( 'Ask the customer for the 8-character code we emailed them, after they have received the goods.', 'snap-pay-for-woocommerce' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="snappay-code-form">';
		wp_nonce_field( self::NONCE_VERIFY . '_' . $order->get_id() );
		echo '<input type="hidden" name="action" value="snappay_verify_code" />';
		printf( '<input type="hidden" name="order_id" value="%d" />', absint( $order->get_id() ) );

		printf(
			'<p><label for="snappay_code">%1$s</label>
			<input type="text" id="snappay_code" name="snappay_code" maxlength="8" pattern="[A-Za-z0-9]{8}" inputmode="latin" autocomplete="off" class="snappay-code-input" placeholder="%2$s" required /></p>',
			esc_html__( 'Customer confirmation code', 'snap-pay-for-woocommerce' ),
			esc_attr__( 'ABCD1234', 'snap-pay-for-woocommerce' )
		);

		submit_button( __( 'Verify and release', 'snap-pay-for-woocommerce' ), 'primary', 'submit', false );
		echo '</form>';

		if ( '' !== $expires ) {
			printf(
				'<p class="snappay-hint">%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: local date and time the code expires. */
						__( 'Code expires %s.', 'snap-pay-for-woocommerce' ),
						wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) strtotime( $expires ) )
					)
				)
			);
		}

		echo '<p class="snappay-hint">' . esc_html__( 'Five wrong attempts locks the code for an hour. Snap Pay support can release funds if the code is lost or expired.', 'snap-pay-for-woocommerce' ) . '</p>';
	}

	/* ---------------------------------------------------------------------
	 * Verify
	 * ------------------------------------------------------------------ */

	/**
	 * Handles the code submission.
	 */
	public function handle_verify(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to release Snap Pay funds.', 'snap-pay-for-woocommerce' ), 403 );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;

		check_admin_referer( self::NONCE_VERIFY . '_' . $order_id );

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			$this->redirect_back( $order_id, 'error', __( 'Order not found.', 'snap-pay-for-woocommerce' ) );
		}

		$code = isset( $_POST['snappay_code'] ) ? strtoupper( trim( sanitize_text_field( wp_unslash( (string) $_POST['snappay_code'] ) ) ) ) : '';

		// Cheap shape check first. A typo must not burn one of the five hourly attempts
		// (DELIVERY-CODE-FLOW.md section 9).
		if ( 1 !== preg_match( '/^[A-Z0-9]{8}$/', $code ) ) {
			$this->redirect_back( $order_id, 'error', __( 'Codes are exactly 8 letters or numbers.', 'snap-pay-for-woocommerce' ) );
		}

		// Duplicate-click guard (DELIVERY-CODE-FLOW.md section 10.2).
		$lock_key = 'snappay_verify_' . $order_id;

		if ( false !== get_transient( $lock_key ) ) {
			$this->redirect_back( $order_id, 'error', __( 'A verification is already in progress for this order.', 'snap-pay-for-woocommerce' ) );
		}

		set_transient( $lock_key, 1, 30 );

		try {
			$result = $this->api->verify_delivery_code( (string) $order_id, $order->get_order_key(), $code );
		} catch ( ApiException $e ) {
			delete_transient( $lock_key );

			// The code itself is never logged, only the outcome.
			Logger::warning( 'Delivery code verification rejected', array( 'order_id' => $order_id, 'error' => $e->error_code(), 'status' => $e->status() ) );

			$this->redirect_back( $order_id, 'error', $this->message_for_error( $e ) );
		}

		delete_transient( $lock_key );

		// The 200 response carries the full settlement breakdown, so the order can be
		// completed now without waiting for delivery.verified. Whichever arrives first
		// wins; the mutex in OrderRepository makes the race safe
		// (OPEN-QUESTIONS.md B5 default).
		$state = isset( $result['state'] ) ? (string) $result['state'] : 'delivered';
		$this->orders->apply_state( $order, $state, $result );

		$currency = isset( $result['currency'] ) ? (string) $result['currency'] : $order->get_currency();

		$this->redirect_back(
			$order_id,
			'success',
			sprintf(
				/* translators: %s: amount now available to the merchant. */
				__( 'Delivery confirmed. Funds released. Available to you: %s.', 'snap-pay-for-woocommerce' ),
				Money::format_plain( (int) ( $result['available_to_merchant_minor'] ?? 0 ), $currency )
			)
		);
	}

	/**
	 * Maps a verify error onto merchant-facing wording (DELIVERY-CODE-FLOW.md section 5.2).
	 */
	private function message_for_error( ApiException $e ): string {
		switch ( $e->status() ) {
			case 400:
				return __( 'Codes are exactly 8 letters or numbers.', 'snap-pay-for-woocommerce' );

			case 403:
				return __( 'That code does not match this order. Check it with the customer.', 'snap-pay-for-woocommerce' );

			case 404:
				return __( 'No delivery code has been issued for this order yet.', 'snap-pay-for-woocommerce' );

			case 410:
				return __( 'That code has already been used or has expired. Contact Snap Pay support.', 'snap-pay-for-woocommerce' );

			case 429:
				return __( 'Too many attempts. Try again in an hour, or contact Snap Pay support.', 'snap-pay-for-woocommerce' );

			default:
				return $e->getMessage();
		}
	}

	/**
	 * Returns to the order screen with a notice.
	 *
	 * @param string $type    'success' or 'error'.
	 * @param string $message Human-readable message.
	 */
	private function redirect_back( int $order_id, string $type, string $message ): void {
		$url = $order_id > 0 && function_exists( 'wc_get_container' ) && class_exists( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )
			? admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order_id )
			: admin_url( 'post.php?post=' . $order_id . '&action=edit' );

		wp_safe_redirect(
			add_query_arg(
				array(
					'snappay_notice'  => $type,
					'snappay_message' => rawurlencode( $message ),
				),
				$url
			)
		);
		exit;
	}

	/**
	 * Human label for a platform state.
	 */
	private function state_label( string $state ): string {
		$labels = array(
			''          => __( 'Awaiting payment', 'snap-pay-for-woocommerce' ),
			'initiated' => __( 'Starting payment', 'snap-pay-for-woocommerce' ),
			'requested' => __( 'Waiting for the customer to approve on their phone', 'snap-pay-for-woocommerce' ),
			'succeeded' => __( 'Funds held pending delivery', 'snap-pay-for-woocommerce' ),
			'failed'    => __( 'Payment failed', 'snap-pay-for-woocommerce' ),
			'cancelled' => __( 'Customer cancelled the payment', 'snap-pay-for-woocommerce' ),
			'timed_out' => __( 'Timed out - awaiting Snap Pay reconciliation', 'snap-pay-for-woocommerce' ),
			'delivered' => __( 'Delivery confirmed, funds released', 'snap-pay-for-woocommerce' ),
			'settled'   => __( 'Settled to your Snap Pay wallet', 'snap-pay-for-woocommerce' ),
			'withdrawn' => __( 'Withdrawn', 'snap-pay-for-woocommerce' ),
		);

		return $labels[ $state ] ?? $state;
	}
}
