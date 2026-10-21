<?php
/**
 * Settings screen for Post Views.
 *
 * @package Post-Views
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles settings page registration, sanitization, and modern dashboard rendering.
 */
class PostViews_Settings {

	/**
	 * Settings group name used with register_setting().
	 *
	 * @var string
	 */
	const GROUP = 'views_options';

	/**
	 * Screen menu slug.
	 *
	 * @var string
	 */
	const SLUG = 'post-views';

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
	}

	/**
	 * Register the single wp_options row with the Settings API.
	 *
	 * @return void
	 */
	public static function register() {
		register_setting(
			self::GROUP,
			PostViews_Options::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => PostViews_Options::defaults(),
			)
		);
	}

	/**
	 * Add the options page under Settings.
	 *
	 * @return void
	 */
	public static function menu() {
		$hook = add_options_page(
			__( '浏览量设置 (Post Views)', 'post-views' ),
			__( '浏览量设置', 'post-views' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' )
		);

		add_action( 'load-' . $hook, array( __CLASS__, 'enqueue_scripts' ) );
	}

	/**
	 * Enqueue front-end styles for style previews and admin scripts.
	 *
	 * @return void
	 */
	public static function enqueue_scripts() {
		$front_css_file = dirname( WP_POSTVIEWS_MAIN_FILE ) . '/postviews-front.css';
		$style_ver      = WP_POSTVIEWS_VERSION . ( file_exists( $front_css_file ) ? '.' . filemtime( $front_css_file ) : '' );

		wp_enqueue_style(
			'post-views-front',
			plugins_url( 'postviews-front.css', WP_POSTVIEWS_MAIN_FILE ),
			array(),
			$style_ver
		);

		$admin_css_file = dirname( WP_POSTVIEWS_MAIN_FILE ) . '/postviews-admin.css';
		$admin_css_ver  = WP_POSTVIEWS_VERSION . ( file_exists( $admin_css_file ) ? '.' . filemtime( $admin_css_file ) : '' );

		wp_enqueue_style(
			'wp-postviews-admin',
			plugins_url( 'postviews-admin.css', WP_POSTVIEWS_MAIN_FILE ),
			array(),
			$admin_css_ver
		);

		$admin_js_file = dirname( WP_POSTVIEWS_MAIN_FILE ) . '/postviews-admin.js';
		$script_ver    = WP_POSTVIEWS_VERSION . ( file_exists( $admin_js_file ) ? '.' . filemtime( $admin_js_file ) : '' );

		wp_enqueue_script(
			'wp-postviews-admin',
			plugins_url( 'postviews-admin.js', WP_POSTVIEWS_MAIN_FILE ),
			array(),
			$script_ver,
			true
		);

		wp_localize_script(
			'wp-postviews-admin',
			'postviewsAdminL10n',
			array(
				'defaults' => array(
					'template'             => PostViews_Options::default_template( 'template' ),
					'most_viewed_template' => PostViews_Options::default_template( 'most_viewed_template' ),
				),
				'resetConfirm' => __( '确定要重置所有选项为默认值吗？未保存的自定义设置将被覆盖。', 'post-views' ),
			)
		);
	}

	/**
	 * Validate and sanitize submitted options.
	 *
	 * @param mixed $input Submitted values.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$current = PostViews_Options::all();

		if ( ! is_array( $input ) ) {
			return $current;
		}

		foreach ( array( 'count', 'display_home', 'display_single', 'display_page', 'display_archive', 'display_search', 'display_other' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$current[ $key ] = min( 2, max( 0, (int) $input[ $key ] ) );
			}
		}

		if ( isset( $input['count_mode'] ) ) {
			$current['count_mode'] = min( 1, max( 0, (int) $input['count_mode'] ) );
		}

		if ( isset( $input['dwell_time'] ) ) {
			$current['dwell_time'] = min( 300, max( 0, (int) $input['dwell_time'] ) );
		}

		if ( isset( $input['scroll_depth'] ) ) {
			$current['scroll_depth'] = min( 100, max( 0, (int) $input['scroll_depth'] ) );
		}

		if ( isset( $input['api_transport'] ) ) {
			$current['api_transport'] = in_array( $input['api_transport'], array( 'rest', 'ajax' ), true ) ? $input['api_transport'] : 'rest';
		}

		if ( isset( $input['enable_buffer'] ) ) {
			$current['enable_buffer'] = empty( $input['enable_buffer'] ) ? 0 : 1;
		}

		if ( isset( $input['buffer_threshold'] ) ) {
			$current['buffer_threshold'] = min( 500, max( 10, (int) $input['buffer_threshold'] ) );
		}

		if ( isset( $input['words_per_minute'] ) ) {
			$current['words_per_minute'] = min( 1000, max( 50, (int) $input['words_per_minute'] ) );
		}

		if ( isset( $input['enable_meta_box'] ) ) {
			$current['enable_meta_box'] = empty( $input['enable_meta_box'] ) ? 0 : 1;
		}

		if ( isset( $input['enable_dashboard_widget'] ) ) {
			$current['enable_dashboard_widget'] = empty( $input['enable_dashboard_widget'] ) ? 0 : 1;
		}

		if ( isset( $input['auto_random_offset'] ) ) {
			$current['auto_random_offset'] = empty( $input['auto_random_offset'] ) ? 0 : 1;
		}

		if ( isset( $input['auto_offset_min'] ) ) {
			$current['auto_offset_min'] = max( 0, (int) $input['auto_offset_min'] );
		}

		if ( isset( $input['auto_offset_max'] ) ) {
			$current['auto_offset_max'] = max( $current['auto_offset_min'], (int) $input['auto_offset_max'] );
		}

		if ( isset( $input['global_multiplier'] ) ) {
			$current['global_multiplier'] = max( 0.1, min( 100.0, (float) $input['global_multiplier'] ) );
		}

		foreach ( array( 'exclude_bots', 'use_ajax', 'display_kuaixun_views' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$current[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
			}
		}

		foreach ( array( 'template', 'most_viewed_template' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$current[ $key ] = PostViews_Options::kses( trim( $input[ $key ] ) );
			}
		}

		// Template style selection.
		if ( isset( $input['template_style'] ) ) {
			$styles = array_keys( PostViews_Options::template_styles() );
			if ( in_array( $input['template_style'], $styles, true ) ) {
				$style_changed             = $input['template_style'] !== $current['template_style'];
				$current['template_style'] = $input['template_style'];

				$stored_template = trim( (string) $current['template'] );
				$all_styles      = PostViews_Options::template_styles();
				if ( $style_changed || '' === $stored_template || in_array( $stored_template, $all_styles, true ) ) {
					$current['template'] = $all_styles[ $input['template_style'] ];
				}
			}
		}

		// Enabled post types.
		$blocked_types = array( 'qa_post', 'kuaixun' );
		if ( isset( $input['enabled_post_types'] ) && is_array( $input['enabled_post_types'] ) ) {
			$submitted            = array_map( 'sanitize_key', $input['enabled_post_types'] );
			$submitted            = array_values( array_diff( $submitted, $blocked_types ) );
			$current['enabled_post_types'] = $submitted;
		} else {
			$current['enabled_post_types'] = array();
		}
		// Views position.
		if ( isset( $input['views_position'] ) ) {
			$allowed_positions = array( 'bottom-right', 'bottom-left', 'top-right', 'top-left' );
			if ( in_array( $input['views_position'], $allowed_positions, true ) ) {
				$current['views_position'] = $input['views_position'];
			}
		}

		// Capsule content display mode (views alone, stats alone, or both combined).
		if ( isset( $input['capsule_display_mode'] ) ) {
			$allowed_modes = array( 'views_only', 'both', 'both_time', 'both_words', 'stats_only', 'stats_time', 'stats_words' );
			if ( in_array( $input['capsule_display_mode'], $allowed_modes, true ) ) {
				$current['capsule_display_mode'] = $input['capsule_display_mode'];
			}
		}

		return $current;
	}

	/**
	 * Three way "who sees this" choices.
	 *
	 * @param string $never_label Label for hiding.
	 * @return array
	 */
	protected static function display_choices( $never_label ) {
		return array(
			0 => __( '对所有人显示', 'post-views' ),
			1 => __( '仅对注册用户显示', 'post-views' ),
			2 => $never_label,
		);
	}

	/**
	 * Render a select bound to one option key.
	 *
	 * @param string $key     Option key.
	 * @param array  $choices Value => label.
	 * @return void
	 */
	protected static function select( $key, $choices ) {
		$selected = PostViews_Options::get( $key );
		?>
		<select name="<?php echo esc_attr( PostViews_Options::OPTION . '[' . $key . ']' ); ?>" id="views-<?php echo esc_attr( $key ); ?>" class="pv-select" size="1">
			<?php foreach ( $choices as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>"<?php selected( (string) $value, (string) $selected ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Render list of template tokens as modern clickable pills.
	 *
	 * @param array $tokens Token names without percents.
	 * @return void
	 */
	protected static function token_list( $tokens ) {
		echo '<div class="pv-tokens">';
		foreach ( $tokens as $token ) {
			echo '<span class="pv-token-pill" data-pv-copy="%' . esc_attr( $token ) . '%" title="' . esc_attr__( '点击复制变量占位符', 'post-views' ) . '">%' . esc_html( $token ) . '%</span>';
		}
		echo '</div>';
	}

	/**
	 * Render the full modern settings screen matching the high-end SaaS dashboard mockup.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$using_cache       = defined( 'WP_CACHE' ) && WP_CACHE;
		$option            = PostViews_Options::OPTION;
		$stats_summary     = PostViews_Stats::get_dashboard_summary();
		$stats_rows        = PostViews_Stats::get_table_row_count();
		$buffered_count    = PostViews_Counter::get_buffered_count();
		$legacy_stats      = PostViews_Admin::get_legacy_meta_stats();
		$current_style     = PostViews_Options::get( 'template_style', 'minimal-line' );
		$styles            = PostViews_Options::template_styles();
		$style_names       = array(
			'modern-card'     => __( '现代简洁 (Line+ 简约圆角)', 'post-views' ),
			'gradient-badge'  => __( '极简数字 (纯数字红点)', 'post-views' ),
			'minimal-line'    => __( '极简线性 (雅致书卷阅读)', 'post-views' ),
			'stat-box'        => __( '复古轻奢 (分形 HUD 设计)', 'post-views' ),
			'glass-card'      => __( '海去动感 (Vision 晶透明玻璃)', 'post-views' ),
			'outline-chip'    => __( '轻奢圆角 (深色渐变发光)', 'post-views' ),
			'sunset-pill'     => __( '霓红渐变 (渐变数字发光)', 'post-views' ),
			'solid-dark-pill' => __( '黑曜石圆边 (动态脉冲信号)', 'post-views' ),
			'neon-pill'       => __( '赛博霓虹 (深空青芒荧光)', 'post-views' ),
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
					<h1 class="pv-header-title"><?php esc_html_e( '文章浏览量设置 (Post Views)', 'post-views' ); ?></h1>
					<span class="pv-badge">v<?php echo esc_html( WP_POSTVIEWS_VERSION ); ?></span>
				</div>
				<div class="pv-header-right">
					<a href="<?php echo esc_url( admin_url( 'edit.php?page=post-views-stats' ) ); ?>" class="pv-btn-primary">
						<svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor">
							<path d="M12 2l2.4 7.4h7.6l-6.2 4.5 2.4 7.4-6.2-4.5-6.2 4.5 2.4-7.4-6.2-4.5h7.6z"/>
						</svg>
						<?php esc_html_e( '打开浏览量排行榜面板', 'post-views' ); ?> &raquo;
					</a>
				</div>
			</div>

			<!-- Notices (Action-specific notices; settings-updated handled natively by WP settings_errors) -->
			<?php if ( isset( $_GET['flushed_buffer'] ) ) : ?>
				<div class="notice notice-success is-dismissible" style="margin: 0 0 20px 0; border-radius: 8px;"><p><?php printf( esc_html__( '缓冲池已刷新！成功批量落库入表 %d 次浏览量。', 'post-views' ), (int) $_GET['flushed_buffer'] ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['migrated'] ) ) : ?>
				<div class="notice notice-success is-dismissible" style="margin: 0 0 20px 0; border-radius: 8px;"><p><?php printf( esc_html__( '历史数据迁移完成！已为 %d 篇文章成功合并历史浏览量。', 'post-views' ), (int) $_GET['migrated'] ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['seeded_stats'] ) ) : ?>
				<div class="notice notice-success is-dismissible" style="margin: 0 0 20px 0; border-radius: 8px;"><p><?php printf( esc_html__( '成功同步初始化！已为 %d 篇文章生成今日周期排行数据。', 'post-views' ), (int) $_GET['seeded_stats'] ); ?></p></div>
			<?php endif; ?>

			<!-- Tab Navigation Bar -->
			<div class="pv-nav-tabs">
				<a href="#tab-styles" class="pv-nav-tab pv-nav-active" data-tab="tab-styles">
					<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>
					<?php esc_html_e( '显示外观预设', 'post-views' ); ?>
				</a>
				<a href="#tab-trending" class="pv-nav-tab" data-tab="tab-trending">
					<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
					<?php esc_html_e( '周期榜单与统计', 'post-views' ); ?>
				</a>
				<a href="#tab-engine" class="pv-nav-tab" data-tab="tab-engine">
					<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
					<?php esc_html_e( '高并发与防刷引擎', 'post-views' ); ?>
				</a>
				<a href="#tab-editor" class="pv-nav-tab" data-tab="tab-editor">
					<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
					<?php esc_html_e( '区块与阅读时长', 'post-views' ); ?>
				</a>
				<a href="#tab-tools" class="pv-nav-tab" data-tab="tab-tools">
					<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
					<?php esc_html_e( '初始底数与数据运维', 'post-views' ); ?>
				</a>
			</div>

			<!-- Master Form -->
			<form method="post" action="options.php" id="pv-main-form">
				<?php settings_fields( self::GROUP ); ?>

				<!-- =========================================================
				 * TAB 1: 显示外观预设
				 * ========================================================= -->
				<div id="tab-styles" class="pv-tab-content">
					<!-- Card 1: 9款高颜值样式预设 -->
					<div class="pv-card">
						<div class="pv-card-head">
							<div class="pv-card-icon">
								<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>
							</div>
							<div class="pv-card-title-group">
								<h2 class="pv-card-title">
									<?php esc_html_e( '浏览量显示样式', 'post-views' ); ?>
									<span class="pv-card-subtitle"><?php esc_html_e( '(9 款高颜值样式预设)', 'post-views' ); ?></span>
								</h2>
								<p class="pv-card-desc"><?php esc_html_e( '全新升级显示样式，丰富的预设模板，满足不同主题风格需求。支持自定义 CSS，轻松打造个性化效果。', 'post-views' ); ?></p>
							</div>
						</div>

						<div class="pv-style-selector">
							<?php foreach ( $styles as $key => $template ) : ?>
								<label class="pv-style-option">
									<input type="radio" name="<?php echo esc_attr( $option . '[template_style]' ); ?>" value="<?php echo esc_attr( $key ); ?>" <?php checked( $current_style, $key ); ?> data-template="<?php echo esc_attr( $template ); ?>" />
									<div class="pv-style-preview">
										<div class="pv-check-ribbon">
											<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
										</div>
										<div class="pv-style-name"><?php echo esc_html( $style_names[ $key ] ); ?></div>
										<div class="pv-style-demo">
											<?php
											$preview_tpl  = $template;
											$reading_mode = PostViews_Display::get_capsule_display_mode();
											if ( 0 === strpos( $reading_mode, 'stats_' ) ) {
												$preview_tpl = PostViews_Display::transform_template_for_stats_only( $preview_tpl, $reading_mode );
											} elseif ( 0 === strpos( $reading_mode, 'both' ) && false === strpos( $preview_tpl, '%READ_TIME%' ) && false === strpos( $preview_tpl, '%WORD_COUNT%' ) ) {
												$preview_tpl = PostViews_Display::inject_reading_stats_to_template( $preview_tpl, $reading_mode );
											}
											// PostViews_Options::kses() keeps the inline SVG icons. A bare
											// wp_kses_post() strips them, which made every preview capsule
											// render without its icon and drift away from the front end.
											echo PostViews_Options::kses(
												str_replace(
													array( '%VIEW_COUNT%', '%VIEW_COUNT_ROUNDED%', '%READ_TIME%', '%WORD_COUNT%' ),
													array( '1,234', '1.2K', '6 分钟', '1,850' ),
													$preview_tpl
												)
											);
											?>
										</div>
									</div>
								</label>
							<?php endforeach; ?>
						</div>
					</div>

					<!-- Card 2: 内容类型与页面展示控制 -->
					<div class="pv-card">
						<div class="pv-card-head">
							<div class="pv-card-icon">
								<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>
							</div>
							<div class="pv-card-title-group">
								<h2 class="pv-card-title"><?php esc_html_e( '内容类型与页面展示控制', 'post-views' ); ?></h2>
								<p class="pv-card-desc"><?php esc_html_e( '灵活配置浏览量在哪些页面自动输出，支持针对不同访客身份精细控制。', 'post-views' ); ?></p>
							</div>
						</div>

						<table class="pv-form-table" role="presentation">
							<tr>
								<th scope="row">
									<label>
										<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
										<?php esc_html_e( '启用的内容类型：', 'post-views' ); ?>
									</label>
								</th>
								<td>
									<?php
									$enabled_types     = PostViews_Options::get( 'enabled_post_types', array( 'post', 'page' ) );
									$enabled_types     = is_array( $enabled_types ) ? $enabled_types : array( 'post', 'page' );
									$unsupported_types = array( 'qa_post', 'kuaixun' );
									$post_types        = get_post_types( array( 'public' => true ), 'objects' );
									?>
									<fieldset>
										<?php foreach ( $post_types as $pt ) : ?>
											<?php if ( in_array( $pt->name, $unsupported_types, true ) ) continue; ?>
											<label class="pv-checkbox-item">
												<input type="checkbox" name="<?php echo esc_attr( $option . '[enabled_post_types][]' ); ?>" value="<?php echo esc_attr( $pt->name ); ?>" <?php checked( in_array( $pt->name, $enabled_types, true ) ); ?> />
												<span><?php echo esc_html( $pt->labels->name ); ?></span>
												<span style="color:#94a3b8;font-size:12px;">(<?php echo esc_html( $pt->name ); ?>)</span>
											</label>
										<?php endforeach; ?>
									</fieldset>
									<div class="pv-info-note">
										<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
										<span><?php esc_html_e( '勾选后插件会自动在对应内容的正文中添加浏览量显示。', 'post-views' ); ?></span>
									</div>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="views-views_position">
										<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/><path d="M9 21V9"/></svg>
										<?php esc_html_e( '胶囊显示位置：', 'post-views' ); ?>
									</label>
								</th>
								<td>
									<?php
									self::select(
										'views_position',
										array(
											'bottom-right' => __( '右下角 (正文下方 · 默认)', 'post-views' ),
											'bottom-left'  => __( '左下角 (正文下方)', 'post-views' ),
											'top-right'    => __( '右上角 (正文上方)', 'post-views' ),
											'top-left'     => __( '左上角 (正文上方)', 'post-views' ),
										)
									);
									?>
									<span class="pv-inline-note"><?php esc_html_e( '自定义文章/页面等常规内容中浏览量胶囊的排版位置（快讯保持右下独立悬浮，不受此项影响）。', 'post-views' ); ?></span>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="views-capsule_display_mode">
										<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
										<?php esc_html_e( '胶囊内容显示模式：', 'post-views' ); ?>
									</label>
								</th>
								<td>
									<?php
									self::select(
										'capsule_display_mode',
										array(
											'views_only'  => __( '浏览量单独显示 (经典模式 · 默认)', 'post-views' ),
											'both'        => __( '浏览量 + 字数与阅读时间同时显示 (例：1,234 次阅读 · 约 1,850 字 · 预计阅读 6 分钟)', 'post-views' ),
											'both_time'   => __( '浏览量 + 仅阅读时间同时显示 (例：1,234 次阅读 · 预计阅读 6 分钟)', 'post-views' ),
											'both_words'  => __( '浏览量 + 仅文章字数同时显示 (例：1,234 次阅读 · 全文约 1,850 字)', 'post-views' ),
											'stats_only'  => __( '字数与阅读时间单独显示 (不显浏览量 · 例：约 1,850 字 · 预计阅读 6 分钟)', 'post-views' ),
											'stats_time'  => __( '仅阅读时间单独显示 (不显浏览量 · 例：预计阅读 6 分钟)', 'post-views' ),
											'stats_words' => __( '仅文章字数单独显示 (不显浏览量 · 例：全文约 1,850 字)', 'post-views' ),
										)
									);
									?>
									<span class="pv-inline-note"><?php esc_html_e( '自由选择胶囊内展示的内容组合（浏览量单独显示 / 字数时间单独显示 / 两者同时显示），胶囊外观预设、高度与图标完美保持不变。', 'post-views' ); ?></span>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="views-display_kuaixun_views">
										<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
										<?php esc_html_e( '快讯详情页显示：', 'post-views' ); ?>
									</label>
								</th>
								<td>
									<?php self::select( 'display_kuaixun_views', array( 1 => __( '开启右下角数量悬浮显示', 'post-views' ), 0 => __( '关闭', 'post-views' ) ) ); ?>
									<span class="pv-inline-note"><?php esc_html_e( '专为 JustNews 等主题的快讯 (kuaixun) 设计的专属布局。', 'post-views' ); ?></span>
								</td>
							</tr>
							<?php
							$contexts = array(
								'display_home'    => array( __( '首页显示：', 'post-views' ), __( '不在首页显示', 'post-views' ), 'home' ),
								'display_single'  => array( __( '文章详情页：', 'post-views' ), __( '不在文章页显示', 'post-views' ), 'file-text' ),
								'display_page'    => array( __( '独立页面：', 'post-views' ), __( '不在独立页面显示', 'post-views' ), 'file' ),
								'display_archive' => array( __( '分类/归档页：', 'post-views' ), __( '不在归档页显示', 'post-views' ), 'archive' ),
								'display_search'  => array( __( '搜索结果页：', 'post-views' ), __( '不在搜索页显示', 'post-views' ), 'search' ),
								'display_other'   => array( __( '其他页面：', 'post-views' ), __( '不在其他页面显示', 'post-views' ), 'grid' ),
							);
							foreach ( $contexts as $k => $labels ) :
								list( $l, $nl, $icon_type ) = $labels;
							?>
							<tr>
								<th scope="row">
									<label for="views-<?php echo esc_attr( $k ); ?>">
										<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
										<?php echo esc_html( $l ); ?>
									</label>
								</th>
								<td><?php self::select( $k, self::display_choices( $nl ) ); ?></td>
							</tr>
							<?php endforeach; ?>
						</table>
					</div>

					<!-- Card 3: 自定义 HTML 模板 (高级) -->
					<div class="pv-card">
						<div class="pv-card-head">
							<div class="pv-card-icon">
								<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
							</div>
							<div class="pv-card-title-group">
								<h2 class="pv-card-title"><?php esc_html_e( '自定义 HTML 模板 (高级)', 'post-views' ); ?></h2>
								<p class="pv-card-desc"><?php esc_html_e( '高级用户可自由编写 HTML 结构与变量占位符。预设切换将自动更新单篇模板。', 'post-views' ); ?></p>
							</div>
						</div>

						<div class="pv-template-grid">
							<!-- Template 1: Single Post -->
							<div class="pv-template-row">
								<div class="pv-template-meta">
									<label for="views-template-template"><?php esc_html_e( '单篇文章模板：', 'post-views' ); ?></label>
									<div class="pv-token-title"><?php esc_html_e( '可用变量占位符：', 'post-views' ); ?></div>
									<?php self::token_list( array( 'VIEW_COUNT', 'VIEW_COUNT_ROUNDED', 'READ_TIME', 'WORD_COUNT' ) ); ?>
								</div>
								<div class="pv-template-editor">
									<div class="pv-editor-top">
										<button type="button" class="pv-btn-copy" data-pv-copy-target="views-template-template">
											<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
											<?php esc_html_e( '复制', 'post-views' ); ?>
										</button>
									</div>
									<input type="text" class="pv-input-code" id="views-template-template" name="<?php echo esc_attr( $option . '[template]' ); ?>" value="<?php echo esc_attr( PostViews_Options::get( 'template', '' ) ); ?>" />
									<div class="pv-editor-bottom">
										<span class="pv-editor-desc"><?php esc_html_e( '留空则使用默认样式输出；如上方更换了预设，该项会自动同步更新。', 'post-views' ); ?></span>
										<button type="button" class="pv-btn-outline" data-postviews-reset="template" data-postviews-target="views-template-template">
											<?php esc_html_e( '恢复默认模板', 'post-views' ); ?>
										</button>
									</div>
								</div>
							</div>

							<!-- Template 2: Most Viewed Loop -->
							<div class="pv-template-row">
								<div class="pv-template-meta">
									<label for="views-template-most_viewed_template"><?php esc_html_e( '热门列表循环模板：', 'post-views' ); ?></label>
									<div class="pv-token-title"><?php esc_html_e( '可用变量占位符：', 'post-views' ); ?></div>
									<?php self::token_list( array( 'VIEW_COUNT', 'VIEW_COUNT_ROUNDED', 'POST_TITLE', 'POST_URL', 'POST_DATE', 'POST_THUMBNAIL', 'READ_TIME', 'WORD_COUNT' ) ); ?>
								</div>
								<div class="pv-template-editor">
									<div class="pv-editor-top">
										<button type="button" class="pv-btn-copy" data-pv-copy-target="views-template-most_viewed_template">
											<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
											<?php esc_html_e( '复制', 'post-views' ); ?>
										</button>
									</div>
									<textarea class="pv-input-code" rows="5" id="views-template-most_viewed_template" name="<?php echo esc_attr( $option . '[most_viewed_template]' ); ?>"><?php echo esc_textarea( PostViews_Options::get( 'most_viewed_template', '' ) ); ?></textarea>
									<div class="pv-editor-bottom">
										<span class="pv-editor-desc"><?php esc_html_e( '用于生成热门文章列表每个 li 元素的 HTML 结构。', 'post-views' ); ?></span>
										<button type="button" class="pv-btn-outline" data-postviews-reset="most_viewed_template" data-postviews-target="views-template-most_viewed_template">
											<?php esc_html_e( '恢复默认模板', 'post-views' ); ?>
										</button>
									</div>
								</div>
							</div>
						</div>
					</div>
				</div>

				<!-- =========================================================
				 * TAB 2: 周期榜单与统计
				 * ========================================================= -->
				<div id="tab-trending" class="pv-tab-content" style="display:none;">
					<!-- Card 1: 监控看板 -->
					<div class="pv-card">
						<div class="pv-card-head">
							<div class="pv-card-icon">
								<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
							</div>
							<div class="pv-card-title-group">
								<h2 class="pv-card-title"><?php esc_html_e( '周期榜单监控看板', 'post-views' ); ?></h2>
								<p class="pv-card-desc"><?php esc_html_e( '基于独立轻量日统计表（wp_post_views_stats），精准记录每日阅读增量，支持今日、近7天与近30天热门榜单。', 'post-views' ); ?></p>
							</div>
						</div>

						<div class="pv-metrics-grid">
							<div class="pv-metric-card pv-metric-blue">
								<div class="pv-metric-label"><?php esc_html_e( '今日全站阅读量', 'post-views' ); ?></div>
								<div class="pv-metric-value"><?php echo esc_html( number_format_i18n( $stats_summary['today_views'] ) ); ?></div>
							</div>
							<div class="pv-metric-card pv-metric-green">
								<div class="pv-metric-label"><?php esc_html_e( '近 7 天全站阅读量', 'post-views' ); ?></div>
								<div class="pv-metric-value"><?php echo esc_html( number_format_i18n( $stats_summary['week_views'] ) ); ?></div>
							</div>
							<div class="pv-metric-card pv-metric-purple">
								<div class="pv-metric-label"><?php esc_html_e( '周期独立统计表状态', 'post-views' ); ?></div>
								<div class="pv-metric-value" style="font-size:18px;margin-top:8px;">
									<?php printf( esc_html__( '%d 行记录 (35天自动归档)', 'post-views' ), (int) $stats_rows ); ?>
								</div>
							</div>
						</div>

						<p style="margin:20px 0 0 0;">
							<a href="<?php echo esc_url( admin_url( 'edit.php?page=post-views-stats' ) ); ?>" class="pv-btn-primary">
								<svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2l2.4 7.4h7.6l-6.2 4.5 2.4 7.4-6.2-4.5-6.2 4.5 2.4-7.4-6.2-4.5h7.6z"/></svg>
								<?php esc_html_e( '打开浏览量排行榜面板', 'post-views' ); ?> &raquo;
							</a>
						</p>
					</div>

					<!-- Card 2: 开发者调用支持 -->
					<div class="pv-card">
						<div class="pv-card-head">
							<div class="pv-card-icon">
								<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
							</div>
							<div class="pv-card-title-group">
								<h2 class="pv-card-title"><?php esc_html_e( '热门排行榜前台调用与组件 (开发者支持)', 'post-views' ); ?></h2>
								<p class="pv-card-desc"><?php esc_html_e( '支持短代码与原生 WP_Query 灵活嵌入，与现有第三方主题的卡片列表样式无缝结合。', 'post-views' ); ?></p>
							</div>
						</div>

						<div class="pv-callout" style="margin-top:0;">
							<div class="pv-callout-title">
								<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
								<?php esc_html_e( 'WP_Query 开发者调用规范：', 'post-views' ); ?>
							</div>
							<div class="pv-callout-body">
								<div class="pv-editor-top" style="margin-bottom:4px;">
									<button type="button" class="pv-btn-copy" data-pv-copy="&dollar;query = new WP_Query( array( 'views_period' => 'week', 'posts_per_page' => 10 ) );">
										<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
										<?php esc_html_e( '复制代码', 'post-views' ); ?>
									</button>
								</div>
								<pre style="background:#0f172a;color:#f8fafc;padding:12px;border-radius:6px;font-size:12px;margin:0;overflow-x:auto;">$query = new WP_Query( array(
    'views_period'   => 'week', // 支持 'today', 'week', 'month', 'all'
    'posts_per_page' => 10,
) );</pre>
							</div>
						</div>
					</div>

					<!-- Card 3: 仪表盘小工具 -->
					<div class="pv-card">
						<div class="pv-card-head">
							<div class="pv-card-icon">
								<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
							</div>
							<div class="pv-card-title-group">
								<h2 class="pv-card-title"><?php esc_html_e( 'WordPress 控制台首页小工具', 'post-views' ); ?></h2>
								<p class="pv-card-desc"><?php esc_html_e( '在后台主页（仪表盘）显示今日与本周热门文章 Top 5 卡片。', 'post-views' ); ?></p>
							</div>
						</div>

						<table class="pv-form-table" role="presentation">
							<tr>
								<th scope="row"><label for="views-enable_dashboard_widget"><?php esc_html_e( '仪表盘首页卡片：', 'post-views' ); ?></label></th>
								<td>
									<?php self::select( 'enable_dashboard_widget', array( 1 => __( '启用控制台首页「今日与本周热门文章 Top 5」卡片', 'post-views' ), 0 => __( '停用', 'post-views' ) ) ); ?>
									<p class="pv-inline-note">
										<?php esc_html_e( '开启后，站长登录后台即可随时洞察最新数据趋势。', 'post-views' ); ?>
										<a href="<?php echo esc_url( admin_url( 'index.php' ) ); ?>"><?php esc_html_e( '立即前往仪表盘查看 &raquo;', 'post-views' ); ?></a>
									</p>
								</td>
							</tr>
						</table>
					</div>
				</div>

				<!-- =========================================================
				 * TAB 3: 高并发与防刷引擎
				 * ========================================================= -->
				<div id="tab-engine" class="pv-tab-content" style="display:none;">
					<!-- Card 1: 计数模式与轻量端点协议 -->
					<div class="pv-card">
						<div class="pv-card-head">
							<div class="pv-card-icon">
								<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
							</div>
							<div class="pv-card-title-group">
								<h2 class="pv-card-title"><?php esc_html_e( '计数模式与轻量端点协议', 'post-views' ); ?></h2>
								<p class="pv-card-desc"><?php esc_html_e( '支持现代化 REST API 异步端点与 UV 去重防刷机制，保障数据真实性。', 'post-views' ); ?></p>
							</div>
						</div>

						<table class="pv-form-table" role="presentation">
							<tr>
								<th scope="row"><label for="views-count_mode"><?php esc_html_e( '统计模式 (PV / UV)：', 'post-views' ); ?></label></th>
								<td>
									<?php
									self::select(
										'count_mode',
										array(
											0 => __( 'PV 模式（每次访问/刷新均自增 1）', 'post-views' ),
											1 => __( 'UV 模式（同一访客 24 小时内对同一文章仅计入 1 次）', 'post-views' ),
										)
									);
									?>
									<p class="pv-inline-note"><?php esc_html_e( 'UV 模式利用客户端本地指纹与时间戳自动去重，有效排除单人高频重复刷新。', 'post-views' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="views-api_transport"><?php esc_html_e( '异步数据上报协议：', 'post-views' ); ?></label></th>
								<td>
									<?php
									self::select(
										'api_transport',
										array(
											'rest' => __( 'REST API 现代化端点 (/wp-json/post-views/v1/hit) [推荐]', 'post-views' ),
											'ajax' => __( '传统兼容端点 (admin-ajax.php)', 'post-views' ),
										)
									);
									?>
									<p class="pv-inline-note">
										<span style="color:#16a34a;font-weight:600;">● REST API 端点就绪</span> — <?php esc_html_e( '绕过庞大的 admin-ajax.php 主循环，大幅提升并发吞吐量并减轻服务器 CPU 负荷。', 'post-views' ); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="views-count"><?php esc_html_e( '有效访客过滤：', 'post-views' ); ?></label></th>
								<td>
									<?php self::select( 'count', array( 0 => __( '所有人', 'post-views' ), 1 => __( '仅游客', 'post-views' ), 2 => __( '仅已登录用户', 'post-views' ) ) ); ?>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="views-exclude_bots"><?php esc_html_e( '排除爬虫机器人：', 'post-views' ); ?></label></th>
								<td>
									<?php self::select( 'exclude_bots', array( 1 => __( '是（自动识别主流搜索引擎与爬虫 User-Agent）', 'post-views' ), 0 => __( '否', 'post-views' ) ) ); ?>
								</td>
							</tr>
						</table>
					</div>

					<!-- Card 2: 防秒退判定与高并发缓冲池 -->
					<div class="pv-card">
						<div class="pv-card-head">
							<div class="pv-card-icon">
								<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
							</div>
							<div class="pv-card-title-group">
								<h2 class="pv-card-title"><?php esc_html_e( '防秒退判定与高并发缓冲池', 'post-views' ); ?></h2>
								<p class="pv-card-desc"><?php esc_html_e( '通过有效停留与深度判定过滤误触秒退；利用 Redis / Memcached 对象缓存缓冲池彻底避免高并发锁表。', 'post-views' ); ?></p>
							</div>
						</div>

						<table class="pv-form-table" role="presentation">
							<tr>
								<th scope="row"><label for="views-dwell_time"><?php esc_html_e( '有效停留时长门槛：', 'post-views' ); ?></label></th>
								<td>
									<input type="number" id="views-dwell_time" name="<?php echo esc_attr( $option . '[dwell_time]' ); ?>" value="<?php echo esc_attr( PostViews_Options::get_int( 'dwell_time' ) ); ?>" min="0" max="300" class="pv-input-number" style="width:90px;" />
									<?php esc_html_e( '秒', 'post-views' ); ?>
									<p class="pv-inline-note"><?php esc_html_e( '访客在页面停留超过指定秒数才触发计数（0 = 立即计入）。建议设置 5~10 秒，有效过滤秒退跳出。', 'post-views' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="views-scroll_depth"><?php esc_html_e( '有效滚动深度门槛：', 'post-views' ); ?></label></th>
								<td>
									<input type="number" id="views-scroll_depth" name="<?php echo esc_attr( $option . '[scroll_depth]' ); ?>" value="<?php echo esc_attr( PostViews_Options::get_int( 'scroll_depth' ) ); ?>" min="0" max="100" class="pv-input-number" style="width:90px;" />
									%
									<p class="pv-inline-note"><?php esc_html_e( '访客向下滚动浏览达到正文百分比后才触发计数（0 = 立即计入）。', 'post-views' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="views-enable_buffer"><?php esc_html_e( '高并发写入缓冲池：', 'post-views' ); ?></label></th>
								<td>
									<?php self::select( 'enable_buffer', array( 0 => __( '关闭（即时写入 MySQL）', 'post-views' ), 1 => __( '开启对象缓存缓冲池聚合落库 [大流量推荐]', 'post-views' ) ) ); ?>
								<p class="pv-inline-note"><?php esc_html_e( '开启后，高并发访问下的浏览量将先暂存聚合于 Redis / Memcached 对象缓存中，批量落库，彻底避免 MySQL 行锁冲突。未检测到外部对象缓存时自动改为即时写入，不会丢失数据。', 'post-views' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="views-buffer_threshold"><?php esc_html_e( '缓冲批量落库阈值：', 'post-views' ); ?></label></th>
								<td>
									<input type="number" id="views-buffer_threshold" name="<?php echo esc_attr( $option . '[buffer_threshold]' ); ?>" value="<?php echo esc_attr( PostViews_Options::get_int( 'buffer_threshold', 50 ) ); ?>" min="10" max="500" class="pv-input-number" style="width:90px;" />
									<?php esc_html_e( '次', 'post-views' ); ?>
									<p class="pv-inline-note"><?php esc_html_e( '累积达到指定次数时自动触发批量写入，后台定时 Cron 亦会每分钟自动保底写入。', 'post-views' ); ?></p>
								</td>
							</tr>
						</table>

						<?php if ( 1 === PostViews_Options::get_int( 'enable_buffer' ) ) : ?>
							<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:14px 18px;margin-top:16px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
								<div>
									<strong style="color:#1e40af;"><?php esc_html_e( '当前缓冲池状态：', 'post-views' ); ?></strong>
									<span style="color:#1e3a8a;"><?php printf( esc_html__( '共有 %d 条浏览计数待落库', 'post-views' ), (int) $buffered_count ); ?></span>
								</div>
								<button type="submit" form="pv-flush-buffer-form" class="pv-btn-outline" style="border-color:#2563eb;color:#2563eb;font-weight:600;">
									⚡ <?php esc_html_e( '立即手动落库清空缓冲', 'post-views' ); ?>
								</button>
							</div>
						<?php endif; ?>
					</div>
				</div>

				<!-- =========================================================
				 * TAB 4: 区块与阅读时长
				 * ========================================================= -->
				<div id="tab-editor" class="pv-tab-content" style="display:none;">
					<!-- Card 1: 阅读时长配置 -->
					<div class="pv-card">
						<div class="pv-card-head">
							<div class="pv-card-icon">
								<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
							</div>
							<div class="pv-card-title-group">
								<h2 class="pv-card-title"><?php esc_html_e( '文章字数与预计阅读时长配置', 'post-views' ); ?></h2>
								<p class="pv-card-desc"><?php esc_html_e( '采用中英双语精准分词，自动计算正文总字数与预计阅读时长，支持在模板中无缝输出。', 'post-views' ); ?></p>
							</div>
						</div>

						<table class="pv-form-table" role="presentation">
							<tr>
								<th scope="row"><label for="views-words_per_minute"><?php esc_html_e( '平均阅读速度：', 'post-views' ); ?></label></th>
								<td>
									<input type="number" id="views-words_per_minute" name="<?php echo esc_attr( $option . '[words_per_minute]' ); ?>" value="<?php echo esc_attr( PostViews_Options::get_int( 'words_per_minute', 300 ) ); ?>" min="50" max="1000" class="pv-input-number" style="width:100px;" />
									<?php esc_html_e( '字/分钟 (默认 300)', 'post-views' ); ?>
									<p class="pv-inline-note"><?php esc_html_e( '系统采用中英双语精准分词，自动计算正文总字数与预计阅读时间。', 'post-views' ); ?></p>
								</td>
							</tr>
						</table>

						<div class="pv-callout">
							<div class="pv-callout-title">
								<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
								<?php esc_html_e( '占位符使用示例：', 'post-views' ); ?>
							</div>
							<div class="pv-callout-body">
								可以在单篇文章模板中输入：<br />
								<code style="background:#fff;border:1px solid #bfdbfe;padding:2px 6px;border-radius:4px;color:#0369a1;">本文约 %WORD_COUNT% 字，预计阅读 %READ_TIME%，已有 %VIEW_COUNT% 次浏览</code><br />
								前端将解析呈现为：<strong>本文约 2,450 字，预计阅读 5 分钟，已有 1,234 次浏览</strong>
							</div>
						</div>
					</div>

					<!-- Card 2: 区块生态 -->
					<div class="pv-card">
						<div class="pv-card-head">
							<div class="pv-card-icon">
								<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
							</div>
							<div class="pv-card-title-group">
								<h2 class="pv-card-title"><?php esc_html_e( 'Gutenberg 原生区块生态', 'post-views' ); ?></h2>
								<p class="pv-card-desc"><?php esc_html_e( '支持在块编辑器中键入 /views 随时插入原生计数与榜单区块。', 'post-views' ); ?></p>
							</div>
						</div>

						<div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(280px, 1fr));gap:16px;">
							<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:18px;">
								<div style="font-weight:700;font-size:14px;color:#1e293b;display:flex;align-items:center;gap:6px;">
									<span>👁️</span> post-views/counter
								</div>
								<div style="font-size:12px;color:#64748b;margin:4px 0 8px 0;"><?php esc_html_e( '文章阅读计数器区块', 'post-views' ); ?></div>
								<p style="font-size:13px;color:#475569;margin:0;line-height:1.5;"><?php esc_html_e( '在正文任意位置插入当前文章阅读量胶囊，可在右侧检查器快速微调显示样式。', 'post-views' ); ?></p>
							</div>

							<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:18px;">
								<div style="font-weight:700;font-size:14px;color:#1e293b;display:flex;align-items:center;gap:6px;">
									<span>🔥</span> post-views/trending
								</div>
								<div style="font-size:12px;color:#64748b;margin:4px 0 8px 0;"><?php esc_html_e( '热门文章排行榜区块', 'post-views' ); ?></div>
								<p style="font-size:13px;color:#475569;margin:0;line-height:1.5;"><?php esc_html_e( '在侧边栏、正文或全站模板中插入今日、本周或本月的热门排行榜列表。', 'post-views' ); ?></p>
							</div>
						</div>
					</div>

					<!-- Card 3: 短代码 -->
					<div class="pv-card">
						<div class="pv-card-head">
							<div class="pv-card-icon">
								<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/></svg>
							</div>
							<div class="pv-card-title-group">
								<h2 class="pv-card-title"><?php esc_html_e( '短代码 (Shortcodes) 快捷指南', 'post-views' ); ?></h2>
								<p class="pv-card-desc"><?php esc_html_e( '全站通用的轻量短代码，点击即可快速复制。', 'post-views' ); ?></p>
							</div>
						</div>

						<table class="pv-form-table" role="presentation">
							<tr>
								<th scope="row"><code style="background:#eff6ff;color:#2563eb;padding:3px 8px;border-radius:4px;border:1px solid #dbeafe;">[views]</code></th>
								<td>
									<?php esc_html_e( '输出当前文章的阅读量标签。支持传入 id 参数指定文章：[views id="123"]', 'post-views' ); ?>
									<button type="button" class="pv-btn-copy" data-pv-copy="[views]" style="margin-left:8px;">
										<?php esc_html_e( '复制短代码', 'post-views' ); ?>
									</button>
								</td>
							</tr>
							<tr>
								<th scope="row"><code style="background:#eff6ff;color:#2563eb;padding:3px 8px;border-radius:4px;border:1px solid #dbeafe;">[trending_views]</code></th>
								<td>
									<?php esc_html_e( '输出热门排行列表。参数：period="today|week|month|all" limit="10"', 'post-views' ); ?>
									<button type="button" class="pv-btn-copy" data-pv-copy='[trending_views period="week" limit="10"]' style="margin-left:8px;">
										<?php esc_html_e( '复制短代码', 'post-views' ); ?>
									</button>
								</td>
							</tr>
						</table>
					</div>
				</div>

				<!-- =========================================================
				 * TAB 5: 初始底数与数据运维
				 * ========================================================= -->
				<div id="tab-tools" class="pv-tab-content" style="display:none;">
					<!-- Card 1: 全局随机底数与倍率 -->
					<div class="pv-card">
						<div class="pv-card-head">
							<div class="pv-card-icon">
								<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
							</div>
							<div class="pv-card-title-group">
								<h2 class="pv-card-title"><?php esc_html_e( '新文章初始底数与虚拟倍率全局自动化配置 (运营利器)', 'post-views' ); ?></h2>
								<p class="pv-card-desc"><?php esc_html_e( '为新发布文章自动赋予初始浏览底数，以及设置全局浏览量放大倍率，彻底解决新文章发布后前台浏览量为 0 的冷启动尴尬。', 'post-views' ); ?></p>
							</div>
						</div>

						<table class="pv-form-table" role="presentation">
							<tr>
								<th scope="row"><label for="views-auto_random_offset"><?php esc_html_e( '新文章自动随机底数：', 'post-views' ); ?></label></th>
								<td>
									<label class="pv-checkbox-item">
										<input type="hidden" name="<?php echo esc_attr( $option . '[auto_random_offset]' ); ?>" value="0" />
										<input type="checkbox" id="views-auto_random_offset" name="<?php echo esc_attr( $option . '[auto_random_offset]' ); ?>" value="1" <?php checked( PostViews_Options::get( 'auto_random_offset', 0 ), 1 ); ?> />
										<strong><?php esc_html_e( '开启：新文章发布时自动赋予随机初始底数', 'post-views' ); ?></strong>
									</label>
									<p class="pv-inline-note">
										<?php esc_html_e( '启用后，任何新文章在首次点击“发布”时，系统将自动在下方区间内随机生成一个底数保存到该文章的 views_offset 中，已有文章不受影响。', 'post-views' ); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( '随机初始底数区间：', 'post-views' ); ?></th>
								<td>
									<label for="views-auto_offset_min">
										<?php esc_html_e( '从', 'post-views' ); ?>
										<input type="number" id="views-auto_offset_min" name="<?php echo esc_attr( $option . '[auto_offset_min]' ); ?>" value="<?php echo esc_attr( PostViews_Options::get_int( 'auto_offset_min', 100 ) ); ?>" min="0" max="9999999" class="pv-input-number" style="width:110px;" />
									</label>
									<label for="views-auto_offset_max" style="margin-left:10px;">
										<?php esc_html_e( '至', 'post-views' ); ?>
										<input type="number" id="views-auto_offset_max" name="<?php echo esc_attr( $option . '[auto_offset_max]' ); ?>" value="<?php echo esc_attr( PostViews_Options::get_int( 'auto_offset_max', 300 ) ); ?>" min="0" max="9999999" class="pv-input-number" style="width:110px;" />
										<?php esc_html_e( '次阅读', 'post-views' ); ?>
									</label>
									<p class="pv-inline-note">
										<?php esc_html_e( '默认范围为 100 ~ 300。新文章发布时在此区间闭区间内随机取整数赋值。', 'post-views' ); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="views-global_multiplier"><?php esc_html_e( '全局虚拟放大倍率：', 'post-views' ); ?></label></th>
								<td>
									<input type="number" step="0.1" id="views-global_multiplier" name="<?php echo esc_attr( $option . '[global_multiplier]' ); ?>" value="<?php echo esc_attr( PostViews_Options::get( 'global_multiplier', 1.0 ) ); ?>" min="0.1" max="100.0" class="pv-input-number" style="width:110px;" />
									<?php esc_html_e( '倍 (默认 1.0 为真实无放大)', 'post-views' ); ?>
									<p class="pv-inline-note">
										<?php esc_html_e( '全局统一倍率。如设置为 1.5，真实每产生 10 次阅读，前台呈现 15 次。若文章在单篇编辑页单独配置了专属倍率，则单篇优先。', 'post-views' ); ?>
									</p>
								</td>
							</tr>
						</table>

						<div class="pv-callout">
							<div class="pv-callout-title">
								<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
								<?php esc_html_e( '前台最终展示阅读量联动计算公式：', 'post-views' ); ?>
							</div>
							<div class="pv-callout-body">
								<code style="background:#fff;border:1px solid #bfdbfe;padding:2px 8px;border-radius:4px;color:#0369a1;font-weight:600;">前台展示阅读量 = round( 真实阅读量 × 虚拟倍率 ) + 虚拟初始底数 (Offset)</code><br />
								<span style="color:#0284c7;font-size:12px;display:inline-block;margin-top:4px;">
									<?php esc_html_e( '公式示例：真实浏览 20 次，全局倍率 1.5，随机初始底数 200 => 前台最终呈现为：round(20 × 1.5) + 200 = 230 次浏览。', 'post-views' ); ?>
								</span>
							</div>
						</div>
					</div>

					<!-- Card 2: Meta Box 管理 -->
					<div class="pv-card">
						<div class="pv-card-head">
							<div class="pv-card-icon">
								<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
							</div>
							<div class="pv-card-title-group">
								<h2 class="pv-card-title"><?php esc_html_e( '文章编辑页单篇管理面板 (Meta Box)', 'post-views' ); ?></h2>
								<p class="pv-card-desc"><?php esc_html_e( '在 WordPress 文章与页面编辑页右侧边栏注入独立管理面板，支持实时手动微调。', 'post-views' ); ?></p>
							</div>
						</div>

						<table class="pv-form-table" role="presentation">
							<tr>
								<th scope="row"><label for="views-enable_meta_box"><?php esc_html_e( '编辑页侧边栏面板：', 'post-views' ); ?></label></th>
								<td>
									<?php self::select( 'enable_meta_box', array( 1 => __( '在文章与页面编辑页面右侧边栏显示「浏览量设置」面板', 'post-views' ), 0 => __( '停用', 'post-views' ) ) ); ?>
									<p class="pv-inline-note">
										<?php esc_html_e( '开启后，站长在后台【文章】->【写文章 / 编辑文章】右侧边栏中可随时：', 'post-views' ); ?>
									</p>
									<ul style="list-style:disc;padding-left:20px;margin-top:6px;color:#475569;font-size:13px;line-height:1.7;">
										<li><strong><?php esc_html_e( '手动修正实际阅读量：', 'post-views' ); ?></strong><?php esc_html_e( '校准文章真实的 views 累计计数；', 'post-views' ); ?></li>
										<li><strong><?php esc_html_e( '自定义虚拟底数 (Offset)：', 'post-views' ); ?></strong><?php esc_html_e( '单独为此文章覆盖设定初始底数（如 500 次）；', 'post-views' ); ?></li>
										<li><strong><?php esc_html_e( '自定义虚拟倍率 (Multiplier)：', 'post-views' ); ?></strong><?php esc_html_e( '单独为此文章指定专属放大倍率（如 2.0 倍）；', 'post-views' ); ?></li>
										<li><strong><?php esc_html_e( '实时公式联动计算：', 'post-views' ); ?></strong><?php esc_html_e( '编辑框内输入数值即时呈现前台最终展示结果，所见即所得。', 'post-views' ); ?></li>
									</ul>
								</td>
							</tr>
						</table>
					</div>

					<!-- Card 3: 历史数据迁移 -->
					<div class="pv-card">
						<div class="pv-card-head">
							<div class="pv-card-icon">
								<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
							</div>
							<div class="pv-card-title-group">
								<h2 class="pv-card-title"><?php esc_html_e( '跨主题 / 竞品插件数据一键迁移', 'post-views' ); ?></h2>
								<p class="pv-card-desc"><?php esc_html_e( '自动深度扫描数据库中第三方主题或插件的历史浏览数据（如 pageviews、post_views_count 等），仅导入目前 views 字段为空或为 0 的文章，绝不覆盖已有正常计数。', 'post-views' ); ?></p>
							</div>
						</div>

						<div class="pv-callout" style="margin-top:0;">
							<div class="pv-callout-title">
								<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
								<?php esc_html_e( '智能扫描结果看板：', 'post-views' ); ?>
							</div>
							<div class="pv-callout-body">
								<?php if ( empty( $legacy_stats ) ) : ?>
									<p style="color:#64748b;margin:4px 0 0 0;font-size:13px;"><?php esc_html_e( '当前数据库中未检测到残留的第三方主题历史字段。', 'post-views' ); ?></p>
								<?php else : ?>
									<ul style="margin:4px 0 0 0;list-style:disc;padding-left:20px;font-size:13px;">
										<?php foreach ( $legacy_stats as $meta_key => $count ) : ?>
											<li>字段 <code><?php echo esc_html( $meta_key ); ?></code>：检测到 <strong><?php echo esc_html( $count ); ?></strong> 篇文章的历史数据可供迁移。</li>
										<?php endforeach; ?>
									</ul>
								<?php endif; ?>
							</div>
						</div>

						<p style="margin:16px 0 0 0;">
							<button type="submit" form="pv-migrate-form" class="pv-btn-primary">
								🔄 <?php esc_html_e( '一键安全扫描并导入历史数据', 'post-views' ); ?>
							</button>
						</p>
					</div>
				</div>

				<!-- Floating / Docked Footer Bar -->
				<div class="pv-footer-bar">
					<div class="pv-footer-left">
						<button type="submit" class="pv-btn-save" id="pv-submit-btn">
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
								<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
								<polyline points="17 21 17 13 7 13 7 21"/>
								<polyline points="7 3 7 8 15 8"/>
							</svg>
							<?php esc_html_e( '保存所有设置', 'post-views' ); ?>
						</button>
					</div>
					<div class="pv-footer-right">
						<span class="pv-status-indicator">
							<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
							<?php esc_html_e( '设置已准备就绪', 'post-views' ); ?>
						</span>
						<span class="pv-footer-divider">|</span>
						<button type="button" class="pv-btn-reset-all" id="pv-reset-all-btn">
							<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
							<?php esc_html_e( '重置为默认', 'post-views' ); ?>
						</button>
					</div>
				</div>
			</form>

			<!-- Ancillary action forms -->
			<form id="pv-flush-buffer-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="postviews_flush_buffer" />
				<?php wp_nonce_field( 'postviews_flush_buffer' ); ?>
			</form>
			<form id="pv-migrate-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="postviews_migrate_legacy" />
				<?php wp_nonce_field( 'postviews_migrate_legacy' ); ?>
			</form>
		</div>

		<?php
	}
}
