<?php
/**
 * Log Viewer for Facebook Post Scheduler
 * 
 * Erstatter export funktionalitet med en log viewer
 */

// Hvis denne fil kaldes direkte, så afbryd
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Vis logs side
 */
function fb_post_scheduler_logs_page() {
    // Tjek bruger tilladelser
    if (!current_user_can('manage_options')) {
        wp_die(__('Du har ikke tilladelse til at få adgang til denne side.'));
    }
    
    // Håndter log cleanup
    if (isset($_POST['cleanup_logs']) && wp_verify_nonce($_POST['cleanup_nonce'], 'cleanup_logs')) {
        $days = intval($_POST['cleanup_days']);
        if ($days > 0) {
            $deleted = fb_post_scheduler_cleanup_old_logs($days);
            echo '<div class="notice notice-success"><p>' . sprintf(__('%d gamle log entries blev slettet.', 'fb-post-scheduler'), $deleted) . '</p></div>';
        }
    }
    
    // Hent log entries
    $filter_status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
    $logs = fb_post_scheduler_get_logs_for_viewer($filter_status);
    
    ?>
    <div class="wrap">
        <h1><?php _e('Facebook Post Scheduler - Logs', 'fb-post-scheduler'); ?></h1>
        
        <div class="nav-tab-wrapper">
            <a href="?page=fb-post-scheduler-logs" class="nav-tab <?php echo empty($filter_status) ? 'nav-tab-active' : ''; ?>">
                <?php _e('Alle Logs', 'fb-post-scheduler'); ?>
            </a>
            <a href="?page=fb-post-scheduler-logs&status=success" class="nav-tab <?php echo $filter_status === 'success' ? 'nav-tab-active' : ''; ?>">
                <?php _e('Success', 'fb-post-scheduler'); ?>
            </a>
            <a href="?page=fb-post-scheduler-logs&status=error" class="nav-tab <?php echo $filter_status === 'error' ? 'nav-tab-active' : ''; ?>">
                <?php _e('Fejl', 'fb-post-scheduler'); ?>
            </a>
            <a href="?page=fb-post-scheduler-logs&status=warning" class="nav-tab <?php echo $filter_status === 'warning' ? 'nav-tab-active' : ''; ?>">
                <?php _e('Advarsler', 'fb-post-scheduler'); ?>
            </a>
            <a href="?page=fb-post-scheduler-logs&status=info" class="nav-tab <?php echo $filter_status === 'info' ? 'nav-tab-active' : ''; ?>">
                <?php _e('Info', 'fb-post-scheduler'); ?>
            </a>
        </div>
        
        <div style="margin-top: 20px;">
            <form method="post" style="float: right;">
                <?php wp_nonce_field('cleanup_logs', 'cleanup_nonce'); ?>
                <label for="cleanup_days"><?php _e('Slet logs ældre end:', 'fb-post-scheduler'); ?></label>
                <input type="number" id="cleanup_days" name="cleanup_days" value="30" min="1" max="365" style="width: 60px;">
                <span><?php _e('dage', 'fb-post-scheduler'); ?></span>
                <input type="submit" name="cleanup_logs" class="button" value="<?php _e('Ryd op', 'fb-post-scheduler'); ?>" 
                       onclick="return confirm('<?php _e('Er du sikker på at du vil slette gamle logs?', 'fb-post-scheduler'); ?>');">
            </form>
            <div style="clear: both;"></div>
        </div>
        
        <?php if (empty($logs)): ?>
            <div class="notice notice-info">
                <p><?php _e('Ingen log entries fundet.', 'fb-post-scheduler'); ?></p>
            </div>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 120px;"><?php _e('Dato/Tid', 'fb-post-scheduler'); ?></th>
                        <th style="width: 80px;"><?php _e('Status', 'fb-post-scheduler'); ?></th>
                        <th style="width: 100px;"><?php _e('Post ID', 'fb-post-scheduler'); ?></th>
                        <th style="width: 120px;"><?php _e('Facebook Post ID', 'fb-post-scheduler'); ?></th>
                        <th><?php _e('Besked', 'fb-post-scheduler'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?php echo esc_html(date('d/m/Y H:i', strtotime($log->created_at))); ?></td>
                            <td>
                                <?php
                                $status_class = 'notice-info';
                                $status_icon = '📋';
                                
                                switch ($log->status) {
                                    case 'success':
                                        $status_class = 'notice-success';
                                        $status_icon = '✅';
                                        break;
                                    case 'error':
                                        $status_class = 'notice-error';
                                        $status_icon = '❌';
                                        break;
                                    case 'warning':
                                        $status_class = 'notice-warning';
                                        $status_icon = '⚠️';
                                        break;
                                    case 'info':
                                        $status_class = 'notice-info';
                                        $status_icon = '📋';
                                        break;
                                }
                                ?>
                                <span class="<?php echo esc_attr($status_class); ?>" style="padding: 2px 8px; border-radius: 3px; display: inline-block;">
                                    <?php echo $status_icon; ?> <?php echo esc_html(ucfirst($log->status)); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($log->post_id): ?>
                                    <a href="<?php echo esc_url(get_edit_post_link($log->post_id)); ?>" target="_blank">
                                        #<?php echo esc_html($log->post_id); ?>
                                    </a>
                                <?php else: ?>
                                    <span style="color: #666;">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($log->fb_post_id): ?>
                                    <code style="font-size: 11px;"><?php echo esc_html($log->fb_post_id); ?></code>
                                <?php else: ?>
                                    <span style="color: #666;">-</span>
                                <?php endif; ?>
                            </td>
                            <td style="word-break: break-word;">
                                <?php echo esc_html($log->message); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <p style="margin-top: 20px; color: #666; font-style: italic;">
                <?php printf(__('Total: %d log entries', 'fb-post-scheduler'), count($logs)); ?>
            </p>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Hent logs fra databasen til log viewer
 */
function fb_post_scheduler_get_logs_for_viewer($status_filter = '') {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'fb_post_scheduler_logs';
    
    $query = "SELECT * FROM $table_name";
    $params = array();
    
    if (!empty($status_filter)) {
        $query .= " WHERE status = %s";
        $params[] = $status_filter;
    }
    
    $query .= " ORDER BY created_at DESC LIMIT 1000";
    
    if (!empty($params)) {
        return $wpdb->get_results($wpdb->prepare($query, $params));
    } else {
        return $wpdb->get_results($query);
    }
}

/**
 * Ryd gamle logs op
 */
function fb_post_scheduler_cleanup_old_logs($days = 30) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'fb_post_scheduler_logs';
    $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$days} days"));
    
    return $wpdb->query($wpdb->prepare(
        "DELETE FROM $table_name WHERE created_at < %s",
        $cutoff_date
    ));
}
