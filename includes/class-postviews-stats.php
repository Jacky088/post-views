<?php
/**
 * Time-based view statistics storage and queries.
 *
 * Maintains a dedicated, lightweight table wp_post_views_stats recording
 * (post_id, view_date, views). This supports Today, This Week, and This Month
 * leaderboards without polluting wp_postmeta with millions of rows.
 *
 * @package Post-Views
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles daily view stats tracking and period-based leaderboards.
 */
class PostViews_Stats {

	/**
	 * Table name without wpdb prefix.
	 *
	 * @var string
	 */
	const TABLE_NAME = 'post_views_stats';

	/**
	 * Transient holding the cached leaderboard results.
	 *
	 * The 'all' leaderboard joins the whole postmeta table, which is expensive on
	 * a site with tens of thousands of posts. One transient stores a map keyed
	 * by period/type/limit so the whole cache can be dropped in one call.
	 *
	 * @var string
	 */
	const LEADERBOARD_CACHE_KEY = 'postviews_leaderboard_cache';

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public static function init() {
		// Daily cleanup cron job for stats older than 35 days.
		add_action( 'postviews_daily_prune', array( __CLASS__, 'prune_old_stats' ) );
		if ( ! wp_next_scheduled( 'postviews_daily_prune' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'postviews_daily_prune' );
		}
	}

	/**
	 * Get full table name with prefix.
	 *
	 * @return string
	 */
	public static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_NAME;
	}

	/**
	 * Create or update the stats table schema.
	 *
	 * @return void
	 */
	public static function create_table() {
		global $wpdb;

		$table_name      = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			post_id bigint(20) unsigned NOT NULL,
			view_date date NOT NULL,
			views int(10) unsigned NOT NULL DEFAULT 1,
			PRIMARY KEY  (post_id, view_date),
			KEY idx_date (view_date),
			KEY idx_date_views (view_date, views)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Atomically record a view for the current date.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function record_view( $post_id ) {
		self::record_views( $post_id, 1 );
	}

	/**
	 * Atomically record several views for the current date.
	 *
	 * Used by the buffer flush so a batch of buffered hits becomes one statement
	 * instead of one per hit.
	 *
	 * @param int $post_id Post ID.
	 * @param int $views   Number of views to add.
	 * @return void
	 */
	public static function record_views( $post_id, $views = 1 ) {
		global $wpdb;

		$post_id = (int) $post_id;
		$views   = (int) $views;

		if ( $post_id <= 0 || $views <= 0 ) {
			return;
		}

		$table_name = self::get_table_name();
		$today      = current_time( 'Y-m-d' );

		// Atomic insert or increment.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table_name} (post_id, view_date, views)
				VALUES (%d, %s, %d)
				ON DUPLICATE KEY UPDATE views = views + VALUES(views)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$post_id,
				$today,
				$views
			)
		);
	}

	/**
	 * Get the date threshold for a given period.
	 *
	 * @param string $period 'today', 'week', 'month', or 'all'.
	 * @return string|null Start date in Y-m-d format, or null for 'all'.
	 */
	public static function get_period_start_date( $period ) {
		$today = current_time( 'Y-m-d' );

		switch ( $period ) {
			case 'today':
			case 'day':
				return $today;
			case 'week':
			case '7days':
				return gmdate( 'Y-m-d', strtotime( '-6 days', strtotime( $today ) ) );
			case 'month':
			case '30days':
				return gmdate( 'Y-m-d', strtotime( '-29 days', strtotime( $today ) ) );
			default:
				return null;
		}
	}

	/**
	 * Get top post IDs for a specific period.
	 *
	 * @param array $args Query arguments: 'period', 'limit', 'order', 'post_type'.
	 * @return array Array of post IDs.
	 */
	public static function get_top_post_ids( $args ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'period'    => 'week',
				'limit'     => 10,
				'order'     => 'desc',
				'post_type' => 'post',
			)
		);

		$start_date = self::get_period_start_date( $args['period'] );
		if ( null === $start_date ) {
			return array();
		}

		$table_name = self::get_table_name();
		$limit      = max( 1, (int) $args['limit'] );
		$order      = 'asc' === strtolower( (string) $args['order'] ) ? 'ASC' : 'DESC';

		$where_type = '';
		if ( ! empty( $args['post_type'] ) && 'any' !== $args['post_type'] ) {
			$post_types   = (array) $args['post_type'];
			$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
			$where_type   = $wpdb->prepare( "AND p.post_type IN ({$placeholders})", ...$post_types ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		$sql = $wpdb->prepare(
			"SELECT s.post_id, SUM(s.views) AS total_views
			FROM {$table_name} s
			INNER JOIN {$wpdb->posts} p ON p.ID = s.post_id
			WHERE s.view_date >= %s
			AND p.post_status = 'publish'
			{$where_type}
			GROUP BY s.post_id
			ORDER BY total_views {$order}
			LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$start_date,
			$limit
		);

		return $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Prune stats older than specified days to keep the table tiny.
	 *
	 * @param int $days Retention days. Default 35.
	 * @return int Number of deleted rows.
	 */
	public static function prune_old_stats( $days = 35 ) {
		global $wpdb;

		$table_name = self::get_table_name();
		$threshold  = gmdate( 'Y-m-d', strtotime( "-{$days} days", strtotime( current_time( 'Y-m-d' ) ) ) );

		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table_name} WHERE view_date < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$threshold
			)
		);
	}

	/**
	 * Get dashboard metrics summary.
	 *
	 * @return array Stats summary for today and the last 7 days.
	 */
	public static function get_dashboard_summary() {
		global $wpdb;

		$table_name = self::get_table_name();
		$today      = current_time( 'Y-m-d' );
		$week_start = gmdate( 'Y-m-d', strtotime( '-6 days', strtotime( $today ) ) );

		$today_views = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(views) FROM {$table_name} WHERE view_date = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$today
			)
		);

		$week_views = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(views) FROM {$table_name} WHERE view_date >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$week_start
			)
		);

		return array(
			'today_views' => $today_views,
			'week_views'  => $week_views,
		);
	}

	/**
	 * Get total row count in stats table.
	 *
	 * @return int
	 */
	public static function get_table_row_count() {
		global $wpdb;
		$table_name = self::get_table_name();
		$exists     = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
		if ( ! $exists ) {
			self::create_table();
		}
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Get comprehensive leaderboard posts array for admin display.
	 *
	 * @param string $period    'today', 'week', 'month', or 'all'.
	 * @param int    $limit     Number of posts to return.
	 * @param string $post_type Post type filter. Default 'post'.
	 * @return array Array of post data arrays.
	 */
	public static function get_leaderboard_posts( $period = 'week', $limit = 20, $post_type = 'post' ) {
		global $wpdb;

		$table_name = self::get_table_name();
		$limit      = max( 1, min( 100, (int) $limit ) );
		$where_type = '';

		$cache_key = $period . '|' . ( is_array( $post_type ) ? implode( ',', $post_type ) : (string) $post_type ) . '|' . $limit;
		$cache     = get_transient( self::LEADERBOARD_CACHE_KEY );

		if ( is_array( $cache ) && isset( $cache[ $cache_key ] ) ) {
			return $cache[ $cache_key ];
		}

		if ( ! empty( $post_type ) && 'any' !== $post_type ) {
			$post_types   = (array) $post_type;
			$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
			$where_type   = $wpdb->prepare( "AND p.post_type IN ({$placeholders})", ...$post_types ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( 'all' === $period ) {
			$sql = "SELECT p.ID as post_id, CAST(pm.meta_value AS UNSIGNED) as period_views, CAST(pm.meta_value AS UNSIGNED) as total_views
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'views'
				WHERE p.post_status = 'publish'
				{$where_type}
				ORDER BY period_views DESC
				LIMIT %d";
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} else {
			$start_date = self::get_period_start_date( $period );
			if ( null === $start_date ) {
				$start_date = self::get_period_start_date( 'week' );
			}

			$sql = $wpdb->prepare(
				"SELECT s.post_id, SUM(s.views) as period_views, CAST(pm.meta_value AS UNSIGNED) as total_views
				FROM {$table_name} s
				INNER JOIN {$wpdb->posts} p ON p.ID = s.post_id
				LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'views'
				WHERE s.view_date >= %s
				AND p.post_status = 'publish'
				{$where_type}
				GROUP BY s.post_id
				ORDER BY period_views DESC
				LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$start_date,
				$limit
			);
			$rows = $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		$results = array();

		foreach ( (array) $rows as $row ) {
			$pid  = (int) $row->post_id;
			$post = get_post( $pid );
			if ( ! $post ) {
				continue;
			}

			$raw_views  = (int) ( $row->total_views ?? get_post_meta( $pid, 'views', true ) );
			if ( 0 === $raw_views ) {
				foreach ( array( 'pageviews', 'post_views_count', 'post_views', '_post_views' ) as $legacy_key ) {
					$fb = (int) get_post_meta( $pid, $legacy_key, true );
					if ( $fb > 0 ) {
						$raw_views = $fb;
						break;
					}
				}
			}

			$offset     = (int) get_post_meta( $pid, 'views_offset', true );
			$multiplier = (float) get_post_meta( $pid, 'views_multiplier', true );
			if ( $multiplier <= 0 ) {
				$multiplier = (float) PostViews_Options::get( 'global_multiplier', 1.0 );
			}
			if ( $multiplier <= 0 ) {
				$multiplier = 1.0;
			}
			$calculated_total = (int) round( $raw_views * $multiplier ) + $offset;
			$period_views     = ( 'all' === $period ) ? $calculated_total : (int) $row->period_views;

			$thumb_url = get_the_post_thumbnail_url( $pid, 'thumbnail' );
			$results[] = array(
				'post_id'      => $pid,
				'title'        => get_the_title( $pid ),
				'period_views' => $period_views,
				'total_views'  => $calculated_total,
				'post_date'    => get_the_date( '', $pid ),
				'post_url'     => get_permalink( $pid ),
				'edit_url'     => get_edit_post_link( $pid ),
				'thumbnail'    => $thumb_url ? $thumb_url : '',
				'author'       => get_the_author_meta( 'display_name', $post->post_author ),
				'post_type'    => $post->post_type,
			);
		}

		$cache = is_array( $cache ) ? $cache : array();

		$cache[ $cache_key ] = $results;
		set_transient( self::LEADERBOARD_CACHE_KEY, $cache, 5 * MINUTE_IN_SECONDS );

		return $results;
	}

	/**
	 * Drop the cached leaderboard results.
	 *
	 * Called whenever the underlying numbers are rewritten wholesale, so the
	 * admin does not have to wait out the five-minute window.
	 *
	 * @return void
	 */
	public static function clear_leaderboard_cache() {
		delete_transient( self::LEADERBOARD_CACHE_KEY );
	}
}
