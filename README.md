# WP Simple Wallet

Wallet balance for WooCommerce customers. Per-user activation, admin adjustments, transaction history, and checkout wallet balance. HPOS compatible.

| | |
|---|---|
| **Slug** | `wp-simple-wallet` |
| **Version** | 1.4.1 |
| **Author** | IDEAA Lab |
| **Requires WP** | 6.0+ |
| **Requires PHP** | 7.4+ |
| **WC tested up to** | 9.4 |
| **Text domain** | `wp-simple-wallet` |
| **License** | GPL-2.0-or-later |

## Features

- Per-customer wallet balance in the store currency.
- Wallet is **off by default**. Enable it per user via either:
  - the dedicated **"Wallet Customer"** role created by the plugin, or
  - a checkbox on the user's profile in `wp-admin`.
- Negative balance support (configurable: disabled, unlimited, or capped at a maximum amount).
- Admin tools under **WooCommerce → Wallets**:
  - List users with wallet enabled and their current balance.
  - Adjust balance manually (credit or debit) with a free-form note.
  - Browse the full transaction history and **export it to CSV**.
- Frontend **Wallet** tab in *My Account* showing the customer's balance and movements (only visible if the wallet is active).
- **Wallet balance at checkout** (gift-card style box):
  - Appears above payment methods when the customer has wallet balance (or overdraft is allowed).
  - Checkbox applies the full available balance as a discount. If it covers the entire order, no other payment method is needed.
  - If the balance only covers part of the order, the remaining total is charged to whatever payment method the customer selects (Stripe, PayPal, etc.).
  - Order cancellations automatically restore the wallet amount. Refunds on full-wallet orders are credited back automatically.
  - Respects the per-user overdraft policy: customers with allowed negative balance can apply more than their current balance.
- HPOS (Custom Order Tables) compatible.
- OOP, internationalized (`.pot` provided), no data deleted on deactivation. Cleanup on uninstall is opt-in.

## Installation

1. Clone or upload the plugin folder into `wp-content/plugins/wp-simple-wallet`:
   ```bash
   cd wp-content/plugins
   git clone https://github.com/ideaalab/wp-simple-wallet.git
   ```
   Or download a release ZIP and upload it from **Plugins → Add new → Upload**.
2. Activate **WP Simple Wallet** from the Plugins screen. WooCommerce must be active.
3. Go to **WooCommerce → Wallets → Settings** to configure overdraft policy and the gateway labels.
4. Enable the wallet for a user:
   - assign them the **Wallet Customer** role, **or**
   - edit the user profile and tick *Enable wallet for this user*.

## Updates

The plugin self-updates from this GitHub repository via the bundled [plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker). New tagged releases (`vX.Y.Z`) appear in the regular WordPress updates screen.

## How to test

Quick functional walkthrough on a Woo store with at least one product:

1. **Enable a wallet.** Edit a customer in `wp-admin`, scroll to *WP Simple Wallet* and tick *Enable wallet for this user*. Save.
2. **Top up the balance.** Go to **WooCommerce → Wallets**, click *Manage* on that user, add a credit of e.g. 100 with note "initial top-up".
3. **Verify the customer view.** Log in as the customer and visit *My account → Wallet*. You should see the balance and the credit movement.
4. **Full wallet payment.** Add a product to cart (price ≤ 100) and go to checkout. A *Wallet balance* box should appear with a checkbox. Check it; the order total should drop to zero and payment methods should disappear. Place the order; the balance should drop and a new *Order payment* transaction should appear.
5. **Split payment.** Add a product that costs more than the wallet balance. Check the wallet box; the total should drop by the balance amount. Select another payment method for the remainder and place the order. Both the wallet debit and the gateway charge should succeed.
6. **Refund (full wallet).** Open a full-wallet order in `wp-admin` and issue a refund. The wallet should be credited back automatically.
7. **Cancel.** Place an order using the wallet, then change the order status to *Cancelled* in `wp-admin`. The wallet amount should be restored automatically.
8. **Negative balance.** In **Settings**, enable *Allow negative balance* and set a *Max negative balance* (e.g. 50). Set the customer balance to 0. Go to checkout; the wallet box should appear. Check it; the full order total should be applied from wallet. Place an order that exceeds the cap: it should be rejected at validation.
9. **HPOS.** Enable *WooCommerce → Settings → Advanced → Features → High-Performance Order Storage* and repeat steps 4–6. Orders are stored in the HPOS tables; wallet flow keeps working.
10. **Export.** From **WooCommerce → Wallets → Transactions** click *Export CSV*.

## Developer API (for other plugins)

WP Simple Wallet exposes a small procedural API so other plugins (royalty
systems, top-ups, point conversions, ticketing, etc.) can move money in and
out of customer wallets in one line.

All API functions are loaded when the plugin boots. Guard your calls with
`function_exists()` so your code keeps working if the wallet is disabled:

```php
if ( ! function_exists( 'wsw_credit' ) ) {
    return; // WP Simple Wallet is not active.
}
```

### Functions

| Function | Returns |
|---|---|
| `wsw_is_active( $user_id )` | `bool` |
| `wsw_set_active( $user_id, $active = true )` | `void` |
| `wsw_get_balance( $user_id )` | `float` |
| `wsw_credit( $user_id, $amount, $note = '', $args = [] )` | `int\|WP_Error` (tx id) |
| `wsw_debit( $user_id, $amount, $note = '', $args = [] )` | `int\|WP_Error` (tx id) |
| `wsw_can_debit( $user_id, $amount, $args = [] )` | `true\|WP_Error` |
| `wsw_get_transactions( $args = [] )` | `array` of rows |
| `wsw_get_settings()` | `array` |

`$args` supports:

| Key | Type | Notes |
|---|---|---|
| `type` | string | Custom transaction type slug (max 64 chars). Default: `credit` / `debit`. Use stable identifiers like `royalty_payout`, `relay_topup`. |
| `source` | string | Slug of the calling plugin, e.g. `wp-royalties`. Stored in a dedicated column and shown in the admin transactions table. |
| `order_id` | int | Related WooCommerce order, if any. |
| `created_by` | int | User to record as the author. Defaults to current user. |
| `force` | bool | Bypass the "max negative balance" cap on debits. Use sparingly. |

### Example: pay royalties into the wallet from `wp-royalties`

```php
add_action( 'wpr_royalty_due', function ( $user_id, $amount, $period ) {
    if ( ! function_exists( 'wsw_credit' ) || ! wsw_is_active( $user_id ) ) {
        return;
    }

    $tx = wsw_credit(
        $user_id,
        $amount,
        sprintf( 'Royalties for %s', $period ),
        array(
            'type'   => 'royalty_payout',
            'source' => 'wp-royalties',
        )
    );

    if ( is_wp_error( $tx ) ) {
        error_log( 'Royalty credit failed: ' . $tx->get_error_message() );
    }
}, 10, 3 );
```

### Example: charge a Relay extra against the wallet

```php
$result = wsw_debit(
    $user_id,
    9.90,
    'Relay Express upgrade (order #1234)',
    array(
        'type'     => 'relay_extra',
        'source'   => 'wp-relay-extras',
        'order_id' => 1234,
    )
);

if ( is_wp_error( $result ) ) {
    // Show the error to the customer: insufficient balance, overdraft cap, etc.
    wc_add_notice( $result->get_error_message(), 'error' );
}
```

### Hooks

- `do_action( 'wsw_balance_changed', $user_id, $delta, $balance_after, $type, $tx_id, $args )` — fires after every balance change.
- `apply_filters( 'wsw_can_debit', $result, $user_id, $amount, $args )` — let another plugin veto or approve a debit.
- `apply_filters( 'wsw_transaction_type_label', $label, $type )` — provide human labels for your custom types.

## Security model

The wallet API is **PHP-level only** — there are no HTTP, REST or AJAX endpoints that allow third parties to credit or debit balances. A remote attacker cannot call `wsw_credit()` or `wsw_debit()` over the network. Server-side, the surface is:

- **Admin UI**: every action checks `current_user_can( 'manage_woocommerce' )` plus a WordPress nonce. This includes balance adjustments, CSV export, enabling/removing wallets, the user-search AJAX endpoint, and the user profile checkbox.
- **Checkout**: the wallet toggle AJAX checks a nonce. The wallet debit is validated before order creation (`woocommerce_after_checkout_validation`) and only executed after payment succeeds (`woocommerce_payment_complete`). The intent is stored in order meta so off-site gateways (PayPal, 3-D Secure) cannot be exploited by session manipulation.
- **Refunds**: credited automatically from the `woocommerce_order_refunded` action (full-wallet orders only), with double-credit protection via order meta. Order cancellations also restore the wallet amount.
- **Customer-facing pages**: read-only. The "Wallet" tab in *My Account* never accepts input that changes the balance.

### What about other plugins?

Any PHP code running on your site (themes, plugins, mu-plugins, snippets, scheduled tasks) can call the API. This is the same trust boundary that already applies to **every** WordPress plugin: a malicious plugin can access the database, alter orders, exfiltrate customer data, or empty Stripe — the wallet API doesn't widen this surface, it just exposes a *clean* way to do something a malicious plugin could already do by writing to `usermeta` directly. The defence is the same as for the rest of your stack:

- Only install plugins you trust.
- Keep an audit log: every wallet movement is recorded with a `source` slug (the calling plugin's identifier), the order it was tied to, the user who triggered it, and a free-form note. Review the *Transactions* tab periodically.
- Reconcile balances: the sum of all transactions for a user should equal their `_wsw_balance`. A drift indicates code wrote to the meta without going through the API.
- Wire the `wsw_can_debit` filter to a fraud-detection plugin if you want to veto suspicious patterns (e.g. amount > threshold, source not in your allowlist).

### Concurrency

`wsw_debit()` reads the balance, checks the rule, writes the new balance and inserts the transaction. There is **no row-level lock**, so two simultaneous debits on the same user (the same millisecond) could both pass the eligibility check and produce a small overdraft. For typical e-commerce volume this is theoretical; for high-throughput integrations (mass payout scripts, automated billing) put your work behind a queue or wrap calls in a `LOCK TABLES`/`SELECT ... FOR UPDATE` flow.

### Hardening checklist

- Disable file editing in `wp-config.php`: `define( 'DISALLOW_FILE_EDIT', true );`
- Restrict who has `manage_woocommerce` to the people who actually need it.
- 2FA on admin accounts.
- Keep WP, WooCommerce, and this plugin on the latest version (the bundled update-checker pulls from GitHub).

## Changelog

### 1.4.1
- **Fix**: the wallet box now stays in sync during WooCommerce AJAX checkout refreshes. Previously, checking or unchecking the wallet had no visible effect after the first interaction because the checkout template's `woocommerce_review_order_before_payment` hook does not fire during AJAX updates. The box is now delivered as a custom WC fragment (`woocommerce_update_order_review_fragments`), so it re-renders on every `update_checkout` cycle.
- **UX**: when the wallet discount is active, the box shows the applied amount and an explicit **Remove** link instead of a checked checkbox, making it obvious how to undo the discount.

### 1.4.0
- **Breaking**: the "Pay with wallet" WooCommerce payment gateway has been **replaced** by a gift-card-style wallet box that appears above payment methods at checkout. Customers check a box to apply their wallet balance; the remaining total (if any) is paid with any other gateway (Stripe, PayPal, bank transfer, etc.). If the wallet covers the full order, no payment method is needed.
- **Split payments**: customers can now use *part* of their wallet balance and pay the rest with a different method — the main feature gap the old gateway had.
- **Overdraft at checkout**: customers with allowed negative balance see the wallet box even when their balance is zero or negative, and can apply credit up to their overdraft limit.
- **Order cancellation**: wallet amounts are automatically restored when an order is cancelled.
- **Validation**: the wallet amount is re-validated right before the order is created, preventing race conditions (e.g. balance changed between page load and submit).
- **Async-gateway safe**: wallet intent is stored in order meta before payment, so off-site gateways (PayPal redirect, Stripe 3-D Secure) work correctly even when the WC session is lost.
- **Refund policy**: for full-wallet orders (no gateway charged) refunds are auto-credited. For split orders, the gateway handles its refund; the admin adjusts the wallet portion manually if needed.
- Removed: gateway title/description settings (no longer applicable).

### 1.3.3
- **Admin UX**: separate settings from actions on the user detail page. Overdraft limits sit at the top as a regular settings form (saved with *Save limits*); the balance adjustment is now wrapped in a visually distinct action card with its own *Apply adjustment* button. The 1.3.2 unified form was confusing because an adjustment is a one-shot transaction, not a setting.

### 1.3.2
- **Admin UX**: the user detail page now has a single *Save changes* button that persists overdraft limits and applies the optional balance adjustment in one shot. Limits always save; the adjustment is skipped if the amount field is left empty, and reported separately if it fails.

### 1.3.1
- **Checkout**: the balance is no longer appended to the gateway title. Instead, the gateway description supports a `{balance}` placeholder that is replaced with the customer's current wallet balance. Default description: *"Use your wallet balance to pay for this order. Available: {balance}"*. The hint is shown next to both the plugin's *Gateway description* setting and WooCommerce's own gateway *Description* field.

### 1.3.0
- **Checkout**: the "Pay with wallet" payment method now shows the customer's current balance in parentheses, e.g. *Pay with wallet (€42.30)*.
- **Per-user overdraft limits**: each customer can now have their own *Allow negative balance* and *Max negative balance* values from their wallet detail page in `WooCommerce → Wallets`. Empty fields fall back to the store defaults. Effective values and their source ("user" vs "default") are shown inline.

### 1.2.2
- **Fix**: the Wallet menu icon no longer fights theme styling. Only `font-family` and `content` are emitted on `.woocommerce-MyAccount-navigation-link--wallet > a::before`, so margins/alignment match the rest of the menu items.

### 1.2.1
- **Fix**: 404 on `/my-account/wallet/` after plugin upgrades. Rewrite rules are now flushed automatically once per version.
- New setting: **My Account menu position** — choose where the Wallet link appears (First, after Dashboard/Orders/Downloads/Addresses/Payment methods/Account details, or Last).
- New setting: **Show menu icon** — adds a Dashicons glyph next to the Wallet label as a generic fallback for themes that don't style our custom endpoint. The glyph (default `\f18e`, the "money" icon) is configurable. Filter `wsw_account_menu_icon_css` lets themes override the entire CSS.

### 1.2.0
- **My Account**: redesigned wallet tab with a prominent balance card and a styled, responsive movements table.
- **Admin**: new *Create wallet for user* modal with live user search (AJAX, by name/login/email).
- **Admin**: new *Remove* button per row that disables the wallet and downgrades the *Wallet Customer* role to *Customer*; balance and history are preserved.
- New `Security model` section in the README.

### 1.1.0
- New procedural API for other plugins: `wsw_credit()`, `wsw_debit()`, `wsw_can_debit()`, `wsw_get_balance()`, `wsw_get_transactions()`, `wsw_is_active()`, `wsw_set_active()`, `wsw_get_settings()`.
- New `source` column on the transactions table to track which plugin originated each movement (shown in the admin list and CSV export).
- New filters: `wsw_can_debit`, `wsw_transaction_type_label`. New `args` payload on `wsw_balance_changed`.
- Custom transaction types supported (up to 64 chars). DB upgrade runs automatically on plugin load.

### 1.0.0
- Initial release.
