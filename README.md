# Snap Pay for WooCommerce

Source of the [Snap Pay for WooCommerce](https://wordpress.org/plugins/snap-pay-for-woocommerce/) plugin by Snap Pay Solutions, and the pipeline that publishes it to the WordPress.org plugin directory.

## Releasing a new version

1. Update the version in the plugin header and `SNAPPAY_VERSION` in `snap-pay-for-woocommerce.php`, and the `Stable tag` and changelog in `readme.txt`.
2. Publish a GitHub release whose tag is the new version number, for example `2.0.2`.
3. The **Deploy to WordPress.org** workflow checks that the tag matches the plugin version, then commits the code to SVN `trunk` and `tags/<version>`.

The workflow needs two repository secrets: `SVN_USERNAME` (`snap1`) and `SVN_PASSWORD`, the SVN password generated in the WordPress.org profile (not the account password).

Files listed with `export-ignore` in `.gitattributes` (this README and the `.github` folder) are not sent to WordPress.org.
