<?php
/**
 * Admin Dashboard template.
 *
 * @package           RSSFeedManager
 * @author            Senior WordPress Developer
 * @copyright         2026 RSS Feed Manager
 * @license           GPL-2.0-or-later
 */

use RSSFeedManager\SourceModel;
use RSSFeedManager\Cron;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

$model = new SourceModel();

// Get statistics.
$all_sources      = $model->get_all();
$total_sources    = count( $all_sources );
$active_sources   = count( $model->get_all( [ 'status' => 'active' ] ) );
$inactive_sources = count( $model->get_all( [ 'status' => 'inactive' ] ) );

global $wpdb;

/*
 * Count actual imports. This previously called wp_count_posts( 'rss_news' ), a post
 * type the plugin no longer registers, so the tile reported stale legacy rows instead
 * of the posts currently being imported.
 */
$total_imported = (int) $wpdb->get_var(
	"SELECT COUNT( DISTINCT p.ID )
	 FROM {$wpdb->posts} p
	 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
	 WHERE pm.meta_key = '_source_feed'
	 AND p.post_type = 'post'
	 AND p.post_status IN ( 'publish', 'draft', 'pending', 'private', 'future' )"
);

// Get latest logs (limited to 5).
$table_logs = $wpdb->prefix . 'rss_logs';
$latest_logs = [];
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_logs ) ) ) {
	$latest_logs = $wpdb->get_results( "SELECT * FROM {$table_logs} ORDER BY id DESC LIMIT 5" );
}
?>

<div class="wrap rfm-admin-wrap">
	<div class="rfm-header">
		<div class="rfm-header-info">
			<h1><?php esc_html_e( 'RSS Feed Manager Dashboard', 'rss-feed-manager' ); ?></h1>
			<p><?php esc_html_e( 'Monitor your feeds, import statistics, and system logs from one central dashboard.', 'rss-feed-manager' ); ?></p>
		</div>
	</div>

	<?php
	/*
	 * Report on the rotation, not on individual runs. With more feeds than one PHP
	 * request can process, a truncated run is the intended steady state — so only
	 * raise an alarm when a feed has actually gone stale.
	 */
	$partial = get_option( 'rss_feed_manager_last_run_partial' );
	$health  = Cron::get_rotation_health();

	if ( is_array( $partial ) && ! empty( $partial['total'] ) && $health['active'] > 0 ) :
		if ( $health['healthy'] ) :
			?>
			<div class="rfm-notice">
				<p>
					<strong><?php esc_html_e( 'Feeds are syncing in rotation.', 'rss-feed-manager' ); ?></strong>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: total active feeds, 2: feeds per run, 3: human readable cycle duration */
							__( 'There are more feeds (%1$d) than one background run can process, so the queue rotates: about %2$d feeds per run, least-recently-synced first. Every feed refreshes roughly every %3$s. This is normal and needs no action.', 'rss-feed-manager' ),
							(int) $health['active'],
							(int) $health['per_run'],
							human_time_diff( 0, max( MINUTE_IN_SECONDS, (int) $health['cycle_seconds'] ) )
						)
					);
					?>
					<?php if ( ! empty( $health['oldest_age'] ) ) : ?>
						<br />
						<span style="font-size: 12px; color: var(--text-muted);">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: human readable duration */
									__( 'Least-recently-synced feed was last updated %s ago.', 'rss-feed-manager' ),
									human_time_diff( 0, (int) $health['oldest_age'] )
								)
							);
							?>
						</span>
					<?php endif; ?>
				</p>
			</div>
		<?php else : ?>
			<div class="rfm-notice rfm-notice-error">
				<p>
					<strong><?php esc_html_e( 'Feeds are falling behind.', 'rss-feed-manager' ); ?></strong>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: human readable staleness, 2: total active feeds, 3: feeds per run, 4: human readable expected cycle */
							__( 'The least-recently-synced feed has not been updated for %1$s, which is well beyond the expected rotation. %2$d active feeds at about %3$d per run should complete a full cycle every %4$s.', 'rss-feed-manager' ),
							human_time_diff( 0, (int) $health['oldest_age'] ),
							(int) $health['active'],
							(int) $health['per_run'],
							human_time_diff( 0, max( MINUTE_IN_SECONDS, (int) $health['cycle_seconds'] ) )
						)
					);
					?>
				</p>
				<p style="margin: 8px 0 0 0; font-size: 12px;">
					<?php esc_html_e( 'Most effective fixes, in order: turn off Enable Featured Image Download (image processing dominates the cost), deactivate feeds that duplicate others, raise the PHP time limit, or shorten the sync interval so the rotation gets more turns per hour.', 'rss-feed-manager' ); ?>
				</p>
			</div>
			<?php
		endif;
	endif;
	?>

	<!-- Statistics Cards -->
	<div class="rfm-grid">
		<div class="rfm-card rfm-stat-card">
			<div class="rfm-stat-info">
				<h3><?php esc_html_e( 'Total Feed Sources', 'rss-feed-manager' ); ?></h3>
				<div class="rfm-stat-val"><?php echo esc_html( $total_sources ); ?></div>
			</div>
			<div class="rfm-stat-icon">
				<span class="dashicons dashicons-rss"></span>
			</div>
		</div>

		<div class="rfm-card rfm-stat-card">
			<div class="rfm-stat-info">
				<h3><?php esc_html_e( 'Active Feeds', 'rss-feed-manager' ); ?></h3>
				<div class="rfm-stat-val"><?php echo esc_html( $active_sources ); ?></div>
			</div>
			<div class="rfm-stat-icon green">
				<span class="dashicons dashicons-yes-alt"></span>
			</div>
		</div>

		<div class="rfm-card rfm-stat-card">
			<div class="rfm-stat-info">
				<h3><?php esc_html_e( 'Inactive Feeds', 'rss-feed-manager' ); ?></h3>
				<div class="rfm-stat-val"><?php echo esc_html( $inactive_sources ); ?></div>
			</div>
			<div class="rfm-stat-icon orange">
				<span class="dashicons dashicons-dismiss"></span>
			</div>
		</div>

		<div class="rfm-card rfm-stat-card">
			<div class="rfm-stat-info">
				<h3><?php esc_html_e( 'Imported News Posts', 'rss-feed-manager' ); ?></h3>
				<div class="rfm-stat-val"><?php echo esc_html( $total_imported ); ?></div>
			</div>
			<div class="rfm-stat-icon blue">
				<span class="dashicons dashicons-admin-post"></span>
			</div>
		</div>

		<?php
		$next_run    = Cron::get_next_run();
		$interval    = $health['interval'];
		$per_run     = $health['per_run'];
		?>
		<div class="rfm-card rfm-stat-card">
			<div class="rfm-stat-info">
				<h3><?php esc_html_e( 'Next Scheduled Sync', 'rss-feed-manager' ); ?></h3>
				<?php if ( $next_run ) : ?>
					<div class="rfm-stat-val" style="font-size: 22px;">
						<?php // wp_date() converts the UTC cron timestamp using the site timezone. ?>
						<?php echo esc_html( wp_date( get_option( 'time_format' ), $next_run ) ); ?>
					</div>
					<p style="margin: 6px 0 0 0; font-size: 12px; color: var(--text-muted); line-height: 1.5;">
						<?php
						if ( $next_run > time() ) {
							/* translators: %s: human readable time difference, e.g. "4 mins" */
							echo esc_html( sprintf( __( 'in %s', 'rss-feed-manager' ), human_time_diff( time(), $next_run ) ) );
						} elseif ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
							esc_html_e( 'due now — waiting for the server scheduler', 'rss-feed-manager' );
						} else {
							esc_html_e( 'due now — waiting for a page load', 'rss-feed-manager' );
						}
						?>
						&middot;
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: interval in minutes, 2: feeds per run */
								_n( 'every %1$d min, ~%2$d feed per run', 'every %1$d min, ~%2$d feeds per run', $per_run, 'rss-feed-manager' ),
								max( 1, (int) round( $interval / MINUTE_IN_SECONDS ) ),
								$per_run
							)
						);
						?>
						<?php
						// The single most useful diagnostic: did the event actually fire?
						$last_run = Cron::get_last_run();
						if ( $last_run ) :
							?>
							<br />
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: how long ago */
									__( 'last ran %s ago', 'rss-feed-manager' ),
									human_time_diff( $last_run, time() )
								)
							);
							?>
						<?php endif; ?>
					</p>
				<?php else : ?>
					<div class="rfm-stat-val" style="font-size: 20px; color: var(--danger);">
						<?php esc_html_e( 'Not scheduled', 'rss-feed-manager' ); ?>
					</div>
					<p style="margin: 6px 0 0 0; font-size: 12px; color: var(--danger); line-height: 1.5;">
						<?php esc_html_e( 'Reload this page to let the plugin re-create the event, or re-save Settings.', 'rss-feed-manager' ); ?>
					</p>
				<?php endif; ?>
			</div>
			<div class="rfm-stat-icon <?php echo $next_run ? '' : 'orange'; ?>">
				<span class="dashicons dashicons-clock"></span>
			</div>
		</div>
	</div>

	<?php
	$sched = Cron::get_scheduler_status();

	if ( 'error' === $sched['state'] ) :
		?>
		<div class="rfm-notice rfm-notice-error">
			<p>
				<strong><?php esc_html_e( 'Could not schedule the sync event:', 'rss-feed-manager' ); ?></strong>
				<?php echo esc_html( $sched['error'] ); ?>
			</p>
		</div>
	<?php elseif ( 'unscheduled' === $sched['state'] ) : ?>
		<div class="rfm-notice rfm-notice-error">
			<p>
				<strong><?php esc_html_e( 'No sync event is scheduled.', 'rss-feed-manager' ); ?></strong>
				<?php esc_html_e( 'Reload this page to let the plugin re-create it, or re-save the Settings page.', 'rss-feed-manager' ); ?>
			</p>
		</div>
	<?php elseif ( 'stalled' === $sched['state'] ) : ?>
		<div class="rfm-notice rfm-notice-error">
			<p>
				<strong><?php esc_html_e( 'Background syncing has stopped.', 'rss-feed-manager' ); ?></strong>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: how long ago, 2: expected interval */
						__( 'The scheduled event last ran %1$s ago, but it should run every %2$s.', 'rss-feed-manager' ),
						human_time_diff( 0, (int) $sched['last_age'] ),
						human_time_diff( 0, (int) $sched['interval'] )
					)
				);
				?>
				<?php if ( $sched['disabled'] ) : ?>
					<?php esc_html_e( 'WP-Cron\'s page-load trigger is disabled on this site, so a server cron job or WP-CLI has to call it — check whether that job is still in place.', 'rss-feed-manager' ); ?>
				<?php else : ?>
					<?php esc_html_e( 'Check that the site is receiving traffic and that no plugin is clearing scheduled events.', 'rss-feed-manager' ); ?>
				<?php endif; ?>
			</p>
		</div>
	<?php elseif ( 'not_invoked' === $sched['state'] ) : ?>
		<div class="rfm-notice rfm-notice-error">
			<p>
				<strong><?php esc_html_e( 'Nothing is running WordPress cron on this site.', 'rss-feed-manager' ); ?></strong>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: how overdue the event is */
						__( 'The sync event has been due for %s and has never executed once. WP-Cron\'s page-load trigger is disabled here, so WordPress cannot run it by itself — something external has to call it, and nothing is.', 'rss-feed-manager' ),
						human_time_diff( 0, (int) $sched['overdue_by'] )
					)
				);
				?>
			</p>
			<p style="margin: 8px 0 0 0; font-size: 12px;">
				<?php esc_html_e( 'Pick one of these:', 'rss-feed-manager' ); ?>
			</p>
			<ul style="margin: 6px 0 0 18px; font-size: 12px; line-height: 1.8;">
				<li>
					<?php esc_html_e( 'Point a free external cron service at this URL every 5 minutes:', 'rss-feed-manager' ); ?>
					<br /><code><?php echo esc_html( site_url( 'wp-cron.php?doing_wp_cron' ) ); ?></code>
				</li>
				<li><?php esc_html_e( 'Or ask your host to add a server cron job that calls that URL, or runs: wp cron event run --due-now', 'rss-feed-manager' ); ?></li>
				<li><?php esc_html_e( 'Or remove define( \'DISABLE_WP_CRON\', true ) from wp-config.php to let page visits trigger it (less reliable, and your host may have set it deliberately).', 'rss-feed-manager' ); ?></li>
			</ul>
			<p style="margin: 10px 0 0 0; font-size: 12px;">
				<?php esc_html_e( 'You can also open that URL in a browser tab right now to run the pending sync immediately.', 'rss-feed-manager' ); ?>
			</p>
		</div>
	<?php elseif ( 'unobserved' === $sched['state'] ) : ?>
		<div class="rfm-notice">
			<p>
				<strong><?php esc_html_e( 'Waiting for the first background run.', 'rss-feed-manager' ); ?></strong>
				<?php esc_html_e( 'The event is scheduled but has not fired yet, so there is nothing to confirm against.', 'rss-feed-manager' ); ?>
				<?php if ( $sched['disabled'] ) : ?>
					<?php esc_html_e( 'WP-Cron\'s page-load trigger is disabled here, which is normal on managed hosting where the server runs the scheduler instead. If this message is still showing after a couple of intervals, that server job is missing.', 'rss-feed-manager' ); ?>
				<?php endif; ?>
			</p>
		</div>
	<?php elseif ( 'running' === $sched['state'] && $sched['throttled'] ) : ?>
		<div class="rfm-notice rfm-notice-error">
			<p>
				<strong><?php esc_html_e( 'Your sync interval is not being honoured.', 'rss-feed-manager' ); ?></strong>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: configured interval, 2: measured interval */
						__( 'Syncs are set to run every %1$s, but WordPress cron is only actually being invoked about every %2$s on this host — so that is the real ceiling, no matter what the setting says.', 'rss-feed-manager' ),
						human_time_diff( 0, (int) $sched['interval'] ),
						human_time_diff( 0, (int) $sched['observed'] )
					)
				);
				?>
			</p>
			<p style="margin: 8px 0 0 0; font-size: 12px;">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: wp-cron URL */
						__( 'To get the configured frequency, have an external cron service call %s every few minutes instead of relying on the host scheduler.', 'rss-feed-manager' ),
						site_url( 'wp-cron.php?doing_wp_cron' )
					)
				);
				?>
			</p>
		</div>
	<?php elseif ( 'running' === $sched['state'] && ! $sched['observed'] ) : ?>
		<?php // One run seen. That proves importing works, but not that anything repeats it. ?>
		<div class="rfm-notice">
			<p>
				<strong><?php esc_html_e( 'One background run completed.', 'rss-feed-manager' ); ?></strong>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: how long ago */
						__( 'A run finished %s ago, so importing itself is working. Only one run has been seen so far, which is not enough to confirm anything is invoking it repeatedly — a single manual call to wp-cron.php looks identical at this point.', 'rss-feed-manager' ),
						human_time_diff( 0, (int) $sched['last_age'] )
					)
				);
				?>
			</p>
			<?php if ( $sched['disabled'] ) : ?>
				<p style="margin: 8px 0 0 0; font-size: 12px;">
					<?php esc_html_e( 'Because WP-Cron\'s page-load trigger is disabled here, check back after the next interval: if this still reads "one run", nothing is calling it on a schedule and you need an external cron job.', 'rss-feed-manager' ); ?>
				</p>
			<?php endif; ?>
		</div>
	<?php elseif ( 'running' === $sched['state'] && $sched['disabled'] ) : ?>
		<div class="rfm-notice rfm-notice-success">
			<p>
				<strong><?php esc_html_e( 'Background syncing is running on a server scheduler.', 'rss-feed-manager' ); ?></strong>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: how long ago the last run happened, 2: measured run frequency */
						__( 'WP-Cron\'s page-load trigger is disabled and something external is calling it instead — last run %1$s ago, and runs are arriving about every %2$s. No action needed.', 'rss-feed-manager' ),
						human_time_diff( 0, (int) $sched['last_age'] ),
						human_time_diff( 0, (int) $sched['observed'] )
					)
				);
				?>
			</p>
		</div>
		<?php
	endif;
	?>

	<!-- Split Layout -->
	<div class="rfm-split-layout">
		<!-- Left: Recent Sync Logs -->
		<div class="rfm-table-card">
			<h2><?php esc_html_e( 'Recent Sync Activity', 'rss-feed-manager' ); ?></h2>
			<?php if ( ! empty( $latest_logs ) ) : ?>
				<table class="rfm-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Feed Name', 'rss-feed-manager' ); ?></th>
							<th><?php esc_html_e( 'Category', 'rss-feed-manager' ); ?></th>
							<th><?php esc_html_e( 'Items Found', 'rss-feed-manager' ); ?></th>
							<th><?php esc_html_e( 'Imported', 'rss-feed-manager' ); ?></th>
							<th><?php esc_html_e( 'Duplicates', 'rss-feed-manager' ); ?></th>
							<th><?php esc_html_e( 'Errors / Details', 'rss-feed-manager' ); ?></th>
							<th><?php esc_html_e( 'Sync Time', 'rss-feed-manager' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $latest_logs as $log ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $log->feed_name ); ?></strong></td>
								<td>
									<?php
									$log_category = SourceModel::resolve_log_category( $log );
									if ( $log_category ) {
										printf(
											'<a href="%1$s" class="rfm-badge rfm-badge-active" style="background-color: var(--primary-light, #eef2ff); color: var(--primary); font-size:11px; padding:2px 6px; text-decoration:none;">%2$s</a>',
											esc_url( admin_url( 'edit.php?cat=' . $log_category['id'] ) ),
											esc_html( $log_category['name'] )
										);
									} else {
										echo '<span style="color: var(--text-muted);">' . esc_html__( 'Uncategorised', 'rss-feed-manager' ) . '</span>';
									}
									?>
								</td>
								<td><?php echo esc_html( $log->items_found ); ?></td>
								<td><span class="rfm-badge rfm-badge-active" style="background-color: var(--success-light); color: var(--success); font-size:11px; padding: 2px 6px;"><?php echo esc_html( $log->imported ); ?></span></td>
								<td><?php echo esc_html( $log->duplicates ); ?></td>
								<td>
									<?php
									if ( empty( $log->errors ) ) {
										echo '<span class="dashicons dashicons-yes text-success" style="color:var(--success)"></span> ' . esc_html__( 'Success', 'rss-feed-manager' );
									} else {
										echo '<span style="color:var(--danger)">' . esc_html( $log->errors ) . '</span>';
									}
									?>
								</td>
								<td>
									<?php
									echo esc_html(
										$log->sync_time ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $log->sync_time ) ) : '-'
									);
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p style="color: var(--text-muted); padding: 20px 0; text-align: center;">
					<?php esc_html_e( 'No synchronization logs found. Sync a feed to see activity logs.', 'rss-feed-manager' ); ?>
				</p>
			<?php endif; ?>
		</div>

		<!-- Right: Quick Actions -->
		<div class="rfm-form-card">
			<h2><?php esc_html_e( 'Quick Actions', 'rss-feed-manager' ); ?></h2>
			<div style="display: flex; flex-direction: column; gap: 12px;">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=rss-feed-manager-sources' ) ); ?>" class="rfm-btn rfm-btn-primary" style="width: 100%;">
					<span class="dashicons dashicons-plus-alt" style="margin-right: 6px;"></span>
					<?php esc_html_e( 'Manage Feed Sources', 'rss-feed-manager' ); ?>
				</a>
				<?php // Imports live in the standard post list now; the rss_news screen no longer exists. ?>
				<a href="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>" class="rfm-btn rfm-btn-secondary" style="width: 100%;">
					<span class="dashicons dashicons-admin-post" style="margin-right: 6px;"></span>
					<?php esc_html_e( 'View Imported Posts', 'rss-feed-manager' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=rss-feed-manager-settings' ) ); ?>" class="rfm-btn rfm-btn-secondary" style="width: 100%;">
					<span class="dashicons dashicons-admin-generic" style="margin-right: 6px;"></span>
					<?php esc_html_e( 'Configure Settings', 'rss-feed-manager' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=rss-feed-manager-logs' ) ); ?>" class="rfm-btn rfm-btn-secondary" style="width: 100%;">
					<span class="dashicons dashicons-list-view" style="margin-right: 6px;"></span>
					<?php esc_html_e( 'View Sync History', 'rss-feed-manager' ); ?>
				</a>
			</div>
		</div>
	</div>
</div>
