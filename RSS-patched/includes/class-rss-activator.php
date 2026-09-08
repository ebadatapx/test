<?php
/**
 * Fired during plugin activation.
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
 * Class Activator
 *
 * Handles database table initialization, CPT registration, and default settings setup.
 */
class Activator {

	/**
	 * Run the activator logic.
	 *
	 * @return void
	 */
	public static function activate() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		// Custom Feed Sources Table.
		$table_sources = $wpdb->prefix . 'rss_sources';
		$sql_sources   = "CREATE TABLE $table_sources (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			feed_name varchar(255) NOT NULL,
			feed_url varchar(255) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			category_id bigint(20) unsigned DEFAULT 0,
			last_sync datetime DEFAULT NULL,
			created_at datetime DEFAULT NULL,
			updated_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY status (status)
		) $charset_collate;";

		// Custom Sync Logs Table.
		$table_logs = $wpdb->prefix . 'rss_logs';
		$sql_logs   = "CREATE TABLE $table_logs (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			feed_name varchar(255) DEFAULT NULL,
			source_id bigint(20) unsigned DEFAULT 0,
			category_id bigint(20) unsigned DEFAULT 0,
			items_found int(11) NOT NULL DEFAULT 0,
			imported int(11) NOT NULL DEFAULT 0,
			duplicates int(11) NOT NULL DEFAULT 0,
			errors text DEFAULT NULL,
			sync_time datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY source_id (source_id)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_sources );
		dbDelta( $sql_logs );

		// Set default settings if not exists.
		if ( ! get_option( 'rss_feed_manager_settings' ) ) {
			$default_settings = [
				'enable_image_download'     => '1',
				'default_image'             => '',
				'auto_publish'              => 'publish', // publish or draft
				'posts_per_page'            => '10',
				'cron_interval'             => 'hourly',
				'delete_data_on_uninstall'  => '0',
				'update_existing'           => '0',
				'strip_source_map'          => '1',
				'strip_source_credit'       => '1',
			];
			update_option( 'rss_feed_manager_settings', $default_settings );
		}

		// Register post type to allow flushing rules immediately.
		$post_type = new PostType();
		$post_type->register();

		// Schedule background syncing job.
		$cron     = new Cron();
		$settings = get_option( 'rss_feed_manager_settings' );
		$interval = isset( $settings['cron_interval'] ) ? $settings['cron_interval'] : 'hourly';
		$cron->schedule_sync( $interval );

		// Clear rewrite rules for custom post type URLs.
		flush_rewrite_rules();
	}
}
