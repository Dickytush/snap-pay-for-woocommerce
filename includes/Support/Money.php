<?php
/**
 * Minor-unit money conversion.
 *
 * @package SnapPay
 */

declare(strict_types=1);

namespace SnapPay\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Converts between WooCommerce decimal amounts and the integer minor units the
 * Platform API speaks.
 *
 * CODING-STANDARDS.md section 2: money is integer minor units everywhere. Floats are
 * only ever an input format (WooCommerce totals) and are converted at the boundary.
 *
 * Exponent
 * --------
 * PLATFORM-API.md uses a 2-decimal exponent for BOTH currencies in the contract:
 * KES 32,000 -> amount_minor 3200000, and at rate 35 the UGX side is
 * display_amount_minor 112000000 (= UGX 1,120,000 x 100). So minor units are
 * major x 100 regardless of currency.
 *
 * This differs from how UGX is handled on the rails themselves (whole shillings),
 * so the exponent is filterable in case the platform team confirms otherwise. Do
 * not change the default without a matching PLATFORM-API.md update (AGENTS.md F9).
 */
final class Money {

	private const DEFAULT_EXPONENT = 2;

	/**
	 * Minor-unit exponent for a currency.
	 */
	public static function exponent( string $currency ): int {
		$exponent = (int) apply_filters( 'snappay_currency_minor_exponent', self::DEFAULT_EXPONENT, strtoupper( $currency ) );

		return max( 0, min( 4, $exponent ) );
	}

	/**
	 * Decimal major units -> integer minor units.
	 *
	 * Uses string rounding so a float like 32500.005 cannot drift.
	 *
	 * @param float|int|string $amount Major-unit amount.
	 */
	public static function to_minor( $amount, string $currency ): int {
		$factor = 10 ** self::exponent( $currency );

		return (int) round( (float) $amount * $factor );
	}

	/**
	 * Integer minor units -> decimal major units.
	 */
	public static function to_major( int $minor, string $currency ): float {
		$factor = 10 ** self::exponent( $currency );

		return $minor / $factor;
	}

	/**
	 * Formats a minor-unit amount for display using WooCommerce currency formatting.
	 *
	 * @return string HTML-safe formatted price.
	 */
	public static function format( int $minor, string $currency ): string {
		return wp_kses_post(
			wc_price(
				self::to_major( $minor, $currency ),
				array( 'currency' => strtoupper( $currency ) )
			)
		);
	}

	/**
	 * Plain-text (unescaped-HTML-free) formatting, for order notes and emails.
	 */
	public static function format_plain( int $minor, string $currency ): string {
		return sprintf(
			'%s %s',
			strtoupper( $currency ),
			number_format( self::to_major( $minor, $currency ), self::exponent( $currency ), '.', ',' )
		);
	}
}
