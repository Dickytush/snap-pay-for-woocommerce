<?php
/**
 * Country, currency and provider reference data.
 *
 * @package SnapPay
 */

declare(strict_types=1);

namespace SnapPay\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Static reference data for the supported corridors.
 *
 * The authoritative list of providers a given merchant may use comes from the
 * platform (stored via Plugin::enabled_providers()). This class only describes
 * which countries and currencies each provider serves, so the gateway filter can
 * answer "does this provider serve the customer's billing country?"
 * (CUSTOMER-COUNTRY-DETECTION.md section 7.1, rule 1).
 */
final class Countries {

	/**
	 * Provider definitions keyed by the platform's provider_id.
	 *
	 * @return array<string, array{label: string, country: string, currency: string, gateway_id: string}>
	 */
	public static function providers(): array {
		$providers = array(
			'mpesa_ke'  => array(
				'label'      => 'M-Pesa',
				'country'    => 'KE',
				'currency'   => 'KES',
				'gateway_id' => 'snappay_mpesa_ke',
				'icon'       => 'mpesa.png',
			),
			'airtel_ke' => array(
				'label'      => 'Airtel Money',
				'country'    => 'KE',
				'currency'   => 'KES',
				'gateway_id' => 'snappay_airtel_ke',
				'icon'       => 'airtel-money.png',
			),
			'mtn_ug'    => array(
				'label'      => 'MTN Mobile Money',
				'country'    => 'UG',
				'currency'   => 'UGX',
				'gateway_id' => 'snappay_mtn_ug',
				'icon'       => 'mtn-momo.png',
			),
			'airtel_ug' => array(
				'label'      => 'Airtel Money',
				'country'    => 'UG',
				'currency'   => 'UGX',
				'gateway_id' => 'snappay_airtel_ug',
				'icon'       => 'airtel-money.png',
			),
		);

		return (array) apply_filters( 'snappay_providers', $providers );
	}

	/**
	 * Absolute URL of a provider's logo, or '' when it has none.
	 *
	 * These are the payment networks' own marks, shown beside the method the customer
	 * is choosing. That is identification, which is what the marks are for — but the
	 * files ship in the plugin, so if a provider's brand guidelines require different
	 * artwork, swap the file or override the URL with the snappay_provider_icon filter
	 * rather than editing this list.
	 */
	public static function icon_url( string $provider_id ): string {
		$definition = self::provider( $provider_id );
		$file       = $definition['icon'] ?? '';

		$url = '' === $file ? '' : \SnapPay\Plugin::asset_url( 'assets/images/' . $file );

		return (string) apply_filters( 'snappay_provider_icon', $url, $provider_id );
	}

	/**
	 * Provider definition or null.
	 *
	 * @return array{label: string, country: string, currency: string, gateway_id: string, icon?: string}|null
	 */
	public static function provider( string $provider_id ): ?array {
		$providers = self::providers();

		return $providers[ $provider_id ] ?? null;
	}

	/**
	 * Resolves a gateway ID back to its provider ID.
	 */
	public static function provider_id_for_gateway( string $gateway_id ): ?string {
		foreach ( self::providers() as $provider_id => $definition ) {
			if ( $definition['gateway_id'] === $gateway_id ) {
				return (string) $provider_id;
			}
		}

		return null;
	}

	/**
	 * Countries a provider serves.
	 *
	 * @return string[]
	 */
	public static function provider_countries( string $provider_id ): array {
		$definition = self::provider( $provider_id );

		return null === $definition ? array() : array( $definition['country'] );
	}

	/**
	 * ISO country -> local currency for the corridors we support.
	 */
	public static function currency_for_country( string $country ): ?string {
		$map = (array) apply_filters(
			'snappay_country_currency_map',
			array(
				'KE' => 'KES',
				'UG' => 'UGX',
			)
		);

		$country = strtoupper( $country );

		return isset( $map[ $country ] ) ? (string) $map[ $country ] : null;
	}

	/**
	 * Countries the plugin will attempt local-currency display for.
	 *
	 * @return string[]
	 */
	public static function supported_countries(): array {
		return array_keys( (array) apply_filters( 'snappay_country_currency_map', array( 'KE' => 'KES', 'UG' => 'UGX' ) ) );
	}

	/**
	 * Normalises a phone number to E.164 for the given billing country.
	 *
	 * PLATFORM-API.md section 4.2 requires E.164; the platform validates too, but
	 * normalising here gives the customer an inline error instead of a 400.
	 *
	 * @return string Normalised number, or '' when it cannot be normalised confidently.
	 */
	public static function to_e164( string $phone, string $country ): string {
		$digits = preg_replace( '/[^0-9+]/', '', $phone );
		$digits = is_string( $digits ) ? $digits : '';

		if ( '' === $digits ) {
			return '';
		}

		$dial_codes = (array) apply_filters(
			'snappay_country_dial_codes',
			array(
				'KE' => '254',
				'UG' => '256',
			)
		);

		$country = strtoupper( $country );
		$code    = isset( $dial_codes[ $country ] ) ? (string) $dial_codes[ $country ] : '';

		// Already E.164.
		if ( 0 === strpos( $digits, '+' ) ) {
			$rest = substr( $digits, 1 );

			return ctype_digit( $rest ) && strlen( $rest ) >= 9 ? '+' . $rest : '';
		}

		if ( '' === $code ) {
			return '';
		}

		// International form without the plus, e.g. 254712345678.
		if ( 0 === strpos( $digits, $code ) ) {
			return '+' . $digits;
		}

		// National trunk form, e.g. 0712345678.
		if ( 0 === strpos( $digits, '0' ) ) {
			return '+' . $code . substr( $digits, 1 );
		}

		// Bare subscriber number, e.g. 712345678.
		if ( strlen( $digits ) >= 9 ) {
			return '+' . $code . $digits;
		}

		return '';
	}
}
