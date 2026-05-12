<?php
/**
 * WooCommerce Payment Gateway: Pay with Wallet.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
	return;
}

class WSW_Gateway extends WC_Payment_Gateway {

	public function __construct() {
		$settings        = WSW_Wallet::get_settings();
		$this->id                 = 'wsw_wallet';
		$this->method_title       = __( 'Wallet', 'wp-simple-wallet' );
		$this->method_description = __( 'Lets customers with an active wallet pay using their balance.', 'wp-simple-wallet' );
		$this->has_fields         = false;
		$this->supports           = array( 'products', 'refunds' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title', $settings['gateway_title'] );
		$this->description = $this->get_option( 'description', $settings['gateway_description'] );
		$this->enabled     = $this->get_option( 'enabled', 'yes' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	public function init_form_fields() {
		$settings = WSW_Wallet::get_settings();
		$this->form_fields = array(
			'enabled' => array(
				'title'   => __( 'Enable/Disable', 'wp-simple-wallet' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Wallet payments', 'wp-simple-wallet' ),
				'default' => 'yes',
			),
			'title' => array(
				'title'       => __( 'Title', 'wp-simple-wallet' ),
				'type'        => 'text',
				'description' => __( 'Shown to customers during checkout.', 'wp-simple-wallet' ),
				'default'     => $settings['gateway_title'],
			),
			'description' => array(
				'title'       => __( 'Description', 'wp-simple-wallet' ),
				'type'        => 'textarea',
				'description' => __( 'Shown to customers below the payment method.', 'wp-simple-wallet' ),
				'default'     => $settings['gateway_description'],
			),
		);
	}

	public function is_available() {
		if ( 'yes' !== $this->enabled ) {
			return false;
		}
		$user_id = get_current_user_id();
		if ( ! $user_id || ! WSW_User::is_wallet_active( $user_id ) ) {
			return false;
		}

		// If we have a cart total, check the user could afford it (respecting overdraft settings).
		if ( WC()->cart && WC()->cart->total > 0 ) {
			$check = WSW_Wallet::can_debit( $user_id, (float) WC()->cart->total );
			if ( is_wp_error( $check ) ) {
				return false;
			}
		}

		return true;
	}

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wc_add_notice( __( 'Order not found.', 'wp-simple-wallet' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$user_id = $order->get_user_id();
		if ( ! $user_id || ! WSW_User::is_wallet_active( $user_id ) ) {
			wc_add_notice( __( 'Wallet is not available for this user.', 'wp-simple-wallet' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$amount = (float) $order->get_total();
		$check  = WSW_Wallet::can_debit( $user_id, $amount );
		if ( is_wp_error( $check ) ) {
			wc_add_notice( $check->get_error_message(), 'error' );
			return array( 'result' => 'failure' );
		}

		$note = sprintf(
			/* translators: %d order id */
			__( 'Payment for order #%d', 'wp-simple-wallet' ),
			$order->get_id()
		);

		$tx = WSW_Wallet::adjust(
			$user_id,
			-$amount,
			WSW_Wallet::TYPE_PAYMENT,
			$note,
			array(
				'order_id' => $order->get_id(),
				'source'   => 'wp-simple-wallet',
			)
		);
		if ( is_wp_error( $tx ) ) {
			wc_add_notice( $tx->get_error_message(), 'error' );
			return array( 'result' => 'failure' );
		}

		$order->payment_complete( (string) $tx );
		$order->add_order_note(
			sprintf(
				/* translators: %s amount */
				__( 'Paid with wallet (%s).', 'wp-simple-wallet' ),
				wc_price( $amount )
			)
		);

		WC()->cart->empty_cart();

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * Declares refund support. The actual credit-back is performed centrally on
	 * `woocommerce_order_refunded` to avoid double processing.
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error( 'wsw_no_order', __( 'Order not found.', 'wp-simple-wallet' ) );
		}
		if ( ! $order->get_user_id() ) {
			return new WP_Error( 'wsw_no_user', __( 'Order has no user.', 'wp-simple-wallet' ) );
		}
		if ( (float) $amount <= 0 ) {
			return new WP_Error( 'wsw_invalid_amount', __( 'Refund amount must be positive.', 'wp-simple-wallet' ) );
		}
		return true;
	}
}
