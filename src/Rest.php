<?php
/**
 * Rest endpoints and functionality
 *
 * @package Bylines
 */

namespace Bylines;

use Bylines\Objects\Byline;

/**
 * Rest endpoints and functionality
 */
class Rest {

	/**
	 * Register meta
	 */
	public static function register_meta() {
		foreach ( Content_Model::get_byline_supported_post_types() as $post_type ) {
			register_meta(
				'post',
				'bylines',
				array(
					'object_subtype' => $post_type,
					'single'         => true,
					'type'           => 'array',
					'show_in_rest'   => array(
						'schema' => array(
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'value' => array(
										'type' => 'string',
									),
									'label' => array(
										'type' => 'string',
									),
								),
							),
						),
					),
				)
			);
		}
	}

	/**
	 * Instead of writing loads of custom logic for the rest api
	 * and the block editor, we "fake" a metadata that contains
	 * the bylines and then use that during the save to set the
	 * byline terms.
	 *
	 * @param mixed  $value     The value to return, either a single metadata value or an array
	 *                          of values depending on the value of `$single`. Default null.
	 * @param int    $object_id ID of the object metadata is for.
	 * @param string $meta_key  Metadata key.
	 */
	public static function filter_meta( $value, $object_id, $meta_key ) {
		if ( 'bylines' !== $meta_key ) {
			return $value;
		}
		$bylines = array();
		foreach ( get_bylines( $object_id ) as $byline ) {
			$display_name        = $byline->display_name;
			$term                = is_a( $byline, 'WP_User' ) ? 'u' . $byline->ID : $byline->term_id;
			$byline_display_data = (object) array(
				'value' => (string) $term,
				'label' => $display_name,
			);
			$bylines[]           = $byline_display_data;
		}
		return array( $bylines );
	}

	/**
	 * Register the bylines rest api route.
	 */
	public static function register_route() {
		register_rest_route(
			'bylines/v1',
			'/bylines',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( 'Bylines\Rest', 'get_bylines' ),
					'permission_callback' => function() {
						return current_user_can( get_taxonomy( 'byline' )->cap->assign_terms );
					},
					'args'                => array(
						's' => array(
							'description' => 'A search string.',
							'type'        => 'string',
						),
					),
				),
			)
		);
	}

	/**
	 * Get the possible bylines for a given search query.
	 *
	 * @param array $request Query variables such as search.
	 */
	public static function get_bylines( $request ) {
		$bylines       = array();
		$ignored_users = array();
		$term_args     = array(
			'taxonomy'   => 'byline',
			'hide_empty' => false,
			'number'     => 80,
		);
		if ( ! empty( $request['s'] ) ) {
			$term_args['search'] = (string) $request['s'];
		}
		$terms = get_terms( $term_args );
		if ( $terms && ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$byline_object = Byline::get_by_term_id( $term->term_id );

				$byline_data = array(
					'value' => (string) $term->term_id,
					'label' => $term->name,
				);
				$bylines[]   = $byline_data;

				if ( $byline_object->user_id ) {
					$ignored_users[] = (int) $byline_object->user_id;
				}
			}
		}
		$user_args = array(
			'number' => 20,
		);
		if ( ! empty( $request['s'] ) ) {
			$user_args['search'] = '*' . (string) $request['s'] . '*';
		}
		if ( ! empty( $ignored_users ) ) {
			$user_args['exclude'] = array();
			foreach ( $ignored_users as $val ) {
				$user_args['exclude'][] = $val;
			}
		}

		$users = get_users( $user_args );
		foreach ( $users as $user ) {
			$byline_data = array(
				'value' => 'u' . $user->ID,
				'label' => $user->display_name,
			);
			$bylines[]   = $byline_data;
		}

		// Sort alphabetically by display name.
		usort(
			$bylines,
			function( $a, $b ) {
				return strcmp( $a['label'], $b['label'] );
			}
		);

		return rest_ensure_response( $bylines );
	}

	/**
	 * Save the bylines posted by the block editor.
	 * Deletes the meta data afterwards since it's only used as
	 * a transportation vessel.
	 */
	public static function save_bylines() {
		foreach ( Content_Model::get_byline_supported_post_types() as $post_type ) {
			add_action(
				"rest_after_insert_{$post_type}",
				function( $post, $request ) {
					global $wpdb;
					if ( ! isset( $request['meta']['bylines'] ) ) {
						$dirty_bylines = array( array( 'value' => 'u' . $post->post_author ) );
					} else {
						$dirty_bylines = $request['meta']['bylines'];
					}
					$bylines = array();
					foreach ( $dirty_bylines as $dirty_byline ) {
						if ( is_numeric( $dirty_byline['value'] ) ) {
							$bylines[] = Byline::get_by_term_id( $dirty_byline['value'] );
						} elseif ( 0 === strncmp( $dirty_byline['value'], 'u', 1 ) ) {
							$user_id = (int) substr( $dirty_byline['value'], 1 );
							$byline  = Byline::get_by_user_id( $user_id );
							if ( ! $byline ) {
								$byline = Byline::create_from_user( $user_id );
								if ( is_wp_error( $byline ) ) {
									continue;
								}
							}
							$bylines[] = $byline;
						}
					}
					Utils::set_post_bylines( $post->ID, $bylines );
					if ( empty( $bylines ) ) {
						$wpdb->update(
							$wpdb->posts,
							array(
								'post_author' => 0,
							),
							array(
								'ID' => $post->ID,
							)
						);
						clean_post_cache( $post->ID );
					}
					delete_post_meta( $post->ID, 'bylines' );
					return $post;
				},
				10,
				2
			);
		}
	}

	/**
	 * Remove the link from the rest response that the block editor
	 * uses to determine if the current user can change authors.
	 *
	 * @param WP_Rest_Response $response The rest response to filter.
	 */
	public static function remove_authors_dropdown( $response ) {
		$response->remove_link( 'wp:action-assign-author' );
		return $response;
	}

}
