<?php
/**
 * Redacting logger.
 *
 * @package SnapPay
 */

declare(strict_types=1);

namespace SnapPay\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Structured logging with mandatory redaction.
 *
 * SECURITY.md section 9 / AGENTS.md F5: delivery codes, API keys, webhook secrets,
 * signatures, full request bodies and unredacted PII must never reach a log line.
 * Everything written here passes through redact() first, and the verify-code path
 * never passes a request body at all.
 */
final class Logger {

	private const SOURCE = 'snappay';

	/**
	 * Keys whose values are dropped entirely, at any nesting depth.
	 */
	private const FORBIDDEN_KEYS = array(
		'code',
		'delivery_code',
		'api_key',
		'authorization',
		'webhook_secret',
		'secret',
		'signature',
		'x-snap-signature',
		'idempotency-key',
		'body',
		'raw_body',
		'password',
	);

	/**
	 * Info-level line.
	 *
	 * @param array<string, mixed> $context Structured context.
	 */
	public static function info( string $message, array $context = array() ): void {
		self::log( 'info', $message, $context );
	}

	/**
	 * Warning-level line.
	 *
	 * @param array<string, mixed> $context Structured context.
	 */
	public static function warning( string $message, array $context = array() ): void {
		self::log( 'warning', $message, $context );
	}

	/**
	 * Error-level line.
	 *
	 * @param array<string, mixed> $context Structured context.
	 */
	public static function error( string $message, array $context = array() ): void {
		self::log( 'error', $message, $context );
	}

	/**
	 * Writes through the WooCommerce logger when available.
	 *
	 * @param array<string, mixed> $context Structured context.
	 */
	private static function log( string $level, string $message, array $context ): void {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		$safe = self::redact( $context );

		wc_get_logger()->log(
			$level,
			$message . ( array() === $safe ? '' : ' ' . wp_json_encode( $safe ) ),
			array( 'source' => self::SOURCE )
		);
	}

	/**
	 * Strips forbidden keys and masks PII.
	 *
	 * @param array<string, mixed> $context Raw context.
	 *
	 * @return array<string, mixed> Safe to persist.
	 */
	public static function redact( array $context ): array {
		$out = array();

		foreach ( $context as $key => $value ) {
			$lower = strtolower( (string) $key );

			if ( in_array( $lower, self::FORBIDDEN_KEYS, true ) ) {
				$out[ $key ] = '[redacted]';
				continue;
			}

			if ( is_array( $value ) ) {
				$out[ $key ] = self::redact( $value );
				continue;
			}

			if ( ! is_scalar( $value ) && null !== $value ) {
				$out[ $key ] = '[object]';
				continue;
			}

			$out[ $key ] = self::mask_value( $lower, (string) $value );
		}

		return $out;
	}

	/**
	 * Masks PII-bearing values by key name.
	 */
	private static function mask_value( string $key, string $value ): string {
		if ( false !== strpos( $key, 'phone' ) ) {
			return self::mask_phone( $value );
		}

		if ( false !== strpos( $key, 'email' ) ) {
			return self::mask_email( $value );
		}

		return $value;
	}

	/**
	 * +254712345678 -> +2547...
	 */
	public static function mask_phone( string $phone ): string {
		if ( strlen( $phone ) <= 5 ) {
			return '[redacted]';
		}

		return substr( $phone, 0, 5 ) . '...';
	}

	/**
	 * jane@example.com -> j...@example.com
	 */
	public static function mask_email( string $email ): string {
		$at = strpos( $email, '@' );

		if ( false === $at || $at < 1 ) {
			return '[redacted]';
		}

		return substr( $email, 0, 1 ) . '...' . substr( $email, $at );
	}
}
