<?php
/**
 * Recording a view.
 *
 * Two paths, and which one runs depends on WP_CACHE. Without a page cache the
 * count is incremented directly during wp_head. With one, that would only ever
 * record the request that generated the cached page, so a small script posts
 * to admin-ajax.php instead.
 *
 * @package Post-Views
 */

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether a request counts, and records it.
 */
class PostViews_Counter {

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'process' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_ajax_postviews', array( __CLASS__, 'ajax_increment' ) );
		add_action( 'wp_ajax_nopriv_postviews', array( __CLASS__, 'ajax_increment' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_route' ) );

		// High-concurrency buffer flush cron.
		add_action( 'postviews_flush_buffer_event', array( __CLASS__, 'flush_buffer' ) );
		if ( ! wp_next_scheduled( 'postviews_flush_buffer_event' ) ) {
			wp_schedule_event( time() + 60, 'hourly', 'postviews_flush_buffer_event' );
		}
		add_action( 'admin_post_postviews_flush_buffer', array( __CLASS__, 'handle_admin_flush_buffer' ) );
	}

	/**
	 * User agent fragments treated as robots.
	 *
	 * Matched case-insensitively as substrings. Some entries are far broader
	 * than their name suggests - 'spider' on its own will match an ordinary
	 * browser whose user agent happens to contain it - but the list has been
	 * this way for years and narrowing it would silently change the counts on
	 * every site that has bot exclusion turned on.
	 *
	 * @return array
	 */
	public static function bots() {
		return array(
			'Google Bot'    => 'google',
			'MSN'           => 'msnbot',
			'Alex'          => 'ia_archiver',
			'Lycos'         => 'lycos',
			'Ask Jeeves'    => 'jeeves',
			'Altavista'     => 'scooter',
			'AllTheWeb'     => 'fast-webcrawler',
			'Inktomi'       => 'slurp@inktomi',
			'Turnitin.com'  => 'turnitinbot',
			'Technorati'    => 'technorati',
			'Yahoo'         => 'yahoo',
			'Findexa'       => 'findexa',
			'NextLinks'     => 'findlinks',
			'Gais'          => 'gaisbo',
			'WiseNut'       => 'zyborg',
			'WhoisSource'   => 'surveybot',
			'Bloglines'     => 'bloglines',
			'BlogSearch'    => 'blogsearch',
			'PubSub'        => 'pubsub',
			'Syndic8'       => 'syndic8',
			'RadioUserland' => 'userland',
			'Gigabot'       => 'gigabot',
			'Become.com'    => 'become.com',
			'Baidu'         => 'baiduspider',
			'so.com'        => '360spider',
			'Sogou'         => 'spider',
			'soso.com'      => 'sosospider',
			'Yandex'        => 'yandex',
			'Ahrefs'        => 'AhrefsBot',
			'Bing'          => 'bingbot',
			'Apple'         => 'applebot',
			'GitCrawler'    => 'GitCrawlerBot',
			'Bytedance'     => 'Bytespider',
			'webmeup'       => 'BLEXBot',
		);
	}

	/**
	 * Whether this request should be counted against a post.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function should_count( $post_id ) {
		$should_count = false;
		$user_id      = get_current_user_id();

		switch ( PostViews_Options::get_int( 'count' ) ) {
			case 0: // Everyone.
				$should_count = true;
				break;
			case 1: // Guests only. The cookie catches a cached page served to
				// someone whose session is still live.
				$logged_in_cookie = defined( 'LOGGED_IN_COOKIE' ) && ! empty( $_COOKIE[ LOGGED_IN_COOKIE ] );
				$user_cookie      = defined( 'USER_COOKIE' ) && ! empty( $_COOKIE[ USER_COOKIE ] );
				if ( ! $logged_in_cookie && ! $user_cookie && 0 === $user_id ) {
					$should_count = true;
				}
				break;
			case 2: // Registered users only.
				if ( $user_id > 0 ) {
					$should_count = true;
				}
				break;
		}

		if ( $should_count && 1 === PostViews_Options::get_int( 'exclude_bots' ) && self::is_bot() ) {
			$should_count = false;
		}

		/**
		 * Filters whether the current request increments the view count.
		 *
		 * @param bool $should_count Whether to count this request.
		 * @param int  $post_id      The post being viewed.
		 */
		return apply_filters( 'postviews_should_count', $should_count, $post_id );
	}

	/**
	 * Whether the requesting user agent is on the robot list.
	 *
	 * @return bool
	 */
	protected static function is_bot() {
		$useragent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

		if ( '' === $useragent ) {
			return false;
		}

		foreach ( self::bots() as $lookfor ) {
			if ( false !== stripos( $useragent, $lookfor ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the AJAX path is in use, rather than counting during wp_head.
	 *
	 * @return bool
	 */
	public static function using_ajax() {
		if ( ! defined( 'WP_CACHE' ) || ! WP_CACHE ) {
			return false;
		}

		return 0 !== PostViews_Options::get_int( 'use_ajax' );
	}

	/**
	 * Whether the count has to be reported by the browser.
	 *
	 * A cached page cannot be counted during wp_head, and the reading-time and
	 * scroll-depth rules can only be evaluated once the visitor has actually
	 * spent time on the page. In both cases the server-side increment is
	 * skipped in favour of the client script.
	 *
	 * @return bool
	 */
	public static function needs_client_report() {
		if ( self::using_ajax() ) {
			return true;
		}

		if ( PostViews_Options::get_int( 'dwell_time' ) > 0 ) {
			return true;
		}

		if ( PostViews_Options::get_int( 'scroll_depth' ) > 0 ) {
			return true;
		}

		return 1 === PostViews_Options::get_int( 'count_mode' );
	}

	/**
	 * The post this request is a view of, or null.
	 *
	 * @return WP_Post|null
	 */
	protected static function current_post() {
		global $post;

		// Historically this global could arrive as a bare ID.
		if ( is_int( $post ) ) {
			$post = get_post( $post ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}

		if ( ! $post instanceof WP_Post ) {
			return null;
		}

		if ( wp_is_post_revision( $post ) ) {
			return null;
		}

		if ( ! is_single() && ! is_page() ) {
			return null;
		}

		return $post;
	}

	/**
	 * Whether a post type participates in counting.
	 *
	 * The kuaixun post type is intentionally absent from the picker (its
	 * template bypasses the_content), but the display_kuaixun_views switch
	 * lets JustNews users opt back into counting when they also want the
	 * label rendered on the detail page.
	 *
	 * @param string $post_type Post type name.
	 * @return bool
	 */
	public static function is_countable_post_type( $post_type ) {
		$enabled_types = PostViews_Options::get( 'enabled_post_types', array( 'post', 'page' ) );
		if ( ! is_array( $enabled_types ) ) {
			$enabled_types = array( 'post', 'page' );
		}

		if ( in_array( $post_type, $enabled_types, true ) ) {
			return true;
		}

		return 'kuaixun' === $post_type && 1 === PostViews_Options::get_int( 'display_kuaixun_views' );
	}

	/**
	 * Count the view during wp_head, when there is no page cache in the way.
	 *
	 * @return void
	 */
	public static function process() {
		if ( is_preview() ) {
			return;
		}

		$post = self::current_post();
		if ( null === $post ) {
			return;
		}

		if ( ! self::is_countable_post_type( $post->post_type ) ) {
			return;
		}

		if ( self::needs_client_report() ) {
			return;
		}

		if ( ! self::should_count( (int) $post->ID ) ) {
			return;
		}

		self::increment( (int) $post->ID, 'postviews_increment_views' );
	}

	/**
	 * Enqueue the counting script when the AJAX path is in use.
	 *
	 * @return void
	 */
	public static function enqueue() {
		if ( ! self::needs_client_report() ) {
			return;
		}

		$post = self::current_post();
		if ( null === $post ) {
			return;
		}

		if ( ! self::is_countable_post_type( $post->post_type ) ) {
			return;
		}

		if ( ! self::should_count( (int) $post->ID ) ) {
			return;
		}

		wp_enqueue_script(
			'wp-postviews-cache',
			plugins_url( 'postviews-cache.js', WP_POSTVIEWS_MAIN_FILE ),
			array(),
			WP_POSTVIEWS_VERSION,
			true
		);
		wp_localize_script(
			'wp-postviews-cache',
			'viewsCacheL10n',
			array(
				'admin_ajax_url' => admin_url( 'admin-ajax.php' ),
				'rest_url'       => esc_url_raw( rest_url( 'post-views/v1/hit' ) ),
				'nonce'          => wp_create_nonce( 'wp_postviews_nonce' ),
				'post_id'        => (int) $post->ID,
				'count_mode'     => PostViews_Options::get_int( 'count_mode' ),
				'dwell_time'     => PostViews_Options::get_int( 'dwell_time' ),
				'scroll_depth'   => PostViews_Options::get_int( 'scroll_depth' ),
				'api_transport'  => PostViews_Options::get( 'api_transport', 'rest' ),
			)
		);
	}

	/**
	 * The admin-ajax.php endpoint the cache script posts to.
	 *
	 * The nonce is checked, but it is worth being honest about what that buys:
	 * for a logged out visitor the nonce is derived from a shared anonymous
	 * session, so anyone can mint one. It stops a naive cross-site POST and
	 * nothing more. The real guard is that the only thing this can do is add
	 * one to a counter on a post that already exists.
	 *
	 * @return void
	 */
	public static function ajax_increment() {
		check_ajax_referer( 'wp_postviews_nonce', 'nonce' );

		if ( ! self::needs_client_report() ) {
			return;
		}

		if ( empty( $_POST['postviews_id'] ) ) {
			return;
		}

		$post_id = (int) sanitize_key( wp_unslash( $_POST['postviews_id'] ) );

		if ( $post_id <= 0 ) {
			return;
		}

		$post = get_post( $post_id );

		// update_post_meta() creates a row whether or not the post exists, so
		// without an existence check a logged out visitor can walk the ID space
		// and grow wp_postmeta without bound. Viewability is checked too: a
		// draft or private post must not accrue views from the outside.
		if ( ! $post instanceof WP_Post || ! is_post_status_viewable( $post->post_status ) ) {
			return;
		}

		// The enqueue path already gated on the type and on should_count(), so a
		// request from a legitimately served page passes both. Repeating them
		// here is what stops a hand-built request from counting a type the site
		// turned off, or a view the settings exclude.
		if ( ! self::is_countable_post_type( $post->post_type ) ) {
			return;
		}

		if ( ! self::should_count( $post_id ) ) {
			return;
		}

		// 防刷：同一 IP 在短时间窗口内对同一文章只计一次自增。
		// 不改变原有计数行为，仅在高频重复请求时跳过自增并返回当前值。
		// 窗口为 0 或负数时视为关闭限流（postviews_rate_limit_seconds 过滤器）。
		$seconds = (int) apply_filters( 'postviews_rate_limit_seconds', 3 );

		if ( ! self::check_rate_limit( $post_id, $seconds ) ) {
			$current_views = (int) get_post_meta( $post_id, 'views', true );
			wp_send_json_success(
				array(
					'views'     => $current_views,
					'throttled' => true,
				)
			);
		}

		$post_views = self::increment( $post_id, 'postviews_increment_views_ajax' );

		wp_send_json_success( array( 'views' => $post_views ) );
	}

	/**
	 * Best-effort client IP for rate-limiting.
	 *
	 * Climbs the usual proxy headers in a defensive order and falls back to the
	 * direct remote address. Not authoritative for security - on a direct
	 * connection every one of these headers is client-controlled - but it is
	 * enough to blunt naive view-count inflation from a single host. The
	 * postviews_client_ip filter lets a site pin the key to REMOTE_ADDR, or to
	 * a header its own proxy sets, when it wants the unforgeable value.
	 *
	 * @return string
	 */
	protected static function get_client_ip() {
		$candidates = array(
			'HTTP_CF_CONNECTING_IP',
			'HTTP_CLIENT_IP',
			'HTTP_X_REAL_IP',
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_FORWARDED',
			'HTTP_X_CLUSTER_CLIENT_IP',
			'HTTP_FORWARDED_FOR',
			'HTTP_FORWARDED',
			'REMOTE_ADDR',
		);

		$found = '';
		foreach ( $candidates as $key ) {
			if ( empty( $_SERVER[ $key ] ) ) {
				continue;
			}
			$value = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
			// HTTP_X_FORWARDED_FOR may be a comma list; take the first.
			$parts = explode( ',', $value );
			$ip    = trim( $parts[0] );
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				$found = $ip;
				break;
			}
		}

		if ( '' === $found ) {
			$found = '0.0.0.0';
		}

		/**
		 * Filters the client IP used to derive the rate-limit key.
		 *
		 * @param string $ip The best-effort client IP.
		 */
		return apply_filters( 'postviews_client_ip', $found );
	}

	/**
	 * Whether the current client may increment this post right now.
	 *
	 * Previously every hit wrote its own transient, which on a busy site left
	 * one expired option row per IP and post behind. The window map lives in a
	 * single transient instead, and expired entries are dropped as it is read.
	 * When an external object cache is present the placeholder insert is atomic,
	 * so concurrent requests cannot both win the window.
	 *
	 * @param int $post_id Post ID.
	 * @param int $seconds Window length; zero or less disables rate limiting.
	 * @return bool True when the view may be counted.
	 */
	protected static function check_rate_limit( $post_id, $seconds ) {
		$seconds = (int) $seconds;
		if ( $seconds <= 0 ) {
			return true;
		}

		$key = md5( self::get_client_ip() . '|' . (int) $post_id );
		$now = time();

		if ( wp_using_ext_object_cache() ) {
			return (bool) wp_cache_add( 'postviews_rate_' . $key, $now, 'post-views', $seconds );
		}

		$bucket = get_transient( 'postviews_rate_bucket' );
		if ( ! is_array( $bucket ) ) {
			$bucket = array();
		}

		foreach ( $bucket as $hash => $expires ) {
			if ( (int) $expires <= $now ) {
				unset( $bucket[ $hash ] );
			}
		}

		$allowed = ! isset( $bucket[ $key ] );

		if ( $allowed ) {
			$bucket[ $key ] = $now + $seconds;
		}

		set_transient( 'postviews_rate_bucket', $bucket, max( 300, $seconds ) );

		return $allowed;
	}

	/**
	 * Add one to a post's view count.
	 *
	 * The bump is a single atomic UPDATE rather than a read-modify-write:
	 * get_post_meta() then update_post_meta() loses a count whenever two
	 * requests overlap on the same post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $hook    Action fired with the new count.
	 * @return int The new count.
	 */
	protected static function increment( $post_id, $hook ) {
		if ( 1 === PostViews_Options::get_int( 'enable_buffer' ) && wp_using_ext_object_cache() ) {
			return self::buffer_increment( $post_id, $hook );
		}

		global $wpdb;

		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->postmeta} SET meta_value = meta_value + 1 WHERE post_id = %d AND meta_key = 'views'",
				$post_id
			)
		);

		if ( ! $updated ) {
			// No row to bump: seed one. If another request inserted it between
			// the failed UPDATE and this insert, add_post_meta() with $unique
			// fails and the UPDATE below adds this request's count on top.
			if ( ! add_post_meta( $post_id, 'views', 1, true ) ) {
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$wpdb->postmeta} SET meta_value = meta_value + 1 WHERE post_id = %d AND meta_key = 'views'",
						$post_id
					)
				);
			}
		}

		// Direct $wpdb writes bypass the meta cache invalidation that
		// update_post_meta() used to do, so the per-post entry is dropped
		// before the new value is read back.
		wp_cache_delete( $post_id, 'post_meta' );

		// Record daily view stats in dedicated table.
		PostViews_Stats::record_view( $post_id );

		$post_views = (int) get_post_meta( $post_id, 'views', true );

		/**
		 * Fires after a view has been recorded.
		 *
		 * @param int $post_views The new view count.
		 */
		do_action( $hook, $post_views );

		return $post_views;
	}

	/**
	 * Buffer view increment in the object cache for high-concurrency environments.
	 *
	 * Each post gets its own counter, incremented with wp_cache_incr() so the
	 * read-modify-write happens inside Redis/Memcached rather than in PHP. A
	 * second key keeps the list of posts with pending hits, which is what the
	 * flush walks; the old transient approach rewrote the whole pool on every
	 * request and lost counts whenever two requests overlapped.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $hook    Action hook.
	 * @return int Estimated current view count.
	 */
	public static function buffer_increment( $post_id, $hook ) {
		$post_id    = (int) $post_id;
		$buffer_key = 'postviews_buffer_' . $post_id;

		if ( ! wp_cache_add( $buffer_key, 1, 'post-views', HOUR_IN_SECONDS ) ) {
			wp_cache_incr( $buffer_key, 1, 'post-views' );
		}

		// A single running total makes the threshold check one cache read rather
		// than one per post with pending hits.
		if ( ! wp_cache_add( 'postviews_buffer_total', 1, 'post-views', HOUR_IN_SECONDS ) ) {
			wp_cache_incr( 'postviews_buffer_total', 1, 'post-views' );
		}

		$ids = wp_cache_get( 'postviews_buffer_ids', 'post-views' );
		if ( ! is_array( $ids ) ) {
			$ids = array();
		}

		if ( ! in_array( $post_id, $ids, true ) ) {
			$ids[] = $post_id;
			wp_cache_set( 'postviews_buffer_ids', $ids, 'post-views', HOUR_IN_SECONDS );
		}

		$current   = (int) get_post_meta( $post_id, 'views', true ) + (int) wp_cache_get( $buffer_key, 'post-views' );
		$threshold = max( 10, PostViews_Options::get_int( 'buffer_threshold', 50 ) );

		if ( self::get_buffered_count() >= $threshold ) {
			self::flush_buffer();
		}

		do_action( $hook, $current );
		return $current;
	}

	/**
	 * Bulk flush all buffered counts to postmeta and the daily stats table.
	 *
	 * @return int Total flushed view counts.
	 */
	public static function flush_buffer() {
		$ids = wp_cache_get( 'postviews_buffer_ids', 'post-views' );
		if ( empty( $ids ) || ! is_array( $ids ) ) {
			wp_cache_delete( 'postviews_buffer_ids', 'post-views' );
			return 0;
		}

		// Detach the list first so hits arriving during the flush start a new one.
		wp_cache_delete( 'postviews_buffer_ids', 'post-views' );

		global $wpdb;
		$total_flushed = 0;

		foreach ( $ids as $post_id ) {
			$post_id    = (int) $post_id;
			$buffer_key = 'postviews_buffer_' . $post_id;

			if ( $post_id <= 0 ) {
				wp_cache_delete( $buffer_key, 'post-views' );
				continue;
			}

			$hits = (int) wp_cache_get( $buffer_key, 'post-views' );

			if ( $hits > 0 && false === wp_cache_decr( $buffer_key, $hits, 'post-views' ) ) {
				wp_cache_delete( $buffer_key, 'post-views' );
			}

			if ( $hits <= 0 ) {
				continue;
			}

			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->postmeta} SET meta_value = meta_value + %d WHERE post_id = %d AND meta_key = 'views'",
					$hits,
					$post_id
				)
			);

			if ( ! $updated ) {
				if ( ! add_post_meta( $post_id, 'views', $hits, true ) ) {
					$wpdb->query(
						$wpdb->prepare(
							"UPDATE {$wpdb->postmeta} SET meta_value = meta_value + %d WHERE post_id = %d AND meta_key = 'views'",
							$hits,
							$post_id
						)
					);
				}
			}

			wp_cache_delete( $post_id, 'post_meta' );

			PostViews_Stats::record_views( $post_id, $hits );

			$total_flushed += $hits;
		}

		if ( $total_flushed > 0 ) {
			wp_cache_decr( 'postviews_buffer_total', $total_flushed, 'post-views' );
		}

		return $total_flushed;
	}

	/**
	 * Get total views currently pending in the buffer.
	 *
	 * @return int
	 */
	public static function get_buffered_count() {
		return (int) wp_cache_get( 'postviews_buffer_total', 'post-views' );
	}

	/**
	 * Handle manual flush via admin-post.php.
	 *
	 * @return void
	 */
	public static function handle_admin_flush_buffer() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}
		check_admin_referer( 'postviews_flush_buffer' );

		$flushed = self::flush_buffer();
		wp_safe_redirect( admin_url( 'options-general.php?page=post-views&flushed_buffer=' . (int) $flushed . '#tab-engine' ) );
		exit;
	}

	/**
	 * Register lightweight REST API endpoint for recording views.
	 *
	 * @return void
	 */
	public static function register_rest_route() {
		register_rest_route(
			'post-views/v1',
			'/hit',
			array(
				'methods'             => array( 'POST' ),
				'callback'            => array( __CLASS__, 'rest_hit' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * REST API hit callback.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function rest_hit( $request ) {
		$post_id = (int) $request->get_param( 'post_id' );
		if ( $post_id <= 0 ) {
			return new WP_REST_Response( array( 'error' => 'invalid_id' ), 400 );
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || ! is_post_status_viewable( $post->post_status ) ) {
			return new WP_REST_Response( array( 'error' => 'unviewable' ), 403 );
		}

		if ( ! self::is_countable_post_type( $post->post_type ) || ! self::should_count( $post_id ) ) {
			return new WP_REST_Response( array( 'error' => 'not_countable' ), 200 );
		}

		// Nothing loads this endpoint unless the browser is expected to report,
		// so a hit arriving on a site that counts during wp_head is not one of
		// ours.
		if ( ! self::needs_client_report() ) {
			return new WP_REST_Response( array( 'error' => 'not_countable' ), 200 );
		}

		$seconds = (int) apply_filters( 'postviews_rate_limit_seconds', 3 );
		if ( ! self::check_rate_limit( $post_id, $seconds ) ) {
			$current_views = (int) get_post_meta( $post_id, 'views', true );
			return new WP_REST_Response(
				array(
					'views'     => $current_views,
					'throttled' => true,
				),
				200
			);
		}

		$post_views = self::increment( $post_id, 'postviews_increment_views_ajax' );

		return new WP_REST_Response(
			array(
				'views' => $post_views,
			),
			200
		);
	}
}
