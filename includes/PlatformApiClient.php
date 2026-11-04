<?php
/**
 * The plugin's only HTTP egress.
 *
 * @package SnapPay
 */

declare(strict_types=1);

namespace SnapPay;

use SnapPay\Http\ApiException;
use SnapPay\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps the Platform API contract (PLATFORM-API.md).
 *
 * ARCHITECTURE.md section 2.3 / AGENTS.md F4: no other component may open an HTTP
 * connection to Snap Pay, and nothing anywhere may call Safaricom, Airtel or MTN.
 * There are exactly two permitted base URLs and both are HTTPS.
 */
final class PlatformApiClient {

	/**
	 * The documented contract hosts (PLATFORM-API.md section 1).
	 *
	 * These are the values the API contract specifies and are what a public release
	 * must use. They can be overridden per site — see base_url() — because during
	 * the beta the platform is reachable on a different hostname and a plugin that
	 * cannot be pointed at a working host is a plugin that cannot be tested.
	 */
	private const BASE_LIVE    = 'https://api.snapcashpay.com/api/v1/';
	private const BASE_SANDBOX = 'https://sandbox-api.snapcashpay.com/api/v1/';

	private const TIMEOUT_READ  = 15;
	private const TIMEOUT_WRITE = 30;

	/**
	 * Plugin configuration source.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * @param Plugin $plugin Configuration source.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/* ---------------------------------------------------------------------
	 * 4.1 Lock FX rate
	 * ------------------------------------------------------------------ */

	/**
	 * POST /merchant/fx/lock-rate
	 *
	 * Locks a rate for 15 minutes at checkout. Scope: fx:lock.
	 *
	 * @param int $amount_minor Cart total in store currency, minor units.
	 *
	 * @return array<string, mixed> Lock payload: lock_id, rate, converted_amount_minor, expires_at.
	 *
	 * @throws ApiException On any non-2xx response or transport failure.
	 */
	public function lock_fx_rate( string $from_currency, string $to_currency, int $amount_minor ): array {
		return $this->post(
			'merchant/fx/lock-rate',
			array(
				'from_currency' => strtoupper( $from_currency ),
				'to_currency'   => strtoupper( $to_currency ),
				'amount_minor'  => $amount_minor,
			),
			false
		);
	}

	/* ---------------------------------------------------------------------
	 * 4.2 Initiate collect
	 * ------------------------------------------------------------------ */

	/**
	 * POST /merchant/collect
	 *
	 * A fresh Idempotency-Key is generated per call because each call corresponds to
	 * one distinct user action (a "Place order" click). On a network timeout this
	 * throws an ambiguous ApiException and the caller must NOT retry (AGENTS.md F6/F7).
	 *
	 * @param array<string, mixed> $payload Collect request body.
	 *
	 * @return array<string, mixed> 202 response body.
	 *
	 * @throws ApiException On any non-2xx response or transport failure.
	 */
	public function collect( array $payload ): array {
		return $this->post( 'merchant/collect', $payload, true );
	}

	/* ---------------------------------------------------------------------
	 * 4.3 Transaction status
	 * ------------------------------------------------------------------ */

	/**
	 * GET /merchant/transactions/{transaction_ref}
	 *
	 * @return array<string, mixed> Transaction resource.
	 *
	 * @throws ApiException On any non-2xx response or transport failure.
	 */
	public function get_transaction( string $transaction_ref ): array {
		return $this->get( 'merchant/transactions/' . rawurlencode( $transaction_ref ) );
	}

	/* ---------------------------------------------------------------------
	 * 4.4 Fetch delivery code (for the order email only)
	 * ------------------------------------------------------------------ */

	/**
	 * GET /merchant/delivery-code
	 *
	 * Returns the plaintext code. SECURITY.md section 4: the caller may use it only to
	 * compose the customer email. It is never persisted, never logged and never
	 * rendered into wp-admin.
	 *
	 * @return array<string, mixed> { code, expires_at, status }
	 *
	 * @throws ApiException On any non-2xx response or transport failure.
	 */
	public function get_delivery_code( string $order_id, string $order_key ): array {
		return $this->get(
			'merchant/delivery-code',
			array(
				'order_id'  => $order_id,
				'order_key' => $order_key,
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * 4.5 Verify delivery code
	 * ------------------------------------------------------------------ */

	/**
	 * POST /merchant/verify-delivery-code
	 *
	 * The plaintext code is passed straight through and discarded. This request is
	 * excluded from body logging by construction — request bodies are never logged.
	 *
	 * @return array<string, mixed> Settlement breakdown on success.
	 *
	 * @throws ApiException On any non-2xx response or transport failure.
	 */
	public function verify_delivery_code( string $order_id, string $order_key, string $code ): array {
		return $this->post(
			'merchant/verify-delivery-code',
			array(
				'order_id'  => $order_id,
				'order_key' => $order_key,
				'code'      => $code,
			),
			false
		);
	}

	/* ---------------------------------------------------------------------
	 * 4.6 List transactions
	 * ------------------------------------------------------------------ */

	/**
	 * GET /merchant/transactions
	 *
	 * @param array<string, mixed> $query Filters: order_id, state, page, per_page.
	 *
	 * @return array<string, mixed> { data, meta }
	 *
	 * @throws ApiException On any non-2xx response or transport failure.
	 */
	public function list_transactions( array $query = array() ): array {
		return $this->get( 'merchant/transactions', $query );
	}

	/* ---------------------------------------------------------------------
	 * 4.7 Wallet
	 * ------------------------------------------------------------------ */

	/**
	 * GET /merchant/wallet
	 *
	 * Also used by the setup wizard as the connection validation call
	 * (END-TO-END-FLOW.md Part 1, Step 4).
	 *
	 * @return array<string, mixed> Wallet resource.
	 *
	 * @throws ApiException On any non-2xx response or transport failure.
	 */
	public function get_wallet(): array {
		return $this->get( 'merchant/wallet' );
	}

	/* ---------------------------------------------------------------------
	 * 4.8 - 4.10 Withdrawals
	 *
	 * Wrapped for contract completeness only. OPEN-QUESTIONS.md B6 default:
	 * withdrawals are Laravel-dashboard-only and plugin keys are issued WITHOUT
	 * withdrawal scopes. Accordingly nothing in this plugin calls request_withdrawal()
	 * and no withdrawal UI ships. Do not wire one up before B6 is resolved.
	 * ------------------------------------------------------------------ */

	/**
	 * POST /merchant/withdrawals
	 *
	 * @param array<string, mixed> $payload amount_minor, currency, method, destination.
	 *
	 * @return array<string, mixed> Withdrawal resource.
	 *
	 * @throws ApiException On any non-2xx response or transport failure.
	 */
	public function request_withdrawal( array $payload ): array {
		return $this->post( 'merchant/withdrawals', $payload, true );
	}

	/**
	 * GET /merchant/withdrawals/{withdrawal_ref}
	 *
	 * @return array<string, mixed> Withdrawal resource.
	 *
	 * @throws ApiException On any non-2xx response or transport failure.
	 */
	public function get_withdrawal( string $withdrawal_ref ): array {
		return $this->get( 'merchant/withdrawals/' . rawurlencode( $withdrawal_ref ) );
	}

	/**
	 * GET /merchant/withdrawals
	 *
	 * @param array<string, mixed> $query Pagination.
	 *
	 * @return array<string, mixed> { data, meta }
	 *
	 * @throws ApiException On any non-2xx response or transport failure.
	 */
	public function list_withdrawals( array $query = array() ): array {
		return $this->get( 'merchant/withdrawals', $query );
	}

	/* ---------------------------------------------------------------------
	 * Transport
	 * ------------------------------------------------------------------ */

	/**
	 * Base URL for the configured environment.
	 *
	 * Resolution order, first match wins:
	 *   1. the SNAPPAY_API_BASE_URL constant in wp-config.php  (per-site, not editable in wp-admin)
	 *   2. the API base URL saved in Snap Pay settings          (per-environment)
	 *   3. the snappay_api_base_url filter                      (for other plugins/tests)
	 *   4. the documented contract host for this environment
	 *
	 * Always HTTPS and always trailing-slashed, whichever wins — the rest of this
	 * class concatenates paths onto it, and assert_ready() refuses non-HTTPS.
	 */
	public function base_url(): string {
		$environment = $this->plugin->environment();
		$default     = 'live' === $environment ? self::BASE_LIVE : self::BASE_SANDBOX;

		$override = '';

		if ( defined( 'SNAPPAY_API_BASE_URL' ) && is_string( SNAPPAY_API_BASE_URL ) ) {
			$override = SNAPPAY_API_BASE_URL;
		}

		if ( '' === $override ) {
			$override = $this->plugin->api_base_override();
		}

		$base = '' !== $override ? $override : $default;

		$base = (string) apply_filters( 'snappay_api_base_url', $base, $environment );

		return trailingslashit( trim( $base ) );
	}

	/**
	 * True when the plugin is pointed somewhere other than the contract host.
	 * Surfaced in settings so an overridden host is never a mystery.
	 */
	public function is_using_custom_base(): bool {
		$default = 'live' === $this->plugin->environment() ? self::BASE_LIVE : self::BASE_SANDBOX;

		return trailingslashit( $default ) !== $this->base_url();
	}

	/**
	 * The contract host for the current environment, for display next to an override.
	 */
	public function default_base_url(): string {
		return 'live' === $this->plugin->environment() ? self::BASE_LIVE : self::BASE_SANDBOX;
	}

	/**
	 * The webhook callback URL registered with each collect call.
	 */
	public function callback_url(): string {
		return rest_url( WebhookController::REST_NAMESPACE . '/' . WebhookController::REST_ROUTE );
	}

	/**
	 * Idempotent read. Safe to retry on 429/5xx.
	 *
	 * @param array<string, mixed> $query Query parameters.
	 *
	 * @return array<string, mixed> Decoded response body.
	 *
	 * @throws ApiException On any non-2xx response or transport failure.
	 */
	private function get( string $path, array $query = array() ): array {
		$url = $this->base_url() . ltrim( $path, '/' );

		if ( array() !== $query ) {
			$url = add_query_arg( array_map( 'strval', $query ), $url );
		}

		return $this->request( 'GET', $url, null, array(), true );
	}

	/**
	 * Side-effecting write. Never auto-retried.
	 *
	 * @param array<string, mixed> $body            JSON body.
	 * @param bool                 $with_idempotency Send a fresh Idempotency-Key.
	 *
	 * @return array<string, mixed> Decoded response body.
	 *
	 * @throws ApiException On any non-2xx response or transport failure.
	 */
	private function post( string $path, array $body, bool $with_idempotency ): array {
		$headers = array();

		if ( $with_idempotency ) {
			// A fresh UUIDv4 per distinct user action. Never reused, never replayed
			// after a timeout (PLATFORM-API.md section 3, AGENTS.md F7).
			$headers['Idempotency-Key'] = wp_generate_uuid4();
		}

		return $this->request( 'POST', $this->base_url() . ltrim( $path, '/' ), $body, $headers, false );
	}

	/**
	 * Performs the HTTP call, enforcing the fail-closed preconditions first.
	 *
	 * @param array<string, mixed>|null $body        JSON body, or null for GET.
	 * @param array<string, string>     $extra       Additional headers.
	 * @param bool                      $retry_safe  Whether transient failures may be retried.
	 *
	 * @return array<string, mixed> Decoded response body.
	 *
	 * @throws ApiException On any non-2xx response or transport failure.
	 */
	private function request( string $method, string $url, ?array $body, array $extra, bool $retry_safe ): array {
		$this->assert_ready( $url );

		$args = array(
			'method'    => $method,
			'timeout'   => null === $body ? self::TIMEOUT_READ : self::TIMEOUT_WRITE,
			'sslverify' => true, // Never disabled. There is no dev-mode bypass.
			'headers'   => array_merge(
				array(
					'Authorization' => 'Bearer ' . $this->plugin->api_key(),
					'X-Store-Url'   => $this->plugin->store_url(),
					'Accept'        => 'application/json',
					'User-Agent'    => 'SnapPay-WooCommerce/' . SNAPPAY_VERSION . '; ' . $this->plugin->store_url(),
				),
				$extra
			),
		);

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = (string) wp_json_encode( $body );
		}

		$attempt  = 0;
		$max_tries = $retry_safe ? 3 : 1;

		while ( true ) {
			++$attempt;

			$response = wp_remote_request( $url, $args );

			if ( is_wp_error( $response ) ) {
				$is_timeout = $this->is_timeout_error( $response );

				// A timeout on a write means the platform may or may not have acted.
				// Do not retry; surface the ambiguity (AGENTS.md F6).
				if ( ! $retry_safe ) {
					Logger::error(
						'Platform request failed',
						array(
							'method'  => $method,
							'path'    => $this->path_of( $url ),
							'timeout' => $is_timeout ? 'yes' : 'no',
						)
					);

					throw new ApiException(
						(bool) $is_timeout
							? esc_html__( 'Snap Pay did not respond in time. The payment may still be processing — do not retry; the order will update automatically.', 'snap-pay-for-woocommerce' )
							: esc_html( $response->get_error_message() ),
						0,
						(bool) $is_timeout ? 'network_timeout' : 'transport_error',
						array(),
						(bool) $is_timeout
					);
				}

				if ( $attempt >= $max_tries ) {
					// A host that does not resolve is a configuration problem, not a
					// transient one. Say so plainly instead of surfacing a raw cURL
					// string — this is the first thing a mis-pointed site hits.
					if ( $this->is_unreachable_host_error( $response ) ) {
						throw new ApiException(
							sprintf(
								/* translators: %s: the API base URL currently configured. */
								esc_html__( 'Could not reach the Snap Pay API at %s. Check the API base URL in Snap Pay settings.', 'snap-pay-for-woocommerce' ),
								esc_html( $this->base_url() )
							),
							0,
							'api_host_unreachable'
						);
					}

					throw new ApiException( esc_html( $response->get_error_message() ), 0, 'transport_error' );
				}

				$this->backoff( $attempt );
				continue;
			}

			$status = (int) wp_remote_retrieve_response_code( $response );
			$raw    = (string) wp_remote_retrieve_body( $response );
			$decoded = json_decode( $raw, true );
			$decoded = is_array( $decoded ) ? $decoded : array();

			if ( $status >= 200 && $status < 300 ) {
				return $decoded;
			}

			// Retry-safe transient statuses only, and only on idempotent reads.
			if ( $retry_safe && $attempt < $max_tries && in_array( $status, array( 429, 500, 502, 503, 504 ), true ) ) {
				$this->backoff( $attempt );
				continue;
			}

			throw $this->to_exception( $status, $decoded, $method, $url );
		}
	}

	/**
	 * Fail-closed preconditions, checked before any bytes leave the server.
	 *
	 * @throws ApiException When the plugin is not safely configured.
	 */
	private function assert_ready( string $url ): void {
		if ( 0 !== strpos( $url, 'https://' ) ) {
			throw new ApiException( esc_html__( 'Snap Pay refuses non-HTTPS API endpoints.', 'snap-pay-for-woocommerce' ), 0, 'insecure_transport' );
		}

		if ( '' === $this->plugin->api_key() ) {
			throw new ApiException( esc_html__( 'Snap Pay is not configured: no API key.', 'snap-pay-for-woocommerce' ), 401, 'unauthenticated' );
		}

		// Sandbox/live separation is enforced in both directions, locally as well as
		// by the platform (SECURITY.md section 2).
		if ( ! $this->plugin->key_matches_environment() ) {
			throw new ApiException(
				sprintf(
					/* translators: %s: configured environment name. */
					esc_html__( 'The saved API key does not match the selected %s environment. Re-enter the correct key.', 'snap-pay-for-woocommerce' ),
					esc_html( $this->plugin->environment() )
				),
				0,
				'environment_mismatch'
			);
		}
	}

	/**
	 * Maps a platform error response onto the documented error table.
	 *
	 * @param array<string, mixed> $decoded Decoded error body.
	 */
	private function to_exception( int $status, array $decoded, string $method, string $url ): ApiException {
		$error_code = isset( $decoded['error'] ) ? (string) $decoded['error'] : $this->default_code_for( $status );
		$message    = isset( $decoded['message'] ) ? (string) $decoded['message'] : $this->default_message_for( $status );
		$details    = isset( $decoded['details'] ) && is_array( $decoded['details'] ) ? $decoded['details'] : array();

		// Carry through the extra fields the error table tells us to surface.
		foreach ( array( 'existing_transaction_ref', 'lock_id', 'expired_at', 'supported_pairs', 'minimum_minor', 'limit', 'suggested_method', 'withdrawable_minor', 'reserve_minor' ) as $key ) {
			if ( isset( $decoded[ $key ] ) ) {
				$details[ $key ] = $decoded[ $key ];
			}
		}

		Logger::warning(
			'Platform returned an error',
			array(
				'method' => $method,
				'path'   => $this->path_of( $url ),
				'status' => $status,
				'error'  => $error_code,
			)
		);

		return new ApiException( $message, $status, $error_code, $details );
	}

	/**
	 * Fallback error code when the platform sends none.
	 */
	private function default_code_for( int $status ): string {
		$map = array(
			400 => 'validation_failed',
			401 => 'unauthenticated',
			403 => 'unauthorized',
			404 => 'not_found',
			409 => 'duplicate_order',
			410 => 'code_consumed',
			422 => 'unprocessable',
			429 => 'rate_limited',
			500 => 'internal_error',
			503 => 'service_unavailable',
		);

		return $map[ $status ] ?? 'unexpected_status';
	}

	/**
	 * Fallback human message when the platform sends none.
	 */
	private function default_message_for( int $status ): string {
		return sprintf(
			/* translators: %d: HTTP status code. */
			__( 'Snap Pay returned an unexpected response (HTTP %d).', 'snap-pay-for-woocommerce' ),
			$status
		);
	}

	/**
	 * True when the host could not be resolved or refused the connection —
	 * i.e. the plugin is pointed somewhere that is not there.
	 *
	 * @param \WP_Error $error Transport error.
	 */
	private function is_unreachable_host_error( $error ): bool {
		$message = strtolower( $error->get_error_message() );

		foreach ( array( 'could not resolve host', 'couldn\'t resolve host', 'name or service not known', 'connection refused', 'failed to connect' ) as $needle ) {
			if ( false !== strpos( $message, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * True when a WP_Error looks like a connection timeout.
	 *
	 * @param \WP_Error $error Transport error.
	 */
	private function is_timeout_error( $error ): bool {
		$message = strtolower( $error->get_error_message() );

		return false !== strpos( $message, 'timed out' )
			|| false !== strpos( $message, 'timeout' )
			|| false !== strpos( $message, 'operation aborted' );
	}

	/**
	 * Short capped backoff. Deliberately not the platform's full retry ladder — a
	 * front-end request must not block for 30 seconds. Longer recovery is the job of
	 * the poll reconciler.
	 */
	private function backoff( int $attempt ): void {
		usleep( min( 4, 2 ** $attempt ) * 250000 );
	}

	/**
	 * Path portion of a URL, for log lines that must not include query strings.
	 */
	private function path_of( string $url ): string {
		$path = wp_parse_url( $url, PHP_URL_PATH );

		return is_string( $path ) ? $path : '';
	}
}
