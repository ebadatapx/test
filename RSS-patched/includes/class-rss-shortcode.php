<?php
/**
 * Shortcode handler for [rss_news].
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
 * Class Shortcode
 *
 * Registers shortcode `[rss_news]` and queries/renders news posts.
 */
class Shortcode {

	/**
	 * Register actions and filters.
	 *
	 * @return void
	 */
	public function init() {
		add_shortcode( 'rss_news', [ $this, 'render' ] );
	}

	/**
	 * Renders shortcode news list.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string Rendered HTML.
	 */
	public function render( $atts ) {
		$settings      = get_option( 'rss_feed_manager_settings' );
		$default_limit = isset( $settings['posts_per_page'] ) ? absint( $settings['posts_per_page'] ) : 10;

		$a = shortcode_atts(
			[
				'limit'   => $default_limit,
				'source'  => '',
				'orderby' => 'date',
				'order'   => 'DESC',
			],
			$atts
		);

		// Build base query arguments.
		$args = [
			'post_type'      => 'post',
			'posts_per_page' => absint( $a['limit'] ),
			'orderby'        => sanitize_key( $a['orderby'] ),
			'order'          => sanitize_key( $a['order'] ),
			'post_status'    => 'publish',
			'no_found_rows'  => true,
		];

		// Ensure frontend visibility constraint.
		$meta_query = [
			'relation' => 'AND',
			// Restrict to feed-imported posts. Without this the grid lists every
			// published blog post on the site, because the visibility clause below
			// also matches posts that carry no _import_status meta at all.
			[
				'key'     => '_source_feed',
				'compare' => 'EXISTS',
			],
			[
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
			],
		];

		// Filter by feed source ID or feed name if provided.
		if ( ! empty( $a['source'] ) ) {
			if ( is_numeric( $a['source'] ) ) {
				$meta_query[] = [
					'key'   => '_source_feed',
					'value' => absint( $a['source'] ),
				];
			} else {
				// Query source feed by name to resolve ID.
				global $wpdb;
				$table   = $wpdb->prefix . 'rss_sources';
				$feed_id = $wpdb->get_var(
					$wpdb->prepare( "SELECT id FROM {$table} WHERE feed_name = %s LIMIT 1", sanitize_text_field( $a['source'] ) )
				);

				if ( $feed_id ) {
					$meta_query[] = [
						'key'   => '_source_feed',
						'value' => absint( $feed_id ),
					];
				} else {
					// Fallback to prevent returning unrelated feeds if source not found.
					$args['post__in'] = [ 0 ];
				}
			}
		}

		$args['meta_query'] = $meta_query;

		$query = new \WP_Query( $args );

		// Load Frontend Assets.
		wp_enqueue_style( 'rss-feed-manager-frontend-css' );
		wp_enqueue_style( 'dashicons' ); // Enqueue dashicons for arrow icon.

		if ( ! $query->have_posts() ) {
			return '<p class="rfm-no-news">' . esc_html__( 'No news items found.', 'rss-feed-manager' ) . '</p>';
		}

		ob_start();
		?>
		<div class="rfm-news-grid">
			<?php
			while ( $query->have_posts() ) :
				$query->the_post();
				$post_id       = get_the_ID();
				$original_link = get_post_meta( $post_id, '_original_link', true );
				// post_date is now stored correctly (with a matching post_date_gmt),
				// so use it directly instead of re-interpreting the meta string.
				$display_date  = get_the_date();

				// Resolve image URL.
				$image_url = '';
				if ( has_post_thumbnail() ) {
					$image_url = get_the_post_thumbnail_url( $post_id, 'medium_large' );
				} else {
					$image_url = get_post_meta( $post_id, '_feed_image', true );
				}

				if ( empty( $image_url ) ) {
					$image_url = $settings['default_image'] ?? '';
				}
				// Determine target link (local post permalink).
				$target_link = get_permalink();
				$link_target = '';
				?>
				<article class="rfm-news-card">
					<?php if ( ! empty( $image_url ) ) : ?>
						<div class="rfm-card-image">
							<a href="<?php echo esc_url( $target_link ); ?>"<?php echo $link_target; ?>>
								<img src="<?php echo esc_url( $image_url ); ?>" alt="<?php the_title_attribute(); ?>" loading="lazy" />
							</a>
						</div>
					<?php endif; ?>
					<div class="rfm-card-content">
						<div class="rfm-card-meta">
							<span class="rfm-card-date"><?php echo esc_html( $display_date ); ?></span>
							<?php
							$source_id = get_post_meta( $post_id, '_source_feed', true );
							if ( $source_id ) {
								$model  = new SourceModel();
								$source = $model->get_by_id( $source_id );
								if ( $source ) {
									echo '<span class="rfm-card-source">' . esc_html( $source->feed_name ) . '</span>';
								}
							}
							?>
						</div>
						<h3 class="rfm-card-title">
							<a href="<?php echo esc_url( $target_link ); ?>"<?php echo $link_target; ?>><?php the_title(); ?></a>
						</h3>
						<div class="rfm-card-excerpt">
							<?php echo wp_kses_post( wp_trim_words( get_the_excerpt(), 25 ) ); ?>
						</div>
						<div class="rfm-card-footer">
							<a href="<?php echo esc_url( $target_link ); ?>" class="rfm-readmore-btn"<?php echo $link_target; ?>>
								<?php esc_html_e( 'Read Full Article', 'rss-feed-manager' ); ?>
								<span class="dashicons dashicons-arrow-right-alt"></span>
							</a>
						</div>
					</div>
				</article>
				<?php
			endwhile;
			wp_reset_postdata();
			?>
		</div>
		<?php
		return ob_get_clean();
	}
}
