<?php
/**
 * The legacy API shims themes and plugins were written against.
 *
 * @package WP-PostViews
 */

/**
 * Compat layer: should_views_be_displayed() and the $post->views population.
 */
class Test_PostViews_Compat extends PostViews_TestCase {

	/**
	 * The global shim resolves against the passed options array.
	 *
	 * JustNews calls should_views_be_displayed( $views_options ) from single.php,
	 * so the legacy signature - an argument, not the plugin's own option row -
	 * has to be honoured.
	 *
	 * @return void
	 */
	public function test_should_views_be_displayed_reads_the_passed_options() {
		$post_id = $this->make_post( array(), 5 );
		$this->set_context( array( 'is_single', 'is_singular' ), $post_id );

		$options = array(
			'display_single' => 2,
			'display_home'   => 0,
		);

		$this->assertFalse( should_views_be_displayed( $options ) );
	}

	/**
	 * A mode of 1 shows the count to logged-in users only.
	 *
	 * @return void
	 */
	public function test_should_views_be_displayed_logged_in_only() {
		$post_id = $this->make_post( array(), 5 );
		$this->set_context( array( 'is_single', 'is_singular' ), $post_id );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertTrue( should_views_be_displayed( array( 'display_single' => 1 ) ) );
	}

	/**
	 * A mode of 0 (everyone) is visible even when logged out.
	 *
	 * @return void
	 */
	public function test_should_views_be_displayed_everyone() {
		$post_id = $this->make_post( array(), 5 );
		$this->set_context( array( 'is_single', 'is_singular' ), $post_id );

		$this->assertTrue( should_views_be_displayed( array( 'display_single' => 0 ) ) );
	}

	/**
	 * A falsy argument falls back to the plugin's own settings.
	 *
	 * @return void
	 */
	public function test_should_views_be_displayed_falls_back_to_stored_options() {
		$post_id = $this->make_post( array(), 5 );
		$this->set_context( array( 'is_single', 'is_singular' ), $post_id );

		$this->set_options( array( 'display_single' => 2 ) );

		$this->assertFalse( should_views_be_displayed() );
	}

	/**
	 * The the_posts filter fills $post->views from the views meta.
	 *
	 * JustNews reads `$post->views ?: 0` on listing cards, which only works
	 * if the property is populated. That is the second half of the shim.
	 *
	 * @return void
	 */
	public function test_the_posts_populates_views_property() {
		$post_id = $this->make_post( array(), 123456 );

		$query = new WP_Query(
			array(
				'post_type'      => 'post',
				'posts_per_page' => 10,
			)
		);

		$this->assertNotEmpty( $query->posts );
		foreach ( $query->posts as $post ) {
			if ( (int) $post->ID === $post_id ) {
				$this->assertSame( 123456, $post->views );
				return;
			}
		}

		$this->fail( 'The seeded post was not part of the queried set.' );
	}

	/**
	 * A post without a count leaves $post->views absent, not zero.
	 *
	 * Keeping the property unset lets the theme's `?: 0` fallback take over
	 * instead of short-circuiting on a literal 0 that was set here.
	 *
	 * @return void
	 */
	public function test_the_posts_leaves_unviewed_posts_unset() {
		$post_id = $this->make_post( array(), null );

		$query = new WP_Query(
			array(
				'post_type'      => 'post',
				'posts_per_page' => 10,
			)
		);

		$this->assertNotEmpty( $query->posts );
		foreach ( $query->posts as $post ) {
			if ( (int) $post->ID === $post_id ) {
				$this->assertFalse( isset( $post->views ) );
				return;
			}
		}

		$this->fail( 'The seeded post was not part of the queried set.' );
	}

	/**
	 * An empty post set passes straight through.
	 *
	 * @return void
	 */
	public function test_the_posts_with_empty_set_is_a_noop() {
		$posts = array();

		$this->assertSame( $posts, postviews_populate_views_property( $posts, new WP_Query() ) );
	}

	/**
	 * The injector script is enqueued on singular kuaixun views.
	 *
	 * JustNews renders kuaixun detail pages through the_excerpt(), which the
	 * plugin's the_content filter cannot reach. The fallback path enqueues a
	 * small DOM injector that appends the count after .entry-content.
	 *
	 * @return void
	 */
	public function test_kuaixun_injector_enqueued_on_singular_kuaixun() {
		register_post_type( 'kuaixun' );

		$post_id = $this->make_post( array( 'post_type' => 'kuaixun' ), 123456 );
		$this->set_context( array( 'is_singular', 'is_single' ), $post_id );
		$this->set_options( array( 'display_kuaixun_views' => 1 ) );

		PostViews_Display::maybe_enqueue_kuaixun_injector();

		$this->assertTrue( wp_script_is( 'post-views-kuaixun', 'enqueued' ) );
	}

	/**
	 * The injector is not enqueued on other post types.
	 *
	 * @return void
	 */
	public function test_kuaixun_injector_skipped_on_other_post_types() {
		register_post_type( 'kuaixun' );

		$post_id = $this->make_post( array(), 123456 );
		$this->set_context( array( 'is_singular', 'is_single' ), $post_id );
		$this->set_options( array( 'display_kuaixun_views' => 1 ) );

		PostViews_Display::maybe_enqueue_kuaixun_injector();

		$this->assertFalse( wp_script_is( 'post-views-kuaixun', 'enqueued' ) );
	}

	/**
	 * The injector is not enqueued while the switch is off.
	 *
	 * @return void
	 */
	public function test_kuaixun_injector_skipped_when_switch_off() {
		register_post_type( 'kuaixun' );

		$post_id = $this->make_post( array( 'post_type' => 'kuaixun' ), 123456 );
		$this->set_context( array( 'is_singular', 'is_single' ), $post_id );

		PostViews_Display::maybe_enqueue_kuaixun_injector();

		$this->assertFalse( wp_script_is( 'post-views-kuaixun', 'enqueued' ) );
	}
}
