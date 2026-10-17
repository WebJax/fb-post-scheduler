<?php
/**
 * Plugin Name: Facebook Post Scheduler
 * Plugin URI: https://jaxweb.dk/fb-post-scheduler
 * Description: Planlæg og administrer Facebook-opslag direkte fra WordPress med automatisk link til indholdet, AI-tekst generering, og avanceret administration
 * Version: 1.2.7
 * Author: Jacob Thygesen
 * Author URI: https://jaxweb.dk
 * License: GPL2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: fb-post-scheduler
 * Domain Path: /languages
 */

// Hvis denne fil kaldes direkte, så afbryd
if (!defined('ABSPATH')) {
    exit;
}

// Definér konstanter
define('FB_POST_SCHEDULER_PATH', plugin_dir_path(__FILE__));
define('FB_POST_SCHEDULER_URL', plugin_dir_url(__FILE__));
define('FB_POST_SCHEDULER_VERSION', '1.2.7');

// Inkluder nødvendige filer
require_once FB_POST_SCHEDULER_PATH . 'includes/ajax-handlers.php';
require_once FB_POST_SCHEDULER_PATH . 'includes/api-helper.php';
require_once FB_POST_SCHEDULER_PATH . 'includes/db-helper.php'; 
require_once FB_POST_SCHEDULER_PATH . 'includes/dashboard-widget.php';
require_once FB_POST_SCHEDULER_PATH . 'includes/export.php';
require_once FB_POST_SCHEDULER_PATH . 'includes/notifications.php';
require_once FB_POST_SCHEDULER_PATH . 'includes/ai-helper.php';
require_once FB_POST_SCHEDULER_PATH . 'includes/migration.php';
require_once FB_POST_SCHEDULER_PATH . 'includes/open-graph.php';

// Registrer aktivering og deaktivering hooks
register_activation_hook(__FILE__, 'fb_post_scheduler_activate');
register_deactivation_hook(__FILE__, 'fb_post_scheduler_deactivate');

/**
 * Plugin aktivering
 */
function fb_post_scheduler_activate() {
    // Tilføj cron job ved aktivering
    if (!wp_next_scheduled('fb_post_scheduler_check_posts')) {
        wp_schedule_event(time(), 'hourly', 'fb_post_scheduler_check_posts');
    }
    
    // Opret log-mappe hvis den ikke eksisterer
    $log_dir = FB_POST_SCHEDULER_PATH . 'logs';
    if (!file_exists($log_dir)) {
        mkdir($log_dir, 0755, true);
    }
    
    // Log aktivering
    fb_post_scheduler_log('Plugin aktiveret');
    
    // Opret database-tabeller
    global $wpdb;
    
    $charset_collate = $wpdb->get_charset_collate();
    
    // Logs tabel
    $logs_table_name = $wpdb->prefix . 'fb_post_scheduler_logs';
    
    $logs_sql = "CREATE TABLE $logs_table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        post_id bigint(20) NOT NULL,
        fb_post_id varchar(255) NOT NULL,
        status varchar(50) NOT NULL,
        message text NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";
    
    // Scheduled posts tabel
    $scheduled_table_name = $wpdb->prefix . 'fb_scheduled_posts';
    
    $scheduled_sql = "CREATE TABLE $scheduled_table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        post_id bigint(20) NOT NULL,
        post_title text NOT NULL,
        message text NOT NULL,
        fb_post_id varchar(255) DEFAULT '',
        image_id bigint(20) DEFAULT 0,
        status varchar(50) DEFAULT 'scheduled',
        post_index int(11) DEFAULT 0,
        scheduled_time datetime NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY post_id_index (post_id, post_index),
        KEY status (status),
        KEY scheduled_time (scheduled_time)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($logs_sql);
    dbDelta($scheduled_sql);
    
    // Kør schema migration hvis nødvendigt
    fb_post_scheduler_migrate_schema();
}

/**
 * Migrer database schema til den seneste version
 * Tilføjer UNIQUE constraint på (post_id, post_index) for at forhindre duplikater
 */
function fb_post_scheduler_migrate_schema() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'fb_scheduled_posts';
    
    // Tjek om UNIQUE constraint allerede eksisterer
    $constraint_exists = $wpdb->get_results($wpdb->prepare(
        "SHOW INDEX FROM $table_name WHERE Key_name = %s",
        'post_id_index'
    ));
    
    if (!empty($constraint_exists)) {
        // Constraint eksisterer allerede
        return;
    }
    
    // Før vi tilføjer UNIQUE constraint, skal vi fjerne eventuelle duplikater
    // Behold kun den ældste post for hver (post_id, post_index) kombination
    $duplicates_removed = $wpdb->query(
        "DELETE t1 FROM $table_name t1
         INNER JOIN $table_name t2
         WHERE t1.post_id = t2.post_id
           AND t1.post_index = t2.post_index
           AND t1.id > t2.id"
    );
    
    if ($duplicates_removed !== false && $duplicates_removed > 0) {
        fb_post_scheduler_log('Schema migration: Fjernede ' . $duplicates_removed . ' duplikerede posts før UNIQUE constraint');
    }
    
    // Tilføj UNIQUE constraint
    $result = $wpdb->query(
        "ALTER TABLE $table_name
         ADD UNIQUE KEY post_id_index (post_id, post_index)"
    );
    
    if ($result === false) {
        // Log fejl hvis ALTER TABLE fejler
        fb_post_scheduler_log('FEJL ved schema migration: Kunne ikke tilføje UNIQUE constraint. Database fejl: ' . $wpdb->last_error);
    } else {
        fb_post_scheduler_log('Schema migration gennemført: UNIQUE constraint tilføjet til (post_id, post_index)');
    }
}

/**
 * Plugin deaktivering
 */
function fb_post_scheduler_deactivate() {
    // Fjern cron job ved deaktivering
    $timestamp = wp_next_scheduled('fb_post_scheduler_check_posts');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'fb_post_scheduler_check_posts');
    }
    
    // Log deaktivering
    fb_post_scheduler_log('Plugin deaktiveret');
}

/**
 * Log funktion
 */
function fb_post_scheduler_log(string $message, $post_id = null) {
    $log_file = FB_POST_SCHEDULER_PATH . 'logs/fb-post-scheduler.log';
    $timestamp = date('Y-m-d H:i:s');
    $post_info = $post_id ? " [Post ID: $post_id]" : "";
    
    // Formater logbesked
    $log_message = "[$timestamp]$post_info $message" . PHP_EOL;
    
    // Skriv til logfil
    file_put_contents($log_file, $log_message, FILE_APPEND);
}

/**
 * Hovedklasse for plugin
 */
class FB_Post_Scheduler {
    
    /**
     * Instance af klassen.
     */
    private static ?self $instance = null;
    
    /**
     * Valgte post types.
     */
    private array $selected_post_types = array();
    
    /**
     * Constructor
     */
    private function __construct() {
        // Indlæs gemte indstillinger
        $this->selected_post_types = get_option('fb_post_scheduler_post_types', array());
        
        // Hooks
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
        add_action('save_post', array($this, 'save_meta_box_data'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // Force meta box position for Gutenberg
        add_action('do_meta_boxes', array($this, 'force_meta_box_position'), 10, 3);
        
        // Additional hook for Gutenberg meta box positioning
        add_filter('postbox_classes_post_fb_post_scheduler_meta_box', array($this, 'add_meta_box_classes'));
        add_filter('postbox_classes_page_fb_post_scheduler_meta_box', array($this, 'add_meta_box_classes'));
        
        // WP-Cron hook
        add_action('fb_post_scheduler_check_posts', array($this, 'check_scheduled_posts'));
        
        // Manual process hook
        add_action('admin_init', array($this, 'process_manual_post_check'));
        add_action('admin_init', array($this, 'process_delete_revision_schedules'));
        add_action('admin_init', array($this, 'process_bulk_delete_list_rows'));
        
        // Handle post duplication - clear FB post data on duplicated posts
        add_action('dp_duplicate_post', array($this, 'clear_fb_posts_on_duplicate'), 10, 2);
        add_action('dp_duplicate_page', array($this, 'clear_fb_posts_on_duplicate'), 10, 2);
        
        // Check for token expiration warnings
        add_action('admin_notices', array($this, 'check_token_expiration_notice'));
        
        // Add Facebook share count columns to post lists
        $this->add_facebook_share_columns();
        add_action('admin_init', array($this, 'register_schedule_count_columns'));
    }
    
    /**
     * Singleton pattern - få instance
     */
    public static function get_instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Tilføj admin menu
     */
    public function add_admin_menu(): void {
        add_menu_page(
            __('Facebook Post Scheduler', 'fb-post-scheduler'),
            __('FB Opslag', 'fb-post-scheduler'),
            'manage_options',
            'fb-post-scheduler',
            array($this, 'admin_page_content'),
            'dashicons-facebook',
            25
        );
        
        add_submenu_page(
            'fb-post-scheduler',
            __('Indstillinger', 'fb-post-scheduler'),
            __('Indstillinger', 'fb-post-scheduler'),
            'manage_options',
            'fb-post-scheduler-settings',
            array($this, 'settings_page_content')
        );
        
        add_submenu_page(
            'fb-post-scheduler',
            __('Kalender', 'fb-post-scheduler'),
            __('Kalender', 'fb-post-scheduler'),
            'manage_options',
            'fb-post-scheduler-calendar',
            array($this, 'calendar_page_content')
        );
    }
    
    /**
     * Admin hovedside indhold
     */
    public function admin_page_content(): void {
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <p><?php _e('Velkommen til Facebook Post Scheduler. Brug denne side til at administrere dine planlagte facebook-opslag.', 'fb-post-scheduler'); ?></p>
            
            <?php
            // Vis succes besked hvis poster er blevet behandlet
            if (isset($_GET['posts_processed']) && $_GET['posts_processed'] === 'true') {
                echo '<div class="notice notice-success is-dismissible"><p>' . __('Planlagte Facebook-opslag er blevet behandlet.', 'fb-post-scheduler') . '</p></div>';
            }

            if (isset($_GET['revision_schedules_deleted'])) {
                $deleted_revisions = absint($_GET['revision_schedules_deleted']);
                echo '<div class="notice notice-success is-dismissible"><p>' .
                    sprintf(
                        _n(
                            '%d planlagt opslag knyttet til en revision blev slettet.',
                            '%d planlagte opslag knyttet til revisioner blev slettet.',
                            $deleted_revisions,
                            'fb-post-scheduler'
                        ),
                        $deleted_revisions
                    ) .
                    '</p></div>';
            }

            if (isset($_GET['bulk_deleted'])) {
                $bulk_deleted = absint($_GET['bulk_deleted']);
                echo '<div class="notice notice-success is-dismissible"><p>' .
                    sprintf(
                        _n(
                            '%d opslag blev slettet.',
                            '%d opslag blev slettet.',
                            $bulk_deleted,
                            'fb-post-scheduler'
                        ),
                        $bulk_deleted
                    ) .
                    '</p></div>';
            }
            
            // Vis besked om migrering gennemført
            if (isset($_GET['migration_complete']) && $_GET['migration_complete'] === 'true') {
                $success = isset($_GET['success']) ? intval($_GET['success']) : 0;
                $failed = isset($_GET['failed']) ? intval($_GET['failed']) : 0;
                
                echo '<div class="notice notice-success is-dismissible"><p>' . 
                    sprintf(__('Facebook Post Scheduler migrering gennemført. %d opslag migreret med succes, %d fejlede.', 'fb-post-scheduler'), 
                    $success, $failed) . '</p></div>';
            }
            ?>
            
            <div class="fb-admin-buttons">
                <a href="<?php echo admin_url('admin.php?page=fb-post-scheduler&process_posts=true'); ?>" class="button button-primary">
                    <?php _e('Kør Facebook-opslag nu', 'fb-post-scheduler'); ?>
                </a>
                
                <a href="<?php echo admin_url('admin.php?page=fb-post-scheduler-export'); ?>" class="button">
                    <?php _e('Eksporter planlagte opslag', 'fb-post-scheduler'); ?>
                </a>
            </div>
            
            <h2><?php _e('Kommende Facebook-opslag', 'fb-post-scheduler'); ?></h2>
            <?php
            $revision_schedule_count = fb_post_scheduler_count_scheduled_revision_posts();
            if ($revision_schedule_count > 0) :
                ?>
                <form method="post" class="fb-delete-revision-schedules" data-confirm="<?php echo esc_attr(__('Slet alle planlagte Facebook-opslag der er knyttet til en WordPress-revision? De rigtige indlæg (fx begivenheder) berøres ikke.', 'fb-post-scheduler')); ?>">
                    <?php wp_nonce_field('fb_post_scheduler_delete_revision_schedules'); ?>
                    <input type="hidden" name="fb_post_scheduler_delete_revision_schedules" value="1">
                    <p>
                        <button type="submit" class="button button-link-delete">
                            <?php
                            printf(
                                _n(
                                    'Fjern %d planlagt opslag knyttet til en revision',
                                    'Fjern %d planlagte opslag knyttet til revisioner',
                                    $revision_schedule_count,
                                    'fb-post-scheduler'
                                ),
                                $revision_schedule_count
                            );
                            ?>
                        </button>
                    </p>
                </form>
                <?php
            endif;
            ?>
            
            <?php
            // Få alle planlagte opslag fra databasen
            global $wpdb;
            $table_name = $wpdb->prefix . 'fb_scheduled_posts';
            $now = current_time('mysql');
            
            $scheduled_posts = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table_name 
                WHERE scheduled_time >= %s AND status = 'scheduled'
                ORDER BY scheduled_time ASC",
                $now
            ));
            
            if (!empty($scheduled_posts)) :
                ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=fb-post-scheduler')); ?>" class="fb-bulk-posts-form">
                    <?php $this->render_list_bulk_actions('scheduled'); ?>
                    <table class="widefat striped" id="scheduled-posts-table">
                        <thead>
                            <tr>
                                <td class="check-column">
                                    <input id="cb-select-all-scheduled" class="fb-select-all" type="checkbox" data-target="fb-row-scheduled" aria-label="<?php esc_attr_e('Vælg alle planlagte opslag', 'fb-post-scheduler'); ?>">
                                </td>
                                <th><?php _e('Titel', 'fb-post-scheduler'); ?></th>
                                <th><?php _e('Type', 'fb-post-scheduler'); ?></th>
                                <th><?php _e('Planlagt til', 'fb-post-scheduler'); ?></th>
                                <th><?php _e('Facebook-tekst', 'fb-post-scheduler'); ?></th>
                                <th><?php _e('Handlinger', 'fb-post-scheduler'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($scheduled_posts as $post) : ?>
                                <tr>
                                    <th class="check-column">
                                        <input class="fb-row-scheduled" type="checkbox" name="fb_row_ids[]" value="<?php echo esc_attr((string) $post->id); ?>" aria-label="<?php echo esc_attr(sprintf(__('Vælg %s', 'fb-post-scheduler'), $post->post_title)); ?>">
                                    </th>
                                    <td>
                                        <a href="<?php echo admin_url('post.php?post=' . $post->post_id . '&action=edit'); ?>"><?php echo esc_html($post->post_title); ?></a>
                                    </td>
                                    <td>
                                        <?php echo esc_html(fb_post_scheduler_get_linked_post_type_label($post->post_id)); ?>
                                    </td>
                                    <td>
                                        <?php 
                                        echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($post->scheduled_time));
                                        ?>
                                    </td>
                                    <td>
                                        <?php 
                                        echo wp_trim_words($post->message, 10);
                                        ?>
                                    </td>
                                    <td>
                                        <a href="<?php echo admin_url('post.php?post=' . $post->post_id . '&action=edit'); ?>" class="button"><?php _e('Rediger', 'fb-post-scheduler'); ?></a>
                                        <button type="button" class="button button-link-delete fb-delete-scheduled-post" data-post-id="<?php echo $post->post_id; ?>" data-index="<?php echo $post->post_index; ?>" data-scheduled-id="<?php echo $post->id; ?>"><?php _e('Slet', 'fb-post-scheduler'); ?></button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </form>
                <?php
            else :
                ?>
                <p><?php _e('Ingen planlagte Facebook-opslag fundet.', 'fb-post-scheduler'); ?></p>
                <?php
            endif;
            ?>

            <h2><?php _e('Postede Facebook-opslag', 'fb-post-scheduler'); ?></h2>
            <?php
            $posted_posts = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table_name WHERE status = %s ORDER BY scheduled_time DESC LIMIT 50",
                'posted'
            ));

            if (!empty($posted_posts)) :
                ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=fb-post-scheduler')); ?>" class="fb-bulk-posts-form">
                    <?php $this->render_list_bulk_actions('posted'); ?>
                    <table class="widefat striped" id="posted-posts-table">
                        <thead>
                            <tr>
                                <td class="check-column">
                                    <input id="cb-select-all-posted" class="fb-select-all" type="checkbox" data-target="fb-row-posted" aria-label="<?php esc_attr_e('Vælg alle postede opslag', 'fb-post-scheduler'); ?>">
                                </td>
                                <th><?php _e('Titel', 'fb-post-scheduler'); ?></th>
                                <th><?php _e('Type', 'fb-post-scheduler'); ?></th>
                                <th><?php _e('Postet', 'fb-post-scheduler'); ?></th>
                                <th><?php _e('Facebook-tekst', 'fb-post-scheduler'); ?></th>
                                <th><?php _e('Handlinger', 'fb-post-scheduler'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($posted_posts as $posted_post) : ?>
                                <tr>
                                    <th class="check-column">
                                        <input class="fb-row-posted" type="checkbox" name="fb_row_ids[]" value="<?php echo esc_attr((string) $posted_post->id); ?>" aria-label="<?php echo esc_attr(sprintf(__('Vælg %s', 'fb-post-scheduler'), $posted_post->post_title)); ?>">
                                    </th>
                                    <td>
                                        <a href="<?php echo esc_url(admin_url('post.php?post=' . $posted_post->post_id . '&action=edit')); ?>"><?php echo esc_html($posted_post->post_title); ?></a>
                                    </td>
                                    <td>
                                        <?php echo esc_html(fb_post_scheduler_get_linked_post_type_label($posted_post->post_id)); ?>
                                    </td>
                                    <td>
                                        <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($posted_post->scheduled_time))); ?>
                                    </td>
                                    <td>
                                        <?php echo esc_html(wp_trim_words($posted_post->message, 10)); ?>
                                    </td>
                                    <td>
                                        <button type="button" class="button button-link-delete fb-delete-posted-record"
                                            data-post-id="<?php echo esc_attr($posted_post->post_id); ?>"
                                            data-post-index="<?php echo esc_attr($posted_post->post_index); ?>"
                                            data-scheduled-id="<?php echo esc_attr($posted_post->id); ?>"><?php _e('Slet', 'fb-post-scheduler'); ?></button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </form>
                <?php
            else :
                ?>
                <p><?php _e('Ingen postede Facebook-opslag fundet.', 'fb-post-scheduler'); ?></p>
                <?php
            endif;
            ?>
        </div>
        <?php
    }
    
    /**
     * Faner på indstillingssiden.
     *
     * Hver fane har sin egen indstillingsgruppe, så gemning af én fane
     * ikke overskriver felter fra de andre.
     *
     * @return array<string, array{label: string, group: string, page: string}>
     */
    private function get_settings_tabs(): array {
        return array(
            'post-types' => array(
                'label' => __('Post Types Indstillinger', 'fb-post-scheduler'),
                'group' => 'fb_post_scheduler_post_types_settings',
                'page'  => 'fb-post-scheduler-settings-post-types',
            ),
            'facebook' => array(
                'label' => __('Facebook API Indstillinger', 'fb-post-scheduler'),
                'group' => 'fb_post_scheduler_facebook_settings',
                'page'  => 'fb-post-scheduler-settings-facebook',
            ),
            'ai' => array(
                'label' => __('AI Tekst Generator Indstillinger', 'fb-post-scheduler'),
                'group' => 'fb_post_scheduler_ai_settings',
                'page'  => 'fb-post-scheduler-settings-ai',
            ),
            'notifications' => array(
                'label' => __('Notifikationer', 'fb-post-scheduler'),
                'group' => 'fb_post_scheduler_notification_settings',
                'page'  => 'fb-post-scheduler-settings-notifications',
            ),
        );
    }

    /**
     * Aktiv fane. Ukendte værdier falder tilbage til post types.
     */
    private function get_current_settings_tab(): string {
        $tabs = $this->get_settings_tabs();
        $requested = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'post-types';

        return isset($tabs[$requested]) ? $requested : 'post-types';
    }

    /**
     * Indstillingsside indhold
     */
    public function settings_page_content(): void {
        $tabs = $this->get_settings_tabs();
        $current = $this->get_current_settings_tab();
        $base_url = admin_url('admin.php?page=fb-post-scheduler-settings');
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <?php settings_errors(); ?>

            <nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e('Indstillingsfaner', 'fb-post-scheduler'); ?>">
                <?php foreach ($tabs as $slug => $tab) : ?>
                    <a
                        href="<?php echo esc_url(add_query_arg('tab', $slug, $base_url)); ?>"
                        class="nav-tab<?php echo $current === $slug ? ' nav-tab-active' : ''; ?>"
                        <?php echo $current === $slug ? 'aria-current="page"' : ''; ?>
                    ><?php echo esc_html($tab['label']); ?></a>
                <?php endforeach; ?>
            </nav>

            <form method="post" action="options.php" class="fb-settings-form">
                <?php
                settings_fields($tabs[$current]['group']);
                do_settings_sections($tabs[$current]['page']);
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
    
    /**
     * Kalenderside indhold
     */
    public function calendar_page_content(): void {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <div id="fb-post-calendar">
                <!-- Kalender vil blive indlæst med JavaScript -->
                <div class="calendar-loading"><?php _e('Indlæser kalender...', 'fb-post-scheduler'); ?></div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Registrer indstillinger
     */
    public function register_settings(): void {
        $tabs = $this->get_settings_tabs();

        register_setting(
            $tabs['post-types']['group'],
            'fb_post_scheduler_post_types'
        );

        register_setting(
            $tabs['facebook']['group'],
            'fb_post_scheduler_facebook_app_id'
        );

        register_setting(
            $tabs['facebook']['group'],
            'fb_post_scheduler_facebook_app_secret'
        );

        register_setting(
            $tabs['facebook']['group'],
            'fb_post_scheduler_facebook_page_id'
        );

        register_setting(
            $tabs['facebook']['group'],
            'fb_post_scheduler_facebook_access_token'
        );

        if (get_option('fb_post_scheduler_facebook_group_id', '') !== '') {
            delete_option('fb_post_scheduler_facebook_group_id');
        }
        if (get_option('fb_post_scheduler_facebook_group_name', '') !== '') {
            delete_option('fb_post_scheduler_facebook_group_name');
        }

        // Bruger-token og sidenavn gemmes via AJAX og må ikke indgå i formularens
        // indstillingsgruppe, ellers nulstilles de når Facebook-fanen gemmes.

        register_setting(
            $tabs['ai']['group'],
            'fb_post_scheduler_ai_enabled'
        );

        register_setting(
            $tabs['ai']['group'],
            'fb_post_scheduler_ai_provider',
            array(
                'type'              => 'string',
                'sanitize_callback' => 'fb_post_scheduler_sanitize_ai_provider',
                'default'           => 'ollama',
            )
        );

        register_setting(
            $tabs['ai']['group'],
            'fb_post_scheduler_gemini_api_key'
        );

        register_setting(
            $tabs['ai']['group'],
            'fb_post_scheduler_ai_prompt'
        );

        add_settings_section(
            'fb_post_scheduler_post_types_section',
            __('Post Types Indstillinger', 'fb-post-scheduler'),
            array($this, 'post_types_section_callback'),
            $tabs['post-types']['page']
        );

        add_settings_section(
            'fb_post_scheduler_facebook_section',
            __('Facebook API Indstillinger', 'fb-post-scheduler'),
            array($this, 'facebook_section_callback'),
            $tabs['facebook']['page']
        );

        add_settings_section(
            'fb_post_scheduler_ai_section',
            __('AI Tekst Generator Indstillinger', 'fb-post-scheduler'),
            array($this, 'ai_section_callback'),
            $tabs['ai']['page']
        );

        add_settings_field(
            'fb_post_scheduler_post_types',
            __('Vælg Post Types', 'fb-post-scheduler'),
            array($this, 'post_types_field_callback'),
            $tabs['post-types']['page'],
            'fb_post_scheduler_post_types_section'
        );

        add_settings_field(
            'fb_post_scheduler_facebook_app_id',
            __('Facebook App ID', 'fb-post-scheduler'),
            array($this, 'facebook_app_id_callback'),
            $tabs['facebook']['page'],
            'fb_post_scheduler_facebook_section'
        );

        add_settings_field(
            'fb_post_scheduler_facebook_app_secret',
            __('Facebook App Secret', 'fb-post-scheduler'),
            array($this, 'facebook_app_secret_callback'),
            $tabs['facebook']['page'],
            'fb_post_scheduler_facebook_section'
        );

        add_settings_field(
            'fb_post_scheduler_facebook_connect',
            __('Forbind Facebook-side', 'fb-post-scheduler'),
            array($this, 'facebook_connect_callback'),
            $tabs['facebook']['page'],
            'fb_post_scheduler_facebook_section'
        );

        add_settings_field(
            'fb_post_scheduler_saved_mention_pages',
            __('Gemte sider til @-tags', 'fb-post-scheduler'),
            array($this, 'saved_mention_pages_callback'),
            $tabs['facebook']['page'],
            'fb_post_scheduler_facebook_section'
        );

        add_settings_field(
            'fb_post_scheduler_facebook_advanced',
            __('Avanceret', 'fb-post-scheduler'),
            array($this, 'facebook_advanced_callback'),
            $tabs['facebook']['page'],
            'fb_post_scheduler_facebook_section'
        );

        add_settings_field(
            'fb_post_scheduler_ai_enabled',
            __('Aktivér AI tekstgenerering', 'fb-post-scheduler'),
            array($this, 'ai_enabled_callback'),
            $tabs['ai']['page'],
            'fb_post_scheduler_ai_section'
        );

        add_settings_field(
            'fb_post_scheduler_ai_provider',
            __('AI-provider', 'fb-post-scheduler'),
            array($this, 'ai_provider_callback'),
            $tabs['ai']['page'],
            'fb_post_scheduler_ai_section'
        );

        add_settings_field(
            'fb_post_scheduler_gemini_api_key',
            __('Google Gemini API Nøgle', 'fb-post-scheduler'),
            array($this, 'gemini_api_key_callback'),
            $tabs['ai']['page'],
            'fb_post_scheduler_ai_section'
        );

        add_settings_field(
            'fb_post_scheduler_ai_prompt',
            __('AI Prompt Skabelon', 'fb-post-scheduler'),
            array($this, 'ai_prompt_callback'),
            $tabs['ai']['page'],
            'fb_post_scheduler_ai_section'
        );

        register_setting(
            $tabs['notifications']['group'],
            'fb_post_scheduler_notification_email_enabled',
            array(
                'type' => 'string',
                'sanitize_callback' => array($this, 'sanitize_notification_toggle'),
                'default' => '1',
            )
        );

        register_setting(
            $tabs['notifications']['group'],
            'fb_post_scheduler_notification_email_address',
            array(
                'type' => 'string',
                'sanitize_callback' => array($this, 'sanitize_notification_email'),
                'default' => '',
            )
        );

        add_settings_section(
            'fb_post_scheduler_notification_section',
            __('Notifikationer', 'fb-post-scheduler'),
            array($this, 'notification_section_callback'),
            $tabs['notifications']['page']
        );

        add_settings_field(
            'fb_post_scheduler_notification_email_enabled',
            __('Opsummering på mail', 'fb-post-scheduler'),
            array($this, 'notification_email_enabled_callback'),
            $tabs['notifications']['page'],
            'fb_post_scheduler_notification_section'
        );

        add_settings_field(
            'fb_post_scheduler_notification_email_address',
            __('Mailadresse', 'fb-post-scheduler'),
            array($this, 'notification_email_address_callback'),
            $tabs['notifications']['page'],
            'fb_post_scheduler_notification_section'
        );
    }
    
    /**
     * Post Types sektion callback
     */
    public function post_types_section_callback(): void {
        echo '<p>' . __('Vælg hvilke post types der skal have mulighed for at planlægge Facebook-opslag.', 'fb-post-scheduler') . '</p>';
    }
    
    /**
     * Facebook sektion callback
     */
    public function facebook_section_callback(): void {
        $allowed_link = array(
            'a' => array(
                'href'   => array(),
                'target' => array(),
                'rel'    => array(),
            ),
        );

        echo '<details class="fb-app-setup-guide">';
        echo '<summary>' . esc_html__('Opsætningsguide til Facebook', 'fb-post-scheduler') . '</summary>';
        echo '<div class="fb-setup-steps">';

        echo '<div class="fb-setup-step">';
        echo '<h3>1. ' . esc_html__('Opret Facebook App', 'fb-post-scheduler') . '</h3>';
        echo '<p>' . wp_kses(
            sprintf(
                /* translators: %s: URL to Facebook for Developers */
                __('Gå til <a href="%s" target="_blank" rel="noopener noreferrer">Facebook for Developers</a> og opret en ny app af typen "Business".', 'fb-post-scheduler'),
                esc_url('https://developers.facebook.com/apps/')
            ),
            $allowed_link
        ) . '</p>';
        echo '</div>';

        echo '<div class="fb-setup-step">';
        echo '<h3>2. ' . esc_html__('Tilføj Facebook Login', 'fb-post-scheduler') . '</h3>';
        echo '<p>' . esc_html__('Tilføj produktet Facebook Login, så du kan hente et bruger-token i Graph API Explorer.', 'fb-post-scheduler') . '</p>';
        echo '</div>';

        echo '<div class="fb-setup-step">';
        echo '<h3>3. ' . esc_html__('Tilladelser', 'fb-post-scheduler') . '</h3>';
        echo '<p>' . esc_html__('Bruger-tokenet skal have disse tilladelser:', 'fb-post-scheduler') . '</p>';
        echo '<ul class="fb-permissions-list">';
        echo '<li><code>pages_show_list</code> — ' . esc_html__('Se liste over sider', 'fb-post-scheduler') . '</li>';
        echo '<li><code>pages_read_engagement</code> — ' . esc_html__('Læs side-engagement', 'fb-post-scheduler') . '</li>';
        echo '<li><code>pages_manage_posts</code> — ' . esc_html__('Udgiv opslag på siden', 'fb-post-scheduler') . '</li>';
        echo '<li><code>business_management</code> — ' . esc_html__('Sider i en Business-konto', 'fb-post-scheduler') . '</li>';
        echo '</ul>';
        echo '</div>';

        echo '<div class="fb-setup-step">';
        echo '<h3>4. ' . esc_html__('Hent App ID og App Secret', 'fb-post-scheduler') . '</h3>';
        echo '<p>' . esc_html__('Find App ID og App Secret under appens grundindstillinger, og gem dem her, før du opretter side-tokenet.', 'fb-post-scheduler') . '</p>';
        echo '</div>';

        echo '</div>';
        echo '</details>';

        echo '<p><strong>' . esc_html__('Gem App ID og App Secret. Derefter opretter du et side-token uden udløb i boksen nedenfor.', 'fb-post-scheduler') . '</strong></p>';
    }
    
    /**
     * AI sektion callback
     */
    public function ai_section_callback(): void {
        echo '<p>' . __('Konfigurer automatisk generering af Facebook-opslagstekst. Brug Ollama lokalt eller Google Gemini i skyen.', 'fb-post-scheduler') . '</p>';
    }

    /**
     * Notifikationssektion.
     */
    public function notification_section_callback(): void {
        echo '<p>' . esc_html__('Send én mail, når Facebook-opslag postes eller fejler. Flere resultater i samme kørsel samles i samme mail.', 'fb-post-scheduler') . '</p>';
    }

    /**
     * Slå opsummeringsmail til eller fra.
     */
    public function notification_email_enabled_callback(): void {
        $enabled = get_option('fb_post_scheduler_notification_email_enabled', '1');
        echo '<input type="hidden" name="fb_post_scheduler_notification_email_enabled" value="0">';
        echo '<label><input type="checkbox" name="fb_post_scheduler_notification_email_enabled" value="1" ' . checked('1', $enabled, false) . '> ';
        echo esc_html__('Send opsummering, når et opslag postes eller fejler', 'fb-post-scheduler');
        echo '</label>';
    }

    /**
     * Modtager af opsummeringsmailen.
     */
    public function notification_email_address_callback(): void {
        $address = get_option('fb_post_scheduler_notification_email_address', '');
        echo '<input type="email" name="fb_post_scheduler_notification_email_address" value="' . esc_attr($address) . '" class="regular-text" placeholder="' . esc_attr(get_option('admin_email')) . '">';
        echo '<p class="description">' . esc_html__('Tomt felt bruger webstedets administrator-mail.', 'fb-post-scheduler') . '</p>';
    }

    /**
     * Gem afkrydsning som 1 eller 0.
     *
     * @param mixed $value Indsendt værdi.
     */
    public function sanitize_notification_toggle($value): string {
        return ((string) $value === '1') ? '1' : '0';
    }

    /**
     * Gem kun en gyldig mailadresse.
     *
     * @param mixed $value Indsendt værdi.
     */
    public function sanitize_notification_email($value): string {
        $email = sanitize_email((string) $value);
        return is_email($email) ? $email : '';
    }
    
    /**
     * Post Types felt callback
     */
    public function post_types_field_callback(): void {
        $selected_post_types = get_option('fb_post_scheduler_post_types', array());
        
        // Få alle tilgængelige post types
        $args = array(
            'public' => true
        );
        
        $post_types = get_post_types($args, 'objects');
        
        foreach ($post_types as $post_type) {
            // Undgå interne WordPress post types som attachments, blocks osv.
            if (in_array($post_type->name, array('attachment', 'wp_block', 'wp_navigation'))) {
                continue;
            }
            
            printf(
                '<label><input type="checkbox" name="fb_post_scheduler_post_types[]" value="%s" %s> %s</label><br>',
                esc_attr($post_type->name),
                in_array($post_type->name, $selected_post_types) ? 'checked' : '',
                esc_html($post_type->labels->singular_name)
            );
        }
    }
    
    /**
     * Facebook App ID callback
     */
    public function facebook_app_id_callback(): void {
        $app_id = get_option('fb_post_scheduler_facebook_app_id', '');
        echo '<input type="text" name="fb_post_scheduler_facebook_app_id" value="' . esc_attr($app_id) . '" class="regular-text">';
    }
    
    /**
     * Facebook App Secret callback
     */
    public function facebook_app_secret_callback(): void {
        $app_secret = get_option('fb_post_scheduler_facebook_app_secret', '');
        echo '<input type="password" name="fb_post_scheduler_facebook_app_secret" value="' . esc_attr($app_secret) . '" class="regular-text">';
    }
    
    /**
     * Ét trin: short-term bruger-token bliver til et side-token uden udløb.
     */
    public function facebook_connect_callback(): void {
        $page_id = get_option('fb_post_scheduler_facebook_page_id', '');
        $page_name = get_option('fb_post_scheduler_facebook_page_name', '');
        $access_token = get_option('fb_post_scheduler_facebook_access_token', '');
        $expires = get_option('fb_post_scheduler_token_expires_date', '');
        $allowed_link = array(
            'a' => array(
                'href'   => array(),
                'target' => array(),
                'rel'    => array(),
            ),
            'code' => array(),
        );

        echo '<div class="fb-page-selector-section">';
        echo '<p>' . wp_kses(
            sprintf(
                /* translators: %s: Graph API Explorer URL */
                __('Åbn <a href="%s" target="_blank" rel="noopener noreferrer">Graph API Explorer</a>, vælg den samme app som App ID herunder, og tilføj tilladelserne <code>pages_show_list</code>, <code>pages_read_engagement</code>, <code>pages_manage_posts</code> og <code>business_management</code>. Generér et bruger-token og indsæt det her. Brug ikke side-tokenet. Du skal være administrator eller redaktør på siden. En rolle i Business-kontoen alene kan ikke udgive opslag.', 'fb-post-scheduler'),
                esc_url('https://developers.facebook.com/tools/explorer/')
            ),
            $allowed_link
        ) . '</p>';

        echo '<label for="fb-short-term-token"><strong>' . esc_html__('Short-term bruger-token', 'fb-post-scheduler') . '</strong></label><br>';
        echo '<input type="password" id="fb-short-term-token" class="regular-text" autocomplete="off" placeholder="' . esc_attr__('Indsæt token fra Graph API Explorer', 'fb-post-scheduler') . '">';
        echo '<button type="button" id="fb-connect-page" class="button button-primary" style="margin-left: 10px;">' . esc_html__('Opret long-term side-token', 'fb-post-scheduler') . '</button>';
        echo '<span class="spinner" id="fb-connect-spinner" style="float: none; margin-left: 10px;"></span>';
        echo '<div id="fb-connect-result" style="margin-top: 10px;"></div>';

        echo '<div id="fb-pages-dropdown-container" style="margin-top: 15px; display: none;">';
        echo '<label for="fb-pages-dropdown"><strong>' . esc_html__('Vælg Facebook-side', 'fb-post-scheduler') . '</strong></label><br>';
        echo '<select id="fb-pages-dropdown" class="regular-text">';
        echo '<option value="">' . esc_html__('-- Vælg en side --', 'fb-post-scheduler') . '</option>';
        echo '</select>';
        echo '<button type="button" id="fb-select-page" class="button button-primary" style="margin-left: 10px;">' . esc_html__('Brug denne side', 'fb-post-scheduler') . '</button>';
        echo '</div>';

        if ($page_id !== '' || $page_name !== '' || $access_token !== '') {
            $expires_label = ($expires === '' || $expires === '0' || $expires === 'Aldrig')
                ? __('Udløber ikke', 'fb-post-scheduler')
                : $expires;

            echo '<div class="fb-current-page-info">';
            echo '<h4>' . esc_html__('Forbundet side', 'fb-post-scheduler') . '</h4>';
            echo '<p><strong>' . esc_html($page_name !== '' ? $page_name : __('Ukendt side', 'fb-post-scheduler')) . '</strong>';
            if ($page_id !== '') {
                echo ' (ID: ' . esc_html($page_id) . ')';
            }
            echo '<br>' . esc_html__('Token:', 'fb-post-scheduler') . ' ' . esc_html($expires_label) . '</p>';
            echo '<p class="description">' . esc_html__('Tokenet har ingen udløbsdato. Det holder op med at virke, hvis du skifter Facebook-kodeord, mister rollen på siden eller fjerner appen.', 'fb-post-scheduler') . '</p>';
            echo '<button type="button" id="fb-test-connection" class="button button-secondary">' . esc_html__('Test Facebook API Forbindelse', 'fb-post-scheduler') . '</button>';
            echo '<button type="button" id="fb-check-token-expiry" class="button button-secondary" style="margin-left: 10px;">' . esc_html__('Tjek Token Udløb', 'fb-post-scheduler') . '</button>';
            echo '<span class="spinner" id="fb-test-spinner" style="float: none; margin-left: 10px;"></span>';
            echo '<div id="fb-test-result" style="margin-top: 10px;"></div>';
            echo '</div>';
        }

        echo '</div>';
    }

    /**
     * Manuelle felter til en eksisterende side og side-token.
     */
    public function facebook_advanced_callback(): void {
        $page_id = get_option('fb_post_scheduler_facebook_page_id', '');
        $access_token = get_option('fb_post_scheduler_facebook_access_token', '');

        echo '<details class="fb-advanced-token">';
        echo '<summary>' . esc_html__('Ret side-id og token manuelt', 'fb-post-scheduler') . '</summary>';
        echo '<p class="description">' . esc_html__('Felterne udfyldes automatisk, når du opretter et long-term side-token. Ret dem kun, hvis forbindelsen skal sættes manuelt, og gem derefter ændringer.', 'fb-post-scheduler') . '</p>';
        echo '<p>';
        echo '<label for="fb_post_scheduler_facebook_page_id"><strong>' . esc_html__('Facebook Side ID', 'fb-post-scheduler') . '</strong></label><br>';
        echo '<input type="text" id="fb_post_scheduler_facebook_page_id" name="fb_post_scheduler_facebook_page_id" value="' . esc_attr($page_id) . '" class="regular-text">';
        echo '</p>';
        echo '<p>';
        echo '<label for="fb_post_scheduler_facebook_access_token"><strong>' . esc_html__('Facebook Access Token', 'fb-post-scheduler') . '</strong></label><br>';
        echo '<input type="password" id="fb_post_scheduler_facebook_access_token" name="fb_post_scheduler_facebook_access_token" value="' . esc_attr($access_token) . '" class="regular-text" autocomplete="off">';
        echo '</p>';
        echo '</details>';
    }

    /**
     * Settings UI: gem navn + Page ID til @[mention]-autocomplete i opslagsteksten.
     */
    public function saved_mention_pages_callback(): void {
        $pages = function_exists('fb_post_scheduler_get_saved_mention_pages')
            ? fb_post_scheduler_get_saved_mention_pages()
            : array();

        echo '<div class="fb-saved-mention-pages" id="fb-saved-mention-pages">';
        echo '<p class="description" style="margin-top:0;">' . esc_html__(
            'Gem Facebook-sider (navn + Page ID). I opslagsteksten kan du skrive @[ efterfulgt af navnet for at indsætte @[PAGE_ID].',
            'fb-post-scheduler'
        ) . '</p>';

        echo '<div class="fb-saved-mention-pages-form">';
        echo '<p>';
        echo '<label for="fb-saved-page-name"><strong>' . esc_html__('Side-navn', 'fb-post-scheduler') . '</strong></label><br>';
        echo '<input type="text" id="fb-saved-page-name" class="regular-text" placeholder="' . esc_attr__('f.eks. Skoringen', 'fb-post-scheduler') . '" autocomplete="off">';
        echo '</p>';
        echo '<p>';
        echo '<label for="fb-saved-page-id"><strong>' . esc_html__('Facebook Page ID', 'fb-post-scheduler') . '</strong></label><br>';
        echo '<input type="text" id="fb-saved-page-id" class="regular-text" placeholder="' . esc_attr__('f.eks. 123456789012345', 'fb-post-scheduler') . '" inputmode="numeric" autocomplete="off">';
        echo '</p>';
        echo '<p>';
        echo '<button type="button" id="fb-add-saved-page" class="button button-secondary">' . esc_html__('Tilføj side', 'fb-post-scheduler') . '</button>';
        echo '<span class="spinner" id="fb-saved-pages-spinner" style="float:none;margin-left:10px;"></span>';
        echo '</p>';
        echo '<div id="fb-saved-pages-result"></div>';
        echo '</div>';

        echo '<ul id="fb-saved-pages-list" class="fb-saved-pages-list">';
        if (empty($pages)) {
            echo '<li class="fb-saved-pages-empty">' . esc_html__('Ingen gemte sider endnu.', 'fb-post-scheduler') . '</li>';
        } else {
            foreach ($pages as $page) {
                echo '<li data-page-id="' . esc_attr($page['id']) . '">';
                echo '<span class="fb-saved-page-name">' . esc_html($page['name']) . '</span>';
                echo '<span class="fb-saved-page-id">' . esc_html($page['id']) . '</span>';
                echo '<button type="button" class="button-link-delete fb-remove-saved-page" data-page-id="' . esc_attr($page['id']) . '">' . esc_html__('Fjern', 'fb-post-scheduler') . '</button>';
                echo '</li>';
            }
        }
        echo '</ul>';
        echo '</div>';
    }
    
    /**
     * AI enabled callback
     */
    public function ai_enabled_callback(): void {
        $enabled = get_option('fb_post_scheduler_ai_enabled', '');
        echo '<input type="checkbox" name="fb_post_scheduler_ai_enabled" value="1" ' . checked('1', $enabled, false) . '>';
        echo '<p class="description">' . __('Aktivér for at bruge AI til at generere Facebook-opslagstekst automatisk.', 'fb-post-scheduler') . '</p>';
    }
    
    /**
     * AI provider callback
     */
    public function ai_provider_callback(): void {
        $provider = fb_post_scheduler_get_ai_provider();
        echo '<select id="fb_post_scheduler_ai_provider" name="fb_post_scheduler_ai_provider">';
        echo '<option value="ollama" ' . selected('ollama', $provider, false) . '>' . esc_html__('Ollama (localhost)', 'fb-post-scheduler') . '</option>';
        echo '<option value="gemini" ' . selected('gemini', $provider, false) . '>' . esc_html__('Google Gemini API', 'fb-post-scheduler') . '</option>';
        echo '</select>';
        echo '<p class="description">' . __('Ollama kører lokalt (fx Gemma 4). Gemini kræver en API-nøgle og bruges typisk i produktion.', 'fb-post-scheduler') . '</p>';
    }

    /**
     * Gemini API key callback
     */
    public function gemini_api_key_callback(): void {
        $api_key = get_option('fb_post_scheduler_gemini_api_key', '');
        echo '<input type="password" name="fb_post_scheduler_gemini_api_key" value="' . esc_attr($api_key) . '" class="regular-text">';
        echo '<p class="description">' . __('Din Google Gemini API nøgle. Du kan få en fra <a href="https://ai.google.dev/" target="_blank">Google AI Studio</a>. Bruges kun når Gemini er valgt som provider.', 'fb-post-scheduler') . '</p>';
    }
    
    /**
     * AI prompt callback
     */
    public function ai_prompt_callback(): void {
        $default_prompt = __('Skriv et kortfattet og engagerende Facebook-opslag på dansk baseret på følgende indhold. Opslaget skal være mellem 2-3 sætninger og motivere til at læse hele artiklen. Undlad at bruge hashtags. Skriv i en venlig, informativ tone:', 'fb-post-scheduler');
        $prompt = get_option('fb_post_scheduler_ai_prompt', $default_prompt);
        echo '<textarea name="fb_post_scheduler_ai_prompt" rows="4" class="large-text">' . esc_textarea($prompt) . '</textarea>';
        echo '<p class="description">' . __('Prompten som sendes til AI. Brug dette til at styre tonen og stilen i de genererede opslag. Indholdet fra artiklen tilføjes automatisk efter denne prompt.', 'fb-post-scheduler') . '</p>';
    }
    
    /**
     * Tilføj meta boxe til valgte post types
     */
    public function add_meta_boxes(): void {
        $selected_post_types = get_option('fb_post_scheduler_post_types', array());
        
        if (!empty($selected_post_types)) {
            foreach ($selected_post_types as $post_type) {
                // Tjek om Gutenberg er aktiv
                $context = $this->is_gutenberg_active() ? 'normal' : 'normal';
                $priority = $this->is_gutenberg_active() ? 'low' : 'low';
                
                add_meta_box(
                    'fb_post_scheduler_meta_box',
                    __('Facebook Opslag', 'fb-post-scheduler'),
                    array($this, 'render_meta_box'),
                    $post_type,
                    $context,
                    $priority
                );
            }
        }
    }
    
    /**
     * Tjek om Gutenberg er aktiv
     */
    private function is_gutenberg_active(): bool {
        return function_exists('use_block_editor_for_post_type');
    }
    
    /**
     * Render meta box indhold
     */
    public function render_meta_box( WP_Post $post ): void {
        // Tilføj nonce for sikkerhed
        wp_nonce_field('fb_post_scheduler_meta_box', 'fb_post_scheduler_meta_box_nonce');
        
        // Meta kan mangle selv om opslaget ligger i fb_scheduled_posts.
        $fb_posts = fb_post_scheduler_get_editor_posts($post->ID);
        
        ?>
        <style>
        .fb-post-scheduler-meta-box .fb-image-control {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
            margin-top: 4px;
        }

        .fb-post-scheduler-meta-box .fb-image-info-wrapper {
            position: relative;
            display: inline-flex;
            align-items: center;
        }

        .fb-post-scheduler-meta-box .fb-image-help-icon {
            width: 22px;
            height: 22px;
            padding: 0;
            border: 1px solid #2271b1;
            border-radius: 50%;
            background: #fff;
            color: #2271b1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            line-height: 1;
            box-shadow: none;
        }

        .fb-post-scheduler-meta-box .fb-image-help-icon:hover,
        .fb-post-scheduler-meta-box .fb-image-help-icon:focus {
            background: #2271b1;
            color: #fff;
            outline: none;
            box-shadow: 0 0 0 1px #fff, 0 0 0 3px rgba(34, 113, 177, 0.2);
        }

        .fb-post-scheduler-meta-box .fb-image-help-icon .dashicons {
            font-size: 14px;
            width: 14px;
            height: 14px;
        }

        .fb-post-scheduler-meta-box .fb-image-info-tooltip {
            position: absolute;
            left: calc(100% + 8px);
            top: 50%;
            transform: translateY(-50%);
            width: 240px;
            max-width: 280px;
            padding: 8px 10px;
            border-radius: 4px;
            background: #1d2327;
            color: #fff;
            font-size: 12px;
            line-height: 1.4;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.2s ease, visibility 0.2s ease;
            z-index: 999;
            pointer-events: none;
        }

        .fb-post-scheduler-meta-box .fb-image-info-wrapper:hover .fb-image-info-tooltip,
        .fb-post-scheduler-meta-box .fb-image-info-wrapper:focus-within .fb-image-info-tooltip {
            opacity: 1;
            visibility: visible;
        }
        </style>
        <div class="fb-post-scheduler-meta-box">
            <div id="fb-posts-container">
                <?php
                $fb_display_number = 0;
                foreach ($fb_posts as $index => $fb_post) :
                    $fb_display_number++;
                    // Formatér dato til input-felt (dato kan mangle for allerede-postede opslag)
                    $date_parts = !empty($fb_post['date']) ? explode(' ', $fb_post['date']) : array();
                    $date = !empty($date_parts[0]) ? $date_parts[0] : '';
                    $time = !empty($date_parts[1]) ? substr($date_parts[1], 0, 5) : '';
                    
                    // Status
                    $is_posted = isset($fb_post['status']) && $fb_post['status'] === 'posted';
                    $image_help_text = __('Vælg et billede til Facebook-linkets forhåndsvisning. Hvis du ikke vælger et, bruges indlæggets udvalgte billede. Opslaget postes som et klikbart link til artiklen.', 'fb-post-scheduler');
                ?>
                <div class="fb-post-item" data-index="<?php echo $index; ?>">
                    <div class="fb-post-header">
                        <h3><?php 
                            if ($is_posted) {
                                echo __('Facebook Opslag #', 'fb-post-scheduler') . $fb_display_number . ' (' . __('Postet', 'fb-post-scheduler') . ')';
                            } else {
                                echo __('Facebook Opslag #', 'fb-post-scheduler') . $fb_display_number;
                            }
                        ?></h3>
                        
                        <?php if (!$is_posted && count($fb_posts) > 1) : ?>
                        <a href="#" class="fb-remove-post dashicons dashicons-trash"></a>
                        <?php endif; ?>
                    </div>
                    
                    <p>
                        <label for="fb_post_enabled_<?php echo $index; ?>">
                            <input type="checkbox" id="fb_post_enabled_<?php echo $index; ?>" name="fb_posts[<?php echo $index; ?>][enabled]" value="1" <?php checked(isset($fb_post['enabled']) && $fb_post['enabled'], true); ?> <?php disabled($is_posted, true); ?>>
                            <?php _e('Aktiver Facebook-opslag', 'fb-post-scheduler'); ?>
                        </label>
                    </p>
                    
                    <?php if ($is_posted && isset($fb_post['fb_post_id'])) : ?>
                    <p class="fb-post-success">
                        <?php _e('Dette opslag blev postet til Facebook', 'fb-post-scheduler'); ?>
                        <?php if (!empty($fb_post['posted_date'])) : ?>
                        <br>
                        <span class="fb-post-posted-date">
                            <?php echo sprintf(__('Postet: %s', 'fb-post-scheduler'), date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($fb_post['posted_date']))); ?>
                        </span>
                        <?php endif; ?>
                    </p>
                    <?php endif; ?>
                    
                    <?php if ($is_posted) : ?>
                    <p>
                        <button type="button" class="button button-link-delete fb-clear-post-record"
                            data-post-id="<?php echo esc_attr($post->ID); ?>"
                            data-post-index="<?php echo esc_attr($index); ?>">
                            <?php _e('Slet opslagsinformationer', 'fb-post-scheduler'); ?>
                        </button>
                    </p>
                    <?php endif; ?>
                    
                    <p>
                        <label for="fb_post_date_<?php echo $index; ?>"><?php _e('Dato for opslag:', 'fb-post-scheduler'); ?></label>
                        <input type="date" id="fb_post_date_<?php echo $index; ?>" name="fb_posts[<?php echo $index; ?>][date]" value="<?php echo esc_attr($date); ?>" class="" <?php disabled($is_posted, true); ?>>
                    </p>
                    
                    <p>
                        <label for="fb_post_time_<?php echo $index; ?>"><?php _e('Tidspunkt for opslag:', 'fb-post-scheduler'); ?></label>
                        <input type="time" id="fb_post_time_<?php echo $index; ?>" name="fb_posts[<?php echo $index; ?>][time]" value="<?php echo esc_attr($time); ?>" class="" <?php disabled($is_posted, true); ?>>
                        <button type="button" class="button fb-toggle-schedule-overview" title="<?php esc_attr_e('Vis overblik over planlagte opslag', 'fb-post-scheduler'); ?>" aria-label="<?php esc_attr_e('Vis overblik over planlagte opslag', 'fb-post-scheduler'); ?>" aria-expanded="false" aria-controls="fb_schedule_overview_<?php echo esc_attr($index); ?>" style="margin-left:5px;">
                            <span class="dashicons dashicons-calendar-alt" style="vertical-align:text-top;"></span>
                        </button>
                    </p>
                    <div id="fb_schedule_overview_<?php echo esc_attr($index); ?>" class="fb-schedule-overview" style="display:none;"></div>
                    
                    <p class="fb-post-text-field">
                        <label for="fb_post_text_<?php echo $index; ?>"><?php _e('Tekst til Facebook-opslag:', 'fb-post-scheduler'); ?></label>
                        <?php if (get_option('fb_post_scheduler_ai_enabled', '') && !$is_posted) : ?>
                        <?php fb_post_scheduler_render_ai_generate_button($index, $post->ID); ?>
                        <?php endif; ?>
                        <textarea id="fb_post_text_<?php echo $index; ?>" name="fb_posts[<?php echo $index; ?>][text]" class="widefat" rows="5" autocomplete="off" <?php disabled($is_posted, true); ?>><?php echo esc_textarea(isset($fb_post['text']) ? $fb_post['text'] : ''); ?></textarea>
                        <span class="description"><?php _e('Skriv @[ og begynd at skrive et gemt side-navn (f.eks. @[Skoring) for at indsætte @[PAGE_ID]. Du kan også skrive @ + mindst 3 bogstaver for at søge offentlige Facebook-sider. Link til indlægget tilføjes automatisk.', 'fb-post-scheduler'); ?></span>
                    </p>
                    
                    <p class="fb-post-image-field">
                        <label for="fb_post_image_<?php echo $index; ?>"><?php _e('Billede til Facebook-opslag:', 'fb-post-scheduler'); ?></label>
                        <input type="hidden" id="fb_post_image_id_<?php echo $index; ?>" name="fb_posts[<?php echo $index; ?>][image_id]" value="<?php echo isset($fb_post['image_id']) ? esc_attr($fb_post['image_id']) : ''; ?>" <?php disabled($is_posted, true); ?>>
                        <div class="fb-post-image-preview-container">
                            <?php if (!empty($fb_post['image_id'])) : 
                                $image_url = wp_get_attachment_image_url($fb_post['image_id'], 'high-res');
                                $image_alt = get_post_meta($fb_post['image_id'], '_wp_attachment_image_alt', true);
                            ?>
                                <img src="<?php echo esc_url($image_url); ?>" alt="<?php echo esc_attr($image_alt); ?>" class="fb-post-image-preview">
                            <?php endif; ?>
                        </div>
                        <div class="fb-image-control">
                            <button type="button" class="button fb-upload-image" data-index="<?php echo $index; ?>" <?php disabled($is_posted, true); ?>><?php _e('Vælg billede', 'fb-post-scheduler'); ?></button>
                            <span class="fb-image-info-wrapper">
                                <button type="button" class="fb-image-help-icon" aria-label="<?php esc_attr_e('Vis information om billedvalg', 'fb-post-scheduler'); ?>" title="<?php echo esc_attr($image_help_text); ?>">
                                    <span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
                                </button>
                                <span class="fb-image-info-tooltip" role="tooltip"><?php echo esc_html($image_help_text); ?></span>
                            </span>
                            <?php if (!empty($fb_post['image_id'])) : ?>
                                <button type="button" class="button fb-remove-image" data-index="<?php echo $index; ?>" <?php disabled($is_posted, true); ?>><?php _e('Fjern billede', 'fb-post-scheduler'); ?></button>
                            <?php endif; ?>
                        </div>
                        <span class="description"><?php //echo esc_html($image_help_text); ?></span>
                    </p>
                    
                    <?php fb_post_scheduler_render_link_preview($post->ID, $fb_post); ?>
                    
                    <?php if ($is_posted) : ?>
                        <input type="hidden" name="fb_posts[<?php echo $index; ?>][status]" value="posted">
                        <input type="hidden" name="fb_posts[<?php echo $index; ?>][text]" value="<?php echo esc_attr(isset($fb_post['text']) ? $fb_post['text'] : ''); ?>">
                        <input type="hidden" name="fb_posts[<?php echo $index; ?>][date]" value="<?php echo esc_attr(isset($fb_post['date']) ? $fb_post['date'] : ''); ?>">
                        <?php if (isset($fb_post['fb_post_id'])) : ?>
                            <input type="hidden" name="fb_posts[<?php echo $index; ?>][fb_post_id]" value="<?php echo esc_attr($fb_post['fb_post_id']); ?>">
                        <?php endif; ?>
                        <?php if (isset($fb_post['posted_date'])) : ?>
                            <input type="hidden" name="fb_posts[<?php echo $index; ?>][posted_date]" value="<?php echo esc_attr($fb_post['posted_date']); ?>">
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            
            <p>
                <button type="button" id="add-fb-post" class="button"><?php _e('Tilføj endnu et opslag', 'fb-post-scheduler'); ?></button>
            </p>
        </div>
        
        <template id="fb-post-template">
            <div class="fb-post-item" data-index="{{index}}">
                <div class="fb-post-header">
                    <h3><?php echo __('Facebook Opslag #', 'fb-post-scheduler'); ?>{{number}}</h3>
                    <a href="#" class="fb-remove-post dashicons dashicons-trash"></a>
                </div>
                
                <p>
                    <label for="fb_post_enabled_{{index}}">
                        <input type="checkbox" id="fb_post_enabled_{{index}}" name="fb_posts[{{index}}][enabled]" value="1">
                        <?php _e('Aktiver Facebook-opslag', 'fb-post-scheduler'); ?>
                    </label>
                </p>
                
                <p>
                    <label for="fb_post_date_{{index}}"><?php _e('Dato for opslag:', 'fb-post-scheduler'); ?></label>
                    <input type="date" id="fb_post_date_{{index}}" name="fb_posts[{{index}}][date]" value="<?php echo date('Y-m-d', strtotime('+1 day') ?? time()); ?>" class="widefat">
                </p>
                
                <p>
                    <label for="fb_post_time_{{index}}"><?php _e('Tidspunkt for opslag:', 'fb-post-scheduler'); ?></label>
                    <input type="time" id="fb_post_time_{{index}}" name="fb_posts[{{index}}][time]" value="12:00" class="widefat">
                    <button type="button" class="button fb-toggle-schedule-overview" title="<?php esc_attr_e('Vis overblik over planlagte opslag', 'fb-post-scheduler'); ?>" aria-label="<?php esc_attr_e('Vis overblik over planlagte opslag', 'fb-post-scheduler'); ?>" aria-expanded="false" aria-controls="fb_schedule_overview_{{index}}" style="margin-left:5px;">
                        <span class="dashicons dashicons-calendar-alt" style="vertical-align:text-top;"></span>
                        <span class="screen-reader-text"><?php _e('Vis overblik over planlagte opslag', 'fb-post-scheduler'); ?></span>
                    </button>
                </p>
                <div id="fb_schedule_overview_{{index}}" class="fb-schedule-overview" style="display:none;"></div>
                
                <p class="fb-post-text-field">
                    <label for="fb_post_text_{{index}}"><?php _e('Tekst til Facebook-opslag:', 'fb-post-scheduler'); ?></label>
                    <?php if (get_option('fb_post_scheduler_ai_enabled', '')) : ?>
                    <?php fb_post_scheduler_render_ai_generate_button('{{index}}', $post->ID); ?>
                    <?php endif; ?>
                    <textarea id="fb_post_text_{{index}}" name="fb_posts[{{index}}][text]" class="widefat" rows="5" autocomplete="off"></textarea>
                    <span class="description"><?php _e('Skriv @[ og begynd at skrive et gemt side-navn (f.eks. @[Skoring) for at indsætte @[PAGE_ID]. Du kan også skrive @ + mindst 3 bogstaver for at søge offentlige Facebook-sider. Link til indlægget tilføjes automatisk.', 'fb-post-scheduler'); ?></span>
                </p>
                
                <p class="fb-post-image-field">
                    <label for="fb_post_image_{{index}}"><?php _e('Billede til Facebook-opslag:', 'fb-post-scheduler'); ?></label>
                    <input type="hidden" id="fb_post_image_id_{{index}}" name="fb_posts[{{index}}][image_id]" value="">
                    <div class="fb-post-image-preview-container"></div>
                    <button type="button" class="button fb-upload-image" data-index="{{index}}"><?php _e('Vælg billede', 'fb-post-scheduler'); ?></button>
                    <span class="description"><?php _e('Vælg et billede til Facebook-linkets forhåndsvisning. Hvis du ikke vælger et, bruges indlæggets udvalgte billede. Opslaget postes som et klikbart link til artiklen.', 'fb-post-scheduler'); ?></span>
                </p>
                
                <?php fb_post_scheduler_render_link_preview($post->ID); ?>
            </div>
        </template>
        <?php
    }
    
    /**
     * Gem meta box data
     */
    public function save_meta_box_data( int $post_id ): void {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        if (get_post_type($post_id) === 'revision') {
            return;
        }

        // Tjek om nonce er sat
        if (!isset($_POST['fb_post_scheduler_meta_box_nonce'])) {
            return;
        }
        
        // Verificér at nonce er valid
        if (!wp_verify_nonce($_POST['fb_post_scheduler_meta_box_nonce'], 'fb_post_scheduler_meta_box')) {
            return;
        }
        
        // Hvis dette er en autosave, skal vi ikke gøre noget
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        // Tjek brugerens rettigheder
        if (isset($_POST['post_type'])) {
            if (!current_user_can('edit_post', $post_id)) {
                return;
            }
        }
        
        // Gem Facebook-opslag data
        if (isset($_POST['fb_posts']) && is_array($_POST['fb_posts'])) {
            $fb_posts = array();
            
            // Hent eksisterende data for at bevare audit-felter på allerede-postede opslag
            $existing_fb_posts = get_post_meta($post_id, '_fb_posts', true);
            if (!is_array($existing_fb_posts)) {
                $existing_fb_posts = array();
            }
            
            foreach ($_POST['fb_posts'] as $index => $fb_post) {
                $index = absint($index);
                if (!is_array($fb_post)) {
                    continue;
                }

                // For allerede-postede opslag: brug de eksisterende gemte audit-data uændret
                // (Disabled form-felter sendes ikke med, hvilket kan overskrive gyldige audit-data)
                if (isset($fb_post['status']) && $fb_post['status'] === 'posted') {
                    if (isset($existing_fb_posts[$index]) && is_array($existing_fb_posts[$index]) && !fb_post_scheduler_editor_slot_is_placeholder($existing_fb_posts[$index])) {
                        $fb_posts[$index] = $existing_fb_posts[$index];
                    } else {
                        // Fallback: rekonstruer fra skjulte input-felter
                        $fb_posts[$index] = array(
                            'status'      => 'posted',
                            'text'        => isset($fb_post['text']) ? sanitize_textarea_field($fb_post['text']) : '',
                            'date'        => isset($fb_post['date']) ? sanitize_text_field($fb_post['date']) : '',
                            'fb_post_id'  => isset($fb_post['fb_post_id']) ? sanitize_text_field($fb_post['fb_post_id']) : '',
                            'posted_date' => isset($fb_post['posted_date']) ? sanitize_text_field($fb_post['posted_date']) : '',
                            'target_type' => 'page',
                            'enabled'     => false,
                        );
                    }
                    continue;
                }
                
                $enabled = isset($fb_post['enabled']) ? true : false;
                $text = isset($fb_post['text']) ? sanitize_textarea_field($fb_post['text']) : '';
                $date = isset($fb_post['date']) ? sanitize_text_field($fb_post['date']) : '';
                $time = isset($fb_post['time']) ? sanitize_text_field($fb_post['time']) : '';
                $datetime = $date . ' ' . $time . ':00';
                $image_id = isset($fb_post['image_id']) ? absint($fb_post['image_id']) : 0;
                $target_type = 'page';
                
                $fb_posts[$index] = array(
                    'text'        => $text,
                    'date'        => $datetime,
                    'enabled'     => $enabled,
                    'image_id'    => $image_id,
                    'target_type' => $target_type,
                );
            }
            
            // Opdater posts meta
            update_post_meta($post_id, '_fb_posts', $fb_posts);
            
            // Tjek om mindst ét opslag er aktiveret
            $has_enabled = false;
            foreach ($fb_posts as $fb_post) {
                if ($fb_post['enabled'] && (!isset($fb_post['status']) || $fb_post['status'] !== 'posted')) {
                    $has_enabled = true;
                    break;
                }
            }
            
            // Opdater _fb_post_enabled meta
            if ($has_enabled) {
                update_post_meta($post_id, '_fb_post_enabled', '1');
                
                // Opret eller opdater planlagte opslag i databasen
                $this->update_scheduled_posts_in_database($post_id, $fb_posts);
            } else {
                delete_post_meta($post_id, '_fb_post_enabled');
                // Fjern planlagte opslag, men behold dem der allerede er postet.
                fb_post_scheduler_delete_unposted_scheduled_posts($post_id);
            }
        }
    }
    
    /**
     * Enqueue admin scripts og styles
     */
    public function enqueue_admin_scripts( string $hook ): void {
        // Indlæs kun på plugin-sider og post-edit skærmen  
        // Hook eksempler: 'toplevel_page_fb-post-scheduler', 'facebook-scheduler_page_fb-post-scheduler-settings'
        $screen = get_current_screen();
        if (
            $hook === 'edit.php'
            && $screen instanceof WP_Screen
            && in_array($screen->post_type, $this->get_schedule_column_post_types(), true)
        ) {
            wp_enqueue_style(
                'fb-post-scheduler-admin-css',
                FB_POST_SCHEDULER_URL . 'assets/css/admin.css',
                array(),
                FB_POST_SCHEDULER_VERSION
            );
        }

        if (strpos($hook, 'fb-post-scheduler') !== false || $hook == 'post.php' || $hook == 'post-new.php' || strpos($hook, 'admin_page_fb-post-scheduler') !== false) {
            // Styles
            wp_enqueue_style(
                'fb-post-scheduler-admin-css',
                FB_POST_SCHEDULER_URL . 'assets/css/admin.css',
                array(),
                FB_POST_SCHEDULER_VERSION
            );
            
            // Media Uploader
            wp_enqueue_media();
            
            // Scripts
            wp_enqueue_script(
                'fb-post-scheduler-admin-js',
                FB_POST_SCHEDULER_URL . 'assets/js/admin.js',
                array('jquery', 'wp-util', 'wp-i18n'),
                FB_POST_SCHEDULER_VERSION,
                true
            );
            
            // Lokalisering
            wp_localize_script(
                'fb-post-scheduler-admin-js',
                'fbPostScheduler',
                array(
                    'deleteConfirm' => __('Er du sikker på, at du vil slette dette opslag?', 'fb-post-scheduler'),
                    'pastDateWarning' => __('Advarsel: Du har valgt en dato i fortiden. Opslaget vil blive postet med det samme.', 'fb-post-scheduler'),
                    'selectImage' => __('Vælg billede til Facebook-opslag', 'fb-post-scheduler'),
                    'useImage' => __('Brug dette billede', 'fb-post-scheduler'),
                    'removeImage' => __('Fjern billede', 'fb-post-scheduler'),
                    'aiNonce' => wp_create_nonce('fb-post-scheduler-ai-nonce'),
                    'nonce' => wp_create_nonce('fb_post_scheduler_nonce'),
                    'ajaxUrl' => admin_url('admin-ajax.php'),
                    'ajaxError' => __('Der opstod en fejl ved kommunikation med serveren. Prøv igen senere.', 'fb-post-scheduler'),
                    'aiError' => __('Kunne ikke generere tekst med AI. Tjek dine indstillinger og prøv igen.', 'fb-post-scheduler'),
                    'clearRecordConfirm' => __('Er du sikker på, at du vil slette disse opslagsinformationer? Opslaget kan herefter planlægges igen.', 'fb-post-scheduler'),
                    'mentionSearching' => __('Søger Facebook-sider…', 'fb-post-scheduler'),
                    'mentionNoResults' => __('Ingen Facebook-sider fundet', 'fb-post-scheduler'),
                    'mentionError' => __('Kunne ikke søge efter Facebook-sider.', 'fb-post-scheduler'),
                    'savedMentionSearching' => __('Søger gemte sider…', 'fb-post-scheduler'),
                    'savedMentionNoResults' => __('Ingen gemte sider fundet', 'fb-post-scheduler'),
                    'savedMentionError' => __('Kunne ikke søge i gemte sider.', 'fb-post-scheduler'),
                    'savedPageAddError' => __('Kunne ikke tilføje siden.', 'fb-post-scheduler'),
                    'savedPageRemoveConfirm' => __('Fjern denne gemte side?', 'fb-post-scheduler'),
                    'savedPagesEmpty' => __('Ingen gemte sider endnu.', 'fb-post-scheduler'),
                    'savedPageRemoveLabel' => __('Fjern', 'fb-post-scheduler'),
                    'previewPlaceholder' => __('Intet preview-billede', 'fb-post-scheduler'),
                    'previewSourceSelected' => __('Valgt preview-billede', 'fb-post-scheduler'),
                    'previewSourceOg' => __('og:image fra siden', 'fb-post-scheduler'),
                    'previewSourceFeatured' => __('Udvalgt billede (fallback)', 'fb-post-scheduler'),
                    'previewSourceFacebook' => __('Facebooks cache', 'fb-post-scheduler'),
                    'previewSourceEmpty' => __('Intet preview-billede', 'fb-post-scheduler'),
                    'facebookPreviewLoading' => __('Henter Facebooks forhåndsvisning…', 'fb-post-scheduler'),
                    'facebookPreviewError' => __('Kunne ikke hente Facebooks forhåndsvisning.', 'fb-post-scheduler'),
                    'facebookNotScraped' => __('Facebook har endnu ikke scrapet denne URL. Prøv at opdatere Facebooks cache.', 'fb-post-scheduler'),
                    'facebookCacheConfirm' => __('Dette opdaterer Facebooks cache for artikel-URL’en. Fortsæt?', 'fb-post-scheduler'),
                    'facebookCacheUpdated' => __('Facebooks cache er opdateret.', 'fb-post-scheduler'),
                    'bulkDeleteConfirm' => __('Slet de valgte opslag? Handlingen kan ikke fortrydes.', 'fb-post-scheduler'),
                    'bulkDeleteNone' => __('Vælg mindst ét opslag.', 'fb-post-scheduler'),
                    'bulkDeleteNoAction' => __('Vælg en massehandling.', 'fb-post-scheduler'),
                )
            );

            if ($hook === 'post.php' || $hook === 'post-new.php') {
                wp_enqueue_script(
                    'fb-post-scheduler-page-mentions',
                    FB_POST_SCHEDULER_URL . 'assets/js/page-mentions.js',
                    array('jquery', 'fb-post-scheduler-admin-js'),
                    FB_POST_SCHEDULER_VERSION,
                    true
                );
            }
            
            // Kun på kalender-siden
            if (strpos($hook, 'fb-post-scheduler-calendar') !== false) {
                // First enqueue the script
                wp_enqueue_script(
                    'fb-post-scheduler-calendar-js',
                    FB_POST_SCHEDULER_URL . 'assets/js/calendar.js',
                    array('jquery'),
                    FB_POST_SCHEDULER_VERSION,
                    true
                );
                
                // Then immediately localize it
                wp_localize_script(
                    'fb-post-scheduler-calendar-js',
                    'fbPostSchedulerData',
                    array(
                        'ajaxurl' => admin_url('admin-ajax.php'),
                        'nonce' => wp_create_nonce('fb-post-scheduler-calendar-nonce'),
                        'debug' => true
                    )
                );
                
                // Add a backup method via inline script in both head and footer
                add_action('admin_head', function() {
                    ?>
                    <script type="text/javascript">
                    /* <![CDATA[ */
                    // Primary fallback - early in head
                    if (typeof window.fbPostSchedulerData === 'undefined') {
                        window.fbPostSchedulerData = {
                            'ajaxurl': '<?php echo admin_url('admin-ajax.php'); ?>',
                            'nonce': '<?php echo wp_create_nonce('fb-post-scheduler-calendar-nonce'); ?>',
                            'debug': true,
                            'fallback': true,
                            'created': 'head'
                        };
                    }
                    /* ]]> */
                    </script>
                    <?php
                }, 1);
                
                add_action('admin_footer', function() {
                    ?>
                    <script type="text/javascript">
                    /* <![CDATA[ */
                    // Secondary fallback - in footer
                    if (typeof fbPostSchedulerData === 'undefined' && typeof window.fbPostSchedulerData === 'undefined') {
                        window.fbPostSchedulerData = {
                            'ajaxurl': '<?php echo admin_url('admin-ajax.php'); ?>',
                            'nonce': '<?php echo wp_create_nonce('fb-post-scheduler-calendar-nonce'); ?>',
                            'debug': true,
                            'fallback': true,
                            'created': 'footer'
                        };
                    }
                    
                    // Ensure global access
                    if (typeof fbPostSchedulerData === 'undefined' && typeof window.fbPostSchedulerData !== 'undefined') {
                        window.fbPostSchedulerData = window.fbPostSchedulerData;
                    }
                    /* ]]> */
                    </script>
                    <?php
                }, 20);
            }
        }
    }
    
    /**
     * Tilføj CSS klasse til admin menu
     */
    public function admin_body_class( string $classes ): string {
        if (isset($_GET['page']) && strpos($_GET['page'], 'fb-post-scheduler') !== false) {
            $classes .= ' fb-post-scheduler-admin-page';
        }
        return $classes;
    }
    
    /**
     * Tjek planlagte opslag og post dem hvis tiden er kommet
     */
    public function check_scheduled_posts(): void {
        global $wpdb;
        fb_post_scheduler_log('Start tjek af planlagte opslag');
        
        // Dato nu
        $now = current_time('mysql');
        
        // Opret API instance
        $api = new FB_Post_Scheduler_API();
        
        // Tjek for gyldige indstillinger
        if (!$api->validate_credentials()) {
            fb_post_scheduler_log('Fejl: Facebook API indstillinger er ikke gyldige');
            return;
        }
        
        // Få poster med _fb_post_enabled meta
        $args = array(
            'post_type' => $this->selected_post_types,
            'posts_per_page' => -1,
            'meta_query' => array(
                array(
                    'key' => '_fb_post_enabled',
                    'value' => '1',
                    'compare' => '='
                )
            )
        );
        
        $query = new WP_Query($args);
        
        fb_post_scheduler_log('Fandt ' . $query->post_count . ' poster med aktiverede Facebook-opslag');
        
        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $post_id = get_the_ID();
                
                fb_post_scheduler_log('Behandler post: ' . get_the_title() . ' (ID: ' . $post_id . ')', $post_id);
                
                // Få opslag data
                $fb_posts = get_post_meta($post_id, '_fb_posts', true);
                
                if (!empty($fb_posts) && is_array($fb_posts)) {
                    $has_active = false;
                    
                    foreach ($fb_posts as $index => $fb_post) {
                        // Tjek om opslaget er planlagt, aktiveret og tiden er kommet
                        if (
                            isset($fb_post['enabled']) && $fb_post['enabled'] && 
                            (!isset($fb_post['status']) || $fb_post['status'] !== 'posted') &&
                            strtotime($fb_post['date']) <= strtotime($now)
                        ) {
                            fb_post_scheduler_log('Opslag #' . ($index + 1) . ' er klar til at blive postet', $post_id);
                            
                            // VIGTIG: Atomisk "claim" operation for at forhindre duplikater ved race conditions
                            // Kun ét cron job kan succesfuldt opdatere status fra 'scheduled' til 'posting'
                            $table_name = fb_post_scheduler_get_table_name();
                            $rows_updated = $wpdb->query($wpdb->prepare(
                                "UPDATE $table_name
                                 SET status = 'posting'
                                 WHERE post_id = %d
                                   AND post_index = %d
                                   AND status = 'scheduled'",
                                $post_id,
                                $index
                            ));
                            
                            // Hvis ingen rækker blev opdateret, er opslaget allerede håndteret af et andet cron job
                            if ($rows_updated === 0) {
                                fb_post_scheduler_log('Opslag #' . ($index + 1) . ' blev ikke claimed til posting (allerede håndteret af et andet cron job)', $post_id);
                                
                                // Tjek om opslaget er blevet postet for at synkronisere post_meta
                                $db_record = $wpdb->get_row($wpdb->prepare(
                                    "SELECT id, status, fb_post_id FROM $table_name WHERE post_id = %d AND post_index = %d",
                                    $post_id, $index
                                ));
                                
                                if ($db_record && ($db_record->status === 'posted' || $db_record->status === 'posting')) {
                                    // Synkroniser post_meta med database status - behold kun essentielle audit-data
                                    if (!isset($fb_post['status']) || $fb_post['status'] !== 'posted') {
                                        $fb_posts[$index] = array(
                                            'status'      => 'posted',
                                            'posted_date' => $now,
                                            'fb_post_id'  => !empty($db_record->fb_post_id) ? $db_record->fb_post_id : '',
                                            'target_type' => isset($fb_post['target_type']) ? $fb_post['target_type'] : 'page',
                                        );
                                        update_post_meta($post_id, '_fb_posts', $fb_posts);
                                    }
                                }
                                continue;
                            }
                            
                            fb_post_scheduler_log('Opslag #' . ($index + 1) . ' claimed til posting', $post_id);
                            
                            // Post til Facebook
                            $message = $fb_post['text'];
                            $link = get_permalink($post_id);
                            $image_id = isset($fb_post['image_id']) ? absint($fb_post['image_id']) : 0;
                            $image_id = fb_post_scheduler_resolve_image_id($post_id, $image_id);
                            $target_type = isset($fb_post['target_type']) ? $fb_post['target_type'] : 'page';
                            
                            if ($image_id) {
                                fb_post_scheduler_log('Bruger billede ID ' . $image_id . ' som link-preview til opslag #' . ($index + 1), $post_id);
                            } else {
                                fb_post_scheduler_log('Ingen billede fundet til opslag #' . ($index + 1) . ' – poster link uden styret preview-billede', $post_id);
                            }

                            if ($target_type === 'group') {
                                fb_post_scheduler_log('Gruppe-deling er ikke længere mulig via Facebook API. Opslaget postes til siden.', $post_id);
                            }

                            $result = $api->post_to_facebook($message, $link, $image_id, $post_id);
                            
                            if (is_wp_error($result)) {
                                // Log fejl
                                fb_post_scheduler_log('Fejl ved posting til Facebook: ' . $result->get_error_message(), $post_id);
                                
                                // Opdater status til error i både database og post_meta
                                $fb_posts[$index]['status'] = 'error';
                                $fb_posts[$index]['error_message'] = $result->get_error_message();
                                
                                // Opdater også i databasen - enten claim'et record eller opret ny
                                $db_check = $wpdb->get_row($wpdb->prepare(
                                    "SELECT id FROM $table_name WHERE post_id = %d AND post_index = %d",
                                    $post_id, $index
                                ));
                                
                                if ($db_check) {
                                    fb_post_scheduler_update_status($db_check->id, 'error');
                                } else {
                                    // Database record mangler - log advarsel
                                    fb_post_scheduler_log('ADVARSEL: Database record mangler for post_id=' . $post_id . ', index=' . $index . '. Kan ikke opdatere status.', $post_id);
                                }

                                fb_post_scheduler_notify_result($post_id, 'error', $result->get_error_message(), $index);
                            } else {
                                // Opdater database status til posted
                                $db_check = $wpdb->get_row($wpdb->prepare(
                                    "SELECT id FROM $table_name WHERE post_id = %d AND post_index = %d",
                                    $post_id, $index
                                ));
                                
                                if ($db_check) {
                                    fb_post_scheduler_update_status($db_check->id, 'posted', isset($result['id']) ? $result['id'] : '');
                                } else {
                                    // Database record mangler - log advarsel og opret ny
                                    fb_post_scheduler_log('ADVARSEL: Database record mangler for post_id=' . $post_id . ', index=' . $index . '. Opretter ny.', $post_id);
                                    fb_post_scheduler_save_scheduled_post($post_id, $fb_post, $index);
                                    
                                    // Hent den nye record og opdater status
                                    $db_check = $wpdb->get_row($wpdb->prepare(
                                        "SELECT id FROM $table_name WHERE post_id = %d AND post_index = %d",
                                        $post_id, $index
                                    ));
                                    if ($db_check) {
                                        fb_post_scheduler_update_status($db_check->id, 'posted', isset($result['id']) ? $result['id'] : '');
                                    }
                                }
                                
                                // Opdater status til posted i post_meta - behold kun essentielle data
                                $fb_posts[$index] = array(
                                    'status'      => 'posted',
                                    'posted_date' => $now,
                                    'fb_post_id'  => isset($result['id']) ? $result['id'] : '',
                                    'target_type' => 'page',
                                );
                                
                                fb_post_scheduler_log('Opslag blev postet til Facebook. Post ID: ' . (isset($result['id']) ? $result['id'] : 'N/A'), $post_id);

                                fb_post_scheduler_notify_result(
                                    $post_id,
                                    'success',
                                    __('Opslaget blev postet til Facebook.', 'fb-post-scheduler'),
                                    $index
                                );
                                
                                // Opret notifikation
                                do_action('fb_post_scheduler_post_success', $post_id, $fb_post, $index);
                            }
                            
                            // Gem opdateret data
                            update_post_meta($post_id, '_fb_posts', $fb_posts);
                        } else if (isset($fb_post['enabled']) && $fb_post['enabled'] && (!isset($fb_post['status']) || $fb_post['status'] !== 'posted')) {
                            $has_active = true;
                        }
                    }
                    
                    // Fjern _fb_post_enabled meta hvis der ikke er flere aktive opslag
                    if (!$has_active) {
                        delete_post_meta($post_id, '_fb_post_enabled');
                        fb_post_scheduler_log('Ingen flere aktive opslag, _fb_post_enabled meta fjernet', $post_id);
                    }
                }
            }
            
            wp_reset_postdata();
        }
        
        fb_post_scheduler_log('Afsluttet tjek af planlagte opslag');
    }
    
    /**
     * Håndterer manual post check anmodning
     */
    public function process_manual_post_check(): void {
        // Tjek om vi skal behandle opslag
        if (isset($_GET['page']) && $_GET['page'] === 'fb-post-scheduler' && isset($_GET['process_posts']) && $_GET['process_posts'] === 'true') {
            // Tjek permissions
            if (!current_user_can('manage_options')) {
                wp_die(__('Du har ikke tilstrækkelige rettigheder til at udføre denne handling.', 'fb-post-scheduler'));
            }
            
            // Kør tjek af planlagte opslag
            $this->check_scheduled_posts();
            
            // Omdiriger til samme side med succes parameter
            wp_redirect(admin_url('admin.php?page=fb-post-scheduler&posts_processed=true'));
            exit;
        }
    }

    /**
     * Slet planlagte opslag der ved en fejl er knyttet til en revision.
     */
    public function process_delete_revision_schedules(): void {
        if (!isset($_POST['fb_post_scheduler_delete_revision_schedules'])) {
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_die(__('Du har ikke tilstrækkelige rettigheder til at udføre denne handling.', 'fb-post-scheduler'));
        }

        check_admin_referer('fb_post_scheduler_delete_revision_schedules');

        $deleted = fb_post_scheduler_delete_scheduled_revision_posts();

        wp_safe_redirect(add_query_arg(
            array(
                'page' => 'fb-post-scheduler',
                'revision_schedules_deleted' => $deleted,
            ),
            admin_url('admin.php')
        ));
        exit;
    }

    /**
     * Masse-slet valgte rækker fra planlagt- eller postet-listen.
     */
    public function process_bulk_delete_list_rows(): void {
        if (!isset($_POST['fb_bulk_delete_posts'])) {
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_die(__('Du har ikke tilstrækkelige rettigheder til at udføre denne handling.', 'fb-post-scheduler'));
        }

        check_admin_referer('fb_post_scheduler_bulk_delete');

        $action = isset($_POST['fb_bulk_action']) ? sanitize_text_field(wp_unslash($_POST['fb_bulk_action'])) : '';
        $scope = isset($_POST['fb_bulk_scope']) ? sanitize_text_field(wp_unslash($_POST['fb_bulk_scope'])) : 'scheduled';
        $ids = isset($_POST['fb_row_ids']) ? array_map('absint', (array) wp_unslash($_POST['fb_row_ids'])) : array();

        $deleted = 0;
        if ($action === 'delete') {
            $deleted = fb_post_scheduler_bulk_delete_list_rows($ids, $scope);
        }

        wp_safe_redirect(add_query_arg(
            array(
                'page' => 'fb-post-scheduler',
                'bulk_deleted' => $deleted,
            ),
            admin_url('admin.php')
        ));
        exit;
    }

    /**
     * Render WordPress-agtig massehandling over en liste.
     *
     * @param string $scope 'scheduled' eller 'posted'
     */
    private function render_list_bulk_actions(string $scope): void {
        $select_id = 'fb-bulk-action-' . $scope;
        ?>
        <div class="tablenav top fb-list-bulk-nav">
            <div class="alignleft actions bulkactions">
                <label for="<?php echo esc_attr($select_id); ?>" class="screen-reader-text"><?php esc_html_e('Vælg massehandling', 'fb-post-scheduler'); ?></label>
                <select id="<?php echo esc_attr($select_id); ?>" name="fb_bulk_action">
                    <option value=""><?php esc_html_e('Massehandlinger', 'fb-post-scheduler'); ?></option>
                    <option value="delete"><?php esc_html_e('Slet valgte', 'fb-post-scheduler'); ?></option>
                </select>
                <input type="hidden" name="fb_bulk_scope" value="<?php echo esc_attr($scope); ?>">
                <?php wp_nonce_field('fb_post_scheduler_bulk_delete'); ?>
                <button type="submit" name="fb_bulk_delete_posts" value="1" class="button action"><?php esc_html_e('Udfør', 'fb-post-scheduler'); ?></button>
            </div>
        </div>
        <?php
    }
    
    /**
     * Opdater planlagte opslag i databasen
     * 
     * @param int $post_id ID på det oprindelige indlæg
     * @param array $fb_posts Array af Facebook opslag data
     */
    private function update_scheduled_posts_in_database( int $post_id, array $fb_posts ): void {
        if (!is_array($fb_posts)) {
            return;
        }
        
        // Slet planlagte rækker og opret dem igen. Allerede postede rækker bliver stående.
        fb_post_scheduler_delete_unposted_scheduled_posts($post_id);
        
        // Gennemgå alle opslag og opret dem i databasen
        foreach ($fb_posts as $index => $fb_post) {
            // Spring over hvis opslaget ikke er aktiveret eller allerede er postet
            if (!isset($fb_post['enabled']) || !$fb_post['enabled'] || 
                (isset($fb_post['status']) && $fb_post['status'] === 'posted')) {
                continue;
            }
            
            // Gem opslaget i databasen
            fb_post_scheduler_save_scheduled_post($post_id, $fb_post, $index);
        }
    }
    
    /**
     * Post types der viser antal planlagte og postede Facebook-opslag.
     *
     * @return string[]
     */
    private function get_schedule_column_post_types(): array {
        $types = array('post', 'page', 'tribe_events');
        $selected = get_option('fb_post_scheduler_post_types', array());

        if (is_array($selected)) {
            $types = array_merge($types, $selected);
        }

        $types = array_values(array_unique(array_filter($types, 'is_string')));

        return $types;
    }

    /**
     * Kolonne med antal planlagte og postede opslag på indlægsoversigter.
     */
    public function register_schedule_count_columns(): void {
        foreach ($this->get_schedule_column_post_types() as $post_type) {
            add_filter("manage_{$post_type}_posts_columns", array($this, 'add_schedule_count_column'));
            add_action("manage_{$post_type}_posts_custom_column", array($this, 'render_schedule_count_column'), 10, 2);
        }
    }

    /**
     * Indsæt kolonnen før dato.
     *
     * @param array<string, string> $columns
     * @return array<string, string>
     */
    public function add_schedule_count_column(array $columns): array {
        $new_columns = array();

        foreach ($columns as $key => $label) {
            if ($key === 'date') {
                $new_columns['fb_schedule_count'] = __('FB opslag', 'fb-post-scheduler');
            }
            $new_columns[$key] = $label;
        }

        if (!isset($new_columns['fb_schedule_count'])) {
            $new_columns['fb_schedule_count'] = __('FB opslag', 'fb-post-scheduler');
        }

        return $new_columns;
    }

    /**
     * Antal planlagte og postede opslag, med tidspunkter ved hover.
     */
    public function render_schedule_count_column(string $column, int $post_id): void {
        if ($column !== 'fb_schedule_count') {
            return;
        }

        $rows = $this->get_schedule_rows_for_list($post_id);
        $planned = 0;
        $posted = 0;
        $errors = 0;

        foreach ($rows as $row) {
            if ($row->status === 'posted') {
                $posted++;
            } elseif ($row->status === 'error') {
                $errors++;
            } elseif ($row->status === 'scheduled' || $row->status === 'posting') {
                $planned++;
            }
        }

        if ($rows === array()) {
            echo '<span class="fb-schedule-count fb-schedule-count--empty">' . esc_html__('Ingen', 'fb-post-scheduler') . '</span>';
            return;
        }

        $summary = sprintf(
            /* translators: 1: number of scheduled posts, 2: number of published posts */
            __('%1$d planlagt · %2$d postet', 'fb-post-scheduler'),
            $planned,
            $posted
        );

        if ($errors > 0) {
            $summary .= ' · ' . sprintf(
                /* translators: %d: number of failed posts */
                __('%d fejl', 'fb-post-scheduler'),
                $errors
            );
        }
        $panel_id = 'fb-schedule-count-' . $post_id;

        echo '<div class="fb-schedule-count">';
        echo '<button type="button" class="fb-schedule-count__trigger" aria-describedby="' . esc_attr($panel_id) . '">' . esc_html($summary) . '</button>';
        echo '<div id="' . esc_attr($panel_id) . '" class="fb-schedule-count__panel" role="tooltip">';
        echo '<ul>';

        foreach ($rows as $row) {
            $label = $this->get_schedule_status_label((string) $row->status);
            echo '<li><time datetime="' . esc_attr((string) $row->scheduled_time) . '">' . esc_html($this->format_schedule_time((string) $row->scheduled_time)) . '</time>';
            echo '<span class="fb-schedule-count__status fb-schedule-count__status--' . esc_attr(sanitize_html_class((string) $row->status)) . '">' . esc_html($label) . '</span></li>';
        }

        echo '</ul></div></div>';
    }

    /**
     * @return object[]
     */
    private function get_schedule_rows_for_list(int $post_id): array {
        static $cache = array();

        if (!array_key_exists($post_id, $cache)) {
            global $wp_query, $wpdb;

            $ids = array($post_id);
            if (isset($wp_query->posts) && is_array($wp_query->posts)) {
                foreach ($wp_query->posts as $post) {
                    if ($post instanceof WP_Post) {
                        $ids[] = (int) $post->ID;
                    }
                }
            }

            $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
            foreach ($ids as $id) {
                if (!array_key_exists($id, $cache)) {
                    $cache[$id] = array();
                }
            }

            $missing = array_values(array_filter($ids, static function (int $id) use ($cache): bool {
                return $cache[$id] === array();
            }));

            if ($missing !== array()) {
                $table_name = $wpdb->prefix . 'fb_scheduled_posts';
                $placeholders = implode(',', array_fill(0, count($missing), '%d'));
                $sql = "SELECT post_id, scheduled_time, status FROM {$table_name} WHERE post_id IN ($placeholders) ORDER BY scheduled_time ASC";
                $results = $wpdb->get_results($wpdb->prepare($sql, ...$missing));

                if (is_array($results)) {
                    foreach ($results as $row) {
                        $cache[(int) $row->post_id][] = $row;
                    }
                }
            }
        }

        return $cache[$post_id] ?? array();
    }

    /**
     * Lokal visning af et tidspunkt gemt som Y-m-d H:i:s.
     */
    private function format_schedule_time(string $mysql_time): string {
        $timezone = wp_timezone();
        $datetime = date_create_immutable($mysql_time, $timezone);

        if (!$datetime instanceof DateTimeImmutable) {
            return $mysql_time;
        }

        $format = get_option('date_format') . ' ' . get_option('time_format');

        return wp_date($format, $datetime->getTimestamp(), $timezone);
    }

    /**
     * Dansk statusnavn til hover-listen.
     */
    private function get_schedule_status_label(string $status): string {
        switch ($status) {
            case 'posted':
                return __('Postet', 'fb-post-scheduler');
            case 'posting':
                return __('Sender', 'fb-post-scheduler');
            case 'error':
                return __('Fejl', 'fb-post-scheduler');
            default:
                return __('Planlagt', 'fb-post-scheduler');
        }
    }

    /**
     * Tilføjer Facebook share count kolonner til post type oversigter
     */
    public function add_facebook_share_columns(): void {
        // Standard post types
        $post_types = array('post', 'page');
        
        // Tilføj custom post types hvis de eksisterer
        if (post_type_exists('event')) {
            $post_types[] = 'event';
        }
        
        foreach ($post_types as $post_type) {
            // Tilføj kolonne header
            add_filter("manage_{$post_type}_posts_columns", array($this, 'add_facebook_share_column_header'));
            
            // Tilføj kolonne indhold
            add_action("manage_{$post_type}_posts_custom_column", array($this, 'display_facebook_share_column'), 10, 2);
            
            // Gør kolonnen sorterbar
            add_filter("manage_edit-{$post_type}_sortable_columns", array($this, 'make_facebook_share_column_sortable'));
        }
        
        // Håndter sortering
        add_action('pre_get_posts', array($this, 'handle_facebook_share_column_sorting'));
    }
    
    /**
     * Tilføjer Facebook share count kolonne header
     */
    public function add_facebook_share_column_header( array $columns ): array {
        // Indsæt kolonnen før dato-kolonnen
        $new_columns = array();
        foreach ($columns as $key => $value) {
            if ($key === 'date') {
                $new_columns['fb_share_count'] = __('FB Delinger', 'fb-post-scheduler');
            }
            $new_columns[$key] = $value;
        }
        return $new_columns;
    }
    
    /**
     * Viser Facebook share count for en post
     */
    public function display_facebook_share_column( string $column, int $post_id ): void {
        if ($column === 'fb_share_count') {
            // Tjek cache først
            $cached_count = get_transient('fb_share_count_' . $post_id);
            
            if ($cached_count !== false) {
                $share_count = intval($cached_count);
            } else {
                global $wpdb;
                $table_name = $wpdb->prefix . 'fb_scheduled_posts';
                
                // Hent antal gange posten er blevet delt på Facebook
                $share_count = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $table_name WHERE post_id = %d AND status = 'posted'",
                    $post_id
                ));
                
                // Cache resultatet i 1 time
                set_transient('fb_share_count_' . $post_id, $share_count, HOUR_IN_SECONDS);
            }
            
            if ($share_count > 0) {
                echo '<span class="fb-share-count" title="' . sprintf(__('Delt %d gang(e) på Facebook', 'fb-post-scheduler'), $share_count) . '">' . intval($share_count) . '</span>';
            } else {
                echo '<span class="fb-share-count-zero" title="' . __('Ikke delt på Facebook endnu', 'fb-post-scheduler') . '">0</span>';
            }
        }
    }
    
    /**
     * Gør Facebook share count kolonnen sorterbar
     */
    public function make_facebook_share_column_sortable( array $columns ): array {
        $columns['fb_share_count'] = 'fb_share_count';
        return $columns;
    }
    
    /**
     * Håndterer sortering af Facebook share count kolonnen
     */
    public function handle_facebook_share_column_sorting( WP_Query $query ): void {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }
        
        $orderby = $query->get('orderby');
        
        if ($orderby === 'fb_share_count') {
            global $wpdb;
            $table_name = $wpdb->prefix . 'fb_scheduled_posts';
            
            // Join med fb_scheduled_posts tabellen og sorter efter antal
            $query->set('meta_query', array(
                'relation' => 'LEFT',
                array(
                    'key' => '_fb_share_count_cache',
                    'compare' => 'EXISTS'
                )
            ));
            
            // Custom ORDER BY
            add_filter('posts_join', array($this, 'facebook_share_count_join'));
            add_filter('posts_orderby', array($this, 'facebook_share_count_orderby'));
            add_filter('posts_groupby', array($this, 'facebook_share_count_groupby'));
        }
    }
    
    /**
     * JOIN for Facebook share count sortering
     */
    public function facebook_share_count_join( string $join ): string {
        global $wpdb;
        $table_name = $wpdb->prefix . 'fb_scheduled_posts';
        
        $join .= " LEFT JOIN (
            SELECT post_id, COUNT(*) as share_count 
            FROM $table_name 
            WHERE status = 'posted' 
            GROUP BY post_id
        ) fb_shares ON {$wpdb->posts}.ID = fb_shares.post_id";
        
        return $join;
    }
    
    /**
     * ORDER BY for Facebook share count sortering
     */
    public function facebook_share_count_orderby( string $orderby ): string {
        global $wpdb;
        
        $order = isset($_GET['order']) && $_GET['order'] === 'desc' ? 'DESC' : 'ASC';
        $orderby = "COALESCE(fb_shares.share_count, 0) $order";
        
        return $orderby;
    }
    
    /**
     * GROUP BY for Facebook share count sortering
     */
    public function facebook_share_count_groupby( string $groupby ): string {
        global $wpdb;
        
        if (!$groupby) {
            $groupby = "{$wpdb->posts}.ID";
        }
        
        // Remove filters after use to avoid conflicts
        remove_filter('posts_join', array($this, 'facebook_share_count_join'));
        remove_filter('posts_orderby', array($this, 'facebook_share_count_orderby'));
        remove_filter('posts_groupby', array($this, 'facebook_share_count_groupby'));
        
        return $groupby;
    }
    
    /**
     * Rydder Facebook share count cache for en post
     */
    public function clear_facebook_share_cache( int $post_id ): void {
        delete_transient('fb_share_count_' . $post_id);
    }
    
    /**
     * Hook til at rydde cache når en post status opdateres
     */
    public function maybe_clear_share_cache_on_status_update( int $post_id, string $new_status ): void {
        if ($new_status === 'posted') {
            $this->clear_facebook_share_cache($post_id);
        }
    }
    
    /**
     * Tjek for token udløb og vis admin notice hvis nødvendigt
     */
    public function check_token_expiration_notice(): void {
        $token_info = array();
        // Vis kun på plugin sider
        if (!isset($_GET['page']) || strpos($_GET['page'], 'fb-post-scheduler') === false) {
            return;
        }
        
        // Tjek kun en gang per dag for at undgå for mange API kald
        $last_check = get_transient('fb_post_scheduler_token_check_v2');
        if ($last_check !== false) {
            return;
        }
        
        // Sæt transient for at undgå gentagne tjek
        set_transient('fb_post_scheduler_token_check_v2', time(), DAY_IN_SECONDS);
        
        // Tjek token udløb
        $api = new FB_Post_Scheduler_API();
        $token_info = $api->check_token_expiration();
        
        if (is_wp_error($token_info) || !is_array($token_info)) {
            // Vis ikke fejl for token tjek da det kan være forvirrende
            return;
        }
        
        // expires_at 0 betyder at page-tokenet ikke udløber. Det er ikke en advarsel.
        $expires_at = isset($token_info['expires_at']) ? (int) $token_info['expires_at'] : 0;
        if ($expires_at > 0 && !empty($token_info['expires_soon'])) {
            echo '<div class="notice notice-warning is-dismissible">';
            echo '<p><strong>' . __('Facebook Post Scheduler Advarsel:', 'fb-post-scheduler') . '</strong> ';
            echo sprintf(
                __('Dit Facebook access token udløber om %.1f dage (%s). Gå til <a href="%s">indstillinger</a> for at forny det.', 'fb-post-scheduler'),
                $token_info['days_until_expiry'],
                $token_info['expires_date'],
                admin_url('admin.php?page=fb-post-scheduler-settings')
            );
            echo '</p></div>';
        }
    }
    
    /**
     * Forcer meta box position under editoren
     */
    public function force_meta_box_position( string $post_type, string $context, $post ): void {
        global $wp_meta_boxes;
        
        // Tjek om vores meta box eksisterer
        if (isset($wp_meta_boxes[$post_type]['normal']['low']['fb_post_scheduler_meta_box'])) {
            // Gem en reference til vores meta box
            $fb_meta_box = $wp_meta_boxes[$post_type]['normal']['low']['fb_post_scheduler_meta_box'];
            
            // Fjern den fra sin nuværende position
            unset($wp_meta_boxes[$post_type]['normal']['low']['fb_post_scheduler_meta_box']);
            
            // Tilføj den igen som den sidste i 'normal' konteksten
            $wp_meta_boxes[$post_type]['normal']['low']['fb_post_scheduler_meta_box'] = $fb_meta_box;
        }
    }
    
    /**
     * Tilføj CSS klasser til meta box
     */
    public function add_meta_box_classes( array $classes ): array {
        $classes[] = 'fb-post-scheduler-metabox';
        $classes[] = 'postbox-below-editor';
        return $classes;
    }

    /**
     * Ryd Facebook post data på duplikerede posts
     * Forhindrer at planlagte/postede Facebook opslag kopieres ved duplikering.
     * Sletter både post meta og eventuelle poster i scheduled-posts databasetabellen
     * for den nye post, så den starter uden nogen planlagte Facebook-opslag.
     *
     * @param int     $new_post_id   ID på den nye (duplikerede) post
     * @param WP_Post $original_post Det originale post objekt
     */
    public function clear_fb_posts_on_duplicate( int $new_post_id, WP_Post $original_post ): void {
        delete_post_meta($new_post_id, '_fb_posts');
        delete_post_meta($new_post_id, '_fb_post_enabled');
        fb_post_scheduler_delete_scheduled_posts($new_post_id);
    }
}

// Init plugin
add_action('plugins_loaded', array('FB_Post_Scheduler', 'get_instance'));

/**
 * Kør planlagte Facebook-opslag
 */
function fb_post_scheduler_run_scheduled_posts() {
    // Hent plugin instance
    $instance = FB_Post_Scheduler::get_instance();
    
    // Få alle poster som er planlagt til at blive postet nu eller tidligere
    $args = array(
        'post_type' => get_option('fb_post_scheduler_post_types', array()),
        'posts_per_page' => -1,
        'meta_query' => array(
            'relation' => 'AND',
            array(
                'key' => '_fb_post_enabled',
                'value' => '1',
                'compare' => '='
            )
        )
    );
    
    $posts_to_share = new WP_Query($args);
    
    if ($posts_to_share->have_posts()) {
        while ($posts_to_share->have_posts()) {
            $posts_to_share->the_post();
            $post_id = get_the_ID();
            
            // Kør den integrerede scheduling funktion
            $instance->check_scheduled_posts();
        }
        
        wp_reset_postdata();
    }
    
    return true;
}

/**
 * Find billed-ID til et Facebook-opslag.
 *
 * Bruger det valgte billede hvis sat, ellers indlæggets featured image.
 *
 * @param int $post_id WordPress post ID
 * @param int $image_id Valgt attachment ID (0 hvis intet valgt)
 * @return int Attachment ID eller 0
 */
function fb_post_scheduler_resolve_image_id($post_id, $image_id = 0) {
    $image_id = absint($image_id);

    if ($image_id && wp_attachment_is_image($image_id)) {
        return $image_id;
    }

    $thumbnail_id = get_post_thumbnail_id($post_id);
    if ($thumbnail_id && wp_attachment_is_image($thumbnail_id)) {
        return (int) $thumbnail_id;
    }

    return 0;
}

/**
 * Prøv et fejlet opslag igen med det samme.
 *
 * @param int $post_id WordPress post ID.
 * @param int $post_index Indeks i _fb_posts.
 * @return true|WP_Error
 */
function fb_post_scheduler_retry_failed_post($post_id, $post_index) {
    $post_id = absint($post_id);
    $post_index = absint($post_index);
    $fb_posts = get_post_meta($post_id, '_fb_posts', true);

    if (!is_array($fb_posts) || !isset($fb_posts[$post_index]) || !is_array($fb_posts[$post_index])) {
        return new WP_Error(
            'fb_retry_missing',
            __('Opslaget kan ikke prøves igen, fordi det ikke findes på indholdet længere.', 'fb-post-scheduler')
        );
    }

    $fb_post = $fb_posts[$post_index];
    if (isset($fb_post['status']) && $fb_post['status'] === 'posted') {
        return new WP_Error(
            'fb_retry_posted',
            __('Opslaget er allerede postet til Facebook.', 'fb-post-scheduler')
        );
    }

    global $wpdb;
    $table_name = fb_post_scheduler_get_table_name();
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT id, status, message FROM {$table_name} WHERE post_id = %d AND post_index = %d",
        $post_id,
        $post_index
    ));

    if ($row && $row->status === 'posted') {
        return new WP_Error(
            'fb_retry_posted',
            __('Opslaget er allerede postet til Facebook.', 'fb-post-scheduler')
        );
    }

    $text = isset($fb_post['text']) ? $fb_post['text'] : '';
    if ($text === '' && $row && !empty($row->message)) {
        $text = $row->message;
    }
    if ($text === '') {
        return new WP_Error(
            'fb_retry_missing',
            __('Opslaget kan ikke prøves igen, fordi teksten ikke findes på indholdet længere.', 'fb-post-scheduler')
        );
    }

    $image_id = isset($fb_post['image_id']) ? absint($fb_post['image_id']) : 0;
    $ok = fb_post_scheduler_post_to_facebook($post_id, $text, $image_id, 'page', $post_index);

    if ($ok) {
        $fb_id = (string) get_post_meta($post_id, '_fb_post_id', true);
        if ($row) {
            fb_post_scheduler_update_status((int) $row->id, 'posted', $fb_id);
        }
        $fb_posts[$post_index] = array(
            'status' => 'posted',
            'posted_date' => current_time('mysql'),
            'fb_post_id' => $fb_id,
            'target_type' => 'page',
        );
        update_post_meta($post_id, '_fb_posts', $fb_posts);
        return true;
    }

    if ($row) {
        fb_post_scheduler_update_status((int) $row->id, 'error');
    }
    $fb_posts[$post_index]['status'] = 'error';
    update_post_meta($post_id, '_fb_posts', $fb_posts);

    return new WP_Error(
        'fb_retry_failed',
        __('Opslaget blev ikke postet. Se den nye notifikation for fejlbeskeden.', 'fb-post-scheduler')
    );
}

/**
 * Post et opslag til Facebook
 * 
 * @param int $post_id WordPress post ID
 * @param string $fb_text Tekst til Facebook-opslag
 * @param int $image_id (Optional) Image attachment ID
 * @param string $target_type Beholdt for kaldere. Opslag postes altid til siden.
 * @param int|null $post_index Indeks, så en fejl kan prøves igen
 * @return bool True hvis opslaget blev postet, ellers false
 */
function fb_post_scheduler_post_to_facebook($post_id, $fb_text, $image_id = 0, $target_type = 'page', $post_index = null) {
    if (empty($fb_text)) {
        error_log("Facebook Post Scheduler fejl: Tom tekst til opslag");
        return false;
    }
    
    // Få permalink
    $permalink = get_permalink($post_id);
    if (empty($permalink)) {
        error_log("Facebook Post Scheduler fejl: Kunne ikke få permalink til post ID: $post_id");
        return false;
    }

    // Brug valgt billede, ellers featured image
    $image_id = fb_post_scheduler_resolve_image_id($post_id, $image_id);
    
    // Hent API-hjælper
    $api = fb_post_scheduler_get_api();

    if ($target_type === 'group') {
        fb_post_scheduler_log('Gruppe-deling er ikke længere mulig via Facebook API. Opslaget postes til siden.', $post_id);
    }

    $result = $api->post_to_facebook($fb_text, $permalink, $image_id, $post_id);
    
    if (is_wp_error($result)) {
        // Log fejl
        fb_post_scheduler_log('Fejl ved post til Facebook: ' . $result->get_error_message(), $post_id);
        fb_post_scheduler_notify_result($post_id, 'error', $result->get_error_message(), $post_index);
        
        error_log("Facebook Post Scheduler fejl: " . $result->get_error_message());
        return false;
    } else {
        if (isset($result['id'])) {
            // Gem Facebook post ID
            update_post_meta($post_id, '_fb_post_id', $result['id']);
            
            // Log success
            fb_post_scheduler_log('Opslag postet til Facebook med ID: ' . $result['id'], $post_id);
            fb_post_scheduler_notify_result(
                $post_id,
                'success',
                __('Opslaget blev postet til Facebook.', 'fb-post-scheduler'),
                $post_index
            );
            
            return true;
        } else {
            // Log ukendt fejl
            fb_post_scheduler_log('Ukendt fejl ved post til Facebook', $post_id);
            fb_post_scheduler_notify_result(
                $post_id,
                'error',
                __('Der opstod en ukendt fejl ved forsøg på at poste til Facebook.', 'fb-post-scheduler'),
                $post_index
            );
            
            error_log("Facebook Post Scheduler fejl: Ukendt fejl ved post til Facebook");
            return false;
        }
    }
}
