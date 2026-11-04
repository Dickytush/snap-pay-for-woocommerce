/**
 * Classic checkout FX lock countdown.
 *
 * Purely presentational. The authoritative expiry lives on the platform: if the
 * lock has expired by the time the customer pays, the collect call returns
 * 410 fx_lock_expired and the gateway asks them to refresh.
 */
( function () {
	'use strict';

	var strings = window.SnapPayCheckout || {};

	function pad( value ) {
		return value < 10 ? '0' + value : String( value );
	}

	function tick( node ) {
		var remaining = parseInt( node.getAttribute( 'data-snappay-fx-expires' ), 10 );

		if ( isNaN( remaining ) ) {
			return;
		}

		if ( remaining <= 0 ) {
			node.textContent = strings.expiredMessage || 'Rate expired';
			node.classList.add( 'snappay-fx-expired' );

			return;
		}

		var minutes = Math.floor( remaining / 60 );
		var seconds = remaining % 60;

		node.textContent =
			( strings.lockedPrefix || 'Rate locked for' ) +
			' ' +
			pad( minutes ) +
			':' +
			pad( seconds );

		node.setAttribute( 'data-snappay-fx-expires', String( remaining - 1 ) );
	}

	function start() {
		var nodes = document.querySelectorAll( '[data-snappay-fx-expires]' );

		Array.prototype.forEach.call( nodes, function ( node ) {
			if ( node.dataset.snappayTicking === '1' ) {
				return;
			}

			node.dataset.snappayTicking = '1';

			window.setInterval( function () {
				tick( node );
			}, 1000 );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', start );

	// The totals table is re-rendered on every checkout AJAX refresh.
	if ( window.jQuery ) {
		window.jQuery( document.body ).on( 'updated_checkout', start );
	}
} )();
