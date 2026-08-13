/*
 * Post-Views: kuaixun detail page injector.
 *
 * JustNews renders kuaixun single pages through the_excerpt(), which the
 * plugin's the_content / the_excerpt filters cannot reliably reach. This
 * script is enqueued only on singular kuaixun views when the dedicated
 * display_kuaixun_views switch is on, and appends a lightweight inline
 * span after the theme's .entry-content container. No positioning, no
 * wrapper, no layout shifts.
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

	function inject() {
		var target = document.querySelector( '.entry-content' );
		if ( ! target ) {
			return;
		}
		if ( target.parentNode.querySelector( '.kx-views' ) ) {
			return;
		}

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

		target.parentNode.insertBefore( span, target.nextSibling );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', inject );
	} else {
		inject();
	}
} )();