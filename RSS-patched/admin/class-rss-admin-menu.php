<?php
/**
 * Admin Menu registration and page routing.
 *
 * @package           RSSFeedManager
 * @author            Senior WordPress Developer
 * @copyright         2026 RSS Feed Manager
 * @license           GPL-2.0-or-later
 */

namespace RSSFeedManager\Admin;

use RSSFeedManager\SourceModel;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class AdminMenu
 *
 * Registers the administration pages and handles asset loading.
 */
class AdminMenu {

	/**
	 * Register actions and filters.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_menu', [ $this, 'register_pages' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
	}

	/**
	 * Register admin menu and submenu pages.
	 *
	 * @return void
	 */
	public function register_pages() {
		// Parent menu.
		add_menu_page(
			__( 'RSS Feed Manager', 'rss-feed-manager' ),
			__( 'RSS Feed Manager', 'rss-feed-manager' ),
			'manage_options',
			'rss-feed-manager',
			[ $this, 'render_dashboard_page' ],
			'dashicons-rss',
			25
		);

		// Dashboard Submenu.
		add_submenu_page(
			'rss-feed-manager',
			__( 'Dashboard', 'rss-feed-manager' ),
			__( 'Dashboard', 'rss-feed-manager' ),
			'manage_options',
			'rss-feed-manager',
			[ $this, 'render_dashboard_page' ]
		);

		// Feed Sources Submenu.
		add_submenu_page(
			'rss-feed-manager',
			__( 'Feed Sources', 'rss-feed-manager' ),
			__( 'Feed Sources', 'rss-feed-manager' ),
			'manage_options',
			'rss-feed-manager-sources',
			[ $this, 'render_sources_page' ]
		);



		// Settings Submenu.
		add_submenu_page(
			'rss-feed-manager',
			__( 'Settings', 'rss-feed-manager' ),
			__( 'Settings', 'rss-feed-manager' ),
			'manage_options',
			'rss-feed-manager-settings',
			[ $this, 'render_settings_page' ]
		);

		// Logs Submenu.
		add_submenu_page(
			'rss-feed-manager',
			__( 'Logs', 'rss-feed-manager' ),
			__( 'Logs', 'rss-feed-manager' ),
			'manage_options',
			'rss-feed-manager-logs',
			[ $this, 'render_logs_page' ]
		);

		// Reset & Re-sync Submenu.
		add_submenu_page(
			'rss-feed-manager',
			__( 'Reset & Re-sync', 'rss-feed-manager' ),
			__( 'Reset & Re-sync', 'rss-feed-manager' ),
			'manage_options',
			'rss-feed-manager-reset',
			[ $this, 'render_reset_page' ]
		);
	}

	/**
	 * Enqueue admin stylesheet and javascript.
	 *
	 * @param string $hook The current admin page hook.
	 * @return void
	 */
	public function enqueue_admin_assets( $hook ) {
		// Only load assets on our plugin pages.
		$pages = [
			'toplevel_page_rss-feed-manager',
			'rss-feed-manager_page_rss-feed-manager-sources',
			'rss-feed-manager_page_rss-feed-manager-settings',
			'rss-feed-manager_page_rss-feed-manager-logs',
			'rss-feed-manager_page_rss-feed-manager-reset',
		];

		if ( ! in_array( $hook, $pages, true ) ) {
			return;
		}

		wp_enqueue_style(
			'rss-feed-manager-admin-css',
			RSS_FEED_MANAGER_URL . 'assets/css/admin.css',
			[],
			RSS_FEED_MANAGER_VERSION
		);

		wp_enqueue_script(
			'rss-feed-manager-admin-js',
			RSS_FEED_MANAGER_URL . 'assets/js/admin.js',
			[ 'jquery' ],
			RSS_FEED_MANAGER_VERSION,
			true
		);

		// Localize for JS usage.
		wp_localize_script(
			'rss-feed-manager-admin-js',
			'rssFeedManagerAdmin',
			[
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'rss_feed_manager_admin_nonce' ),
			]
		);

		// Separate nonce for the destructive reset endpoint.
		if ( 'rss-feed-manager_page_rss-feed-manager-reset' === $hook ) {
			wp_localize_script(
				'rss-feed-manager-admin-js',
				'rssFeedManagerReset',
				[
					'ajax_url' => admin_url( 'admin-ajax.php' ),
					'nonce'    => wp_create_nonce( 'rss_feed_manager_reset_nonce' ),
				]
			);
		}
	}

	/**
	 * Render Dashboard Page.
	 *
	 * @return void
	 */
	public function render_dashboard_page() {
		$template = RSS_FEED_MANAGER_PATH . 'templates/admin-dashboard.php';
		if ( file_exists( $template ) ) {
			include_once $template;
		}
	}

	/**
	 * Render Feed Sources CRUD Page.
	 *
	 * @return void
	 */
	public function render_sources_page() {
		$template = RSS_FEED_MANAGER_PATH . 'templates/admin-feed-sources.php';
		if ( file_exists( $template ) ) {
			include_once $template;
		}
	}

	/**
	 * Render Settings Page.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		$template = RSS_FEED_MANAGER_PATH . 'templates/admin-settings.php';
		if ( file_exists( $template ) ) {
			include_once $template;
		}
	}

	/**
	 * Render Logs Page.
	 *
	 * @return void
	 */
	public function render_logs_page() {
		$template = RSS_FEED_MANAGER_PATH . 'templates/admin-logs.php';
		if ( file_exists( $template ) ) {
			include_once $template;
		}
	}

	/**
	 * Render Reset & Re-sync Page.
	 *
	 * @return void
	 */
	public function render_reset_page() {
		$template = RSS_FEED_MANAGER_PATH . 'templates/admin-reset.php';
		if ( file_exists( $template ) ) {
			include_once $template;
		}
	}
}
