/*
 * Post-Views: kuaixun detail page injector.
 *
 * JustNews renders kuaixun detail pages through the_excerpt(), which the
 * plugin's the_content / the_excerpt filters cannot reliably reach. This
 * script is enqueued only on singular kuaixun views when the dedicated
 * display_kuaixun_views switch is on, and inserts a small wrapped pill at
 * the bottom-right of the article's content container. The wrapper is
 * positioned absolutely so the label does not stretch or push the layout.
 */
( function () {
	if ( typeof window.PostViewsKuaixun !== 'object' || ! window.PostViewsKuaixun ) {
		return;
	}

	var data = window.PostViewsKuaixun;
	var count = parseInt( data.count, 10 ) || 0;

	function format( n ) {
		var s = String( n );
		return s.replace( /\B(?=(\d{3})+(?!\d))/g, ',' );
	}

	function pickTarget() {
		var selectors = [
			'.entry-content',
			'.kx-content',
			'.entry-content.clearfix',
			'article .entry-content',
			'.entry-main .entry-content',
			'article .entry-main',
			'#post-' + data.post_id + ' .entry-content',
		];
		for ( var i = 0; i < selectors.length; i++ ) {
			var el = document.querySelector( selectors[ i ] );
			if ( el ) {
				return el;
			}
		}
		return null;
	}

	function buildPill() {
		var wrap = document.createElement( 'div' );
		wrap.className = 'kx-views-wrap pv-positioned-wrapper';
		wrap.setAttribute( 'data-postviews-id', String( data.post_id ) );

		if ( data.html && typeof data.html === 'string' && data.html.trim().length > 0 ) {
			wrap.innerHTML = data.html;
			return wrap;
		}

		// Fallback minimal-line capsule with elegant book icon
		var pill = document.createElement( 'div' );
		pill.className = 'pv-minimal-line';
		pill.innerHTML = '<span class="pv-icon"><svg class="pv-svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg></span><span class="pv-divider"></span><span class="pv-text">' + format( count ) + ' 次阅读</span>';
		wrap.appendChild( pill );
		return wrap;
	}

	function inject() {
		var target = pickTarget();
		if ( ! target ) {
			return;
		}
		if ( document.querySelector( '.kx-views-wrap' ) ) {
			return;
		}

		var wrap = buildPill();
		var parent = target.parentNode;

		// The wrapper needs a positioned ancestor so absolute coords refer to
		// the article block, not the viewport. If the parent is not already
		// positioned, make it so without altering its visual size.
		var parentPos = window.getComputedStyle( parent ).position;
		if ( parentPos === 'static' ) {
			parent.classList.add( 'kx-views-anchor' );
		}

		parent.appendChild( wrap );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', inject );
	} else {
		inject();
	}
} )();