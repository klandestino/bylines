<?php
/**
 * Render bylines within WordPress' default RSS feed templates.
 *
 * @package Bylines
 */

namespace Bylines\Integrations;

use Bylines\Content_Model;
use Bylines\Objects\Byline;

/**
 * Render bylines within WordPress' default RSS feed templates.
 */
class RSS {

	/**
	 * Display the first byline in WordPress' use of the_author()
	 *
	 * @param string $author Existing author string.
	 */
	public static function filter_the_author( $author ) {
		if ( ! is_feed() || ! self::is_supported_post_type() ) {
			return $author;
		}

		$bylines = get_bylines();
		$first   = array_shift( $bylines );
		return ! empty( $first ) ? $first->display_name : '';
	}

	/**
	 * Add any additional bylines to the feed.
	 */
	public static function action_rss2_item() {
		if ( ! self::is_supported_post_type() ) {
			return;
		}
		$bylines = get_bylines();
		// Ditch the first byline, which was already rendered above.
		array_shift( $bylines );
		foreach ( $bylines as $byline ) {
			echo '<dc:creator><![CDATA[' . esc_html( $byline->display_name ) . ']]></dc:creator>' . PHP_EOL;
		}
	}

	/**
	 * Remove feed link from byline archives.
	 */
	public static function filter_feed_links_extra_show_author_feed() {
		return ! get_queried_object() instanceof Byline;
	}

	/**
	 * Correct feed link in <head>
	 */
	public static function action_wp_head() {
		if ( ! is_author() ) {
			return;
		}
		$byline = get_queried_object();
		if ( ! $byline instanceof Byline ) {
			return;
		}
		$href  = str_replace( 'feed/', "{$byline->user_nicename}/feed/", get_author_feed_link( 0 ) );
		$title = sprintf(
			/* translators: 1: Blog name, 2: Separator (raquo), 3: Author name. */
			__( '%1$s %2$s Posts by %3$s Feed' ),
			get_bloginfo( 'name' ),
			_x( '&raquo;', 'feed link' ),
			$byline->name
		);
		printf(
			'<link rel="alternate" type="%s" title="%s" href="%s" />' . "\n",
			feed_content_type(),
			esc_attr( $title ),
			esc_url( $href )
		);
	}

	/**
	 * Whether or not the global post is a supported post type
	 *
	 * @return boolean
	 */
	private static function is_supported_post_type() {
		global $post;

		// Can't determine post, so assume true.
		if ( ! $post ) {
			return true;
		}
		return in_array( $post->post_type, Content_Model::get_byline_supported_post_types(), true );
	}

}
