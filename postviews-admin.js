/**
 * Settings > PostViews.
 *
 * One delegated listener for the "Restore Default Template" buttons, replacing
 * the inline onclick handlers and the jQuery call they used up to 1.78.1. The
 * defaults arrive as data from wp_localize_script() rather than being written
 * into an attribute, so no escaping happens in a JavaScript string literal.
 *
 * The tab switching, copy buttons and reset-all confirmation used to live in a
 * <script> block at the bottom of the settings page; they moved here when that
 * page was split so the browser can cache them.
 */
( function() {
	document.addEventListener( 'click', function( event ) {
		const button = event.target.closest( '[data-postviews-reset]' );

		if ( ! button ) {
			return;
		}

		const key = button.dataset.postviewsReset;
		const field = document.getElementById( button.dataset.postviewsTarget );

		if (
			! field ||
			typeof postviewsAdminL10n === 'undefined' ||
			typeof postviewsAdminL10n.defaults[ key ] === 'undefined'
		) {
			return;
		}

		field.value = postviewsAdminL10n.defaults[ key ];
	} );

	document.addEventListener( 'DOMContentLoaded', function() {
		// Tab switching with hash persistence.
		var tabs = document.querySelectorAll( '.pv-nav-tabs .pv-nav-tab' );
		var contents = document.querySelectorAll( '.pv-tab-content' );

		function activateTab( tabId ) {
			tabs.forEach( function( t ) {
				if ( t.getAttribute( 'data-tab' ) === tabId ) {
					t.classList.add( 'pv-nav-active' );
				} else {
					t.classList.remove( 'pv-nav-active' );
				}
			} );
			contents.forEach( function( c ) {
				c.style.display = ( c.id === tabId ) ? 'block' : 'none';
			} );
		}

		tabs.forEach( function( tab ) {
			tab.addEventListener( 'click', function( e ) {
				e.preventDefault();
				if ( typeof this.blur === 'function' ) {
					this.blur();
				}
				var tabId = this.getAttribute( 'data-tab' );
				activateTab( tabId );
				if ( history.replaceState ) {
					history.replaceState( null, null, '#' + tabId );
				}
			} );
		} );

		var hash = window.location.hash ? window.location.hash.substring( 1 ) : 'tab-styles';
		if ( document.getElementById( hash ) ) {
			activateTab( hash );
		} else {
			activateTab( 'tab-styles' );
		}

		// Clear any browser focus outline restored on reload or hash jump.
		if ( document.activeElement && document.activeElement !== document.body && typeof document.activeElement.blur === 'function' ) {
			document.activeElement.blur();
		}

		// Suppress focus rect when submitting the settings form.
		var mainForm = document.getElementById( 'pv-main-form' );
		if ( mainForm ) {
			mainForm.addEventListener( 'submit', function() {
				if ( document.activeElement && typeof document.activeElement.blur === 'function' ) {
					document.activeElement.blur();
				}
			} );
		}

		// Style preset real-time radio change.
		var styleRadios = document.querySelectorAll( 'input[name="views_options[template_style]"]' );
		var templateInput = document.getElementById( 'views-template-template' );
		styleRadios.forEach( function( radio ) {
			radio.addEventListener( 'change', function() {
				if ( this.checked && templateInput ) {
					var tpl = this.getAttribute( 'data-template' );
					if ( tpl ) {
						templateInput.value = tpl;
					}
				}
			} );
		} );

		// One-click copy buttons.
		document.addEventListener( 'click', function( e ) {
			var copyBtn = e.target.closest( '[data-pv-copy-target], [data-pv-copy]' );
			if ( ! copyBtn ) {
				return;
			}

			var textToCopy = '';
			if ( copyBtn.getAttribute( 'data-pv-copy' ) ) {
				textToCopy = copyBtn.getAttribute( 'data-pv-copy' );
			} else if ( copyBtn.getAttribute( 'data-pv-copy-target' ) ) {
				var targetEl = document.getElementById( copyBtn.getAttribute( 'data-pv-copy-target' ) );
				if ( targetEl ) {
					textToCopy = targetEl.value;
				}
			}

			if ( textToCopy && navigator.clipboard ) {
				navigator.clipboard.writeText( textToCopy ).then( function() {
					var originalText = copyBtn.innerHTML;
					copyBtn.innerHTML = '<span>✓ 已复制</span>';
					setTimeout( function() {
						copyBtn.innerHTML = originalText;
					}, 1800 );
				} );
			}
		} );

		// Reset all options confirmation.
		var resetAllBtn = document.getElementById( 'pv-reset-all-btn' );
		if ( resetAllBtn ) {
			resetAllBtn.addEventListener( 'click', function() {
				var message = ( typeof postviewsAdminL10n !== 'undefined' && postviewsAdminL10n.resetConfirm )
					? postviewsAdminL10n.resetConfirm
					: '确定要重置所有选项为默认值吗？未保存的自定义设置将被覆盖。';
				if ( confirm( message ) ) {
					// Trigger defaults restoration.
					document.querySelectorAll( '[data-postviews-reset]' ).forEach( function( btn ) {
						btn.click();
					} );
				}
			} );
		}
	} );
} )();
