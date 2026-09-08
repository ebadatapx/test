<?php
/**
 * Admin Settings page template.
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

$success = false;
$error   = false;

// Handle Form Saving.
if ( isset( $_POST['rss_settings_submit'] ) ) {
	if ( isset( $_POST['rss_settings_nonce'] ) && wp_verify_nonce( $_POST['rss_settings_nonce'], 'rss_save_settings_action' ) ) {
		$settings = [
			'enable_image_download'    => isset( $_POST['enable_image_download'] ) ? '1' : '0',
			'default_image'            => esc_url_raw( $_POST['default_image'] ),
			'auto_publish'             => in_array( $_POST['auto_publish'], [ 'publish', 'draft' ], true ) ? $_POST['auto_publish'] : 'publish',
			'posts_per_page'           => max( 1, absint( $_POST['posts_per_page'] ) ),
			'cron_interval'            => in_array( $_POST['cron_interval'], \RSSFeedManager\Cron::get_allowed_intervals(), true ) ? $_POST['cron_interval'] : 'hourly',
			'delete_data_on_uninstall' => isset( $_POST['delete_data_on_uninstall'] ) ? '1' : '0',
			'update_existing'          => isset( $_POST['update_existing'] ) ? '1' : '0',
			'strip_source_map'         => isset( $_POST['strip_source_map'] ) ? '1' : '0',
			'strip_source_credit'      => isset( $_POST['strip_source_credit'] ) ? '1' : '0',
			'cpt_slug'                 => isset( $_POST['cpt_slug'] ) && ! empty( $_POST['cpt_slug'] ) ? sanitize_title( $_POST['cpt_slug'] ) : 'design-dispatch',
		];

		update_option( 'rss_feed_manager_settings', $settings );
		
		// Flush rewrite rules for CPT base changes.
		flush_rewrite_rules();

		$success = __( 'Settings saved successfully.', 'rss-feed-manager' );

		// Hook to trigger rescheduling of cron when interval changes.
		do_action( 'rss_feed_manager_settings_updated', $settings );
	} else {
		$error = __( 'Security check failed.', 'rss-feed-manager' );
	}
}

// Retrieve current settings.
$settings = get_option(
	'rss_feed_manager_settings',
	[
		'enable_image_download'    => '1',
		'default_image'            => '',
		'auto_publish'             => 'publish',
		'posts_per_page'           => '10',
		'cron_interval'            => 'hourly',
		'delete_data_on_uninstall' => '0',
		'update_existing'          => '0',
		'strip_source_map'         => '1',
		'strip_source_credit'      => '1',
		'cpt_slug'                 => 'design-dispatch',
	]
);

// Enqueue media library files.
wp_enqueue_media();
?>

<div class="wrap rfm-admin-wrap">
	<h2><?php esc_html_e( 'Settings', 'rss-feed-manager' ); ?></h2>

	<?php if ( $success ) : ?>
		<div class="rfm-notice rfm-notice-success"><p><?php echo esc_html( $success ); ?></p></div>
	<?php endif; ?>

	<?php if ( $error ) : ?>
		<div class="rfm-notice rfm-notice-error"><p><?php echo esc_html( $error ); ?></p></div>
	<?php endif; ?>

	<div class="rfm-split-layout full-width">
		<div class="rfm-form-card" style="max-width: 800px; margin: 0 auto;">
			<h3><?php esc_html_e( 'Plugin Configuration', 'rss-feed-manager' ); ?></h3>
			<form method="post" action="">
				<?php wp_nonce_field( 'rss_save_settings_action', 'rss_settings_nonce' ); ?>

				<!-- Image Settings Section -->
				<div style="margin-bottom: 24px;">
					<h4 style="border-bottom: 1px solid var(--border-color); padding-bottom: 8px; margin-bottom: 16px; color: var(--primary);">
						<?php esc_html_e( 'Image & Media Handling', 'rss-feed-manager' ); ?>
					</h4>
					
					<div class="rfm-form-group" style="display: flex; align-items: center; gap: 10px;">
						<input type="checkbox" id="enable_image_download" name="enable_image_download" value="1" <?php checked( $settings['enable_image_download'] ?? '1', '1' ); ?> />
						<label for="enable_image_download" style="margin-bottom: 0; font-weight: 600;">
							<?php esc_html_e( 'Enable Featured Image Download', 'rss-feed-manager' ); ?>
						</label>
					</div>
					<p style="color: var(--text-muted); font-size: 12px; margin-top: 4px; margin-left: 24px; margin-bottom: 16px;">
						<?php esc_html_e( 'When checked, the plugin downloads feed media attachments/images to the local Media Library and configures them as the post featured image.', 'rss-feed-manager' ); ?>
					</p>

					<div class="rfm-form-group">
						<label for="default_image"><?php esc_html_e( 'Default Placeholder Image URL', 'rss-feed-manager' ); ?></label>
						<div style="display: flex; gap: 8px;">
							<input type="url" id="default_image" name="default_image" value="<?php echo esc_url( $settings['default_image'] ?? '' ); ?>" placeholder="https://domain.com/placeholder.jpg" style="flex-grow: 1;" />
							<button type="button" id="rfm_upload_btn" class="rfm-btn rfm-btn-secondary" style="white-space: nowrap;">
								<span class="dashicons dashicons-admin-media" style="margin-right: 4px;"></span>
								<?php esc_html_e( 'Upload / Select', 'rss-feed-manager' ); ?>
							</button>
						</div>
						<p style="color: var(--text-muted); font-size: 12px; margin-top: 4px;">
							<?php esc_html_e( 'Fallback image to display on the frontend if the RSS item does not contain an image.', 'rss-feed-manager' ); ?>
						</p>
					</div>
				</div>

				<!-- Sync & Publishing Section -->
				<div style="margin-bottom: 24px;">
					<h4 style="border-bottom: 1px solid var(--border-color); padding-bottom: 8px; margin-bottom: 16px; color: var(--primary);">
						<?php esc_html_e( 'Sync & Publishing Options', 'rss-feed-manager' ); ?>
					</h4>

					<div class="rfm-form-group">
						<label for="auto_publish"><?php esc_html_e( 'Auto-Publish Status', 'rss-feed-manager' ); ?></label>
						<select id="auto_publish" name="auto_publish">
							<option value="publish" <?php selected( $settings['auto_publish'] ?? 'publish', 'publish' ); ?>><?php esc_html_e( 'Publish (Show on Frontend)', 'rss-feed-manager' ); ?></option>
							<option value="draft" <?php selected( $settings['auto_publish'] ?? 'publish', 'draft' ); ?>><?php esc_html_e( 'Draft (Requires review)', 'rss-feed-manager' ); ?></option>
						</select>
						<p style="color: var(--text-muted); font-size: 12px; margin-top: 4px;">
							<?php esc_html_e( 'New news posts imported from the feed will be default set to this status.', 'rss-feed-manager' ); ?>
						</p>
					</div>

					<div class="rfm-form-group">
						<label for="cron_interval"><?php esc_html_e( 'Cron Sync Frequency', 'rss-feed-manager' ); ?></label>
						<select id="cron_interval" name="cron_interval">
							<option value="5min" <?php selected( $settings['cron_interval'] ?? 'hourly', '5min' ); ?>><?php esc_html_e( 'Every 5 Minutes', 'rss-feed-manager' ); ?></option>
							<option value="10min" <?php selected( $settings['cron_interval'] ?? 'hourly', '10min' ); ?>><?php esc_html_e( 'Every 10 Minutes', 'rss-feed-manager' ); ?></option>
							<option value="15min" <?php selected( $settings['cron_interval'] ?? 'hourly', '15min' ); ?>><?php esc_html_e( 'Every 15 Minutes', 'rss-feed-manager' ); ?></option>
							<option value="30min" <?php selected( $settings['cron_interval'] ?? 'hourly', '30min' ); ?>><?php esc_html_e( 'Every 30 Minutes', 'rss-feed-manager' ); ?></option>
							<option value="hourly" <?php selected( $settings['cron_interval'] ?? 'hourly', 'hourly' ); ?>><?php esc_html_e( 'Hourly', 'rss-feed-manager' ); ?></option>
							<option value="twicedaily" <?php selected( $settings['cron_interval'] ?? 'hourly', 'twicedaily' ); ?>><?php esc_html_e( 'Twice Daily', 'rss-feed-manager' ); ?></option>
							<option value="daily" <?php selected( $settings['cron_interval'] ?? 'hourly', 'daily' ); ?>><?php esc_html_e( 'Daily', 'rss-feed-manager' ); ?></option>
						</select>
						<p style="color: var(--text-muted); font-size: 12px; margin-top: 4px;">
							<?php esc_html_e( 'How often the background worker retrieves feed updates.', 'rss-feed-manager' ); ?>
							<br />
							<?php esc_html_e( 'Note: WordPress cron only fires when someone visits the site, so on a quiet site the short intervals will drift. For dependable 5 or 10 minute syncing, disable WP-Cron and drive it from a real server cron job.', 'rss-feed-manager' ); ?>
						</p>
					</div>

					<div class="rfm-form-group" style="display: flex; align-items: center; gap: 10px;">
						<input type="checkbox" id="strip_source_map" name="strip_source_map" value="1" <?php checked( $settings['strip_source_map'] ?? '1', '1' ); ?> />
						<label for="strip_source_map" style="margin-bottom: 0; font-weight: 600;">
							<?php esc_html_e( 'Remove the "Source Reference Map" block from imported articles', 'rss-feed-manager' ); ?>
						</label>
					</div>
					<p style="color: var(--text-muted); font-size: 12px; margin-top: 4px; margin-left: 24px; margin-bottom: 16px;">
						<?php esc_html_e( 'Strips the trailing citation list: the heading and its per-paragraph reference lines.', 'rss-feed-manager' ); ?>
					</p>

					<div class="rfm-form-group" style="display: flex; align-items: center; gap: 10px; margin-left: 24px;">
						<input type="checkbox" id="strip_source_credit" name="strip_source_credit" value="1" <?php checked( $settings['strip_source_credit'] ?? '1', '1' ); ?> />
						<label for="strip_source_credit" style="margin-bottom: 0; font-weight: 600;">
							<?php esc_html_e( 'Also remove the "Source: ..." credit line', 'rss-feed-manager' ); ?>
						</label>
					</div>
					<p style="color: var(--text-muted); font-size: 12px; margin-top: 4px; margin-left: 48px; margin-bottom: 16px;">
						<?php esc_html_e( 'Removes the publisher credit that follows the citation block, and any standalone "Source:", "Courtesy:" or similar line. Check your content licence first: some feed providers require attribution to be retained. Only short lines that begin with a credit keyword are removed, so prose mentioning a source is left alone.', 'rss-feed-manager' ); ?>
					</p>

					<div class="rfm-form-group" style="display: flex; align-items: center; gap: 10px;">
						<input type="checkbox" id="update_existing" name="update_existing" value="1" <?php checked( $settings['update_existing'] ?? '0', '1' ); ?> />
						<label for="update_existing" style="margin-bottom: 0; font-weight: 600;">
							<?php esc_html_e( 'Refresh Existing Posts When the Source Article Changes', 'rss-feed-manager' ); ?>
						</label>
					</div>
					<p style="color: var(--text-muted); font-size: 12px; margin-top: 4px; margin-left: 24px; margin-bottom: 16px;">
						<?php esc_html_e( 'When checked, a sync also updates the title and body of already-imported posts if the publisher edited them. Leave unchecked if your editors modify imported posts locally, as their edits would be overwritten.', 'rss-feed-manager' ); ?>
					</p>

					<div class="rfm-form-group">
						<label for="posts_per_page"><?php esc_html_e( 'Frontend Posts Per Page Limit', 'rss-feed-manager' ); ?></label>
						<input type="number" id="posts_per_page" name="posts_per_page" min="1" max="100" value="<?php echo esc_attr( $settings['posts_per_page'] ?? '10' ); ?>" />
						<p style="color: var(--text-muted); font-size: 12px; margin-top: 4px;">
							<?php esc_html_e( 'Default pagination count for shortcodes showing imported feeds.', 'rss-feed-manager' ); ?>
						</p>
					</div>

					<div class="rfm-form-group">
						<label for="cpt_slug"><?php esc_html_e( 'Custom Post Type Slug Base', 'rss-feed-manager' ); ?></label>
						<input type="text" id="cpt_slug" name="cpt_slug" value="<?php echo esc_attr( $settings['cpt_slug'] ?? 'rss-news' ); ?>" required placeholder="e.g. rss-news" />
						<p style="color: var(--text-muted); font-size: 12px; margin-top: 4px;">
							<?php esc_html_e( 'The URL base for news posts. For example: using "pagename" generates /newswire/pagename/categoryname/postname.', 'rss-feed-manager' ); ?>
						</p>
					</div>
				</div>

				<!-- Safety and Cleanup Section -->
				<div style="margin-bottom: 24px;">
					<h4 style="border-bottom: 1px solid var(--border-color); padding-bottom: 8px; margin-bottom: 16px; color: var(--danger);">
						<?php esc_html_e( 'Database Safety & Uninstall', 'rss-feed-manager' ); ?>
					</h4>

					<div class="rfm-form-group" style="display: flex; align-items: center; gap: 10px;">
						<input type="checkbox" id="delete_data_on_uninstall" name="delete_data_on_uninstall" value="1" <?php checked( $settings['delete_data_on_uninstall'] ?? '0', '1' ); ?> />
						<label for="delete_data_on_uninstall" style="margin-bottom: 0; font-weight: 600; color: var(--danger);">
							<?php esc_html_e( 'Delete Plugin Data on Uninstall', 'rss-feed-manager' ); ?>
						</label>
					</div>
					<p style="color: var(--text-muted); font-size: 12px; margin-top: 4px; margin-left: 24px;">
						<strong style="color: var(--danger);"><?php esc_html_e( 'WARNING:', 'rss-feed-manager' ); ?></strong>
						<?php esc_html_e( 'Checking this box deletes all RSS feed configurations, imported posts, logs, and metadata from your database upon deleting this plugin.', 'rss-feed-manager' ); ?>
					</p>
				</div>

				<div style="margin-top: 30px; border-top: 1px solid var(--border-color); padding-top: 20px;">
					<button type="submit" name="rss_settings_submit" class="rfm-btn rfm-btn-primary">
						<span class="dashicons dashicons-saved" style="margin-right: 6px;"></span>
						<?php esc_html_e( 'Save Settings Configurations', 'rss-feed-manager' ); ?>
					</button>
				</div>
			</form>
		</div>
	</div>
</div>

<script>
jQuery(document).ready(function($){
	// WordPress Media Library Integration
	$('#rfm_upload_btn').click(function(e) {
		e.preventDefault();
		var image_frame;
		if (image_frame) {
			image_frame.open();
			return;
		}
		image_frame = wp.media({
			title: '<?php echo esc_js( __( 'Select Default Placeholder Image', 'rss-feed-manager' ) ); ?>',
			multiple: false,
			library: {
				type: 'image'
			},
			button: {
				text: '<?php echo esc_js( __( 'Use as Placeholder', 'rss-feed-manager' ) ); ?>'
			}
		});
		image_frame.on('select', function() {
			var attachment = image_frame.state().get('selection').first().toJSON();
			$('#default_image').val(attachment.url);
		});
		image_frame.open();
	});
});
</script>
