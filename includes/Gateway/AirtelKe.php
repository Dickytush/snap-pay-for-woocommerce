<?php
/**
 * Airtel Money Kenya corridor.
 *
 * @package SnapPay
 */

declare(strict_types=1);

namespace SnapPay\Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * Collects in KES from Kenyan customers via the platform's airtel_ke driver.
 */
final class AirtelKe extends AbstractGateway {

	/**
	 * Platform provider_id.
	 *
	 * @var string
	 */
	protected string $provider_id = 'airtel_ke';
}
