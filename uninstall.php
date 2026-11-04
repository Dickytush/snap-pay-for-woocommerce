<?php
/**
 * Uninstall routine.
 *
 * Removes plugin options and transients only. Escrow state, balances, reserves and
 * withdrawals live on the Snap Pay platform, so uninstalling never strands funds
 * (SECURITY.md section 8, ARCHITECTURE.md invariant 4). Order meta is left in place so
 * historical orders keep rendering their Snap Pay details.
 *
 * @package SnapPay
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$snappay_options = array(
	'snappay_environment',
	'snappay_api_key',
	'snappay_webhook_secret',
	'snappay_store_url',
	'snappay_connection_validated',
	'snappay_enabled_providers',
	'snappay_merchant_snapshot',
	'snappay_db_version',
	'snappay_upgraded_from_v1',
	'woocommerce_snappay_mpesa_ke_settings',
	'woocommerce_snappay_airtel_ke_settings',
	'woocommerce_snappay_mtn_ug_settings',
	'woocommerce_snappay_airtel_ug_settings',
);

foreach ( $snappay_options as $snappay_option ) {
	delete_option( $snappay_option );
}

wp_clear_scheduled_hook( 'snappay_poll_transactions' );

// Drop our transients (webhook replay markers, FX display cache, action mutexes).
global $wpdb;

$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_snappay_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_snappay_' ) . '%'
	)
);
