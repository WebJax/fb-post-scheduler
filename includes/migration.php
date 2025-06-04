<?php
/**
 * Migrationshjælper for Facebook Post Scheduler
 * 
 * Migrerer data fra custom post type til database tabel
 */

// Hvis denne fil kaldes direkte, så afbryd
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Vis migration notice i admin
 */
function fb_post_scheduler_show_migration_notice() {
    // Tjek om migrering allerede er kørt
    if (get_option('fb_post_scheduler_migration_complete')) {
        return;
    }
    
    // Tæl antallet af posts der skal migreres
    $posts_count = count(get_posts(array(
        'post_type' => 'fb_scheduled_post',
        'posts_per_page' => -1,
        'fields' => 'ids'
    )));
    
    if ($posts_count === 0) {
        // Ingen posts at migrere, marker som færdig
        update_option('fb_post_scheduler_migration_complete', true);
        return;
    }
    
    ?>
    <div class="notice notice-warning">
        <p>
            <strong><?php _e('Facebook Post Scheduler migrering påkrævet', 'fb-post-scheduler'); ?></strong>
        </p>
        <p>
            <?php printf(
                __('Der er %d planlagte Facebook opslag der skal migreres fra det gamle format til det nye for at vises korrekt i kalenderen.', 'fb-post-scheduler'),
                $posts_count
            ); ?>
        </p>
        <p>
            <a href="<?php echo admin_url('admin.php?page=fb-post-scheduler&run_migration=true&_wpnonce=' . wp_create_nonce('fb-post-scheduler-migration')); ?>" class="button button-primary">
                <?php _e('Kør migrering nu', 'fb-post-scheduler'); ?>
            </a>
        </p>
    </div>
    <?php
}
add_action('admin_notices', 'fb_post_scheduler_show_migration_notice');

/**
 * Håndter migrations handling
 */
function fb_post_scheduler_handle_migration() {
    // Tjek om migrering allerede er kørt
    if (get_option('fb_post_scheduler_migration_complete')) {
        return;
    }
    
    if (!isset($_GET['run_migration']) || $_GET['run_migration'] !== 'true') {
        return;
    }
    
    // Tjek nonce
    if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'fb-post-scheduler-migration')) {
        wp_die(__('Sikkerhedstjek fejlede. Prøv igen.', 'fb-post-scheduler'));
    }
    
    // Tjek brugerrettigheder
    if (!current_user_can('manage_options')) {
        wp_die(__('Du har ikke tilstrækkelige rettigheder til at udføre denne handling.', 'fb-post-scheduler'));
    }
    
    // Kør migrering
    require_once FB_POST_SCHEDULER_PATH . 'includes/db-helper.php';
    $results = fb_post_scheduler_migrate_from_post_type();
    
    // Marker migrering som fuldført
    update_option('fb_post_scheduler_migration_complete', true);
    
    // Omdiriger til admin med besked
    wp_redirect(admin_url('admin.php?page=fb-post-scheduler&migration_complete=true&success=' . $results['success'] . '&failed=' . $results['failed']));
    exit;
}
add_action('admin_init', 'fb_post_scheduler_handle_migration');

/**
 * Vis migrations success besked
 */
function fb_post_scheduler_migration_success_notice() {
    if (!isset($_GET['migration_complete']) || $_GET['migration_complete'] !== 'true') {
        return;
    }
    
    $success = isset($_GET['success']) ? intval($_GET['success']) : 0;
    $failed = isset($_GET['failed']) ? intval($_GET['failed']) : 0;
    
    ?>
    <div class="notice notice-success is-dismissible">
        <p>
            <strong><?php _e('Facebook Post Scheduler migrering gennemført', 'fb-post-scheduler'); ?></strong>
        </p>
        <p>
            <?php printf(
                __('Migreringen er fuldført. %d opslag blev migreret med succes, %d fejlede.', 'fb-post-scheduler'),
                $success, $failed
            ); ?>
        </p>
    </div>
    <?php
}
add_action('admin_notices', 'fb_post_scheduler_migration_success_notice');
