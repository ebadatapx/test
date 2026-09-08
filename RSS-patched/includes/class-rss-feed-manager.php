<?php
/**
 * Core orchestrator of the RSS Feed Manager plugin.
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
 * Class FeedManager
 *
 * Core coordinator that initializes hooks, registers assets, and binds modules.
 */
class FeedManager {

	/**
	 * Single instance of the class.
	 *
	 * @var FeedManager
	 */
	private static $instance = null;

	/**
	 * Retrieve the single instance of the class.
	 *
	 * @return FeedManager
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->define_constants();
		$this->db_migration();
		$this->init_hooks();
	}

	/**
	 * Define plugin constants.
	 *
	 * @return void
	 */
	private function define_constants() {
		if ( ! defined( 'RSS_FEED_MANAGER_VERSION' ) ) {
			define( 'RSS_FEED_MANAGER_VERSION', '1.2.2' );
		}
		if ( ! defined( 'RSS_FEED_MANAGER_PATH' ) ) {
			define( 'RSS_FEED_MANAGER_PATH', plugin_dir_path( dirname( __FILE__ ) ) );
		}
		if ( ! defined( 'RSS_FEED_MANAGER_URL' ) ) {
			define( 'RSS_FEED_MANAGER_URL', plugin_dir_url( dirname( __FILE__ ) ) );
		}
		if ( ! defined( 'RSS_FEED_MANAGER_BASENAME' ) ) {
			define( 'RSS_FEED_MANAGER_BASENAME', plugin_basename( dirname( __DIR__ ) . '/rss-feed-manager.php' ) );
		}
	}

	/**
	 * Register actions and filters.
	 *
	 * @return void
	 */
	private function init_hooks() {
		// Localization.
		add_action( 'plugins_loaded', [ $this, 'load_textdomain' ] );

		// Initialize Custom Post Type.
		$post_type = new PostType();
		$post_type->init();

		// Initialize Admin Menu.
		if ( is_admin() ) {
			$admin_menu = new Admin\AdminMenu();
			$admin_menu->init();

			// Reset & Re-sync tool (registers its AJAX endpoint).
			$reset_tool = new Admin\ResetTool();
			$reset_tool->init();
		}

		// Initialize Import Engine.
		$import_engine = new ImportEngine();
		$import_engine->init();

		// Initialize Cron Manager.
		$cron = new Cron();
		$cron->init();

		// Initialize Shortcode.
		$shortcode = new Shortcode();
		$shortcode->init();

		// Assets & Templates.
		add_action( 'wp_enqueue_scripts', [ $this, 'register_frontend_assets' ] );
	}

	/**
	 * Register frontend stylesheet.
	 *
	 * @return void
	 */
	public function register_frontend_assets() {
		wp_register_style(
			'rss-feed-manager-frontend-css',
			RSS_FEED_MANAGER_URL . 'assets/css/frontend.css',
			[],
			RSS_FEED_MANAGER_VERSION
		);
	}



	/**
	 * Load translation files.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'rss-feed-manager',
			false,
			dirname( RSS_FEED_MANAGER_BASENAME ) . '/languages'
		);
	}

	/**
	 * Run database migrations for updates.
	 *
	 * @return void
	 */
	private function db_migration() {
		global $wpdb;

		/*
		 * Run at most once per plugin version. Previously this fired SHOW TABLES and
		 * SHOW COLUMNS on every single request, front end included.
		 */
		if ( get_option( 'rss_feed_manager_db_version' ) === RSS_FEED_MANAGER_VERSION ) {
			return;
		}

		$table_name = $wpdb->prefix . 'rss_sources';

		// Check if table exists first (in case activation hasn't completed).
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name ) {
			// Check if category_id column exists.
			$column = $wpdb->get_results( "SHOW COLUMNS FROM {$table_name} LIKE 'category_id'" );
			if ( empty( $column ) ) {
				$wpdb->query( "ALTER TABLE {$table_name} ADD category_id bigint(20) unsigned DEFAULT 0 AFTER status" );
			}
		}

		// Sync logs now record which source and category each run belonged to, so the
		// dashboard can report categories even when two sources share a feed name.
		$table_logs = $wpdb->prefix . 'rss_logs';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_logs ) ) === $table_logs ) {
			if ( empty( $wpdb->get_results( "SHOW COLUMNS FROM {$table_logs} LIKE 'source_id'" ) ) ) {
				$wpdb->query( "ALTER TABLE {$table_logs} ADD source_id bigint(20) unsigned DEFAULT 0 AFTER feed_name" );
				$wpdb->query( "ALTER TABLE {$table_logs} ADD KEY source_id (source_id)" );
			}
			if ( empty( $wpdb->get_results( "SHOW COLUMNS FROM {$table_logs} LIKE 'category_id'" ) ) ) {
				$wpdb->query( "ALTER TABLE {$table_logs} ADD category_id bigint(20) unsigned DEFAULT 0 AFTER source_id" );
			}

			// Backfill historic rows, but only where the feed name is unambiguous.
			$wpdb->query(
				"UPDATE {$table_logs} l
				 INNER JOIN (
					SELECT feed_name, MIN(id) AS id, COUNT(*) AS c
					FROM {$table_name} GROUP BY feed_name HAVING c = 1
				 ) s ON s.feed_name = l.feed_name
				 SET l.source_id = s.id
				 WHERE l.source_id = 0"
			);
			$wpdb->query(
				"UPDATE {$table_logs} l
				 INNER JOIN {$table_name} s ON s.id = l.source_id
				 SET l.category_id = s.category_id
				 WHERE l.category_id = 0 AND l.source_id > 0"
			);
		}

		// Force default CPT slug to design-dispatch if it's rss-news or empty.
		$settings = get_option( 'rss_feed_manager_settings' );
		if ( is_array( $settings ) ) {
			if ( ! isset( $settings['cpt_slug'] ) || 'rss-news' === $settings['cpt_slug'] ) {
				$settings['cpt_slug'] = 'design-dispatch';
				update_option( 'rss_feed_manager_settings', $settings );
				flush_rewrite_rules();
			}
		}

		update_option( 'rss_feed_manager_db_version', RSS_FEED_MANAGER_VERSION, false );
	}
}
