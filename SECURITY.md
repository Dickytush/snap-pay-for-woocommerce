# Security policy

Snap Pay for WooCommerce takes part in real payments, so security reports are handled privately and taken seriously.

## Reporting a vulnerability

**Please do not open a public issue, discussion or pull request for a vulnerability.**

Report it privately through GitHub: open the repository's **Security** tab and choose **Report a vulnerability**, or go straight to [the private report form](https://github.com/Dickytush/snap-pay-for-woocommerce/security/advisories/new).

Please include the plugin version, the WordPress, WooCommerce and PHP versions, a description of the problem and its impact, and the steps to reproduce it on a test store. Do not include real API keys, webhook secrets, delivery codes or customers' personal data.

We aim to acknowledge reports within a few working days, keep you informed while a fix is prepared, and credit you in the changelog unless you would rather stay anonymous. Please give us reasonable time to release a fix before disclosing the issue.

## Scope

In scope: this plugin's checkout, the webhook route `/wp-json/snappay/v1/webhook`, the escrow panel and delivery-code entry, and how credentials are stored and logged.

Out of scope: the Snap Pay platform itself (report those to Snap Pay through your merchant account), problems that need an already compromised administrator account, and issues in WordPress, WooCommerce or other plugins (report those to their maintainers).
