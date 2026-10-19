<?php
/**
 * Rendering the view count for a single post: the_views(), the [views]
 * shortcode, the display matrix that decides who sees a count on which kind of
 * page, and the two number helpers the templates use.
 *
 * @package Post-Views
 */

defined( 'ABSPATH' ) || exit;

/**
 * Single post view count output.
 */
class PostViews_Display {

	/**
	 * Flag to prevent recursion when reading raw post meta.
	 *
	 * @var bool
	 */
	private static $bypass_filter = false;

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public static function init() {
		add_shortcode( 'views', array( __CLASS__, 'shortcode' ) );
		add_shortcode( 'trending_views', array( __CLASS__, 'shortcode_trending' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_styles' ) );
		add_filter( 'the_content', array( __CLASS__, 'append_to_content' ) );
		add_filter( 'get_post_metadata', array( __CLASS__, 'filter_get_post_metadata' ), 10, 4 );

		// JustNews renders kuaixun detail pages through the_excerpt() rather
		// than the_content(), and the the_excerpt path proved unreliable on the
		// production site (some hook ordering or excerpt empty-state causes our
		// filter callback to be skipped). The fallback is a tiny DOM injector
		// that runs on the front end once the kuaixun page has loaded.
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_kuaixun_injector' ) );
	}

	/**
	 * Enqueue the kuaixun injector on kuaixun detail pages.
	 *
	 * The script is only registered/enqueued when the switch is on and the
	 * current request is a singular kuaixun view. It receives the post id
	 * and view count as a localized data blob and appends an inline span
	 * after the theme's .entry-content container. No positioning, no wrapper.
	 *
	 * @return void
	 */
	public static function maybe_enqueue_kuaixun_injector() {
		if ( ! is_singular( 'kuaixun' ) && 'kuaixun' !== get_post_type() ) {
			return;
		}
		if ( 1 !== PostViews_Options::get_int( 'display_kuaixun_views' ) ) {
			return;
		}

		$post_id = (int) get_queried_object_id();
		if ( $post_id <= 0 ) {
			$post_id = (int) get_the_ID();
		}
		if ( $post_id <= 0 ) {
			return;
		}

		$count         = self::get_display_views( $post_id );
		$rendered_html = self::render_count_template( $post_id );
		$rendered_html = apply_filters( 'the_views', $rendered_html );

		$script_file = dirname( WP_POSTVIEWS_MAIN_FILE ) . '/postviews-kuaixun.js';
		$script_ver  = WP_POSTVIEWS_VERSION . ( file_exists( $script_file ) ? '.' . filemtime( $script_file ) : '' );

		wp_register_script(
			'post-views-kuaixun',
			plugins_url( 'postviews-kuaixun.js', WP_POSTVIEWS_MAIN_FILE ),
			array(),
			$script_ver,
			true
		);

		wp_enqueue_script( 'post-views-kuaixun' );
		wp_localize_script(
			'post-views-kuaixun',
			'PostViewsKuaixun',
			array(
				'post_id' => $post_id,
				'count'   => $count,
				'html'    => $rendered_html,
			)
		);
	}

	/**
	 * Enqueue the front-end stylesheet.
	 *
	 * @return void
	 */
	public static function enqueue_styles() {
		$style_file = dirname( WP_POSTVIEWS_MAIN_FILE ) . '/postviews-front.css';
		$style_ver  = WP_POSTVIEWS_VERSION . ( file_exists( $style_file ) ? '.' . filemtime( $style_file ) : '' );

		wp_enqueue_style(
			'post-views-front',
			plugins_url( 'postviews-front.css', WP_POSTVIEWS_MAIN_FILE ),
			array(),
			$style_ver
		);
	}

	/**
	 * Automatically append the view count to single post/page content.
	 *
	 * @param string $content The post content.
	 * @return string
	 */
	public static function append_to_content( $content ) {
		if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		if ( ! self::should_be_displayed() ) {
			return $content;
		}

		// Check if current post type is enabled.
		$enabled_types = PostViews_Options::get( 'enabled_post_types', array( 'post', 'page' ) );
		if ( ! is_array( $enabled_types ) ) {
			$enabled_types = array( 'post', 'page' );
		}
		$current_post_type = get_post_type();

		// Strictly exclude kuaixun from automatic content insertion (kuaixun uses its dedicated floating injector).
		if ( 'kuaixun' === $current_post_type ) {
			return $content;
		}

		if ( ! in_array( $current_post_type, $enabled_types, true ) ) {
			return $content;
		}

		$views_html = self::render_count_template( get_the_ID() );

		/** This filter is documented in includes/class-postviews-display.php */
		$views_html = apply_filters( 'the_views', $views_html );

		if ( '' === trim( (string) $views_html ) ) {
			return $content;
		}

		$position = PostViews_Options::get( 'views_position', 'bottom-right' );
		if ( ! in_array( $position, array( 'bottom-right', 'bottom-left', 'top-right', 'top-left' ), true ) ) {
			$position = 'bottom-right';
		}

		$mode       = self::get_capsule_display_mode();
		$mode_class = ( 0 === strpos( $mode, 'stats_' ) ) ? 'pv-mode-stats' : 'pv-mode-views';

		// Wrap views in a positioned container according to user preference.
		$positioned_views = '<div class="pv-positioned-wrapper pv-pos-' . esc_attr( $position ) . ' ' . esc_attr( $mode_class ) . '" data-pv-mode="' . esc_attr( $mode ) . '" data-postviews-id="' . esc_attr( (string) get_the_ID() ) . '">' . $views_html . '</div>';

		if ( 'top-left' === $position || 'top-right' === $position ) {
			return $positioned_views . $content;
		}

		return $content . $positioned_views;
	}

	/**
	 * Render the view count for the current post.
	 *
	 * @param bool   $display Echo when true, return when false.
	 * @param string $prefix  Prepended to the rendered template.
	 * @param string $postfix Appended to the rendered template.
	 * @param bool   $always  Ignore the display matrix. Used by the admin column.
	 * @return string|void
	 */
	public static function the_views( $display = true, $prefix = '', $postfix = '', $always = false ) {
		if ( ! $always && ! self::should_be_displayed() ) {
			return $display ? null : '';
		}

		$output = $prefix . self::render_count_template( get_the_ID() ) . $postfix;

		/**
		 * Filters the rendered view count for a single post.
		 *
		 * @param string $output The rendered template.
		 */
		$output = apply_filters( 'the_views', $output );

		if ( ! $display ) {
			return $output;
		}

		// The template is HTML by design and is run through wp_kses_post() when
		// it is saved, so it is echoed as-is.
		echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * The [views] shortcode.
	 *
	 * Deliberately ignores the display matrix: dropping the shortcode into a
	 * post is an explicit request for the count, not a themed sidebar the
	 * matrix is there to govern.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$attributes = shortcode_atts( array( 'id' => 0 ), $atts );
		$id         = (int) $attributes['id'];

		if ( 0 === $id ) {
			$id = get_the_ID();
		}

		/** This filter is documented in includes/class-postviews-display.php */
		return apply_filters( 'the_views', self::render_count_template( $id ) );
	}

	/**
	 * The [trending_views] shortcode.
	 *
	 * Attributes: period ('today', 'week', 'month', 'all'), limit (default 10), mode ('post', 'page', etc.).
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function shortcode_trending( $atts ) {
		$attributes = shortcode_atts(
			array(
				'period'   => 'week',
				'limit'    => 10,
				'chars'    => 0,
				'mode'     => '',
				'order'    => 'desc',
				'category' => null,
				'tag'      => null,
			),
			$atts
		);

		return '<ul class="pv-top-list pv-trending-shortcode">' . PostViews_Query::render( $attributes ) . '</ul>';
	}

	/**
	 * Filter get_post_metadata for 'views' key on the front end.
	 *
	 * Ensures third-party themes calling get_post_meta($id, 'views', true)
	 * receive the calculated view count (actual * multiplier + offset).
	 *
	 * @param mixed  $value     Original value.
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Meta key being requested.
	 * @param bool   $single    Whether to return a single value.
	 * @return mixed
	 */
	public static function filter_get_post_metadata( $value, $object_id, $meta_key, $single = false ) {
		$target_keys = array( 'views', 'post_views_count', 'pageviews' );
		if ( ! in_array( $meta_key, $target_keys, true ) ) {
			return $value;
		}

		if ( self::$bypass_filter ) {
			return $value;
		}

		// Never filter in WordPress Admin or when saving.
		if ( is_admin() ) {
			return $value;
		}

		$display_views = self::get_display_views( $object_id );

		return $single ? (string) $display_views : array( (string) $display_views );
	}

	/**
	 * Get the final calculated display views for a post: (raw * multiplier) + offset.
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	public static function get_display_views( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return 0;
		}

		self::$bypass_filter = true;
		$post_views          = (int) get_post_meta( $post_id, 'views', true );

		// Fallback: check theme's view count meta keys (read-only, never overwrite).
		if ( 0 === $post_views ) {
			$source_keys = array( 'pageviews', 'post_views_count', 'post_views', '_post_views', '_post_views_count' );
			foreach ( $source_keys as $key ) {
				$fallback = (int) get_post_meta( $post_id, $key, true );
				if ( $fallback > 0 ) {
					$post_views = $fallback;
					break;
				}
			}
		}

		$offset     = (int) get_post_meta( $post_id, 'views_offset', true );
		$multiplier = (float) get_post_meta( $post_id, 'views_multiplier', true );
		self::$bypass_filter = false;

		if ( $multiplier <= 0 ) {
			$multiplier = (float) PostViews_Options::get( 'global_multiplier', 1.0 );
		}
		if ( $multiplier <= 0 ) {
			$multiplier = 1.0;
		}

		return (int) round( $post_views * $multiplier ) + $offset;
	}

	/**
	 * Substitute the count tokens into the single post template.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	/**
	 * Get current capsule display mode with backward compatibility.
	 *
	 * @return string One of: 'views_only', 'both', 'both_time', 'both_words', 'stats_only', 'stats_time', 'stats_words'.
	 */
	public static function get_capsule_display_mode() {
		$mode = PostViews_Options::get( 'capsule_display_mode', null );
		if ( null === $mode ) {
			// Fallback to legacy display_reading_stats option.
			$legacy = PostViews_Options::get( 'display_reading_stats', 0 );
			if ( 1 === (int) $legacy ) {
				return 'both';
			} elseif ( 2 === (int) $legacy ) {
				return 'both_time';
			} elseif ( 3 === (int) $legacy ) {
				return 'both_words';
			}
			return 'views_only';
		}

		$allowed = array( 'views_only', 'both', 'both_time', 'both_words', 'stats_only', 'stats_time', 'stats_words' );
		return in_array( $mode, $allowed, true ) ? $mode : 'views_only';
	}

	/**
	 * Substitute the count tokens into the single post template.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function render_count_template( $post_id ) {
		$post_views = self::get_display_views( $post_id );

		$stats         = self::get_reading_stats( $post_id );
		$read_time_str = sprintf( _n( '%d 分钟', '%d 分钟', $stats['minutes'], 'post-views' ), $stats['minutes'] );
		$words_str     = number_format_i18n( $stats['words'] );

		$template = (string) PostViews_Options::get( 'template', '' );
		if ( '' === trim( $template ) ) {
			$current_style = PostViews_Options::get( 'template_style', 'minimal-line' );
			$all_styles    = PostViews_Options::template_styles();
			$template      = isset( $all_styles[ $current_style ] ) ? $all_styles[ $current_style ] : PostViews_Options::default_template( 'template' );
		}

		// Check capsule content display mode (strictly excluding kuaixun).
		$mode = self::get_capsule_display_mode();
		if ( 'kuaixun' !== get_post_type( $post_id ) && $stats['words'] > 0 ) {
			if ( 0 === strpos( $mode, 'stats_' ) ) {
				// Reading stats only (no view count in capsule).
				$template = self::transform_template_for_stats_only( $template, $mode );
			} elseif ( 0 === strpos( $mode, 'both' ) ) {
				// Both view count and reading stats.
				if ( false === strpos( $template, '%READ_TIME%' ) && false === strpos( $template, '%WORD_COUNT%' ) ) {
					$template = self::inject_reading_stats_to_template( $template, $mode );
				}
			}
		}

		// wp_kses_post() at render, not just at save: the option row can also be
		// written by PostViews_Options::save() or a direct update_option(),
		// neither of which runs the settings screen's sanitize callback.
		return str_replace(
			array( '%VIEW_COUNT%', '%VIEW_COUNT_ROUNDED%', '%READ_TIME%', '%WORD_COUNT%' ),
			array( number_format_i18n( $post_views ), self::round_number( $post_views ), $read_time_str, $words_str ),
			wp_kses_post( $template )
		);
	}

	/**
	 * Transform a template for reading-stats-only mode (replaces views count elements with stats).
	 *
	 * @param string $template HTML template.
	 * @param string $mode     Mode: 'stats_only', 'stats_time', 'stats_words'.
	 * @return string
	 */
	public static function transform_template_for_stats_only( $template, $mode ) {
		if ( empty( $template ) || ! is_string( $template ) ) {
			return $template;
		}

		$is_time_only  = ( 'stats_time' === $mode );
		$is_words_only = ( 'stats_words' === $mode );

		// 1. modern-card: <span class="pv-label">阅读量</span><span class="pv-number">%VIEW_COUNT%</span>
		if ( false !== strpos( $template, 'pv-modern-card' ) ) {
			if ( $is_time_only ) {
				$rep = '<span class="pv-label">' . esc_html__( '预计阅读', 'post-views' ) . '</span><span class="pv-number">%READ_TIME%</span>';
			} elseif ( $is_words_only ) {
				$rep = '<span class="pv-label">' . esc_html__( '全文', 'post-views' ) . '</span><span class="pv-number">%WORD_COUNT%</span><span class="pv-reading-meta">' . esc_html__( '字', 'post-views' ) . '</span>';
			} else {
				$rep = '<span class="pv-label">' . esc_html__( '全文', 'post-views' ) . '</span><span class="pv-number">%WORD_COUNT%</span><span class="pv-reading-meta">' . esc_html__( '字', 'post-views' ) . ' <span class="pv-meta-dot">·</span> ' . esc_html__( '预计阅读', 'post-views' ) . ' %READ_TIME%</span>';
			}
			$pattern = '/<span class="pv-label">.*?<\/span>\s*<span class="pv-number">%VIEW_COUNT%<\/span>/u';
			if ( preg_match( $pattern, $template ) ) {
				return preg_replace( $pattern, $rep, $template, 1 );
			}
		}

		// 2. minimal-line: <span class="pv-text">%VIEW_COUNT% 次阅读</span>
		if ( false !== strpos( $template, 'pv-minimal-line' ) ) {
			if ( $is_time_only ) {
				$rep = '<span class="pv-text">' . esc_html__( '预计阅读', 'post-views' ) . ' %READ_TIME%</span>';
			} elseif ( $is_words_only ) {
				$rep = '<span class="pv-text">' . esc_html__( '全文约', 'post-views' ) . ' %WORD_COUNT% ' . esc_html__( '字', 'post-views' ) . '</span>';
			} else {
				$rep = '<span class="pv-text">' . esc_html__( '约', 'post-views' ) . ' %WORD_COUNT% ' . esc_html__( '字', 'post-views' ) . '<span class="pv-reading-meta"><span class="pv-meta-dot">·</span> ' . esc_html__( '预计阅读', 'post-views' ) . ' %READ_TIME%</span></span>';
			}
			$pattern = '/<span class="pv-text">%VIEW_COUNT%[^<]*<\/span>/u';
			if ( preg_match( $pattern, $template ) ) {
				return preg_replace( $pattern, $rep, $template, 1 );
			}
		}

		// 3. glass-card: <div class="pv-glass-text">本文阅读 <span class="pv-glass-count">%VIEW_COUNT%</span> 次</div>
		if ( false !== strpos( $template, 'pv-glass-card' ) ) {
			if ( $is_time_only ) {
				$rep = '<div class="pv-glass-text">' . esc_html__( '预计阅读', 'post-views' ) . ' <span class="pv-glass-count">%READ_TIME%</span></div>';
			} elseif ( $is_words_only ) {
				$rep = '<div class="pv-glass-text">' . esc_html__( '本文全文约', 'post-views' ) . ' <span class="pv-glass-count">%WORD_COUNT%</span> ' . esc_html__( '字', 'post-views' ) . '</div>';
			} else {
				$rep = '<div class="pv-glass-text">' . esc_html__( '本文约', 'post-views' ) . ' <span class="pv-glass-count">%WORD_COUNT%</span> ' . esc_html__( '字', 'post-views' ) . '<span class="pv-reading-meta"><span class="pv-meta-dot">·</span> ' . esc_html__( '预计阅读', 'post-views' ) . ' %READ_TIME%</span></div>';
			}
			$pattern = '/<div class="pv-glass-text">.*?<span class="pv-glass-count">%VIEW_COUNT%<\/span>.*?<\/div>/u';
			if ( preg_match( $pattern, $template ) ) {
				return preg_replace( $pattern, $rep, $template, 1 );
			}
		}

		// 4. gradient-badge: <span class="pv-badge-text"><strong>%VIEW_COUNT%</strong> 次热度</span>
		if ( false !== strpos( $template, 'pv-gradient-badge' ) ) {
			if ( $is_time_only ) {
				$rep = '<span class="pv-badge-text">' . esc_html__( '预计阅读', 'post-views' ) . ' <strong>%READ_TIME%</strong></span>';
			} elseif ( $is_words_only ) {
				$rep = '<span class="pv-badge-text">' . esc_html__( '全文约', 'post-views' ) . ' <strong>%WORD_COUNT%</strong> ' . esc_html__( '字', 'post-views' ) . '</span>';
			} else {
				$rep = '<span class="pv-badge-text"><strong>%WORD_COUNT%</strong> ' . esc_html__( '字', 'post-views' ) . '<span class="pv-reading-meta"><span class="pv-meta-dot">·</span> ' . esc_html__( '预计阅读', 'post-views' ) . ' %READ_TIME%</span></span>';
			}
			$pattern = '/<span class="pv-badge-text"><strong>%VIEW_COUNT%<\/strong>[^<]*<\/span>/u';
			if ( preg_match( $pattern, $template ) ) {
				return preg_replace( $pattern, $rep, $template, 1 );
			}
		}

		// 5. outline-chip: <span class="pv-chip-label">浏览</span><span class="pv-chip-count">%VIEW_COUNT%</span>
		if ( false !== strpos( $template, 'pv-outline-chip' ) ) {
			if ( $is_time_only ) {
				$rep = '<span class="pv-chip-label">' . esc_html__( '预计阅读', 'post-views' ) . '</span><span class="pv-chip-count">%READ_TIME%</span>';
			} elseif ( $is_words_only ) {
				$rep = '<span class="pv-chip-label">' . esc_html__( '字数', 'post-views' ) . '</span><span class="pv-chip-count">%WORD_COUNT%</span>';
			} else {
				$rep = '<span class="pv-chip-label">' . esc_html__( '字数', 'post-views' ) . '</span><span class="pv-chip-count">%WORD_COUNT%</span><span class="pv-reading-meta"><span class="pv-meta-dot">·</span> ' . esc_html__( '预计阅读', 'post-views' ) . ' %READ_TIME%</span>';
			}
			$pattern = '/<span class="pv-chip-label">.*?<\/span>\s*<span class="pv-chip-count">%VIEW_COUNT%<\/span>/u';
			if ( preg_match( $pattern, $template ) ) {
				return preg_replace( $pattern, $rep, $template, 1 );
			}
		}

		// 6. neon-pill: <span class="pv-neon-text">浏览 <strong>%VIEW_COUNT%</strong></span>
		if ( false !== strpos( $template, 'pv-neon-pill' ) ) {
			if ( $is_time_only ) {
				$rep = '<span class="pv-neon-text">' . esc_html__( '预计阅读', 'post-views' ) . ' <strong>%READ_TIME%</strong></span>';
			} elseif ( $is_words_only ) {
				$rep = '<span class="pv-neon-text"><strong>%WORD_COUNT%</strong> ' . esc_html__( '字', 'post-views' ) . '</span>';
			} else {
				$rep = '<span class="pv-neon-text"><strong>%WORD_COUNT%</strong> ' . esc_html__( '字', 'post-views' ) . '<span class="pv-reading-meta"><span class="pv-meta-dot">·</span> ' . esc_html__( '预计阅读', 'post-views' ) . ' %READ_TIME%</span></span>';
			}
			$pattern = '/<span class="pv-neon-text">.*?<strong>%VIEW_COUNT%<\/strong><\/span>/u';
			if ( preg_match( $pattern, $template ) ) {
				return preg_replace( $pattern, $rep, $template, 1 );
			}
		}

		// 7. sunset-pill: <span class="pv-sunset-text"><strong>%VIEW_COUNT%</strong> 次浏览</span>
		if ( false !== strpos( $template, 'pv-sunset-pill' ) ) {
			if ( $is_time_only ) {
				$rep = '<span class="pv-sunset-text">' . esc_html__( '预计阅读', 'post-views' ) . ' <strong>%READ_TIME%</strong></span>';
			} elseif ( $is_words_only ) {
				$rep = '<span class="pv-sunset-text"><strong>%WORD_COUNT%</strong> ' . esc_html__( '字', 'post-views' ) . '</span>';
			} else {
				$rep = '<span class="pv-sunset-text"><strong>%WORD_COUNT%</strong> ' . esc_html__( '字', 'post-views' ) . '<span class="pv-reading-meta"><span class="pv-meta-dot">·</span> ' . esc_html__( '预计阅读', 'post-views' ) . ' %READ_TIME%</span></span>';
			}
			$pattern = '/<span class="pv-sunset-text"><strong>%VIEW_COUNT%<\/strong>[^<]*<\/span>/u';
			if ( preg_match( $pattern, $template ) ) {
				return preg_replace( $pattern, $rep, $template, 1 );
			}
		}

		// 8. solid-dark-pill: <span class="pv-solid-text">阅读量 <strong>%VIEW_COUNT%</strong></span>
		if ( false !== strpos( $template, 'pv-solid-dark-pill' ) ) {
			if ( $is_time_only ) {
				$rep = '<span class="pv-solid-text">' . esc_html__( '预计阅读', 'post-views' ) . ' <strong>%READ_TIME%</strong></span>';
			} elseif ( $is_words_only ) {
				$rep = '<span class="pv-solid-text">' . esc_html__( '全文', 'post-views' ) . ' <strong>%WORD_COUNT%</strong> ' . esc_html__( '字', 'post-views' ) . '</span>';
			} else {
				$rep = '<span class="pv-solid-text">' . esc_html__( '全文', 'post-views' ) . ' <strong>%WORD_COUNT%</strong> ' . esc_html__( '字', 'post-views' ) . '<span class="pv-reading-meta"><span class="pv-meta-dot">·</span> ' . esc_html__( '预计阅读', 'post-views' ) . ' %READ_TIME%</span></span>';
			}
			$pattern = '/<span class="pv-solid-text">.*?<strong>%VIEW_COUNT%<\/strong><\/span>/u';
			if ( preg_match( $pattern, $template ) ) {
				return preg_replace( $pattern, $rep, $template, 1 );
			}
		}

		// 9. stat-box: <div class="pv-stat-value">%VIEW_COUNT_ROUNDED%</div><div class="pv-stat-label">浏览统计</div>
		if ( false !== strpos( $template, 'pv-stat-box' ) ) {
			if ( $is_time_only ) {
				$rep = '<div class="pv-stat-value">%READ_TIME%</div><div class="pv-stat-label">' . esc_html__( '预计阅读', 'post-views' ) . '</div>';
			} elseif ( $is_words_only ) {
				$rep = '<div class="pv-stat-value">%WORD_COUNT%</div><div class="pv-stat-label">' . esc_html__( '文章字数', 'post-views' ) . '</div>';
			} else {
				$rep = '<div class="pv-stat-value">%WORD_COUNT%</div><div class="pv-stat-label">' . esc_html__( '字数 · 预计阅读 ', 'post-views' ) . '%READ_TIME%</div>';
			}
			$pattern = '/<div class="pv-stat-value">%VIEW_COUNT_ROUNDED%<\/div>\s*<div class="pv-stat-label">.*?<\/div>/u';
			if ( preg_match( $pattern, $template ) ) {
				return preg_replace( $pattern, $rep, $template, 1 );
			}
		}

		// Generic fallback: replace %VIEW_COUNT% with stats.
		if ( $is_time_only ) {
			return str_replace( '%VIEW_COUNT%', '预计阅读 %READ_TIME%', $template );
		} elseif ( $is_words_only ) {
			return str_replace( '%VIEW_COUNT%', '%WORD_COUNT%', $template );
		}
		return str_replace( '%VIEW_COUNT%', '%WORD_COUNT% 字 · 预计阅读 %READ_TIME%', $template );
	}

	/**
	 * Automatically inject reading stats markup into a template if not manually present.
	 *
	 * @param string     $template     HTML template.
	 * @param string|int $reading_mode Display mode: 'both', 'both_time', 'both_words' or 1, 2, 3.
	 * @return string
	 */
	public static function inject_reading_stats_to_template( $template, $reading_mode ) {
		if ( empty( $template ) || ! is_string( $template ) ) {
			return $template;
		}

		$meta_text = '';
		if ( 'both_time' === $reading_mode || 2 === $reading_mode || '2' === $reading_mode ) {
			// Reading time only.
			$meta_text = '<span class="pv-reading-meta"><span class="pv-meta-dot">·</span> ' . esc_html__( '预计阅读', 'post-views' ) . ' %READ_TIME%</span>';
		} elseif ( 'both_words' === $reading_mode || 3 === $reading_mode || '3' === $reading_mode ) {
			// Word count only.
			$meta_text = '<span class="pv-reading-meta"><span class="pv-meta-dot">·</span> ' . esc_html__( '全文约', 'post-views' ) . ' %WORD_COUNT% ' . esc_html__( '字', 'post-views' ) . '</span>';
		} else {
			// Both word count and reading time.
			$meta_text = '<span class="pv-reading-meta"><span class="pv-meta-dot">·</span> ' . esc_html__( '约', 'post-views' ) . ' %WORD_COUNT% ' . esc_html__( '字', 'post-views' ) . ' <span class="pv-meta-dot">·</span> ' . esc_html__( '预计阅读', 'post-views' ) . ' %READ_TIME%</span>';
		}

		if ( empty( $meta_text ) ) {
			return $template;
		}

		// Targeted injection markers for the 9 built-in presets.
		$targets = array(
			// modern-card
			'<span class="pv-number">%VIEW_COUNT%</span>' => '<span class="pv-number">%VIEW_COUNT%</span>' . $meta_text,
			// minimal-line
			__( '次阅读', 'post-views' ) . '</span>' => __( '次阅读', 'post-views' ) . $meta_text . '</span>',
			'次阅读</span>' => '次阅读' . $meta_text . '</span>',
			// gradient-badge
			__( '次热度', 'post-views' ) . '</span>' => __( '次热度', 'post-views' ) . $meta_text . '</span>',
			'次热度</span>' => '次热度' . $meta_text . '</span>',
			// glass-card
			__( '次', 'post-views' ) . '</div>' => __( '次', 'post-views' ) . $meta_text . '</div>',
			'次</div>' => '次' . $meta_text . '</div>',
			// outline-chip
			'<span class="pv-chip-count">%VIEW_COUNT%</span>' => '<span class="pv-chip-count">%VIEW_COUNT%</span>' . $meta_text,
			// neon-pill & solid-dark-pill
			'<strong>%VIEW_COUNT%</strong>' => '<strong>%VIEW_COUNT%</strong>' . $meta_text,
			// sunset-pill
			__( '次浏览', 'post-views' ) . '</span>' => __( '次浏览', 'post-views' ) . $meta_text . '</span>',
			'次浏览</span>' => '次浏览' . $meta_text . '</span>',
			// stat-box
			'<div class="pv-stat-label">' . __( '浏览统计', 'post-views' ) . '</div>' => '<div class="pv-stat-label">' . __( '浏览统计', 'post-views' ) . ' ' . $meta_text . '</div>',
			'<div class="pv-stat-label">浏览统计</div>' => '<div class="pv-stat-label">浏览统计 ' . $meta_text . '</div>',
		);

		foreach ( $targets as $needle => $replacement ) {
			if ( false !== strpos( $template, $needle ) ) {
				return str_replace( $needle, $replacement, $template );
			}
		}

		// Generic fallback: inject right before the last closing tag.
		$last_close = strrpos( $template, '</' );
		if ( false !== $last_close ) {
			return substr_replace( $template, $meta_text . substr( $template, $last_close ), $last_close );
		}

		return $template . $meta_text;
	}

	/**
	 * Calculate word count and estimated reading time for a post.
	 *
	 * Supports both CJK characters and Latin alphabetic words.
	 *
	 * @param int $post_id Post ID.
	 * @return array Array with 'words' count and estimated 'minutes'.
	 */
	public static function get_reading_stats( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array(
				'words'   => 0,
				'minutes' => 1,
			);
		}

		$content = strip_shortcodes( $post->post_content );
		$content = wp_strip_all_tags( $content );

		// Count CJK characters.
		$cjk_count = preg_match_all( '/[\x{4e00}-\x{9fa5}]/u', $content, $matches );
		// Strip CJK to count Latin words cleanly.
		$non_cjk     = preg_replace( '/[\x{4e00}-\x{9fa5}]/u', ' ', $content );
		$latin_count = str_word_count( $non_cjk );

		$total_words = (int) ( $cjk_count + $latin_count );
		// Average reading speed configurable in settings (default: 300 words per minute).
		$wpm     = PostViews_Options::get_int( 'words_per_minute', 300 );
		$wpm     = $wpm > 0 ? $wpm : 300;
		$minutes = max( 1, (int) ceil( $total_words / $wpm ) );

		return array(
			'words'   => $total_words,
			'minutes' => $minutes,
		);
	}

	/**
	 * Whether a count should be shown on the page currently being rendered.
	 *
	 * Each context has its own setting: 0 shows it to everyone, 1 only to
	 * logged in users, 2 to nobody.
	 *
	 * Originally contributed as should_views_be_displayed() by David Potter.
	 *
	 * @return bool
	 */
	public static function should_be_displayed() {
		if ( is_home() ) {
			$key = 'display_home';
		} elseif ( is_single() ) {
			$key = 'display_single';
		} elseif ( is_page() ) {
			$key = 'display_page';
		} elseif ( is_archive() ) {
			$key = 'display_archive';
		} elseif ( is_search() ) {
			$key = 'display_search';
		} else {
			$key = 'display_other';
		}

		$display_option = PostViews_Options::get_int( $key );

		return 0 === $display_option || ( 1 === $display_option && is_user_logged_in() );
	}

	/**
	 * Abbreviate a number to K, M or B.
	 *
	 * The unit is chosen from the *rounded* value, not the raw one. Choosing it
	 * from the raw number lets rounding tip the result out of range: 999,950
	 * over a thousand rounds to 1000.0, which used to print as "1000K" instead
	 * of "1M".
	 *
	 * @param int $number    The number to abbreviate.
	 * @param int $min_value Below this the number is returned in full.
	 * @param int $decimal   Decimal places to keep.
	 * @return string
	 */
	public static function round_number( $number, $min_value = 1000, $decimal = 1 ) {
		if ( $number < $min_value ) {
			return number_format_i18n( $number );
		}

		$units = array(
			1000       => 'K',
			1000000    => 'M',
			1000000000 => 'B',
		);

		foreach ( $units as $divisor => $suffix ) {
			$rounded = round( $number / $divisor, $decimal );
			if ( $rounded < 1000 ) {
				return $rounded . $suffix;
			}
		}

		return round( $number / 1000000000, $decimal ) . 'B';
	}

	/**
	 * Truncate a title to a character count and HTML-encode it.
	 *
	 * The multibyte branch used to be gated on MB_OVERLOAD_STRING, which PHP
	 * 8.0 removed along with mbstring function overloading. Every title
	 * therefore went through substr(), which cuts on bytes: a CJK title chopped
	 * mid-character became invalid UTF-8 and htmlentities() returned an empty
	 * string, so the title vanished entirely.
	 *
	 * Encoding uses ENT_QUOTES rather than ENT_COMPAT: a title that survives
	 * into a single-quoted template attribute would otherwise carry a raw
	 * apostrophe and break out of the attribute.
	 *
	 * @param string $text   The title.
	 * @param int    $length Maximum length in characters. When used by listing helpers, passing 0 disables truncation at the caller level.
	 * @return string
	 */
	public static function snippet_text( $text, $length = 0 ) {
		$charset = get_option( 'blog_charset' );
		$text    = html_entity_decode( (string) $text, ENT_QUOTES, $charset );

		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
			$too_long = mb_strlen( $text, $charset ) > $length;
			$trimmed  = $too_long ? mb_substr( $text, 0, $length, $charset ) : $text;
		} else {
			$too_long = strlen( $text ) > $length;
			$trimmed  = $too_long ? substr( $text, 0, $length ) : $text;
		}

		return htmlentities( $trimmed, ENT_QUOTES, $charset ) . ( $too_long ? '...' : '' );
	}

	/**
	 * Total views across every post.
	 *
	 * @param bool $display Echo when true, return when false.
	 * @return int|void
	 */
	public static function get_totalviews( $display = true ) {
		global $wpdb;

		// Cache the aggregate so the full-table SUM is not re-run on every call
		// (it is typically rendered in a footer or stats area). Hourly refresh.
		$cache_key   = 'postviews_total_views';
		$total_views = get_transient( $cache_key );
		if ( false === $total_views ) {
			$total_views = (int) $wpdb->get_var( "SELECT SUM(meta_value+0) FROM $wpdb->postmeta WHERE meta_key = 'views'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			set_transient( $cache_key, $total_views, HOUR_IN_SECONDS );
		}

		if ( ! $display ) {
			return $total_views;
		}

		echo esc_html( number_format_i18n( $total_views ) );
	}
}
