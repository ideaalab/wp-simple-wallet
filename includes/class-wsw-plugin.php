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
		require_once WSW_PLUGIN_DIR . 'includes/class-wsw-gateway.php';

		new WSW_Admin();
		new WSW_My_Account();

		add_filter( 'woocommerce_payment_gateways', array( $this, 'register_gateway' ) );
		add_action( 'woocommerce_order_refunded', array( $this, 'on_order_refunded' ), 10, 2 );
	}

	public function register_gateway( $gateways ) {
		$gateways[] = 'WSW_Gateway';
		return $gateways;
	}

	/**
	 * Refund handler: if the order was paid with the wallet gateway, credit the refund amount back.
	 *
	 * @param int $order_id
	 * @param int $refund_id
	 */
	public function on_order_refunded( $order_id, $refund_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		if ( 'wsw_wallet' !== $order->get_payment_method() ) {
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

		$amount  = abs( (float) $refund->get_amount() );
		$user_id = $order->get_user_id();

		if ( $amount <= 0 || ! $user_id ) {
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
			$amount,
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
			$order->add_order_note(
				sprintf(
					/* translators: %s: formatted amount */
					__( 'WP Simple Wallet: refunded %s back to the customer wallet.', 'wp-simple-wallet' ),
					wc_price( $amount )
				)
			);
		}
	}
}
