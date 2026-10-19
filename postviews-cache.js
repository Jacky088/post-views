/**
 * Records a view over REST API or admin-ajax.php.
 *
 * Enqueued when a page cache is in use, or when dwell time / scroll depth / UV
 * tracking is configured.
 *
 * On success, automatically updates any built-in PostViews styles in the DOM and
 * dispatches a `postviews:updated` event on `document`.
 */
( function () {
	if ( typeof viewsCacheL10n === 'undefined' || ! viewsCacheL10n.post_id ) {
		return;
	}

	var postId = viewsCacheL10n.post_id;
	var dwellTime = parseInt( viewsCacheL10n.dwell_time, 10 ) || 0;
	var scrollDepth = parseInt( viewsCacheL10n.scroll_depth, 10 ) || 0;
	var isUvMode = parseInt( viewsCacheL10n.count_mode, 10 ) === 1;

	// UV (Unique Visitor) deduplication: 24h per post.
	if ( isUvMode ) {
		try {
			var uvKey = 'pv_uv_' + postId;
			var lastVisit = localStorage.getItem( uvKey );
			var now = Date.now();
			if ( lastVisit && ( now - parseInt( lastVisit, 10 ) ) < 86400000 ) {
				return;
			}
		} catch ( e ) {
			// localStorage unavailable in private mode or restricted iframe; proceed.
		}
	}

	var dwellSatisfied = dwellTime <= 0;
	var scrollSatisfied = scrollDepth <= 0;
	var hitSent = false;

	function roundNumber( num ) {
		if ( num < 1000 ) {
			return num.toLocaleString();
		}
		var units = [
			{ divisor: 1e9, suffix: 'B' },
			{ divisor: 1e6, suffix: 'M' },
			{ divisor: 1e3, suffix: 'K' }
		];
		for ( var i = 0; i < units.length; i++ ) {
			var rounded = Math.round( ( num / units[ i ].divisor ) * 10 ) / 10;
			if ( rounded < 1000 ) {
				return rounded + units[ i ].suffix;
			}
		}
		return ( Math.round( ( num / 1e9 ) * 10 ) / 10 ) + 'B';
	}

	function syncThemeTopViews( views ) {
		var formatted = views.toLocaleString();
		var selectors = [
			'#post-' + postId + ' .item-meta-li.views',
			'#post-' + postId + ' .entry-meta .views',
			'article .entry-header .views',
			'article .item-meta-li.views',
			'.entry-header .item-meta-li.views',
			'.entry-header .views',
			'.article-header .views',
			'.single-header .views',
			'.entry-meta .views',
			'.post-meta .views',
			'.item-meta-li.views'
		];

		var candidates = document.querySelectorAll( selectors.join( ', ' ) );
		candidates.forEach( function( el ) {
			// Never touch our plugin's own bottom/inline containers
			if ( el.closest( '.pv-positioned-wrapper, .pv-minimal-line, .pv-modern-card, .pv-gradient-badge' ) ) {
				return;
			}

			// If element has a dedicated inner count span (e.g. .count or strong)
			var innerNum = el.querySelector( '.count, .num, strong, b' );
			if ( innerNum && /\d[\d,]*/.test( innerNum.textContent ) ) {
				innerNum.textContent = innerNum.textContent.replace( /\d[\d,]*/, formatted );
				return;
			}

			// Traverse text nodes to replace numbers in place, preserving labels/prefixes (e.g. "阅读 669" -> "阅读 671")
			var walker = document.createTreeWalker( el, NodeFilter.SHOW_TEXT, null, false );
			var node;
			while ( ( node = walker.nextNode() ) ) {
				if ( /\d[\d,]*/.test( node.nodeValue ) ) {
					node.nodeValue = node.nodeValue.replace( /\d[\d,]*/, formatted );
					break;
				}
			}
		} );
	}

	function updateDOM( views ) {
		var formatted = views.toLocaleString();
		var rounded = roundNumber( views );

		var wrappers = document.querySelectorAll( '.pv-positioned-wrapper[data-postviews-id="' + postId + '"], .pv-positioned-wrapper' );
		wrappers.forEach( function( wrapper ) {
			if ( wrapper.classList.contains( 'pv-mode-stats' ) || ( wrapper.getAttribute( 'data-pv-mode' ) && wrapper.getAttribute( 'data-pv-mode' ).indexOf( 'stats_' ) === 0 ) ) {
				return;
			}
			wrapper.querySelectorAll( '.pv-number, .pv-glass-count, .pv-chip-count' ).forEach( function( el ) {
				el.textContent = formatted;
			} );
			wrapper.querySelectorAll( '.pv-stat-value' ).forEach( function( el ) {
				el.textContent = rounded;
			} );
			wrapper.querySelectorAll( '.pv-badge-text strong, .pv-neon-text strong, .pv-sunset-text strong, .pv-solid-text strong' ).forEach( function( el ) {
				el.textContent = formatted;
			} );
			wrapper.querySelectorAll( '.pv-minimal-line .pv-text' ).forEach( function( el ) {
				var metaEl = el.querySelector( '.pv-reading-meta' );
				var metaHtml = metaEl ? metaEl.outerHTML : '';
				var suffix = el.textContent.replace( /^[\d,\.]+\s*/, '' );
				if ( metaEl ) {
					var baseSuffix = suffix.replace( metaEl.textContent, '' ).trim();
					el.innerHTML = formatted + ( baseSuffix ? ' ' + baseSuffix : '' ) + metaHtml;
				} else {
					el.textContent = formatted + ( suffix ? ' ' + suffix : '' );
				}
			} );
		} );

		// Real-time synchronization for JustNews and other third-party theme top meta bars.
		syncThemeTopViews( views );

		document.dispatchEvent(
			new CustomEvent( 'postviews:updated', {
				detail: {
					views: views,
					postId: postId,
				},
			} )
		);
	}

	function sendHit() {
		if ( hitSent ) {
			return;
		}
		hitSent = true;

		// Mark UV timestamp upon sending hit.
		if ( isUvMode ) {
			try {
				localStorage.setItem( 'pv_uv_' + postId, String( Date.now() ) );
			} catch ( e ) {}
		}

		var preferRest = viewsCacheL10n.api_transport !== 'ajax';
		var isRest = preferRest && !! viewsCacheL10n.rest_url;
		var targetUrl = isRest ? viewsCacheL10n.rest_url : viewsCacheL10n.admin_ajax_url;

		var headers = {
			'Content-Type': 'application/x-www-form-urlencoded',
			'Cache-Control': 'no-cache',
		};

		var bodyParams = isRest
			? new URLSearchParams( { post_id: postId } )
			: new URLSearchParams( {
				action: 'postviews',
				nonce: viewsCacheL10n.nonce,
				postviews_id: postId,
			} );

		fetch( targetUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: headers,
			body: bodyParams,
		} )
			.then( function( response ) {
				return response.json();
			} )
			.then( function( data ) {
				if ( ! data ) {
					return;
				}
				// Support both REST format { views: ... } and admin-ajax { success: true, data: { views: ... } }.
				var views = 0;
				if ( typeof data.views !== 'undefined' ) {
					views = parseInt( data.views, 10 ) || 0;
				} else if ( data.success && data.data && typeof data.data.views !== 'undefined' ) {
					views = parseInt( data.data.views, 10 ) || 0;
				}

				if ( views > 0 ) {
					updateDOM( views );
				}
			} )
			.catch( function( error ) {
				// eslint-disable-next-line no-console
				console.log( 'WP-PostViews', error );
			} );
	}

	function maybeTriggerHit() {
		if ( dwellSatisfied && scrollSatisfied ) {
			sendHit();
		}
	}

	// 1. Dwell time timer.
	if ( dwellTime > 0 ) {
		setTimeout( function () {
			dwellSatisfied = true;
			maybeTriggerHit();
		}, dwellTime * 1000 );
	}

	// 2. Scroll depth listener.
	if ( scrollDepth > 0 ) {
		function onScroll() {
			var docHeight = document.documentElement.scrollHeight - window.innerHeight;
			if ( docHeight <= 0 ) {
				scrollSatisfied = true;
				window.removeEventListener( 'scroll', onScroll );
				maybeTriggerHit();
				return;
			}
			var currentScroll = window.scrollY || window.pageYOffset || 0;
			var pct = Math.round( ( currentScroll / docHeight ) * 100 );
			if ( pct >= scrollDepth ) {
				scrollSatisfied = true;
				window.removeEventListener( 'scroll', onScroll );
				maybeTriggerHit();
			}
		}
		window.addEventListener( 'scroll', onScroll, { passive: true } );
		onScroll(); // Initial check in case page is short.
	}

	// Initial real-time alignment with bottom card if page was rendered from cache
	function syncInitial() {
		var wrapper = document.querySelector( '.pv-positioned-wrapper[data-postviews-id="' + postId + '"], .pv-positioned-wrapper' );
		if ( ! wrapper ) {
			return;
		}
		var numEl = wrapper.querySelector( '.pv-number, .pv-glass-count, .pv-chip-count, .pv-badge-text strong, .pv-neon-text strong, .pv-sunset-text strong, .pv-solid-text strong' );
		var initialNum = 0;
		if ( numEl ) {
			initialNum = parseInt( numEl.textContent.replace( /[^\d]/g, '' ), 10 ) || 0;
		} else {
			var textEl = wrapper.querySelector( '.pv-minimal-line .pv-text' );
			if ( textEl ) {
				var m = textEl.textContent.match( /\d[\d,]*/ );
				if ( m ) {
					initialNum = parseInt( m[0].replace( /[^\d]/g, '' ), 10 ) || 0;
				}
			}
		}
		if ( initialNum > 0 ) {
			syncThemeTopViews( initialNum );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', syncInitial );
	} else {
		syncInitial();
	}

	// If neither dwell time nor scroll depth was specified, trigger immediately.
	maybeTriggerHit();
} )();
