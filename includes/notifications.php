<?php
/**
 * Notifications for Facebook Post Scheduler
 * 
 * Håndterer notifikationer til administrator om Facebook-opslag status
 */

// Hvis denne fil kaldes direkte, så afbryd
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Klasse til håndtering af notifikationer
 */
class FB_Post_Scheduler_Notifications {
    
    /**
     * Konstruktør
     */
    public function __construct() {
        // Tilføj notifikationer til admin bar
        add_action('admin_bar_menu', array($this, 'add_admin_bar_notifications'), 100);
        
        // Administrer notifikationer
        add_action('admin_init', array($this, 'check_for_new_notifications'));
        
        // AJAX-handlers
        add_action('wp_ajax_fb_post_scheduler_mark_notification_read', array($this, 'mark_notification_read'));
        add_action('wp_ajax_fb_post_scheduler_mark_all_notifications_read', array($this, 'mark_all_notifications_read'));
        
        // Tilføj scripts og styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
    }
    
    /**
     * Tilføj notifikationer til admin bar
     */
    public function add_admin_bar_notifications($wp_admin_bar) {
        if (!current_user_can('edit_posts')) {
            return;
        }
        
        $notifications = $this->get_notifications();
        $unread_count = $this->get_unread_count($notifications);
        
        // Opret parent node
        $wp_admin_bar->add_node(array(
            'id' => 'fb-post-scheduler-notifications',
            'title' => $unread_count > 0 
                ? sprintf('<span class="fb-notification-count">%d</span> <span class="screen-reader-text">%s</span>', 
                    $unread_count, 
                    __('Nye Facebook-opslagsnotifikationer', 'fb-post-scheduler')
                )
                : '<span class="fb-notification-icon"></span>',
            'href' => '#',
            'meta' => array(
                'class' => 'fb-post-scheduler-notifications-menu',
            ),
        ));
        
        // Tilføj undermenu
        if (!empty($notifications)) {
            foreach ($notifications as $index => $notification) {
                $wp_admin_bar->add_node(array(
                    'id' => 'fb-post-scheduler-notification-' . $index,
                    'parent' => 'fb-post-scheduler-notifications',
                    'title' => sprintf(
                        '<div class="fb-notification %s">
                            <span class="fb-notification-title">%s</span>
                            <span class="fb-notification-time">%s</span>
                            <span class="fb-notification-message">%s</span>
                            <a href="#" class="fb-mark-read" data-id="%d">%s</a>
                        </div>',
                        $notification->read ? 'read' : 'unread',
                        esc_html($notification->title),
                        esc_html(human_time_diff(strtotime($notification->time), current_time('timestamp'))) . ' ' . __('siden', 'fb-post-scheduler'),
                        esc_html($notification->message),
                        $notification->id,
                        __('Markér som læst', 'fb-post-scheduler')
                    ),
                    'href' => isset($notification->link) ? $notification->link : '#',
                ));
            }
        } else {
            // Ingen notifikationer
            $wp_admin_bar->add_node(array(
                'id' => 'fb-post-scheduler-notification-none',
                'parent' => 'fb-post-scheduler-notifications',
                'title' => __('Ingen notifikationer', 'fb-post-scheduler'),
            ));
        }
        
        // Tilføj se alle link
        $wp_admin_bar->add_node(array(
            'id' => 'fb-post-scheduler-notifications-see-all',
            'parent' => 'fb-post-scheduler-notifications',
            'title' => __('Se alle Facebook-opslag', 'fb-post-scheduler'),
            'href' => admin_url('admin.php?page=fb-post-scheduler'),
            'meta' => array(
                'class' => 'fb-notifications-see-all',
            ),
        ));
        
        // Tilføj markér alle som læst
        if ($unread_count > 0) {
            $wp_admin_bar->add_node(array(
                'id' => 'fb-post-scheduler-notifications-mark-all',
                'parent' => 'fb-post-scheduler-notifications',
                'title' => __('Markér alle som læst', 'fb-post-scheduler'),
                'href' => '#',
                'meta' => array(
                    'class' => 'fb-notifications-mark-all',
                ),
            ));
        }
    }
    
    /**
     * Tilføj scripts og styles til admin
     */
    public function enqueue_scripts() {
        // Tilføj styles specifikt til notifikationer
        wp_add_inline_style('fb-post-scheduler-admin-css', $this->get_notification_styles());
        
        // Registrér notifikationsscript
        wp_enqueue_script(
            'fb-post-scheduler-notifications-js',
            FB_POST_SCHEDULER_URL . 'assets/js/notifications.js',
            array('jquery'),
            FB_POST_SCHEDULER_VERSION,
            true
        );
        
        // Lokalisér script
        wp_localize_script(
            'fb-post-scheduler-notifications-js',
            'fbPostSchedulerNotifications',
            array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('fb-post-scheduler-notifications-nonce'),
            )
        );
    }
    
    /**
     * CSS styles til notifikationer
     */
    private function get_notification_styles() {
        return '
            .fb-notification-count {
                display: inline-block;
                min-width: 18px;
                height: 18px;
                background-color: #ca4a1f;
                border-radius: 9px;
                font-size: 11px;
                line-height: 1.6;
                text-align: center;
                color: #fff;
                padding: 0 4px;
                margin: 0 2px 0 0;
            }
            
            .fb-notification-icon:before {
                content: "\f488";
                font-family: dashicons;
                font-size: 20px;
                color: #a0a5aa;
            }
            
            .fb-post-scheduler-notifications-menu .ab-sub-wrapper {
                min-width: 300px;
            }
            
            .fb-notification {
                padding: 8px 10px;
                border-bottom: 1px solid #eee;
                position: relative;
            }
            
            .fb-notification.unread {
                background-color: #f0f6fc;
            }
            
            .fb-notification-title {
                display: block;
                font-weight: 600;
                margin-bottom: 3px;
            }
            
            .fb-notification-time {
                font-size: 11px;
                color: #72777c;
                margin-bottom: 5px;
                display: block;
            }
            
            .fb-notification-message {
                display: block;
                margin-bottom: 5px;
            }
            
            .fb-mark-read {
                font-size: 11px;
                color: #0073aa;
                text-decoration: none;
            }
            
            .fb-notifications-mark-all {
                border-top: 1px solid #eee;
                text-align: center;
                display: block;
                padding: 5px 0;
            }
            
            .fb-notifications-see-all {
                text-align: center;
                display: block;
                padding: 5px 0;
                border-top: 1px solid #eee;
            }
        ';
    }
    
    /**
     * AJAX-handler for at markere en notifikation som læst
     */
    public function mark_notification_read() {
        // Tjek nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'fb-post-scheduler-notifications-nonce')) {
            wp_send_json_error('Ugyldig nonce');
        }
        
        if (!isset($_POST['id'])) {
            wp_send_json_error('Manglende ID');
        }
        
        $id = intval($_POST['id']);
        
        $notifications = $this->get_notifications();
        
        foreach ($notifications as $key => $notification) {
            if ($notification->id === $id) {
                $notifications[$key]->read = true;
                break;
            }
        }
        
        update_option('fb_post_scheduler_notifications', $notifications);
        
        wp_send_json_success();
    }
    
    /**
     * AJAX-handler for at markere alle notifikationer som læst
     */
    public function mark_all_notifications_read() {
        // Tjek nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'fb-post-scheduler-notifications-nonce')) {
            wp_send_json_error('Ugyldig nonce');
        }
        
        $notifications = $this->get_notifications();
        
        foreach ($notifications as $key => $notification) {
            $notifications[$key]->read = true;
        }
        
        update_option('fb_post_scheduler_notifications', $notifications);
        
        wp_send_json_success();
    }
    
    /**
     * Få alle notifikationer
     */
    public function get_notifications() {
        $notifications = get_option('fb_post_scheduler_notifications', array());
        
        // Sortér efter tid (nyeste først)
        usort($notifications, function($a, $b) {
            return strtotime($b->time) - strtotime($a->time);
        });
        
        // Begræns til de 10 nyeste
        if (count($notifications) > 10) {
            $notifications = array_slice($notifications, 0, 10);
        }
        
        return $notifications;
    }
    
    /**
     * Få antal ulæste notifikationer
     */
    private function get_unread_count($notifications) {
        $count = 0;
        
        foreach ($notifications as $notification) {
            if (!$notification->read) {
                $count++;
            }
        }
        
        return $count;
    }
    
    /**
     * Tjek for nye notifikationer fra logfilen
     */
    public function check_for_new_notifications() {
        global $wpdb;
        
        // Få sidste notifikationstid
        $last_check = get_option('fb_post_scheduler_last_notification_check', 0);
        $now = time();
        
        // Tjek ikke oftere end hvert 5. minut
        if ($now - $last_check < 300) {
            return;
        }
        
        // Opdater sidste tjek
        update_option('fb_post_scheduler_last_notification_check', $now);
        
        // Få nye log-indlæg siden sidste tjek
        $table_name = $wpdb->prefix . 'fb_post_scheduler_logs';
        $date = date('Y-m-d H:i:s', $last_check);
        
        $logs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table_name WHERE created_at > %s ORDER BY created_at DESC",
                $date
            )
        );
        
        if (empty($logs)) {
            return;
        }
        
        // Få eksisterende notifikationer
        $notifications = get_option('fb_post_scheduler_notifications', array());
        
        // Tilføj nye notifikationer
        foreach ($logs as $log) {
            $post_title = get_the_title($log->post_id);
            
            if (empty($post_title)) {
                $post_title = __('Indlæg', 'fb-post-scheduler') . ' #' . $log->post_id;
            }
            
            $notification = (object) array(
                'id' => $log->id,
                'time' => $log->created_at,
                'read' => false,
                'title' => $log->status === 'success' 
                    ? sprintf(__('Opslag postet til Facebook: %s', 'fb-post-scheduler'), $post_title)
                    : sprintf(__('Fejl ved post til Facebook: %s', 'fb-post-scheduler'), $post_title),
                'message' => $log->message,
                'link' => admin_url('post.php?post=' . $log->post_id . '&action=edit'),
            );
            
            // Tjek om notifikationen allerede findes
            $exists = false;
            foreach ($notifications as $existing) {
                if ($existing->id === $notification->id) {
                    $exists = true;
                    break;
                }
            }
            
            if (!$exists) {
                $notifications[] = $notification;
            }
        }
        
        // Gem notifikationerne
        update_option('fb_post_scheduler_notifications', $notifications);
    }
    
    /**
     * Tilføj en ny notifikation
     */
    public function add_notification($title, $message, $link = '', $context = array()) {
        $notifications = get_option('fb_post_scheduler_notifications', array());
        
        $notification = (object) array(
            'id' => time() . rand(100, 999),
            'time' => current_time('mysql'),
            'read' => false,
            'title' => $title,
            'message' => $message,
            'link' => $link,
            'context' => $context,
        );
        
        $notifications[] = $notification;
        
        // Sorter efter tid
        usort($notifications, function($a, $b) {
            return strtotime($b->time) - strtotime($a->time);
        });
        
        // Begræns til de 50 nyeste
        if (count($notifications) > 50) {
            $notifications = array_slice($notifications, 0, 50);
        }
        
        update_option('fb_post_scheduler_notifications', $notifications);
    }
}

// Instantiér notifikationsklassen
$fb_post_scheduler_notifications = new FB_Post_Scheduler_Notifications();

/**
 * Helper funktion til at tilføje notifikationer
 */
function fb_post_scheduler_add_notification($title, $message, $link = '', $context = array()) {
    global $fb_post_scheduler_notifications;
    
    if ($fb_post_scheduler_notifications) {
        $fb_post_scheduler_notifications->add_notification($title, $message, $link, $context);
    }
}
