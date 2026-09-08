<?php
/**
 * Fired during plugin deactivation.
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
 * Class Deactivator
 *
 * Handles deactivation cleanup (clearing cron schedules, flushing rewrite rules).
 */
class Deactivator {

	/**
	 * Run the deactivator logic.
	 *
	 * @return void
	 */
	public static function deactivate() {
		// Clear cron jobs.
		wp_clear_scheduled_hook( 'rss_feed_manager_cron_sync' );

		// Flush rewrite rules.
		flush_rewrite_rules();
	}
}
