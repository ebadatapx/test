<?php
/**
 * Plugin Name: RSS Feed Manager
 * Description: An enterprise-grade WordPress plugin to import RSS feeds into Custom Post Types, manage sources, schedule automatic cron syncs, and display imported posts with rich shortcodes.
 * Version: 1.2.2
 * Author: WordPress Developer
 * Text Domain: rss-feed-manager
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 *
 * @package RSSFeedManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Prevent direct access.
}

// Include and register the autoloader.
require_once plugin_dir_path( __FILE__ ) . 'includes/class-rss-autoloader.php';
\RSSFeedManager\Autoloader::register();

/**
 * Register Activation Hook.
 */
register_activation_hook( __FILE__, [ '\RSSFeedManager\Activator', 'activate' ] );

/**
 * Register Deactivation Hook.
 */
register_deactivation_hook( __FILE__, [ '\RSSFeedManager\Deactivator', 'deactivate' ] );

/**
 * Bootstraps the core plugin.
 *
 * @return \RSSFeedManager\FeedManager
 */
function rss_feed_manager_boot() {
	return \RSSFeedManager\FeedManager::get_instance();
}

// Initialize.
rss_feed_manager_boot();
