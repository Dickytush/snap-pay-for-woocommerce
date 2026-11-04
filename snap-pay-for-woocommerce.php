<?php
/**
 * Plugin Name:       Snap Pay for WooCommerce
 * Plugin URI:        https://snapcashpay.com/merchant/woocommerce
 * Description:       Delivery-code escrow payments for WooCommerce stores in Kenya and Uganda. Funds are held by the Snap Pay platform until the customer confirms delivery with an 8-character code.
 * Version:           2.0.1
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * Author:            Snap Pay Solutions
 * Author URI:        https://snapaysolutions.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       snap-pay-for-woocommerce
 * Domain Path:       /languages
 * WC requires at least: 8.2
 * WC tested up to:   10.5
 *
 * @package SnapPay
 *
 * Architecture: this plugin is a THIN ADAPTER. It owns no escrow state, no ledger,
 * no fee/reserve math, no FX sourcing and no provider integrations.
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

define( 'SNAPPAY_VERSION', '2.0.1' );
define( 'SNAPPAY_PLUGIN_FILE', __FILE__ );
define( 'SNAPPAY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SNAPPAY_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * PSR-4-ish autoloader for the SnapPay\ namespace.
 *
 * SnapPay\Fx\Display  ->  includes/Fx/Display.php
 */
spl_autoload_register(
	static function ( string $class ): void {
		if ( 0 !== strpos( $class, 'SnapPay\\' ) ) {
			return;
		}

		$relative = str_replace( '\\', DIRECTORY_SEPARATOR, substr( $class, strlen( 'SnapPay\\' ) ) );
		$path     = SNAPPAY_PLUGIN_DIR . 'includes' . DIRECTORY_SEPARATOR . $relative . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

/**
 * Declare HPOS (custom order tables) compatibility.
 *
 * All order access goes through WC_Order CRUD in OrderRepository, never raw postmeta.
 */
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', SNAPPAY_PLUGIN_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', SNAPPAY_PLUGIN_FILE, true );
		}
	}
);

add_action( 'plugins_loaded', array( \SnapPay\Plugin::class, 'instance' ), 11 );

register_activation_hook( __FILE__, array( \SnapPay\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \SnapPay\Plugin::class, 'deactivate' ) );
