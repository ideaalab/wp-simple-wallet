<?php
/**
 * Public procedural API for WP Simple Wallet.
 *
 * Other plugins should call these functions instead of the underlying classes.
 * All amount-taking functions accept positive numbers; `wsw_credit()` adds,
 * `wsw_debit()` subtracts. Both return the inserted transaction ID (int) on
 * success or a WP_Error.
 *
 * Optional $args:
 *   - 'type'       (string) Custom transaction type, max 64 chars. Defaults
 *                  to 'credit' or 'debit'. Use stable slugs like
 *                  'royalty_payout', 'relay_topup', etc.
 *   - 'order_id'   (int)    Related WooCommerce order, if any.
 *   - 'source'     (string) Slug identifying the calling plugin. Stored in
 *                  the dedicated `source` column and shown in transaction
 *                  listings. E.g. 'wp-royalties', 'wp-relay-extras'.
 *   - 'created_by' (int)    User ID to record as author. Defaults to the
 *                  current logged-in user (or NULL on cron/CLI).
 *   - 'force'      (bool)   Bypass the "allow negative / max negative"
 *                  setting on debits. Use sparingly (refunds, system
 *                  corrections, payouts that must not be blocked).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Is the wallet active for this user?
 *
 * @param int $user_id
 * @return bool
 */
function wsw_is_active( $user_id ) {
	return WSW_User::is_wallet_active( $user_id );
}

/**
 * Enable or disable the wallet for a user by toggling the profile meta flag.
 * Users with the 'wsw_wallet_customer' role are always active regardless of
 * this flag.
 *
 * @param int  $user_id
 * @param bool $active
 * @return void
 */
function wsw_set_active( $user_id, $active = true ) {
	WSW_User::set_wallet_active( $user_id, (bool) $active );
}

/**
 * Get the current wallet balance for a user (always returns a float, 0.0 if
 * the user has never had a wallet).
 *
 * @param int $user_id
 * @return float
 */
function wsw_get_balance( $user_id ) {
	return WSW_Wallet::get_balance( $user_id );
}

/**
 * Add funds to a user's wallet.
 *
 * @param int    $user_id
 * @param float  $amount  Positive amount to credit.
 * @param string $note    Free-form note shown in the admin and customer history.
 * @param array  $args    See file header.
 * @return int|WP_Error   Transaction ID or error.
 */
function wsw_credit( $user_id, $amount, $note = '', $args = array() ) {
	$amount = abs( (float) $amount );
	if ( $amount <= 0 ) {
		return new WP_Error( 'wsw_invalid_amount', __( 'Amount must be greater than zero.', 'wp-simple-wallet' ) );
	}
	$args = wp_parse_args( $args, array( 'type' => WSW_Wallet::TYPE_CREDIT ) );
	$type = $args['type'];
	unset( $args['type'] );
	return WSW_Wallet::adjust( $user_id, $amount, $type, $note, $args );
}

/**
 * Subtract funds from a user's wallet.
 *
 * Respects the "allow negative balance / max negative balance" settings unless
 * `$args['force']` is true.
 *
 * @param int    $user_id
 * @param float  $amount  Positive amount to debit.
 * @param string $note
 * @param array  $args    See file header.
 * @return int|WP_Error
 */
function wsw_debit( $user_id, $amount, $note = '', $args = array() ) {
	$amount = abs( (float) $amount );
	if ( $amount <= 0 ) {
		return new WP_Error( 'wsw_invalid_amount', __( 'Amount must be greater than zero.', 'wp-simple-wallet' ) );
	}
	$args = wp_parse_args( $args, array( 'type' => WSW_Wallet::TYPE_DEBIT ) );
	$type = $args['type'];
	unset( $args['type'] );
	return WSW_Wallet::adjust( $user_id, -$amount, $type, $note, $args );
}

/**
 * Check whether a debit of $amount is currently allowed (without performing it).
 *
 * @param int   $user_id
 * @param float $amount  Positive amount.
 * @param array $args    Accepts 'force'.
 * @return true|WP_Error
 */
function wsw_can_debit( $user_id, $amount, $args = array() ) {
	return WSW_Wallet::can_debit( $user_id, abs( (float) $amount ), $args );
}

/**
 * Query the transactions table.
 *
 * @param array $args Supports user_id, source, type, limit, offset, orderby, order.
 * @return array Array of row objects.
 */
function wsw_get_transactions( $args = array() ) {
	return WSW_Wallet::get_transactions( $args );
}

/**
 * Get the current plugin settings as a parsed array.
 *
 * @return array
 */
function wsw_get_settings() {
	return WSW_Wallet::get_settings();
}
