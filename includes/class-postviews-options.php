<?php
/**
 * Option storage for Post Views.
 *
 * Everything the plugin configures already lived in a single wp_options row,
 * views_options, so 2.0.0 does not consolidate anything - it just puts one
 * accessor in front of the row instead of a get_option() call at each of the
 * dozen sites that used to read it. The keys are deliberately left flat and
 * unchanged: themes and other plugins read this option directly, and there is
 * no row count to win by nesting them.
 *
 * One companion row is kept separate: views_version. It is read to decide
 * whether views_options needs migrating, so it cannot live inside the thing
 * being migrated.
 *
 * @package Post-Views
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the views_options row.
 */
class PostViews_Options {

	/**
	 * Name of the settings row.
	 *
	 * @var string
	 */
	const OPTION = 'views_options';

	/**
	 * Row holding the version the stored data was last migrated to.
	 *
	 * @var string
	 */
	const VERSION_OPTION = 'views_version';

	/**
	 * Runtime cache, so a page render reads the row once rather than per lookup.
	 *
	 * @var array|null
	 */
	protected static $cache = null;

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public static function init() {
		// On 'init' rather than 'admin_init': the 2.0.0 migration changes how
		// templates are stored, and until it has run a legacy template renders
		// with its backslashes showing. Waiting for an administrator to load a
		// screen would leave that visible to every front end visitor in the
		// meantime. The version gate means this writes at most once.
		add_action( 'init', array( __CLASS__, 'maybe_upgrade' ), 1 );

		// Before 2.0.0 every read went straight to get_option(), so anything
		// that wrote the row - another plugin, WP-CLI, a migration script -
		// took effect immediately in the same request. The runtime cache below
		// would otherwise keep serving the value from before the write.
		add_action( 'add_option_' . self::OPTION, array( __CLASS__, 'flush' ) );
		add_action( 'update_option_' . self::OPTION, array( __CLASS__, 'flush' ) );
	}

	/**
	 * Run template markup through wp_kses_post() with SVG tags allowed.
	 *
	 * The whitelist used to be installed globally for the whole request, which
	 * also loosened sanitising for every other plugin on the page. It is scoped
	 * to this call instead: the filter goes on, wp_kses_post() runs, and the
	 * filter comes straight back off.
	 *
	 * @param string $html Markup to sanitize.
	 * @return string
	 */
	public static function kses( $html ) {
		add_filter( 'wp_kses_allowed_html', array( __CLASS__, 'filter_allowed_html' ), 10, 2 );
		$clean = wp_kses_post( $html );
		remove_filter( 'wp_kses_allowed_html', array( __CLASS__, 'filter_allowed_html' ), 10 );

		return $clean;
	}

	/**
	 * Extend wp_kses allowed tags to preserve vector SVGs and micro-markup in templates.
	 *
	 * @param array  $tags    Allowed HTML elements.
	 * @param string $context Context name.
	 * @return array
	 */
	public static function filter_allowed_html( $tags, $context ) {
		if ( 'post' === $context || empty( $context ) ) {
			$tags['svg'] = array(
				'class'           => true,
				'aria-hidden'     => true,
				'aria-label'      => true,
				'role'            => true,
				'viewbox'         => true,
				'viewBox'         => true,
				'width'           => true,
				'height'          => true,
				'fill'            => true,
				'stroke'          => true,
				'stroke-width'    => true,
				'stroke-linecap'  => true,
				'stroke-linejoin' => true,
				'xmlns'           => true,
				'style'           => true,
			);
			$tags['path'] = array(
				'd'               => true,
				'fill'            => true,
				'stroke'          => true,
				'stroke-width'    => true,
				'stroke-linecap'  => true,
				'stroke-linejoin' => true,
				'opacity'         => true,
			);
			$tags['circle'] = array(
				'cx'           => true,
				'cy'           => true,
				'r'            => true,
				'fill'         => true,
				'stroke'       => true,
				'stroke-width' => true,
				'opacity'      => true,
			);
			$tags['polygon'] = array(
				'points'          => true,
				'fill'            => true,
				'stroke'          => true,
				'stroke-width'    => true,
				'stroke-linecap'  => true,
				'stroke-linejoin' => true,
			);
			$tags['polyline'] = array(
				'points'          => true,
				'fill'            => true,
				'stroke'          => true,
				'stroke-width'    => true,
				'stroke-linecap'  => true,
				'stroke-linejoin' => true,
			);
			$tags['line'] = array(
				'x1'              => true,
				'y1'              => true,
				'x2'              => true,
				'y2'              => true,
				'stroke'          => true,
				'stroke-width'    => true,
				'stroke-linecap'  => true,
			);
		}
		return $tags;
	}

	/**
	 * Default value for every key.
	 *
	 * These mirror the pre-2.0.0 activation routine exactly. Changing any of
	 * them silently changes what a fresh install looks like.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'count'                   => 1,
			'count_mode'              => 0, // 0 = PV, 1 = UV (24h deduplication).
			'dwell_time'              => 0, // Minimum dwell time in seconds before counting (0 = immediate).
			'scroll_depth'            => 0, // Minimum scroll depth percentage before counting (0 = immediate).
			'exclude_bots'            => 0,
			'api_transport'           => 'rest', // 'rest' = REST API, 'ajax' = admin-ajax.php
			'enable_buffer'           => 0, // 0 = direct write, 1 = object-cache buffer pool
			'buffer_threshold'        => 50, // Batch flush threshold
			'words_per_minute'        => 300, // Average reading speed for %READ_TIME%
			'enable_meta_box'         => 1, // 1 = show post edit meta box, 0 = hide
			'enable_dashboard_widget' => 1, // 1 = show dashboard top 5 widget
			'auto_random_offset'      => 0, // 0 = off, 1 = auto assign random offset on publish
			'auto_offset_min'         => 100, // Min initial random offset
			'auto_offset_max'         => 300, // Max initial random offset
			'global_multiplier'       => 1.0, // Global virtual views multiplier
			'display_home'            => 0,
			'display_single'          => 0,
			'display_page'            => 0,
			'display_archive'         => 0,
			'display_search'          => 0,
			'display_other'           => 0,
			'use_ajax'                => 1,
			'template'                => self::default_template( 'template' ),
			'most_viewed_template'    => self::default_template( 'most_viewed_template' ),
			'template_style'          => 'minimal-line',
			'enabled_post_types'      => array( 'post', 'page' ),
			'display_kuaixun_views'   => 0,
			'views_position'          => 'bottom-right',
			'capsule_display_mode'    => 'views_only', // 'views_only', 'both', 'both_time', 'both_words', 'stats_only', 'stats_time', 'stats_words'
			'display_reading_stats'   => 0, // legacy fallback
		);
	}

	/**
	 * Available template style presets.
	 *
	 * @return array Associative array of style_key => template_html.
	 */
	public static function template_styles() {
		return array(
			'modern-card'     => '<div class="pv-modern-card"><span class="pv-icon-circle"><svg class="pv-svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg></span><div class="pv-content"><span class="pv-label">' . __( '阅读量', 'post-views' ) . '</span><span class="pv-number">%VIEW_COUNT%</span></div></div>',
			'gradient-badge'  => '<div class="pv-gradient-badge"><span class="pv-badge-icon"><svg class="pv-svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 3z"/></svg></span><span class="pv-badge-text"><strong>%VIEW_COUNT%</strong> ' . __( '次热度', 'post-views' ) . '</span></div>',
			'minimal-line'    => '<div class="pv-minimal-line"><span class="pv-icon"><svg class="pv-svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg></span><span class="pv-divider"></span><span class="pv-text">%VIEW_COUNT% ' . __( '次阅读', 'post-views' ) . '</span></div>',
			'stat-box'        => '<div class="pv-stat-box"><div class="pv-stat-icon"><svg class="pv-svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg></div><div class="pv-stat-content"><div class="pv-stat-value">%VIEW_COUNT_ROUNDED%</div><div class="pv-stat-label">' . __( '浏览统计', 'post-views' ) . '</div></div></div>',
			'glass-card'      => '<div class="pv-glass-card"><div class="pv-glass-icon"><svg class="pv-svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg></div><div class="pv-glass-text">' . __( '本文阅读', 'post-views' ) . ' <span class="pv-glass-count">%VIEW_COUNT%</span> ' . __( '次', 'post-views' ) . '</div></div>',
			'outline-chip'    => '<div class="pv-outline-chip"><span class="pv-chip-icon"><svg class="pv-svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1-2.5-2.5Z"/><path d="M6 6h10"/><path d="M6 10h10"/></svg></span><span class="pv-chip-label">' . __( '浏览', 'post-views' ) . '</span><span class="pv-chip-count">%VIEW_COUNT%</span></div>',
			'neon-pill'       => '<div class="pv-neon-pill"><span class="pv-neon-icon"><svg class="pv-svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg></span><span class="pv-neon-text">' . __( '浏览', 'post-views' ) . ' <strong>%VIEW_COUNT%</strong></span></div>',
			'sunset-pill'     => '<div class="pv-sunset-pill"><span class="pv-sunset-icon"><svg class="pv-svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/></svg></span><span class="pv-sunset-text"><strong>%VIEW_COUNT%</strong> ' . __( '次浏览', 'post-views' ) . '</span></div>',
			'solid-dark-pill' => '<div class="pv-solid-dark-pill"><span class="pv-solid-dot"><span class="pv-ping"></span></span><span class="pv-solid-text">' . __( '阅读量', 'post-views' ) . ' <strong>%VIEW_COUNT%</strong></span></div>',
		);
	}

	/**
	 * The stock markup for one of the two templates.
	 *
	 * Kept in one place so the defaults, the activation routine and the
	 * "Restore Default Template" buttons cannot drift apart.
	 *
	 * Only the bare word "views" is translatable. Putting the whole template
	 * through __() would hand phpcbf a string full of %TOKEN% placeholders,
	 * which it rewrites into numbered sprintf arguments - changing the msgid
	 * and showing users "%1$VIEW_COUNT%".
	 *
	 * @param string $key Either 'template' or 'most_viewed_template'.
	 * @return string
	 */
	public static function default_template( $key ) {
		if ( 'most_viewed_template' === $key ) {
			return '<li><a href="%POST_URL%" title="%POST_TITLE%">%POST_TITLE%</a> - %VIEW_COUNT% ' . __( '次浏览', 'post-views' ) . '</li>';
		}

		$styles = self::template_styles();
		return $styles['minimal-line'];
	}

	/**
	 * The whole option with defaults merged in.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$stored = get_option( self::OPTION, array() );
			if ( ! is_array( $stored ) ) {
				$stored = array();
			}
			self::$cache = array_merge( self::defaults(), $stored );

			// Smoothly upgrade any old sharp eye SVG to Open Book in saved templates.
			if ( isset( self::$cache['template'] ) && is_string( self::$cache['template'] ) ) {
				$old_eyes = array(
					'<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
					'<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8z"/><circle cx="12" cy="12" r="3"/>',
				);
				$book_path = '<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>';
				foreach ( $old_eyes as $old_eye ) {
					if ( false !== strpos( self::$cache['template'], $old_eye ) ) {
						self::$cache['template'] = str_replace( $old_eye, $book_path, self::$cache['template'] );
					}
				}
			}
		}

		return self::$cache;
	}

	/**
	 * Read one setting.
	 *
	 * @param string $key           Option key.
	 * @param mixed  $default_value Returned when the key is absent.
	 * @return mixed
	 */
	public static function get( $key, $default_value = null ) {
		$all = self::all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $default_value;
	}

	/**
	 * Read one setting as an integer.
	 *
	 * @param string $key Option key.
	 * @return int
	 */
	public static function get_int( $key ) {
		return (int) self::get( $key, 0 );
	}

	/**
	 * Replace the whole option.
	 *
	 * The two HTML templates are kses-filtered here: save() is the
	 * programmatic write path and never sees the settings screen's sanitize
	 * callback, so this is what keeps a caller from storing markup the render
	 * path would have to trust.
	 *
	 * @param array $values Full option array.
	 * @return bool
	 */
	public static function save( $values ) {
		$values = (array) $values;

		foreach ( array( 'template', 'most_viewed_template' ) as $key ) {
			if ( isset( $values[ $key ] ) && is_string( $values[ $key ] ) ) {
				$values[ $key ] = self::kses( $values[ $key ] );
			}
		}

		self::$cache = array_merge( self::defaults(), $values );

		return update_option( self::OPTION, self::$cache );
	}

	/**
	 * Drop the runtime cache. Needed after a migration writes the row.
	 *
	 * @return void
	 */
	public static function flush() {
		self::$cache = null;
		PostViews_Query::flush_list_cache();
	}

	/**
	 * Seed the row on activation.
	 *
	 * @return void
	 */
	public static function install() {
		add_option( self::OPTION, self::defaults() );
		self::flush();
		self::maybe_upgrade();
	}

	/**
	 * Run any outstanding data migrations and record the version.
	 *
	 * Activation does not fire on plugin *update*, which is the usual reason a
	 * migration never runs, so this is driven from 'init' as well.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		$installed = get_option( self::VERSION_OPTION );

		if ( WP_POSTVIEWS_VERSION === $installed ) {
			return;
		}

		// Gated on the recorded version rather than on "do the stored templates
		// still contain backslashes". A template with no apostrophe in it is
		// indistinguishable before and after, so a content check would rerun
		// the migration forever on some sites and never mark others done.
		if ( empty( $installed ) || version_compare( $installed, '2.0.0', '<' ) ) {
			self::migrate_template_slashes();
		}

		if ( empty( $installed ) || version_compare( $installed, '2.1.0', '<' ) ) {
			PostViews_Stats::create_table();
		}

		update_option( self::VERSION_OPTION, WP_POSTVIEWS_VERSION );
	}

	/**
	 * Unslash the two stored templates, once.
	 *
	 * Up to 1.78.1 the settings screen wrote $_POST straight through without
	 * wp_unslash(), so templates were stored slashed and every read path undid
	 * that with stripslashes(). 2.0.0 unslashes on save instead, which means
	 * the read paths must stop stripping - and that in turn means the rows
	 * already on disk have to be corrected once.
	 *
	 * Doing it the other way round, keeping stripslashes() on read, would eat a
	 * backslash the user genuinely wanted: a template containing a Windows path
	 * arrives unslashed, gets stripped again on output and loses the separator.
	 *
	 * @return void
	 */
	public static function migrate_template_slashes() {
		$stored = get_option( self::OPTION );

		if ( ! is_array( $stored ) ) {
			return;
		}

		$changed = false;
		foreach ( array( 'template', 'most_viewed_template' ) as $key ) {
			if ( isset( $stored[ $key ] ) && is_string( $stored[ $key ] ) ) {
				$unslashed = stripslashes( $stored[ $key ] );
				if ( $unslashed !== $stored[ $key ] ) {
					$stored[ $key ] = $unslashed;
					$changed        = true;
				}
			}
		}

		if ( $changed ) {
			update_option( self::OPTION, $stored );
			self::flush();
		}
	}
}
