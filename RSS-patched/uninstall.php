<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package           RSSFeedManager
 * @author            Senior WordPress Developer
 * @copyright         2026 RSS Feed Manager
 * @license           GPL-2.0-or-later
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$settings = get_option( 'rss_feed_manager_settings' );

// Delete data only if configured to do so on uninstall.
if ( isset( $settings['delete_data_on_uninstall'] ) && '1' === $settings['delete_data_on_uninstall'] ) {
	global $wpdb;

	/*
	 * Imports now live in the standard `post` type, so target posts carrying the
	 * `_source_feed` marker plus any legacy `rss_news` rows from older versions.
	 * The previous routine only deleted `rss_news`, leaving every current import behind.
	 */
	$ids = $wpdb->get_col(
		"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
		 LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_source_feed'
		 WHERE p.post_type = 'rss_news' OR pm.meta_id IS NOT NULL"
	);

	if ( ! empty( $ids ) ) {
		// wp_delete_post() cleans up meta, term relationships and attachments properly.
		foreach ( $ids as $id ) {
			wp_delete_post( (int) $id, true );
		}
	}

	// 2. Drop Custom Tables.
	$table_sources = $wpdb->prefix . 'rss_sources';
	$table_logs    = $wpdb->prefix . 'rss_logs';

	$wpdb->query( "DROP TABLE IF EXISTS {$table_sources}" );
	$wpdb->query( "DROP TABLE IF EXISTS {$table_logs}" );

	// 3. Delete Option Settings.
	delete_option( 'rss_feed_manager_settings' );
	delete_option( 'rss_feed_manager_db_version' );
	delete_option( 'rss_feed_manager_cron_error' );
	delete_option( 'rss_feed_manager_last_run_partial' );
	delete_option( 'rss_feed_manager_last_run_at' );
	delete_option( 'rss_feed_manager_last_run_finished_at' );
	delete_option( 'rss_feed_manager_prev_run_at' );
}
