<?php
/**
 * Cron Job manager for RSS Feed Manager.
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
 * Class Cron
 *
 * Configures cron schedules, clears jobs, and executes the background syncing routine.
 */
class Cron {

	const HOOK = 'rss_feed_manager_cron_sync';

	/**
	 * Register cron hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_filter( 'cron_schedules', [ $this, 'add_custom_intervals' ] );
		add_action( self::HOOK, [ $this, 'run_sync' ] );
		add_action( 'rss_feed_manager_settings_updated', [ $this, 'handle_settings_updated' ] );

		// Self-heal: if the event ever disappears (migration, another plugin clearing
		// cron, a failed activation) syncing would otherwise stop silently forever.
		add_action( 'init', [ $this, 'maybe_schedule' ] );
	}

	/**
	 * Interval slugs offered by this plugin, mapped to seconds.
	 *
	 * @return array<string,int>
	 */
	public static function get_custom_intervals() {
		return [
			'5min'  => 300,
			'10min' => 600,
			'15min' => 900,
			'30min' => 1800,
		];
	}

	/**
	 * Every interval slug the settings screen accepts, including WordPress defaults.
	 *
	 * @return string[]
	 */
	public static function get_allowed_intervals() {
		return array_merge(
			array_keys( self::get_custom_intervals() ),
			[ 'hourly', 'twicedaily', 'daily' ]
		);
	}

	/**
	 * Register custom cron intervals: 5min, 10min, 15min, 30min.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array Updated schedules.
	 */
	public function add_custom_intervals( $schedules ) {
		$labels = [
			'5min'  => __( 'Every 5 Minutes', 'rss-feed-manager' ),
			'10min' => __( 'Every 10 Minutes', 'rss-feed-manager' ),
			'15min' => __( 'Every 15 Minutes', 'rss-feed-manager' ),
			'30min' => __( 'Every 30 Minutes', 'rss-feed-manager' ),
		];

		foreach ( self::get_custom_intervals() as $slug => $seconds ) {
			$schedules[ $slug ] = [
				'interval' => $seconds,
				'display'  => isset( $labels[ $slug ] ) ? $labels[ $slug ] : $slug,
			];
		}

		return $schedules;
	}

	/**
	 * Timestamp of the next scheduled run, or false when nothing is scheduled.
	 *
	 * @return int|false UTC timestamp.
	 */
	public static function get_next_run() {
		$timestamp = wp_next_scheduled( self::HOOK );

		return $timestamp ? (int) $timestamp : false;
	}

	/**
	 * Seconds of work a single background run is allowed to do.
	 *
	 * Kept here rather than in the import engine so the admin screens can project
	 * upcoming sync times from exactly the same numbers the engine uses.
	 *
	 * @return int
	 */
	public static function get_run_budget_seconds() {
		$php_limit = (int) ini_get( 'max_execution_time' );
		if ( $php_limit <= 0 ) {
			$php_limit = 120; // Unlimited or unreported: pick something sane.
		}

		return (int) apply_filters( 'rss_feed_manager_max_run_seconds', max( 30, (int) floor( $php_limit * 0.6 ) ) );
	}

	/**
	 * How many feeds a single run realistically gets through.
	 *
	 * Prefers the observed count from the last truncated run over the theoretical
	 * figure, because real per-item cost is dominated by image sideloading.
	 *
	 * @return int At least 1.
	 */
	public static function get_feeds_per_run() {
		$partial = get_option( 'rss_feed_manager_last_run_partial' );
		if ( is_array( $partial ) && ! empty( $partial['processed'] ) ) {
			return max( 1, (int) $partial['processed'] );
		}

		$per_feed = max( 5, (int) apply_filters( 'rss_feed_manager_max_sync_seconds', 30, null ) );

		return max( 1, (int) floor( self::get_run_budget_seconds() / $per_feed ) );
	}

	/**
	 * Describe how the feed rotation is actually performing.
	 *
	 * A truncated run is the normal, intended steady state once there are more feeds
	 * than one PHP request can handle: the queue rotates and every feed gets its turn.
	 * What matters is whether the full rotation completes often enough, not whether an
	 * individual run reached the end of the list.
	 *
	 * @return array
	 */
	public static function get_rotation_health() {
		global $wpdb;

		$table = SourceModel::get_table_name();

		$active = 0;
		$oldest = null;
		$never  = 0;

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
			$active = (int) $wpdb->get_var( "SELECT COUNT(id) FROM {$table} WHERE status = 'active'" );
			$never  = (int) $wpdb->get_var( "SELECT COUNT(id) FROM {$table} WHERE status = 'active' AND last_sync IS NULL" );
			$oldest = $wpdb->get_var( "SELECT MIN(last_sync) FROM {$table} WHERE status = 'active' AND last_sync IS NOT NULL" );
		}

		$per_run  = self::get_feeds_per_run();
		$interval = self::get_configured_interval_seconds();

		$runs_per_cycle = $active > 0 ? (int) ceil( $active / max( 1, $per_run ) ) : 0;
		$cycle_seconds  = $runs_per_cycle * $interval;

		// Real starvation: a feed has gone far longer than a full rotation should take.
		$oldest_age = null;
		if ( $oldest ) {
			$oldest_age = strtotime( current_time( 'mysql' ) ) - strtotime( $oldest );
		}

		$tolerance = max( 3 * $cycle_seconds, 30 * MINUTE_IN_SECONDS );
		$healthy   = ( null === $oldest_age ) || ( $oldest_age <= $tolerance );

		return [
			'active'         => $active,
			'never_synced'   => $never,
			'per_run'        => $per_run,
			'interval'       => $interval,
			'runs_per_cycle' => $runs_per_cycle,
			'cycle_seconds'  => $cycle_seconds,
			'oldest_sync'    => $oldest,
			'oldest_age'     => $oldest_age,
			'healthy'        => $healthy,
		];
	}

	/**
	 * Project when each active source is next expected to be synchronised.
	 *
	 * The scheduled event syncs feeds least-recently-synced first and stops when the
	 * run budget is spent, so a source's position in that queue determines which run
	 * picks it up. Returns estimates, not guarantees — WP-Cron fires on page loads.
	 *
	 * @return array<int,array> Keyed by source ID: [ 'timestamp' => int, 'position' => int, 'run' => int ].
	 */
	public static function get_projected_sync_times() {
		$next = self::get_next_run();
		if ( ! $next ) {
			return [];
		}

		$model  = new SourceModel();
		$active = $model->get_all(
			[
				'status'  => 'active',
				'orderby' => 'last_sync',
				'order'   => 'ASC',
			]
		);

		if ( empty( $active ) ) {
			return [];
		}

		$per_run  = self::get_feeds_per_run();
		$interval = self::get_configured_interval_seconds();
		$out      = [];

		foreach ( array_values( $active ) as $index => $source ) {
			$run = (int) floor( $index / $per_run );

			$out[ (int) $source->id ] = [
				'timestamp' => $next + ( $run * $interval ),
				'position'  => $index + 1,
				'run'       => $run + 1,
			];
		}

		return $out;
	}

	/**
	 * Resolve the configured sync interval in seconds.
	 *
	 * @return int Seconds between scheduled syncs.
	 */
	public static function get_configured_interval_seconds() {
		$settings = get_option( 'rss_feed_manager_settings' );
		$slug     = is_array( $settings ) && ! empty( $settings['cron_interval'] ) ? $settings['cron_interval'] : 'hourly';

		$custom = self::get_custom_intervals();
		if ( isset( $custom[ $slug ] ) ) {
			return $custom[ $slug ];
		}

		$schedules = wp_get_schedules();
		if ( isset( $schedules[ $slug ]['interval'] ) ) {
			return (int) $schedules[ $slug ]['interval'];
		}

		return HOUR_IN_SECONDS;
	}

	/**
	 * Executes background RSS synchronizations.
	 *
	 * @return void
	 */
	public function run_sync() {
		/*
		 * Stamped before the work starts, so a run killed by a PHP timeout still counts
		 * as evidence that something is firing the event. This is the only reliable way
		 * to tell "nothing runs cron" from "the host runs cron for us".
		 */
		$previous = (int) get_option( 'rss_feed_manager_last_run_at' );
		if ( $previous > 0 ) {
			// Keep the prior stamp so the real-world run frequency can be measured.
			update_option( 'rss_feed_manager_prev_run_at', $previous, false );
		}
		update_option( 'rss_feed_manager_last_run_at', time(), false );

		$engine = new ImportEngine();
		$engine->sync_all_active_feeds();

		update_option( 'rss_feed_manager_last_run_finished_at', time(), false );
	}

	/**
	 * Measured gap between the last two actual runs.
	 *
	 * The configured interval is only a request. What matters is how often whatever
	 * invokes WP-Cron actually gets round to it, which on managed hosting can be far
	 * less frequent than the setting implies.
	 *
	 * @return int|null Seconds, or null until two runs have been observed.
	 */
	public static function get_observed_interval() {
		$last = self::get_last_run();
		$prev = (int) get_option( 'rss_feed_manager_prev_run_at' );

		if ( $last && $prev > 0 && $last > $prev ) {
			return $last - $prev;
		}

		return null;
	}

	/**
	 * Timestamp of the last time the scheduled event actually executed.
	 *
	 * @return int|false
	 */
	public static function get_last_run() {
		$timestamp = (int) get_option( 'rss_feed_manager_last_run_at' );

		return $timestamp > 0 ? $timestamp : false;
	}

	/**
	 * Work out whether background syncing is genuinely running.
	 *
	 * DISABLE_WP_CRON on its own says nothing about whether syncs happen: managed hosts
	 * routinely set it and replace the page-load trigger with a real system scheduler,
	 * which is strictly more reliable. So judge by observed runs, not by the constant.
	 *
	 * @return array
	 */
	public static function get_scheduler_status() {
		$next     = self::get_next_run();
		$last     = self::get_last_run();
		$interval = self::get_configured_interval_seconds();
		$disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$error    = get_option( 'rss_feed_manager_cron_error' );

		$observed = self::get_observed_interval();

		/*
		 * Judge staleness against how often runs actually happen, not against the
		 * configured interval. A host that calls WP-Cron hourly is not "stalled" for
		 * 55 minutes out of every hour — it is simply slower than the setting asks for.
		 */
		$grace = max( 3 * $interval, 20 * MINUTE_IN_SECONDS );
		if ( $observed ) {
			$grace = max( $grace, 2 * $observed );
		}

		$age     = $last ? ( time() - $last ) : null;
		$running = ( null !== $age ) && $age <= $grace;

		// Scheduled, long overdue, and never once observed: nothing is calling WP-Cron.
		$overdue_by    = $next ? ( time() - $next ) : null;
		$never_invoked = ! $last && null !== $overdue_by
			&& $overdue_by > max( 2 * $interval, 10 * MINUTE_IN_SECONDS );

		if ( $error ) {
			$state = 'error';
		} elseif ( ! $next ) {
			$state = 'unscheduled';
		} elseif ( $running ) {
			$state = 'running';
		} elseif ( $last ) {
			$state = 'stalled';
		} elseif ( $never_invoked ) {
			$state = 'not_invoked';
		} else {
			$state = 'unobserved';
		}

		// The setting cannot be honoured if runs genuinely arrive less often than it asks.
		$throttled = $observed && $observed > ( 2 * $interval );

		return [
			'state'      => $state,
			'next'       => $next,
			'last'       => $last,
			'last_age'   => $age,
			'interval'   => $interval,
			'observed'   => $observed,
			'throttled'  => $throttled,
			'overdue_by' => $overdue_by,
			'disabled'   => $disabled,
			'error'      => $error,
			'grace'      => $grace,
		];
	}

	/**
	 * Re-create the scheduled event if it is missing.
	 *
	 * @return void
	 */
	public function maybe_schedule() {
		if ( wp_next_scheduled( self::HOOK ) ) {
			return;
		}

		$settings = get_option( 'rss_feed_manager_settings' );
		$interval = is_array( $settings ) && ! empty( $settings['cron_interval'] ) ? $settings['cron_interval'] : 'hourly';

		$this->schedule_sync( $interval );
	}

	/**
	 * Callback handler when settings are updated.
	 * Reschedules the cron event with the new interval configuration.
	 *
	 * @param array $settings Updated settings.
	 * @return void
	 */
	public function handle_settings_updated( $settings ) {
		$interval = isset( $settings['cron_interval'] ) ? $settings['cron_interval'] : 'hourly';

		// Only rebuild the schedule when the interval actually changed, so saving
		// unrelated settings does not keep pushing the next run into the future.
		$current = wp_get_scheduled_event( self::HOOK );
		if ( $current && isset( $current->schedule ) && $current->schedule === $interval ) {
			return;
		}

		$this->schedule_sync( $interval );
	}

	/**
	 * Set up the scheduled event.
	 *
	 * @param string $interval Schedule speed (e.g. hourly, 15min).
	 * @return bool True when the event was scheduled.
	 */
	public function schedule_sync( $interval = 'hourly' ) {
		$schedules = wp_get_schedules();
		if ( ! isset( $schedules[ $interval ] ) ) {
			$interval = 'hourly';
		}

		wp_clear_scheduled_hook( self::HOOK );

		$scheduled = wp_schedule_event( time() + 60, $interval, self::HOOK, [], true );

		if ( is_wp_error( $scheduled ) ) {
			update_option( 'rss_feed_manager_cron_error', $scheduled->get_error_message(), false );
			return false;
		}

		delete_option( 'rss_feed_manager_cron_error' );

		return true;
	}
}
