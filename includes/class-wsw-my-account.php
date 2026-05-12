<?php
/**
 * Frontend "My Account" Wallet tab.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSW_My_Account {

	const ENDPOINT = 'wallet';

	public function __construct() {
		add_action( 'init', array( $this, 'add_endpoint' ) );
		add_action( 'wp_loaded', array( $this, 'maybe_flush_rewrite' ), 20 );
		add_filter( 'query_vars', array( $this, 'query_vars' ), 0 );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'menu_items' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $this, 'render_content' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_styles' ) );

		register_activation_hook( WSW_PLUGIN_FILE, array( $this, 'flush_rewrite_on_activate' ) );
	}

	public function flush_rewrite_on_activate() {
		$this->add_endpoint();
		flush_rewrite_rules();
		update_option( 'wsw_rewrite_flushed', WSW_VERSION );
	}

	/**
	 * Flush rewrite rules once per plugin version, so endpoint updates after a
	 * plugin upgrade don't leave the customer with a 404 on /my-account/wallet/.
	 */
	public function maybe_flush_rewrite() {
		if ( get_option( 'wsw_rewrite_flushed' ) !== WSW_VERSION ) {
			flush_rewrite_rules( false );
			update_option( 'wsw_rewrite_flushed', WSW_VERSION );
		}
	}

	public function add_endpoint() {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	public function query_vars( $vars ) {
		$vars[] = self::ENDPOINT;
		return $vars;
	}

	/**
	 * Inject the Wallet menu item at the position chosen in Settings.
	 *
	 * Supported values for `myaccount_position`:
	 *   - "first" / "last"           : prepend or append
	 *   - any other slug (e.g. "dashboard", "orders", "edit-account",
	 *     "payment-methods", "customer-logout"): insert AFTER that slug.
	 *   - empty / not found          : append at the end (safe fallback).
	 *
	 * The same value can be overridden per-site via the `wsw_account_menu_position`
	 * filter.
	 */
	public function menu_items( $items ) {
		$user_id = get_current_user_id();
		if ( ! $user_id || ! WSW_User::is_wallet_active( $user_id ) ) {
			return $items;
		}

		$settings = WSW_Wallet::get_settings();
		$position = isset( $settings['myaccount_position'] ) ? $settings['myaccount_position'] : 'dashboard';
		$position = (string) apply_filters( 'wsw_account_menu_position', $position, $items );
		$label    = __( 'Wallet', 'wp-simple-wallet' );

		if ( 'first' === $position ) {
			return array( self::ENDPOINT => $label ) + $items;
		}
		if ( 'last' === $position || ! isset( $items[ $position ] ) ) {
			$items[ self::ENDPOINT ] = $label;
			return $items;
		}

		$new = array();
		foreach ( $items as $key => $value ) {
			$new[ $key ] = $value;
			if ( $key === $position ) {
				$new[ self::ENDPOINT ] = $label;
			}
		}
		return $new;
	}

	public function maybe_enqueue_styles() {
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return;
		}

		$settings   = WSW_Wallet::get_settings();
		$show_icon  = ! isset( $settings['myaccount_show_icon'] ) || 'yes' === $settings['myaccount_show_icon'];
		$icon_glyph = isset( $settings['myaccount_icon_glyph'] ) && $settings['myaccount_icon_glyph'] !== ''
			? $settings['myaccount_icon_glyph']
			: '\f18e'; // dashicons-money

		/**
		 * Override the CSS used to add an icon to the Wallet menu item.
		 * Return an empty string to disable the default icon entirely.
		 *
		 * @param string $css        Default CSS.
		 * @param string $icon_glyph Configured dashicon escape sequence.
		 */
		$icon_css = apply_filters(
			'wsw_account_menu_icon_css',
			sprintf(
				'.woocommerce-MyAccount-navigation-link--%1$s > a::before{font-family:dashicons;content:"%2$s"}',
				self::ENDPOINT,
				$icon_glyph
			),
			$icon_glyph
		);

		$css = '
			.wsw-balance-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:32px 24px;text-align:center;margin:0 0 28px;box-shadow:0 1px 3px rgba(0,0,0,0.05)}
			.wsw-balance-card .wsw-label{display:block;text-transform:uppercase;letter-spacing:1px;font-size:12px;color:#777;margin-bottom:10px}
			.wsw-balance-card .wsw-amount{display:block;font-size:2.8em;font-weight:600;line-height:1.1;color:#1f5fa3}
			.wsw-balance-card .wsw-amount.is-negative{color:#c62828}
			.wsw-balance-card .wsw-amount.is-zero{color:#555}
			.wsw-tx-amount.positive{color:#2e7d32;font-weight:600}
			.wsw-tx-amount.negative{color:#c62828;font-weight:600}
			.wsw-empty{text-align:center;padding:24px;color:#777;background:#fafafa;border-radius:6px}
		';

		if ( $show_icon ) {
			wp_enqueue_style( 'dashicons' );
			$css .= $icon_css;
		}

		wp_register_style( 'wsw-myaccount', false );
		wp_enqueue_style( 'wsw-myaccount' );
		wp_add_inline_style( 'wsw-myaccount', $css );
	}

	public function render_content() {
		$user_id = get_current_user_id();
		if ( ! $user_id || ! WSW_User::is_wallet_active( $user_id ) ) {
			echo '<p>' . esc_html__( 'Wallet is not enabled for your account.', 'wp-simple-wallet' ) . '</p>';
			return;
		}

		$balance = WSW_Wallet::get_balance( $user_id );
		$txs     = WSW_Wallet::get_transactions( array( 'user_id' => $user_id, 'limit' => 50 ) );

		$amount_class = 'is-zero';
		if ( $balance > 0 ) {
			$amount_class = '';
		} elseif ( $balance < 0 ) {
			$amount_class = 'is-negative';
		}
		?>
		<div class="wsw-balance-card">
			<span class="wsw-label"><?php esc_html_e( 'Your wallet balance', 'wp-simple-wallet' ); ?></span>
			<span class="wsw-amount <?php echo esc_attr( $amount_class ); ?>"><?php echo wp_kses_post( wc_price( $balance ) ); ?></span>
		</div>

		<h3><?php esc_html_e( 'Recent movements', 'wp-simple-wallet' ); ?></h3>
		<?php if ( empty( $txs ) ) : ?>
			<div class="wsw-empty"><?php esc_html_e( 'You have no wallet movements yet.', 'wp-simple-wallet' ); ?></div>
		<?php else : ?>
			<table class="shop_table shop_table_responsive my_account_orders">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date', 'wp-simple-wallet' ); ?></th>
						<th><?php esc_html_e( 'Type', 'wp-simple-wallet' ); ?></th>
						<th><?php esc_html_e( 'Amount', 'wp-simple-wallet' ); ?></th>
						<th><?php esc_html_e( 'Balance', 'wp-simple-wallet' ); ?></th>
						<th><?php esc_html_e( 'Order', 'wp-simple-wallet' ); ?></th>
						<th><?php esc_html_e( 'Note', 'wp-simple-wallet' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $txs as $tx ) :
						$amount = (float) $tx->amount;
						$cls    = $amount < 0 ? 'negative' : 'positive';
						?>
						<tr>
							<td data-title="<?php esc_attr_e( 'Date', 'wp-simple-wallet' ); ?>"><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' H:i', $tx->created_at ) ); ?></td>
							<td data-title="<?php esc_attr_e( 'Type', 'wp-simple-wallet' ); ?>"><?php echo esc_html( WSW_Wallet::type_label( $tx->type ) ); ?></td>
							<td data-title="<?php esc_attr_e( 'Amount', 'wp-simple-wallet' ); ?>" class="wsw-tx-amount <?php echo esc_attr( $cls ); ?>"><?php echo wp_kses_post( wc_price( $amount ) ); ?></td>
							<td data-title="<?php esc_attr_e( 'Balance', 'wp-simple-wallet' ); ?>"><?php echo wp_kses_post( wc_price( (float) $tx->balance_after ) ); ?></td>
							<td data-title="<?php esc_attr_e( 'Order', 'wp-simple-wallet' ); ?>">
								<?php
								if ( ! empty( $tx->order_id ) ) {
									$order = wc_get_order( (int) $tx->order_id );
									if ( $order && $order->get_user_id() === $user_id ) {
										echo '<a href="' . esc_url( $order->get_view_order_url() ) . '">#' . (int) $tx->order_id . '</a>';
									} else {
										echo '#' . (int) $tx->order_id;
									}
								} else {
									echo '—';
								}
								?>
							</td>
							<td data-title="<?php esc_attr_e( 'Note', 'wp-simple-wallet' ); ?>"><?php echo esc_html( $tx->note ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif;
	}
}
