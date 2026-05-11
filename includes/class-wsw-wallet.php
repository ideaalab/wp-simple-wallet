<?php
/**
 * Wallet operations: balance read/write and transaction logging.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSW_Wallet {

	const META_BALANCE = '_wsw_balance';

	const TYPE_CREDIT     = 'credit';
	const TYPE_DEBIT      = 'debit';
	const TYPE_ADJUSTMENT = 'adjustment';
	const TYPE_PAYMENT    = 'order_payment';
	const TYPE_REFUND     = 'order_refund';

	public static function get_balance( $user_id ) {
		$balance = get_user_meta( absint( $user_id ), self::META_BALANCE, true );
		return $balance === '' ? 0.0 : (float) $balance;
	}

	public static function get_settings() {
		$settings = get_option( 'wsw_settings', array() );
		$defaults = array(
			'allow_negative'       => 'no',
			'max_negative'         => '0',
			'cleanup_on_uninstall' => 'no',
			'gateway_title'        => __( 'Pay with wallet', 'wp-simple-wallet' ),
			'gateway_description'  => __( 'Use your wallet balance to pay for this order.', 'wp-simple-wallet' ),
		);
		return wp_parse_args( $settings, $defaults );
	}

	/**
	 * Check that a debit of $amount is allowed given current settings.
	 *
	 * @param int   $user_id
	 * @param float $amount  Positive amount to debit.
	 * @return true|WP_Error
	 */
	public static function can_debit( $user_id, $amount ) {
		$amount = (float) $amount;
		if ( $amount <= 0 ) {
			return new WP_Error( 'wsw_invalid_amount', __( 'Amount must be greater than zero.', 'wp-simple-wallet' ) );
		}

		$balance     = self::get_balance( $user_id );
		$after       = $balance - $amount;
		$settings    = self::get_settings();
		$allow_neg   = 'yes' === $settings['allow_negative'];
		$max_negative = (float) $settings['max_negative'];

		if ( $after < 0 && ! $allow_neg ) {
			return new WP_Error( 'wsw_insufficient_balance', __( 'Insufficient wallet balance.', 'wp-simple-wallet' ) );
		}

		if ( $after < 0 && $allow_neg && $max_negative > 0 && abs( $after ) > $max_negative ) {
			return new WP_Error(
				'wsw_limit_exceeded',
				sprintf(
					/* translators: %s: max negative balance allowed */
					__( 'Charging this amount would exceed the maximum negative balance allowed (%s).', 'wp-simple-wallet' ),
					wc_price( $max_negative )
				)
			);
		}

		return true;
	}

	/**
	 * Apply a delta to the balance and record a transaction.
	 *
	 * @param int    $user_id
	 * @param float  $delta     Positive to credit, negative to debit.
	 * @param string $type      One of the TYPE_* constants.
	 * @param string $note
	 * @param int    $order_id  Optional.
	 * @return int|WP_Error  Inserted transaction ID or error.
	 */
	public static function adjust( $user_id, $delta, $type, $note = '', $order_id = 0 ) {
		global $wpdb;

		$user_id = absint( $user_id );
		$delta   = (float) $delta;

		if ( ! $user_id ) {
			return new WP_Error( 'wsw_invalid_user', __( 'Invalid user.', 'wp-simple-wallet' ) );
		}
		if ( 0.0 === $delta ) {
			return new WP_Error( 'wsw_zero_delta', __( 'Adjustment amount cannot be zero.', 'wp-simple-wallet' ) );
		}

		if ( $delta < 0 ) {
			$check = self::can_debit( $user_id, abs( $delta ) );
			if ( is_wp_error( $check ) ) {
				return $check;
			}
		}

		$balance = self::get_balance( $user_id );
		$after   = round( $balance + $delta, 4 );

		update_user_meta( $user_id, self::META_BALANCE, $after );

		$wpdb->insert(
			$wpdb->prefix . 'wsw_transactions',
			array(
				'user_id'       => $user_id,
				'amount'        => $delta,
				'balance_after' => $after,
				'type'          => $type,
				'note'          => $note,
				'order_id'      => $order_id ? absint( $order_id ) : null,
				'created_by'    => get_current_user_id() ?: null,
				'created_at'    => current_time( 'mysql' ),
			),
			array( '%d', '%f', '%f', '%s', '%s', '%d', '%d', '%s' )
		);

		$tx_id = (int) $wpdb->insert_id;

		do_action( 'wsw_balance_changed', $user_id, $delta, $after, $type, $tx_id );

		return $tx_id;
	}

	public static function get_transactions( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'user_id' => 0,
			'limit'   => 50,
			'offset'  => 0,
			'orderby' => 'created_at',
			'order'   => 'DESC',
		);
		$args     = wp_parse_args( $args, $defaults );

		$table  = $wpdb->prefix . 'wsw_transactions';
		$where  = '1=1';
		$params = array();

		if ( $args['user_id'] ) {
			$where   .= ' AND user_id = %d';
			$params[] = absint( $args['user_id'] );
		}

		$orderby = in_array( $args['orderby'], array( 'created_at', 'id', 'amount', 'type' ), true ) ? $args['orderby'] : 'created_at';
		$order   = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

		$limit_sql = '';
		if ( $args['limit'] > 0 ) {
			$limit_sql = $wpdb->prepare( ' LIMIT %d OFFSET %d', absint( $args['limit'] ), absint( $args['offset'] ) );
		}

		$sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY {$orderby} {$order}{$limit_sql}";

		if ( $params ) {
			$sql = $wpdb->prepare( $sql, $params ); // phpcs:ignore
		}

		return $wpdb->get_results( $sql ); // phpcs:ignore
	}

	public static function count_transactions( $user_id = 0 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wsw_transactions';
		if ( $user_id ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d", absint( $user_id ) ) ); // phpcs:ignore
		}
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore
	}

	public static function type_label( $type ) {
		$labels = array(
			self::TYPE_CREDIT     => __( 'Credit', 'wp-simple-wallet' ),
			self::TYPE_DEBIT      => __( 'Debit', 'wp-simple-wallet' ),
			self::TYPE_ADJUSTMENT => __( 'Manual adjustment', 'wp-simple-wallet' ),
			self::TYPE_PAYMENT    => __( 'Order payment', 'wp-simple-wallet' ),
			self::TYPE_REFUND     => __( 'Order refund', 'wp-simple-wallet' ),
		);
		return isset( $labels[ $type ] ) ? $labels[ $type ] : $type;
	}
}
