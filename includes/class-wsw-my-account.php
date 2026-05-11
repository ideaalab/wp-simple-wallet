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
		add_filter( 'query_vars', array( $this, 'query_vars' ), 0 );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'menu_items' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $this, 'render_content' ) );

		register_activation_hook( WSW_PLUGIN_FILE, array( $this, 'flush_rewrite_on_activate' ) );
	}

	public function flush_rewrite_on_activate() {
		$this->add_endpoint();
		flush_rewrite_rules();
	}

	public function add_endpoint() {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	public function query_vars( $vars ) {
		$vars[] = self::ENDPOINT;
		return $vars;
	}

	public function menu_items( $items ) {
		$user_id = get_current_user_id();
		if ( ! $user_id || ! WSW_User::is_wallet_active( $user_id ) ) {
			return $items;
		}

		$new = array();
		foreach ( $items as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'dashboard' === $key ) {
				$new[ self::ENDPOINT ] = __( 'Wallet', 'wp-simple-wallet' );
			}
		}
		if ( ! isset( $new[ self::ENDPOINT ] ) ) {
			$new[ self::ENDPOINT ] = __( 'Wallet', 'wp-simple-wallet' );
		}
		return $new;
	}

	public function render_content() {
		$user_id = get_current_user_id();
		if ( ! $user_id || ! WSW_User::is_wallet_active( $user_id ) ) {
			echo '<p>' . esc_html__( 'Wallet is not enabled for your account.', 'wp-simple-wallet' ) . '</p>';
			return;
		}

		$balance = WSW_Wallet::get_balance( $user_id );
		$txs     = WSW_Wallet::get_transactions( array( 'user_id' => $user_id, 'limit' => 50 ) );
		?>
		<h2><?php esc_html_e( 'Wallet balance', 'wp-simple-wallet' ); ?>: <?php echo wp_kses_post( wc_price( $balance ) ); ?></h2>
		<h3><?php esc_html_e( 'Recent movements', 'wp-simple-wallet' ); ?></h3>
		<?php if ( empty( $txs ) ) : ?>
			<p><?php esc_html_e( 'You have no wallet movements yet.', 'wp-simple-wallet' ); ?></p>
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
					<?php foreach ( $txs as $tx ) : ?>
						<tr>
							<td><?php echo esc_html( $tx->created_at ); ?></td>
							<td><?php echo esc_html( WSW_Wallet::type_label( $tx->type ) ); ?></td>
							<td><?php echo wp_kses_post( wc_price( (float) $tx->amount ) ); ?></td>
							<td><?php echo wp_kses_post( wc_price( (float) $tx->balance_after ) ); ?></td>
							<td>
								<?php if ( ! empty( $tx->order_id ) ) : ?>
									<?php
									$order = wc_get_order( (int) $tx->order_id );
									if ( $order && $order->get_user_id() === $user_id ) {
										echo '<a href="' . esc_url( $order->get_view_order_url() ) . '">#' . (int) $tx->order_id . '</a>';
									} else {
										echo '#' . (int) $tx->order_id;
									}
									?>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $tx->note ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif;
	}
}
