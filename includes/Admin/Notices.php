<?php
/**
 * Admin notices.
 *
 * @package SnapPay
 */

declare(strict_types=1);

namespace SnapPay\Admin;

use SnapPay\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Surfaces configuration problems and the results of order-screen actions.
 */
final class Notices {

	/**
	 * Screens where a Snap Pay configuration notice is on-topic.
	 *
	 * Guideline 11: a notice belongs on the screens it concerns, not on every page of
	 * wp-admin. Someone editing a post does not need to hear about a payment gateway.
	 * The Plugins screen is included because that is where activation lands, and the
	 * Dashboard because it is the one screen a store owner reliably visits.
	 */
	private const NOTICE_SCREENS = array( 'plugins', 'dashboard' );

	/**
	 * Hooks in.
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render_action_result' ) );
		add_action( 'admin_notices', array( $this, 'render_connection_warning' ) );
	}

	/**
	 * Registers the "WooCommerce missing" notice only.
	 */
	public function register_missing_woocommerce(): void {
		add_action(
			'admin_notices',
			function (): void {
				if ( ! current_user_can( 'activate_plugins' ) || ! $this->on_relevant_screen() ) {
					return;
				}

				echo '<div class="notice notice-error is-dismissible"><p>';
				echo esc_html__( 'Snap Pay needs WooCommerce to be installed and active.', 'snap-pay-for-woocommerce' );
				echo '</p></div>';
			}
		);
	}

	/**
	 * Shows the outcome of a delivery-code verification.
	 *
	 * Not screen-gated: it is the direct result of an action the user just took on the
	 * order screen, and is only rendered when the redirect carries the parameters.
	 */
	public function render_action_result(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice rendering after a redirect.
		$notice = isset( $_GET['snappay_notice'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['snappay_notice'] ) ) : '';

		if ( 'success' !== $notice && 'error' !== $notice ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only notice rendering after a redirect; the value is URL-decoded and then passed through sanitize_text_field().
		$message = isset( $_GET['snappay_message'] ) ? sanitize_text_field( rawurldecode( wp_unslash( (string) $_GET['snappay_message'] ) ) ) : '';

		if ( '' === $message ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( 'success' === $notice ? 'success' : 'error' ),
			esc_html( $message )
		);
	}

	/**
	 * Warns when checkout is switched off because the connection is not validated.
	 */
	public function render_connection_warning(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! $this->on_relevant_screen() ) {
			return;
		}

		$plugin = Plugin::instance();

		if ( $plugin->is_connected() ) {
			return;
		}

		printf(
			'<div class="notice notice-warning is-dismissible"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
			esc_html__( 'Snap Pay is not connected, so its payment methods are hidden at checkout.', 'snap-pay-for-woocommerce' ),
			esc_url( admin_url( 'admin.php?page=snappay-settings' ) ),
			esc_html__( 'Connect now', 'snap-pay-for-woocommerce' )
		);
	}

	/**
	 * True on the Plugins and Dashboard screens, and anywhere under WooCommerce —
	 * including the HPOS order tables, whose screen ids are not fixed strings.
	 *
	 * The Snap Pay settings screen is excluded: the warning would sit directly above
	 * the fields that resolve it, which is noise rather than help.
	 */
	private function on_relevant_screen(): bool {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();

		if ( null === $screen ) {
			return false;
		}

		$id = (string) $screen->id;

		if ( false !== strpos( $id, 'snappay-settings' ) ) {
			return false;
		}

		if ( in_array( $id, self::NOTICE_SCREENS, true ) ) {
			return true;
		}

		return false !== strpos( $id, 'woocommerce' )
			|| false !== strpos( $id, 'wc-orders' )
			|| 'shop_order' === (string) $screen->post_type;
	}
}
