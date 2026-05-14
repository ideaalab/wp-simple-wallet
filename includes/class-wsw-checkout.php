<?php
/**
 * Checkout integration: wallet balance box (gift-card style).
 *
 * Replaces the old WC payment gateway. Applies the wallet balance as a
 * negative fee on the cart so the remaining total is charged to whatever
 * payment method the customer selects. If the wallet covers the full
 * amount the order processes as a zero-total (no gateway needed).
 *
 * The box is rendered via woocommerce_review_order_before_payment on the
 * initial page load. Since that action does NOT fire during WC AJAX
 * updates (wp_doing_ajax check in checkout/payment.php), the box is kept
 * in sync via the woocommerce_update_order_review_fragments filter.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSW_Checkout {

	public function __construct() {
		// Display the wallet box above payment methods (initial page load).
		add_action( 'woocommerce_review_order_before_payment', array( $this, 'render_wallet_box' ) );

		// Keep the box updated during AJAX checkout refreshes.
		add_filter( 'woocommerce_update_order_review_fragments', array( $this, 'wallet_fragment' ) );

		// Apply wallet fee during cart calculation.
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'apply_wallet_fee' ) );

		// AJAX: toggle wallet on/off.
		add_action( 'wp_ajax_wsw_toggle_wallet', array( $this, 'ajax_toggle' ) );

		// Validate wallet availability before order is created.
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_wallet' ), 10, 2 );

		// Store wallet intent in order meta (before payment gateway runs).
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'store_wallet_intent' ), 10, 3 );

		// Debit wallet after payment succeeds.
		add_action( 'woocommerce_payment_complete', array( $this, 'process_wallet_debit' ) );

		// Some gateways transition status without calling payment_complete().
		add_action( 'woocommerce_order_status_processing', array( $this, 'process_wallet_debit' ) );
		add_action( 'woocommerce_order_status_completed', array( $this, 'process_wallet_debit' ) );

		// Enqueue checkout JS/CSS once.
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_scripts' ) );

		// Clear session after checkout.
		add_action( 'woocommerce_thankyou', array( $this, 'clear_session' ) );
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * ----------------------------------------------------------------*/

	/**
	 * Maximum amount the user can apply from wallet.
	 *
	 * Takes the overdraft policy into account. Returns PHP_FLOAT_MAX when
	 * negative balance is allowed with no cap (will be capped at cart total).
	 *
	 * @param int $user_id
	 * @return float
	 */
	public static function get_max_applicable( $user_id ) {
		$balance = WSW_Wallet::get_balance( $user_id );
		$policy  = WSW_User::get_overdraft( $user_id );

		if ( $policy['allow_negative'] ) {
			if ( $policy['max_negative'] > 0 ) {
				// Can go down to -max_negative.
				return max( 0.0, $balance + $policy['max_negative'] );
			}
			// Unlimited overdraft.
			return PHP_FLOAT_MAX;
		}

		// No overdraft: can only use positive balance.
		return max( 0.0, $balance );
	}

	/**
	 * Whether the wallet box should appear at checkout.
	 *
	 * @return bool
	 */
	private function should_show_box() {
		$user_id = get_current_user_id();
		if ( ! $user_id || ! WSW_User::is_wallet_active( $user_id ) ) {
			return false;
		}
		return self::get_max_applicable( $user_id ) > 0;
	}

	/**
	 * Calculate the cart total before the wallet fee.
	 *
	 * At the point where woocommerce_cart_calculate_fees fires, item totals
	 * (after coupons), item taxes, shipping and shipping taxes are already
	 * calculated. The result includes VAT/IVA — the wallet deducts from the
	 * gross total.
	 *
	 * @param WC_Cart $cart
	 * @return float
	 */
	private function get_pre_wallet_total( $cart ) {
		$total = floatval( $cart->get_cart_contents_total() )
			   + floatval( $cart->get_cart_contents_tax() )
			   + floatval( $cart->get_shipping_total() )
			   + floatval( $cart->get_shipping_tax() );

		// Include other fees already added by different plugins.
		foreach ( $cart->get_fees() as $fee ) {
			$total += floatval( $fee->total );
			if ( ! empty( $fee->tax ) ) {
				$total += floatval( $fee->tax );
			}
		}

		return max( 0.0, round( $total, wc_get_price_decimals() ) );
	}

	/* ------------------------------------------------------------------
	 * Render
	 * ----------------------------------------------------------------*/

	/**
	 * Build the wallet box HTML.
	 *
	 * Always outputs a wrapper div with id="wsw-wallet-box" so the AJAX
	 * fragment has a stable target to replace.
	 *
	 * @return string
	 */
	private function build_wallet_box_html() {
		if ( ! $this->should_show_box() ) {
			return '<div id="wsw-wallet-box"></div>';
		}

		$user_id = get_current_user_id();
		$balance = WSW_Wallet::get_balance( $user_id );
		$applied = WC()->session ? (bool) WC()->session->get( 'wsw_apply_wallet', false ) : false;
		$amount  = WC()->session ? floatval( WC()->session->get( 'wsw_apply_amount', 0 ) ) : 0;

		ob_start();
		?>
		<div id="wsw-wallet-box" class="wsw-wallet-box<?php echo $applied ? ' wsw-wallet-applied' : ''; ?>">
			<div class="wsw-wallet-box-header">
				<span class="wsw-wallet-label"><?php esc_html_e( 'Wallet balance', 'wp-simple-wallet' ); ?></span>
				<span class="wsw-wallet-amount<?php echo $balance < 0 ? ' wsw-negative' : ''; ?>">
					<?php echo wp_kses_post( wc_price( $balance ) ); ?>
				</span>
			</div>
			<?php if ( $applied && $amount > 0 ) : ?>
				<div class="wsw-wallet-applied-row">
					<span class="wsw-wallet-discount">
						<?php
						printf(
							/* translators: %s: formatted negative price, e.g. -€42.30 */
							esc_html__( 'Discount applied: %s', 'wp-simple-wallet' ),
							wp_kses_post( wc_price( -$amount ) )
						);
						?>
					</span>
					<a href="#" id="wsw-remove-wallet" class="wsw-wallet-remove">
						&times; <?php esc_html_e( 'Remove', 'wp-simple-wallet' ); ?>
					</a>
				</div>
			<?php else : ?>
				<label class="wsw-wallet-toggle">
					<input type="checkbox" id="wsw-apply-wallet" value="1" />
					<?php esc_html_e( 'Apply wallet balance to this order', 'wp-simple-wallet' ); ?>
				</label>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Output the wallet box (initial page render via action hook).
	 */
	public function render_wallet_box() {
		echo $this->build_wallet_box_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Add the wallet box as an AJAX fragment so it stays in sync when WC
	 * refreshes the checkout order review.
	 *
	 * @param array $fragments
	 * @return array
	 */
	public function wallet_fragment( $fragments ) {
		$fragments['#wsw-wallet-box'] = $this->build_wallet_box_html();
		return $fragments;
	}

	/**
	 * Enqueue checkout-specific CSS and JS.
	 */
	public function maybe_enqueue_scripts() {
		if ( ! is_checkout() ) {
			return;
		}
		if ( ! $this->should_show_box() ) {
			return;
		}

		$nonce    = wp_create_nonce( 'wsw_checkout' );
		$ajax_url = admin_url( 'admin-ajax.php' );

		/* ---- CSS ---- */
		$css = '
			.wsw-wallet-box:empty{display:none}
			.wsw-wallet-box{border:2px solid #dcdcde;border-radius:6px;padding:16px;margin:0 0 20px;background:#fafafa;transition:border-color .2s,background .2s}
			.wsw-wallet-box.wsw-wallet-applied{border-color:#2e7d32;background:#f0faf0}
			.wsw-wallet-box.wsw-wallet-loading{opacity:.6;pointer-events:none}
			.wsw-wallet-box-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}
			.wsw-wallet-label{font-weight:600;font-size:1em}
			.wsw-wallet-amount{font-weight:700;font-size:1.1em;color:#2e7d32}
			.wsw-wallet-amount.wsw-negative{color:#c62828}
			.wsw-wallet-toggle{display:flex;align-items:center;gap:8px;cursor:pointer;font-size:.95em}
			.wsw-wallet-toggle input[type=checkbox]{margin:0;width:18px;height:18px}
			.wsw-wallet-applied-row{display:flex;justify-content:space-between;align-items:center;gap:12px}
			.wsw-wallet-discount{font-size:.95em;color:#2e7d32;font-weight:600}
			.wsw-wallet-remove{font-size:.85em;color:#b32d2e;text-decoration:none;white-space:nowrap}
			.wsw-wallet-remove:hover{text-decoration:underline;color:#8b0000}
		';

		wp_register_style( 'wsw-checkout', false ); // phpcs:ignore
		wp_enqueue_style( 'wsw-checkout' );
		wp_add_inline_style( 'wsw-checkout', $css );

		/* ---- JS ---- */
		$js = '
			(function($){
				function wswToggle(apply){
					$("#wsw-wallet-box").addClass("wsw-wallet-loading");
					$.post("' . esc_js( $ajax_url ) . '",{
						action:"wsw_toggle_wallet",
						apply:apply,
						nonce:"' . esc_js( $nonce ) . '"
					}).always(function(){
						$(document.body).trigger("update_checkout");
					});
				}
				$(document).on("change","#wsw-apply-wallet",function(){
					wswToggle($(this).is(":checked")?"1":"0");
				});
				$(document).on("click","#wsw-remove-wallet",function(e){
					e.preventDefault();
					wswToggle("0");
				});
			})(jQuery);
		';

		wp_register_script( 'wsw-checkout', false, array( 'jquery' ), WSW_VERSION, true ); // phpcs:ignore
		wp_enqueue_script( 'wsw-checkout' );
		wp_add_inline_script( 'wsw-checkout', $js );
	}

	/* ------------------------------------------------------------------
	 * AJAX
	 * ----------------------------------------------------------------*/

	/**
	 * Toggle wallet application on/off (AJAX).
	 */
	public function ajax_toggle() {
		check_ajax_referer( 'wsw_checkout', 'nonce' );

		$apply = isset( $_POST['apply'] ) && '1' === $_POST['apply'];

		if ( WC()->session ) {
			WC()->session->set( 'wsw_apply_wallet', $apply );
			if ( ! $apply ) {
				WC()->session->set( 'wsw_apply_amount', 0 );
			}
		}

		wp_send_json_success();
	}

	/* ------------------------------------------------------------------
	 * Cart fee
	 * ----------------------------------------------------------------*/

	/**
	 * Add a negative fee when the wallet checkbox is active.
	 *
	 * The fee is NOT taxable — it is a payment, not a discount. Product
	 * taxes (IVA) are unaffected; the fee simply reduces the gross total
	 * the customer has to pay via another method.
	 *
	 * @param WC_Cart $cart
	 */
	public function apply_wallet_fee( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}
		if ( ! WC()->session || ! WC()->session->get( 'wsw_apply_wallet' ) ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id || ! WSW_User::is_wallet_active( $user_id ) ) {
			WC()->session->set( 'wsw_apply_wallet', false );
			return;
		}

		$max_applicable = self::get_max_applicable( $user_id );
		$cart_total     = $this->get_pre_wallet_total( $cart );
		$apply          = min( $max_applicable, $cart_total );
		$apply          = round( $apply, wc_get_price_decimals() );

		if ( $apply <= 0 ) {
			WC()->session->set( 'wsw_apply_amount', 0 );
			return;
		}

		WC()->session->set( 'wsw_apply_amount', $apply );
		$cart->add_fee( __( 'Wallet balance', 'wp-simple-wallet' ), -$apply, false );
	}

	/* ------------------------------------------------------------------
	 * Order processing
	 * ----------------------------------------------------------------*/

	/**
	 * Validate wallet debit eligibility before the order is created.
	 *
	 * @param array    $data
	 * @param WP_Error $errors
	 */
	public function validate_wallet( $data, $errors ) {
		if ( ! WC()->session || ! WC()->session->get( 'wsw_apply_wallet' ) ) {
			return;
		}

		$user_id = get_current_user_id();
		$amount  = floatval( WC()->session->get( 'wsw_apply_amount', 0 ) );

		if ( $amount <= 0 ) {
			return;
		}

		if ( ! $user_id || ! WSW_User::is_wallet_active( $user_id ) ) {
			$errors->add( 'wsw_wallet', __( 'Wallet is not available for your account.', 'wp-simple-wallet' ) );
			WC()->session->set( 'wsw_apply_wallet', false );
			WC()->session->set( 'wsw_apply_amount', 0 );
			return;
		}

		$check = WSW_Wallet::can_debit( $user_id, $amount );
		if ( is_wp_error( $check ) ) {
			$errors->add( 'wsw_wallet', $check->get_error_message() );
			WC()->session->set( 'wsw_apply_wallet', false );
			WC()->session->set( 'wsw_apply_amount', 0 );
		}
	}

	/**
	 * Store the wallet intent in order meta right after the order is
	 * created, before the payment gateway runs.
	 *
	 * This survives session loss (PayPal redirect, off-site gateways).
	 *
	 * @param int      $order_id
	 * @param array    $posted_data
	 * @param WC_Order $order
	 */
	public function store_wallet_intent( $order_id, $posted_data, $order ) {
		if ( ! WC()->session || ! WC()->session->get( 'wsw_apply_wallet' ) ) {
			return;
		}

		$amount = floatval( WC()->session->get( 'wsw_apply_amount', 0 ) );
		if ( $amount <= 0 ) {
			return;
		}

		$order->update_meta_data( '_wsw_wallet_pending', $amount );
		$order->save();

		// Clear session so a second submit cannot double-debit.
		WC()->session->set( 'wsw_apply_wallet', false );
		WC()->session->set( 'wsw_apply_amount', 0 );
	}

	/**
	 * Debit the wallet after the payment succeeds.
	 *
	 * Hooked into woocommerce_payment_complete and order-status transitions.
	 * Uses _wsw_wallet_pending meta (set by store_wallet_intent) so it
	 * works even when the WC session is gone (PayPal IPN, async gateways).
	 *
	 * @param int $order_id
	 */
	public function process_wallet_debit( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$amount = floatval( $order->get_meta( '_wsw_wallet_pending' ) );
		if ( $amount <= 0 ) {
			return;
		}

		// Already processed.
		if ( $order->get_meta( '_wsw_wallet_amount' ) ) {
			return;
		}

		$user_id = $order->get_user_id();
		if ( ! $user_id ) {
			return;
		}

		$note = sprintf(
			/* translators: %d: order number */
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

		if ( ! is_wp_error( $tx ) ) {
			$order->update_meta_data( '_wsw_wallet_amount', $amount );
			$order->delete_meta_data( '_wsw_wallet_pending' );
			$order->save();
			$order->add_order_note(
				sprintf(
					/* translators: %s: formatted price */
					__( 'Wallet: %s applied from customer balance.', 'wp-simple-wallet' ),
					wc_price( $amount )
				)
			);
		} else {
			$order->add_order_note(
				sprintf(
					/* translators: %s: error message */
					__( 'Wallet debit failed: %s', 'wp-simple-wallet' ),
					$tx->get_error_message()
				)
			);
		}
	}

	/**
	 * Clear wallet session data (runs on thank-you page).
	 *
	 * @param int $order_id Unused.
	 */
	public function clear_session( $order_id = 0 ) {
		if ( WC()->session ) {
			WC()->session->set( 'wsw_apply_wallet', false );
			WC()->session->set( 'wsw_apply_amount', 0 );
		}
	}
}
