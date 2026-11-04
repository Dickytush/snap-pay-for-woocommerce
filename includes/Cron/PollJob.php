<?php
/**
 * Drift repair for missed webhooks.
 *
 * @package SnapPay
 */

declare(strict_types=1);

namespace SnapPay\Cron;

use SnapPay\Http\ApiException;
use SnapPay\OrderRepository;
use SnapPay\PlatformApiClient;
use SnapPay\Plugin;
use SnapPay\Support\Logger;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Compares open orders against the platform and mirrors any state we missed.
 *
 * This is NOT reconciliation - reconciliation is the platform's daily ledger job
 * (ARCHITECTURE.md section 5.9). This only repairs display drift when a webhook was
 * lost, and the platform always wins.
 *
 * It can only ever move an order into a state the platform already reports, so it
 * cannot release funds. There is no timer-based release path anywhere in this plugin
 * (AGENTS.md F1, DELIVERY-CODE-FLOW.md section 11).
 */
final class PollJob {

	public const HOOK = 'snappay_poll_transactions';

	private const BATCH_SIZE = 25;
	private const MAX_AGE_DAYS = 45;

	/**
	 * States worth polling. Terminal states are skipped.
	 *
	 * @var string[]
	 */
	private const OPEN_STATES = array( 'initiated', 'requested', 'succeeded', 'timed_out' );

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
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'init', array( self::class, 'schedule' ) );
	}

	/**
	 * Ensures the event exists.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::HOOK );
		}
	}

	/**
	 * Clears the event.
	 */
	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::HOOK );

		while ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
			$timestamp = wp_next_scheduled( self::HOOK );
		}
	}

	/**
	 * Polls a batch of open orders.
	 */
	public function run(): void {
		if ( ! $this->plugin->is_connected() ) {
			return;
		}

		foreach ( $this->open_orders() as $order ) {
			$ref = $this->orders->string_meta( $order, OrderRepository::META_TRANSACTION_REF );

			if ( '' === $ref ) {
				continue;
			}

			try {
				$transaction = $this->api->get_transaction( $ref );
			} catch ( ApiException $e ) {
				Logger::warning( 'Poll failed for transaction', array( 'order_id' => $order->get_id(), 'error' => $e->error_code() ) );

				continue;
			}

			$remote = isset( $transaction['state'] ) ? strtolower( (string) $transaction['state'] ) : '';
			$local  = $this->orders->string_meta( $order, OrderRepository::META_STATE );

			if ( '' === $remote || $remote === $local ) {
				continue;
			}

			Logger::info( 'Poll repaired state drift', array( 'order_id' => $order->get_id(), 'from' => $local, 'to' => $remote ) );

			$this->orders->apply_state( $order, $remote, $transaction );
		}
	}

	/**
	 * Orders still awaiting a terminal platform state.
	 *
	 * @return WC_Order[]
	 */
	private function open_orders(): array {
		$orders = wc_get_orders(
			array(
				'limit'        => self::BATCH_SIZE,
				'orderby'      => 'date',
				'order'        => 'ASC',
				'date_created' => '>' . ( time() - ( self::MAX_AGE_DAYS * DAY_IN_SECONDS ) ),
				'meta_query'   => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => OrderRepository::META_STATE,
						'value'   => self::OPEN_STATES,
						'compare' => 'IN',
					),
				),
			)
		);

		if ( ! is_array( $orders ) ) {
			return array();
		}

		return array_values(
			array_filter(
				$orders,
				static function ( $order ): bool {
					return $order instanceof WC_Order;
				}
			)
		);
	}
}
