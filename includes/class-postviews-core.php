<?php
/**
 * Plumbing that is neither counting nor display: the ?v_sortby= query vars,
 * seeding the meta key on publish, and the REST field.
 *
 * @package Post-Views
 */

defined( 'ABSPATH' ) || exit;

/**
 * Query var sorting, publish hook and REST exposure.
 */
class PostViews_Core {

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'maybe_sort_by_views' ) );

		add_action( 'transition_post_status', array( __CLASS__, 'seed_views_meta_on_transition' ), 10, 3 );

		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_field' ) );
	}

	/**
	 * Expose ?v_sortby=views&v_orderby=asc to the front end.
	 *
	 * @param array $public_query_vars Registered public query vars.
	 * @return array
	 */
	public static function query_vars( $public_query_vars ) {
		$public_query_vars[] = 'v_sortby';
		$public_query_vars[] = 'v_orderby';

		return $public_query_vars;
	}

	/**
	 * Attach or detach the sorting filters for a query.
	 *
	 * The detach half matters as much as the attach half: these are global
	 * filters, so leaving them on after the sorted query has run would join
	 * every later query on the request to postmeta.
	 *
	 * @param WP_Query $query The query being prepared.
	 * @return void
	 */
	public static function maybe_sort_by_views( $query ) {
		if ( 'views' === $query->get( 'v_sortby' ) ) {
			add_filter( 'posts_fields', array( __CLASS__, 'posts_fields' ) );
			add_filter( 'posts_join', array( __CLASS__, 'posts_join' ) );
			add_filter( 'posts_where', array( __CLASS__, 'posts_where' ) );
			add_filter( 'posts_orderby', array( __CLASS__, 'posts_orderby' ) );

			return;
		}

		remove_filter( 'posts_fields', array( __CLASS__, 'posts_fields' ) );
		remove_filter( 'posts_join', array( __CLASS__, 'posts_join' ) );
		remove_filter( 'posts_where', array( __CLASS__, 'posts_where' ) );
		remove_filter( 'posts_orderby', array( __CLASS__, 'posts_orderby' ) );
	}

	/**
	 * Select the view count alongside the post columns.
	 *
	 * @param string $content The SELECT list.
	 * @return string
	 */
	public static function posts_fields( $content ) {
		return $content . ', ( postviews_pm.meta_value + 0 ) AS views';
	}

	/**
	 * Join postmeta.
	 *
	 * Joined under a dedicated alias: a query that already joins postmeta on
	 * its own (a meta_query, for instance) would otherwise collide with the
	 * bare table name and die on "Not unique table/alias".
	 *
	 * @param string $content The JOIN clause.
	 * @return string
	 */
	public static function posts_join( $content ) {
		global $wpdb;

		return $content . " LEFT JOIN $wpdb->postmeta AS postviews_pm ON postviews_pm.post_id = $wpdb->posts.ID";
	}

	/**
	 * Restrict the joined rows to the views key.
	 *
	 * @param string $content The WHERE clause.
	 * @return string
	 */
	public static function posts_where( $content ) {
		return $content . " AND postviews_pm.meta_key = 'views'";
	}

	/**
	 * Order by the selected view count.
	 *
	 * The incoming clause is discarded rather than appended to: sorting by view
	 * count is the whole point of the query var, so it replaces whatever
	 * ordering was in place.
	 *
	 * @param string $content The ORDER BY clause. Intentionally unused.
	 * @return string
	 */
	public static function posts_orderby( $content ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$orderby = strtolower( trim( (string) get_query_var( 'v_orderby' ) ) );

		// Validated against a fixed pair rather than escaped: this is
		// interpolated into ORDER BY, where neither prepare() nor an escape
		// helper can bind an identifier or a direction.
		if ( ! in_array( $orderby, array( 'asc', 'desc' ), true ) ) {
			$orderby = 'desc';
		}

		return " views $orderby";
	}

	/**
	 * Give a newly published post a zero view count.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function seed_views_meta( $post_id ) {
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		// The $unique flag is what stops a republish resetting a real count.
		add_post_meta( $post_id, 'views', 0, true );
	}

	/**
	 * Seed the count when a post transitions to publish.
	 *
	 * Runs on the status transition rather than the publish_{type} hooks so
	 * every enabled post type is seeded the same way, and so a type the site
	 * switched off does not accumulate zero rows that would surface in the
	 * least-viewed listings.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Previous status.
	 * @param WP_Post $post       The post.
	 * @return void
	 */
	public static function seed_views_meta_on_transition( $new_status, $old_status, $post ) {
		if ( 'publish' !== $new_status || ! $post instanceof WP_Post ) {
			return;
		}

		if ( ! PostViews_Counter::is_countable_post_type( $post->post_type ) ) {
			return;
		}

		self::seed_views_meta( $post->ID );
	}

	/**
	 * Expose the count as a `views` field on the REST post resource.
	 *
	 * @return void
	 */
	public static function register_rest_field() {
		register_rest_field(
			'post',
			'views',
			array(
				'get_callback' => array( __CLASS__, 'rest_get_views' ),
				'schema'       => array(
					'description' => __( '文章被浏览的次数。', 'post-views' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit', 'embed' ),
					'readonly'    => true,
				),
			)
		);
	}

	/**
	 * REST callback for the views field.
	 *
	 * @param array $post The post, as prepared for the response.
	 * @return int
	 */
	public static function rest_get_views( $post ) {
		return (int) get_post_meta( $post['id'], 'views', true );
	}
}
