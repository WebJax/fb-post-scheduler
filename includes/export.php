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
    
    // Eksporter hvis der er en anmodning
    if (isset($_POST['export_fb_posts']) && check_admin_referer('fb_post_scheduler_export', 'fb_post_scheduler_export_nonce')) {
        fb_post_scheduler_export_posts();
    }
}

/**
 * Eksporter poster til CSV
 */
function fb_post_scheduler_export_posts() {
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
    
    // Opbyg query args
    $args = array(
        'post_type' => $selected_post_types,
        'posts_per_page' => -1,
        'meta_query' => array(
            'relation' => 'AND',
            array(
                'key' => '_fb_post_enabled',
                'value' => '1',
                'compare' => '='
            ),
            array(
                'key' => '_fb_post_date',
                'value' => array($start_date, $end_date),
                'compare' => 'BETWEEN',
                'type' => 'DATETIME'
            )
        ),
        'orderby' => 'meta_value',
        'meta_key' => '_fb_post_date',
        'order' => 'ASC'
    );
    
    // Filter på status
    if (!empty($status) && !in_array('posted', $status)) {
        $args['tax_query'] = array(
            array(
                'taxonomy' => 'fb_post_status',
                'field' => 'slug',
                'terms' => 'posted',
                'operator' => 'NOT IN'
            )
        );
    } elseif (!empty($status) && !in_array('scheduled', $status)) {
        $args['tax_query'] = array(
            array(
                'taxonomy' => 'fb_post_status',
                'field' => 'slug',
                'terms' => 'posted',
                'operator' => 'IN'
            )
        );
    }
    
    $posts = new WP_Query($args);
    
    if (!$posts->have_posts()) {
        wp_die(__('Ingen Facebook-opslag fundet i det valgte tidsrum.', 'fb-post-scheduler'));
    }
    
    // Indstil header headers
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=facebook-posts-' . date('Y-m-d') . '.csv');
    
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
    while ($posts->have_posts()) {
        $posts->the_post();
        $post_id = get_the_ID();
        
        // Få meta data
        $fb_post_date = get_post_meta($post_id, '_fb_post_date', true);
        $fb_post_text = get_post_meta($post_id, '_fb_post_text', true);
        $fb_post_id = get_post_meta($post_id, '_fb_post_id', true);
        
        // Tjek status
        $has_term = has_term('posted', 'fb_post_status', $post_id);
        $status_text = $has_term ? __('Postet', 'fb-post-scheduler') : __('Planlagt', 'fb-post-scheduler');
        
        // Tilføj række til CSV
        fputcsv($output, array(
            $post_id,
            get_the_title(),
            get_post_type_object(get_post_type())->labels->singular_name,
            date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($fb_post_date)),
            $status_text,
            $fb_post_id,
            $fb_post_text,
            get_permalink()
        ));
    }
    
    wp_reset_postdata();
    
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
