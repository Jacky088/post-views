<?php
/**
 * Gutenberg / FSE block support for Post Views.
 *
 * Registers server-side dynamic blocks:
 * 1. post-views/counter - displays the current post's view count.
 * 2. post-views/trending - displays the most viewed posts list with period filtering.
 *
 * @package Post-Views
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles block registration and server-side rendering.
 */
class PostViews_Block {

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_blocks' ) );
	}

	/**
	 * Register Gutenberg dynamic blocks.
	 *
	 * @return void
	 */
	public static function register_blocks() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		// Block: Current post view count.
		register_block_type(
			'post-views/counter',
			array(
				'api_version'     => 2,
				'title'           => __( '文章浏览量', 'post-views' ),
				'category'        => 'widgets',
				'icon'            => 'visibility',
				'description'     => __( '在文章或模板中显示浏览量标签。', 'post-views' ),
				'attributes'      => array(
					'postId' => array(
						'type'    => 'number',
						'default' => 0,
					),
				),
				'render_callback' => array( __CLASS__, 'render_counter_block' ),
			)
		);

		// Block: Trending posts list.
		register_block_type(
			'post-views/trending',
			array(
				'api_version'     => 2,
				'title'           => __( '热门文章榜单', 'post-views' ),
				'category'        => 'widgets',
				'icon'            => 'chart-line',
				'description'     => __( '按今日、本周、本月或全部时间展示热门文章排行。', 'post-views' ),
				'attributes'      => array(
					'period' => array(
						'type'    => 'string',
						'default' => 'week',
					),
					'limit'  => array(
						'type'    => 'number',
						'default' => 5,
					),
					'mode'   => array(
						'type'    => 'string',
						'default' => 'post',
					),
				),
				'render_callback' => array( __CLASS__, 'render_trending_block' ),
			)
		);
	}

	/**
	 * Server-side render callback for post-views/counter block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string Rendered HTML.
	 */
	public static function render_counter_block( $attributes ) {
		$post_id = ! empty( $attributes['postId'] ) ? (int) $attributes['postId'] : get_the_ID();
		if ( ! $post_id ) {
			return '';
		}

		$html = PostViews_Display::render_count_template( $post_id );
		$html = apply_filters( 'the_views', $html );

		if ( '' === trim( (string) $html ) ) {
			return '';
		}

		return '<div class="pv-block-counter pv-positioned-wrapper" data-postviews-id="' . esc_attr( (string) $post_id ) . '">' . $html . '</div>';
	}

	/**
	 * Server-side render callback for post-views/trending block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string Rendered HTML.
	 */
	public static function render_trending_block( $attributes ) {
		$period = ! empty( $attributes['period'] ) ? sanitize_key( $attributes['period'] ) : 'week';
		$limit  = ! empty( $attributes['limit'] ) ? max( 1, (int) $attributes['limit'] ) : 5;
		$mode   = ! empty( $attributes['mode'] ) ? sanitize_key( $attributes['mode'] ) : 'post';

		$listing_html = PostViews_Query::render(
			array(
				'period' => $period,
				'limit'  => $limit,
				'mode'   => $mode,
			)
		);

		return '<div class="pv-block-trending wp-block-post-views-trending"><ul class="pv-top-list">' . $listing_html . '</ul></div>';
	}
}
