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
		add_action( 'admin_post_wsw_save_user', array( $this, 'handle_save_user' ) );
		add_action( 'admin_post_wsw_enable_wallet', array( $this, 'handle_enable_wallet' ) );
		add_action( 'admin_post_wsw_remove_wallet', array( $this, 'handle_remove_wallet' ) );
		add_action( 'wp_ajax_wsw_search_users', array( $this, 'ajax_search_users' ) );

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

		$out['myaccount_position']   = isset( $input['myaccount_position'] ) ? sanitize_text_field( wp_unslash( $input['myaccount_position'] ) ) : $current['myaccount_position'];
		$out['myaccount_show_icon']  = isset( $input['myaccount_show_icon'] ) && 'yes' === $input['myaccount_show_icon'] ? 'yes' : 'no';
		$out['myaccount_icon_glyph'] = isset( $input['myaccount_icon_glyph'] ) ? sanitize_text_field( wp_unslash( $input['myaccount_icon_glyph'] ) ) : $current['myaccount_icon_glyph'];

		if ( $out['myaccount_position'] !== $current['myaccount_position']
			|| $out['myaccount_show_icon'] !== $current['myaccount_show_icon']
			|| $out['myaccount_icon_glyph'] !== $current['myaccount_icon_glyph']
		) {
			delete_option( 'wsw_rewrite_flushed' );
		}

		return $out;
	}

	public function enqueue_assets( $hook ) {
		if ( false === strpos( (string) $hook, self::MENU_SLUG ) ) {
			return;
		}
		wp_add_inline_style(
			'common',
			'
			.wsw-balance-positive{color:#2e7d32;font-weight:600}
			.wsw-balance-negative{color:#c62828;font-weight:600}
			.wsw-adjust-form input[type=number]{width:120px}
			.wsw-modal{position:fixed;inset:0;z-index:100100;display:none;align-items:center;justify-content:center}
			.wsw-modal.is-open{display:flex}
			.wsw-modal-backdrop{position:absolute;inset:0;background:rgba(0,0,0,0.5)}
			.wsw-modal-dialog{position:relative;background:#fff;border-radius:6px;padding:24px;width:90%;max-width:520px;box-shadow:0 4px 20px rgba(0,0,0,0.25)}
			.wsw-modal-dialog h2{margin-top:0}
			.wsw-modal-dialog input[type=search]{width:100%;padding:8px;margin:8px 0 0}
			.wsw-modal-actions{margin-top:14px;text-align:right}
			#wsw-search-results{max-height:320px;overflow-y:auto;margin:12px -8px;border-top:1px solid #eee}
			.wsw-user-result{padding:10px 8px;border-bottom:1px solid #f0f0f0;cursor:pointer;display:flex;justify-content:space-between;align-items:center}
			.wsw-user-result:hover{background:#f0f6fc}
			.wsw-user-result.already-active{color:#888;cursor:not-allowed}
			.wsw-user-result.already-active:hover{background:transparent}
			.wsw-user-result small{color:#777}
			.wsw-user-result .wsw-tag{background:#dcdcde;color:#1d2327;padding:2px 8px;border-radius:10px;font-size:11px}
			.wsw-user-result.already-active .wsw-tag{background:#cfe5cf;color:#1d3a1d}
			.wsw-search-empty{padding:16px;color:#777;text-align:center}
			.wsw-row-actions{display:flex;gap:6px}
			'
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

		// Flash messages.
		if ( isset( $_GET['wsw_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$msg  = sanitize_text_field( wp_unslash( $_GET['wsw_msg'] ) ); // phpcs:ignore
			$map  = array(
				'enabled'                => array( 'updated', __( 'Wallet enabled for the selected user.', 'wp-simple-wallet' ) ),
				'removed'                => array( 'updated', __( 'Wallet disabled. Balance and transaction history were preserved.', 'wp-simple-wallet' ) ),
				'saved'                  => array( 'updated', __( 'Changes saved.', 'wp-simple-wallet' ) ),
				'saved_with_adjustment'  => array( 'updated', __( 'Changes saved and balance adjustment applied.', 'wp-simple-wallet' ) ),
				'ok'                     => array( 'updated', __( 'Done.', 'wp-simple-wallet' ) ),
			);
			if ( isset( $map[ $msg ] ) ) {
				echo '<div class="notice notice-' . esc_attr( $map[ $msg ][0] ) . ' is-dismissible"><p>' . esc_html( $map[ $msg ][1] ) . '</p></div>';
			} else {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
			}
		}
		?>
		<div style="margin:12px 0;display:flex;gap:8px;align-items:center;justify-content:space-between;flex-wrap:wrap">
			<form method="get" style="display:flex;gap:6px;margin:0">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search user…', 'wp-simple-wallet' ); ?>" />
				<button type="submit" class="button"><?php esc_html_e( 'Search', 'wp-simple-wallet' ); ?></button>
			</form>
			<button type="button" class="button button-primary" id="wsw-open-create-modal">
				+ <?php esc_html_e( 'Create wallet for user', 'wp-simple-wallet' ); ?>
			</button>
		</div>
		<?php $this->render_create_wallet_modal(); ?>
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
								<div class="wsw-row-actions">
									<a href="<?php echo esc_url( $detail_url ); ?>" class="button button-small"><?php esc_html_e( 'Manage', 'wp-simple-wallet' ); ?></a>
									<?php
									$remove_url = wp_nonce_url(
										add_query_arg(
											array(
												'action'  => 'wsw_remove_wallet',
												'user_id' => $user->ID,
											),
											admin_url( 'admin-post.php' )
										),
										'wsw_remove_wallet_' . $user->ID
									);
									$confirm_text = sprintf(
										/* translators: %s user display name */
										__( "Disable the wallet for %s?\n\nBalance and transaction history are KEPT. If the user has the 'Wallet Customer' role it will be changed to 'Customer'.", 'wp-simple-wallet' ),
										$user->display_name
									);
									?>
									<a href="<?php echo esc_url( $remove_url ); ?>"
									   class="button button-small button-link-delete"
									   onclick="return confirm(<?php echo wp_json_encode( $confirm_text ); ?>);">
										<?php esc_html_e( 'Remove', 'wp-simple-wallet' ); ?>
									</a>
								</div>
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

		<?php
		$policy   = WSW_User::get_overdraft( $user_id );
		$defaults = WSW_Wallet::get_settings();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wsw-user-form">
			<?php wp_nonce_field( 'wsw_save_user_' . $user_id, 'wsw_user_form_nonce' ); ?>
			<input type="hidden" name="action" value="wsw_save_user" />
			<input type="hidden" name="user_id" value="<?php echo (int) $user_id; ?>" />

			<h3><?php esc_html_e( 'Adjust balance', 'wp-simple-wallet' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Leave amount empty if you only want to update the overdraft limit.', 'wp-simple-wallet' ); ?></p>
			<table class="form-table">
				<tr>
					<th><label><?php esc_html_e( 'Type', 'wp-simple-wallet' ); ?></label></th>
					<td>
						<select name="direction">
							<option value="credit"><?php esc_html_e( 'Add (credit)', 'wp-simple-wallet' ); ?></option>
							<option value="debit"><?php esc_html_e( 'Subtract (debit)', 'wp-simple-wallet' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th><label><?php esc_html_e( 'Amount', 'wp-simple-wallet' ); ?></label></th>
					<td><input type="number" step="0.01" min="0" name="amount" value="" /></td>
				</tr>
				<tr>
					<th><label><?php esc_html_e( 'Note', 'wp-simple-wallet' ); ?></label></th>
					<td><input type="text" name="note" class="regular-text" value="" /></td>
				</tr>
			</table>

			<h3><?php esc_html_e( 'Overdraft limit (this user)', 'wp-simple-wallet' ); ?></h3>
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Allow negative balance', 'wp-simple-wallet' ); ?></th>
					<td>
						<select name="allow_negative">
							<option value="" <?php selected( $policy['user_allow_raw'], '' ); ?>>
								<?php
								/* translators: %s: yes or no */
								printf( esc_html__( 'Use store default (%s)', 'wp-simple-wallet' ), 'yes' === $defaults['allow_negative'] ? esc_html__( 'yes', 'wp-simple-wallet' ) : esc_html__( 'no', 'wp-simple-wallet' ) );
								?>
							</option>
							<option value="yes" <?php selected( $policy['user_allow_raw'], 'yes' ); ?>><?php esc_html_e( 'Yes — this user can overdraw', 'wp-simple-wallet' ); ?></option>
							<option value="no"  <?php selected( $policy['user_allow_raw'], 'no' ); ?>><?php esc_html_e( 'No — block negative balance', 'wp-simple-wallet' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Max negative balance', 'wp-simple-wallet' ); ?></th>
					<td>
						<input type="number" step="0.01" min="0" name="max_negative" value="<?php echo esc_attr( $policy['user_max_raw'] ); ?>" placeholder="<?php
							/* translators: %s: max negative balance from store defaults */
							printf( esc_attr__( 'Empty = store default (%s)', 'wp-simple-wallet' ), esc_attr( $defaults['max_negative'] ) );
						?>" />
						<p class="description">
							<?php
							$badge_allow = sprintf( '<code>%s</code>', 'user' === $policy['allow_source'] ? esc_html__( 'user', 'wp-simple-wallet' ) : esc_html__( 'default', 'wp-simple-wallet' ) );
							$badge_max   = sprintf( '<code>%s</code>', 'user'   === $policy['max_source']   ? esc_html__( 'user', 'wp-simple-wallet' ) : esc_html__( 'default', 'wp-simple-wallet' ) );
							echo wp_kses_post( sprintf(
								/* translators: 1: allow_negative effective value, 2: max_negative effective value, 3: source badge for allow, 4: source badge for max */
								__( 'Effective: allow = <strong>%1$s</strong> (%3$s), max = <strong>%2$s</strong> (%4$s). 0 = unlimited.', 'wp-simple-wallet' ),
								$policy['allow_negative'] ? esc_html__( 'yes', 'wp-simple-wallet' ) : esc_html__( 'no', 'wp-simple-wallet' ),
								wc_price( $policy['max_negative'] ),
								$badge_allow,
								$badge_max
							) );
							?>
						</p>
					</td>
				</tr>
			</table>

			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save changes', 'wp-simple-wallet' ); ?></button></p>
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
					<td>
						<textarea class="large-text" rows="3" name="wsw_settings[gateway_description]"><?php echo esc_textarea( $settings['gateway_description'] ); ?></textarea>
						<p class="description">
							<?php
							echo wp_kses_post(
								__( 'Shown to customers under the payment method in checkout. Available placeholder: <code>{balance}</code> — replaced with the customer\'s current wallet balance.', 'wp-simple-wallet' )
							);
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'My Account menu position', 'wp-simple-wallet' ); ?></th>
					<td>
						<?php
						$positions = array(
							'first'           => __( 'First (top of the menu)', 'wp-simple-wallet' ),
							'dashboard'       => __( 'After Dashboard', 'wp-simple-wallet' ),
							'orders'          => __( 'After Orders', 'wp-simple-wallet' ),
							'downloads'       => __( 'After Downloads', 'wp-simple-wallet' ),
							'edit-address'    => __( 'After Addresses', 'wp-simple-wallet' ),
							'payment-methods' => __( 'After Payment methods', 'wp-simple-wallet' ),
							'edit-account'    => __( 'After Account details', 'wp-simple-wallet' ),
							'last'            => __( 'Last (just before Logout)', 'wp-simple-wallet' ),
						);
						?>
						<select name="wsw_settings[myaccount_position]">
							<?php foreach ( $positions as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['myaccount_position'], $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Where the "Wallet" link appears in the customer "My Account" menu.', 'wp-simple-wallet' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Show menu icon', 'wp-simple-wallet' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="wsw_settings[myaccount_show_icon]" value="yes" <?php checked( $settings['myaccount_show_icon'], 'yes' ); ?> />
							<?php esc_html_e( 'Add a dashicon next to the Wallet menu label (uncheck if your theme provides its own icons).', 'wp-simple-wallet' ); ?>
						</label>
						<p class="description">
							<?php
							printf(
								/* translators: %s: link to dashicons reference */
								esc_html__( 'Dashicon glyph code (default: %s). Find more codes at the Dashicons reference.', 'wp-simple-wallet' ),
								'<code>\f18e</code>'
							);
							?>
							<br/>
							<input type="text" name="wsw_settings[myaccount_icon_glyph]" value="<?php echo esc_attr( $settings['myaccount_icon_glyph'] ); ?>" class="regular-text" placeholder="\f18e" />
						</p>
					</td>
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

	private function render_create_wallet_modal() {
		$nonce        = wp_create_nonce( 'wsw_search_users' );
		$enable_nonce = wp_create_nonce( 'wsw_enable_wallet' );
		$post_url     = esc_url( admin_url( 'admin-post.php' ) );
		$ajax_url     = esc_url( admin_url( 'admin-ajax.php' ) );
		?>
		<div id="wsw-create-modal" class="wsw-modal" aria-hidden="true">
			<div class="wsw-modal-backdrop" data-wsw-close></div>
			<div class="wsw-modal-dialog" role="dialog" aria-modal="true">
				<h2><?php esc_html_e( 'Create wallet for a user', 'wp-simple-wallet' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Search the user by name, login or email and click them to enable their wallet.', 'wp-simple-wallet' ); ?></p>
				<input type="search" id="wsw-user-search" placeholder="<?php esc_attr_e( 'Type at least 2 characters…', 'wp-simple-wallet' ); ?>" autocomplete="off" />
				<div id="wsw-search-results"></div>
				<div class="wsw-modal-actions">
					<button type="button" class="button" data-wsw-close><?php esc_html_e( 'Cancel', 'wp-simple-wallet' ); ?></button>
				</div>
				<form id="wsw-enable-form" method="post" action="<?php echo $post_url; // phpcs:ignore ?>" style="display:none">
					<input type="hidden" name="action" value="wsw_enable_wallet" />
					<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $enable_nonce ); ?>" />
					<input type="hidden" name="user_id" id="wsw-enable-user-id" value="" />
				</form>
			</div>
		</div>
		<script>
		(function($){
			var $modal   = $('#wsw-create-modal');
			var $input   = $('#wsw-user-search');
			var $results = $('#wsw-search-results');
			var $form    = $('#wsw-enable-form');
			var timer    = null;

			function openModal(){ $modal.addClass('is-open'); $input.val('').focus(); $results.empty(); }
			function closeModal(){ $modal.removeClass('is-open'); }

			$('#wsw-open-create-modal').on('click', openModal);
			$modal.on('click', '[data-wsw-close]', closeModal);
			$(document).on('keydown', function(e){ if(e.key === 'Escape') closeModal(); });

			$input.on('input', function(){
				clearTimeout(timer);
				var q = $input.val().trim();
				if (q.length < 2) { $results.empty(); return; }
				timer = setTimeout(function(){ doSearch(q); }, 250);
			});

			function doSearch(q){
				$results.html('<div class="wsw-search-empty"><?php echo esc_js( __( 'Searching…', 'wp-simple-wallet' ) ); ?></div>');
				$.post('<?php echo $ajax_url; // phpcs:ignore ?>', {
					action: 'wsw_search_users',
					_wpnonce: '<?php echo esc_js( $nonce ); ?>',
					s: q
				}).done(function(resp){
					if (!resp || !resp.success) {
						$results.html('<div class="wsw-search-empty">' + ((resp && resp.data) ? resp.data : '<?php echo esc_js( __( 'Error', 'wp-simple-wallet' ) ); ?>') + '</div>');
						return;
					}
					if (!resp.data.length) {
						$results.html('<div class="wsw-search-empty"><?php echo esc_js( __( 'No users found.', 'wp-simple-wallet' ) ); ?></div>');
						return;
					}
					var html = '';
					resp.data.forEach(function(u){
						var cls = u.active ? 'wsw-user-result already-active' : 'wsw-user-result';
						var tag = u.active
							? '<span class="wsw-tag"><?php echo esc_js( __( 'Already has wallet', 'wp-simple-wallet' ) ); ?></span>'
							: '<span class="wsw-tag"><?php echo esc_js( __( 'Click to enable', 'wp-simple-wallet' ) ); ?></span>';
						html += '<div class="' + cls + '" data-user-id="' + u.id + '" data-active="' + (u.active ? '1' : '0') + '">' +
							'<div><strong>' + u.name + '</strong> <small>#' + u.id + ' — ' + u.login + '</small><br/><small>' + u.email + '</small></div>' +
							tag +
							'</div>';
					});
					$results.html(html);
				}).fail(function(){
					$results.html('<div class="wsw-search-empty"><?php echo esc_js( __( 'Request failed.', 'wp-simple-wallet' ) ); ?></div>');
				});
			}

			$results.on('click', '.wsw-user-result', function(){
				if ($(this).data('active') === 1 || $(this).data('active') === '1') return;
				var id = $(this).data('user-id');
				$('#wsw-enable-user-id').val(id);
				$form.trigger('submit');
			});
		})(jQuery);
		</script>
		<?php
	}

	public function ajax_search_users() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'wp-simple-wallet' ), 403 );
		}
		check_ajax_referer( 'wsw_search_users' );

		$s = isset( $_POST['s'] ) ? sanitize_text_field( wp_unslash( $_POST['s'] ) ) : '';
		if ( strlen( $s ) < 2 ) {
			wp_send_json_success( array() );
		}

		$users = get_users(
			array(
				'search'         => '*' . esc_attr( $s ) . '*',
				'search_columns' => array( 'user_login', 'user_email', 'display_name', 'user_nicename' ),
				'number'         => 20,
				'orderby'        => 'display_name',
				'order'          => 'ASC',
			)
		);

		$out = array();
		foreach ( $users as $u ) {
			$out[] = array(
				'id'     => (int) $u->ID,
				'name'   => $u->display_name,
				'login'  => $u->user_login,
				'email'  => $u->user_email,
				'active' => WSW_User::is_wallet_active( $u->ID ),
			);
		}
		wp_send_json_success( $out );
	}

	public function handle_enable_wallet() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'wp-simple-wallet' ) );
		}
		check_admin_referer( 'wsw_enable_wallet' );

		$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		if ( ! $user_id || ! get_user_by( 'id', $user_id ) ) {
			wp_safe_redirect(
				add_query_arg(
					array( 'page' => self::MENU_SLUG, 'tab' => 'wallets', 'wsw_msg' => rawurlencode( __( 'Invalid user.', 'wp-simple-wallet' ) ) ),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		WSW_User::set_wallet_active( $user_id, true );

		wp_safe_redirect(
			add_query_arg(
				array( 'page' => self::MENU_SLUG, 'tab' => 'wallets', 'wsw_msg' => 'enabled' ),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function handle_remove_wallet() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'wp-simple-wallet' ) );
		}
		$user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
		check_admin_referer( 'wsw_remove_wallet_' . $user_id );

		$user = $user_id ? get_user_by( 'id', $user_id ) : null;
		if ( ! $user ) {
			wp_safe_redirect(
				add_query_arg(
					array( 'page' => self::MENU_SLUG, 'tab' => 'wallets', 'wsw_msg' => rawurlencode( __( 'Invalid user.', 'wp-simple-wallet' ) ) ),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		// Flip the meta flag off.
		WSW_User::set_wallet_active( $user_id, false );

		// If they were active via the dedicated role, downgrade to default customer.
		if ( in_array( WSW_User::ROLE, (array) $user->roles, true ) ) {
			$user->remove_role( WSW_User::ROLE );
			$still_roles = (array) $user->roles;
			if ( empty( $still_roles ) ) {
				$user->add_role( 'customer' );
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array( 'page' => self::MENU_SLUG, 'tab' => 'wallets', 'wsw_msg' => 'removed' ),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Unified handler for the user detail form: applies an optional balance
	 * adjustment AND saves the per-user overdraft policy in a single request.
	 *
	 * Limits are always persisted. The adjustment is only attempted when the
	 * admin entered an amount > 0. If the adjustment fails (insufficient
	 * balance, overdraft cap, etc.), limits are still saved and the error is
	 * reported back to the admin.
	 */
	public function handle_save_user() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'wp-simple-wallet' ) );
		}
		$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		check_admin_referer( 'wsw_save_user_' . $user_id, 'wsw_user_form_nonce' );

		// 1) Save limits — never fails.
		$allow = isset( $_POST['allow_negative'] ) ? sanitize_text_field( wp_unslash( $_POST['allow_negative'] ) ) : '';
		$max   = isset( $_POST['max_negative'] ) ? sanitize_text_field( wp_unslash( $_POST['max_negative'] ) ) : '';
		WSW_User::set_overdraft( $user_id, $allow, $max );

		// 2) Apply adjustment only if an amount was entered.
		$amount = isset( $_POST['amount'] ) ? abs( (float) wp_unslash( $_POST['amount'] ) ) : 0;
		$msg    = 'saved';

		if ( $amount > 0 ) {
			$direction = isset( $_POST['direction'] ) ? sanitize_key( wp_unslash( $_POST['direction'] ) ) : 'credit';
			$note      = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '';
			$delta     = 'debit' === $direction ? -$amount : $amount;

			$result = WSW_Wallet::adjust( $user_id, $delta, WSW_Wallet::TYPE_ADJUSTMENT, $note );
			$msg    = is_wp_error( $result ) ? rawurlencode( $result->get_error_message() ) : 'saved_with_adjustment';
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::MENU_SLUG,
					'tab'     => 'wallets',
					'user_id' => $user_id,
					'wsw_msg' => $msg,
				),
				admin_url( 'admin.php' )
			)
		);
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
