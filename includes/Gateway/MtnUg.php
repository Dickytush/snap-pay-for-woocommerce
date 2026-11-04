<?php
/**
 * MTN Mobile Money Uganda corridor.
 *
 * @package SnapPay
 */

declare(strict_types=1);

namespace SnapPay\Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * Collects in UGX from Ugandan customers via the platform's mtn_ug driver.
 */
final class MtnUg extends AbstractGateway {

	/**
	 * Platform provider_id.
	 *
	 * @var string
	 */
	protected string $provider_id = 'mtn_ug';
}
