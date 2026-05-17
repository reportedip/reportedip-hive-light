/**
 * ReportedIP Hive — admin scripts.
 *
 * Wires the "Test connection" AJAX flow and the welcome-notice dismiss
 * behaviour. All translatable strings are injected via wp_localize_script.
 *
 * @package   ReportedIP_Hive
 * @author    Patrick Schlesinger <1@reportedip.de>
 * @copyright 2026 Patrick Schlesinger
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */
( function () {
	'use strict';

	function onReady( fn ) {
		if ( document.readyState !== 'loading' ) {
			fn();
			return;
		}
		document.addEventListener( 'DOMContentLoaded', fn );
	}

	function setResult( target, message, state ) {
		target.textContent = message;
		target.classList.remove( 'is-success', 'is-error', 'is-pending' );
		target.classList.add( 'is-' + state );
	}

	function bindTestConnection() {
		var button = document.getElementById( 'rip-test-connection' );
		var result = document.getElementById( 'rip-test-result' );
		var keyEl  = document.getElementById( 'reportedip_hive_api_key' );

		if ( ! button || ! result || ! keyEl ) {
			return;
		}

		button.addEventListener( 'click', function () {
			var key = ( keyEl.value || '' ).trim();
			if ( ! key ) {
				setResult( result, window.reportedipHiveAdmin.i18n.noKey, 'error' );
				return;
			}

			button.disabled = true;
			setResult( result, window.reportedipHiveAdmin.i18n.testing, 'pending' );

			var body = new FormData();
			body.append( 'action', 'reportedip_hive_test_connection' );
			body.append( 'nonce', window.reportedipHiveAdmin.testNonce );
			body.append( 'key', key );

			fetch( window.reportedipHiveAdmin.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: body
			} )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( payload ) {
					button.disabled = false;
					if ( payload && payload.success && payload.data ) {
						setResult( result, payload.data.message || '', payload.data.valid ? 'success' : 'error' );
					} else {
						setResult( result, ( payload && payload.data && payload.data.message ) || window.reportedipHiveAdmin.i18n.error, 'error' );
					}
				} )
				.catch( function () {
					button.disabled = false;
					setResult( result, window.reportedipHiveAdmin.i18n.error, 'error' );
				} );
		} );
	}

	function bindWelcomeDismiss() {
		var notices = document.querySelectorAll( '[data-rip-welcome]' );
		if ( ! notices.length ) {
			return;
		}

		notices.forEach( function ( notice ) {
			var dismiss = notice.querySelector( '.rip-welcome-notice__dismiss, .notice-dismiss' );
			if ( ! dismiss ) {
				return;
			}
			dismiss.addEventListener( 'click', function () {
				var nonce = notice.getAttribute( 'data-nonce' );
				if ( window.reportedipHiveAdmin && nonce ) {
					var body = new FormData();
					body.append( 'action', 'reportedip_hive_dismiss_welcome' );
					body.append( 'nonce', nonce );
					fetch( window.reportedipHiveAdmin.ajaxUrl, {
						method: 'POST',
						credentials: 'same-origin',
						body: body
					} );
				}
				notice.style.display = 'none';
			} );
		} );
	}

	function bindOperationModeToggle() {
		var radios = document.querySelectorAll( 'input[name="reportedip_hive_operation_mode"]' );
		if ( ! radios.length ) {
			return;
		}
		var wrap = document.querySelector( '.rip-wrap' );
		if ( ! wrap ) {
			return;
		}

		function sync() {
			var checked = document.querySelector( 'input[name="reportedip_hive_operation_mode"]:checked' );
			if ( checked ) {
				wrap.setAttribute( 'data-rip-operation-mode', checked.value );
			}
		}

		radios.forEach( function ( r ) {
			r.addEventListener( 'change', sync );
		} );
		sync();
	}

	onReady( function () {
		if ( ! window.reportedipHiveAdmin ) {
			return;
		}
		bindTestConnection();
		bindWelcomeDismiss();
		bindOperationModeToggle();
	} );
} )();
