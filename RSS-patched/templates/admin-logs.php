<?php
/**
 * Admin Logs page template.
 *
 * @package           RSSFeedManager
 * @author            Senior WordPress Developer
 * @copyright         2026 RSS Feed Manager
 * @license           GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'rss-feed-manager' ) );
}

global $wpdb;
$table_logs = $wpdb->prefix . 'rss_logs';
$success    = false;
$error      = false;

// Handle Clear Logs action.
if ( isset( $_POST['rss_clear_logs'] ) ) {
	if ( isset( $_POST['rss_logs_nonce'] ) && wp_verify_nonce( $_POST['rss_logs_nonce'], 'rss_clear_logs_action' ) ) {
		$wpdb->query( "TRUNCATE TABLE {$table_logs}" );
		$success = __( 'Sync logs cleared successfully.', 'rss-feed-manager' );
	} else {
		$error = __( 'Security check failed.', 'rss-feed-manager' );
	}
}

// Fetch logs.
$logs = [];
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_logs ) ) ) {
	// Simple pagination parameters.
	$limit  = 20;
	$page   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
	$offset = ( $page - 1 ) * $limit;

	$total_items = (int) $wpdb->get_var( "SELECT COUNT(id) FROM {$table_logs}" );
	$total_pages = ceil( $total_items / $limit );

	$logs = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$table_logs} ORDER BY id DESC LIMIT %d OFFSET %d",
			$limit,
			$offset
		)
	);
}
?>

<div class="wrap rfm-admin-wrap">
	<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
		<h2 style="margin: 0;"><?php esc_html_e( 'Sync History Logs', 'rss-feed-manager' ); ?></h2>
		<?php if ( ! empty( $logs ) ) : ?>
			<form method="post" action="">
				<?php wp_nonce_field( 'rss_clear_logs_action', 'rss_logs_nonce' ); ?>
				<button type="submit" name="rss_clear_logs" class="rfm-btn rfm-btn-danger" onclick="return confirm('<?php esc_js( esc_html_e( 'Are you sure you want to clear all sync logs?', 'rss-feed-manager' ) ); ?>');">
					<span class="dashicons dashicons-trash" style="margin-right: 4px;"></span>
					<?php esc_html_e( 'Clear All Logs', 'rss-feed-manager' ); ?>
				</button>
			</form>
		<?php endif; ?>
	</div>

	<?php if ( $success ) : ?>
		<div class="rfm-notice rfm-notice-success"><p><?php echo esc_html( $success ); ?></p></div>
	<?php endif; ?>

	<?php if ( $error ) : ?>
		<div class="rfm-notice rfm-notice-error"><p><?php echo esc_html( $error ); ?></p></div>
	<?php endif; ?>

	<div class="rfm-split-layout full-width">
		<div class="rfm-table-card">
			<table class="rfm-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Log ID', 'rss-feed-manager' ); ?></th>
						<th><?php esc_html_e( 'Feed Name', 'rss-feed-manager' ); ?></th>
						<th><?php esc_html_e( 'Category', 'rss-feed-manager' ); ?></th>
						<th><?php esc_html_e( 'Items Found', 'rss-feed-manager' ); ?></th>
						<th><?php esc_html_e( 'Imported', 'rss-feed-manager' ); ?></th>
						<th><?php esc_html_e( 'Duplicates', 'rss-feed-manager' ); ?></th>
						<th><?php esc_html_e( 'Errors / Details', 'rss-feed-manager' ); ?></th>
						<th><?php esc_html_e( 'Sync Execution Time', 'rss-feed-manager' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! empty( $logs ) ) : ?>
						<?php foreach ( $logs as $log ) : ?>
							<tr>
								<td>#<?php echo esc_html( $log->id ); ?></td>
								<td><strong><?php echo esc_html( $log->feed_name ); ?></strong></td>
								<td>
									<?php
									$log_category = \RSSFeedManager\SourceModel::resolve_log_category( $log );
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
								<td><span class="rfm-badge rfm-badge-active" style="background-color: var(--success-light); color: var(--success); font-size: 11px; padding: 2px 6px;"><?php echo esc_html( $log->imported ); ?></span></td>
								<td><?php echo esc_html( $log->duplicates ); ?></td>
								<td>
									<?php
									if ( empty( $log->errors ) ) {
										echo '<span class="dashicons dashicons-yes text-success" style="color:var(--success)"></span> ' . esc_html__( 'Success', 'rss-feed-manager' );
									} else {
										echo '<span style="color:var(--danger); font-family: monospace; font-size:12px;">' . esc_html( $log->errors ) . '</span>';
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
					<?php else : ?>
						<tr>
							<td colspan="8" style="text-align: center; color: var(--text-muted); padding: 40px;">
								<?php esc_html_e( 'No synchronization history found.', 'rss-feed-manager' ); ?>
							</td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>

			<!-- Pagination -->
			<?php if ( isset( $total_pages ) && $total_pages > 1 ) : ?>
				<div class="tablenav" style="margin-top: 20px;">
					<div class="tablenav-pages" style="float: none; display: flex; gap: 5px; justify-content: center;">
						<?php
						echo paginate_links(
							[
								'base'      => add_query_arg( 'paged', '%#%' ),
								'format'    => '',
								'prev_text' => __( '&laquo; Prev', 'rss-feed-manager' ),
								'next_text' => __( 'Next &raquo;', 'rss-feed-manager' ),
								'total'     => $total_pages,
								'current'   => $page,
							]
						);
						?>
					</div>
				</div>
			<?php endif; ?>
		</div>
	</div>
</div>
