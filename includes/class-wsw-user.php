<?php
/**
 * User helpers: wallet activation state.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSW_User {

	const META_ACTIVE = '_wsw_wallet_active';
	const ROLE        = 'wsw_wallet_customer';

	public static function is_wallet_active( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return false;
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return false;
		}

		if ( in_array( self::ROLE, (array) $user->roles, true ) ) {
			return true;
		}

		return 'yes' === get_user_meta( $user_id, self::META_ACTIVE, true );
	}

	public static function set_wallet_active( $user_id, $active ) {
		update_user_meta( $user_id, self::META_ACTIVE, $active ? 'yes' : 'no' );
	}

	public static function get_users_with_wallet( $args = array() ) {
		$defaults = array(
			'number' => -1,
			'search' => '',
		);
		$args     = wp_parse_args( $args, $defaults );

		$role_users = get_users(
			array(
				'role'   => self::ROLE,
				'fields' => 'ID',
				'number' => -1,
			)
		);

		$meta_users = get_users(
			array(
				'meta_key'   => self::META_ACTIVE,
				'meta_value' => 'yes',
				'fields'     => 'ID',
				'number'     => -1,
			)
		);

		$ids = array_values( array_unique( array_map( 'absint', array_merge( $role_users, $meta_users ) ) ) );

		if ( empty( $ids ) ) {
			return array();
		}

		$query_args = array(
			'include' => $ids,
			'orderby' => 'display_name',
			'order'   => 'ASC',
			'number'  => $args['number'],
		);

		if ( ! empty( $args['search'] ) ) {
			$query_args['search']         = '*' . esc_attr( $args['search'] ) . '*';
			$query_args['search_columns'] = array( 'user_login', 'user_email', 'display_name', 'user_nicename' );
		}

		return get_users( $query_args );
	}
}
