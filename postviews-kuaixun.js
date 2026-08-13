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
		wrap.className = 'kx-views-wrap';

		var span = document.createElement( 'span' );
		span.className = 'item-meta-li views kx-views';
		span.setAttribute( 'title', 'Views' );

		var svg = document.createElementNS( 'http://www.w3.org/2000/svg', 'svg' );
		svg.setAttribute( 'class', 'kx-views-icon' );
		svg.setAttribute( 'viewBox', '0 0 24 24' );
		svg.setAttribute( 'width', '14' );
		svg.setAttribute( 'height', '14' );
		svg.setAttribute( 'fill', 'none' );
		svg.setAttribute( 'stroke', 'currentColor' );
		svg.setAttribute( 'stroke-width', '2' );
		svg.setAttribute( 'stroke-linecap', 'round' );
		svg.setAttribute( 'stroke-linejoin', 'round' );
		svg.setAttribute( 'aria-hidden', 'true' );

		var path = document.createElementNS( 'http://www.w3.org/2000/svg', 'path' );
		path.setAttribute( 'd', 'M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z' );
		var circle = document.createElementNS( 'http://www.w3.org/2000/svg', 'circle' );
		circle.setAttribute( 'cx', '12' );
		circle.setAttribute( 'cy', '12' );
		circle.setAttribute( 'r', '3' );
		svg.appendChild( path );
		svg.appendChild( circle );

		span.appendChild( svg );
		span.appendChild( document.createTextNode( format( count ) ) );
		wrap.appendChild( span );
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