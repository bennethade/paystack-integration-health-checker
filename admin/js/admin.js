/**
 * Paystack Integration Health Checker - admin enhancements.
 *
 * Forms work without JavaScript (admin-post.php). With JavaScript, forms marked
 * .pihc-ajax-form are sent to admin-ajax.php with the same fields (including the nonce),
 * the button is disabled while running to prevent duplicate submissions, and the
 * server-rendered (already escaped) HTML replaces the target element. Results are
 * announced in an aria-live region.
 */
( function () {
	'use strict';

	var config = window.pihcAdmin || {};
	var live = document.querySelector( '.pihc-live' );

	function announce( message ) {
		if ( live ) {
			live.textContent = '';
			window.setTimeout( function () {
				live.textContent = message;
			}, 50 );
		}
	}

	function setBusy( form, busy ) {
		var button = form.querySelector( 'button[type="submit"]' );
		var spinner = form.querySelector( '.spinner' );
		form.setAttribute( 'aria-busy', busy ? 'true' : 'false' );
		if ( button ) {
			button.disabled = busy;
			if ( busy ) {
				button.dataset.label = button.textContent;
				button.textContent = ( config.i18n && config.i18n.running ) || 'Running…';
			} else if ( button.dataset.label ) {
				button.textContent = button.dataset.label;
			}
		}
		if ( spinner ) {
			spinner.classList.toggle( 'is-active', busy );
		}
	}

	function showError( form, message ) {
		var notice = document.createElement( 'div' );
		notice.className = 'notice notice-error inline pihc-error';
		var p = document.createElement( 'p' );
		p.textContent = message;
		notice.appendChild( p );
		var previous = form.parentNode.querySelector( '.pihc-error' );
		if ( previous ) {
			previous.remove();
		}
		form.parentNode.insertBefore( notice, form.nextSibling );
		announce( message );
	}

	function replace( selector, html, outer ) {
		var target = selector ? document.querySelector( selector ) : null;
		if ( ! target || 'string' !== typeof html ) {
			return null;
		}
		if ( outer ) {
			var wrapper = document.createElement( 'div' );
			wrapper.innerHTML = html; // Server-rendered and escaped by Result_Renderer.
			var replacement = wrapper.firstElementChild;
			if ( replacement ) {
				target.replaceWith( replacement );
				return replacement;
			}
			return null;
		}
		target.innerHTML = html; // Server-rendered and escaped by Result_Renderer.
		return target;
	}

	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;

		if ( form.dataset && form.dataset.confirm && ! window.confirm( form.dataset.confirm ) ) {
			event.preventDefault();
			return;
		}

		if ( ! form.classList || ! form.classList.contains( 'pihc-ajax-form' ) || ! window.fetch || ! config.ajaxUrl ) {
			return;
		}
		event.preventDefault();

		if ( 'true' === form.getAttribute( 'aria-busy' ) ) {
			return; // Already running: ignore duplicate submissions.
		}

		setBusy( form, true );

		window.fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: new FormData( form ),
		} )
			.then( function ( response ) {
				return response.json().catch( function () {
					return { success: false, data: { message: ( config.i18n && config.i18n.failed ) || 'Request failed.' } };
				} );
			} )
			.then( function ( payload ) {
				setBusy( form, false );
				if ( ! payload || ! payload.success ) {
					showError( form, ( payload && payload.data && payload.data.message ) || ( config.i18n && config.i18n.failed ) || 'Request failed.' );
					return;
				}
				var data = payload.data || {};
				if ( data.summary_html ) {
					replace( form.dataset.summary, data.summary_html, false );
				}
				var updated = replace( form.dataset.target, data.html, 'outer' === form.dataset.replace );
				announce( data.message || '' );
				if ( updated && updated.focus ) {
					updated.setAttribute( 'tabindex', '-1' );
					updated.focus( { preventScroll: false } );
				}
			} )
			.catch( function () {
				setBusy( form, false );
				showError( form, ( config.i18n && config.i18n.failed ) || 'Request failed.' );
			} );
	} );
} )();
