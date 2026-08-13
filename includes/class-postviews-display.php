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
	 * Hook registration.
	 *
	 * @return void
	 */
	public static function init() {
		add_shortcode( 'views', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_styles' ) );
		add_filter( 'the_content', array( __CLASS__, 'append_to_content' ) );

		// Third-party themes (JustNews) that render kuaixun detail pages through
		// the_excerpt() never hit the_content, so the auto-append above cannot
		// reach them. This hook injects the count into the kuaixun excerpt when
		// the dedicated option is on.
		add_filter( 'the_excerpt', array( __CLASS__, 'append_kuaixun_views_to_excerpt' ) );
	}

	/**
	 * Enqueue the front-end stylesheet.
	 *
	 * @return void
	 */
	public static function enqueue_styles() {
		wp_enqueue_style(
			'post-views-front',
			plugins_url( 'postviews-front.css', WP_POSTVIEWS_MAIN_FILE ),
			array(),
			WP_POSTVIEWS_VERSION
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
		if ( ! in_array( $current_post_type, $enabled_types, true ) ) {
			return $content;
		}

		$views_html = self::render_count_template( get_the_ID() );

		/** This filter is documented in includes/class-postviews-display.php */
		$views_html = apply_filters( 'the_views', $views_html );

		// Wrap views in a positioned container for bottom-right placement.
		$positioned_views = '<div class="pv-positioned-wrapper">' . $views_html . '</div>';

		return $content . $positioned_views;
	}

	/**
	 * Append the view count to a kuaixun detail page excerpt.
	 *
	 * JustNews renders kuaixun single pages through the_excerpt() rather than
	 * the_content(), so append_to_content() never runs there. When the
	 * display_kuaixun_views option is on, this appends a lightweight
	 * theme-styled count to the excerpt. Deliberately scoped to singular
	 * kuaixun pages: listing loops that call the_excerpt() elsewhere are left
	 * untouched, and the plain markup avoids the positioned-wrapper behaviour
	 * that previously broke the kuaixun layout.
	 *
	 * @param string $excerpt The post excerpt.
	 * @return string
	 */
	public static function append_kuaixun_views_to_excerpt( $excerpt ) {
		if ( 1 !== PostViews_Options::get_int( 'display_kuaixun_views' ) ) {
			return $excerpt;
		}

		if ( ! is_singular( 'kuaixun' ) ) {
			return $excerpt;
		}

		$count = (int) get_post_meta( get_the_ID(), 'views', true );

		// A minimal inline eye icon (SVG) plus the count, styled to blend with
		// the theme's own meta labels. Reuses the theme's item-meta-li class
		// name so any theme CSS that targets it applies.
		$html = '<span class="item-meta-li views kx-views">'
			. '<svg class="kx-views-icon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>'
			. number_format_i18n( $count )
			. '</span>';

		return $excerpt . $html;
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
	 * Substitute the count tokens into the single post template.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function render_count_template( $post_id ) {
		$post_views = (int) get_post_meta( $post_id, 'views', true );

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

		return str_replace(
			array( '%VIEW_COUNT%', '%VIEW_COUNT_ROUNDED%' ),
			array( number_format_i18n( $post_views ), self::round_number( $post_views ) ),
			(string) PostViews_Options::get( 'template', '' )
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
	 * @param string $text   The title.
	 * @param int    $length Maximum length in characters. 0 disables truncation.
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

		return htmlentities( $trimmed, ENT_COMPAT, $charset ) . ( $too_long ? '...' : '' );
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
