<?php
/**
 * Feed Sources CRUD page template.
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

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'rss-feed-manager' ) );
}

$model   = new SourceModel();
$errors  = [];
$success = [];

// Handle URL query parameters for delete/toggle actions.
$action = isset( $_GET['action'] ) ? sanitize_text_field( $_GET['action'] ) : '';
$id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

if ( ! empty( $action ) && $id > 0 ) {
	if ( 'delete' === $action ) {
		if ( isset( $_GET['_wpnonce'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'rss_source_delete_' . $id ) ) {
			if ( $model->delete( $id ) ) {
				$success[] = __( 'Feed source deleted successfully.', 'rss-feed-manager' );
			} else {
				$errors[] = __( 'Failed to delete feed source.', 'rss-feed-manager' );
			}
		} else {
			$errors[] = __( 'Security check failed.', 'rss-feed-manager' );
		}
	} elseif ( in_array( $action, [ 'enable', 'disable' ], true ) ) {
		if ( isset( $_GET['_wpnonce'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'rss_source_status_' . $id ) ) {
			$new_status = 'enable' === $action ? 'active' : 'inactive';
			if ( $model->update( $id, [ 'status' => $new_status ] ) ) {
				$success[] = 'enable' === $action ? __( 'Feed source activated.', 'rss-feed-manager' ) : __( 'Feed source deactivated.', 'rss-feed-manager' );
			} else {
				$errors[] = __( 'Failed to update feed source status.', 'rss-feed-manager' );
			}
		} else {
			$errors[] = __( 'Security check failed.', 'rss-feed-manager' );
		}
	}
}

// Handle Form Submissions (Add / Edit).
if ( isset( $_POST['rss_source_submit'] ) ) {
	if ( isset( $_POST['rss_source_save_nonce'] ) && wp_verify_nonce( $_POST['rss_source_save_nonce'], 'rss_source_save_action' ) ) {
		$feed_name = isset( $_POST['feed_name'] ) ? sanitize_text_field( $_POST['feed_name'] ) : '';
		$feed_url  = isset( $_POST['feed_url'] ) ? esc_url_raw( $_POST['feed_url'] ) : '';
		$status    = isset( $_POST['status'] ) && 'inactive' === $_POST['status'] ? 'inactive' : 'active';
		$edit_id   = isset( $_POST['edit_id'] ) ? absint( $_POST['edit_id'] ) : 0;

		$category_id = isset( $_POST['category_id'] ) ? absint( $_POST['category_id'] ) : 0;

		if ( empty( $feed_name ) || empty( $feed_url ) ) {
			$errors[] = __( 'Feed Name and Feed URL are required fields.', 'rss-feed-manager' );
		} elseif ( ! filter_var( $feed_url, FILTER_VALIDATE_URL ) ) {
			$errors[] = __( 'Please enter a valid Feed URL.', 'rss-feed-manager' );
		} else {
			$data = [
				'feed_name'   => $feed_name,
				'feed_url'    => $feed_url,
				'status'      => $status,
				'category_id' => $category_id,
			];

			if ( $edit_id > 0 ) {
				// Update.
				if ( $model->update( $edit_id, $data ) ) {
					$success[] = __( 'Feed source updated successfully.', 'rss-feed-manager' );

					// Assign category to all existing posts of this feed source.
					if ( $category_id > 0 ) {
						$posts = get_posts( [
							'post_type'   => 'post',
							'numberposts' => -1,
							'post_status' => 'any',
							'fields'      => 'ids',
							'meta_query'  => [
								[
									'key'   => '_source_feed',
									'value' => $edit_id,
								]
							]
						] );
						if ( ! empty( $posts ) ) {
							foreach ( $posts as $p_id ) {
								wp_set_post_categories( $p_id, [ $category_id ] );
							}
						}
					}
				} else {
					$errors[] = __( 'Failed to update feed source, or no changes were made.', 'rss-feed-manager' );
				}
			} else {
				// Insert.
				if ( $model->insert( $data ) ) {
					$success[] = __( 'Feed source added successfully.', 'rss-feed-manager' );
				} else {
					$errors[] = __( 'Failed to save feed source.', 'rss-feed-manager' );
				}
			}
		}
	} else {
		$errors[] = __( 'Security check failed.', 'rss-feed-manager' );
	}
}

// Check if we are in Edit Mode.
$edit_feed = null;
if ( 'edit' === $action && $id > 0 ) {
	$edit_feed = $model->get_by_id( $id );
}

// Fetch all feed sources.
$sources = $model->get_all();

// Projected next-sync time per source, from the same queue order the cron run uses.
$projected = Cron::get_projected_sync_times();
?>

<div class="wrap rfm-admin-wrap">
	<h2><?php esc_html_e( 'Feed Sources', 'rss-feed-manager' ); ?></h2>

	<!-- Status Notices -->
	<?php if ( ! empty( $errors ) ) : ?>
		<?php foreach ( $errors as $error ) : ?>
			<div class="rfm-notice rfm-notice-error"><p><?php echo esc_html( $error ); ?></p></div>
		<?php endforeach; ?>
	<?php endif; ?>

	<?php if ( ! empty( $success ) ) : ?>
		<?php foreach ( $success as $msg ) : ?>
			<div class="rfm-notice rfm-notice-success"><p><?php echo esc_html( $msg ); ?></p></div>
		<?php endforeach; ?>
	<?php endif; ?>

	<div class="rfm-split-layout">
		<!-- Left: Sources Table -->
		<div class="rfm-table-card">
			<h3><?php esc_html_e( 'Configured Sources', 'rss-feed-manager' ); ?></h3>
			<table class="rfm-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Name', 'rss-feed-manager' ); ?></th>
						<th><?php esc_html_e( 'Category', 'rss-feed-manager' ); ?></th>
						<th><?php esc_html_e( 'Feed URL', 'rss-feed-manager' ); ?></th>
						<th><?php esc_html_e( 'Status', 'rss-feed-manager' ); ?></th>
						<th class="rfm-col-sync"><?php esc_html_e( 'Last Sync', 'rss-feed-manager' ); ?></th>
						<th class="rfm-col-next"><?php esc_html_e( 'Next Sync', 'rss-feed-manager' ); ?></th>
						<th class="rfm-col-posts"><?php esc_html_e( 'Imported Posts', 'rss-feed-manager' ); ?></th>
						<th style="width: 260px;"><?php esc_html_e( 'Actions', 'rss-feed-manager' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! empty( $sources ) ) : ?>
						<?php foreach ( $sources as $src ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $src->feed_name ); ?></strong></td>
								<td>
									<?php
									if ( isset( $src->category_id ) && $src->category_id > 0 ) {
										$term = get_term( $src->category_id, 'category' );
										if ( $term && ! is_wp_error( $term ) ) {
											echo esc_html($term->name);
										} else {
											echo '<span style="color:#64748b; font-style:italic;">' . esc_html__( 'None', 'rss-feed-manager' ) . '</span>';
										}
									} else {
										echo '<span style="color:#64748b; font-style:italic;">' . esc_html__( 'None', 'rss-feed-manager' ) . '</span>';
									}
									?>
								</td>
								<td><code style="word-break: break-all;"><?php echo esc_html( $src->feed_url ); ?></code></td>
								<td>
									<?php if ( 'active' === $src->status ) : ?>
										<span class="rfm-badge rfm-badge-active"><?php esc_html_e( 'Active', 'rss-feed-manager' ); ?></span>
									<?php else : ?>
										<span class="rfm-badge rfm-badge-inactive"><?php esc_html_e( 'Inactive', 'rss-feed-manager' ); ?></span>
									<?php endif; ?>
								</td>
								<td class="rfm-col-sync">
									<?php
									echo esc_html(
										$src->last_sync ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $src->last_sync ) ) : __( 'Never', 'rss-feed-manager' )
									);
									?>
								</td>
								<td class="rfm-col-next">
									<?php
									if ( 'active' !== $src->status ) {
										echo '<span style="color:#64748b; font-style:italic;">' . esc_html__( 'Paused', 'rss-feed-manager' ) . '</span>';
									} elseif ( isset( $projected[ (int) $src->id ] ) ) {
										$p = $projected[ (int) $src->id ];
										echo esc_html( wp_date( get_option( 'time_format' ), $p['timestamp'] ) );
										echo '<br /><span style="font-size:11px; color:var(--text-muted);">';
										if ( 1 === $p['run'] ) {
											esc_html_e( 'next run', 'rss-feed-manager' );
										} else {
											echo esc_html(
												sprintf(
													/* translators: %d: number of runs away */
													__( '%d runs away', 'rss-feed-manager' ),
													$p['run']
												)
											);
										}
										echo esc_html( sprintf( ' · #%d in queue', $p['position'] ) );
										echo '</span>';
									} else {
										echo '<span style="color:var(--danger); font-size:12px;">' . esc_html__( 'Not scheduled', 'rss-feed-manager' ) . '</span>';
									}
									?>
								</td>
								<td class="rfm-col-posts">
									<?php echo esc_html( $model->get_imported_posts_count( $src->id ) ); ?>
								</td>
								<td>
									<div class="rfm-table-actions">
										<!-- Fetch Now AJAX Button -->
										<button type="button" class="rfm-action-sync rfm-btn" data-id="<?php echo esc_attr( $src->id ); ?>" title="<?php esc_attr_e( 'Fetch Now', 'rss-feed-manager' ); ?>">
											<span class="dashicons dashicons-update"></span>
										</button>

										<!-- Enable / Disable -->
										<?php if ( 'active' === $src->status ) : ?>
											<?php
											$toggle_url = wp_nonce_url(
												admin_url( 'admin.php?page=rss-feed-manager-sources&action=disable&id=' . $src->id ),
												'rss_source_status_' . $src->id
											);
											?>
											<a href="<?php echo esc_url( $toggle_url ); ?>" class="rfm-action-toggle-inactive rfm-btn" title="<?php esc_attr_e( 'Disable', 'rss-feed-manager' ); ?>">
												<span class="dashicons dashicons-hidden"></span>
											</a>
										<?php else : ?>
											<?php
											$toggle_url = wp_nonce_url(
												admin_url( 'admin.php?page=rss-feed-manager-sources&action=enable&id=' . $src->id ),
												'rss_source_status_' . $src->id
											);
											?>
											<a href="<?php echo esc_url( $toggle_url ); ?>" class="rfm-action-toggle-active rfm-btn" title="<?php esc_attr_e( 'Enable', 'rss-feed-manager' ); ?>">
												<span class="dashicons dashicons-visibility"></span>
											</a>
										<?php endif; ?>

										<!-- Edit -->
										<a href="<?php echo esc_url( admin_url( 'admin.php?page=rss-feed-manager-sources&action=edit&id=' . $src->id ) ); ?>" class="rfm-action-edit rfm-btn" title="<?php esc_attr_e( 'Edit', 'rss-feed-manager' ); ?>">
											<span class="dashicons dashicons-edit"></span>
										</a>

										<!-- Delete -->
										<?php
										$delete_url = wp_nonce_url(
											admin_url( 'admin.php?page=rss-feed-manager-sources&action=delete&id=' . $src->id ),
											'rss_source_delete_' . $src->id
										);
										?>
										<a href="<?php echo esc_url( $delete_url ); ?>" class="rfm-action-delete rfm-btn" onclick="return confirm('<?php esc_js( esc_html_e( 'Are you sure you want to delete this feed source?', 'rss-feed-manager' ) ); ?>');" title="<?php esc_attr_e( 'Delete', 'rss-feed-manager' ); ?>">
											<span class="dashicons dashicons-trash"></span>
										</a>
									</div>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php else : ?>
						<tr>
							<td colspan="8" style="text-align: center; color: var(--text-muted); padding: 30px;">
								<?php esc_html_e( 'No feed sources configured yet.', 'rss-feed-manager' ); ?>
							</td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>
		</div>

		<!-- Right: Form -->
		<div class="rfm-form-card">
			<h3><?php echo $edit_feed ? esc_html__( 'Edit Feed Source', 'rss-feed-manager' ) : esc_html__( 'Add New Feed Source', 'rss-feed-manager' ); ?></h3>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=rss-feed-manager-sources' ) ); ?>">
				<?php wp_nonce_field( 'rss_source_save_action', 'rss_source_save_nonce' ); ?>
				
				<?php if ( $edit_feed ) : ?>
					<input type="hidden" name="edit_id" value="<?php echo esc_attr( $edit_feed->id ); ?>" />
				<?php endif; ?>

				<div class="rfm-form-group">
					<label for="feed_name"><?php esc_html_e( 'Feed Name', 'rss-feed-manager' ); ?> *</label>
					<input type="text" id="feed_name" name="feed_name" required value="<?php echo $edit_feed ? esc_attr( $edit_feed->feed_name ) : ''; ?>" placeholder="e.g. Architecture Design India" />
				</div>

				<div class="rfm-form-group">
					<label for="feed_url"><?php esc_html_e( 'Feed URL', 'rss-feed-manager' ); ?> *</label>
					<input type="url" id="feed_url" name="feed_url" required value="<?php echo $edit_feed ? esc_url( $edit_feed->feed_url ) : ''; ?>" placeholder="e.g. https://domain.com/feed.xml" />
				</div>

				<div class="rfm-form-group">
					<label for="category_id"><?php esc_html_e( 'Category', 'rss-feed-manager' ); ?></label>
					<select id="category_id" name="category_id">
						<option value="0"><?php esc_html_e( '— None —', 'rss-feed-manager' ); ?></option>
						<?php
						$categories = get_categories( [
							'hide_empty' => false,
						] );
						if ( ! is_wp_error( $categories ) && ! empty( $categories ) ) {
							foreach ( $categories as $cat ) {
								$selected = ( $edit_feed && (int) $edit_feed->category_id === (int) $cat->term_id ) ? 'selected' : '';
								echo '<option value="' . esc_attr( $cat->term_id ) . '" ' . $selected . '>' . esc_html( $cat->name ) . '</option>';
							}
						}
						?>
					</select>
				</div>

				<div class="rfm-form-group">
					<label for="status"><?php esc_html_e( 'Initial Status', 'rss-feed-manager' ); ?></label>
					<select id="status" name="status">
						<option value="active" <?php echo ( $edit_feed && 'active' === $edit_feed->status ) ? 'selected' : ''; ?>><?php esc_html_e( 'Active', 'rss-feed-manager' ); ?></option>
						<option value="inactive" <?php echo ( $edit_feed && 'inactive' === $edit_feed->status ) ? 'selected' : ''; ?>><?php esc_html_e( 'Inactive', 'rss-feed-manager' ); ?></option>
					</select>
				</div>

				<div style="display: flex; gap: 10px; margin-top: 20px;">
					<button type="submit" name="rss_source_submit" class="rfm-btn rfm-btn-primary" style="flex-grow: 1;">
						<?php echo $edit_feed ? esc_html__( 'Update Source', 'rss-feed-manager' ) : esc_html__( 'Save Source', 'rss-feed-manager' ); ?>
					</button>
					<?php if ( $edit_feed ) : ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=rss-feed-manager-sources' ) ); ?>" class="rfm-btn rfm-btn-secondary">
							<?php esc_html_e( 'Cancel', 'rss-feed-manager' ); ?>
						</a>
					<?php endif; ?>
				</div>
			</form>
		</div>
	</div>
</div>
