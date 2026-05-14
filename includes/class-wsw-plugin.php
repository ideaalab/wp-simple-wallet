<?php
/**
 * Main plugin bootstrapper.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSW_Plugin {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		require_once WSW_PLUGIN_DIR . 'includes/class-wsw-admin.php';
		require_once WSW_PLUGIN_DIR . 'includes/class-wsw-my-account.php';
		require_once WSW_PLUGIN_DIR . 'includes/class-wsw-checkout.php';

		new WSW_Admin();
		new WSW_My_Account();
		new WSW_Checkout();

		add_action( 'woocommerce_order_refunded', array( $this, 'on_order_refunded' ), 10, 2 );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'on_order_cancelled' ) );
	}

	/**
	 * Refund handler.
	 *
	 * Supports two flows:
	 * 1) Legacy v1.x: orders paid entirely with the old `wsw_wallet` gateway.
	 * 2) New v1.4+: orders where wallet was applied as a fee. For these,
	 *    auto-refund only runs when the gateway portion was zero (the wallet
	 *    covered the full total). On split-payment orders the gateway handles
	 *    its own refund; the admin can adjust the wallet manually.
	 *
	 * @param int $order_id
	 * @param int $refund_id
	 */
	public function on_order_refunded( $order_id, $refund_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Determine how much wallet was used.
		$is_legacy     = ( 'wsw_wallet' === $order->get_payment_method() );
		$wallet_amount = 0.0;

		if ( $is_legacy ) {
			// Legacy gateway: full order was paid by wallet.
			// The stored total may already have been recalculated by a refund, so
			// we rely on the sum of wallet transactions instead.
			$wallet_amount = floatval( $order->get_meta( '_order_total' ) );
			if ( ! $wallet_amount ) {
				$wallet_amount = floatval( $order->get_total() );
			}
		} else {
			$wallet_amount = floatval( $order->get_meta( '_wsw_wallet_amount' ) );
		}

		if ( $wallet_amount <= 0 ) {
			return;
		}

		// For fee-based orders with a separate gateway (split payment),
		// do NOT auto-refund the wallet portion — the gateway handles its
		// own refund and the admin should adjust wallet manually.
		if ( ! $is_legacy && floatval( $order->get_total() ) > 0.01 ) {
			return;
		}

		$refund = wc_get_order( $refund_id );
		if ( ! $refund ) {
			return;
		}

		// Avoid double-processing.
		if ( $refund->get_meta( '_wsw_credited' ) ) {
			return;
		}

		$refund_amount = abs( (float) $refund->get_amount() );
		$user_id       = $order->get_user_id();

		if ( $refund_amount <= 0 || ! $user_id ) {
			return;
		}

		// Cap at wallet amount minus anything already refunded.
		$already = floatval( $order->get_meta( '_wsw_wallet_refunded' ) );
		$credit  = min( $refund_amount, $wallet_amount - $already );
		if ( $credit <= 0 ) {
			return;
		}

		$note = sprintf(
			/* translators: 1: order id, 2: refund id */
			__( 'Refund credited back to wallet (order #%1$d, refund #%2$d).', 'wp-simple-wallet' ),
			$order_id,
			$refund_id
		);

		$result = WSW_Wallet::adjust(
			$user_id,
			$credit,
			WSW_Wallet::TYPE_REFUND,
			$note,
			array(
				'order_id' => $order_id,
				'source'   => 'wp-simple-wallet',
				'force'    => true,
			)
		);

		if ( ! is_wp_error( $result ) ) {
			$refund->update_meta_data( '_wsw_credited', 1 );
			$refund->save();
			$order->update_meta_data( '_wsw_wallet_refunded', $already + $credit );
			$order->save();
			$order->add_order_note(
				sprintf(
					/* translators: %s: formatted amount */
					__( 'WP Simple Wallet: refunded %s back to the customer wallet.', 'wp-simple-wallet' ),
					wc_price( $credit )
				)
			);
		}
	}

	/**
	 * Restore wallet balance when an order is cancelled.
	 *
	 * This handles both full-wallet and split-payment orders: in either
	 * case the wallet portion is reversed.
	 *
	 * @param int $order_id
	 */
	public function on_order_cancelled( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$wallet_amount = floatval( $order->get_meta( '_wsw_wallet_amount' ) );
		if ( $wallet_amount <= 0 ) {
			return;
		}

		// Already restored.
		if ( $order->get_meta( '_wsw_wallet_cancelled' ) ) {
			return;
		}

		$user_id = $order->get_user_id();
		if ( ! $user_id ) {
			return;
		}

		$already = floatval( $order->get_meta( '_wsw_wallet_refunded' ) );
		$credit  = $wallet_amount - $already;
		if ( $credit <= 0 ) {
			return;
		}

		$note = sprintf(
			/* translators: %d: order number */
			__( 'Order #%d cancelled — wallet balance restored.', 'wp-simple-wallet' ),
			$order_id
		);

		$result = WSW_Wallet::adjust(
			$user_id,
			$credit,
			WSW_Wallet::TYPE_REFUND,
			$note,
			array(
				'order_id' => $order_id,
				'source'   => 'wp-simple-wallet',
				'force'    => true,
			)
		);

		if ( ! is_wp_error( $result ) ) {
			$order->update_meta_data( '_wsw_wallet_cancelled', 1 );
			$order->update_meta_data( '_wsw_wallet_refunded', $already + $credit );
			$order->save();
			$order->add_order_note(
				sprintf(
					/* translators: %s: formatted amount */
					__( 'WP Simple Wallet: restored %s to the customer wallet (order cancelled).', 'wp-simple-wallet' ),
					wc_price( $credit )
				)
			);
		}
	}
}
