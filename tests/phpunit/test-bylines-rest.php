<?php
/**
 * Class Test_Bylines_Rest
 *
 * @package Bylines
 */

use Bylines\Rest;
use Bylines\Utils;
use Bylines\Objects\Byline;

/**
 * Test functionality related to rest interactions
 */
class Test_Bylines_Rest extends Bylines_Testcase {

	/**
	 * Enable the native block editor integration before running these tests.
	 * WP resets the global state during tearDown, so we need to fire the 'init'
	 * action again here to make our calls to register_meta work.
	 *
	 * @see https://core.trac.wordpress.org/ticket/48300#comment:6
	 */
	public function setUp() {
		add_filter( 'bylines_use_native_block_editor_meta_box', '__return_true' );
		do_action( 'init' );
		parent::setUp();
	}

	/**
	 * Remove the native block editor integration after running these tests.
	 */
	public function tearDown() {
		remove_filter( 'bylines_use_native_block_editor_meta_box', '__return_true' );
		parent::tearDown();
	}

	/**
	 * Rest bylines endpoint.
	 */
	public function test_rest_bylines_endpoint() {
		$user_id1 = $this->factory->user->create(
			array(
				'display_name' => 'A User 1',
				'role'         => 'administrator',
			)
		);
		$user_id2 = $this->factory->user->create(
			array(
				'display_name' => 'B User 2',
			)
		);
		$user_id3 = $this->factory->user->create(
			array(
				'display_name' => 'C User 3',
			)
		);
		$user_id4 = $this->factory->user->create(
			array(
				'display_name' => 'D User 4',
			)
		);
		$byline1  = Byline::create_from_user( $user_id3 );

		// Test non logged in user cannot access endpoint.
		$request  = new WP_REST_Request( 'GET', '/bylines/v1/bylines' );
		$response = rest_do_request( $request );
		$data     = $response->get_data();
		$this->assertEquals( 401, $data['data']['status'] );

		// Empty search should return all users.
		wp_set_current_user( $user_id1 );
		$request  = new WP_REST_Request( 'GET', '/bylines/v1/bylines' );
		$response = rest_do_request( $request );
		$data     = $response->get_data();
		$this->assertEquals(
			array(
				'A User 1',
				'B User 2',
				'C User 3',
				'D User 4',
				'admin',
			),
			wp_list_pluck( $data, 'label' )
		);
		// Search should default to wildcard.
		$request = new WP_REST_Request( 'GET', '/bylines/v1/bylines' );
		$request->set_query_params( array( 's' => 'use' ) );
		$response = rest_do_request( $request );
		$data     = $response->get_data();
		$this->assertEquals(
			array(
				'A User 1',
				'B User 2',
				'C User 3',
				'D User 4',
			),
			wp_list_pluck( $data, 'label' )
		);
		$request = new WP_REST_Request( 'GET', '/bylines/v1/bylines' );
		$request->set_query_params( array( 's' => 'C U' ) );
		$response = rest_do_request( $request );
		$data     = $response->get_data();
		$this->assertEquals(
			array(
				'C User 3',
			),
			wp_list_pluck( $data, 'label' )
		);
	}

	/**
	 * Meta data registered on posts.
	 */
	public function test_rest_meta_data_registered() {
		$user_id           = $this->factory->user->create(
			array(
				'role' => 'administrator',
			)
		);
		$user_display_name = get_user_by( 'id', $user_id )->data->display_name;
		$byline            = Byline::create_from_user( $user_id );
		$post_id           = $this->factory->post->create(
			array(
				'post_status' => 'publish',
			)
		);
		Utils::set_post_bylines( $post_id, array( $byline ) );
		wp_set_current_user( $user_id );
		$request  = new WP_REST_Request( 'GET', "/wp/v2/posts/{$post_id}" );
		$response = rest_do_request( $request );
		$data     = $response->get_data();
		$this->assertArrayHasKey( 'bylines', $data['meta'] );
		$this->assertEquals( $data['meta']['bylines'][0]['label'], $user_display_name );
	}

	/**
	 * Saving bylines through the rest api.
	 */
	public function test_rest_save_bylines() {
		$user_id = $this->factory->user->create(
			array(
				'role' => 'editor',
			)
		);
		wp_set_current_user( $user_id );
		$post_id = $this->factory->post->create();
		$b1      = Byline::create(
			array(
				'slug'         => 'b1',
				'display_name' => 'Byline 1',
			)
		);
		$b2      = Byline::create(
			array(
				'slug'         => 'b2',
				'display_name' => 'Byline 2',
			)
		);
		$data    = array(
			'meta' => array(
				'bylines' => array(
					array(
						'value' => (string) $b1->term_id,
						'label' => 'Byline 1',
					),
					array(
						'value' => (string) $b2->term_id,
						'label' => 'Byline 2',
					),
				),
			),
		);
		$request = new WP_REST_Request( 'POST', "/wp/v2/posts/{$post_id}" );
		$request->set_body_params( $data );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$bylines = get_bylines( $post_id );
		$this->assertCount( 2, $bylines );
		$this->assertEquals( array( 'b1', 'b2' ), wp_list_pluck( $bylines, 'slug' ) );
	}

	/**
	 * Saving bylines by creating a new user
	 */
	public function test_rest_save_bylines_create_new_user() {
		$user_id = $this->factory->user->create(
			array(
				'role' => 'editor',
			)
		);
		wp_set_current_user( $user_id );
		$post_id = $this->factory->post->create();
		$b1      = Byline::create(
			array(
				'slug'         => 'b1',
				'display_name' => 'Byline 1',
			)
		);
		$user_id = $this->factory->user->create(
			array(
				'display_name'  => 'Foo Bar',
				'user_nicename' => 'foobar',
			)
		);
		$this->assertFalse( Byline::get_by_user_id( $user_id ) );
		$data    = array(
			'meta' => array(
				'bylines' => array(
					array(
						'value' => "u{$user_id}",
						'label' => 'Foo Bar',
					),
					array(
						'value' => (string) $b1->term_id,
						'label' => 'Byline 1',
					),
				),
			),
		);
		$request = new WP_REST_Request( 'POST', "/wp/v2/posts/{$post_id}" );
		$request->set_body_params( $data );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$bylines = get_bylines( $post_id );
		$this->assertCount( 2, $bylines );
		$this->assertEquals( array( 'foobar', 'b1' ), wp_list_pluck( $bylines, 'slug' ) );
		$byline = Byline::get_by_user_id( $user_id );
		$this->assertInstanceOf( 'Bylines\Objects\Byline', $byline );
		$this->assertEquals( 'Foo Bar', $byline->display_name );
	}

	/**
	 * Saving bylines by repurposing an existing user
	 */
	public function test_rest_save_bylines_existing_user() {
		$user_id = $this->factory->user->create(
			array(
				'role' => 'editor',
			)
		);
		wp_set_current_user( $user_id );
		$post_id = $this->factory->post->create();
		$b1      = Byline::create(
			array(
				'slug'         => 'b1',
				'display_name' => 'Byline 1',
			)
		);
		$user_id = $this->factory->user->create(
			array(
				'display_name'  => 'Foo Bar',
				'user_nicename' => 'foobar',
			)
		);
		$byline  = Byline::create_from_user( $user_id );
		$this->assertInstanceOf( 'Bylines\Objects\Byline', $byline );
		$data    = array(
			'meta' => array(
				'bylines' => array(
					array(
						'value' => "u{$user_id}",
						'label' => 'Foo Bar',
					),
					array(
						'value' => (string) $b1->term_id,
						'label' => 'Byline 1',
					),
				),
			),
		);
		$request = new WP_REST_Request( 'POST', "/wp/v2/posts/{$post_id}" );
		$request->set_body_params( $data );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$bylines = get_bylines( $post_id );
		$this->assertCount( 2, $bylines );
		$this->assertEquals( array( 'foobar', 'b1' ), wp_list_pluck( $bylines, 'slug' ) );
	}

	/**
	 * Saving a post without any bylines
	 */
	public function test_rest_save_bylines_none() {
		$user_id = $this->factory->user->create(
			array(
				'role' => 'editor',
			)
		);
		wp_set_current_user( $user_id );
		$post_id = $this->factory->post->create(
			array(
				'post_author' => $user_id,
			)
		);
		$this->assertEquals( $user_id, get_post( $post_id )->post_author );
		$bylines = get_bylines( $post_id );
		$this->assertCount( 1, $bylines );
		$data    = array(
			'meta' => array(
				'bylines' => array(),
			),
		);
		$request = new WP_REST_Request( 'POST', "/wp/v2/posts/{$post_id}" );
		$request->set_body_params( $data );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$bylines = get_bylines( $post_id );
		$this->assertCount( 0, $bylines );
		$this->assertEquals( 0, get_post( $post_id )->post_author );
	}

}
