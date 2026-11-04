<?php
/**
 * All WC_Order reads and writes.
 *
 * @package SnapPay
 */

declare(strict_types=1);

namespace SnapPay;

use SnapPay\Support\Logger;
use SnapPay\Support\Money;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Owns Snap Pay order meta and the platform-state -> WooCommerce-status mirror.
 *
 * The plugin does not own escrow state. This class only reflects what the platform
 * reports (ARCHITECTURE.md section 2.2). Every write goes through WooCommerce CRUD so
 * the plugin stays HPOS-compatible.
 */
final class OrderRepository {

	public const META_TRANSACTION_REF   = '_snappay_transaction_ref';
	public const META_STATE             = '_snappay_state';
	public const META_PROVIDER_ID       = '_snappay_provider_id';
	public const META_PROVIDER_REF      = '_snappay_provider_ref';
	public const META_ENVIRONMENT       = '_snappay_environment';
	public const META_AMOUNT_MINOR      = '_snappay_amount_minor';
	public const META_CURRENCY          = '_snappay_currency';
	public const META_DISPLAY_CURRENCY  = '_snappay_display_currency';
	public const META_DISPLAY_AMOUNT    = '_snappay_display_amount_minor';
	public const META_FX_LOCK_ID        = '_snappay_fx_lock_id';
	public const META_FX_RATE           = '_snappay_fx_rate';
	public const META_FEE_MINOR         = '_snappay_fee_minor';
	public const META_RESERVE_MINOR     = '_snappay_reserve_minor';
	public const META_NET_MINOR         = '_snappay_net_to_merchant_minor';
	public const META_AVAILABLE_MINOR   = '_snappay_available_to_merchant_minor';
	public const META_CODE_STATUS       = '_snappay_code_status';
	public const META_CODE_EXPIRES_AT   = '_snappay_code_expires_at';
	public const META_CODE_EMAILED      = '_snappay_code_emailed';
	public const META_VERIFIED_BY       = '_snappay_verified_by';
	public const META_SEEN_EVENTS       = '_snappay_seen_event_ids';

	/**
	 * Canonical platform state -> WooCommerce status map.
	 *
	 * ESCROW-STATE-MACHINE.md section 6. Changing an entry requires a doc update in the
	 * same change (AGENTS.md F9).
	 *
	 * @return array<string, string>
	 */
	public function state_map(): array {
		$delivered = (string) apply_filters( 'snappay_escrow_delivered_status', 'completed' );
		$held      = (string) apply_filters( 'snappay_escrow_held_status', 'processing' );

		// A filter may re-point the held status, but never onto the delivered status:
		// that would complete the order (and fire completion emails and stock
		// reduction) while the funds are still in escrow. The delivery-code
		// requirement is not filterable (ARCHITECTURE.md section 6).
		if ( $held === $delivered ) {
			$held = 'processing';
		}

		return array(
			'initiated' => 'pending',
			'requested' => 'pending',
			// NOT completed. Funds are held, not settled.
			'succeeded' => $held,
			'failed'    => 'failed',
			'cancelled' => 'cancelled',
			'timed_out' => 'on-hold',
			'delivered' => $delivered,
			'settled'   => $delivered,
			'withdrawn' => $delivered,
		);
	}

	/**
	 * Maps a platform state to a WooCommerce status, or null if unknown.
	 */
	public function status_for_state( string $state ): ?string {
		$map   = $this->state_map();
		$state = strtolower( $state );

		return isset( $map[ $state ] ) ? $map[ $state ] : null;
	}

	/* ---------------------------------------------------------------------
	 * Lookup
	 * ------------------------------------------------------------------ */

	/**
	 * Finds the order a platform payload refers to, validating order_key.
	 *
	 * The key check stops a forged payload from steering a different store's order
	 * into a completed state.
	 */
	public function find( string $order_id, string $order_key = '' ): ?WC_Order {
		$order = wc_get_order( absint( $order_id ) );

		if ( ! $order instanceof WC_Order ) {
			return null;
		}

		if ( '' !== $order_key && ! hash_equals( $order->get_order_key(), $order_key ) ) {
			Logger::warning( 'Order key mismatch on platform payload', array( 'order_id' => $order_id ) );

			return null;
		}

		return $order;
	}

	/**
	 * Finds an order by its Snap Pay transaction reference.
	 */
	public function find_by_transaction_ref( string $transaction_ref ): ?WC_Order {
		if ( '' === $transaction_ref ) {
			return null;
		}

		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => self::META_TRANSACTION_REF,
						'value' => $transaction_ref,
					),
				),
			)
		);

		if ( is_array( $orders ) && isset( $orders[0] ) && $orders[0] instanceof WC_Order ) {
			return $orders[0];
		}

		return null;
	}

	/* ---------------------------------------------------------------------
	 * Writes
	 * ------------------------------------------------------------------ */

	/**
	 * Records the collect response on the order.
	 *
	 * @param array<string, mixed> $transaction 202 response body from POST /merchant/collect.
	 */
	public function record_collect( WC_Order $order, array $transaction, string $provider_id, string $environment ): void {
		$order->update_meta_data( self::META_TRANSACTION_REF, (string) ( $transaction['transaction_ref'] ?? '' ) );
		$order->update_meta_data( self::META_PROVIDER_ID, $provider_id );
		$order->update_meta_data( self::META_ENVIRONMENT, $environment );
		$order->update_meta_data( self::META_STATE, (string) ( $transaction['state'] ?? 'requested' ) );

		if ( isset( $transaction['provider_ref'] ) ) {
			$order->update_meta_data( self::META_PROVIDER_REF, (string) $transaction['provider_ref'] );
		}

		$this->store_amounts( $order, $transaction );

		$order->set_transaction_id( (string) ( $transaction['transaction_ref'] ?? '' ) );
		$order->save();
	}

	/**
	 * Copies the money and FX fields the platform reported. The plugin never computes
	 * these — every figure shown in wp-admin originates from a platform payload
	 * (ARCHITECTURE.md invariant 2).
	 *
	 * @param array<string, mixed> $payload Any transaction-shaped payload.
	 */
	public function store_amounts( WC_Order $order, array $payload ): void {
		/*
		 * Adopt the transaction reference if we do not already have one. Normally
		 * record_collect() stores it from the 202, but when a collect times out the
		 * plugin never sees that response (AGENTS.md F6 forbids retrying). The
		 * webhook is then the only place the reference ever arrives, and without it
		 * the poll job cannot reconcile the order.
		 */
		if ( isset( $payload['transaction_ref'] ) && '' !== $payload['transaction_ref']
			&& '' === $this->string_meta( $order, self::META_TRANSACTION_REF ) ) {
			$order->update_meta_data( self::META_TRANSACTION_REF, (string) $payload['transaction_ref'] );
		}

		$int_fields = array(
			'amount_minor'                => self::META_AMOUNT_MINOR,
			'display_amount_minor'        => self::META_DISPLAY_AMOUNT,
			'fee_minor'                   => self::META_FEE_MINOR,
			'reserve_minor'               => self::META_RESERVE_MINOR,
			'net_to_merchant_minor'       => self::META_NET_MINOR,
			'available_to_merchant_minor' => self::META_AVAILABLE_MINOR,
		);

		foreach ( $int_fields as $field => $meta_key ) {
			if ( isset( $payload[ $field ] ) && is_numeric( $payload[ $field ] ) ) {
				$order->update_meta_data( $meta_key, (int) $payload[ $field ] );
			}
		}

		$string_fields = array(
			'currency'         => self::META_CURRENCY,
			'display_currency' => self::META_DISPLAY_CURRENCY,
			'fx_rate'          => self::META_FX_RATE,
		);

		foreach ( $string_fields as $field => $meta_key ) {
			if ( isset( $payload[ $field ] ) && '' !== $payload[ $field ] ) {
				$order->update_meta_data( $meta_key, (string) $payload[ $field ] );
			}
		}

		if ( isset( $payload['delivery_code'] ) && is_array( $payload['delivery_code'] ) ) {
			// Status and expiry only. The plaintext code is never returned here and is
			// never stored (SECURITY.md section 4).
			if ( isset( $payload['delivery_code']['status'] ) ) {
				$order->update_meta_data( self::META_CODE_STATUS, (string) $payload['delivery_code']['status'] );
			}

			if ( isset( $payload['delivery_code']['expires_at'] ) ) {
				$order->update_meta_data( self::META_CODE_EXPIRES_AT, (string) $payload['delivery_code']['expires_at'] );
			}
		}

		if ( isset( $payload['verified_by'] ) ) {
			$order->update_meta_data( self::META_VERIFIED_BY, (string) $payload['verified_by'] );
		}
	}

	/**
	 * Persists the FX lock chosen at checkout.
	 */
	public function record_fx_lock( WC_Order $order, string $lock_id, string $rate, string $display_currency, int $display_amount_minor ): void {
		$order->update_meta_data( self::META_FX_LOCK_ID, $lock_id );
		$order->update_meta_data( self::META_FX_RATE, $rate );
		$order->update_meta_data( self::META_DISPLAY_CURRENCY, $display_currency );
		$order->update_meta_data( self::META_DISPLAY_AMOUNT, $display_amount_minor );
	}

	/**
	 * Mirrors a platform state onto the order.
	 *
	 * This is the single place order status changes. It is safe to call repeatedly
	 * with the same state — webhooks are at-least-once and the poll job re-checks.
	 *
	 * @param array<string, mixed> $payload Transaction-shaped payload driving the change.
	 *
	 * @return bool True when the order status actually changed.
	 */
	public function apply_state( WC_Order $order, string $state, array $payload = array(), string $note = '' ): bool {
		$state  = strtolower( $state );
		$status = $this->status_for_state( $state );

		if ( null === $status ) {
			Logger::warning( 'Unknown platform state ignored', array( 'state' => $state, 'order_id' => $order->get_id() ) );

			return false;
		}

		$previous = (string) $order->get_meta( self::META_STATE );

		$this->store_amounts( $order, $payload );
		$order->update_meta_data( self::META_STATE, $state );
		$order->save();

		if ( $order->get_status() === $status ) {
			return false;
		}

		$completed_status = (string) apply_filters( 'snappay_escrow_delivered_status', 'completed' );

		if ( $status === $completed_status ) {
			return $this->complete_once( $order, $state, $previous, $payload, $note );
		}

		$order->update_status( $status, '' !== $note ? $note : $this->note_for_state( $state, $payload, $order ) );

		$this->fire_state_action( $order, $state, $previous, $payload );

		return true;
	}

	/**
	 * Completes the order at most once.
	 *
	 * Both the verify 200 response and the delivery.verified webhook can trigger
	 * completion (OPEN-QUESTIONS.md B5 default: whichever arrives first wins). The
	 * mutex plus the status re-check makes the race safe
	 * (ESCROW-STATE-MACHINE.md section 8.3).
	 *
	 * @param string               $previous The state the order held before this change.
	 * @param array<string, mixed> $payload  Driving payload.
	 */
	private function complete_once( WC_Order $order, string $state, string $previous, array $payload, string $note ): bool {
		$lock_key = 'snappay_complete_' . $order->get_id();

		if ( false !== get_transient( $lock_key ) ) {
			return false;
		}

		set_transient( $lock_key, 1, 60 );

		try {
			// Re-read: another process may have completed it while we waited.
			$fresh = wc_get_order( $order->get_id() );

			if ( ! $fresh instanceof WC_Order ) {
				return false;
			}

			$completed_status = (string) apply_filters( 'snappay_escrow_delivered_status', 'completed' );

			if ( $fresh->get_status() === $completed_status ) {
				return false;
			}

			$fresh->update_status( $completed_status, '' !== $note ? $note : $this->note_for_state( $state, $payload, $fresh ) );

			$this->fire_state_action( $fresh, $state, $previous, $payload );

			return true;
		} finally {
			delete_transient( $lock_key );
		}
	}

	/**
	 * Fires the public action hooks for a state change.
	 *
	 * @param array<string, mixed> $payload Driving payload.
	 */
	private function fire_state_action( WC_Order $order, string $state, string $previous, array $payload ): void {
		if ( 'succeeded' === $state && 'succeeded' !== $previous ) {
			do_action( 'snappay_escrow_succeeded', $order, $payload );
		}

		if ( 'delivered' === $state && 'delivered' !== $previous ) {
			do_action( 'snappay_escrow_delivered', $order, $payload, (string) ( $payload['verified_by'] ?? 'customer' ) );
		}
	}

	/**
	 * Human-readable order note for a state, using only platform-supplied figures.
	 *
	 * @param array<string, mixed> $payload Driving payload.
	 */
	private function note_for_state( string $state, array $payload, WC_Order $order ): string {
		$currency = (string) ( $payload['currency'] ?? $order->get_currency() );

		switch ( $state ) {
			case 'succeeded':
				$note = __( 'Snap Pay: payment held in escrow. Delivery confirmation required. Code issued to customer.', 'snap-pay-for-woocommerce' );

				if ( isset( $payload['display_currency'], $payload['display_amount_minor'] ) ) {
					$note .= ' ' . sprintf(
						/* translators: %s: amount the customer paid, in their local currency. */
						__( 'Customer paid %s.', 'snap-pay-for-woocommerce' ),
						Money::format_plain( (int) $payload['display_amount_minor'], (string) $payload['display_currency'] )
					);
				}

				return $note;

			case 'delivered':
				$parts = array( __( 'Snap Pay: delivery confirmed. Funds released.', 'snap-pay-for-woocommerce' ) );

				if ( isset( $payload['fee_minor'] ) ) {
					$parts[] = sprintf(
						/* translators: %s: fee amount. */
						__( 'Fee: %s.', 'snap-pay-for-woocommerce' ),
						Money::format_plain( (int) $payload['fee_minor'], $currency )
					);
				}

				if ( isset( $payload['available_to_merchant_minor'] ) ) {
					$parts[] = sprintf(
						/* translators: %s: amount available to the merchant now. */
						__( 'Available now: %s.', 'snap-pay-for-woocommerce' ),
						Money::format_plain( (int) $payload['available_to_merchant_minor'], $currency )
					);
				}

				if ( isset( $payload['reserve_minor'] ) ) {
					$parts[] = sprintf(
						/* translators: %s: reserve amount held for 30 days. */
						__( 'Reserve: %s (released after 30 days).', 'snap-pay-for-woocommerce' ),
						Money::format_plain( (int) $payload['reserve_minor'], $currency )
					);
				}

				if ( 'support' === ( $payload['verified_by'] ?? '' ) ) {
					$parts[] = __( 'Verified by Snap Pay support override.', 'snap-pay-for-woocommerce' );
				}

				return implode( ' ', $parts );

			case 'failed':
				return sprintf(
					/* translators: %s: failure reason from the platform. */
					__( 'Snap Pay: payment failed. %s', 'snap-pay-for-woocommerce' ),
					isset( $payload['reason'] ) ? (string) $payload['reason'] : __( 'No reason given.', 'snap-pay-for-woocommerce' )
				);

			case 'cancelled':
				return __( 'Snap Pay: customer declined the payment prompt.', 'snap-pay-for-woocommerce' );

			case 'timed_out':
				return __( 'Snap Pay: payment timed out awaiting confirmation. Awaiting platform reconciliation — do not re-charge.', 'snap-pay-for-woocommerce' );

			case 'settled':
				return __( 'Snap Pay: funds settled to your Snap Pay wallet.', 'snap-pay-for-woocommerce' );

			case 'withdrawn':
				return __( 'Snap Pay: funds withdrawn from your Snap Pay wallet.', 'snap-pay-for-woocommerce' );

			default:
				return sprintf(
					/* translators: %s: platform state name. */
					__( 'Snap Pay: state is now %s.', 'snap-pay-for-woocommerce' ),
					$state
				);
		}
	}

	/* ---------------------------------------------------------------------
	 * Webhook replay guard
	 * ------------------------------------------------------------------ */
	/**
	 * True when this event_id has already been processed.
	 *
	 * Read-only on purpose. The documented verification order puts the replay guard
	 * before the HMAC so a replay is rejected without secret-dependent work
	 * (PLATFORM-API.md section 2.2), but the marker must NOT be written there: an
	 * unauthenticated caller could then post arbitrary event ids and fill the options
	 * table with 24-hour transients. Checking is free; recording is a privilege, so
	 * remember_event() runs only after the signature has passed.
	 */
	public function seen_event( string $event_id ): bool {
		if ( '' === $event_id ) {
			return false;
		}

		return false !== get_transient( $this->event_key( $event_id ) );
	}

	/**
	 * Records an event_id so a redelivery of it becomes a no-op.
	 *
	 * Only ever called on a request whose signature verified. The 24h window matches
	 * SECURITY.md section 3; webhooks are at-least-once, so duplicates are expected and
	 * must be harmless (PLATFORM-API.md section 5.5).
	 */
	public function remember_event( string $event_id ): void {
		if ( '' === $event_id ) {
			return;
		}

		set_transient( $this->event_key( $event_id ), 1, DAY_IN_SECONDS );
	}

	/**
	 * Transient key for an event id.
	 */
	private function event_key( string $event_id ): string {
		return 'snappay_evt_' . md5( $event_id );
	}

	/**
	 * True when the order was paid with a Snap Pay gateway.
	 */
	public function is_snappay_order( WC_Order $order ): bool {
		return 0 === strpos( (string) $order->get_payment_method(), 'snappay_' );
	}

	/**
	 * Reads an integer meta value.
	 */
	public function int_meta( WC_Order $order, string $key ): int {
		$value = $order->get_meta( $key );

		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * Reads a string meta value.
	 */
	public function string_meta( WC_Order $order, string $key ): string {
		$value = $order->get_meta( $key );

		return is_scalar( $value ) ? (string) $value : '';
	}
}
