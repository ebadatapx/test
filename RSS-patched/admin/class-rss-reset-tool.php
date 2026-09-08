<?php
/**
 * Reset & Re-sync tool.
 *
 * Removes feed-imported posts, their sideloaded media, and the sync history, then
 * clears the feed cache so the next sync starts from a clean slate.
 *
 * @package           RSSFeedManager
 * @author            Senior WordPress Developer
 * @copyright         2026 RSS Feed Manager
 * @license           GPL-2.0-or-later
 */

namespace RSSFeedManager\Admin;

use RSSFeedManager\SourceModel;
use RSSFeedManager\ContentCleaner;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class ResetTool
 *
 * Batched, capability-checked teardown of imported content.
 */
class ResetTool {

	/**
	 * Posts deleted per AJAX batch. Deliberately small: each post may own several
	 * attachments, and deleting an attachment also removes its generated image sizes.
	 */
	const BATCH_SIZE = 10;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'wp_ajax_rss_feed_manager_reset_step', [ $this, 'ajax_reset_step' ] );
	}

	/**
	 * Count everything the reset would remove.
	 *
	 * @return array
	 */
	public static function get_totals() {
		global $wpdb;

		$posts = (int) $wpdb->get_var(
			"SELECT COUNT( DISTINCT p.ID )
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_source_feed'
			 WHERE p.post_type IN ( 'post', 'rss_news' )"
		);

		$legacy = (int) $wpdb->get_var(
			"SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type = 'rss_news'"
		);

		$attachments = (int) $wpdb->get_var(
			"SELECT COUNT( DISTINCT a.ID )
			 FROM {$wpdb->posts} a
			 INNER JOIN {$wpdb->postmeta} pm ON a.post_parent = pm.post_id AND pm.meta_key = '_source_feed'
			 WHERE a.post_type = 'attachment'"
		);

		$table_logs = $wpdb->prefix . 'rss_logs';
		$logs       = 0;
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_logs ) ) === $table_logs ) {
			$logs = (int) $wpdb->get_var( "SELECT COUNT(id) FROM {$table_logs}" );
		}

		return [
			'posts'       => $posts,
			'legacy'      => $legacy,
			'attachments' => $attachments,
			'logs'        => $logs,
		];
	}

	/**
	 * AJAX router for a single reset step.
	 *
	 * @return void
	 */
	public function ajax_reset_step() {
		check_ajax_referer( 'rss_feed_manager_reset_nonce', 'nonce' );

		if ( ! current_user_can( 'delete_others_posts' ) || ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'rss-feed-manager' ) ] );
		}

		$step         = isset( $_POST['step'] ) ? sanitize_key( $_POST['step'] ) : '';
		$with_media   = isset( $_POST['with_media'] ) && '1' === $_POST['with_media'];

		switch ( $step ) {
			case 'count':
				wp_send_json_success( self::get_totals() );
				break;

			case 'posts':
				wp_send_json_success( $this->delete_post_batch( $with_media ) );
				break;

			case 'logs':
				wp_send_json_success( $this->clear_logs() );
				break;

			case 'cache':
				wp_send_json_success( $this->clear_caches() );
				break;

			case 'sources':
				wp_send_json_success( $this->reset_sources() );
				break;

			case 'clean_scan':
				wp_send_json_success( [ 'pending' => self::count_posts_with_block() ] );
				break;

			case 'clean':
				wp_send_json_success( $this->clean_batch() );
				break;

			default:
				wp_send_json_error( [ 'message' => __( 'Unknown reset step.', 'rss-feed-manager' ) ] );
		}
	}

	/**
	 * Delete one batch of imported posts, optionally with their sideloaded media.
	 *
	 * @param bool $with_media Whether to delete attachments owned by these posts.
	 * @return array Progress payload.
	 */
	private function delete_post_batch( $with_media ) {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_source_feed'
				 WHERE p.post_type IN ( 'post', 'rss_news' )
				 ORDER BY p.ID ASC
				 LIMIT %d",
				self::BATCH_SIZE
			)
		);

		// Sweep up legacy CPT rows that never carried a _source_feed marker.
		if ( empty( $ids ) ) {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'rss_news' ORDER BY ID ASC LIMIT %d",
					self::BATCH_SIZE
				)
			);
		}

		$deleted_posts = 0;
		$deleted_media = 0;

		foreach ( $ids as $post_id ) {
			$post_id = (int) $post_id;

			if ( $with_media ) {
				/*
				 * Only attachments whose parent IS this post — i.e. the ones
				 * media_sideload_image() created for it. Deliberately NOT resolved via
				 * _thumbnail_id: image-less imports have the shared placeholder image
				 * set as their thumbnail, and deleting that would break every other
				 * post using it.
				 */
				$attachments = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_parent = %d",
						$post_id
					)
				);

				foreach ( $attachments as $attachment_id ) {
					if ( wp_delete_attachment( (int) $attachment_id, true ) ) {
						$deleted_media++;
					}
				}
			}

			if ( wp_delete_post( $post_id, true ) ) {
				$deleted_posts++;
			}
		}

		$totals = self::get_totals();

		return [
			'deleted_posts'    => $deleted_posts,
			'deleted_media'    => $deleted_media,
			'remaining_posts'  => (int) $totals['posts'] + (int) $totals['legacy'],
			'done'             => empty( $ids ),
		];
	}

	/**
	 * SQL fragments matching posts that may contain strippable boilerplate.
	 *
	 * A deliberately loose pre-filter: the authoritative decision is made in PHP by
	 * ContentCleaner, so a few extra candidate rows here are harmless.
	 *
	 * @return array [ 'sql' => string, 'values' => array ]
	 */
	private static function get_candidate_clause() {
		global $wpdb;

		$needles = ContentCleaner::get_markers();

		if ( ContentCleaner::credit_enabled() ) {
			$needles = array_merge( $needles, [ 'source:', 'sources:', 'credit:', 'attribution:', 'courtesy' ] );
		}

		$clauses = [];
		$values  = [];

		foreach ( array_unique( $needles ) as $needle ) {
			$clauses[] = 'p.post_content LIKE %s';
			$values[]  = '%' . $wpdb->esc_like( $needle ) . '%';
		}

		return [
			'sql'    => $clauses ? '( ' . implode( ' OR ', $clauses ) . ' )' : '0 = 1',
			'values' => $values,
		];
	}

	/**
	 * Count already-imported posts that still contain strippable boilerplate.
	 *
	 * @return int
	 */
	public static function count_posts_with_block() {
		global $wpdb;

		$clause = self::get_candidate_clause();
		if ( empty( $clause['values'] ) ) {
			return 0;
		}

		$sql = "SELECT DISTINCT p.ID
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_source_feed'
				WHERE p.post_type IN ( 'post', 'rss_news' )
				AND " . $clause['sql'];

		$ids = $wpdb->get_col( $wpdb->prepare( $sql, $clause['values'] ) );

		// Confirm in PHP so the figure shown matches what would actually change.
		$count = 0;
		foreach ( $ids as $post_id ) {
			$post = get_post( (int) $post_id );
			if ( ! $post ) {
				continue;
			}
			if ( ContentCleaner::strip( $post->post_content ) !== $post->post_content ) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Strip boilerplate from one batch of already-imported posts.
	 *
	 * Uses an ID cursor rather than re-querying from the start, so posts the cleaner
	 * decides not to touch cannot stall the loop or be re-examined forever.
	 *
	 * @return array Progress payload.
	 */
	private function clean_batch() {
		global $wpdb;

		$after = isset( $_POST['after_id'] ) ? absint( $_POST['after_id'] ) : 0;

		$clause = self::get_candidate_clause();
		if ( empty( $clause['values'] ) ) {
			return [
				'cleaned' => 0,
				'skipped' => 0,
				'last_id' => $after,
				'done'    => true,
			];
		}

		$values   = $clause['values'];
		$values[] = $after;
		$values[] = self::BATCH_SIZE;

		$sql = "SELECT DISTINCT p.ID
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_source_feed'
				WHERE p.post_type IN ( 'post', 'rss_news' )
				AND " . $clause['sql'] . '
				AND p.ID > %d
				ORDER BY p.ID ASC
				LIMIT %d';

		$ids = $wpdb->get_col( $wpdb->prepare( $sql, $values ) );

		$cleaned = 0;
		$skipped = 0;
		$last_id = $after;

		foreach ( $ids as $post_id ) {
			$post_id = (int) $post_id;
			$last_id = max( $last_id, $post_id );

			$post = get_post( $post_id );
			if ( ! $post ) {
				continue;
			}

			$stripped = ContentCleaner::strip( $post->post_content );

			// Nothing to change, or the cleaner declined (it never empties a post).
			if ( $stripped === $post->post_content ) {
				$skipped++;
				continue;
			}

			$result = wp_update_post(
				[
					'ID'           => $post_id,
					'post_content' => $stripped,
				],
				true
			);

			if ( is_wp_error( $result ) ) {
				$skipped++;
				continue;
			}

			$cleaned++;
		}

		return [
			'cleaned' => $cleaned,
			'skipped' => $skipped,
			'last_id' => $last_id,
			'done'    => count( $ids ) < self::BATCH_SIZE,
		];
	}

	/**
	 * Empty the sync log table.
	 *
	 * @return array
	 */
	private function clear_logs() {
		global $wpdb;

		$table_logs = $wpdb->prefix . 'rss_logs';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_logs ) ) !== $table_logs ) {
			return [ 'cleared' => false ];
		}

		$wpdb->query( "TRUNCATE TABLE {$table_logs}" );
		delete_option( 'rss_feed_manager_last_run_partial' );

		return [ 'cleared' => true ];
	}

	/**
	 * Drop the cached feed payloads, per-feed locks, and the object cache.
	 *
	 * @return array
	 */
	private function clear_caches() {
		global $wpdb;

		$model   = new SourceModel();
		$sources = $model->get_all();
		$cleared = 0;

		foreach ( $sources as $source ) {
			$key = md5( $source->feed_url );
			delete_transient( 'feed_' . $key );
			delete_transient( 'feed_mod_' . $key );
			delete_transient( 'rss_fm_lock_' . (int) $source->id );
			$cleared++;
		}

		/*
		 * With an external object cache the transients above never touch the options
		 * table, so also sweep any rows left behind by a previous non-Redis setup.
		 */
		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			 WHERE option_name LIKE '_transient_feed_%'
			 OR option_name LIKE '_transient_timeout_feed_%'
			 OR option_name LIKE '_transient_rss_fm_lock_%'
			 OR option_name LIKE '_transient_timeout_rss_fm_lock_%'"
		);

		$object_cache = false;
		if ( function_exists( 'wp_cache_flush' ) ) {
			$object_cache = (bool) wp_cache_flush();
		}

		return [
			'feeds_cleared' => $cleared,
			'object_cache'  => $object_cache,
		];
	}

	/**
	 * Clear last_sync on every source so the next run treats them all as due.
	 *
	 * @return array
	 */
	private function reset_sources() {
		global $wpdb;

		$table = SourceModel::get_table_name();

		// SourceModel::update() cannot write NULL, so go direct.
		$rows = $wpdb->query( "UPDATE {$table} SET last_sync = NULL, updated_at = updated_at" );

		return [ 'sources_reset' => (int) $rows ];
	}
}
