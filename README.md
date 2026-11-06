# Snap Pay for WooCommerce

**Mobile money payments for WooCommerce stores in Kenya and Uganda, held safely until the buyer confirms delivery.** Customers pay with M-Pesa, Airtel Money or MTN MoMo. Snap Pay holds the money and releases it to you when the buyer hands over an 8-character delivery code.

[![WordPress.org](https://img.shields.io/wordpress/plugin/v/snap-pay-for-woocommerce?label=WordPress.org)](https://wordpress.org/plugins/snap-pay-for-woocommerce/)
[![License: GPL v2 or later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](license.txt)
![WordPress 6.5+](https://img.shields.io/badge/WordPress-6.5%2B-21759b.svg)
![WooCommerce 8.2+](https://img.shields.io/badge/WooCommerce-8.2%2B-7f54b3.svg)
![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-777bb4.svg)

Published by [Snap Pay Solutions](https://snapaysolutions.com), Nairobi, which runs the Snap Pay platform at [snapcashpay.com](https://snapcashpay.com). The plugin needs a Snap Pay merchant account.

## Payment methods

| Store customers in | Currency | Methods offered at checkout |
| --- | --- | --- |
| Kenya | KES | M-Pesa, Airtel Money |
| Uganda | UGX | MTN MoMo, Airtel Money |

Each customer only sees the methods that serve their billing country.

## How an order works

1. The customer picks Snap Pay at checkout and approves the payment prompt on their phone.
2. The order moves to **Processing**. The money is held by Snap Pay; nothing is paid out yet.
3. Snap Pay emails the buyer an 8-character delivery code. The code is also added to the WooCommerce order email.
4. You deliver. The buyer receives the goods and gives you the code.
5. You enter the code in the Snap Pay panel on the order. The order completes and the money goes to your Snap Pay wallet, less the fee and a 10% reserve held for 30 days.
6. You withdraw from your Snap Pay dashboard.

Codes last 14 days. If a buyer loses their code, Snap Pay support can release the funds after checking with them.

**Selling across the border:** a Ugandan buyer on a Kenyan store (or the other way round) sees the exact amount in their own currency, with the exchange rate locked for 15 minutes. You are still paid in your store's currency at the rate quoted at checkout. You don't need a multi-currency plugin.

## Get started

**1. Open a Snap Pay merchant account.** Sign up at [snapcashpay.com](https://snapcashpay.com), complete the verification (KYC), and register your store under **Channels → E-commerce → WooCommerce**. Generate your API credentials; they are shown only once, so keep them safe.

**2. Install the plugin.** Either way gives you the same plugin:

- **From WordPress (recommended, with automatic updates):** in your WordPress admin go to **Plugins → Add New**, search for **Snap Pay for WooCommerce**, then click **Install Now** and **Activate**.
- **From GitHub:** download **`snap-pay-for-woocommerce.zip`** from the [latest release](https://github.com/Dickytush/snap-pay-for-woocommerce/releases/latest), then go to **Plugins → Add New → Upload Plugin**. Use that file, not GitHub's "Source code" downloads, whose folder name WordPress would not recognise for updates.

**3. Connect your store.**

1. Go to **WooCommerce → Snap Pay** and choose **Test**.
2. Paste your `sk_test_` API key and webhook secret, tick the payment methods Snap Pay has activated for you, and save.
3. Copy the webhook URL shown on that screen into your Snap Pay dashboard.
4. Place one full test order. In test mode the delivery code is always `TEST0000`.
5. Switch to **Live** and paste your `sk_live_` key.

Snap Pay stays hidden at checkout until the connection checks out, so a half-configured store never takes live payments.

## Requirements

- WordPress 6.5 or later and WooCommerce 8.2 or later (block or classic checkout; HPOS compatible)
- PHP 8.1 or later
- A site the Snap Pay platform can reach, so payment updates arrive through the webhook

## Questions

- **Why is my order on Processing?** The money is held and the delivery code hasn't been entered yet. That is normal between payment and delivery.
- **The buyer never got their code.** Contact Snap Pay support. They will check with the buyer and issue a new code, or release the funds if delivery can be shown.
- **Can I refund from WooCommerce?** No. Refunds go through Snap Pay support so the fee and reserve are reversed correctly. The order shows the transaction reference they need.
- **Where do I withdraw my money?** From your Snap Pay dashboard. The plugin shows your balance but never moves money.

More answers are in the [WordPress.org FAQ](https://wordpress.org/plugins/snap-pay-for-woocommerce/#faq).

## Help

- **Your account, payouts, refunds or a lost code:** Snap Pay support, through your [snapcashpay.com](https://snapcashpay.com) account.
- **A problem with the plugin itself:** the [WordPress.org support forum](https://wordpress.org/support/plugin/snap-pay-for-woocommerce/) or a [GitHub issue](https://github.com/Dickytush/snap-pay-for-woocommerce/issues). Never post API keys, webhook secrets or customers' details.
- **A security problem:** report it privately as described in [SECURITY.md](SECURITY.md).

## Your data

The plugin sends Snap Pay only what a payment needs: the order number and key, the amount and currency, the customer's name, email address, mobile money number and billing country, the payment method and your store URL. No addresses, cart contents or IP addresses. The delivery code is never stored on your site or written to a log. Read the [terms of service](https://snapcashpay.com/terms) and [privacy policy](https://snapcashpay.com/privacy).

## For developers

The plugin is a thin adapter: it renders checkout, mirrors order state and talks to the Snap Pay platform API. Escrow, the ledger, fees, reserves, exchange rates, mobile-money provider connections and payouts all live on the platform, so the plugin holds no provider credentials and never contacts Safaricom, Airtel or MTN directly.

| Path | What it is |
| --- | --- |
| `snap-pay-for-woocommerce.php` | Plugin header, constants and autoloader |
| `includes/Gateway/` | One WooCommerce gateway per corridor: `MpesaKe`, `AirtelKe`, `AirtelUg`, `MtnUg` |
| `includes/PlatformApiClient.php` | Calls to the Snap Pay platform API |
| `includes/WebhookController.php` | `POST /wp-json/snappay/v1/webhook`, checked by HMAC-SHA256 signature, timestamp and event id |
| `includes/Admin/` | The **WooCommerce → Snap Pay** screen and the escrow panel on orders |
| `includes/Blocks/` | Block checkout integration |
| `includes/Cron/PollJob.php` | Hourly reconciler for missed webhook events |

Bug reports and pull requests are welcome. Please keep each pull request to one topic and say how you tested it.

### Releasing (maintainers)

1. Update the version in the plugin header and `SNAPPAY_VERSION` in `snap-pay-for-woocommerce.php`, and the `Stable tag` and changelog in `readme.txt`.
2. Publish a GitHub release whose tag is the new version number, for example `2.0.2`.
3. The **Deploy to WordPress.org** workflow checks that the tag matches the plugin version, commits the code to SVN `trunk` and `tags/<version>`, and attaches `snap-pay-for-woocommerce.zip` to the release.

The workflow needs two repository secrets: `SVN_USERNAME` and `SVN_PASSWORD` (the SVN password generated in the WordPress.org profile, not the account password). Files marked `export-ignore` in `.gitattributes` (this README, `SECURITY.md` and the `.github` folder) are not sent to WordPress.org.

## Licence and trademarks

Snap Pay for WooCommerce is free software released under the [GNU General Public License v2.0 or later](license.txt).

M-Pesa, Airtel Money, MTN and MoMo are trademarks of their respective owners. They are named only to identify the payment methods supported; Snap Pay is not affiliated with Safaricom, Airtel or MTN. WooCommerce is a trademark of Automattic Inc.
