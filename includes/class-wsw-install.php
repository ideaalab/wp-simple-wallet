<?php
/**
 * Installer: creates DB table, role, and default options.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSW_Install {

	const DB_VERSION = '1.1.0';

	public static function activate() {
		self::create_tables();
		self::create_role();
		self::set_default_options();
		update_option( 'wsw_db_version', self::DB_VERSION );
	}

	public static function deactivate() {
		// Intentionally empty: do not delete any data on deactivation.
	}

	/**
	 * Runs on plugins_loaded; upgrades the DB schema if needed.
	 */
	public static function maybe_upgrade() {
		$installed = get_option( 'wsw_db_version' );
		if ( $installed !== self::DB_VERSION ) {
			self::create_tables();
			update_option( 'wsw_db_version', self::DB_VERSION );
		}
	}

	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$table           = $wpdb->prefix . 'wsw_transactions';

		$sql = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT(20) UNSIGNED NOT NULL,
			amount DECIMAL(18,4) NOT NULL DEFAULT 0,
			balance_after DECIMAL(18,4) NOT NULL DEFAULT 0,
			type VARCHAR(64) NOT NULL,
			source VARCHAR(64) NULL,
			note TEXT NULL,
			order_id BIGINT(20) UNSIGNED NULL,
			created_by BIGINT(20) UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY order_id (order_id),
			KEY type (type),
			KEY source (source),
			KEY created_at (created_at)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	public static function create_role() {
		if ( ! get_role( 'wsw_wallet_customer' ) ) {
			$customer = get_role( 'customer' );
			$caps     = $customer ? $customer->capabilities : array( 'read' => true );
			add_role( 'wsw_wallet_customer', __( 'Wallet Customer', 'wp-simple-wallet' ), $caps );
		}
	}

	public static function set_default_options() {
		$existing = get_option( 'wsw_settings', false );
		if ( false === $existing ) {
			update_option(
				'wsw_settings',
				array(
					'allow_negative'       => 'no',
					'max_negative'         => '0',
					'cleanup_on_uninstall' => 'no',
					'gateway_title'        => __( 'Pay with wallet', 'wp-simple-wallet' ),
					'gateway_description'  => __( 'Use your wallet balance to pay for this order.', 'wp-simple-wallet' ),
				)
			);
		}
	}
}
