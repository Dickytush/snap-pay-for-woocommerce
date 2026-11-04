=== Snap Pay for WooCommerce ===
Contributors: snap1
Tags: woocommerce, payment gateway, mpesa, mobile money, escrow
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 2.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Mobile money payments for Kenya and Uganda, held in escrow until the buyer confirms delivery with an 8-character code.

== Description ==

Snap Pay is the payments brand of Snap Pay Solutions, Nairobi, which operates the Snap Pay platform at snapcashpay.com and publishes this plugin.

Snap Pay lets your customers pay by M-Pesa, Airtel Money or MTN Mobile Money. The money is not paid straight out to you. Snap Pay holds it, emails the buyer an 8-character delivery code, and releases the funds to your Snap Pay wallet once you enter that code on the order.

That is the point of it. The buyer knows they are not sending money into the dark, and you get paid for a delivery that actually landed.

**How an order runs**

1. The customer picks Snap Pay at checkout and approves the prompt on their phone.
2. The order goes to Processing. The money is held, not settled — nothing is paid out yet.
3. Snap Pay emails the buyer a code. It also appears on the WooCommerce order email, so one spam filter cannot strand the order.
4. You ship. The buyer receives the goods and gives you the code.
5. You enter it in the Snap Pay panel on the order. The order completes and the funds land in your Snap Pay wallet, less the fee and a 10% reserve held for 30 days.
6. You withdraw from the Snap Pay dashboard.

Codes last 14 days. Five wrong entries locks a code for an hour. If a buyer loses their code, Snap Pay support can release the funds after checking with them — there is no way to release money on a timer, by filter, or by any setting in this plugin.

**Selling across the border**

A Ugandan buyer on a Kenyan store sees the exact shilling amount they will be charged, with the exchange rate locked for 15 minutes before they pay. You still settle in your own currency at the rate quoted at checkout. Snap Pay carries the currency movement, not you and not the buyer.

**What this plugin is**

A thin adapter. It renders checkout, mirrors order state, and talks to the Snap Pay platform. Escrow, the ledger, fees, reserves, exchange rates, provider connections and payouts all live on the platform. The plugin holds no provider credentials and never contacts Safaricom, Airtel or MTN.

= External service =

This plugin does not work on its own. It requires an account with **Snap Pay** (Snap Pay Solutions, snapaysolutions.com) and sends data to the Snap Pay platform API, operated by the same business at snapcashpay.com, in order to take payments.

**Service:** Snap Pay platform API, `https://api.snapcashpay.com` (sandbox: `https://sandbox-api.snapcashpay.com`). Your site may be configured to use a different Snap Pay endpoint if support has given you one.

**When data is sent:**

* When a customer places an order using a Snap Pay payment method.
* When a cross-border customer reaches checkout, to quote and lock an exchange rate.
* When you enter a delivery code to release funds.
* When the plugin checks the status of an order, or your account balance.

**What is sent:** the order number and order key, the amount and currency, the customer's name, email address and mobile money number, the customer's billing country, the payment method chosen, and your store URL. Nothing else from the order is transmitted — no addresses, no cart contents, no IP addresses.

**What is received:** the transaction status, the fee and reserve amounts, the exchange rate, and the delivery code so it can be added to your order email.

**Webhook endpoint:** the plugin registers `POST /wp-json/snappay/v1/webhook` on your site so the Snap Pay platform can report payment events. The route is public by design (the platform has no WordPress login); every request is rejected unless its HMAC-SHA256 signature, timestamp and event id check out.

**What is stored on your site:** the Snap Pay transaction reference, the payment state, the fee/reserve/settlement figures, and whether a delivery code has been issued and when it expires. **The delivery code itself is never stored on your site or written to any log.**

Terms of service: https://snapcashpay.com/terms
Privacy policy: https://snapcashpay.com/privacy

By installing this plugin and connecting an account you agree to those terms.

= Trademarks =

M-Pesa, Airtel Money, MTN and MoMo are trademarks of their respective owners. Their use here refers to the payment methods supported and does not imply endorsement, partnership or official status. Snap Pay is not affiliated with Safaricom, Airtel or MTN.

WooCommerce is a trademark of Automattic Inc. This plugin is not affiliated with or endorsed by Automattic.

== Installation ==

1. Sign up at snapcashpay.com, complete KYC, and register your store under Channels, E-commerce, WooCommerce.
2. Generate credentials. They are shown once.
3. Upload and activate this plugin.
4. Go to WooCommerce, Snap Pay. Choose Test, paste your `sk_test_` API key and webhook secret, tick the payment methods Snap Pay has activated for you, and save.
5. Copy the webhook URL shown on that screen into your Snap Pay dashboard.
6. Run one full test order end to end. In test mode the delivery code is always TEST0000.
7. Switch to Live and paste your `sk_live_` key.

Payment methods stay hidden at checkout until the connection validates. That is deliberate — a half-configured store cannot take live payments.

== Frequently Asked Questions ==

= Why is my order stuck on Processing? =

Processing means the money is held and the delivery code has not been entered yet. That is the normal state between payment and delivery. The order completes when you enter the buyer's code.

= The buyer never got their code. =

Contact Snap Pay support. They will verify the buyer and issue a fresh code, or release the funds directly if delivery can be shown. You cannot resend a code from WooCommerce.

= Can I refund from WooCommerce? =

No. Refunds go through Snap Pay support so the reserve and fee are unwound correctly. The order carries the transaction reference support will need.

= Do I need a multi-currency plugin for cross-border sales? =

No. Your store stays in one currency. Snap Pay converts for the buyer and settles you in yours.

= Where do I withdraw my money? =

From the Snap Pay dashboard. This plugin shows your balance but never moves money.

= It says it cannot reach the Snap Pay API. =

Your site could not open a connection to the Snap Pay endpoint. Check the API base URL shown on the Snap Pay settings screen — it displays the address actually in use. If Snap Pay support gave you a different address, enter it there. If your host blocks outgoing connections, they will need to allow requests to the Snap Pay domain.

= Can I lock the API address so nobody changes it? =

Yes. Define it in `wp-config.php` and the settings field becomes read-only:

`define( 'SNAPPAY_API_BASE_URL', 'https://api.snapcashpay.com/api/v1/' );`

== Screenshots ==

1. Mobile money at checkout. Only the methods that serve the customer's billing country are offered — a Ugandan shopper sees MTN MoMo and Airtel Money, a Kenyan one sees M-Pesa and Airtel.
2. The escrow panel on the order screen. The money is held, the amount is shown, and the order cannot be completed until the buyer's 8-character code is entered.
3. The same panel after release, showing exactly where the money went: gross, fee, the 30-day reserve, and what is available to the merchant.
4. The connection screen, showing test/live mode, which corridors are enabled, and the webhook URL to register.

== Changelog ==

= 2.0.1 =
* WordPress.org release: plugin folder and text domain aligned to snap-pay-for-woocommerce, exception messages escaped, readme documents the webhook endpoint.

= 2.0.0 =
* Rebuilt as a thin adapter over the Snap Pay platform API.
* Payments are now held in escrow and released by delivery code. Orders go to Processing on payment and Complete on delivery confirmation.
* Added cross-border local-currency checkout with a 15-minute rate lock.
* Added signed webhooks, an hourly reconciler for missed events, and HPOS and Blocks support.
* Credentials must be re-entered after upgrading. Checkout stays off until the connection validates.

== Upgrade Notice ==

= 2.0.0 =
Major change: payments are now held in escrow and released only when you enter the buyer's delivery code. You must re-enter your API credentials after upgrading — Snap Pay stays hidden at checkout until you do. Orders taken on version 1 must be resolved with Snap Pay support.
