/**
 * Facebook Post Scheduler Notifications Scripts
 */
(function($) {
    'use strict';
    
    // Når dokumentet er klar
    $(document).ready(function() {
        // Håndtér klik på "Markér som læst"
        $(document).on('click', '.fb-mark-read', function(e) {
            e.preventDefault();
            
            var $this = $(this);
            var id = $this.data('id');
            
            // Send AJAX-anmodning
            $.ajax({
                url: fbPostSchedulerNotifications.ajaxurl,
                type: 'POST',
                data: {
                    action: 'fb_post_scheduler_mark_notification_read',
                    nonce: fbPostSchedulerNotifications.nonce,
                    id: id
                },
                success: function(response) {
                    if (response.success) {
                        // Opdater UI
                        $this.closest('.fb-notification').removeClass('unread').addClass('read');
                        
                        // Opdater tæller
                        updateNotificationCount();
                    }
                }
            });
        });
        
        // Håndtér klik på "Markér alle som læst"
        $(document).on('click', '.fb-notifications-mark-all', function(e) {
            e.preventDefault();
            
            // Send AJAX-anmodning
            $.ajax({
                url: fbPostSchedulerNotifications.ajaxurl,
                type: 'POST',
                data: {
                    action: 'fb_post_scheduler_mark_all_notifications_read',
                    nonce: fbPostSchedulerNotifications.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Opdater UI
                        $('.fb-notification').removeClass('unread').addClass('read');
                        
                        // Fjern tæller
                        $('#wp-admin-bar-fb-post-scheduler-notifications > a').html('<span class="fb-notification-icon"></span>');
                        
                        // Fjern "Markér alle som læst" knappen
                        $('#wp-admin-bar-fb-post-scheduler-notifications-mark-all').remove();
                    }
                }
            });
        });
        
        // Funktionalitet for at opdatere notifikationstæller
        function updateNotificationCount() {
            var unreadCount = $('.fb-notification.unread').length;
            
            if (unreadCount > 0) {
                $('#wp-admin-bar-fb-post-scheduler-notifications > a').html(
                    '<span class="fb-notification-count">' + unreadCount + '</span> ' +
                    '<span class="screen-reader-text">Nye Facebook-opslagsnotifikationer</span>'
                );
            } else {
                $('#wp-admin-bar-fb-post-scheduler-notifications > a').html('<span class="fb-notification-icon"></span>');
                $('#wp-admin-bar-fb-post-scheduler-notifications-mark-all').remove();
            }
        }
    });
    
})(jQuery);
