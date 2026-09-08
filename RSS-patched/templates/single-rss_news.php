<?php
/**
 * Single template for rss_news post type.
 *
 * @package           RSSFeedManager
 * @author            Senior WordPress Developer
 * @copyright         2026 RSS Feed Manager
 * @license           GPL-2.0-or-later
 */

get_header();

// Fetch settings.
$settings = get_option( 'rss_feed_manager_settings' );

// Load Frontend CSS & Dashicons.
wp_enqueue_style( 'rss-feed-manager-frontend-css' );
wp_enqueue_style( 'dashicons' );

while ( have_posts() ) :
	the_post();
	$post_id       = get_the_ID();
	$original_link = get_post_meta( $post_id, '_original_link', true );
	$feed_date     = get_post_meta( $post_id, '_feed_date', true );
	$display_date  = $feed_date ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $feed_date ) ) : get_the_date();
	$feed_author   = get_post_meta( $post_id, '_feed_author', true );
	
	if ( empty( $feed_author ) ) {
		$feed_author = get_the_author();
	}

	// Resolve image.
	$image_url = '';
	if ( has_post_thumbnail() ) {
		$image_url = get_the_post_thumbnail_url( $post_id, 'large' );
	} else {
		$image_url = get_post_meta( $post_id, '_feed_image', true );
	}
	
	if ( empty( $image_url ) ) {
		$image_url = $settings['default_image'] ?? '';
	}
	?>
	<div class="rfm-single-news-wrap">
		<article id="post-<?php the_ID(); ?>" <?php post_class( 'rfm-single-article' ); ?>>
			<?php if ( ! empty( $image_url ) ) : ?>
				<div class="rfm-single-hero" style="background-image: linear-gradient(180deg, rgba(0,0,0,0.1) 0%, rgba(0,0,0,0.7) 100%), url('<?php echo esc_url( $image_url ); ?>');">
					<div class="rfm-single-hero-content">
						<?php
						$source_id = get_post_meta( $post_id, '_source_feed', true );
						if ( $source_id ) {
							$model  = new \RSSFeedManager\SourceModel();
							$source = $model->get_by_id( $source_id );
							if ( $source ) {
								echo '<span class="rfm-single-source">' . esc_html( $source->feed_name ) . '</span>';
							}
						}
						?>
						<h1 class="rfm-single-title"><?php the_title(); ?></h1>
						<div class="rfm-single-meta">
							<span class="rfm-meta-item"><span class="dashicons dashicons-calendar"></span> <?php echo esc_html( $display_date ); ?></span>
							<?php if ( ! empty( $feed_author ) ) : ?>
								<span class="rfm-meta-item"><span class="dashicons dashicons-admin-users"></span> <?php echo esc_html( $feed_author ); ?></span>
							<?php endif; ?>
						</div>
					</div>
				</div>
			<?php else : ?>
				<div class="rfm-single-header-no-hero">
					<?php
					$source_id = get_post_meta( $post_id, '_source_feed', true );
					if ( $source_id ) {
						$model  = new \RSSFeedManager\SourceModel();
						$source = $model->get_by_id( $source_id );
						if ( $source ) {
							echo '<span class="rfm-single-source">' . esc_html( $source->feed_name ) . '</span>';
						}
					}
					?>
					<h1 class="rfm-single-title-no-hero"><?php the_title(); ?></h1>
					<div class="rfm-single-meta-no-hero">
						<span class="rfm-meta-item"><span class="dashicons dashicons-calendar"></span> <?php echo esc_html( $display_date ); ?></span>
						<?php if ( ! empty( $feed_author ) ) : ?>
							<span class="rfm-meta-item"><span class="dashicons dashicons-admin-users"></span> <?php echo esc_html( $feed_author ); ?></span>
						<?php endif; ?>
					</div>
				</div>
			<?php endif; ?>

			<div class="rfm-single-content">
				<?php the_content(); ?>

				<?php if ( ! empty( $original_link ) ) : ?>
					<div class="rfm-single-source-action">
						<a href="<?php echo esc_url( $original_link ); ?>" class="rfm-source-btn" target="_blank" rel="noopener noreferrer">
							<span class="dashicons dashicons-external"></span>
							<?php esc_html_e( 'Read Original Article', 'rss-feed-manager' ); ?>
						</a>
					</div>
				<?php endif; ?>
			</div>
		</article>
	</div>
	<?php
endwhile;

get_footer();
