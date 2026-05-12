# WP Simple Wallet

Wallet balance for WooCommerce customers. Per-user activation, admin adjustments, transaction history, and a "Pay with wallet" gateway. HPOS compatible.

| | |
|---|---|
| **Slug** | `wp-simple-wallet` |
| **Version** | 1.1.0 |
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
- **"Pay with wallet"** WooCommerce payment gateway:
  - Only shown to logged-in users with an active wallet.
  - Debits the order total from the balance, respecting the overdraft setting.
  - Order refunds automatically credit the amount back to the wallet.
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
4. **Pay an order.** Add a product to cart and go to checkout. The *Pay with wallet* method should be available. Place the order; the balance should drop by the order total and a new transaction of type *Order payment* should appear, linked to the order.
5. **Refund.** In `wp-admin`, open the order and issue a refund (any amount). The amount should be credited back to the wallet automatically. Check the customer's *Wallet* tab and the admin transactions list.
6. **Negative balance.** In **Settings**, enable *Allow negative balance* and set a *Max negative balance* (e.g. 50). Place an order that exceeds the balance by less than 50: it should succeed; one that exceeds by more should be rejected.
7. **HPOS.** Enable *WooCommerce → Settings → Advanced → Features → High-Performance Order Storage* and repeat steps 4–5. Orders are stored in the HPOS tables; wallet flow keeps working.
8. **Export.** From **WooCommerce → Wallets → Transactions** click *Export CSV*.

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

## Changelog

### 1.1.0
- New procedural API for other plugins: `wsw_credit()`, `wsw_debit()`, `wsw_can_debit()`, `wsw_get_balance()`, `wsw_get_transactions()`, `wsw_is_active()`, `wsw_set_active()`, `wsw_get_settings()`.
- New `source` column on the transactions table to track which plugin originated each movement (shown in the admin list and CSV export).
- New filters: `wsw_can_debit`, `wsw_transaction_type_label`. New `args` payload on `wsw_balance_changed`.
- Custom transaction types supported (up to 64 chars). DB upgrade runs automatically on plugin load.

### 1.0.0
- Initial release.
