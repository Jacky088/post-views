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

		// Leaderboard seed and Migration handlers.
		add_action( 'admin_post_postviews_seed_period_stats', array( __CLASS__, 'seed_period_stats' ) );
		add_action( 'admin_post_postviews_migrate_legacy', array( __CLASS__, 'migrate_legacy_views' ) );

		// Dashboard widget.
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'add_dashboard_widget' ) );

		// Submenu page for Leaderboard.
		add_action( 'admin_menu', array( __CLASS__, 'add_admin_menu' ) );

		// Post edit screen meta box.
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post', array( __CLASS__, 'save_post_views_meta' ) );

		// Auto-assign random initial offset on new post publish.
		add_action( 'transition_post_status', array( __CLASS__, 'maybe_assign_random_offset' ), 10, 3 );
	}

	/**
	 * Seed stats table with existing posts that have views for initial baseline.
	 *
	 * @return void
	 */
	public static function seed_period_stats() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'post-views' ) );
		}
		check_admin_referer( 'postviews_seed_period_stats' );

		global $wpdb;
		PostViews_Stats::create_table();
		$table_name = PostViews_Stats::get_table_name();
		$today      = current_time( 'Y-m-d' );

		// Seed stats table with existing posts that have views.
		$seeded_count = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table_name} (post_id, view_date, views)
				SELECT pm.post_id, %s, (pm.meta_value + 0)
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key = 'views'
				AND (pm.meta_value + 0) > 0
				AND p.post_status = 'publish'
				ON DUPLICATE KEY UPDATE views = VALUES(views)",
				$today
			)
		);

		wp_safe_redirect( admin_url( 'edit.php?page=post-views-stats&seeded_stats=' . (int) $seeded_count ) );
		exit;
	}

	/**
	 * Add the Views column.
	 *
	 * @param array $columns List table columns.
	 * @return array
	 */
	public static function add_column( $columns ) {
		$columns['views'] = __( '浏览量', 'post-views' );

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

	/**
	 * Register Dashboard Widget.
	 *
	 * @return void
	 */
	public static function add_dashboard_widget() {
		if ( 0 === PostViews_Options::get_int( 'enable_dashboard_widget', 1 ) || ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'postviews_dashboard_widget',
			__( '文章浏览量统计 (Post Views)', 'post-views' ),
			array( __CLASS__, 'render_dashboard_widget' )
		);
	}

	/**
	 * Register admin submenu pages.
	 *
	 * @return void
	 */
	public static function add_admin_menu() {
		add_submenu_page(
			'edit.php',
			__( '文章浏览量排行榜', 'post-views' ),
			__( '浏览量排行', 'post-views' ),
			'edit_posts',
			'post-views-stats',
			array( __CLASS__, 'render_leaderboard_page' )
		);
	}

	/**
	 * Register meta box on post edit screens.
	 *
	 * @return void
	 */
	public static function add_meta_box() {
		if ( 0 === PostViews_Options::get_int( 'enable_meta_box', 1 ) ) {
			return;
		}

		$types = PostViews_Options::get( 'enabled_post_types', array( 'post', 'page' ) );
		foreach ( (array) $types as $type ) {
			add_meta_box(
				'postviews_meta_box',
				__( '浏览量设置 (Post Views)', 'post-views' ),
				array( __CLASS__, 'render_meta_box' ),
				$type,
				'side',
				'default'
			);
		}
	}

	/**
	 * Render post edit screen meta box.
	 *
	 * @param WP_Post $post Current post.
	 * @return void
	 */
	public static function render_meta_box( $post ) {
		wp_nonce_field( 'postviews_meta_box_save', 'postviews_meta_box_nonce' );
		$views      = (int) get_post_meta( $post->ID, 'views', true );
		$offset     = (int) get_post_meta( $post->ID, 'views_offset', true );
		$multiplier = (float) get_post_meta( $post->ID, 'views_multiplier', true );
		if ( $multiplier <= 0 ) {
			$multiplier = (float) PostViews_Options::get( 'global_multiplier', 1.0 );
		}
		if ( $multiplier <= 0 ) {
			$multiplier = 1.0;
		}

		$preview_total = (int) round( $views * $multiplier ) + $offset;
		?>
		<p>
			<label for="postviews_views"><strong><?php esc_html_e( '实际浏览量：', 'post-views' ); ?></strong></label>
			<input type="number" id="postviews_views" name="postviews_views" value="<?php echo esc_attr( $views ); ?>" min="0" style="width:100%;margin-top:4px;" />
			<small style="color:#646970;display:block;margin-top:2px;"><?php esc_html_e( '访客真实产生的浏览次数，可手动校正。', 'post-views' ); ?></small>
		</p>
		<p>
			<label for="postviews_offset"><strong><?php esc_html_e( '虚拟初始底数 (Offset)：', 'post-views' ); ?></strong></label>
			<input type="number" id="postviews_offset" name="postviews_offset" value="<?php echo esc_attr( $offset ); ?>" min="0" style="width:100%;margin-top:4px;" />
			<small style="color:#646970;display:block;margin-top:2px;"><?php esc_html_e( '新文冷启动基础曝光，直接累加到底数中。', 'post-views' ); ?></small>
		</p>
		<p>
			<label for="postviews_multiplier"><strong><?php esc_html_e( '虚拟放大倍率 (Multiplier)：', 'post-views' ); ?></strong></label>
			<input type="number" id="postviews_multiplier" name="postviews_multiplier" value="<?php echo esc_attr( $multiplier ); ?>" step="0.1" min="0.1" max="100" style="width:100%;margin-top:4px;" />
			<small style="color:#646970;display:block;margin-top:2px;"><?php esc_html_e( '每次真实访问放大的倍数（默认为 1.0 倍）。', 'post-views' ); ?></small>
		</p>
		<div style="background:#f0f6fc;border:1px solid #c8d8f8;padding:10px;border-radius:4px;margin-top:8px;">
			<div style="font-size:12px;color:#2271b1;font-weight:600;"><?php esc_html_e( '前台最终显示预估：', 'post-views' ); ?></div>
			<div style="font-size:18px;font-weight:700;color:#1d2327;margin-top:2px;" id="postviews_preview_calc">
				<?php echo esc_html( number_format_i18n( $preview_total ) ); ?> <?php esc_html_e( '次', 'post-views' ); ?>
			</div>
			<small style="color:#646970;display:block;margin-top:2px;font-size:11px;">
				<?php esc_html_e( '计算公式：(实际浏览量 × 虚拟倍率) + 虚拟底数', 'post-views' ); ?>
			</small>
		</div>
		<script>
		(function(){
			function updatePreview() {
				var v = parseFloat(document.getElementById('postviews_views').value) || 0;
				var o = parseFloat(document.getElementById('postviews_offset').value) || 0;
				var m = parseFloat(document.getElementById('postviews_multiplier').value) || 1.0;
				var total = Math.round(v * m) + o;
				document.getElementById('postviews_preview_calc').textContent = total.toLocaleString() + ' <?php esc_html_e( '次', 'post-views' ); ?>';
			}
			['postviews_views', 'postviews_offset', 'postviews_multiplier'].forEach(function(id){
				var el = document.getElementById(id);
				if (el) { el.addEventListener('input', updatePreview); }
			});
		})();
		</script>
		<?php
	}

	/**
	 * Save post views meta box data.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function save_post_views_meta( $post_id ) {
		if ( ! isset( $_POST['postviews_meta_box_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['postviews_meta_box_nonce'] ) ), 'postviews_meta_box_save' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( isset( $_POST['postviews_views'] ) ) {
			$new_views = max( 0, (int) $_POST['postviews_views'] );
			update_post_meta( $post_id, 'views', $new_views );
		}

		if ( isset( $_POST['postviews_offset'] ) ) {
			$new_offset = max( 0, (int) $_POST['postviews_offset'] );
			update_post_meta( $post_id, 'views_offset', $new_offset );
		}

		if ( isset( $_POST['postviews_multiplier'] ) ) {
			$new_mult = max( 0.1, min( 100.0, (float) $_POST['postviews_multiplier'] ) );
			update_post_meta( $post_id, 'views_multiplier', $new_mult );
		}
	}

	/**
	 * Automatically assign random initial offset for newly published posts if configured.
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Old post status.
	 * @param WP_Post $post       Post object.
	 * @return void
	 */
	public static function maybe_assign_random_offset( $new_status, $old_status, $post ) {
		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}

		if ( 1 !== PostViews_Options::get_int( 'auto_random_offset' ) ) {
			return;
		}

		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$enabled_types = PostViews_Options::get( 'enabled_post_types', array( 'post', 'page' ) );
		if ( ! in_array( $post->post_type, (array) $enabled_types, true ) ) {
			return;
		}

		$existing = get_post_meta( $post->ID, 'views_offset', true );
		if ( '' === $existing || false === $existing ) {
			$min = PostViews_Options::get_int( 'auto_offset_min', 100 );
			$max = PostViews_Options::get_int( 'auto_offset_max', 300 );
			if ( $min > $max ) {
				$tmp = $min; $min = $max; $max = $tmp;
			}
			$random_offset = wp_rand( $min, $max );
			update_post_meta( $post->ID, 'views_offset', $random_offset );
		}
	}

	/**
	 * One-click migration of legacy/third-party theme view counters into 'views'.
	 *
	 * @return void
	 */
	public static function migrate_legacy_views() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'post-views' ) );
		}
		check_admin_referer( 'postviews_migrate_legacy' );

		global $wpdb;
		$fallback_keys = array(
			'pageviews',
			'post_views_count',
			'post_views',
			'_post_views',
			'_post_views_count',
			'views_count',
			'tie_views',
			'wp_post_views',
		);
		$placeholders  = implode( ',', array_fill( 0, count( $fallback_keys ), '%s' ) );

		// Query posts that have legacy views > 0 and do NOT already have positive 'views' (> 0).
		// Uses MAX(pm.meta_value + 0) to safely pick the highest count if multiple legacy keys exist.
		$sql = $wpdb->prepare(
			"SELECT pm.post_id, MAX(pm.meta_value + 0) AS max_legacy_views
			FROM {$wpdb->postmeta} pm
			WHERE pm.meta_key IN ($placeholders)
			AND (pm.meta_value + 0) > 0
			AND NOT EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} p2
				WHERE p2.post_id = pm.post_id
				AND p2.meta_key = 'views'
				AND (p2.meta_value + 0) > 0
			)
			GROUP BY pm.post_id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			...$fallback_keys
		);

		$candidates = $wpdb->get_results( $sql );
		$migrated_count = 0;

		if ( ! empty( $candidates ) ) {
			foreach ( $candidates as $row ) {
				$post_id      = (int) $row->post_id;
				$legacy_views = (int) $row->max_legacy_views;

				if ( $post_id > 0 && $legacy_views > 0 ) {
					// Use update_post_meta: updates existing 0/empty meta in place, or inserts if none exists.
					// This prevents duplicate 'views' rows in wp_postmeta and refreshes WP post cache.
					update_post_meta( $post_id, 'views', $legacy_views );
					$migrated_count++;
				}
			}
		}

		delete_transient( 'postviews_total_views' );
		if ( function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( 'post-views' );
		}

		wp_safe_redirect( admin_url( 'options-general.php?page=post-views&migrated=' . (int) $migrated_count . '#tab-tools' ) );
		exit;
	}

	/**
	 * Scan database for third-party or legacy view count meta keys.
	 *
	 * @return array Associative array of meta_key => post_count.
	 */
	public static function get_legacy_meta_stats() {
		global $wpdb;
		$keys  = array(
			'pageviews',
			'post_views_count',
			'post_views',
			'_post_views',
			'_post_views_count',
			'views_count',
			'tie_views',
			'wp_post_views',
		);
		$stats = array();

		foreach ( $keys as $k ) {
			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s AND (meta_value + 0) > 0",
					$k
				)
			);
			if ( $count > 0 ) {
				$stats[ $k ] = $count;
			}
		}

		return $stats;
	}

	/**
	 * Render the Dashboard summary widget.
	 *
	 * @return void
	 */
	public static function render_dashboard_widget() {
		$summary   = PostViews_Stats::get_dashboard_summary();
		$top_today = PostViews_Stats::get_top_post_ids(
			array(
				'period' => 'today',
				'limit'  => 5,
			)
		);
		$top_week  = PostViews_Stats::get_top_post_ids(
			array(
				'period' => 'week',
				'limit'  => 5,
			)
		);
		?>
		<div class="pv-dashboard-summary" style="display:flex;gap:12px;margin-bottom:15px;">
			<div style="flex:1;background:#f0f6fc;padding:10px 14px;border-radius:6px;border-left:4px solid #2271b1;">
				<div style="font-size:12px;color:#646970;"><?php esc_html_e( '今日全站浏览', 'post-views' ); ?></div>
				<div style="font-size:20px;font-weight:bold;color:#1d2327;margin-top:2px;"><?php echo esc_html( number_format_i18n( $summary['today_views'] ) ); ?></div>
			</div>
			<div style="flex:1;background:#f0fdf4;padding:10px 14px;border-radius:6px;border-left:4px solid #16a34a;">
				<div style="font-size:12px;color:#646970;"><?php esc_html_e( '近 7 天浏览量', 'post-views' ); ?></div>
				<div style="font-size:20px;font-weight:bold;color:#1d2327;margin-top:2px;"><?php echo esc_html( number_format_i18n( $summary['week_views'] ) ); ?></div>
			</div>
		</div>

		<h4 style="margin:12px 0 6px 0;"><?php esc_html_e( '今日最热 Top 5', 'post-views' ); ?></h4>
		<?php if ( empty( $top_today ) ) : ?>
			<p style="color:#646970;font-size:13px;"><?php esc_html_e( '今日暂无统计数据', 'post-views' ); ?></p>
		<?php else : ?>
			<ul style="margin:0 0 12px 0;">
				<?php foreach ( $top_today as $pid ) : ?>
					<li style="margin-bottom:4px;display:flex;justify-content:space-between;">
						<a href="<?php echo esc_url( get_edit_post_link( $pid ) ); ?>" style="text-decoration:none;"><?php echo esc_html( get_the_title( $pid ) ); ?></a>
						<span style="color:#646970;font-size:12px;"><?php echo esc_html( number_format_i18n( (int) get_post_meta( $pid, 'views', true ) ) ); ?> <?php esc_html_e( '次', 'post-views' ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<h4 style="margin:12px 0 6px 0;"><?php esc_html_e( '本周最热 Top 5', 'post-views' ); ?></h4>
		<?php if ( empty( $top_week ) ) : ?>
			<p style="color:#646970;font-size:13px;"><?php esc_html_e( '本周暂无统计数据', 'post-views' ); ?></p>
		<?php else : ?>
			<ul style="margin:0 0 10px 0;">
				<?php foreach ( $top_week as $pid ) : ?>
					<li style="margin-bottom:4px;display:flex;justify-content:space-between;">
						<a href="<?php echo esc_url( get_edit_post_link( $pid ) ); ?>" style="text-decoration:none;"><?php echo esc_html( get_the_title( $pid ) ); ?></a>
						<span style="color:#646970;font-size:12px;"><?php echo esc_html( number_format_i18n( (int) get_post_meta( $pid, 'views', true ) ) ); ?> <?php esc_html_e( '次', 'post-views' ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<p style="text-align:right;margin:10px 0 0 0;font-size:12px;">
			<a href="<?php echo esc_url( admin_url( 'edit.php?page=post-views-stats' ) ); ?>" style="text-decoration:none;font-weight:600;">
				<?php esc_html_e( '查看完整排行榜 &raquo;', 'post-views' ); ?>
			</a>
		</p>
		<?php
	}

	/**
	 * Render the dedicated Leaderboard page in WordPress Admin.
	 *
	 * @return void
	 */
	public static function render_leaderboard_page() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( '无权访问此页面', 'post-views' ) );
		}

		$total_rows     = PostViews_Stats::get_table_row_count();
		$default_period = ( $total_rows > 0 ) ? 'week' : 'all';
		$period         = isset( $_GET['period'] ) ? sanitize_key( $_GET['period'] ) : $default_period;
		$post_type      = isset( $_GET['post_type'] ) ? sanitize_key( $_GET['post_type'] ) : 'post';
		$valid_periods  = array( 'today', 'week', 'month', 'all' );
		if ( ! in_array( $period, $valid_periods, true ) ) {
			$period = $default_period;
		}

		$summary = PostViews_Stats::get_dashboard_summary();
		$posts   = PostViews_Stats::get_leaderboard_posts( $period, 30, $post_type );

		$period_labels = array(
			'today' => __( '今日热门榜', 'post-views' ),
			'week'  => __( '本周热门榜 (近7天)', 'post-views' ),
			'month' => __( '本月热门榜 (近30天)', 'post-views' ),
			'all'   => __( '历史总榜 (全量累计)', 'post-views' ),
		);
		?>
		<div class="wrap pv-admin-wrap">
			<!-- Header -->
			<div class="pv-header">
				<div class="pv-header-left">
					<div class="pv-header-icon">
						<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
							<rect x="3" y="12" width="4" height="9" rx="2" fill="#2563eb"/>
							<rect x="10" y="7" width="4" height="14" rx="2" fill="#2563eb"/>
							<rect x="17" y="3" width="4" height="18" rx="2" fill="#2563eb"/>
						</svg>
					</div>
					<h1 class="pv-header-title"><?php esc_html_e( '文章浏览量排行榜 (Post Views Leaderboards)', 'post-views' ); ?></h1>
					<span class="pv-badge">v<?php echo esc_html( WP_POSTVIEWS_VERSION ); ?></span>
				</div>
				<div class="pv-header-right">
					<?php if ( current_user_can( 'manage_options' ) ) : ?>
						<a href="<?php echo esc_url( admin_url( 'options-general.php?page=post-views' ) ); ?>" class="pv-btn-primary">
							<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
								<circle cx="12" cy="12" r="3"></circle>
								<path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
							</svg>
							<?php esc_html_e( '打开浏览量设置面板', 'post-views' ); ?> &raquo;
						</a>
					<?php endif; ?>
				</div>
			</div>

			<!-- Notices -->
			<?php if ( isset( $_GET['seeded_stats'] ) ) : ?>
				<div class="notice notice-success is-dismissible" style="margin: 0 0 20px 0; border-radius: 8px;"><p><?php printf( esc_html__( '成功同步初始化！已为 %d 篇文章生成今日周期排行数据。', 'post-views' ), (int) $_GET['seeded_stats'] ); ?></p></div>
			<?php endif; ?>

			<!-- Summary Metric Cards -->
			<div class="pv-metrics-grid" style="margin-top:0;margin-bottom:20px;">
				<div class="pv-metric-card pv-metric-blue">
					<div class="pv-metric-top">
						<span class="pv-metric-label"><?php esc_html_e( '今日全站阅读量', 'post-views' ); ?></span>
						<span class="pv-metric-chip pv-chip-blue"><?php esc_html_e( '实时统计', 'post-views' ); ?></span>
					</div>
					<div class="pv-metric-value"><?php echo esc_html( number_format_i18n( $summary['today_views'] ) ); ?> <span class="pv-metric-unit"><?php esc_html_e( '次', 'post-views' ); ?></span></div>
					<div class="pv-metric-subtext"><?php esc_html_e( '今日访客产生的高精独立阅读聚合', 'post-views' ); ?></div>
				</div>
				<div class="pv-metric-card pv-metric-green">
					<div class="pv-metric-top">
						<span class="pv-metric-label"><?php esc_html_e( '近 7 天全站阅读量', 'post-views' ); ?></span>
						<span class="pv-metric-chip pv-chip-green"><?php esc_html_e( '周度趋势', 'post-views' ); ?></span>
					</div>
					<div class="pv-metric-value"><?php echo esc_html( number_format_i18n( $summary['week_views'] ) ); ?> <span class="pv-metric-unit"><?php esc_html_e( '次', 'post-views' ); ?></span></div>
					<div class="pv-metric-subtext"><?php esc_html_e( '过去 7 天连续周期热度统计累加', 'post-views' ); ?></div>
				</div>
				<div class="pv-metric-card pv-metric-purple">
					<div class="pv-metric-top">
						<span class="pv-metric-label"><?php esc_html_e( '周期独立统计表状态', 'post-views' ); ?></span>
						<span class="pv-metric-chip pv-chip-purple"><?php esc_html_e( '自动运维', 'post-views' ); ?></span>
					</div>
					<div class="pv-metric-value"><?php echo esc_html( number_format_i18n( $total_rows ) ); ?> <span class="pv-metric-unit"><?php esc_html_e( '行记录', 'post-views' ); ?></span></div>
					<div class="pv-metric-subtext"><?php esc_html_e( '已开启 35 天高频数据滚动自动归档', 'post-views' ); ?></div>
				</div>
			</div>

			<?php if ( 0 === $total_rows && current_user_can( 'manage_options' ) ) : ?>
				<div class="pv-callout" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px;margin-bottom:20px;">
					<div style="flex:1;min-width:280px;">
						<div class="pv-callout-title">
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
							<?php esc_html_e( '新功能提示：周期排行榜已成功就绪', 'post-views' ); ?>
						</div>
						<div class="pv-callout-body">
							<?php esc_html_e( '2.1.0 引入了独立的轻量周期统计表，新访客浏览文章将自动实时累加至今日与本周。若您希望当前周期榜单立即有数据展示，可点击右侧按钮，一键将现有文章的历史阅读量同步为初始基准。', 'post-views' ); ?>
						</div>
					</div>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;">
						<input type="hidden" name="action" value="postviews_seed_period_stats" />
						<?php wp_nonce_field( 'postviews_seed_period_stats' ); ?>
						<button type="submit" class="pv-btn-primary">
							⚡ <?php esc_html_e( '一键同步历史数据为初始基准', 'post-views' ); ?>
						</button>
					</form>
				</div>
			<?php endif; ?>

			<!-- Filter Tabs -->
			<div class="pv-nav-tabs">
				<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'post-views-stats', 'period' => 'today', 'post_type' => $post_type ), admin_url( 'edit.php' ) ) ); ?>" class="pv-nav-tab <?php echo 'today' === $period ? 'pv-nav-active' : ''; ?>">
					<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
					<?php esc_html_e( '今日热门榜', 'post-views' ); ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'post-views-stats', 'period' => 'week', 'post_type' => $post_type ), admin_url( 'edit.php' ) ) ); ?>" class="pv-nav-tab <?php echo 'week' === $period ? 'pv-nav-active' : ''; ?>">
					<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
					<?php esc_html_e( '本周热门榜 (近7天)', 'post-views' ); ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'post-views-stats', 'period' => 'month', 'post_type' => $post_type ), admin_url( 'edit.php' ) ) ); ?>" class="pv-nav-tab <?php echo 'month' === $period ? 'pv-nav-active' : ''; ?>">
					<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
					<?php esc_html_e( '本月热门榜 (近30天)', 'post-views' ); ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'post-views-stats', 'period' => 'all', 'post_type' => $post_type ), admin_url( 'edit.php' ) ) ); ?>" class="pv-nav-tab <?php echo 'all' === $period ? 'pv-nav-active' : ''; ?>">
					<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
					<?php esc_html_e( '历史总榜 (全量累计)', 'post-views' ); ?>
				</a>
			</div>

			<!-- Ranking Card -->
			<div class="pv-card" style="padding:0;overflow:hidden;">
				<div class="pv-card-head" style="padding:20px 24px 16px 24px;border-bottom:1px solid #f1f5f9;margin-bottom:0;">
					<div class="pv-card-icon">
						<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
					</div>
					<div class="pv-card-title-group">
						<h2 class="pv-card-title">
							<?php echo esc_html( $period_labels[ $period ] ); ?>
							<span class="pv-card-subtitle"><?php esc_html_e( '(TOP 30 热门排行)', 'post-views' ); ?></span>
						</h2>
						<p class="pv-card-desc"><?php esc_html_e( '基于独立轻量统计表与元数据综合运算，实时呈现当前统计周期内全站最受访客喜爱的文章。', 'post-views' ); ?></p>
					</div>
				</div>

				<?php if ( empty( $posts ) ) : ?>
					<div class="pv-table-empty">
						<div class="pv-empty-icon">
							<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
						</div>
						<h3 class="pv-empty-title"><?php esc_html_e( '该周期下暂无阅读数据记录', 'post-views' ); ?></h3>
						<p class="pv-empty-desc"><?php esc_html_e( '周期榜单（今日/本周/本月）为全新独立高精统计表，随着访客在前台浏览文章将实时自动累加生成。', 'post-views' ); ?></p>
						<?php if ( 'all' !== $period ) : ?>
							<div class="pv-empty-actions">
								<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'post-views-stats', 'period' => 'all' ), admin_url( 'edit.php' ) ) ); ?>" class="pv-btn-outline" style="font-size:13px;padding:8px 16px;">
									<?php esc_html_e( '查看历史总榜 (全量累计) &raquo;', 'post-views' ); ?>
								</a>
								<?php if ( current_user_can( 'manage_options' ) ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;margin:0;">
										<input type="hidden" name="action" value="postviews_seed_period_stats" />
										<?php wp_nonce_field( 'postviews_seed_period_stats' ); ?>
										<button type="submit" class="pv-btn-primary">
											⚡ <?php esc_html_e( '一键同步现有文章历史阅读量为初始基准', 'post-views' ); ?>
										</button>
									</form>
								<?php endif; ?>
							</div>
						<?php endif; ?>
					</div>
				<?php else : ?>
					<div class="pv-table-wrap">
						<table class="pv-leaderboard-table">
							<thead>
								<tr>
									<th style="width:75px;text-align:center;"><?php esc_html_e( '排名', 'post-views' ); ?></th>
									<th style="width:65px;text-align:center;"><?php esc_html_e( '封面', 'post-views' ); ?></th>
									<th><?php esc_html_e( '文章标题', 'post-views' ); ?></th>
									<th style="width:160px;"><?php echo esc_html( $period_labels[ $period ] ); ?></th>
									<th style="width:130px;"><?php esc_html_e( '历史累计', 'post-views' ); ?></th>
									<th style="width:120px;"><?php esc_html_e( '作者', 'post-views' ); ?></th>
									<th style="width:170px;min-width:160px;text-align:right;padding-right:24px;white-space:nowrap;"><?php esc_html_e( '发布日期', 'post-views' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $posts as $index => $item ) : ?>
									<?php
									$rank = $index + 1;
									$rank_class = 'pv-rank-other';
									if ( 1 === $rank ) {
										$rank_class = 'pv-rank-1';
									} elseif ( 2 === $rank ) {
										$rank_class = 'pv-rank-2';
									} elseif ( 3 === $rank ) {
										$rank_class = 'pv-rank-3';
									}
									?>
									<tr class="pv-row-<?php echo esc_attr( $rank ); ?>">
										<td style="text-align:center;">
											<span class="pv-rank-badge <?php echo esc_attr( $rank_class ); ?>"><?php echo esc_html( $rank ); ?></span>
										</td>
										<td style="text-align:center;">
											<?php if ( ! empty( $item['thumbnail'] ) ) : ?>
												<img src="<?php echo esc_url( $item['thumbnail'] ); ?>" class="pv-cover-thumb" alt="" />
											<?php else : ?>
												<div class="pv-cover-thumb pv-cover-placeholder">
													<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
												</div>
											<?php endif; ?>
										</td>
										<td>
											<div class="pv-post-title-wrap">
												<a href="<?php echo esc_url( $item['edit_url'] ); ?>" class="pv-post-title" title="<?php echo esc_attr( $item['title'] ); ?>">
													<?php echo esc_html( $item['title'] ); ?>
												</a>
												<div class="pv-post-actions">
													<a href="<?php echo esc_url( $item['edit_url'] ); ?>"><?php esc_html_e( '编辑', 'post-views' ); ?></a>
													<span class="pv-sep">·</span>
													<a href="<?php echo esc_url( $item['post_url'] ); ?>" target="_blank"><?php esc_html_e( '查看文章', 'post-views' ); ?> &nearr;</a>
												</div>
											</div>
										</td>
										<td>
											<span class="pv-period-views"><?php echo esc_html( number_format_i18n( $item['period_views'] ) ); ?></span>
											<span class="pv-views-unit"><?php esc_html_e( '次阅读', 'post-views' ); ?></span>
										</td>
										<td>
											<span class="pv-total-views"><?php echo esc_html( number_format_i18n( $item['total_views'] ) ); ?></span>
											<span class="pv-views-unit"><?php esc_html_e( '次', 'post-views' ); ?></span>
										</td>
										<td>
											<span class="pv-author-badge">
												<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
												<?php echo esc_html( $item['author'] ); ?>
											</span>
										</td>
										<td style="text-align:right;padding-right:24px;white-space:nowrap;">
											<span class="pv-post-date"><?php echo esc_html( $item['post_date'] ); ?></span>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			</div>
		</div>

		<style>
			/* Container matching settings */
			.pv-admin-wrap {
				width: auto;
				max-width: none;
				margin: 20px 20px 40px 0;
				font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
				color: #1e293b;
				box-sizing: border-box;
			}
			.pv-admin-wrap * {
				box-sizing: border-box;
			}

			/* Header */
			.pv-header {
				display: flex;
				align-items: center;
				justify-content: space-between;
				flex-wrap: wrap;
				gap: 16px;
				margin-bottom: 20px;
			}
			.pv-header-left {
				display: flex;
				align-items: center;
				gap: 10px;
			}
			.pv-header-icon {
				display: inline-flex;
				align-items: center;
				justify-content: center;
			}
			.pv-header-title {
				font-size: 22px;
				font-weight: 700;
				color: #0f172a;
				margin: 0;
				line-height: 1.2;
			}
			.pv-badge {
				background: #eff6ff;
				color: #2563eb;
				border: 1px solid #bfdbfe;
				font-size: 12px;
				font-weight: 600;
				padding: 2px 10px;
				border-radius: 9999px;
			}
			.pv-btn-primary {
				background: #2563eb;
				color: #ffffff !important;
				padding: 8px 18px;
				border-radius: 8px;
				font-weight: 500;
				font-size: 13px;
				text-decoration: none !important;
				display: inline-flex;
				align-items: center;
				gap: 6px;
				box-shadow: 0 2px 4px rgba(37, 99, 235, 0.25);
				transition: all 0.2s ease;
				border: none;
				cursor: pointer;
			}
			.pv-btn-primary:hover {
				background: #1d4ed8;
				box-shadow: 0 4px 8px rgba(37, 99, 235, 0.35);
				transform: translateY(-1px);
			}
			.pv-btn-outline {
				background: #ffffff;
				border: 1px solid #cbd5e1;
				border-radius: 6px;
				padding: 6px 14px;
				font-size: 12px;
				color: #475569;
				cursor: pointer;
				text-decoration: none !important;
				display: inline-flex;
				align-items: center;
				gap: 4px;
				transition: all 0.15s ease;
			}
			.pv-btn-outline:hover {
				background: #f1f5f9;
				color: #0f172a;
				border-color: #94a3b8;
			}

			/* Metrics Grid */
			.pv-metrics-grid {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
				gap: 16px;
			}
			.pv-metric-card {
				background: #ffffff;
				border: 1px solid #e2e8f0;
				border-radius: 12px;
				padding: 18px 20px;
				box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
				display: flex;
				flex-direction: column;
				transition: transform 0.15s ease, box-shadow 0.15s ease;
			}
			.pv-metric-card:hover {
				transform: translateY(-2px);
				box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
			}
			.pv-metric-blue { border-left: 4px solid #2563eb; }
			.pv-metric-green { border-left: 4px solid #16a34a; }
			.pv-metric-purple { border-left: 4px solid #8b5cf6; }
			.pv-metric-top {
				display: flex;
				align-items: center;
				justify-content: space-between;
				gap: 8px;
			}
			.pv-metric-label {
				font-size: 12px;
				color: #64748b;
				font-weight: 600;
				text-transform: uppercase;
				letter-spacing: 0.5px;
			}
			.pv-metric-chip {
				font-size: 11px;
				font-weight: 600;
				padding: 2px 8px;
				border-radius: 9999px;
			}
			.pv-chip-blue { background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; }
			.pv-chip-green { background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; }
			.pv-chip-purple { background: #faf5ff; color: #8b5cf6; border: 1px solid #e9d5ff; }
			.pv-metric-value {
				font-size: 28px;
				font-weight: 700;
				color: #0f172a;
				margin-top: 8px;
				line-height: 1.1;
			}
			.pv-metric-unit {
				font-size: 14px;
				font-weight: 500;
				color: #64748b;
				margin-left: 2px;
			}
			.pv-metric-subtext {
				font-size: 12px;
				color: #94a3b8;
				margin-top: 6px;
			}

			/* Callout */
			.pv-callout {
				background: #f0f9ff;
				border: 1px solid #bae6fd;
				border-left: 4px solid #0284c7;
				border-radius: 8px;
				padding: 16px 20px;
			}
			.pv-callout-title {
				font-weight: 700;
				color: #0369a1;
				font-size: 14px;
				margin-bottom: 4px;
				display: flex;
				align-items: center;
				gap: 6px;
			}
			.pv-callout-body {
				font-size: 13px;
				color: #0c4a6e;
				line-height: 1.6;
			}

			/* Tabs */
			.pv-nav-tabs {
				background: #ffffff;
				border: 1px solid #e2e8f0;
				border-radius: 10px;
				padding: 6px;
				display: flex;
				flex-wrap: wrap;
				gap: 6px;
				margin-bottom: 22px;
				box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
			}
			.pv-nav-tab {
				background: transparent;
				color: #64748b;
				border: 1px solid transparent;
				font-weight: 500;
				border-radius: 6px;
				padding: 8px 18px;
				font-size: 13px;
				display: inline-flex;
				align-items: center;
				gap: 6px;
				text-decoration: none !important;
				transition: all 0.15s ease;
				cursor: pointer;
			}
			.pv-nav-tab:hover {
				background: #f8fafc;
				color: #0f172a;
			}
			.pv-nav-tab.pv-nav-active {
				background: #eff6ff;
				color: #2563eb;
				border-color: #bfdbfe;
				font-weight: 600;
				box-shadow: 0 1px 2px rgba(37, 99, 235, 0.08);
			}

			/* Card */
			.pv-card {
				background: #ffffff;
				border: 1px solid #e2e8f0;
				border-radius: 12px;
				box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
				margin-bottom: 24px;
			}
			.pv-card-head {
				display: flex;
				align-items: flex-start;
				gap: 14px;
			}
			.pv-card-icon {
				background: #2563eb;
				color: #ffffff;
				width: 32px;
				height: 32px;
				border-radius: 8px;
				display: flex;
				align-items: center;
				justify-content: center;
				flex-shrink: 0;
				margin-top: 2px;
			}
			.pv-card-title-group {
				flex: 1;
			}
			.pv-card-title {
				font-size: 16px;
				font-weight: 700;
				color: #0f172a;
				margin: 0;
				line-height: 1.3;
				display: flex;
				align-items: center;
				gap: 6px;
				flex-wrap: wrap;
			}
			.pv-card-subtitle {
				font-size: 13px;
				color: #64748b;
				font-weight: 500;
			}
			.pv-card-desc {
				font-size: 13px;
				color: #64748b;
				margin: 4px 0 0 0;
				line-height: 1.5;
			}

			/* Table */
			.pv-table-wrap {
				width: 100%;
				overflow-x: auto;
			}
			.pv-leaderboard-table {
				width: 100%;
				border-collapse: collapse;
				font-size: 13px;
				text-align: left;
			}
			.pv-leaderboard-table thead th {
				background: #f8fafc;
				color: #475569;
				font-weight: 600;
				font-size: 12px;
				padding: 14px 18px;
				border-bottom: 1px solid #e2e8f0;
				white-space: nowrap;
			}
			.pv-leaderboard-table tbody td {
				padding: 14px 18px;
				border-bottom: 1px solid #f1f5f9;
				vertical-align: middle;
			}
			.pv-leaderboard-table tbody tr:last-child td {
				border-bottom: none;
			}
			.pv-leaderboard-table tbody tr:hover td {
				background: #f8fafc;
			}

			/* Rank Badges */
			.pv-rank-badge {
				display: inline-flex;
				align-items: center;
				justify-content: center;
				width: 28px;
				height: 28px;
				border-radius: 50%;
				font-weight: 700;
				font-size: 12px;
				line-height: 1;
			}
			.pv-rank-1 {
				background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
				color: #ffffff;
				box-shadow: 0 2px 6px rgba(245, 158, 11, 0.4);
			}
			.pv-rank-2 {
				background: linear-gradient(135deg, #94a3b8 0%, #64748b 100%);
				color: #ffffff;
				box-shadow: 0 2px 6px rgba(100, 116, 139, 0.35);
			}
			.pv-rank-3 {
				background: linear-gradient(135deg, #f97316 0%, #c2410c 100%);
				color: #ffffff;
				box-shadow: 0 2px 6px rgba(234, 88, 12, 0.35);
			}
			.pv-rank-other {
				background: #f1f5f9;
				color: #64748b;
				border: 1px solid #e2e8f0;
				font-weight: 600;
			}

			/* Cover Thumbnails */
			.pv-cover-thumb {
				width: 44px;
				height: 44px;
				border-radius: 8px;
				object-fit: cover;
				border: 1px solid #e2e8f0;
				box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
				display: block;
				margin: 0 auto;
			}
			.pv-cover-placeholder {
				background: #f8fafc;
				display: flex;
				align-items: center;
				justify-content: center;
			}

			/* Post Title & Actions */
			.pv-post-title-wrap {
				display: flex;
				flex-direction: column;
				gap: 4px;
			}
			.pv-post-title {
				color: #0f172a;
				font-weight: 600;
				font-size: 14px;
				text-decoration: none !important;
				line-height: 1.4;
				transition: color 0.15s ease;
			}
			.pv-post-title:hover {
				color: #2563eb !important;
			}
			.pv-post-actions {
				font-size: 12px;
				color: #94a3b8;
				display: flex;
				align-items: center;
				gap: 6px;
			}
			.pv-post-actions a {
				color: #64748b;
				text-decoration: none !important;
				transition: color 0.15s ease;
			}
			.pv-post-actions a:hover {
				color: #2563eb !important;
			}
			.pv-sep {
				color: #cbd5e1;
			}

			/* Views Numbers */
			.pv-period-views {
				font-size: 16px;
				font-weight: 700;
				color: #2563eb;
			}
			.pv-total-views {
				font-size: 14px;
				font-weight: 600;
				color: #475569;
			}
			.pv-views-unit {
				font-size: 12px;
				color: #94a3b8;
				margin-left: 2px;
			}

			/* Author & Date */
			.pv-author-badge {
				display: inline-flex;
				align-items: center;
				gap: 4px;
				background: #f8fafc;
				border: 1px solid #e2e8f0;
				padding: 3px 8px;
				border-radius: 6px;
				color: #475569;
				font-size: 12px;
				font-weight: 500;
			}
			.pv-post-date {
				color: #64748b;
				font-size: 13px;
				white-space: nowrap !important;
				display: inline-block;
				letter-spacing: -0.2px;
			}
			.pv-leaderboard-table th:last-child,
			.pv-leaderboard-table td:last-child {
				white-space: nowrap !important;
				text-align: right !important;
				padding-right: 24px !important;
			}

			/* Empty State */
			.pv-table-empty {
				padding: 56px 24px;
				text-align: center;
			}
			.pv-empty-icon {
				width: 64px;
				height: 64px;
				border-radius: 50%;
				background: #f1f5f9;
				display: flex;
				align-items: center;
				justify-content: center;
				margin: 0 auto 16px auto;
			}
			.pv-empty-title {
				font-size: 16px;
				font-weight: 700;
				color: #0f172a;
				margin: 0 0 6px 0;
			}
			.pv-empty-desc {
				font-size: 13px;
				color: #64748b;
				max-width: 480px;
				margin: 0 auto 20px auto;
				line-height: 1.5;
			}
			.pv-empty-actions {
				display: flex;
				justify-content: center;
				align-items: center;
				gap: 12px;
				flex-wrap: wrap;
			}
		</style>
		<?php
	}
}
