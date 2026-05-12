<?php
/**
 * User helpers: wallet activation state.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSW_User {

	const META_ACTIVE             = '_wsw_wallet_active';
	const META_ALLOW_NEGATIVE     = '_wsw_allow_negative';
	const META_MAX_NEGATIVE       = '_wsw_max_negative';
	const ROLE                    = 'wsw_wallet_customer';

	/**
	 * Resolve the effective overdraft policy for a user.
	 *
	 * Per-user meta overrides global settings; empty meta means "use store default".
	 *
	 * @param int $user_id
	 * @return array {
	 *     @type bool   $allow_negative  Whether the user may overdraw.
	 *     @type float  $max_negative    Hard limit on the negative balance (0 = unlimited).
	 *     @type string $allow_source    'user' or 'default'.
	 *     @type string $max_source      'user' or 'default'.
	 *     @type string $user_allow_raw  Raw meta value ('yes'|'no'|'').
	 *     @type string $user_max_raw    Raw meta value (string number or '').
	 * }
	 */
	public static function get_overdraft( $user_id ) {
		$settings = WSW_Wallet::get_settings();
		$default_allow = 'yes' === $settings['allow_negative'];
		$default_max   = (float) $settings['max_negative'];

		$user_allow = (string) get_user_meta( absint( $user_id ), self::META_ALLOW_NEGATIVE, true );
		$user_max   = (string) get_user_meta( absint( $user_id ), self::META_MAX_NEGATIVE, true );

		$allow_source = '' !== $user_allow ? 'user' : 'default';
		$max_source   = '' !== $user_max   ? 'user' : 'default';

		return array(
			'allow_negative' => '' !== $user_allow ? ( 'yes' === $user_allow ) : $default_allow,
			'max_negative'   => '' !== $user_max   ? (float) $user_max : $default_max,
			'allow_source'   => $allow_source,
			'max_source'     => $max_source,
			'user_allow_raw' => $user_allow,
			'user_max_raw'   => $user_max,
		);
	}

	public static function set_overdraft( $user_id, $allow_raw, $max_raw ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return;
		}

		// '' means "delete the override" -> fall back to global.
		if ( '' === $allow_raw ) {
			delete_user_meta( $user_id, self::META_ALLOW_NEGATIVE );
		} else {
			update_user_meta( $user_id, self::META_ALLOW_NEGATIVE, 'yes' === $allow_raw ? 'yes' : 'no' );
		}

		if ( '' === $max_raw ) {
			delete_user_meta( $user_id, self::META_MAX_NEGATIVE );
		} else {
			update_user_meta( $user_id, self::META_MAX_NEGATIVE, (string) max( 0, (float) $max_raw ) );
		}
	}

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
