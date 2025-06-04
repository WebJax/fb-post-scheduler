<?php
/**
 * Dashboard Widget for Facebook Post Scheduler
 * 
 * Viser planlagte Facebook-opslag i WordPress dashboard
 */

// Hvis denne fil kaldes direkte, så afbryd
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Tilføj dashboard widget
 */
function fb_post_scheduler_add_dashboard_widget() {
    wp_add_dashboard_widget(
        'fb_post_scheduler_dashboard_widget',
        __('Kommende Facebook Opslag', 'fb-post-scheduler'),
        'fb_post_scheduler_dashboard_widget_content'
    );
}
add_action('wp_dashboard_setup', 'fb_post_scheduler_add_dashboard_widget');

/**
 * Dashboard widget indhold
 */
function fb_post_scheduler_dashboard_widget_content() {
    // Få alle post types der er valgt til Facebook-opslag
    $selected_post_types = get_option('fb_post_scheduler_post_types', array());
    
    if (empty($selected_post_types)) {
        echo '<p>' . __('Ingen post types er valgt til Facebook-opslag. Gå til FB Opslag > Indstillinger for at vælge post types.', 'fb-post-scheduler') . '</p>';
        return;
    }
    
    // Få alle planlagte opslag fra databasen
    global $wpdb;
    $table_name = $wpdb->prefix . 'fb_scheduled_posts';
    $now = current_time('mysql');
    
    $scheduled_posts = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table_name 
        WHERE scheduled_time >= %s AND status = 'scheduled'
        AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('".implode("','", $selected_post_types)."'))
        ORDER BY scheduled_time ASC
        LIMIT 5",
        $now
    ));
    
    if (!empty($scheduled_posts)) :
        ?>
        <table class="widefat fb-posts-table">
            <thead>
                <tr>
                    <th><?php _e('Titel', 'fb-post-scheduler'); ?></th>
                    <th><?php _e('Planlagt til', 'fb-post-scheduler'); ?></th>
                    <th><?php _e('Handlinger', 'fb-post-scheduler'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($scheduled_posts as $post) : ?>
                    <tr>
                        <td>
                            <?php echo esc_html($post->post_title); ?>
                            <div class="row-actions">
                                <span class="edit">
                                    <a href="<?php echo get_edit_post_link($post->post_id); ?>"><?php _e('Rediger', 'fb-post-scheduler'); ?></a>
                                </span>
                            </div>
                        </td>
                        <td>
                            <?php 
                            echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($post->scheduled_time));
                            ?>
                        </td>
                        <td>
                            <a href="<?php echo admin_url('post.php?post=' . $post->post_id . '&action=edit'); ?>" class="button button-small"><?php _e('Rediger', 'fb-post-scheduler'); ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="textright">
            <a href="<?php echo admin_url('admin.php?page=fb-post-scheduler'); ?>" class="button button-primary"><?php _e('Vis alle opslag', 'fb-post-scheduler'); ?></a>
        </p>
        <?php
        wp_reset_postdata();
    else :
        ?>
        <p class="no-posts"><?php _e('Ingen planlagte Facebook-opslag fundet.', 'fb-post-scheduler'); ?></p>
        <p class="textright">
            <a href="<?php echo admin_url('admin.php?page=fb-post-scheduler-settings'); ?>" class="button"><?php _e('Indstillinger', 'fb-post-scheduler'); ?></a>
        </p>
        <?php
    endif;
}
