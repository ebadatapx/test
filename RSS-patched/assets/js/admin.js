/**
 * Admin JavaScript for RSS Feed Manager
 */
jQuery(document).ready(function($) {
    // Handle AJAX Feed Sync
    $('.rfm-action-sync').on('click', function(e) {
        e.preventDefault();
        
        var $btn = $(this);
        var feedId = $btn.data('id');
        var $row = $btn.closest('tr');
        var $syncIcon = $btn.find('span.dashicons');
        
        // Prevent multiple clicks
        if ($btn.hasClass('disabled')) {
            return;
        }
        
        $btn.addClass('disabled');
        $syncIcon.addClass('rfm-syncing');
        
        // Remove previous notifications
        $('.rfm-notice').remove();
        
        $.ajax({
            url: rssFeedManagerAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'rss_feed_manager_sync_feed',
                feed_id: feedId,
                nonce: rssFeedManagerAdmin.nonce
            },
            success: function(response) {
                $btn.removeClass('disabled');
                $syncIcon.removeClass('rfm-syncing');
                
                if (response.success) {
                    // Update sync time and count in row
                    if (response.data.last_sync) {
                        $row.find('.rfm-col-sync').text(response.data.last_sync);
                    }
                    // Show the running total for this source, not just this run's
                    // import count (which is 0 whenever there was nothing new).
                    if (response.data.total_posts !== undefined) {
                        $row.find('.rfm-col-posts').text(response.data.total_posts);
                    }
                    
                    // Show success notice
                    var message = response.data.message || 'Feed sync completed successfully.';
                    var notice = $('<div class="rfm-notice rfm-notice-success"><p>' + message + '</p></div>');
                    $('.rfm-admin-wrap > h2').after(notice);
                } else {
                    // Show error notice
                    var errorMsg = response.data.message || 'An unknown error occurred during sync.';
                    var notice = $('<div class="rfm-notice rfm-notice-error"><p><strong>Error:</strong> ' + errorMsg + '</p></div>');
                    $('.rfm-admin-wrap > h2').after(notice);
                }
            },
            error: function() {
                $btn.removeClass('disabled');
                $syncIcon.removeClass('rfm-syncing');
                
                var notice = $('<div class="rfm-notice rfm-notice-error"><p><strong>Error:</strong> Failed to connect to server. Please try again.</p></div>');
                $('.rfm-admin-wrap > h2').after(notice);
            }
        });
    });
});
