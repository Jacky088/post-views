/*
 * Post-Views: kuaixun detail page injector.
 *
 * JustNews renders kuaixun detail pages through the_excerpt(), which the
 * plugin's the_content / the_excerpt filters cannot reliably reach. This
 * script is enqueued only on singular kuaixun views when the dedicated
 * display_kuaixun_views switch is on, and appends a lightweight inline
 * span inside the theme's content container. No positioning, no wrapper,
 * no layout shifts.
 */
( function () {
	if ( typeof window.PostViewsKuaixun !== 'object' || ! window.PostViewsKuaixun ) {
		return;
	}

	var data = window.PostViewsKuaixun;
	var count = parseInt( data.count, 10 ) || 0;

	// Iron-clad proof that this script ran. If you can find a
	// data-pv-kx-debug="loaded" on the page, the script reached this line.
	// If you cannot, the script never executed (deferred, stripped by an
	// optimizer, cached, or blocked by CSP).
	var boot = document.createElement( 'div' );
	boot.setAttribute( 'data-pv-kx-debug', 'loaded' );
	boot.style.display = 'none';
	(document.body || document.documentElement).appendChild( boot );

	window.addEventListener( 'error', function ( e ) {
		var d = document.createElement( 'div' );
		d.setAttribute( 'data-pv-kx-debug', 'error:' + ( e.message || 'unknown' ) );
		d.style.display = 'none';
		document.body.appendChild( d );
	} );

	function format( n ) {
		var s = String( n );
		return s.replace( /\B(?=(\d{3})+(?!\d))/g, ',' );
	}

	function debug( msg ) {
		// Surface failures on the page itself so they are visible without
		// having to open devtools. Tagged with a stable marker so it can be
		// located with Ctrl+F.
		var d = document.createElement( 'div' );
		d.setAttribute( 'data-pv-kx-debug', msg );
		d.style.display = 'none';
		document.body && document.body.appendChild( d );
	}

	function pickTarget() {
		// A list of selectors to try, in the order most-likely to be the
		// actual content container on a JustNews kuaixun detail page. The
		// first match wins. JustNews' single-kuaixun.php uses .entry-content,
		// but its wrappers / variant templates may not.
		var selectors = [
			'.entry-content',
			'.kx-content',
			'.entry-content.clearfix',
			'article .entry-content',
			'.entry-main .entry-content',
			'article .entry-main',
			'#post-11412 .entry-content',
		];
		for ( var i = 0; i < selectors.length; i++ ) {
			var el = document.querySelector( selectors[ i ] );
			if ( el ) {
				return el;
			}
		}
		return null;
	}

	function buildSpan() {
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
		return span;
	}

	function inject() {
		var target = pickTarget();
		if ( ! target ) {
			debug( 'no-target' );
			return;
		}
		if ( document.querySelector( '.kx-views' ) ) {
			debug( 'already-injected' );
			return;
		}

		var span = buildSpan();
		target.parentNode.insertBefore( span, target.nextSibling );
		debug( 'injected-' + target.tagName.toLowerCase() + ( target.className ? '.' + target.className.replace( /\s+/g, '.' ) : '' ) );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', inject );
	} else {
		inject();
	}
} )();