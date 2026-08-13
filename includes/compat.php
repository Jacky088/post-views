<?php
/**
 * Compatibility shims for third-party themes and plugins that were written
 * against the original WP-Postviews API.
 *
 * The upstream plugin shipped two things the public classes here do not:
 *
 *  1. A global should_views_be_displayed( $options ) that took the full
 *     views_options array and decided, per context, whether a count was
 *     visible. JustNews calls it directly from single.php.
 *
 *  2. A the_posts filter that loaded each post's count into $post->views,
 *     which themes then read on listing cards. Without it that property is
 *     always absent and every card shows zero.
 *
 * Both are reconstructed here without touching the count, the templates or
 * the display matrix the rest of the plugin is built on.
 *
 * @package Post-Views
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'should_views_be_displayed' ) ) {
	/**
	 * Whether the view count should be shown in the current context.
	 *
	 * Mirrors the legacy global of the same name: it takes the views_options
	 * array as its argument and reads the display_* keys from it, so themes
	 * that were written against the original signature keep working. Falls
	 * back to the plugin's own option row when no array is supplied.
	 *
	 * @param array $options The views_options array, or anything falsy to use
	 *                       the plugin's stored settings.
	 * @return bool True when the count is visible in this context.
	 */
	function should_views_be_displayed( $options = array() ) {
		$options = is_array( $options ) && ! empty( $options ) ? $options : PostViews_Options::all();

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

		$mode = isset( $options[ $key ] ) ? (int) $options[ $key ] : 0;

		return 0 === $mode || ( 1 === $mode && is_user_logged_in() );
	}
}

if ( ! function_exists( 'postviews_load_views_into_posts' ) ) {
	/**
	 * Attach the the_posts filter that fills $post->views.
	 *
	 * Registered from init() so it is only active when the plugin decides it
	 * is wanted, and the callback is tagged with the expected precedence.
	 *
	 * @return void
	 */
	function postviews_load_views_into_posts() {
		// Front end only: admin listings have no cards reading $post->views,
		// and the extra meta batch load is wasted work there.
		if ( is_admin() ) {
			return;
		}

		add_filter( 'the_posts', 'postviews_populate_views_property', 10, 2 );
	}
}
add_action( 'init', 'postviews_load_views_into_posts' );

if ( ! function_exists( 'postviews_populate_views_property' ) ) {
	/**
	 * Copy each post's view count into $post->views.
	 *
	 * Themes like JustNews read `$post->views` on listing cards instead of
	 * calling get_post_meta(). The property does not exist on its own, so it
	 * is populated here in one batched query rather than a get_post_meta()
	 * call per post. Posts without a count keep the property unset so the
	 * theme's `?: 0` fallback still applies.
	 *
	 * @param array    $posts Posts returned by the query.
	 * @param WP_Query $query The query that produced them.
	 * @return array
	 */
	function postviews_populate_views_property( $posts, $query ) {
		if ( empty( $posts ) || ! is_array( $posts ) ) {
			return $posts;
		}

		$ids = wp_list_pluck( $posts, 'ID' );
		if ( empty( $ids ) ) {
			return $posts;
		}

		// Populate the meta cache for the whole batch up front so the
		// per-post get_post_meta() calls below never touch the database.
		update_meta_cache( 'post', $ids );

		foreach ( $posts as $post ) {
			$count = (int) get_post_meta( $post->ID, 'views', true );
			if ( $count > 0 ) {
				$post->views = $count;
			}
		}

		return $posts;
	}
}
