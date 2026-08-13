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
	 * The kuaixun excerpt appends a count only when the option is on.
	 *
	 * JustNews renders kuaixun detail pages via the_excerpt(), which the
	 * plugin's the_content path cannot reach. The dedicated switch enables a
	 * lightweight count appended to that excerpt.
	 *
	 * @return void
	 */
	public function test_kuaixun_excerpt_appends_views_when_enabled() {
		register_post_type( 'kuaixun' );

		$post_id = $this->make_post( array( 'post_type' => 'kuaixun' ), 123456 );
		$this->set_context( array( 'is_singular', 'is_single' ), $post_id );

		$this->set_options( array( 'display_kuaixun_views' => 1 ) );

		$excerpt = apply_filters( 'the_excerpt', 'The quick update.' );

		$this->assertStringContainsString( 'kx-views', $excerpt );
		$this->assertStringContainsString( '123,456', $excerpt );
		$this->assertStringContainsString( 'The quick update.', $excerpt );
	}

	/**
	 * The kuaixun excerpt is untouched while the option is off.
	 *
	 * @return void
	 */
	public function test_kuaixun_excerpt_untouched_when_disabled() {
		register_post_type( 'kuaixun' );

		$post_id = $this->make_post( array( 'post_type' => 'kuaixun' ), 500 );
		$this->set_context( array( 'is_singular', 'is_single' ), $post_id );

		$this->assertSame( 'The quick update.', apply_filters( 'the_excerpt', 'The quick update.' ) );
	}

	/**
	 * Non-kuaixun singular pages never get the appended count.
	 *
	 * @return void
	 */
	public function test_kuaixun_excerpt_skips_other_post_types() {
		register_post_type( 'kuaixun' );

		$post_id = $this->make_post( array(), 500 );
		$this->set_context( array( 'is_singular', 'is_single' ), $post_id );

		$this->set_options( array( 'display_kuaixun_views' => 1 ) );

		$this->assertSame( 'A normal post.', apply_filters( 'the_excerpt', 'A normal post.' ) );
	}

	/**
	 * A kuaixun excerpt on a non-singular page (listing) is left alone.
	 *
	 * the_excerpt() is also the standard listing summary, so the scoping has
	 * to be tight enough not to stamp a count onto every card.
	 *
	 * @return void
	 */
	public function test_kuaixun_excerpt_skips_listing_context() {
		register_post_type( 'kuaixun' );

		$post_id = $this->make_post( array( 'post_type' => 'kuaixun' ), 500 );
		$this->set_context( array(), $post_id );

		$this->set_options( array( 'display_kuaixun_views' => 1 ) );

		$this->assertSame( 'The quick update.', apply_filters( 'the_excerpt', 'The quick update.' ) );
	}
}
