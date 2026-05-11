<?php
/**
 * Uninstall WP Simple Wallet.
 *
 * Deletes plugin data only if the user enabled the "Remove data on uninstall" option.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$settings = get_option( 'wsw_settings', array() );
$cleanup  = isset( $settings['cleanup_on_uninstall'] ) && 'yes' === $settings['cleanup_on_uninstall'];

if ( ! $cleanup ) {
	return;
}

$table = $wpdb->prefix . 'wsw_transactions';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore

delete_option( 'wsw_settings' );
delete_option( 'wsw_db_version' );

$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key IN ('_wsw_balance', '_wsw_wallet_active')" ); // phpcs:ignore

if ( get_role( 'wsw_wallet_customer' ) ) {
	remove_role( 'wsw_wallet_customer' );
}
