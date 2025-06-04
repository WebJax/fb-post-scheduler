<?php
/**
 * Export Functionality for Facebook Post Scheduler
 * 
 * Giver mulighed for at eksportere planlagte Facebook-opslag til CSV
 */

// Hvis denne fil kaldes direkte, så afbryd
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Tilføj export side
 */
function fb_post_scheduler_register_export_page() {
    add_submenu_page(
        'fb-post-scheduler',
        __('Eksporter Opslag', 'fb-post-scheduler'),
        __('Eksporter', 'fb-post-scheduler'),
        'manage_options',
        'fb-post-scheduler-export',
        'fb_post_scheduler_export_page_content'
    );
}
add_action('admin_menu', 'fb_post_scheduler_register_export_page', 20);

/**
 * Indhold til export siden
 */
function fb_post_scheduler_export_page_content() {
    // Tjek for eksport anmodning FØRST, før HTML output
    if (isset($_POST['export_fb_posts']) && check_admin_referer('fb_post_scheduler_export', 'fb_post_scheduler_export_nonce')) {
        fb_post_scheduler_export_posts();
        return; // Stop execution after export
    }
    
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        
        <div class="card">
            <h2><?php _e('Eksporter Facebook-opslag', 'fb-post-scheduler'); ?></h2>
            <p><?php _e('Brug denne side til at eksportere dine planlagte Facebook-opslag til en CSV-fil.', 'fb-post-scheduler'); ?></p>
            
            <form method="post" action="">
                <?php wp_nonce_field('fb_post_scheduler_export', 'fb_post_scheduler_export_nonce'); ?>
                
                <h3><?php _e('Vælg dato interval', 'fb-post-scheduler'); ?></h3>
                <p>
                    <label for="start_date"><?php _e('Fra dato:', 'fb-post-scheduler'); ?></label>
                    <input type="date" id="start_date" name="start_date" value="<?php echo date('Y-m-d'); ?>" class="regular-text">
                </p>
                
                <p>
                    <label for="end_date"><?php _e('Til dato:', 'fb-post-scheduler'); ?></label>
                    <input type="date" id="end_date" name="end_date" value="<?php echo date('Y-m-d', strtotime('+1 month')); ?>" class="regular-text">
                </p>
                
                <h3><?php _e('Vælg status', 'fb-post-scheduler'); ?></h3>
                <p>
                    <label>
                        <input type="checkbox" name="status[]" value="scheduled" checked> 
                        <?php _e('Planlagte opslag', 'fb-post-scheduler'); ?>
                    </label>
                </p>
                <p>
                    <label>
                        <input type="checkbox" name="status[]" value="posted" checked> 
                        <?php _e('Udførte opslag', 'fb-post-scheduler'); ?>
                    </label>
                </p>
                
                <p>
                    <input type="submit" name="export_fb_posts" class="button button-primary" value="<?php _e('Eksporter til CSV', 'fb-post-scheduler'); ?>">
                </p>
            </form>
        </div>
        
        <div class="card">
            <h2><?php _e('Logfil', 'fb-post-scheduler'); ?></h2>
            <p><?php _e('Se en logfil over alle Facebook-opslag forsøg.', 'fb-post-scheduler'); ?></p>
            
            <p>
                <a href="<?php echo admin_url('admin.php?page=fb-post-scheduler-logs'); ?>" class="button"><?php _e('Se logfil', 'fb-post-scheduler'); ?></a>
            </p>
        </div>
    </div>
    <?php
}

/**
 * Eksporter poster til CSV
 */
function fb_post_scheduler_export_posts() {
    // Rens output buffer for at sikre ren CSV
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    // Få alle valgte post types
    $selected_post_types = get_option('fb_post_scheduler_post_types', array());
    
    if (empty($selected_post_types)) {
        wp_die(__('Ingen post types er valgt til Facebook-opslag. Gå til FB Opslag > Indstillinger for at vælge post types.', 'fb-post-scheduler'));
    }
    
    // Få dato interval
    $start_date = isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) . ' 00:00:00' : date('Y-m-d 00:00:00');
    $end_date = isset($_POST['end_date']) ? sanitize_text_field($_POST['end_date']) . ' 23:59:59' : date('Y-m-d 23:59:59', strtotime('+1 month'));
    
    // Få status filter
    $status = isset($_POST['status']) ? $_POST['status'] : array('scheduled', 'posted');
    
    // Få planlagte opslag fra databasen
    global $wpdb;
    $table_name = $wpdb->prefix . 'fb_scheduled_posts';
    
    // Byg status filter til SQL
    $status_filter = '';
    if (!empty($status)) {
        $status_placeholders = implode(',', array_fill(0, count($status), '%s'));
        $status_filter = "AND status IN ($status_placeholders)";
    }
    
    // Byg post type filter til SQL
    $post_type_placeholders = implode(',', array_fill(0, count($selected_post_types), '%s'));
    
    // Forbered SQL query
    $sql = "SELECT sp.*, p.post_title, p.post_type 
            FROM $table_name sp
            INNER JOIN {$wpdb->posts} p ON sp.post_id = p.ID
            WHERE sp.scheduled_time BETWEEN %s AND %s
            AND p.post_type IN ($post_type_placeholders)
            $status_filter
            ORDER BY sp.scheduled_time ASC";
    
    // Forbered parametre til query
    $params = array($start_date, $end_date);
    $params = array_merge($params, $selected_post_types);
    if (!empty($status)) {
        $params = array_merge($params, $status);
    }
    
    $scheduled_posts = $wpdb->get_results($wpdb->prepare($sql, $params));
    
    if (empty($scheduled_posts)) {
        wp_die(__('Ingen Facebook-opslag fundet i det valgte tidsrum.', 'fb-post-scheduler'));
    }
    
    // Disable WordPress output buffering og sæt CSV headers
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Forhindre WordPress headers
    nocache_headers();
    
    // Indstil CSV headers
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=facebook-posts-' . date('Y-m-d') . '.csv');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Opret output stream
    $output = fopen('php://output', 'w');
    
    // Tilføj BOM for UTF-8 encoding
    fputs($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
    
    // Tilføj header række
    fputcsv($output, array(
        __('ID', 'fb-post-scheduler'),
        __('Titel', 'fb-post-scheduler'),
        __('Type', 'fb-post-scheduler'),
        __('Planlagt til', 'fb-post-scheduler'),
        __('Status', 'fb-post-scheduler'),
        __('Facebook ID', 'fb-post-scheduler'),
        __('Facebook-tekst', 'fb-post-scheduler'),
        __('Link', 'fb-post-scheduler')
    ));
    
    // Tilføj data rækker
    foreach ($scheduled_posts as $scheduled_post) {
        // Få WordPress post data
        $wp_post = get_post($scheduled_post->post_id);
        if (!$wp_post) {
            continue; // Skip hvis post ikke findes
        }
        
        // Få post type information
        $post_type_obj = get_post_type_object($wp_post->post_type);
        $post_type_name = $post_type_obj ? $post_type_obj->labels->singular_name : $wp_post->post_type;
        
        // Determine status text
        $status_text = ($scheduled_post->status === 'posted') ? __('Postet', 'fb-post-scheduler') : __('Planlagt', 'fb-post-scheduler');
        
        // Tilføj række til CSV
        fputcsv($output, array(
            $scheduled_post->post_id,
            $wp_post->post_title,
            $post_type_name,
            date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($scheduled_post->scheduled_time)),
            $status_text,
            $scheduled_post->fb_post_id ? $scheduled_post->fb_post_id : '',
            $scheduled_post->message,
            get_permalink($scheduled_post->post_id)
        ));
    }
    
    // Afslut og slut PHP
    fclose($output);
    exit;
}

/**
 * Tilføj logs side
 */
function fb_post_scheduler_register_logs_page() {
    add_submenu_page(
        'fb-post-scheduler',
        __('Facebook Opslag Logfil', 'fb-post-scheduler'),
        __('Logfil', 'fb-post-scheduler'),
        'manage_options',
        'fb-post-scheduler-logs',
        'fb_post_scheduler_logs_page_content'
    );
}
add_action('admin_menu', 'fb_post_scheduler_register_logs_page', 30);

/**
 * Indhold til logs siden
 */
function fb_post_scheduler_logs_page_content() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'fb_post_scheduler_logs';
    
    // Få alle logs
    $logs = $wpdb->get_results("SELECT * FROM $table_name ORDER BY created_at DESC LIMIT 100");
    
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        
        <?php if ($logs) : ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php _e('Dato', 'fb-post-scheduler'); ?></th>
                    <th><?php _e('Indlæg', 'fb-post-scheduler'); ?></th>
                    <th><?php _e('Facebook ID', 'fb-post-scheduler'); ?></th>
                    <th><?php _e('Status', 'fb-post-scheduler'); ?></th>
                    <th><?php _e('Besked', 'fb-post-scheduler'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log) : ?>
                <tr>
                    <td><?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($log->created_at)); ?></td>
                    <td>
                        <?php 
                        $post_title = get_the_title($log->post_id);
                        if ($post_title) {
                            echo '<a href="' . get_edit_post_link($log->post_id) . '">' . $post_title . '</a>';
                        } else {
                            echo __('Indlæg ikke fundet', 'fb-post-scheduler') . ' (ID: ' . $log->post_id . ')';
                        }
                        ?>
                    </td>
                    <td><?php echo $log->fb_post_id ? $log->fb_post_id : '-'; ?></td>
                    <td>
                        <?php if ($log->status === 'success') : ?>
                            <span class="fb-log-success"><?php _e('Success', 'fb-post-scheduler'); ?></span>
                        <?php else : ?>
                            <span class="fb-log-error"><?php _e('Fejl', 'fb-post-scheduler'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html($log->message); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else : ?>
        <p><?php _e('Ingen logdata fundet.', 'fb-post-scheduler'); ?></p>
        <?php endif; ?>
    </div>
    <?php
}
