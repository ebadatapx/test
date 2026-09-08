<?php
/**
 * RSS Feed Import Engine.
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
 * Class ImportEngine
 *
 * Parses RSS feeds, handles duplicate verification, downloads images, and registers news posts.
 */
class ImportEngine {

	/**
	 * Cached author ID used for imported posts.
	 *
	 * @var int|null
	 */
	private $author_id = null;

	/**
	 * Absolute timestamp at which the current multi-feed run must stop.
	 *
	 * @var int
	 */
	private $run_deadline = 0;

	/**
	 * Register AJAX hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'wp_ajax_rss_feed_manager_sync_feed', [ $this, 'ajax_sync_feed' ] );
	}

	/**
	 * AJAX handler for syncing a single feed.
	 *
	 * @return void
	 */
	public function ajax_sync_feed() {
		// Verify security.
		check_ajax_referer( 'rss_feed_manager_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'rss-feed-manager' ) ] );
		}

		$feed_id = isset( $_POST['feed_id'] ) ? absint( $_POST['feed_id'] ) : 0;
		if ( ! $feed_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid feed source ID.', 'rss-feed-manager' ) ] );
		}

		// A manual sync should always see the live feed, never the cached copy.
		$result = $this->sync_feed( $feed_id, true );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Sync a specific feed source by ID.
	 *
	 * @param int  $feed_id     The feed source database ID.
	 * @param bool $force_fresh Bypass the SimplePie transient cache for this run.
	 * @return array|\WP_Error Sync results array or WP_Error.
	 */
	public function sync_feed( $feed_id, $force_fresh = false ) {
		$source_model = new SourceModel();
		$feed_source  = $source_model->get_by_id( $feed_id );

		if ( ! $feed_source ) {
			return new \WP_Error( 'feed_not_found', __( 'Feed source not found in database.', 'rss-feed-manager' ) );
		}

		// Prevent a cron run and a manual sync from importing the same items twice.
		$lock_key = 'rss_fm_lock_' . (int) $feed_id;
		if ( get_transient( $lock_key ) ) {
			return new \WP_Error( 'sync_in_progress', __( 'A sync for this feed is already running. Please try again shortly.', 'rss-feed-manager' ) );
		}
		// Comfortably longer than the per-feed time budget, short enough that a hard
		// PHP crash does not block the next run for long on a 5-minute schedule.
		set_transient( $lock_key, 1, 4 * MINUTE_IN_SECONDS );

		try {
			return $this->do_sync( $source_model, $feed_source, $force_fresh );
		} finally {
			delete_transient( $lock_key );
		}
	}

	/**
	 * Perform the actual import for one feed source.
	 *
	 * @param SourceModel $source_model Source model instance.
	 * @param object      $feed_source  Feed source row.
	 * @param bool        $force_fresh  Bypass the feed cache.
	 * @return array|\WP_Error
	 */
	private function do_sync( $source_model, $feed_source, $force_fresh ) {
		// Include SimplePie wrapper.
		if ( ! function_exists( 'fetch_feed' ) ) {
			require_once ABSPATH . WPINC . '/class-feed.php';
		}

		/*
		 * fetch_feed() caches the parsed feed in a transient for 12 hours by default,
		 * which makes any sync interval shorter than 12h a no-op. Shorten the window
		 * so scheduled syncs actually see new items, and drop the cache entirely for
		 * manual syncs.
		 */
		if ( $force_fresh ) {
			$this->delete_feed_cache( $feed_source->feed_url );
		}
		add_filter( 'wp_feed_cache_transient_lifetime', [ $this, 'filter_feed_cache_lifetime' ], 99 );

		/*
		 * SSL verification used to be switched off unconditionally, which silently
		 * downgraded every HTTP request made during the fetch. It is now opt-in:
		 * add define( 'RSS_FEED_MANAGER_INSECURE_SSL', true ); to wp-config.php only
		 * if the host's CA bundle is genuinely broken.
		 */
		$insecure = defined( 'RSS_FEED_MANAGER_INSECURE_SSL' ) && RSS_FEED_MANAGER_INSECURE_SSL;
		if ( $insecure ) {
			add_filter( 'http_request_args', [ $this, 'disable_ssl_verification' ], 10, 2 );
		}

		$feed = fetch_feed( $feed_source->feed_url );

		if ( $insecure ) {
			remove_filter( 'http_request_args', [ $this, 'disable_ssl_verification' ], 10 );
		}

		remove_filter( 'wp_feed_cache_transient_lifetime', [ $this, 'filter_feed_cache_lifetime' ], 99 );

		if ( is_wp_error( $feed ) ) {
			$error_message = $feed->get_error_message();
			$this->log_sync_result( $feed_source, 0, 0, 0, $error_message );
			return new \WP_Error( 'feed_fetch_failed', sprintf( __( 'Failed to fetch RSS: %s', 'rss-feed-manager' ), $error_message ) );
		}

		// Retrieve setting values.
		$settings = get_option(
			'rss_feed_manager_settings',
			[
				'enable_image_download' => '1',
				'default_image'         => '',
				'auto_publish'          => 'publish',
			]
		);

		$items_found = 0;
		$imported    = 0;
		$updated     = 0;
		$duplicates  = 0;
		$errors      = [];

		$started     = time();
		$max_seconds = (int) apply_filters( 'rss_feed_manager_max_sync_seconds', 30, $feed_source );

		/*
		 * When part of a multi-feed run, never spend more than the time left in the
		 * whole run. Otherwise a single slow feed consumes the entire PHP request and
		 * the feeds behind it are never reached.
		 */
		if ( $this->run_deadline > 0 ) {
			$max_seconds = max( 5, min( $max_seconds, $this->run_deadline - time() ) );
		}

		$items = $feed->get_items();
		if ( ! empty( $items ) ) {
			$items_found = count( $items );

			foreach ( $items as $item ) {
				// Stay inside the request time budget; the next run picks up where this stopped.
				if ( $max_seconds > 0 && ( time() - $started ) > $max_seconds ) {
					$errors[] = __( 'Time budget reached; remaining items will be processed on the next sync.', 'rss-feed-manager' );
					break;
				}

				$guid          = sanitize_text_field( (string) $item->get_id() );
				$original_link = esc_url_raw( (string) $item->get_permalink() );
				$title         = sanitize_text_field( (string) $item->get_title() );

				if ( '' === $guid && '' === $original_link && '' === $title ) {
					continue; // Nothing usable to identify this item by.
				}

				// 1. Check Duplicates (GUID -> Permalink -> Title within the same source).
				$existing_id = $this->post_exists( $guid, $original_link, $title, $feed_source );
				if ( $existing_id ) {
					if ( isset( $feed_source->category_id ) && $feed_source->category_id > 0 ) {
						wp_set_post_categories( $existing_id, [ (int) $feed_source->category_id ], true );
					}
					// Also link this feed source to the existing post if not already linked.
					$linked_sources = get_post_meta( $existing_id, '_source_feed' );
					if ( ! in_array( (string) $feed_source->id, array_map( 'strval', (array) $linked_sources ), true ) ) {
						add_post_meta( $existing_id, '_source_feed', $feed_source->id );
					}

					// Refresh the local copy when the publisher edited the article.
					if ( $this->maybe_update_existing( $existing_id, $item, $settings ) ) {
						$updated++;
					}

					$duplicates++;
					continue;
				}

				// Prepare Post Content.
				$content = $item->get_content();
				if ( empty( $content ) ) {
					$content = $item->get_description();
				}

				// Drop the publisher's trailing citation block, keeping any credit line.
				$content = ContentCleaner::maybe_strip( $content, $settings );

				// Extract Author name.
				$author      = $item->get_author();
				$author_name = $author ? sanitize_text_field( $author->get_name() ) : '';

				// Date handling. SimplePie returns UTC because WordPress forces PHP's
				// timezone to UTC, so convert explicitly instead of treating it as local
				// time (which pushed posts into the 'future' status on UTC-negative sites).
				list( $post_date, $post_date_gmt ) = $this->resolve_dates( $item );

				// 2. Insert standard post.
				$post_status = isset( $settings['auto_publish'] ) && 'draft' === $settings['auto_publish'] ? 'draft' : 'publish';

				$post_category = [];
				if ( isset( $feed_source->category_id ) && $feed_source->category_id > 0 ) {
					$post_category = [ (int) $feed_source->category_id ];
				}

				$post_id = wp_insert_post(
					[
						'post_title'    => $title,
						'post_content'  => wp_kses_post( $content ),
						'post_status'   => $post_status,
						'post_type'     => 'post',
						'post_author'   => $this->get_author_id(),
						'post_date'     => $post_date,
						'post_date_gmt' => $post_date_gmt,
						'post_category' => $post_category,
					],
					true
				);

				if ( is_wp_error( $post_id ) ) {
					$errors[] = sprintf( __( 'Failed inserting post "%s": %s', 'rss-feed-manager' ), $title, $post_id->get_error_message() );
					continue;
				}

				if ( $post_id > 0 ) {
					$imported++;

					// 3. Store Post Metadata.
					update_post_meta( $post_id, '_source_feed', $feed_source->id );
					update_post_meta( $post_id, '_source_url', $feed_source->feed_url );
					update_post_meta( $post_id, '_original_link', $original_link );
					update_post_meta( $post_id, '_feed_guid', $guid );
					update_post_meta( $post_id, '_feed_author', $author_name );
					update_post_meta( $post_id, '_feed_date', $post_date_gmt );
					update_post_meta( $post_id, '_feed_hash', $this->item_hash( $item ) );
					update_post_meta( $post_id, '_import_status', 'enabled' ); // Enabled by default.
					update_post_meta( $post_id, '_sync_time', current_time( 'mysql' ) );

					// 4. Handle Featured Image.
					$this->process_post_image( $post_id, $item, $settings );
				}
			}
		}

		$error_log_string = ! empty( $errors ) ? implode( ' | ', $errors ) : '';

		// Update Feed Last Sync.
		$now = current_time( 'mysql' );
		$source_model->update( $feed_source->id, [ 'last_sync' => $now ] );

		// Log results.
		$this->log_sync_result( $feed_source, $items_found, $imported, $duplicates, $error_log_string );

		return [
			'items_found'    => $items_found,
			'imported_count' => $imported,
			'updated_count'  => $updated,
			'duplicates'     => $duplicates,
			'total_posts'    => $source_model->get_imported_posts_count( $feed_source->id ),
			'errors'         => $error_log_string,
			'last_sync'      => date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $now ) ),
			'message'        => sprintf(
				/* translators: 1: items parsed, 2: imported, 3: refreshed, 4: skipped */
				__( 'Sync completed: %1$d items parsed, %2$d imported, %3$d refreshed, %4$d skipped.', 'rss-feed-manager' ),
				$items_found,
				$imported,
				$updated,
				$duplicates
			),
		];
	}

	/**
	 * Sync all active feed sources (triggered by Cron).
	 *
	 * @return void
	 */
	public function sync_all_active_feeds() {
		$source_model = new SourceModel();

		/*
		 * Least-recently-synced first. The default ordering was by id, so every cron
		 * tick restarted at the same end of the list, spent the whole PHP request on
		 * the first few feeds, and starved the rest indefinitely — they showed an old
		 * last_sync and zero imported posts no matter how often cron ran.
		 */
		$active_feeds = $source_model->get_all(
			[
				'status'  => 'active',
				'orderby' => 'last_sync',
				'order'   => 'ASC',
			]
		);

		if ( empty( $active_feeds ) ) {
			return;
		}

		/*
		 * Budget the whole run against PHP's execution limit rather than giving each
		 * feed its own allowance. 17 feeds x 30s would be 510s against a 180s limit,
		 * so the request was being killed mid-import every time.
		 */
		$run_budget = Cron::get_run_budget_seconds();

		$this->run_deadline = time() + $run_budget;

		$total     = count( $active_feeds );
		$processed = 0;

		foreach ( $active_feeds as $feed ) {
			if ( time() >= $this->run_deadline ) {
				break;
			}
			$this->sync_feed( $feed->id );
			$processed++;
		}

		$this->run_deadline = 0;

		// Make truncated runs visible instead of silently invisible.
		if ( $processed < $total ) {
			update_option(
				'rss_feed_manager_last_run_partial',
				[
					'processed' => $processed,
					'total'     => $total,
					'budget'    => $run_budget,
					'time'      => current_time( 'mysql' ),
				],
				false
			);
		} else {
			delete_option( 'rss_feed_manager_last_run_partial' );
		}
	}

	/**
	 * Disable SSL verification for HTTP requests during RSS feed fetching.
	 *
	 * Only attached when RSS_FEED_MANAGER_INSECURE_SSL is defined and true.
	 *
	 * @param array  $r   HTTP request arguments.
	 * @param string $url Request URL.
	 * @return array Modified arguments.
	 */
	public function disable_ssl_verification( $r, $url ) {
		$r['sslverify'] = false;
		return $r;
	}

	/**
	 * Shorten the feed cache window so scheduled syncs see fresh items.
	 *
	 * @return int Cache lifetime in seconds.
	 */
	public function filter_feed_cache_lifetime() {
		/*
		 * Derive the cache window from the configured sync interval, never the other
		 * way round. A fixed window would silently swallow every other run once the
		 * interval drops to match it — a 5-minute schedule behind a 5-minute cache
		 * imports on roughly half its runs.
		 */
		$interval = Cron::get_configured_interval_seconds();
		$lifetime = (int) floor( $interval / 2 );

		// Never hammer the publisher harder than once a minute.
		$lifetime = max( MINUTE_IN_SECONDS, $lifetime );

		return (int) apply_filters( 'rss_feed_manager_feed_cache_lifetime', $lifetime, $interval );
	}

	/**
	 * Delete the cached SimplePie payload for a feed URL.
	 *
	 * @param string $url Feed URL.
	 * @return void
	 */
	private function delete_feed_cache( $url ) {
		// WordPress stores the SimplePie cache in transients keyed on an md5 of the URL.
		$key = md5( $url );
		delete_transient( 'feed_' . $key );
		delete_transient( 'feed_mod_' . $key );
	}

	/**
	 * Resolve local and GMT post dates for a feed item.
	 *
	 * @param object $item SimplePie item.
	 * @return array [ post_date, post_date_gmt ]
	 */
	private function resolve_dates( $item ) {
		$timestamp = $item->get_date( 'U' );
		$timestamp = $timestamp ? (int) $timestamp : 0;

		// Feeds sometimes carry pubDates in the future, which makes WordPress
		// silently switch the post to the 'future' status so it never appears.
		if ( ! $timestamp || $timestamp > time() ) {
			$timestamp = time();
		}

		$post_date_gmt = gmdate( 'Y-m-d H:i:s', $timestamp );

		return [ get_date_from_gmt( $post_date_gmt ), $post_date_gmt ];
	}

	/**
	 * Fingerprint of an item's mutable content, used to detect publisher edits.
	 *
	 * @param object $item SimplePie item.
	 * @return string
	 */
	private function item_hash( $item ) {
		$content = $item->get_content();
		if ( empty( $content ) ) {
			$content = $item->get_description();
		}

		return md5( (string) $item->get_title() . '|' . (string) $content );
	}

	/**
	 * Refresh an existing post when the source article changed.
	 *
	 * @param int    $post_id  Existing post ID.
	 * @param object $item     SimplePie item.
	 * @param array  $settings Plugin configurations.
	 * @return bool True when the post was updated.
	 */
	private function maybe_update_existing( $post_id, $item, $settings ) {
		$enabled = ! empty( $settings['update_existing'] ) && '1' === $settings['update_existing'];
		if ( ! apply_filters( 'rss_feed_manager_update_existing', $enabled, $post_id ) ) {
			return false;
		}

		$hash = $this->item_hash( $item );
		if ( get_post_meta( $post_id, '_feed_hash', true ) === $hash ) {
			return false;
		}

		$content = $item->get_content();
		if ( empty( $content ) ) {
			$content = $item->get_description();
		}
		$content = ContentCleaner::maybe_strip( $content, $settings );

		$result = wp_update_post(
			[
				'ID'           => $post_id,
				'post_title'   => sanitize_text_field( (string) $item->get_title() ),
				'post_content' => wp_kses_post( $content ),
			],
			true
		);

		if ( is_wp_error( $result ) ) {
			return false;
		}

		update_post_meta( $post_id, '_feed_hash', $hash );
		update_post_meta( $post_id, '_sync_time', current_time( 'mysql' ) );

		return true;
	}

	/**
	 * Resolve the author ID used for imported posts.
	 *
	 * @return int
	 */
	private function get_author_id() {
		if ( null !== $this->author_id ) {
			return $this->author_id;
		}

		$author_id = (int) apply_filters( 'rss_feed_manager_post_author', 0 );

		if ( $author_id <= 0 ) {
			$admins    = get_users(
				[
					'role'    => 'administrator',
					'number'  => 1,
					'orderby' => 'ID',
					'fields'  => 'ID',
				]
			);
			$author_id = ! empty( $admins ) ? (int) $admins[0] : 1;
		}

		$this->author_id = $author_id;

		return $this->author_id;
	}

	/**
	 * Checks if a news post already exists by GUID, URL, or Title.
	 *
	 * GUID and permalink are matched across every status (including trash) so that
	 * an editor deleting an item stops it coming back. The title fallback is scoped
	 * to posts imported from the same feed source, so a hand-written article or an
	 * unrelated feed never blocks an import.
	 *
	 * @param string $guid          Item GUID.
	 * @param string $original_link Item permalink.
	 * @param string $title         Item title.
	 * @param object $feed_source   Feed source row.
	 * @return int|false Post ID if duplicate exists, false otherwise.
	 */
	private function post_exists( $guid, $original_link, $title, $feed_source ) {
		global $wpdb;

		// 1. Check by GUID.
		if ( ! empty( $guid ) ) {
			$post_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT pm.post_id
					 FROM {$wpdb->postmeta} pm
					 INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
					 WHERE p.post_type = 'post'
					 AND p.post_status != 'auto-draft'
					 AND pm.meta_key = '_feed_guid'
					 AND pm.meta_value = %s
					 LIMIT 1",
					$guid
				)
			);
			if ( $post_id ) {
				return (int) $post_id;
			}
		}

		// 2. Check by Original URL.
		if ( ! empty( $original_link ) ) {
			$post_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT pm.post_id
					 FROM {$wpdb->postmeta} pm
					 INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
					 WHERE p.post_type = 'post'
					 AND p.post_status != 'auto-draft'
					 AND pm.meta_key = '_original_link'
					 AND pm.meta_value = %s
					 LIMIT 1",
					$original_link
				)
			);
			if ( $post_id ) {
				return (int) $post_id;
			}
		}

		// 3. Check by Title, but only against posts imported from this same source.
		if ( ! empty( $title ) ) {
			$post_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT p.ID
					 FROM {$wpdb->posts} p
					 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
					 WHERE p.post_type = 'post'
					 AND p.post_status NOT IN ( 'auto-draft', 'trash' )
					 AND p.post_title = %s
					 AND pm.meta_key = '_source_feed'
					 AND pm.meta_value = %d
					 LIMIT 1",
					$title,
					(int) $feed_source->id
				)
			);
			if ( $post_id ) {
				return (int) $post_id;
			}
		}

		return false;
	}

	/**
	 * Extract image from RSS enclosure or post content body and attach it.
	 *
	 * @param int    $post_id  The created post ID.
	 * @param object $item     The SimplePie item.
	 * @param array  $settings Plugin configurations.
	 * @return void
	 */
	private function process_post_image( $post_id, $item, $settings ) {
		$image_url = $this->extract_image_url( $item );

		// Store the remote feed image URL first by default.
		if ( ! empty( $image_url ) && ( 0 === strpos( $image_url, 'http' ) ) ) {
			update_post_meta( $post_id, '_feed_image', $image_url );

			// 3. Sideload and attach image if feature is enabled.
			if ( isset( $settings['enable_image_download'] ) && '1' === $settings['enable_image_download'] ) {
				require_once ABSPATH . 'wp-admin/includes/image.php';
				require_once ABSPATH . 'wp-admin/includes/file.php';
				require_once ABSPATH . 'wp-admin/includes/media.php';

				// Safe download.
				$attachment_id = media_sideload_image( $image_url, $post_id, get_the_title( $post_id ), 'id' );

				if ( ! is_wp_error( $attachment_id ) && $attachment_id > 0 ) {
					set_post_thumbnail( $post_id, $attachment_id );
					// Overwrite with downloaded local media URL.
					update_post_meta( $post_id, '_feed_image', wp_get_attachment_url( $attachment_id ) );
				}
			}
		} else {
			// No image found in RSS item. Apply placeholder.
			$this->apply_placeholder_image( $post_id, $settings );
		}
	}

	/**
	 * Resolve the best available image URL for a feed item.
	 *
	 * @param object $item SimplePie item.
	 * @return string Image URL or empty string.
	 */
	private function extract_image_url( $item ) {
		$image_url = '';

		// 1. Try SimplePie enclosure first.
		$enclosure = $item->get_enclosure();
		if ( $enclosure ) {
			if ( strpos( (string) $enclosure->get_type(), 'image/' ) === 0 ) {
				$image_url = $enclosure->get_link();
			} elseif ( $enclosure->get_thumbnail() ) {
				$image_url = $enclosure->get_thumbnail();
			}
		}

		// Fallback: Query raw <enclosure> tags directly from XML if SimplePie parser bypassed it.
		if ( empty( $image_url ) ) {
			$enclosure_tags = $item->get_item_tags( '', 'enclosure' );
			if ( ! empty( $enclosure_tags ) && isset( $enclosure_tags[0]['attribs']['']['url'] ) ) {
				$image_url = esc_url_raw( $enclosure_tags[0]['attribs']['']['url'] );
			}
		}

		// Fallback 2: Query Yahoo Media namespace content tags (<media:content>).
		if ( empty( $image_url ) ) {
			$media_tags = $item->get_item_tags( 'http://search.yahoo.com/mrss/', 'content' );
			if ( ! empty( $media_tags ) && isset( $media_tags[0]['attribs']['']['url'] ) ) {
				$image_url = esc_url_raw( $media_tags[0]['attribs']['']['url'] );
			}
		}

		// Fallback 3: <media:thumbnail>.
		if ( empty( $image_url ) ) {
			$thumb_tags = $item->get_item_tags( 'http://search.yahoo.com/mrss/', 'thumbnail' );
			if ( ! empty( $thumb_tags ) && isset( $thumb_tags[0]['attribs']['']['url'] ) ) {
				$image_url = esc_url_raw( $thumb_tags[0]['attribs']['']['url'] );
			}
		}

		// Fallback 4: parse <img> tag from content/description.
		if ( empty( $image_url ) ) {
			$html = $item->get_content();
			if ( empty( $html ) ) {
				$html = $item->get_description();
			}
			if ( ! empty( $html ) ) {
				// Decode HTML entities in case tags are encoded in the feed.
				$decoded_html = html_entity_decode( $html, ENT_QUOTES, 'UTF-8' );
				if ( preg_match( '/<img[^>]+src=[\'"]([^\'"]+)[\'"]/i', $decoded_html, $matches ) ) {
					$image_url = $matches[1];
				}
			}
		}

		return (string) $image_url;
	}

	/**
	 * Apply placeholder fallback settings to post thumbnail.
	 *
	 * @param int   $post_id  The post ID.
	 * @param array $settings Plugin configurations.
	 * @return void
	 */
	private function apply_placeholder_image( $post_id, $settings ) {
		$placeholder_url = isset( $settings['default_image'] ) ? $settings['default_image'] : '';
		if ( ! empty( $placeholder_url ) ) {
			$attachment_id = attachment_url_to_postid( $placeholder_url );
			if ( $attachment_id ) {
				set_post_thumbnail( $post_id, $attachment_id );
			}
			update_post_meta( $post_id, '_feed_image', $placeholder_url );
		}
	}

	/**
	 * Write synchronization outcomes into the custom log table.
	 *
	 * @param object $feed_source Feed source row being synchronized.
	 * @param int    $items_found Total items found.
	 * @param int    $imported    Items imported.
	 * @param int    $duplicates  Duplicates skipped.
	 * @param string $errors      Error details.
	 * @return void
	 */
	private function log_sync_result( $feed_source, $items_found, $imported, $duplicates, $errors ) {
		global $wpdb;

		$table_logs = $wpdb->prefix . 'rss_logs';

		// Never let a missing log table take the whole sync down.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_logs ) ) !== $table_logs ) {
			return;
		}

		$data = [
			'feed_name'   => isset( $feed_source->feed_name ) ? $feed_source->feed_name : '',
			'items_found' => absint( $items_found ),
			'imported'    => absint( $imported ),
			'duplicates'  => absint( $duplicates ),
			'errors'      => sanitize_textarea_field( $errors ),
			'sync_time'   => current_time( 'mysql' ),
		];
		$formats = [ '%s', '%d', '%d', '%d', '%s', '%s' ];

		/*
		 * Record the source and its category so the dashboard can report where a run
		 * sent its items. Guarded because the columns only exist after the migration,
		 * and an unmigrated install must keep logging rather than fail.
		 */
		if ( ! empty( $wpdb->get_results( "SHOW COLUMNS FROM {$table_logs} LIKE 'source_id'" ) ) ) {
			$data['source_id'] = isset( $feed_source->id ) ? absint( $feed_source->id ) : 0;
			$formats[]         = '%d';
		}
		if ( ! empty( $wpdb->get_results( "SHOW COLUMNS FROM {$table_logs} LIKE 'category_id'" ) ) ) {
			$data['category_id'] = isset( $feed_source->category_id ) ? absint( $feed_source->category_id ) : 0;
			$formats[]           = '%d';
		}

		$wpdb->insert( $table_logs, $data, $formats );
	}
}
