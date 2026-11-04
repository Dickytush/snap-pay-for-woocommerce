<?php
/**
 * Inbound platform webhooks.
 *
 * @package SnapPay
 */

declare(strict_types=1);

namespace SnapPay;

use SnapPay\Support\Logger;
use SnapPay\Support\Money;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Receives signed events at /wp-json/snappay/v1/webhook.
 *
 * The route path is stable across major versions so platform-registered callback
 * URLs survive an upgrade (ADR 0002 decision item 4).
 */
final class WebhookController {

	public const REST_NAMESPACE = 'snappay/v1';
	public const REST_ROUTE     = 'webhook';

	private const TIMESTAMP_TOLERANCE = 300;   // +/- 5 minutes.
	private const MAX_BODY_BYTES      = 65536; // 64 KB, matching the platform edge cap.

	/**
	 * Plugin configuration.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * API client, for the follow-up delivery-code fetch.
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
	 * Registers the REST route.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
	}

	/**
	 * Route definition.
	 */
	public function register_route(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::REST_ROUTE,
			array(
				'methods'  => WP_REST_Server::CREATABLE,
				'callback' => array( $this, 'handle' ),
				/*
				 * This is the one route with no WordPress-side authentication: the
				 * platform has no WordPress credentials. Authenticity comes from the
				 * HMAC check in handle(), which runs before any side effect
				 * (SECURITY.md section 3). Every other route the plugin registers has a
				 * capability check.
				 */
				'permission_callback' => static function (): bool {
					return true;
				},
			)
		);
	}

	/**
	 * Verifies and dispatches one event.
	 *
	 * @param WP_REST_Request $request Inbound request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle( WP_REST_Request $request ) {
		$secret = $this->plugin->webhook_secret();

		if ( '' === $secret ) {
			Logger::error( 'Webhook received but no webhook secret is configured' );

			return new WP_Error( 'snappay_not_configured', __( 'Snap Pay is not configured.', 'snap-pay-for-woocommerce' ), array( 'status' => 503 ) );
		}

		$raw = (string) $request->get_body();

		if ( strlen( $raw ) > self::MAX_BODY_BYTES ) {
			Logger::warning( 'Webhook rejected: body exceeds 64 KB' );

			return new WP_Error( 'snappay_payload_too_large', __( 'Payload too large.', 'snap-pay-for-woocommerce' ), array( 'status' => 413 ) );
		}

		$timestamp = (string) $request->get_header( 'x-snap-timestamp' );
		$event_id  = (string) $request->get_header( 'x-snap-event-id' );
		$signature = (string) $request->get_header( 'x-snap-signature' );

		// ---- Verification order is fixed: cheapest rejection first (AGENTS.md F10).

		// 1. Timestamp window.
		if ( '' === $timestamp || ! is_numeric( $timestamp ) || abs( time() - (int) $timestamp ) > self::TIMESTAMP_TOLERANCE ) {
			Logger::warning( 'Webhook rejected: timestamp outside tolerance', array( 'event_id' => $event_id ) );

			return new WP_Error( 'snappay_stale_timestamp', __( 'Stale or missing timestamp.', 'snap-pay-for-woocommerce' ), array( 'status' => 401 ) );
		}

		// 2. Replay guard — a READ only. A duplicate is acknowledged, not re-processed.
		//    Deliberately does not record anything: writing the marker here would let an
		//    unauthenticated caller post arbitrary event ids and fill the options table
		//    with 24-hour transients. The marker is written after step 3 instead.
		if ( $this->orders->seen_event( $event_id ) ) {
			Logger::info( 'Webhook replay ignored', array( 'event_id' => $event_id ) );

			return new WP_REST_Response( array( 'received' => true, 'duplicate' => true ), 200 );
		}

		// 3. HMAC over the exact raw bytes, constant-time compared.
		$expected = 'sha256=' . hash_hmac( 'sha256', $raw, $secret );

		if ( '' === $signature || ! hash_equals( $expected, $signature ) ) {
			Logger::error( 'Webhook rejected: signature mismatch', array( 'event_id' => $event_id ) );

			return new WP_Error( 'snappay_bad_signature', __( 'Invalid signature.', 'snap-pay-for-woocommerce' ), array( 'status' => 401 ) );
		}

		// Authentic from here on, so it is safe to spend storage remembering it.
		$this->orders->remember_event( $event_id );

		$payload = json_decode( $raw, true );

		if ( ! is_array( $payload ) ) {
			return new WP_Error( 'snappay_bad_payload', __( 'Malformed payload.', 'snap-pay-for-woocommerce' ), array( 'status' => 400 ) );
		}

		$event = isset( $payload['event'] ) ? (string) $payload['event'] : '';

		Logger::info( 'Webhook accepted', array( 'event' => $event, 'event_id' => $event_id, 'transaction_ref' => $payload['transaction_ref'] ?? '' ) );

		$this->dispatch( $event, $payload );

		return new WP_REST_Response( array( 'received' => true ), 200 );
	}

	/**
	 * Routes an event to its handler.
	 *
	 * @param array<string, mixed> $payload Verified payload.
	 */
	private function dispatch( string $event, array $payload ): void {
		$order = $this->resolve_order( $payload );

		if ( null === $order ) {
			Logger::warning( 'Webhook could not be matched to an order', array( 'event' => $event, 'transaction_ref' => $payload['transaction_ref'] ?? '' ) );

			return;
		}

		switch ( $event ) {
			case 'transaction.requested':
			case 'transaction.succeeded':
			case 'transaction.failed':
			case 'delivery.verified':
			case 'transaction.settled':
			case 'transaction.withdrawn':
				$state = isset( $payload['state'] ) ? (string) $payload['state'] : '';

				if ( '' !== $state ) {
					$this->orders->apply_state( $order, $state, $payload );
				}
				break;

			case 'reserve.released':
				// OPEN-QUESTIONS.md B3 default: surface as an order note; the Laravel
				// dashboard remains the primary display.
				$currency = $this->orders->string_meta( $order, OrderRepository::META_CURRENCY );
				$currency = '' !== $currency ? $currency : $order->get_currency();

				$order->add_order_note(
					sprintf(
						/* translators: 1: reserve amount released, 2: total available to the merchant. */
						__( 'Snap Pay: reserve of %1$s released and is now available for withdrawal. Available: %2$s.', 'snap-pay-for-woocommerce' ),
						Money::format_plain( (int) ( $payload['reserve_minor'] ?? 0 ), $currency ),
						Money::format_plain( (int) ( $payload['available_to_merchant_minor'] ?? 0 ), $currency )
					)
				);

				$order->update_meta_data( OrderRepository::META_RESERVE_MINOR, 0 );
				$order->save();
				break;

			default:
				Logger::warning( 'Unhandled webhook event type', array( 'event' => $event ) );
		}

		do_action( 'snappay_webhook_handled', $event, $payload, $order );
	}

	/**
	 * Matches a payload to a WooCommerce order.
	 *
	 * @param array<string, mixed> $payload Verified payload.
	 */
	private function resolve_order( array $payload ): ?\WC_Order {
		$order_id  = isset( $payload['order_id'] ) ? (string) $payload['order_id'] : '';
		$order_key = isset( $payload['order_key'] ) ? (string) $payload['order_key'] : '';

		if ( '' !== $order_id ) {
			$order = $this->orders->find( $order_id, $order_key );

			if ( null !== $order ) {
				return $order;
			}
		}

		return $this->orders->find_by_transaction_ref( isset( $payload['transaction_ref'] ) ? (string) $payload['transaction_ref'] : '' );
	}
}
