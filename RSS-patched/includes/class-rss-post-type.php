<?php
/**
 * Register Custom Post Type for RSS News.
 *
 * @package           RSSFeedManager
 * @author            Senior WordPress Developer
 * @copyright         2026 RSS Feed Manager
 * @license           GPL-2.0-or-later
 */

namespace RSSFeedManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class PostType
 *
 * Handles registration of the Custom Post Type `rss_news`.
 */
class PostType {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init() {
		// Custom Post Type and Custom Taxonomy disabled to use standard WordPress posts and categories.
	}

	/**
	 * Register the `rss_news` CPT.
	 *
	 * @return void
	 */
	public function register() {
		$settings = get_option( 'rss_feed_manager_settings' );
		$cpt_slug = isset( $settings['cpt_slug'] ) && ! empty( $settings['cpt_slug'] ) ? sanitize_title( $settings['cpt_slug'] ) : 'design-dispatch';

		$labels = [
			'name'                  => _x( 'RSS News', 'Post Type General Name', 'rss-feed-manager' ),
			'singular_name'         => _x( 'RSS News', 'Post Type Singular Name', 'rss-feed-manager' ),
			'menu_name'             => __( 'RSS News', 'rss-feed-manager' ),
			'name_admin_bar'        => __( 'RSS News', 'rss-feed-manager' ),
			'archives'              => __( 'News Archives', 'rss-feed-manager' ),
			'attributes'            => __( 'News Attributes', 'rss-feed-manager' ),
			'parent_item_colon'     => __( 'Parent News:', 'rss-feed-manager' ),
			'all_items'             => __( 'All Imported News', 'rss-feed-manager' ),
			'add_new_item'          => __( 'Add New RSS News', 'rss-feed-manager' ),
			'add_new'               => __( 'Add New', 'rss-feed-manager' ),
			'new_item'              => __( 'New RSS News', 'rss-feed-manager' ),
			'edit_item'             => __( 'Edit RSS News', 'rss-feed-manager' ),
			'update_item'           => __( 'Update RSS News', 'rss-feed-manager' ),
			'view_item'             => __( 'View RSS News', 'rss-feed-manager' ),
			'view_items'            => __( 'View RSS News Items', 'rss-feed-manager' ),
			'search_items'          => __( 'Search RSS News', 'rss-feed-manager' ),
			'not_found'             => __( 'Not found', 'rss-feed-manager' ),
			'not_found_in_trash'    => __( 'Not found in Trash', 'rss-feed-manager' ),
			'featured_image'        => __( 'Featured Image', 'rss-feed-manager' ),
			'set_featured_image'    => __( 'Set featured image', 'rss-feed-manager' ),
			'remove_featured_image' => __( 'Remove featured image', 'rss-feed-manager' ),
			'use_featured_image'    => __( 'Use as featured image', 'rss-feed-manager' ),
			'insert_into_item'      => __( 'Insert into news', 'rss-feed-manager' ),
			'uploaded_to_this_item' => __( 'Uploaded to this news', 'rss-feed-manager' ),
			'items_list'            => __( 'RSS News list', 'rss-feed-manager' ),
			'items_list_navigation' => __( 'RSS News list navigation', 'rss-feed-manager' ),
			'filter_items_list'     => __( 'Filter RSS News list', 'rss-feed-manager' ),
		];

		$args = [
			'label'               => __( 'RSS News', 'rss-feed-manager' ),
			'description'         => __( 'Imported News from RSS Feeds', 'rss-feed-manager' ),
			'labels'              => $labels,
			'supports'            => [ 'title', 'editor', 'thumbnail', 'excerpt', 'author', 'custom-fields' ],
			'hierarchical'        => false,
			'public'              => true,
			'show_ui'             => true,
			'show_in_menu'        => false, // Handled manually under parent menu to stay modular.
			'menu_position'       => 5,
			'show_in_admin_bar'   => true,
			'show_in_nav_menus'   => true,
			'can_export'          => true,
			'has_archive'         => true,
			'exclude_from_search' => false,
			'publicly_queryable'  => true,
			'capability_type'     => 'post',
			'show_in_rest'        => true,
			'rewrite'             => [ 'slug' => $cpt_slug . '/%rss_category%', 'with_front' => false ],
		];

		register_post_type( 'rss_news', $args );
	}

	public function register_taxonomy() {
		$settings = get_option( 'rss_feed_manager_settings' );
		$cpt_slug = isset( $settings['cpt_slug'] ) && ! empty( $settings['cpt_slug'] ) ? sanitize_title( $settings['cpt_slug'] ) : 'design-dispatch';

		$labels = [
			'name'              => _x( 'Categories', 'taxonomy general name', 'rss-feed-manager' ),
			'singular_name'     => _x( 'Category', 'taxonomy singular name', 'rss-feed-manager' ),
			'search_items'      => __( 'Search Categories', 'rss-feed-manager' ),
			'all_items'         => __( 'All Categories', 'rss-feed-manager' ),
			'parent_item'       => __( 'Parent Category', 'rss-feed-manager' ),
			'parent_item_colon' => __( 'Parent Category:', 'rss-feed-manager' ),
			'edit_item'         => __( 'Edit Category', 'rss-feed-manager' ),
			'update_item'       => __( 'Update Category', 'rss-feed-manager' ),
			'add_new_item'      => __( 'Add New Category', 'rss-feed-manager' ),
			'new_item_name'     => __( 'New Category Name', 'rss-feed-manager' ),
			'menu_name'         => __( 'Categories', 'rss-feed-manager' ),
		];

		$args = [
			'hierarchical'      => true,
			'labels'            => $labels,
			'show_ui'           => true,
			'show_admin_column' => true,
			'query_var'         => true,
			'rewrite'           => [ 'slug' => $cpt_slug, 'with_front' => false ],
			'show_in_rest'      => true,
		];

		register_taxonomy( 'rss_category', [ 'rss_news' ], $args );

		// Register rewrite tag so WordPress knows how to resolve the CPT rewrite rule.
		add_rewrite_tag( '%rss_category%', '([^/]+)', 'rss_category=' );
	}

	/**
	 * Filters the permalink for rss_news post type to include the category slug.
	 *
	 * @param string   $post_link The post's permalink.
	 * @param \WP_Post $post      The post object.
	 * @return string The filtered permalink.
	 */
	public function filter_post_type_link( $post_link, $post ) {
		if ( 'rss_news' === $post->post_type ) {
			if ( strpos( $post_link, '%rss_category%' ) !== false ) {
				$terms = get_the_terms( $post->ID, 'rss_category' );
				if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
					$category_slug = $terms[0]->slug;
				} else {
					$category_slug = 'uncategorized';
				}
				$post_link = str_replace( '%rss_category%', $category_slug, $post_link );
			}
		}
		return $post_link;
	}

	/**
	 * Add custom rewrite rules for hierarchical CPT permalinks.
	 *
	 * @return void
	 */
	public function add_custom_rewrite_rules() {
		$settings = get_option( 'rss_feed_manager_settings' );
		$cpt_slug = isset( $settings['cpt_slug'] ) && ! empty( $settings['cpt_slug'] ) ? sanitize_title( $settings['cpt_slug'] ) : 'design-dispatch';

		add_rewrite_rule(
			'^' . $cpt_slug . '/([^/]+)/([^/]+)/?$',
			'index.php?post_type=rss_news&rss_category=$matches[1]&name=$matches[2]',
			'top'
		);
	}
}
