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
    
    // Få alle poster med planlagte Facebook-opslag
    $args = array(
        'post_type' => $selected_post_types,
        'posts_per_page' => 5,
        'meta_query' => array(
            'relation' => 'AND',
            array(
                'key' => '_fb_post_enabled',
                'value' => '1',
                'compare' => '='
            ),
            array(
                'key' => '_fb_post_date',
                'value' => date('Y-m-d H:i:s'),
                'compare' => '>=',
                'type' => 'DATETIME'
            )
        ),
        'tax_query' => array(
            array(
                'taxonomy' => 'fb_post_status',
                'field' => 'slug',
                'terms' => 'posted',
                'operator' => 'NOT IN'
            )
        ),
        'orderby' => 'meta_value',
        'meta_key' => '_fb_post_date',
        'order' => 'ASC'
    );
    
    $posts_with_fb = new WP_Query($args);
    
    if ($posts_with_fb->have_posts()) :
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
                <?php while ($posts_with_fb->have_posts()) : $posts_with_fb->the_post(); ?>
                    <tr>
                        <td>
                            <?php the_title(); ?>
                            <div class="row-actions">
                                <span class="edit">
                                    <a href="<?php echo get_edit_post_link(); ?>"><?php _e('Rediger', 'fb-post-scheduler'); ?></a>
                                </span>
                            </div>
                        </td>
                        <td>
                            <?php 
                            $post_date = get_post_meta(get_the_ID(), '_fb_post_date', true);
                            echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($post_date));
                            ?>
                        </td>
                        <td>
                            <a href="<?php echo admin_url('post.php?post=' . get_the_ID() . '&action=edit'); ?>" class="button button-small"><?php _e('Rediger', 'fb-post-scheduler'); ?></a>
                        </td>
                    </tr>
                <?php endwhile; ?>
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
