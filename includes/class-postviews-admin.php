<?php
/**
 * Admin surface: the Views column on the posts and pages list tables, sorting
 * by it, and the WP-Stats integration.
 *
 * The settings screen itself lives in PostViews_Settings.
 *
 * @package Post-Views
 */

defined( 'ABSPATH' ) || exit;

/**
 * List table column and WP-Stats panels.
 */
class PostViews_Admin {

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'manage_posts_columns', array( __CLASS__, 'add_column' ) );
		add_filter( 'manage_pages_columns', array( __CLASS__, 'add_column' ) );
		add_action( 'manage_posts_custom_column', array( __CLASS__, 'render_column' ) );
		add_action( 'manage_pages_custom_column', array( __CLASS__, 'render_column' ) );

		add_filter( 'manage_edit-post_sortable_columns', array( __CLASS__, 'sortable_column' ) );
		add_filter( 'manage_edit-page_sortable_columns', array( __CLASS__, 'sortable_column' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'sort_by_column' ) );

		// WP-Stats registers its filters late, so hook them up once every
		// plugin has loaded.
		add_action( 'plugins_loaded', array( __CLASS__, 'register_wp_stats' ) );

		// Sync from theme views data.
		add_action( 'admin_post_postviews_sync_theme', array( __CLASS__, 'sync_theme_views' ) );
	}

	/**
	 * Bulk sync pageviews from JustNews theme to the views meta key.
	 *
	 * @return void
	 */
	public static function sync_theme_views() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}
		check_admin_referer( 'postviews_sync_theme' );

		global $wpdb;

		// Copy pageviews to views where views is missing or zero.
		$wpdb->query(
			"INSERT INTO $wpdb->postmeta (post_id, meta_key, meta_value)
			SELECT pm.post_id, 'views', pm.meta_value
			FROM $wpdb->postmeta pm
			LEFT JOIN $wpdb->postmeta pm2 ON pm2.post_id = pm.post_id AND pm2.meta_key = 'views'
			WHERE pm.meta_key = 'pageviews'
			AND pm.meta_value > 0
			AND (pm2.meta_id IS NULL)
			ON DUPLICATE KEY UPDATE meta_value = GREATEST(pm.meta_value + 0, $wpdb->postmeta.meta_value + 0)"
		);

		// Also update existing views = 0 rows.
		$wpdb->query(
			"UPDATE $wpdb->postmeta v
			INNER JOIN $wpdb->postmeta p ON p.post_id = v.post_id AND p.meta_key = 'pageviews'
			SET v.meta_value = p.meta_value
			WHERE v.meta_key = 'views'
			AND (v.meta_value + 0) = 0
			AND (p.meta_value + 0) > 0"
		);

		wp_safe_redirect( admin_url( 'options-general.php?page=post-views&synced=1' ) );
		exit;
	}

	/**
	 * Add the Views column.
	 *
	 * @param array $columns List table columns.
	 * @return array
	 */
	public static function add_column( $columns ) {
		$columns['views'] = __( 'Views', 'post-views' );

		return $columns;
	}

	/**
	 * Render the Views cell.
	 *
	 * $always is true because the display matrix governs the front end only.
	 * Letting it apply here blanked the admin column for anyone who had chosen
	 * "Don't display", which is what went wrong in 1.65.
	 *
	 * @param string $column_name The column being rendered.
	 * @return void
	 */
	public static function render_column( $column_name ) {
		if ( 'views' !== $column_name ) {
			return;
		}

		PostViews_Display::the_views( true, '', '', true );
	}

	/**
	 * Mark the Views column sortable.
	 *
	 * @param array $columns Sortable columns.
	 * @return array
	 */
	public static function sortable_column( $columns ) {
		$columns['views'] = 'views';

		return $columns;
	}

	/**
	 * Apply the sort when the Views column header is clicked.
	 *
	 * @param WP_Query $query The query being prepared.
	 * @return void
	 */
	public static function sort_by_column( $query ) {
		if ( ! is_admin() ) {
			return;
		}

		if ( 'views' !== $query->get( 'orderby' ) ) {
			return;
		}

		$query->set( 'meta_key', 'views' );
		$query->set( 'orderby', 'meta_value_num' );
	}

	/**
	 * Register the WP-Stats panels.
	 *
	 * @return void
	 */
	public static function register_wp_stats() {
		add_filter( 'wp_stats_display_defaults', array( __CLASS__, 'wp_stats_display_defaults' ) );
		add_filter( 'wp_stats_page_admin_plugins', array( __CLASS__, 'wp_stats_admin_general' ) );
		add_filter( 'wp_stats_page_admin_most', array( __CLASS__, 'wp_stats_admin_most' ) );
		add_filter( 'wp_stats_page_plugins', array( __CLASS__, 'wp_stats_general' ) );
		add_filter( 'wp_stats_page_most', array( __CLASS__, 'wp_stats_most' ) );
	}

	/**
	 * Tell WP-Stats about the toggles this plugin owns, and their defaults.
	 *
	 * Without this WP-Stats only learns a key exists once its checkbox has been
	 * submitted, so the panels would start out off on a fresh install.
	 *
	 * @param array $defaults Registered toggles.
	 * @return array
	 */
	public static function wp_stats_display_defaults( $defaults ) {
		// WP-Stats' own defaults win, so this only ever adds.
		return array_merge(
			array(
				'views'            => 1,
				'viewed_most_post' => 1,
				'viewed_most_page' => 0,
			),
			(array) $defaults
		);
	}

	/**
	 * Whether a WP-Stats display toggle is on.
	 *
	 * The option belongs to WP-Stats, which may not be installed at all.
	 *
	 * @param string $key Toggle name.
	 * @return bool
	 */
	protected static function wp_stats_enabled( $key ) {
		if ( function_exists( 'wp_stats_display_enabled' ) ) {
			return wp_stats_display_enabled( $key );
		}

		// WP-Stats before 3.0.0 kept the toggles in their own option row.
		$stats_display = get_option( 'stats_display' );

		if ( ! is_array( $stats_display ) ) {
			return false;
		}

		return 1 === (int) ( $stats_display[ $key ] ?? 0 );
	}

	/**
	 * How many "most" entries WP-Stats is configured to show.
	 *
	 * @return int
	 */
	protected static function wp_stats_limit() {
		if ( function_exists( 'wp_stats_most_limit' ) ) {
			return wp_stats_most_limit();
		}

		return (int) get_option( 'stats_mostlimit' );
	}

	/**
	 * One checkbox on the WP-Stats options screen.
	 *
	 * @param string $value   Checkbox value and id suffix.
	 * @param string $label   Visible label.
	 * @param bool   $checked Whether it is ticked.
	 * @return string
	 */
	protected static function wp_stats_checkbox( $value, $label, $checked ) {
		// WP-Stats 3.0.0 owns the field name, which changed when it consolidated
		// its option rows.
		if ( function_exists( 'wp_stats_checkbox' ) ) {
			return wp_stats_checkbox( $value, $label );
		}

		return '<input type="checkbox" name="stats_display[]" id="wpstats_' . esc_attr( $value ) . '" value="' . esc_attr( $value ) . '"' .
			checked( $checked, true, false ) .
			' />&nbsp;&nbsp;<label for="wpstats_' . esc_attr( $value ) . '">' . esc_html( $label ) . '</label><br />' . "\n";
	}

	/**
	 * The Post Views toggle on the WP-Stats options screen.
	 *
	 * @param string $content Accumulated markup.
	 * @return string
	 */
	public static function wp_stats_admin_general( $content ) {
		return $content . self::wp_stats_checkbox( 'views', __( 'Post Views', 'post-views' ), self::wp_stats_enabled( 'views' ) );
	}

	/**
	 * The most-viewed toggles on the WP-Stats options screen.
	 *
	 * @param string $content Accumulated markup.
	 * @return string
	 */
	public static function wp_stats_admin_most( $content ) {
		$limit = self::wp_stats_limit();

		$content .= self::wp_stats_checkbox(
			'viewed_most_post',
			/* translators: %s: number of posts. */
			sprintf( _n( '%s Most Viewed Post', '%s Most Viewed Posts', $limit, 'post-views' ), number_format_i18n( $limit ) ),
			self::wp_stats_enabled( 'viewed_most_post' )
		);
		$content .= self::wp_stats_checkbox(
			'viewed_most_page',
			/* translators: %s: number of pages. */
			sprintf( _n( '%s Most Viewed Page', '%s Most Viewed Pages', $limit, 'post-views' ), number_format_i18n( $limit ) ),
			self::wp_stats_enabled( 'viewed_most_page' )
		);

		return $content;
	}

	/**
	 * The total views paragraph on the WP-Stats page.
	 *
	 * @param string $content Accumulated markup.
	 * @return string
	 */
	public static function wp_stats_general( $content ) {
		if ( ! self::wp_stats_enabled( 'views' ) ) {
			return $content;
		}

		$total = PostViews_Display::get_totalviews( false );

		$content .= '<p><strong>' . esc_html__( 'Post Views', 'post-views' ) . '</strong></p>' . "\n";
		$content .= '<ul>' . "\n";
		$content .= '<li>' . wp_kses_post(
			sprintf(
				/* translators: %s: number of views. */
				_n( '<strong>%s</strong> view was generated.', '<strong>%s</strong> views were generated.', $total, 'post-views' ),
				number_format_i18n( $total )
			)
		) . '</li>' . "\n";
		$content .= '</ul>' . "\n";

		return $content;
	}

	/**
	 * The most viewed listings on the WP-Stats page.
	 *
	 * @param string $content Accumulated markup.
	 * @return string
	 */
	public static function wp_stats_most( $content ) {
		$limit = self::wp_stats_limit();

		$panels = array(
			'viewed_most_post' => array(
				'post',
				/* translators: %s: number of posts. */
				_n( '%s Most Viewed Post', '%s Most Viewed Posts', $limit, 'post-views' ),
			),
			'viewed_most_page' => array(
				'page',
				/* translators: %s: number of pages. */
				_n( '%s Most Viewed Page', '%s Most Viewed Pages', $limit, 'post-views' ),
			),
		);

		foreach ( $panels as $toggle => $panel ) {
			if ( ! self::wp_stats_enabled( $toggle ) ) {
				continue;
			}

			list( $post_type, $heading ) = $panel;

			$content .= '<p><strong>' . esc_html( sprintf( $heading, number_format_i18n( $limit ) ) ) . '</strong></p>' . "\n";
			$content .= '<ul>' . "\n";
			$content .= PostViews_Query::render(
				array(
					'mode'  => $post_type,
					'limit' => $limit,
					'order' => 'desc',
				)
			);
			$content .= '</ul>' . "\n";
		}

		return $content;
	}
}
