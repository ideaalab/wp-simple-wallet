<?php
/**
 * Plugin Name: WP Simple Wallet
 * Plugin URI: https://github.com/ideaalab/wp-simple-wallet
 * Description: Wallet balance for WooCommerce customers. Per-user activation, admin adjustments, transaction history, and a "Pay with wallet" gateway. HPOS compatible.
 * Version: 1.2.2
 * Author: IDEAA Lab
 * Author URI: https://github.com/ideaalab
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 9.4
 * Text Domain: wp-simple-wallet
 * Domain Path: /languages
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Auto-update from GitHub
require_once __DIR__ . '/vendor/plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

PucFactory::buildUpdateChecker(
	'https://github.com/ideaalab/wp-simple-wallet/',
	__FILE__,
	'wp-simple-wallet'
);

define( 'WSW_VERSION', '1.2.2' );
define( 'WSW_PLUGIN_FILE', __FILE__ );
define( 'WSW_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WSW_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WSW_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once WSW_PLUGIN_DIR . 'includes/class-wsw-install.php';
require_once WSW_PLUGIN_DIR . 'includes/class-wsw-user.php';
require_once WSW_PLUGIN_DIR . 'includes/class-wsw-wallet.php';
require_once WSW_PLUGIN_DIR . 'includes/wsw-api.php';
require_once WSW_PLUGIN_DIR . 'includes/class-wsw-plugin.php';

register_activation_hook( __FILE__, array( 'WSW_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WSW_Install', 'deactivate' ) );

// HPOS compatibility declaration.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p>' .
						esc_html__( 'WP Simple Wallet requires WooCommerce to be installed and active.', 'wp-simple-wallet' ) .
						'</p></div>';
				}
			);
			return;
		}

		load_plugin_textdomain( 'wp-simple-wallet', false, dirname( WSW_PLUGIN_BASENAME ) . '/languages' );

		WSW_Install::maybe_upgrade();
		WSW_Plugin::instance();
	}
);
