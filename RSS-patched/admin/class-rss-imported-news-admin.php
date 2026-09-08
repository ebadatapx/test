<?php
/**
 * Custom modifications to the Imported News CPT admin screen.
 *
 * @package           RSSFeedManager
 * @author            Senior WordPress Developer
 * @copyright         2026 RSS Feed Manager
 * @license           GPL-2.0-or-later
 */

namespace RSSFeedManager\Admin;

use RSSFeedManager\SourceModel;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class ImportedNewsAdmin
 *
 * Customizes column listing, bulk actions, and visibility filtering for `rss_news` CPT.
 */
class ImportedNewsAdmin {

	/**
	 * Register actions and filters.
	 *
	 * @return void
	 */
	public function init() {
		// Custom Columns hooks.
		add_filter( 'manage_rss_news_posts_columns', [ $this, 'set_custom_columns' ] );
		add_action( 'manage_rss_news_posts_custom_column', [ $this, 'fill_custom_columns' ], 10, 2 );

		// Bulk Actions.
		add_filter( 'bulk_actions-edit-rss_news', [ $this, 'register_bulk_actions' ] );
		add_filter( 'handle_bulk_actions-edit-rss_news', [ $this, 'handle_bulk_actions' ], 10, 3 );
		add_action( 'admin_notices', [ $this, 'display_bulk_notices' ] );

		// Handle single row actions.
		add_action( 'admin_init', [ $this, 'handle_row_actions' ] );

		// Handle image repair action.
		add_action( 'admin_init', [ $this, 'repair_missing_images' ] );
		add_action( 'admin_notices', [ $this, 'display_repair_images_notice' ] );

		// Filter frontend queries to exclude disabled news.
		add_action( 'pre_get_posts', [ $this, 'filter_frontend_news' ] );
	}

	/**
	 * Define custom column headers.
	 *
	 * @param array $columns Default columns.
	 * @return array Modified columns.
	 */
	public function set_custom_columns( $columns ) {
		$new_columns = [];
		$new_columns['cb']         = $columns['cb']; // Keep checkbox.
		$new_columns['thumbnail']  = __( 'Thumbnail', 'rss-feed-manager' );
		$new_columns['title']      = $columns['title']; // Keep title.
		$new_columns['feed']       = __( 'Feed Source', 'rss-feed-manager' );
		$new_columns['import_date'] = __( 'Imported Date', 'rss-feed-manager' );
		$new_columns['status']     = __( 'Status', 'rss-feed-manager' );
		$new_columns['visibility'] = __( 'Frontend Visibility', 'rss-feed-manager' );
		$new_columns['actions']    = __( 'Actions', 'rss-feed-manager' );
		return $new_columns;
	}

	/**
	 * Render custom column cell values.
	 *
	 * @param string $column  Column identifier.
	 * @param int    $post_id The post ID.
	 * @return void
	 */
	public function fill_custom_columns( $column, $post_id ) {
		switch ( $column ) {
			case 'thumbnail':
				if ( has_post_thumbnail( $post_id ) ) {
					echo get_the_post_thumbnail( $post_id, [ 50, 50 ], [ 'style' => 'object-fit:cover; border-radius:4px;' ] );
				} else {
					$feed_image = get_post_meta( $post_id, '_feed_image', true );
					if ( ! empty( $feed_image ) ) {
						echo '<img src="' . esc_url( $feed_image ) . '" width="50" height="50" style="object-fit:cover; border-radius:4px;" />';
					} else {
						$settings    = get_option( 'rss_feed_manager_settings' );
						$placeholder = isset( $settings['default_image'] ) ? $settings['default_image'] : '';
						if ( ! empty( $placeholder ) ) {
							echo '<img src="' . esc_url( $placeholder ) . '" width="50" height="50" style="object-fit:cover; border-radius:4px;" />';
						} else {
							echo '<span class="dashicons dashicons-format-image" style="font-size:36px; width:36px; height:36px; color:#cbd5e1; margin-top:6px;"></span>';
						}
					}
				}
				break;

			case 'feed':
				$source_id = get_post_meta( $post_id, '_source_feed', true );
				if ( $source_id ) {
					$model  = new SourceModel();
					$source = $model->get_by_id( $source_id );
					if ( $source ) {
						echo '<strong>' . esc_html( $source->feed_name ) . '</strong>';
					} else {
						echo '<span style="color:#64748b; font-style:italic;">' . esc_html__( 'Unknown Feed', 'rss-feed-manager' ) . '</span>';
					}
				} else {
					echo '-';
				}
				break;

			case 'import_date':
				$sync_time = get_post_meta( $post_id, '_sync_time', true );
				echo esc_html(
					$sync_time ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $sync_time ) ) : '-'
				);
				break;

			case 'status':
				$post_status = get_post_status( $post_id );
				if ( 'publish' === $post_status ) {
					echo '<span class="rfm-badge rfm-badge-active" style="background-color: var(--success-light); color: var(--success);">' . esc_html__( 'Published', 'rss-feed-manager' ) . '</span>';
				} else {
					echo '<span class="rfm-badge" style="background-color: #f1f5f9; color: #475569;">' . esc_html( ucfirst( $post_status ) ) . '</span>';
				}
				break;

			case 'visibility':
				$visibility = get_post_meta( $post_id, '_import_status', true );
				if ( 'disabled' === $visibility ) {
					echo '<span class="rfm-badge rfm-badge-inactive">' . esc_html__( 'Disabled', 'rss-feed-manager' ) . '</span>';
				} else {
					echo '<span class="rfm-badge rfm-badge-active">' . esc_html__( 'Enabled', 'rss-feed-manager' ) . '</span>';
				}
				break;

			case 'actions':
				$visibility    = get_post_meta( $post_id, '_import_status', true );
				$toggle_action = 'disabled' === $visibility ? 'enable' : 'disable';
				$toggle_label  = 'disabled' === $visibility ? __( 'Enable Visibility', 'rss-feed-manager' ) : __( 'Disable Visibility', 'rss-feed-manager' );
				$toggle_class  = 'disabled' === $visibility ? 'rfm-action-toggle-active' : 'rfm-action-toggle-inactive';

				$toggle_url = wp_nonce_url(
					admin_url( 'edit.php?post_type=rss_news&action=toggle_news_status&post_id=' . $post_id . '&status=' . $toggle_action ),
					'rss_toggle_' . $post_id
				);

				$edit_url   = get_edit_post_link( $post_id );
				$view_url   = get_permalink( $post_id );
				$delete_url = get_delete_post_link( $post_id, '', true ); // Force permanent bypass trash.

				echo '<div class="rfm-table-actions">';
				echo '<a href="' . esc_url( $view_url ) . '" class="rfm-btn rfm-btn-secondary" style="padding:4px 8px;" target="_blank" title="' . esc_attr__( 'View Item', 'rss-feed-manager' ) . '"><span class="dashicons dashicons-visibility"></span></a>';
				echo '<a href="' . esc_url( $edit_url ) . '" class="rfm-action-edit rfm-btn" style="padding:4px 8px;" title="' . esc_attr__( 'Edit Item', 'rss-feed-manager' ) . '"><span class="dashicons dashicons-edit"></span></a>';
				echo '<a href="' . esc_url( $toggle_url ) . '" class="' . esc_attr( $toggle_class ) . ' rfm-btn" style="padding:4px 8px;" title="' . esc_attr( $toggle_label ) . '"><span class="dashicons ' . ( 'disabled' === $visibility ? 'dashicons-yes' : 'dashicons-no' ) . '"></span></a>';
				echo '<a href="' . esc_url( $delete_url ) . '" class="rfm-action-delete rfm-btn" style="padding:4px 8px;" onclick="return confirm(\'' . esc_js( __( 'Are you sure you want to permanently delete this post?', 'rss-feed-manager' ) ) . '\');" title="' . esc_attr__( 'Delete Permanently', 'rss-feed-manager' ) . '"><span class="dashicons dashicons-trash"></span></a>';
				echo '</div>';
				break;
		}
	}

	/**
	 * Register Enable / Disable in Bulk Actions.
	 *
	 * @param array $bulk_actions Native bulk actions.
	 * @return array Modified actions.
	 */
	public function register_bulk_actions( $bulk_actions ) {
		$bulk_actions['rss_bulk_enable']  = __( 'Enable Frontend Visibility', 'rss-feed-manager' );
		$bulk_actions['rss_bulk_disable'] = __( 'Disable Frontend Visibility', 'rss-feed-manager' );
		return $bulk_actions;
	}

	/**
	 * Handle bulk actions updates.
	 *
	 * @param string $redirect_to Target redirect URL.
	 * @param string $action      Action being run.
	 * @param array  $post_ids    Array of checked post IDs.
	 * @return string Redirect URL with notification tags.
	 */
	public function handle_bulk_actions( $redirect_to, $action, $post_ids ) {
		if ( ! in_array( $action, [ 'rss_bulk_enable', 'rss_bulk_disable' ], true ) ) {
			return $redirect_to;
		}

		$status = 'rss_bulk_enable' === $action ? 'enabled' : 'disabled';
		$count  = 0;

		foreach ( $post_ids as $post_id ) {
			if ( current_user_can( 'edit_post', $post_id ) ) {
				update_post_meta( $post_id, '_import_status', $status );
				$count++;
			}
		}

		return add_query_arg(
			[
				'rss_bulk_updated' => $count,
				'rss_bulk_action'  => $status,
			],
			$redirect_to
		);
	}

	/**
	 * Display notices upon bulk actions run.
	 *
	 * @return void
	 */
	public function display_bulk_notices() {
		global $pagenow;
		if ( 'edit.php' !== $pagenow || ! isset( $_GET['post_type'] ) || 'rss_news' !== $_GET['post_type'] ) {
			return;
		}

		if ( isset( $_GET['rss_bulk_updated'] ) ) {
			$count  = absint( $_GET['rss_bulk_updated'] );
			$action = sanitize_text_field( $_GET['rss_bulk_action'] );
			
			$message = 'enabled' === $action
				? sprintf( _n( '%d post successfully enabled on the frontend.', '%d posts successfully enabled on the frontend.', $count, 'rss-feed-manager' ), $count )
				: sprintf( _n( '%d post successfully disabled on the frontend.', '%d posts successfully disabled on the frontend.', $count, 'rss-feed-manager' ), $count );

			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		}
	}

	/**
	 * Intercept row actions toggling status.
	 *
	 * @return void
	 */
	public function handle_row_actions() {
		global $pagenow;
		if ( 'edit.php' !== $pagenow || ! isset( $_GET['post_type'] ) || 'rss_news' !== $_GET['post_type'] ) {
			return;
		}

		$action  = isset( $_GET['action'] ) ? sanitize_text_field( $_GET['action'] ) : '';
		$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;

		if ( 'toggle_news_status' === $action && $post_id > 0 ) {
			if ( isset( $_GET['_wpnonce'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'rss_toggle_' . $post_id ) ) {
				if ( current_user_can( 'edit_post', $post_id ) ) {
					$status = isset( $_GET['status'] ) && 'enable' === $_GET['status'] ? 'enabled' : 'disabled';
					update_post_meta( $post_id, '_import_status', $status );

					// Redirect to prevent duplicate hits.
					$referer = wp_get_referer();
					wp_safe_redirect( $referer ? $referer : admin_url( 'edit.php?post_type=rss_news' ) );
					exit;
				}
			}
		}
	}

	/**
	 * Query modifier: Filters out "disabled" post items from frontend lists.
	 *
	 * @param \WP_Query $query Query being compiled.
	 * @return void
	 */
	public function filter_frontend_news( $query ) {
		// Only filter frontend queries, and only main query.
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$is_news = false;
		$post_type = $query->get( 'post_type' );

		if ( 'rss_news' === $post_type || is_post_type_archive( 'rss_news' ) ) {
			$is_news = true;
		}

		if ( $is_news ) {
			$meta_query = $query->get( 'meta_query' );
			if ( ! is_array( $meta_query ) ) {
				$meta_query = [];
			}

			// Include logic: not equal to 'disabled' OR does not exist (fallback).
			$meta_query[] = [
				'relation' => 'OR',
				[
					'key'     => '_import_status',
					'value'   => 'disabled',
					'compare' => '!=',
				],
				[
					'key'     => '_import_status',
					'compare' => 'NOT EXISTS',
				],
			];

			$query->set( 'meta_query', $meta_query );
		}
	}

	/**
	 * Scan existing news posts that are missing images, fetch their feed items, and repair metadata/thumbnails.
	 *
	 * @return void
	 */
	public function repair_missing_images() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! isset( $_GET['repair_images'] ) || '1' !== $_GET['repair_images'] ) {
			return;
		}

		$posts = get_posts(
			[
				'post_type'   => 'rss_news',
				'numberposts' => -1,
				'post_status' => 'any',
			]
		);

		if ( empty( $posts ) ) {
			return;
		}

		$model = new SourceModel();

		if ( ! function_exists( 'fetch_feed' ) ) {
			require_once ABSPATH . WPINC . '/class-feed.php';
		}

		$repaired = 0;

		foreach ( $posts as $post ) {
			$post_id    = $post->ID;
			$thumbnail  = get_post_thumbnail_id( $post_id );
			$feed_image = get_post_meta( $post_id, '_feed_image', true );

			// Skip if thumbnail already exists or a valid remote image URL is already in meta.
			if ( $thumbnail || ( ! empty( $feed_image ) && 0 === strpos( $feed_image, 'http' ) ) ) {
				continue;
			}

			// Get the source feed mapping ID.
			$source_id = get_post_meta( $post_id, '_source_feed', true );
			if ( ! $source_id ) {
				continue;
			}

			$source = $model->get_by_id( $source_id );
			if ( ! $source ) {
				continue;
			}

			// Fetch feed XML to find matching title.
			$feed = fetch_feed( $source->feed_url );
			if ( is_wp_error( $feed ) ) {
				continue;
			}

			$items = $feed->get_items();
			foreach ( $items as $item ) {
				if ( sanitize_text_field( $item->get_title() ) === $post->post_title ) {
					// Match found.
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

					// Fallback 3: parse <img> tag from content/description.
					if ( empty( $image_url ) ) {
						$html = $item->get_content();
						if ( empty( $html ) ) {
							$html = $item->get_description();
						}
						if ( ! empty( $html ) ) {
							$decoded_html = html_entity_decode( $html, ENT_QUOTES, 'UTF-8' );
							if ( preg_match( '/<img[^>]+src=[\'"]([^\'"]+)[\'"]/i', $decoded_html, $matches ) ) {
								$image_url = $matches[1];
							}
						}
					}

					if ( ! empty( $image_url ) && 0 === strpos( $image_url, 'http' ) ) {
						update_post_meta( $post_id, '_feed_image', $image_url );
						$repaired++;

						// Sideload.
						$settings = get_option( 'rss_feed_manager_settings' );
						if ( isset( $settings['enable_image_download'] ) && '1' === $settings['enable_image_download'] ) {
							require_once ABSPATH . 'wp-admin/includes/image.php';
							require_once ABSPATH . 'wp-admin/includes/file.php';
							require_once ABSPATH . 'wp-admin/includes/media.php';

							$attachment_id = media_sideload_image( $image_url, $post_id, get_the_title( $post_id ), 'id' );

							if ( ! is_wp_error( $attachment_id ) && $attachment_id > 0 ) {
								set_post_thumbnail( $post_id, $attachment_id );
								update_post_meta( $post_id, '_feed_image', wp_get_attachment_url( $attachment_id ) );
							}
						}
					}
					break;
				}
			}
		}

		// Redirect with query argument to show notice.
		wp_safe_redirect(
			add_query_arg(
				[
					'repaired_count' => $repaired,
				],
				remove_query_arg( [ 'repair_images' ], wp_get_referer() ? wp_get_referer() : admin_url( 'edit.php?post_type=rss_news' ) )
			)
		);
		exit;
	}

	/**
	 * Display admin notices for repairing images.
	 *
	 * @return void
	 */
	public function display_repair_images_notice() {
		global $pagenow;
		if ( 'edit.php' !== $pagenow || ! isset( $_GET['post_type'] ) || 'rss_news' !== $_GET['post_type'] ) {
			return;
		}

		// If repair was just executed.
		if ( isset( $_GET['repaired_count'] ) ) {
			$count = absint( $_GET['repaired_count'] );
			$msg   = sprintf( _n( '%d post image successfully synchronized and fixed.', '%d post images successfully synchronized and fixed.', $count, 'rss-feed-manager' ), $count );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
			return;
		}

		// Count posts missing images.
		$posts = get_posts(
			[
				'post_type'   => 'rss_news',
				'numberposts' => -1,
				'post_status' => 'any',
			]
		);

		$missing = 0;
		foreach ( $posts as $post ) {
			$post_id    = $post->ID;
			$thumbnail  = get_post_thumbnail_id( $post_id );
			$feed_image = get_post_meta( $post_id, '_feed_image', true );

			if ( ! $thumbnail && ( empty( $feed_image ) || 0 !== strpos( $feed_image, 'http' ) ) ) {
				$missing++;
			}
		}

		if ( $missing > 0 ) {
			$repair_url = add_query_arg( 'repair_images', '1', admin_url( 'edit.php?post_type=rss_news' ) );
			?>
			<div class="notice notice-warning is-dismissible">
				<p>
					<strong><?php esc_html_e( 'RSS Feed Manager:', 'rss-feed-manager' ); ?></strong>
					<?php
					echo esc_html(
						sprintf(
							_n(
								'Found %d imported post that is missing its feed image thumbnail due to previous configuration.',
								'Found %d imported posts that are missing their feed image thumbnails due to previous configuration.',
								$missing,
								'rss-feed-manager'
							),
							$missing
						)
					);
					?>
					<a href="<?php echo esc_url( $repair_url ); ?>" class="button button-secondary" style="margin-left: 10px; vertical-align: middle;">
						<?php esc_html_e( 'Synchronize and Fix Images Now', 'rss-feed-manager' ); ?>
					</a>
				</p>
			</div>
			<?php
		}
	}
}
