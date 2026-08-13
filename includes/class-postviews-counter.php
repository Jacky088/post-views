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
				if ( empty( $_COOKIE[ USER_COOKIE ] ) && 0 === $user_id ) {
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

		// Check if current post type is enabled.
		$enabled_types = PostViews_Options::get( 'enabled_post_types', array( 'post', 'page' ) );
		if ( ! is_array( $enabled_types ) ) {
			$enabled_types = array( 'post', 'page' );
		}
		// The kuaixun post type is intentionally absent from the picker (its
		// template bypasses the_content), but the display_kuaixun_views switch
		// lets JustNews users opt back into counting when they also want the
		// label rendered on the detail page.
		if ( ! in_array( $post->post_type, $enabled_types, true ) ) {
			if ( ! ( 'kuaixun' === $post->post_type && 1 === PostViews_Options::get_int( 'display_kuaixun_views' ) ) ) {
				return;
			}
		}

		if ( self::using_ajax() ) {
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
		if ( ! self::using_ajax() ) {
			return;
		}

		$post = self::current_post();
		if ( null === $post ) {
			return;
		}

		// Check if current post type is enabled.
		$enabled_types = PostViews_Options::get( 'enabled_post_types', array( 'post', 'page' ) );
		if ( ! is_array( $enabled_types ) ) {
			$enabled_types = array( 'post', 'page' );
		}
		// The kuaixun post type is intentionally absent from the picker (its
		// template bypasses the_content), but the display_kuaixun_views switch
		// lets JustNews users opt back into counting when they also want the
		// label rendered on the detail page.
		if ( ! in_array( $post->post_type, $enabled_types, true ) ) {
			if ( ! ( 'kuaixun' === $post->post_type && 1 === PostViews_Options::get_int( 'display_kuaixun_views' ) ) ) {
				return;
			}
		}

		if ( ! self::should_count( (int) $post->ID ) ) {
			return;
		}

		wp_enqueue_script(
			'post-views-cache',
			plugins_url( 'postviews-cache.js', WP_POSTVIEWS_MAIN_FILE ),
			array(),
			WP_POSTVIEWS_VERSION,
			true
		);
		wp_localize_script(
			'post-views-cache',
			'viewsCacheL10n',
			array(
				'admin_ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'          => wp_create_nonce( 'wp_postviews_nonce' ),
				'post_id'        => (int) $post->ID,
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

		if ( ! self::using_ajax() ) {
			return;
		}

		if ( empty( $_POST['postviews_id'] ) ) {
			return;
		}

		$post_id = (int) sanitize_key( wp_unslash( $_POST['postviews_id'] ) );

		// update_post_meta() creates a row whether or not the post exists, so
		// without this check a logged out visitor can walk the ID space and
		// grow wp_postmeta without bound.
		if ( $post_id <= 0 || ! get_post_status( $post_id ) ) {
			return;
		}

		// 防刷：同一 IP 在短时间窗口内对同一文章只计一次自增。
		// 不改变原有计数行为，仅在高频重复请求时跳过自增并返回当前值。
		$client_ip    = self::get_client_ip();
		$rate_key     = 'postviews_rate_' . md5( $client_ip . '|' . $post_id );
		$rate_blocked = get_transient( $rate_key );
		if ( false !== $rate_blocked ) {
			$current_views = (int) get_post_meta( $post_id, 'views', true );
			wp_send_json_success(
				array(
					'views'     => $current_views,
					'throttled' => true,
				)
			);
		}
		// 首次（或冷却已过）：记录 3 秒冷却标记。
		set_transient( $rate_key, time(), 3 );

		$post_views = self::increment( $post_id, 'postviews_increment_views_ajax' );

		wp_send_json_success( array( 'views' => $post_views ) );
	}

	/**
	 * Best-effort client IP for rate-limiting.
	 *
	 * Climbs the usual proxy headers in a defensive order and falls back to the
	 * direct remote address. Not authoritative for security - IPs are spoofable -
	 * but it is enough to blunt naive view-count inflation from a single host.
	 *
	 * @return string
	 */
	protected static function get_client_ip() {
		$candidates = array(
			'HTTP_CLIENT_IP',
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_FORWARDED',
			'HTTP_X_CLUSTER_CLIENT_IP',
			'HTTP_FORWARDED_FOR',
			'HTTP_FORWARDED',
			'REMOTE_ADDR',
		);

		foreach ( $candidates as $key ) {
			if ( empty( $_SERVER[ $key ] ) ) {
				continue;
			}
			$value = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
			// HTTP_X_FORWARDED_FOR may be a comma list; take the first.
			$parts = explode( ',', $value );
			$ip    = trim( $parts[0] );
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}

		return '0.0.0.0';
	}

	/**
	 * Add one to a post's view count.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $hook    Action fired with the new count.
	 * @return int The new count.
	 */
	protected static function increment( $post_id, $hook ) {
		$post_views = (int) get_post_meta( $post_id, 'views', true ) + 1;

		update_post_meta( $post_id, 'views', $post_views );

		/**
		 * Fires after a view has been recorded.
		 *
		 * @param int $post_views The new view count.
		 */
		do_action( $hook, $post_views );

		return $post_views;
	}
}
