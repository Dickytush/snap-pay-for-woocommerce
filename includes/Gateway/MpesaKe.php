<?php
/**
 * M-Pesa Kenya corridor.
 *
 * @package SnapPay
 */

declare(strict_types=1);

namespace SnapPay\Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * Collects in KES from Kenyan customers via the platform's mpesa_ke driver.
 */
final class MpesaKe extends AbstractGateway {

	/**
	 * Platform provider_id.
	 *
	 * @var string
	 */
	protected string $provider_id = 'mpesa_ke';
}
