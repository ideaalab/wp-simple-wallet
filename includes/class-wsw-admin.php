<?php
/**
 * Admin UI: wallets list, settings, transactions, manual adjustment, CSV export,
 * user profile checkbox.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSW_Admin {

	const MENU_SLUG = 'wsw-wallets';
	const CAPABILITY = 'manage_woocommerce';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'maybe_export_csv' ) );
		add_action( 'admin_post_wsw_adjust_balance', array( $this, 'handle_adjust_balance' ) );

		// User profile checkbox.
		add_action( 'show_user_profile', array( $this, 'render_user_profile_field' ) );
		add_action( 'edit_user_profile', array( $this, 'render_user_profile_field' ) );
		add_action( 'personal_options_update', array( $this, 'save_user_profile_field' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_user_profile_field' ) );

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function register_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Wallets', 'wp-simple-wallet' ),
			__( 'Wallets', 'wp-simple-wallet' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function register_settings() {
		register_setting(
			'wsw_settings_group',
			'wsw_settings',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => array(),
			)
		);
	}

	public function sanitize_settings( $input ) {
		$current = WSW_Wallet::get_settings();
		$out     = $current;

		$out['allow_negative']       = isset( $input['allow_negative'] ) && 'yes' === $input['allow_negative'] ? 'yes' : 'no';
		$out['max_negative']         = isset( $input['max_negative'] ) ? max( 0, (float) $input['max_negative'] ) : 0;
		$out['cleanup_on_uninstall'] = isset( $input['cleanup_on_uninstall'] ) && 'yes' === $input['cleanup_on_uninstall'] ? 'yes' : 'no';
		$out['gateway_title']        = isset( $input['gateway_title'] ) ? sanitize_text_field( wp_unslash( $input['gateway_title'] ) ) : $current['gateway_title'];
		$out['gateway_description']  = isset( $input['gateway_description'] ) ? sanitize_textarea_field( wp_unslash( $input['gateway_description'] ) ) : $current['gateway_description'];

		return $out;
	}

	public function enqueue_assets( $hook ) {
		if ( false === strpos( (string) $hook, self::MENU_SLUG ) ) {
			return;
		}
		wp_add_inline_style(
			'common',
			'.wsw-balance-positive{color:#2e7d32;font-weight:600}.wsw-balance-negative{color:#c62828;font-weight:600}.wsw-adjust-form input[type=number]{width:120px}'
		);
	}

	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-simple-wallet' ) );
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'wallets'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$tabs = array(
			'wallets'      => __( 'Wallets', 'wp-simple-wallet' ),
			'transactions' => __( 'Transactions', 'wp-simple-wallet' ),
			'settings'     => __( 'Settings', 'wp-simple-wallet' ),
		);

		echo '<div class="wrap"><h1>' . esc_html__( 'WP Simple Wallet', 'wp-simple-wallet' ) . '</h1>';

		echo '<h2 class="nav-tab-wrapper">';
		foreach ( $tabs as $slug => $label ) {
			$url   = add_query_arg( array( 'page' => self::MENU_SLUG, 'tab' => $slug ), admin_url( 'admin.php' ) );
			$class = 'nav-tab' . ( $tab === $slug ? ' nav-tab-active' : '' );
			echo '<a href="' . esc_url( $url ) . '" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</h2>';

		switch ( $tab ) {
			case 'transactions':
				$this->render_transactions_tab();
				break;
			case 'settings':
				$this->render_settings_tab();
				break;
			default:
				if ( isset( $_GET['user_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					$this->render_user_detail( absint( $_GET['user_id'] ) ); // phpcs:ignore
				} else {
					$this->render_wallets_tab();
				}
		}

		echo '</div>';
	}

	private function render_wallets_tab() {
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore
		$users  = WSW_User::get_users_with_wallet( array( 'search' => $search ) );
		?>
		<form method="get" style="margin:12px 0;">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search user…', 'wp-simple-wallet' ); ?>" />
			<button type="submit" class="button"><?php esc_html_e( 'Search', 'wp-simple-wallet' ); ?></button>
		</form>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'User', 'wp-simple-wallet' ); ?></th>
					<th><?php esc_html_e( 'Email', 'wp-simple-wallet' ); ?></th>
					<th><?php esc_html_e( 'Activation', 'wp-simple-wallet' ); ?></th>
					<th><?php esc_html_e( 'Balance', 'wp-simple-wallet' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'wp-simple-wallet' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $users ) ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'No users with wallet enabled.', 'wp-simple-wallet' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $users as $user ) :
						$balance     = WSW_Wallet::get_balance( $user->ID );
						$has_role    = in_array( WSW_User::ROLE, (array) $user->roles, true );
						$meta_active = 'yes' === get_user_meta( $user->ID, WSW_User::META_ACTIVE, true );
						$activation  = array();
						if ( $has_role ) {
							$activation[] = __( 'Role', 'wp-simple-wallet' );
						}
						if ( $meta_active ) {
							$activation[] = __( 'Profile flag', 'wp-simple-wallet' );
						}
						$detail_url = add_query_arg(
							array(
								'page'    => self::MENU_SLUG,
								'tab'     => 'wallets',
								'user_id' => $user->ID,
							),
							admin_url( 'admin.php' )
						);
						?>
						<tr>
							<td>
								<strong><?php echo esc_html( $user->display_name ); ?></strong><br />
								<small>#<?php echo (int) $user->ID; ?> — <?php echo esc_html( $user->user_login ); ?></small>
							</td>
							<td><?php echo esc_html( $user->user_email ); ?></td>
							<td><?php echo esc_html( implode( ', ', $activation ) ); ?></td>
							<td>
								<span class="<?php echo $balance < 0 ? 'wsw-balance-negative' : 'wsw-balance-positive'; ?>">
									<?php echo wp_kses_post( wc_price( $balance ) ); ?>
								</span>
							</td>
							<td>
								<a href="<?php echo esc_url( $detail_url ); ?>" class="button button-small"><?php esc_html_e( 'Manage', 'wp-simple-wallet' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	private function render_user_detail( $user_id ) {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			echo '<p>' . esc_html__( 'User not found.', 'wp-simple-wallet' ) . '</p>';
			return;
		}
		$balance = WSW_Wallet::get_balance( $user_id );
		$back    = add_query_arg( array( 'page' => self::MENU_SLUG, 'tab' => 'wallets' ), admin_url( 'admin.php' ) );

		$txs = WSW_Wallet::get_transactions( array( 'user_id' => $user_id, 'limit' => 25 ) );
		?>
		<p><a href="<?php echo esc_url( $back ); ?>">&larr; <?php esc_html_e( 'Back to wallets', 'wp-simple-wallet' ); ?></a></p>
		<h2><?php echo esc_html( $user->display_name ); ?> — <?php echo wp_kses_post( wc_price( $balance ) ); ?></h2>

		<h3><?php esc_html_e( 'Adjust balance', 'wp-simple-wallet' ); ?></h3>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wsw-adjust-form">
			<?php wp_nonce_field( 'wsw_adjust_balance_' . $user_id, 'wsw_nonce' ); ?>
			<input type="hidden" name="action" value="wsw_adjust_balance" />
			<input type="hidden" name="user_id" value="<?php echo (int) $user_id; ?>" />
			<p>
				<label><?php esc_html_e( 'Type', 'wp-simple-wallet' ); ?>
					<select name="direction">
						<option value="credit"><?php esc_html_e( 'Add (credit)', 'wp-simple-wallet' ); ?></option>
						<option value="debit"><?php esc_html_e( 'Subtract (debit)', 'wp-simple-wallet' ); ?></option>
					</select>
				</label>
				&nbsp;
				<label><?php esc_html_e( 'Amount', 'wp-simple-wallet' ); ?>
					<input type="number" step="0.01" min="0" name="amount" required />
				</label>
			</p>
			<p>
				<label style="display:block"><?php esc_html_e( 'Note', 'wp-simple-wallet' ); ?>
					<input type="text" name="note" class="regular-text" />
				</label>
			</p>
			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Apply adjustment', 'wp-simple-wallet' ); ?></button></p>
		</form>

		<h3><?php esc_html_e( 'Recent transactions', 'wp-simple-wallet' ); ?></h3>
		<?php $this->render_transactions_table( $txs ); ?>

		<p>
			<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => self::MENU_SLUG, 'tab' => 'transactions', 'user_id' => $user_id ), admin_url( 'admin.php' ) ) ); ?>">
				<?php esc_html_e( 'View full history', 'wp-simple-wallet' ); ?>
			</a>
		</p>
		<?php
	}

	private function render_transactions_tab() {
		$user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0; // phpcs:ignore
		$paged   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore
		$per     = 50;
		$offset  = ( $paged - 1 ) * $per;

		$txs   = WSW_Wallet::get_transactions(
			array(
				'user_id' => $user_id,
				'limit'   => $per,
				'offset'  => $offset,
			)
		);
		$total = WSW_Wallet::count_transactions( $user_id );
		$pages = max( 1, (int) ceil( $total / $per ) );

		$export_args = array( 'page' => self::MENU_SLUG, 'tab' => 'transactions', 'wsw_export' => 'csv' );
		if ( $user_id ) {
			$export_args['user_id'] = $user_id;
		}
		$export_url = wp_nonce_url( add_query_arg( $export_args, admin_url( 'admin.php' ) ), 'wsw_export_csv' );
		?>
		<form method="get" style="margin:12px 0;">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
			<input type="hidden" name="tab" value="transactions" />
			<label><?php esc_html_e( 'Filter by user ID', 'wp-simple-wallet' ); ?>:
				<input type="number" name="user_id" value="<?php echo $user_id ? (int) $user_id : ''; ?>" />
			</label>
			<button type="submit" class="button"><?php esc_html_e( 'Filter', 'wp-simple-wallet' ); ?></button>
			<a class="button" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'Export CSV', 'wp-simple-wallet' ); ?></a>
		</form>
		<?php $this->render_transactions_table( $txs ); ?>
		<?php if ( $pages > 1 ) : ?>
			<p>
				<?php
				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => add_query_arg( 'paged', '%#%' ),
							'format'    => '',
							'total'     => $pages,
							'current'   => $paged,
							'show_all'  => false,
							'prev_text' => '&laquo;',
							'next_text' => '&raquo;',
						)
					)
				);
				?>
			</p>
		<?php endif; ?>
		<?php
	}

	private function render_transactions_table( $txs ) {
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Date', 'wp-simple-wallet' ); ?></th>
					<th><?php esc_html_e( 'User', 'wp-simple-wallet' ); ?></th>
					<th><?php esc_html_e( 'Type', 'wp-simple-wallet' ); ?></th>
					<th><?php esc_html_e( 'Source', 'wp-simple-wallet' ); ?></th>
					<th><?php esc_html_e( 'Amount', 'wp-simple-wallet' ); ?></th>
					<th><?php esc_html_e( 'Balance after', 'wp-simple-wallet' ); ?></th>
					<th><?php esc_html_e( 'Order', 'wp-simple-wallet' ); ?></th>
					<th><?php esc_html_e( 'Note', 'wp-simple-wallet' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $txs ) ) : ?>
					<tr><td colspan="8"><?php esc_html_e( 'No transactions.', 'wp-simple-wallet' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $txs as $tx ) :
						$u       = get_user_by( 'id', $tx->user_id );
						$amount  = (float) $tx->amount;
						$cls     = $amount < 0 ? 'wsw-balance-negative' : 'wsw-balance-positive';
						$order_link = '';
						if ( ! empty( $tx->order_id ) ) {
							$order_link = '<a href="' . esc_url( admin_url( 'post.php?post=' . (int) $tx->order_id . '&action=edit' ) ) . '">#' . (int) $tx->order_id . '</a>';
							if ( function_exists( 'wc_get_container' ) && class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' ) ) {
								$hpos_url = admin_url( 'admin.php?page=wc-orders&action=edit&id=' . (int) $tx->order_id );
								$order_link = '<a href="' . esc_url( $hpos_url ) . '">#' . (int) $tx->order_id . '</a>';
							}
						}
						?>
						<tr>
							<td><?php echo esc_html( $tx->created_at ); ?></td>
							<td>
								<?php if ( $u ) : ?>
									<?php echo esc_html( $u->display_name ); ?> <small>(#<?php echo (int) $u->ID; ?>)</small>
								<?php else : ?>
									#<?php echo (int) $tx->user_id; ?>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( WSW_Wallet::type_label( $tx->type ) ); ?></td>
							<td><code><?php echo esc_html( $tx->source ? $tx->source : '—' ); ?></code></td>
							<td><span class="<?php echo esc_attr( $cls ); ?>"><?php echo wp_kses_post( wc_price( $amount ) ); ?></span></td>
							<td><?php echo wp_kses_post( wc_price( (float) $tx->balance_after ) ); ?></td>
							<td><?php echo wp_kses_post( $order_link ); ?></td>
							<td><?php echo esc_html( $tx->note ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	private function render_settings_tab() {
		$settings = WSW_Wallet::get_settings();
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'wsw_settings_group' ); ?>
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Allow negative balance', 'wp-simple-wallet' ); ?></th>
					<td>
						<label><input type="checkbox" name="wsw_settings[allow_negative]" value="yes" <?php checked( $settings['allow_negative'], 'yes' ); ?> />
							<?php esc_html_e( 'Allow customers to overdraw the wallet', 'wp-simple-wallet' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Max negative balance', 'wp-simple-wallet' ); ?></th>
					<td>
						<input type="number" step="0.01" min="0" name="wsw_settings[max_negative]" value="<?php echo esc_attr( $settings['max_negative'] ); ?>" />
						<p class="description"><?php esc_html_e( '0 = unlimited. Only applies when negative balance is allowed.', 'wp-simple-wallet' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Gateway title', 'wp-simple-wallet' ); ?></th>
					<td><input type="text" class="regular-text" name="wsw_settings[gateway_title]" value="<?php echo esc_attr( $settings['gateway_title'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Gateway description', 'wp-simple-wallet' ); ?></th>
					<td><textarea class="large-text" rows="3" name="wsw_settings[gateway_description]"><?php echo esc_textarea( $settings['gateway_description'] ); ?></textarea></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Remove data on uninstall', 'wp-simple-wallet' ); ?></th>
					<td>
						<label><input type="checkbox" name="wsw_settings[cleanup_on_uninstall]" value="yes" <?php checked( $settings['cleanup_on_uninstall'], 'yes' ); ?> />
							<?php esc_html_e( 'Delete table, options, role and meta when the plugin is deleted from WordPress.', 'wp-simple-wallet' ); ?>
						</label>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php
	}

	public function handle_adjust_balance() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'wp-simple-wallet' ) );
		}
		$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		check_admin_referer( 'wsw_adjust_balance_' . $user_id, 'wsw_nonce' );

		$direction = isset( $_POST['direction'] ) ? sanitize_key( wp_unslash( $_POST['direction'] ) ) : 'credit';
		$amount    = isset( $_POST['amount'] ) ? abs( (float) wp_unslash( $_POST['amount'] ) ) : 0;
		$note      = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '';

		$delta = 'debit' === $direction ? -$amount : $amount;

		$result = WSW_Wallet::adjust( $user_id, $delta, WSW_Wallet::TYPE_ADJUSTMENT, $note );

		$redirect = add_query_arg(
			array(
				'page'    => self::MENU_SLUG,
				'tab'     => 'wallets',
				'user_id' => $user_id,
				'wsw_msg' => is_wp_error( $result ) ? rawurlencode( $result->get_error_message() ) : 'ok',
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	public function maybe_export_csv() {
		if ( ! isset( $_GET['page'], $_GET['wsw_export'] ) ) { // phpcs:ignore
			return;
		}
		if ( self::MENU_SLUG !== $_GET['page'] || 'csv' !== $_GET['wsw_export'] ) { // phpcs:ignore
			return;
		}
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		check_admin_referer( 'wsw_export_csv' );

		$user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
		$txs     = WSW_Wallet::get_transactions(
			array(
				'user_id' => $user_id,
				'limit'   => -1,
			)
		);

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=wsw-transactions-' . gmdate( 'Y-m-d-His' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'id', 'created_at', 'user_id', 'user_login', 'user_email', 'type', 'source', 'amount', 'balance_after', 'order_id', 'note', 'created_by' ) );
		foreach ( $txs as $tx ) {
			$u = get_user_by( 'id', $tx->user_id );
			fputcsv(
				$out,
				array(
					$tx->id,
					$tx->created_at,
					$tx->user_id,
					$u ? $u->user_login : '',
					$u ? $u->user_email : '',
					$tx->type,
					$tx->source,
					$tx->amount,
					$tx->balance_after,
					$tx->order_id,
					$tx->note,
					$tx->created_by,
				)
			);
		}
		fclose( $out );
		exit;
	}

	public function render_user_profile_field( $user ) {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$active  = 'yes' === get_user_meta( $user->ID, WSW_User::META_ACTIVE, true );
		$balance = WSW_Wallet::get_balance( $user->ID );
		?>
		<h2><?php esc_html_e( 'WP Simple Wallet', 'wp-simple-wallet' ); ?></h2>
		<table class="form-table">
			<tr>
				<th><label for="wsw_wallet_active"><?php esc_html_e( 'Enable wallet for this user', 'wp-simple-wallet' ); ?></label></th>
				<td>
					<?php wp_nonce_field( 'wsw_save_user_' . $user->ID, 'wsw_user_nonce' ); ?>
					<input type="checkbox" id="wsw_wallet_active" name="wsw_wallet_active" value="yes" <?php checked( $active ); ?> />
					<p class="description">
						<?php esc_html_e( 'Users with the "Wallet Customer" role have the wallet enabled automatically.', 'wp-simple-wallet' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Current balance', 'wp-simple-wallet' ); ?></th>
				<td><?php echo wp_kses_post( wc_price( $balance ) ); ?></td>
			</tr>
		</table>
		<?php
	}

	public function save_user_profile_field( $user_id ) {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		if ( ! isset( $_POST['wsw_user_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wsw_user_nonce'] ) ), 'wsw_save_user_' . $user_id ) ) {
			return;
		}
		WSW_User::set_wallet_active( $user_id, isset( $_POST['wsw_wallet_active'] ) );
	}
}
