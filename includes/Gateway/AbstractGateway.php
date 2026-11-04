<?php
/**
 * Base payment gateway for every Snap Pay corridor.
 *
 * @package SnapPay
 */

declare(strict_types=1);

namespace SnapPay\Gateway;

use SnapPay\Fx\Display as FxDisplay;
use SnapPay\Http\ApiException;
use SnapPay\OrderRepository;
use SnapPay\Plugin;
use SnapPay\Support\Countries;
use SnapPay\Support\Logger;
use SnapPay\Support\Money;
use WC_Order;
use WC_Payment_Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * One gateway per provider corridor (mpesa_ke, airtel_ke, mtn_ug, airtel_ug).
 *
 * process_payment() builds the collect request and hands it to the platform. It never
 * marks the order paid: on a 202 the money has not even left the customer's handset yet,
 * and even after SUCCEEDED the funds are held, not settled (AGENTS.md F3).
 */
abstract class AbstractGateway extends WC_Payment_Gateway {

	/**
	 * Platform provider_id this gateway maps to.
	 *
	 * @var string
	 */
	protected string $provider_id = '';

	/**
	 * Shared plugin instance.
	 *
	 * @var Plugin
	 */
	protected Plugin $plugin;

	/**
	 * Sets up WooCommerce gateway plumbing.
	 */
	public function __construct() {
		$this->plugin = Plugin::instance();

		$definition = Countries::provider( $this->provider_id );
		$label      = null !== $definition ? $definition['label'] : 'Snap Pay';
		$country    = null !== $definition ? $definition['country'] : '';

		$this->id                 = null !== $definition ? $definition['gateway_id'] : 'snappay_' . $this->provider_id;
		$this->has_fields         = true;
		$this->method_title       = sprintf( 'Snap Pay - %s (%s)', $label, $country );
		$this->method_description = __( 'Mobile money payment held in escrow by Snap Pay until the customer confirms delivery with an 8-character code. Configure credentials under WooCommerce > Snap Pay.', 'snap-pay-for-woocommerce' );
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title', sprintf( '%s (%s)', $label, $country ) );
		$this->description = $this->get_option( 'description', __( 'Pay by mobile money. Your money is held safely by Snap Pay until you confirm delivery.', 'snap-pay-for-woocommerce' ) );
		$this->enabled     = $this->get_option( 'enabled', 'yes' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Per-gateway settings. Credentials live in the central Snap Pay settings screen,
	 * not here, so a store cannot end up with four divergent key copies.
	 */
	public function init_form_fields(): void {
		$this->form_fields = array(
			'enabled'     => array(
				'title'   => __( 'Enable/Disable', 'snap-pay-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable this payment method', 'snap-pay-for-woocommerce' ),
				'default' => 'yes',
			),
			'title'       => array(
				'title'       => __( 'Title', 'snap-pay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'What the customer sees at checkout.', 'snap-pay-for-woocommerce' ),
				'default'     => $this->method_title,
				'desc_tip'    => true,
			),
			'description' => array(
				'title'       => __( 'Description', 'snap-pay-for-woocommerce' ),
				'type'        => 'textarea',
				'default'     => __( 'Pay by mobile money. Your money is held safely by Snap Pay until you confirm delivery.', 'snap-pay-for-woocommerce' ),
			),
		);
	}

	/**
	 * The platform provider_id.
	 */
	public function provider_id(): string {
		return $this->provider_id;
	}

	/**
	 * The provider's logo, shown beside the method name at checkout.
	 *
	 * Height is capped in CSS rather than the markup so a provider whose mark has a
	 * different aspect ratio still lines up with the others.
	 */
	public function get_icon(): string {
		$url = Countries::icon_url( $this->provider_id );

		if ( '' === $url ) {
			return (string) apply_filters( 'woocommerce_gateway_icon', '', $this->id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core filter.
		}

		$icon = sprintf(
			'<img src="%s" alt="%s" class="snappay-gateway-icon" />',
			esc_url( $url ),
			esc_attr( $this->get_title() )
		);

		return (string) apply_filters( 'woocommerce_gateway_icon', $icon, $this->id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core filter.
	}

	/**
	 * Availability rules.
	 *
	 * Fails closed: no credentials, an unvalidated connection, a key/environment
	 * mismatch, a provider the merchant has not enabled, or a customer outside the
	 * corridor all hide the method (ADR 0002 decision item 2).
	 */
	public function is_available(): bool {
		if ( 'yes' !== $this->enabled ) {
			return false;
		}

		if ( ! $this->plugin->is_connected() || ! $this->plugin->key_matches_environment() ) {
			return false;
		}

		$enabled_providers = $this->plugin->enabled_providers();

		if ( array() !== $enabled_providers && ! in_array( $this->provider_id, $enabled_providers, true ) ) {
			return false;
		}

		return $this->serves_country( $this->billing_country() );
	}

	/**
	 * True when this provider serves the given billing country.
	 */
	public function serves_country( string $country ): bool {
		if ( '' === $country ) {
			// Blocks checkout can render before a country is chosen. Fail closed
			// (CUSTOMER-COUNTRY-DETECTION.md section 10).
			return false;
		}

		return in_array( strtoupper( $country ), Countries::provider_countries( $this->provider_id ), true );
	}

	/**
	 * Current billing country from the WooCommerce customer session.
	 *
	 * The billing country is the single source of truth for method filtering — never
	 * GeoIP (CUSTOMER-COUNTRY-DETECTION.md section 1).
	 */
	protected function billing_country(): string {
		if ( ! function_exists( 'WC' ) || null === WC()->customer ) {
			return '';
		}

		return strtoupper( (string) WC()->customer->get_billing_country() );
	}

	/**
	 * Renders the mobile-money number field.
	 */
	public function payment_fields(): void {
		if ( '' !== $this->description ) {
			echo wp_kses_post( wpautop( $this->description ) );
		}

		$field_id = $this->id . '_phone';
		$default  = function_exists( 'WC' ) && null !== WC()->customer ? (string) WC()->customer->get_billing_phone() : '';

		echo '<fieldset class="snappay-payment-fields">';
		printf(
			'<p class="form-row form-row-wide"><label for="%1$s">%2$s <span class="required">*</span></label>
			<input id="%1$s" name="%1$s" type="tel" inputmode="tel" autocomplete="tel" class="input-text" value="%3$s" placeholder="%4$s" /></p>',
			esc_attr( $field_id ),
			esc_html__( 'Mobile money number', 'snap-pay-for-woocommerce' ),
			esc_attr( $default ),
			esc_attr__( 'e.g. 0712345678', 'snap-pay-for-woocommerce' )
		);
		echo '<p class="snappay-escrow-note">' . esc_html__( 'Snap Pay holds your payment until you confirm delivery with the code we email you.', 'snap-pay-for-woocommerce' ) . '</p>';
		echo '</fieldset>';
	}

	/**
	 * Validates the phone field before WooCommerce creates the order.
	 */
	public function validate_fields(): bool {
		$phone = $this->submitted_phone();

		if ( '' === $phone ) {
			wc_add_notice( __( 'Enter the mobile money number to charge.', 'snap-pay-for-woocommerce' ), 'error' );

			return false;
		}

		if ( '' === Countries::to_e164( $phone, $this->billing_country() ) ) {
			wc_add_notice( __( 'That mobile money number does not look valid. Use the full number, for example 0712345678.', 'snap-pay-for-woocommerce' ), 'error' );

			return false;
		}

		return true;
	}

	/**
	 * Reads the submitted phone number.
	 *
	 * Nonce verification is WooCommerce's responsibility on the checkout POST; this
	 * only sanitises the value.
	 */
	protected function submitted_phone(): string {
		$key = $this->id . '_phone';

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce.
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) ) : '';
	}

	/**
	 * Initiates the collect.
	 *
	 * @param int $order_id WooCommerce order ID.
	 *
	 * @return array<string, mixed> WooCommerce process_payment result.
	 */
	public function process_payment( $order_id ): array {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return $this->failure( __( 'Order not found.', 'snap-pay-for-woocommerce' ) );
		}

		$orders = $this->plugin->orders();
		$api    = $this->plugin->api();

		$phone = Countries::to_e164( $this->submitted_phone(), (string) $order->get_billing_country() );

		if ( '' === $phone ) {
			return $this->failure( __( 'That mobile money number does not look valid.', 'snap-pay-for-woocommerce' ) );
		}

		$payload = $this->build_collect_payload( $order, $phone );

		// Persist the FX lock before the call so a timeout still leaves an audit trail.
		if ( isset( $payload['fx_lock_id'] ) ) {
			$orders->record_fx_lock(
				$order,
				(string) $payload['fx_lock_id'],
				(string) FxDisplay::session_get( 'snappay_fx_rate' ),
				(string) $payload['display_currency'],
				(int) $payload['display_amount_minor']
			);
			$order->save();
		}

		try {
			$transaction = $api->collect( $payload );
		} catch ( ApiException $e ) {
			return $this->handle_collect_error( $e, $order );
		}

		$orders->record_collect( $order, $transaction, $this->provider_id, $this->plugin->environment() );

		$state = isset( $transaction['state'] ) ? (string) $transaction['state'] : 'requested';
		$orders->apply_state( $order, $state, $transaction, $this->requested_note( $transaction, $order ) );

		FxDisplay::clear_lock();

		if ( null !== WC()->cart ) {
			WC()->cart->empty_cart();
		}

		wc_add_notice( $this->handset_prompt( $transaction, $order ), 'notice' );

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * Builds the collect body from server-side order data.
	 *
	 * Amounts are read from the order, never from the browser (SECURITY.md section 8).
	 *
	 * @return array<string, mixed> Collect request body.
	 */
	protected function build_collect_payload( WC_Order $order, string $phone ): array {
		$currency = strtoupper( $order->get_currency() );

		$payload = array(
			'order_id'        => (string) $order->get_id(),
			'order_key'       => $order->get_order_key(),
			'amount_minor'    => Money::to_minor( $order->get_total(), $currency ),
			'currency'        => $currency,
			'customer_email'  => sanitize_email( (string) $order->get_billing_email() ),
			'customer_phone'  => $phone,
			'customer_name'   => mb_substr( trim( $order->get_formatted_billing_full_name() ), 0, 128 ),
			'billing_country' => strtoupper( (string) $order->get_billing_country() ),
			'provider_id'     => $this->provider_id,
			'callback_url'    => $this->plugin->api()->callback_url(),
		);

		$lock = FxDisplay::current_lock();

		if ( null !== $lock ) {
			$payload['display_currency']     = $lock['currency'];
			$payload['display_amount_minor'] = $lock['amount_minor'];
			$payload['fx_lock_id']           = $lock['lock_id'];
		}

		return (array) apply_filters( 'snappay_collect_payload', $payload, $order, $this );
	}

	/**
	 * Turns a platform error into checkout feedback, per the documented error table.
	 *
	 * @return array<string, mixed> WooCommerce process_payment result.
	 */
	protected function handle_collect_error( ApiException $e, WC_Order $order ): array {
		// A timeout means the platform may or may not have created the transaction.
		// Do not retry and do not assume failure (AGENTS.md F6).
		if ( $e->is_ambiguous() ) {
			$order->update_status(
				'on-hold',
				__( 'Snap Pay: the collect request timed out. The payment may still be in progress. Do not re-charge — the order updates automatically when the platform reports the outcome.', 'snap-pay-for-woocommerce' )
			);

			Logger::error( 'Collect timed out; ambiguity surfaced', array( 'order_id' => $order->get_id() ) );

			return $this->failure( $e->getMessage() );
		}

		switch ( $e->error_code() ) {
			case 'duplicate_order':
				// An active transaction already exists. Poll it rather than creating a second.
				$details = $e->details();
				$ref     = isset( $details['existing_transaction_ref'] ) ? (string) $details['existing_transaction_ref'] : '';

				if ( '' !== $ref ) {
					$order->update_meta_data( OrderRepository::META_TRANSACTION_REF, $ref );
					$order->save();
					$this->sync_existing( $order, $ref );

					return array(
						'result'   => 'success',
						'redirect' => $this->get_return_url( $order ),
					);
				}

				return $this->failure( __( 'A payment for this order is already in progress. Check your phone.', 'snap-pay-for-woocommerce' ) );

			case 'fx_lock_expired':
				FxDisplay::clear_lock();

				return $this->failure( __( 'The exchange rate quote expired. Refresh the page to get an updated rate, then pay again.', 'snap-pay-for-woocommerce' ) );

			case 'validation_failed':
				$detail = $e->first_detail();

				return $this->failure( '' !== $detail ? $detail : $e->getMessage() );

			case 'provider_unavailable':
				return $this->failure( __( 'That payment method is temporarily unavailable. Please choose another one.', 'snap-pay-for-woocommerce' ) );

			case 'unauthenticated':
			case 'unauthorized':
			case 'environment_mismatch':
				Logger::error( 'Collect rejected on credentials', array( 'error' => $e->error_code(), 'order_id' => $order->get_id() ) );

				return $this->failure( __( 'Payments are temporarily unavailable. Please contact the store.', 'snap-pay-for-woocommerce' ) );

			case 'rate_limited':
				return $this->failure( __( 'Too many payment attempts right now. Wait a moment and try again.', 'snap-pay-for-woocommerce' ) );

			default:
				return $this->failure( $e->getMessage() );
		}
	}

	/**
	 * Syncs an order against an already-existing platform transaction.
	 */
	protected function sync_existing( WC_Order $order, string $transaction_ref ): void {
		try {
			$transaction = $this->plugin->api()->get_transaction( $transaction_ref );
		} catch ( ApiException $e ) {
			Logger::warning( 'Could not poll existing transaction', array( 'error' => $e->error_code(), 'order_id' => $order->get_id() ) );

			return;
		}

		if ( isset( $transaction['state'] ) ) {
			$this->plugin->orders()->apply_state( $order, (string) $transaction['state'], $transaction );
		}
	}

	/**
	 * Order note recorded when the push has been sent.
	 *
	 * @param array<string, mixed> $transaction Collect response.
	 */
	protected function requested_note( array $transaction, WC_Order $order ): string {
		$note = sprintf(
			/* translators: 1: provider name, 2: Snap Pay transaction reference. */
			__( 'Snap Pay: %1$s payment request sent to the customer. Transaction %2$s. Awaiting confirmation.', 'snap-pay-for-woocommerce' ),
			$this->title,
			isset( $transaction['transaction_ref'] ) ? (string) $transaction['transaction_ref'] : '-'
		);

		if ( isset( $transaction['fx_rate'], $transaction['display_currency'] ) ) {
			$note .= ' ' . sprintf(
				/* translators: 1: store currency, 2: FX rate, 3: display currency. */
				__( 'FX rate locked at checkout: 1 %1$s = %2$s %3$s.', 'snap-pay-for-woocommerce' ),
				strtoupper( $order->get_currency() ),
				(string) $transaction['fx_rate'],
				strtoupper( (string) $transaction['display_currency'] )
			);
		}

		return $note;
	}

	/**
	 * Customer-facing "check your phone" message.
	 *
	 * @param array<string, mixed> $transaction Collect response.
	 */
	protected function handset_prompt( array $transaction, WC_Order $order ): string {
		if ( isset( $transaction['display_amount_minor'], $transaction['display_currency'] ) ) {
			return sprintf(
				/* translators: %s: the amount to approve, formatted with its currency. */
				__( 'Check your phone and approve the payment of %s.', 'snap-pay-for-woocommerce' ),
				Money::format_plain( (int) $transaction['display_amount_minor'], (string) $transaction['display_currency'] )
			);
		}

		return sprintf(
			/* translators: %s: the amount to approve, formatted with its currency. */
			__( 'Check your phone and approve the payment of %s.', 'snap-pay-for-woocommerce' ),
			Money::format_plain(
				(int) ( $transaction['amount_minor'] ?? Money::to_minor( $order->get_total(), $order->get_currency() ) ),
				(string) ( $transaction['currency'] ?? $order->get_currency() )
			)
		);
	}

	/**
	 * Standard failure result with a checkout notice.
	 *
	 * @return array<string, mixed> WooCommerce process_payment result.
	 */
	protected function failure( string $message ): array {
		wc_add_notice( $message, 'error' );

		return array( 'result' => 'failure' );
	}

	/**
	 * Refunds are a platform/back-office operation.
	 *
	 * The contract exposes no refund endpoint to the plugin (PLATFORM-API.md section 4),
	 * and the plugin may not move money (ARCHITECTURE.md section 5.8), so this returns a
	 * WP_Error directing the merchant to Snap Pay support rather than silently
	 * marking an order refunded that has not been refunded.
	 *
	 * @param int        $order_id Order ID.
	 * @param float|null $amount   Refund amount.
	 * @param string     $reason   Refund reason.
	 *
	 * @return \WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		return new \WP_Error(
			'snappay_refund_unsupported',
			__( 'Snap Pay refunds are processed by Snap Pay support, not from WooCommerce. Contact support with the transaction reference on this order.', 'snap-pay-for-woocommerce' )
		);
	}
}
