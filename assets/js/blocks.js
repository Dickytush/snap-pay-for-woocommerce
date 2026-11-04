/**
 * Snap Pay payment methods for the WooCommerce Blocks checkout.
 *
 * Written against the global wc/wp registries so the plugin ships without a build
 * step. No JSX, no bundler. It computes no amounts, handles no delivery codes and
 * receives no secrets — see CODING-STANDARDS.md section 4.
 */
( function ( window ) {
	'use strict';

	var registry = window.wc && window.wc.wcBlocksRegistry;
	var element = window.wp && window.wp.element;
	var i18n = window.wp && window.wp.i18n;
	var settingsStore = window.wc && window.wc.wcSettings;

	if ( ! registry || ! element || ! settingsStore ) {
		return;
	}

	var createElement = element.createElement;
	var useState = element.useState;
	var useEffect = element.useEffect;
	var __ = i18n ? i18n.__ : function ( text ) {
		return text;
	};

	var PROVIDERS = [ 'mpesa_ke', 'airtel_ke', 'mtn_ug', 'airtel_ug' ];

	/**
	 * Renders the mobile-money number field and the locked-FX summary.
	 */
	function SnapPayFields( props ) {
		var config = props.config;
		var eventRegistration = props.eventRegistration;
		var emitResponse = props.emitResponse;
		var billingCountry = props.billing && props.billing.billingAddress
			? props.billing.billingAddress.country
			: '';
		var cartTotal = props.billing && props.billing.cartTotal
			? props.billing.cartTotal.value
			: '';

		var phoneState = useState( '' );
		var phone = phoneState[ 0 ];
		var setPhone = phoneState[ 1 ];

		var fxState = useState( null );
		var fx = fxState[ 0 ];
		var setFx = fxState[ 1 ];

		var countdownState = useState( 0 );
		var remaining = countdownState[ 0 ];
		var setRemaining = countdownState[ 1 ];

		// Ask the server for the current rate lock. The server owns the lock; this
		// only displays it.
		//
		// Re-runs whenever the billing country or the cart total changes, because both
		// change what the customer will actually be charged: switching KE <-> UG moves
		// them between paying in KES and paying in UGX.
		useEffect( function () {
			var cfg = window.SnapPayBlocks || {};

			if ( ! cfg.fxLockUrl ) {
				return;
			}

			// Clear the previous quote immediately. Showing a stale UGX figure while a
			// new one is being fetched is worse than showing none.
			setFx( null );

			window
				.fetch( cfg.fxLockUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/json',
						// A plain WP REST route needs the wp_rest nonce, not the Store API one.
						'X-WP-Nonce': cfg.nonce || '',
					},
				} )
				.then( function ( response ) {
					return response.ok ? response.json() : null;
				} )
				.then( function ( data ) {
					if ( data && data.locked ) {
						setFx( data );
						setRemaining( data.expiresIn );
					}
				} )
				.catch( function () {
					// FX display is best-effort. Store currency stands.
				} );
		}, [ billingCountry, cartTotal ] );

		// Countdown ticker.
		useEffect( function () {
			if ( ! fx ) {
				return undefined;
			}

			var timer = window.setInterval( function () {
				setRemaining( function ( previous ) {
					return previous > 0 ? previous - 1 : 0;
				} );
			}, 1000 );

			return function () {
				window.clearInterval( timer );
			};
		}, [ fx ] );

		// Hand the phone number to the server at submit time.
		useEffect( function () {
			var unsubscribe = eventRegistration.onPaymentSetup( function () {
				if ( ! phone ) {
					return {
						type: emitResponse.responseTypes.ERROR,
						message: __( 'Enter the mobile money number to charge.', 'snap-pay-for-woocommerce' ),
					};
				}

				var data = {};
				data[ config.name + '_phone' ] = phone;

				return {
					type: emitResponse.responseTypes.SUCCESS,
					meta: { paymentMethodData: data },
				};
			} );

			return unsubscribe;
		}, [ phone, config.name, eventRegistration, emitResponse ] );

		var children = [];

		if ( config.description ) {
			children.push(
				createElement( 'p', { key: 'desc' }, config.description )
			);
		}

		children.push(
			createElement(
				'p',
				{ key: 'field', className: 'snappay-blocks-field' },
				createElement(
					'label',
					{ htmlFor: config.name + '_phone' },
					__( 'Mobile money number', 'snap-pay-for-woocommerce' )
				),
				createElement( 'input', {
					id: config.name + '_phone',
					type: 'tel',
					inputMode: 'tel',
					autoComplete: 'tel',
					value: phone,
					placeholder: '0712345678',
					onChange: function ( event ) {
						setPhone( event.target.value );
					},
				} )
			)
		);

		if ( fx ) {
			var minutes = Math.floor( remaining / 60 );
			var seconds = remaining % 60;
			var clock = minutes + ':' + ( seconds < 10 ? '0' : '' ) + seconds;

			children.push(
				createElement(
					'div',
					{ key: 'fx', className: 'snappay-blocks-fx' },
					createElement(
						'p',
						{ className: 'snappay-blocks-fx-amount' },
						__( 'You will pay', 'snap-pay-for-woocommerce' ) + ' ' + fx.amountLabel
					),
					createElement(
						'p',
						{ className: 'snappay-blocks-fx-rate' },
						'1 ' + fx.storeCurrency + ' = ' + Number( fx.rate ).toFixed( 2 ) + ' ' + fx.currency
					),
					createElement(
						'p',
						{ className: 'snappay-blocks-fx-timer' },
						remaining > 0
							? __( 'Rate locked for', 'snap-pay-for-woocommerce' ) + ' ' + clock
							: __( 'Rate expired — refresh for a new rate', 'snap-pay-for-woocommerce' )
					)
				)
			);
		}

		children.push(
			createElement(
				'p',
				{ key: 'escrow', className: 'snappay-blocks-escrow' },
				__(
					'Snap Pay holds your payment until you confirm delivery with the code we email you.',
					'snap-pay-for-woocommerce'
				)
			)
		);

		return createElement( 'div', { className: 'snappay-blocks-fields' }, children );
	}

	PROVIDERS.forEach( function ( providerId ) {
		var name = 'snappay_' + providerId;
		var config = settingsStore.getSetting( name + '_data', null );

		if ( ! config ) {
			return;
		}

		config.name = name;

		// Label = the provider's own mark plus its name. The logo identifies the method
		// the customer is picking; the text keeps it accessible and keeps the row
		// readable if the image ever fails to load.
		var label = config.icon
			? createElement(
				'span',
				{ className: 'snappay-blocks-label' },
				createElement( 'img', {
					src: config.icon,
					alt: '',
					className: 'snappay-blocks-icon',
					'aria-hidden': true,
				} ),
				createElement( 'span', null, config.title )
			)
			: createElement( 'span', null, config.title );

		registry.registerPaymentMethod( {
			name: name,
			label: label,
			ariaLabel: config.title,
			content: createElement( SnapPayFields, { config: config } ),
			edit: createElement( 'div', null, config.title ),
			/**
			 * Mirrors the server-side country filter. Returns false until the billing
			 * country is known — CUSTOMER-COUNTRY-DETECTION.md section 10.
			 */
			canMakePayment: function ( cartData ) {
				var country =
					cartData &&
					cartData.billingAddress &&
					cartData.billingAddress.country;

				if ( ! country ) {
					return false;
				}

				return country === config.country;
			},
			supports: {
				features: config.supports || [ 'products' ],
			},
		} );
	} );
} )( window );
