<?php
/**
 * Reset & Re-sync admin page.
 *
 * @package           RSSFeedManager
 * @author            Senior WordPress Developer
 * @copyright         2026 RSS Feed Manager
 * @license           GPL-2.0-or-later
 */

use RSSFeedManager\Admin\ResetTool;
use RSSFeedManager\ContentCleaner;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'delete_others_posts' ) ) {
	wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'rss-feed-manager' ) );
}

$totals        = ResetTool::get_totals();
$clean_pending = ResetTool::count_posts_with_block();
?>

<div class="wrap rfm-admin-wrap">
	<h2><?php esc_html_e( 'Reset &amp; Re-sync', 'rss-feed-manager' ); ?></h2>

	<div class="rfm-split-layout full-width">
		<div class="rfm-form-card" style="max-width: 820px; margin-bottom: 24px;">
			<h3 style="margin-top: 0;"><?php esc_html_e( 'Clean Up Existing Posts', 'rss-feed-manager' ); ?></h3>
			<p style="font-size: 13px; color: var(--text-muted); line-height: 1.7;">
				<?php esc_html_e( 'New imports are cleaned automatically according to your Settings. This applies the same cleanup to posts imported before those settings existed — removing the "Source Reference Map" citation block, and the "Source:" credit line if that option is enabled. Post content is edited in place; nothing is deleted.', 'rss-feed-manager' ); ?>
			</p>

			<p style="font-size: 13px; margin: 16px 0;">
				<strong><?php echo esc_html( $clean_pending ); ?></strong>
				<?php esc_html_e( 'imported posts would be changed by the current cleanup settings.', 'rss-feed-manager' ); ?>
			</p>

			<button type="button" id="rfm-clean-run" class="rfm-btn rfm-btn-primary" <?php disabled( 0, $clean_pending ); ?>>
				<span class="dashicons dashicons-editor-removeformatting" style="margin-right: 6px;"></span>
				<?php esc_html_e( 'Clean Up Posts Now', 'rss-feed-manager' ); ?>
			</button>

			<div id="rfm-clean-progress" style="display: none; margin-top: 16px;">
				<pre id="rfm-clean-log" style="padding: 14px; background: #0f172a; color: #e2e8f0; border-radius: var(--radius-sm); font-size: 12px; line-height: 1.7; max-height: 220px; overflow: auto;"></pre>
			</div>
		</div>
	</div>

	<div class="rfm-split-layout full-width">
		<div class="rfm-form-card" style="max-width: 820px;">

			<h3 style="color: var(--danger); margin-top: 0;">
				<?php esc_html_e( 'This permanently deletes content. There is no undo.', 'rss-feed-manager' ); ?>
			</h3>

			<p style="font-size: 13px; color: var(--text-muted); line-height: 1.7;">
				<?php esc_html_e( 'Take a database and uploads backup before running this. Deleted posts do not go to Trash and deleted image files are removed from disk.', 'rss-feed-manager' ); ?>
			</p>

			<table class="rfm-table" style="margin-bottom: 24px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'What will be removed', 'rss-feed-manager' ); ?></th>
						<th><?php esc_html_e( 'Count', 'rss-feed-manager' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td><?php esc_html_e( 'Imported posts (carrying a feed source marker)', 'rss-feed-manager' ); ?></td>
						<td><strong id="rfm-reset-count-posts"><?php echo esc_html( $totals['posts'] ); ?></strong></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Legacy rss_news posts from earlier versions', 'rss-feed-manager' ); ?></td>
						<td><strong><?php echo esc_html( $totals['legacy'] ); ?></strong></td>
					</tr>
					<tr>
						<td>
							<?php esc_html_e( 'Media files sideloaded onto those posts', 'rss-feed-manager' ); ?>
							<br />
							<span style="font-size: 11px; color: var(--text-muted);">
								<?php esc_html_e( 'Only attachments whose parent is an imported post. Your placeholder image, theme images and page media are not touched.', 'rss-feed-manager' ); ?>
							</span>
						</td>
						<td><strong><?php echo esc_html( $totals['attachments'] ); ?></strong></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Sync log entries', 'rss-feed-manager' ); ?></td>
						<td><strong><?php echo esc_html( $totals['logs'] ); ?></strong></td>
					</tr>
				</tbody>
			</table>

			<p style="font-size: 13px; color: var(--text-muted); line-height: 1.7;">
				<?php esc_html_e( 'It then clears the cached feed payloads, flushes the object cache, and resets every source\'s last-sync timestamp so the next scheduled run treats them all as due.', 'rss-feed-manager' ); ?>
			</p>

			<div class="rfm-form-group" style="display: flex; align-items: flex-start; gap: 10px; margin-top: 20px;">
				<input type="checkbox" id="rfm-reset-media" checked style="margin-top: 3px;" />
				<label for="rfm-reset-media" style="margin-bottom: 0;">
					<?php esc_html_e( 'Also delete the sideloaded media files', 'rss-feed-manager' ); ?>
					<br />
					<span style="font-weight: 400; font-size: 12px; color: var(--text-muted);">
						<?php esc_html_e( 'Uncheck to keep the images in the Media Library and remove only the posts.', 'rss-feed-manager' ); ?>
					</span>
				</label>
			</div>

			<div class="rfm-form-group" style="margin-top: 20px;">
				<label for="rfm-reset-confirm">
					<?php esc_html_e( 'Type DELETE to enable the button', 'rss-feed-manager' ); ?>
				</label>
				<input type="text" id="rfm-reset-confirm" autocomplete="off" placeholder="DELETE" style="max-width: 220px;" />
			</div>

			<div style="margin-top: 24px; border-top: 1px solid var(--border-color); padding-top: 20px;">
				<button type="button" id="rfm-reset-run" class="rfm-btn rfm-btn-danger" disabled>
					<span class="dashicons dashicons-trash" style="margin-right: 6px;"></span>
					<?php esc_html_e( 'Delete Everything and Reset', 'rss-feed-manager' ); ?>
				</button>
			</div>

			<div id="rfm-reset-progress" style="display: none; margin-top: 24px;">
				<div style="height: 8px; background: var(--bg-main); border-radius: 999px; overflow: hidden;">
					<div id="rfm-reset-bar" style="height: 100%; width: 0%; background: var(--danger); transition: width .3s ease;"></div>
				</div>
				<pre id="rfm-reset-log" style="margin-top: 16px; padding: 14px; background: #0f172a; color: #e2e8f0; border-radius: var(--radius-sm); font-size: 12px; line-height: 1.7; max-height: 320px; overflow: auto;"></pre>
			</div>

		</div>
	</div>
</div>

<script>
jQuery(function ($) {
	var $confirm  = $('#rfm-reset-confirm');
	var $run      = $('#rfm-reset-run');
	var $progress = $('#rfm-reset-progress');
	var $bar      = $('#rfm-reset-bar');
	var $log      = $('#rfm-reset-log');

	var startTotal = 0;

	$confirm.on('input', function () {
		$run.prop('disabled', $confirm.val().trim() !== 'DELETE');
	});

	// --- Non-destructive cleanup of existing posts ---
	var $cleanRun = $('#rfm-clean-run');
	var $cleanLog = $('#rfm-clean-log');

	function cleanSay(line) {
		$cleanLog.append(line + '\n');
		$cleanLog.scrollTop($cleanLog[0].scrollHeight);
	}

	function cleanBatch(totals, afterId) {
		return $.post(rssFeedManagerReset.ajax_url, {
			action: 'rss_feed_manager_reset_step',
			nonce: rssFeedManagerReset.nonce,
			step: 'clean',
			after_id: afterId || 0
		}).then(function (res) {
			if (!res || !res.success) {
				cleanSay('! Failed: ' + ((res && res.data && res.data.message) || 'unknown error'));
				return $.Deferred().reject();
			}

			var d = res.data;
			totals.cleaned += d.cleaned;
			totals.skipped += (d.skipped || 0);

			if (d.done) {
				cleanSay('- Cleaned ' + totals.cleaned + ' posts'
					+ (totals.skipped ? ', ' + totals.skipped + ' needed no change' : ''));
				return true;
			}

			cleanSay('  ...' + totals.cleaned + ' cleaned so far');
			return cleanBatch(totals, d.last_id);
		});
	}

	$cleanRun.on('click', function () {
		$cleanRun.prop('disabled', true);
		$('#rfm-clean-progress').show();
		$cleanLog.text('');
		cleanSay('Stripping reference blocks...');

		var totals = { cleaned: 0, skipped: 0 };

		cleanBatch(totals, 0).then(function () {
			cleanSay('');
			cleanSay('Done. Reload to re-check.');
		}).fail(function () {
			cleanSay('Stopped. Reload this page and try again.');
			$cleanRun.prop('disabled', false);
		});
	});

	function say(line) {
		$log.append(line + '\n');
		$log.scrollTop($log[0].scrollHeight);
	}

	function step(name, extra) {
		return $.post(rssFeedManagerReset.ajax_url, $.extend({
			action: 'rss_feed_manager_reset_step',
			nonce: rssFeedManagerReset.nonce,
			step: name
		}, extra || {}));
	}

	function deletePosts(withMedia, totals) {
		return step('posts', { with_media: withMedia ? '1' : '0' }).then(function (res) {
			if (!res || !res.success) {
				say('! Failed: ' + ((res && res.data && res.data.message) || 'unknown error'));
				return $.Deferred().reject();
			}

			var d = res.data;
			totals.posts += d.deleted_posts;
			totals.media += d.deleted_media;

			if (startTotal > 0) {
				var pct = Math.min(100, Math.round(((startTotal - d.remaining_posts) / startTotal) * 100));
				$bar.css('width', pct + '%');
			}

			if (d.done) {
				say('- Posts deleted: ' + totals.posts + ', media files deleted: ' + totals.media);
				return true;
			}

			say('  ...' + d.remaining_posts + ' posts remaining');
			return deletePosts(withMedia, totals);
		});
	}

	$run.on('click', function () {
		var withMedia = $('#rfm-reset-media').is(':checked');

		$run.prop('disabled', true);
		$confirm.prop('disabled', true);
		$progress.show();
		$log.text('');

		var totals = { posts: 0, media: 0 };

		say('Counting...');

		step('count').then(function (res) {
			startTotal = res.data.posts + res.data.legacy;
			say('- ' + startTotal + ' posts, ' + res.data.attachments + ' attachments, ' + res.data.logs + ' log rows');
			say('Deleting posts' + (withMedia ? ' and media' : '') + '...');
			return deletePosts(withMedia, totals);
		}).then(function () {
			say('Clearing sync logs...');
			return step('logs');
		}).then(function () {
			say('- Sync logs cleared');
			say('Clearing feed cache and object cache...');
			return step('cache');
		}).then(function (res) {
			say('- Feed cache cleared for ' + res.data.feeds_cleared + ' sources; object cache flush: ' + (res.data.object_cache ? 'yes' : 'not available'));
			say('Resetting last-sync timestamps...');
			return step('sources');
		}).then(function (res) {
			say('- ' + res.data.sources_reset + ' sources marked as due');
			$bar.css('width', '100%');
			say('');
			say('Done. The next scheduled run will start from scratch.');
			say('Use Feed Sources > Sync on a single feed if you want to kick it off now.');
		}).fail(function () {
			say('');
			say('Stopped. Reload this page to see what remains and run it again.');
			$confirm.prop('disabled', false).val('');
		});
	});
});
</script>
