<?php
/**
 * Platform API error.
 *
 * @package SnapPay
 */

declare(strict_types=1);

namespace SnapPay\Http;

use Exception;

defined( 'ABSPATH' ) || exit;

/**
 * Carries the platform's structured error shape so callers can branch on the
 * documented error codes (PLATFORM-API.md section 6) instead of parsing messages.
 */
final class ApiException extends Exception {

	/**
	 * HTTP status, or 0 for transport-level failures.
	 *
	 * @var int
	 */
	private int $status;

	/**
	 * Platform error code, e.g. 'fx_lock_expired'.
	 *
	 * @var string
	 */
	private string $error_code;

	/**
	 * Field-level validation details.
	 *
	 * @var array<string, mixed>
	 */
	private array $details;

	/**
	 * True when the request may or may not have been processed (network timeout).
	 *
	 * @var bool
	 */
	private bool $ambiguous;

	/**
	 * @param array<string, mixed> $details   Structured details from the platform.
	 */
	public function __construct(
		string $message,
		int $status = 0,
		string $error_code = 'transport_error',
		array $details = array(),
		bool $ambiguous = false
	) {
		parent::__construct( $message, $status );

		$this->status     = $status;
		$this->error_code = $error_code;
		$this->details    = $details;
		$this->ambiguous  = $ambiguous;
	}

	/**
	 * HTTP status code (0 for transport failures).
	 */
	public function status(): int {
		return $this->status;
	}

	/**
	 * Documented platform error code.
	 */
	public function error_code(): string {
		return $this->error_code;
	}

	/**
	 * Validation details, keyed by field.
	 *
	 * @return array<string, mixed>
	 */
	public function details(): array {
		return $this->details;
	}

	/**
	 * True for a network timeout on a side-effecting call.
	 *
	 * AGENTS.md F6/F7: the plugin must NOT retry and must NOT assume failure. It
	 * surfaces the ambiguity and lets polling or the webhook resolve the truth.
	 */
	public function is_ambiguous(): bool {
		return $this->ambiguous;
	}

	/**
	 * First field-level validation message, when present.
	 */
	public function first_detail(): string {
		foreach ( $this->details as $messages ) {
			if ( is_array( $messages ) && array() !== $messages ) {
				return (string) reset( $messages );
			}

			if ( is_string( $messages ) && '' !== $messages ) {
				return $messages;
			}
		}

		return '';
	}
}
