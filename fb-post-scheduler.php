<?php
/**
 * Plugin Name: Facebook Post Scheduler
 * Plugin URI: https://example.com/plugins/fb-post-scheduler
 * Description: Planlæg og administrer Facebook-opslag direkte fra WordPress med automatisk link til indholdet
 * Version: 1.0.0
 * Author: Jacob Thygesen
 * Author URI: https://example.com
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
define('FB_POST_SCHEDULER_VERSION', '1.0.0');

// Definér testmode konstant for lokalt udviklingsmiljø (sæt til false i produktion)
define('FB_POST_SCHEDULER_TEST_MODE', true);

// Inkluder nødvendige filer
require_once FB_POST_SCHEDULER_PATH . 'includes/ajax-handlers.php';
require_once FB_POST_SCHEDULER_PATH . 'includes/api-wrapper.php';
require_once FB_POST_SCHEDULER_PATH . 'includes/db-helper.php'; 
require_once FB_POST_SCHEDULER_PATH . 'includes/dashboard-widget.php';
require_once FB_POST_SCHEDULER_PATH . 'includes/export.php';
require_once FB_POST_SCHEDULER_PATH . 'includes/notifications.php';
require_once FB_POST_SCHEDULER_PATH . 'includes/manual-check.php';
require_once FB_POST_SCHEDULER_PATH . 'includes/ai-helper.php';
require_once FB_POST_SCHEDULER_PATH . 'includes/migration.php';

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
        KEY post_id (post_id),
        KEY status (status),
        KEY scheduled_time (scheduled_time)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($logs_sql);
    dbDelta($scheduled_sql);
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
function fb_post_scheduler_log($message, $post_id = null) {
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
     * Instance af klassen
     */
    private static $instance = null;
    
    /**
     * Valgte post types
     */
    private $selected_post_types = array();
    
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
        
        // WP-Cron hook
        add_action('fb_post_scheduler_check_posts', array($this, 'check_scheduled_posts'));
        
        // Manual process hook
        add_action('admin_init', array($this, 'process_manual_post_check'));
        
        // Init taksonomien
        add_action('init', array($this, 'register_taxonomy'));
    }
    
    /**
     * Singleton pattern - få instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Tilføj admin menu
     */
    public function add_admin_menu() {
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
    public function admin_page_content() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <p><?php _e('Velkommen til Facebook Post Scheduler. Brug denne side til at administrere dine planlagte facebook-opslag.', 'fb-post-scheduler'); ?></p>
            
            <?php
            // Vis succes besked hvis poster er blevet behandlet
            if (isset($_GET['posts_processed']) && $_GET['posts_processed'] === 'true') {
                echo '<div class="notice notice-success is-dismissible"><p>' . __('Planlagte Facebook-opslag er blevet behandlet.', 'fb-post-scheduler') . '</p></div>';
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
                <table class="widefat striped">
                    <thead>
                        <tr>
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
                                <td>
                                    <a href="<?php echo admin_url('post.php?post=' . $post->post_id . '&action=edit'); ?>"><?php echo esc_html($post->post_title); ?></a>
                                </td>
                                <td>
                                    <?php 
                                    $post_type = get_post_type($post->post_id);
                                    echo get_post_type_object($post_type)->labels->singular_name; 
                                    ?>
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
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php
            else :
                ?>
                <p><?php _e('Ingen planlagte Facebook-opslag fundet.', 'fb-post-scheduler'); ?></p>
                <?php
            endif;
            ?>
        </div>
        <?php
    }
    
    /**
     * Indstillingsside indhold
     */
    public function settings_page_content() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('fb_post_scheduler_settings');
                do_settings_sections('fb-post-scheduler-settings');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
    
    /**
     * Kalenderside indhold
     */
    public function calendar_page_content() {
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
    public function register_settings() {
        register_setting(
            'fb_post_scheduler_settings',
            'fb_post_scheduler_post_types'
        );
        
        register_setting(
            'fb_post_scheduler_settings',
            'fb_post_scheduler_facebook_app_id'
        );
        
        register_setting(
            'fb_post_scheduler_settings',
            'fb_post_scheduler_facebook_app_secret'
        );
        
        register_setting(
            'fb_post_scheduler_settings',
            'fb_post_scheduler_facebook_page_id'
        );
        
        register_setting(
            'fb_post_scheduler_settings',
            'fb_post_scheduler_facebook_access_token'
        );
        
        // AI Settings
        register_setting(
            'fb_post_scheduler_settings',
            'fb_post_scheduler_ai_enabled'
        );
        
        register_setting(
            'fb_post_scheduler_settings',
            'fb_post_scheduler_gemini_api_key'
        );
        
        register_setting(
            'fb_post_scheduler_settings',
            'fb_post_scheduler_ai_prompt'
        );
        
        add_settings_section(
            'fb_post_scheduler_post_types_section',
            __('Post Types Indstillinger', 'fb-post-scheduler'),
            array($this, 'post_types_section_callback'),
            'fb-post-scheduler-settings'
        );
        
        add_settings_section(
            'fb_post_scheduler_facebook_section',
            __('Facebook API Indstillinger', 'fb-post-scheduler'),
            array($this, 'facebook_section_callback'),
            'fb-post-scheduler-settings'
        );
        
        add_settings_section(
            'fb_post_scheduler_ai_section',
            __('AI Tekst Generator Indstillinger', 'fb-post-scheduler'),
            array($this, 'ai_section_callback'),
            'fb-post-scheduler-settings'
        );
        
        add_settings_field(
            'fb_post_scheduler_post_types',
            __('Vælg Post Types', 'fb-post-scheduler'),
            array($this, 'post_types_field_callback'),
            'fb-post-scheduler-settings',
            'fb_post_scheduler_post_types_section'
        );
        
        add_settings_field(
            'fb_post_scheduler_facebook_app_id',
            __('Facebook App ID', 'fb-post-scheduler'),
            array($this, 'facebook_app_id_callback'),
            'fb-post-scheduler-settings',
            'fb_post_scheduler_facebook_section'
        );
        
        add_settings_field(
            'fb_post_scheduler_facebook_app_secret',
            __('Facebook App Secret', 'fb-post-scheduler'),
            array($this, 'facebook_app_secret_callback'),
            'fb-post-scheduler-settings',
            'fb_post_scheduler_facebook_section'
        );
        
        add_settings_field(
            'fb_post_scheduler_facebook_page_id',
            __('Facebook Side ID', 'fb-post-scheduler'),
            array($this, 'facebook_page_id_callback'),
            'fb-post-scheduler-settings',
            'fb_post_scheduler_facebook_section'
        );
        
        add_settings_field(
            'fb_post_scheduler_facebook_access_token',
            __('Facebook Access Token', 'fb-post-scheduler'),
            array($this, 'facebook_access_token_callback'),
            'fb-post-scheduler-settings',
            'fb_post_scheduler_facebook_section'
        );
        
        add_settings_field(
            'fb_post_scheduler_ai_enabled',
            __('Aktivér AI tekstgenerering', 'fb-post-scheduler'),
            array($this, 'ai_enabled_callback'),
            'fb-post-scheduler-settings',
            'fb_post_scheduler_ai_section'
        );
        
        add_settings_field(
            'fb_post_scheduler_gemini_api_key',
            __('Google Gemini API Nøgle', 'fb-post-scheduler'),
            array($this, 'gemini_api_key_callback'),
            'fb-post-scheduler-settings',
            'fb_post_scheduler_ai_section'
        );
        
        add_settings_field(
            'fb_post_scheduler_ai_prompt',
            __('AI Prompt Skabelon', 'fb-post-scheduler'),
            array($this, 'ai_prompt_callback'),
            'fb-post-scheduler-settings',
            'fb_post_scheduler_ai_section'
        );
    }
    
    /**
     * Post Types sektion callback
     */
    public function post_types_section_callback() {
        echo '<p>' . __('Vælg hvilke post types der skal have mulighed for at planlægge Facebook-opslag.', 'fb-post-scheduler') . '</p>';
    }
    
    /**
     * Facebook sektion callback
     */
    public function facebook_section_callback() {
        echo '<p>' . __('Indtast dine Facebook API-oplysninger for at aktivere automatisk opslag til Facebook.', 'fb-post-scheduler') . '</p>';
    }
    
    /**
     * AI sektion callback
     */
    public function ai_section_callback() {
        echo '<p>' . __('Konfigurer indstillinger for automatisk generering af Facebook-opslagstekst med Google Gemini AI.', 'fb-post-scheduler') . '</p>';
    }
    
    /**
     * Post Types felt callback
     */
    public function post_types_field_callback() {
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
    public function facebook_app_id_callback() {
        $app_id = get_option('fb_post_scheduler_facebook_app_id', '');
        echo '<input type="text" name="fb_post_scheduler_facebook_app_id" value="' . esc_attr($app_id) . '" class="regular-text">';
    }
    
    /**
     * Facebook App Secret callback
     */
    public function facebook_app_secret_callback() {
        $app_secret = get_option('fb_post_scheduler_facebook_app_secret', '');
        echo '<input type="password" name="fb_post_scheduler_facebook_app_secret" value="' . esc_attr($app_secret) . '" class="regular-text">';
    }
    
    /**
     * Facebook Page ID callback
     */
    public function facebook_page_id_callback() {
        $page_id = get_option('fb_post_scheduler_facebook_page_id', '');
        echo '<input type="text" name="fb_post_scheduler_facebook_page_id" value="' . esc_attr($page_id) . '" class="regular-text">';
    }
    
    /**
     * Facebook Access Token callback
     */
    public function facebook_access_token_callback() {
        $access_token = get_option('fb_post_scheduler_facebook_access_token', '');
        echo '<input type="password" name="fb_post_scheduler_facebook_access_token" value="' . esc_attr($access_token) . '" class="regular-text">';
    }
    
    /**
     * AI enabled callback
     */
    public function ai_enabled_callback() {
        $enabled = get_option('fb_post_scheduler_ai_enabled', '');
        echo '<input type="checkbox" name="fb_post_scheduler_ai_enabled" value="1" ' . checked('1', $enabled, false) . '>';
        echo '<p class="description">' . __('Aktivér for at bruge AI til at generere Facebook-opslagstekst automatisk.', 'fb-post-scheduler') . '</p>';
    }
    
    /**
     * Gemini API key callback
     */
    public function gemini_api_key_callback() {
        $api_key = get_option('fb_post_scheduler_gemini_api_key', '');
        echo '<input type="password" name="fb_post_scheduler_gemini_api_key" value="' . esc_attr($api_key) . '" class="regular-text">';
        echo '<p class="description">' . __('Din Google Gemini API nøgle. Du kan få en fra <a href="https://ai.google.dev/" target="_blank">Google AI Studio</a>.', 'fb-post-scheduler') . '</p>';
    }
    
    /**
     * AI prompt callback
     */
    public function ai_prompt_callback() {
        $default_prompt = __('Skriv et kortfattet og engagerende Facebook-opslag på dansk baseret på følgende indhold. Opslaget skal være mellem 2-3 sætninger og motivere til at læse hele artiklen. Undlad at bruge hashtags. Skriv i en venlig, informativ tone:', 'fb-post-scheduler');
        $prompt = get_option('fb_post_scheduler_ai_prompt', $default_prompt);
        echo '<textarea name="fb_post_scheduler_ai_prompt" rows="4" class="large-text">' . esc_textarea($prompt) . '</textarea>';
        echo '<p class="description">' . __('Prompten som sendes til AI. Brug dette til at styre tonen og stilen i de genererede opslag. Indholdet fra artiklen tilføjes automatisk efter denne prompt.', 'fb-post-scheduler') . '</p>';
    }
    
    /**
     * Tilføj meta boxe til valgte post types
     */
    public function add_meta_boxes() {
        $selected_post_types = get_option('fb_post_scheduler_post_types', array());
        
        if (!empty($selected_post_types)) {
            foreach ($selected_post_types as $post_type) {
                add_meta_box(
                    'fb_post_scheduler_meta_box',
                    __('Facebook Opslag', 'fb-post-scheduler'),
                    array($this, 'render_meta_box'),
                    $post_type,
                    'normal',
                    'high'
                );
            }
        }
    }
    
    /**
     * Render meta box indhold
     */
    public function render_meta_box($post) {
        // Tilføj nonce for sikkerhed
        wp_nonce_field('fb_post_scheduler_meta_box', 'fb_post_scheduler_meta_box_nonce');
        
        // Få gemte metaværdier
        $fb_posts = get_post_meta($post->ID, '_fb_posts', true);
        
        // Hvis der ikke er nogen opslag, oprettes en tom array
        if (empty($fb_posts) || !is_array($fb_posts)) {
            $fb_posts = array(
                array(
                    'text' => '',
                    'date' => date('Y-m-d H:i:s', strtotime('+1 day')),
                    'enabled' => false,
                    'status' => 'scheduled'
                )
            );
        }
        
        ?>
        <div class="fb-post-scheduler-meta-box">
            <div id="fb-posts-container">
                <?php foreach ($fb_posts as $index => $fb_post) : 
                    // Formatér dato til input-felt
                    $date_parts = explode(' ', $fb_post['date']);
                    $date = isset($date_parts[0]) ? $date_parts[0] : date('Y-m-d');
                    $time = isset($date_parts[1]) ? substr($date_parts[1], 0, 5) : '12:00';
                    
                    // Status
                    $is_posted = isset($fb_post['status']) && $fb_post['status'] === 'posted';
                ?>
                <div class="fb-post-item" data-index="<?php echo $index; ?>">
                    <div class="fb-post-header">
                        <h3><?php 
                            if ($is_posted) {
                                echo __('Facebook Opslag #', 'fb-post-scheduler') . ($index + 1) . ' (' . __('Postet', 'fb-post-scheduler') . ')';
                            } else {
                                echo __('Facebook Opslag #', 'fb-post-scheduler') . ($index + 1);
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
                    
                    <p>
                        <label for="fb_post_date_<?php echo $index; ?>"><?php _e('Dato for opslag:', 'fb-post-scheduler'); ?></label>
                        <input type="date" id="fb_post_date_<?php echo $index; ?>" name="fb_posts[<?php echo $index; ?>][date]" value="<?php echo esc_attr($date); ?>" class="widefat" <?php disabled($is_posted, true); ?>>
                    </p>
                    
                    <p>
                        <label for="fb_post_time_<?php echo $index; ?>"><?php _e('Tidspunkt for opslag:', 'fb-post-scheduler'); ?></label>
                        <input type="time" id="fb_post_time_<?php echo $index; ?>" name="fb_posts[<?php echo $index; ?>][time]" value="<?php echo esc_attr($time); ?>" class="widefat" <?php disabled($is_posted, true); ?>>
                    </p>
                    
                    <p>
                        <label for="fb_post_text_<?php echo $index; ?>"><?php _e('Tekst til Facebook-opslag:', 'fb-post-scheduler'); ?></label>
                        <?php if (get_option('fb_post_scheduler_ai_enabled', '') && !$is_posted) : ?>
                        <button type="button" class="button fb-generate-ai-text" data-index="<?php echo $index; ?>" data-post-id="<?php echo $post->ID; ?>">
                            <span class="dashicons dashicons-google" style="vertical-align: text-top;"></span> 
                            <?php _e('Generer tekst med Gemini AI', 'fb-post-scheduler'); ?>
                        </button>
                        <span class="spinner fb-ai-spinner" style="float: none; margin-top: 0;"></span>
                        <?php endif; ?>
                        <textarea id="fb_post_text_<?php echo $index; ?>" name="fb_posts[<?php echo $index; ?>][text]" class="widefat" rows="5" <?php disabled($is_posted, true); ?>><?php echo esc_textarea($fb_post['text']); ?></textarea>
                        <span class="description"><?php _e('Denne tekst vil blive brugt til Facebook-opslaget. Link til indlægget vil automatisk blive tilføjet.', 'fb-post-scheduler'); ?></span>
                    </p>
                    
                    <p class="fb-post-image-field">
                        <label for="fb_post_image_<?php echo $index; ?>"><?php _e('Billede til Facebook-opslag:', 'fb-post-scheduler'); ?></label>
                        <input type="hidden" id="fb_post_image_id_<?php echo $index; ?>" name="fb_posts[<?php echo $index; ?>][image_id]" value="<?php echo isset($fb_post['image_id']) ? esc_attr($fb_post['image_id']) : ''; ?>" <?php disabled($is_posted, true); ?>>
                        <div class="fb-post-image-preview-container">
                            <?php if (!empty($fb_post['image_id'])) : 
                                $image_url = wp_get_attachment_image_url($fb_post['image_id'], 'medium');
                                $image_alt = get_post_meta($fb_post['image_id'], '_wp_attachment_image_alt', true);
                            ?>
                                <img src="<?php echo esc_url($image_url); ?>" alt="<?php echo esc_attr($image_alt); ?>" class="fb-post-image-preview">
                            <?php endif; ?>
                        </div>
                        <button type="button" class="button fb-upload-image" data-index="<?php echo $index; ?>" <?php disabled($is_posted, true); ?>><?php _e('Vælg billede', 'fb-post-scheduler'); ?></button>
                        <?php if (!empty($fb_post['image_id'])) : ?>
                            <button type="button" class="button fb-remove-image" data-index="<?php echo $index; ?>" <?php disabled($is_posted, true); ?>><?php _e('Fjern billede', 'fb-post-scheduler'); ?></button>
                        <?php endif; ?>
                        <span class="description"><?php _e('Vælg et billede der skal bruges til Facebook-opslaget. Hvis du ikke vælger et billede, vil Facebook bruge det første billede fra indlægget.', 'fb-post-scheduler'); ?></span>
                    </p>
                    
                    <div class="fb-post-preview">
                        <h4><?php _e('Forhåndsvisning af opslag', 'fb-post-scheduler'); ?></h4>
                        <div class="fb-post-preview-content">
                            <p class="fb-post-preview-text"><?php echo wp_kses_post($fb_post['text']); ?></p>
                            <div class="fb-post-preview-link">
                                <div class="fb-post-preview-title"><?php echo get_the_title($post->ID); ?></div>
                                <div class="fb-post-preview-url"><?php echo get_permalink($post->ID); ?></div>
                            </div>
                        </div>
                    </div>
                    
                    <?php if ($is_posted) : ?>
                        <input type="hidden" name="fb_posts[<?php echo $index; ?>][status]" value="posted">
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
                    <input type="date" id="fb_post_date_{{index}}" name="fb_posts[{{index}}][date]" value="<?php echo date('Y-m-d', strtotime('+1 day')); ?>" class="widefat">
                </p>
                
                <p>
                    <label for="fb_post_time_{{index}}"><?php _e('Tidspunkt for opslag:', 'fb-post-scheduler'); ?></label>
                    <input type="time" id="fb_post_time_{{index}}" name="fb_posts[{{index}}][time]" value="12:00" class="widefat">
                </p>
                
                <p>
                    <label for="fb_post_text_{{index}}"><?php _e('Tekst til Facebook-opslag:', 'fb-post-scheduler'); ?></label>
                    <?php if (get_option('fb_post_scheduler_ai_enabled', '')) : ?>
                    <button type="button" class="button fb-generate-ai-text" data-index="{{index}}" data-post-id="<?php echo $post->ID; ?>">
                        <span class="dashicons dashicons-google" style="vertical-align: text-top;"></span> 
                        <?php _e('Generer tekst med Gemini AI', 'fb-post-scheduler'); ?>
                    </button>
                    <span class="spinner fb-ai-spinner" style="float: none; margin-top: 0;"></span>
                    <?php endif; ?>
                    <textarea id="fb_post_text_{{index}}" name="fb_posts[{{index}}][text]" class="widefat" rows="5"></textarea>
                    <span class="description"><?php _e('Denne tekst vil blive brugt til Facebook-opslaget. Link til indlægget vil automatisk blive tilføjet.', 'fb-post-scheduler'); ?></span>
                </p>
                
                <p class="fb-post-image-field">
                    <label for="fb_post_image_{{index}}"><?php _e('Billede til Facebook-opslag:', 'fb-post-scheduler'); ?></label>
                    <input type="hidden" id="fb_post_image_id_{{index}}" name="fb_posts[{{index}}][image_id]" value="">
                    <div class="fb-post-image-preview-container"></div>
                    <button type="button" class="button fb-upload-image" data-index="{{index}}"><?php _e('Vælg billede', 'fb-post-scheduler'); ?></button>
                    <span class="description"><?php _e('Vælg et billede der skal bruges til Facebook-opslaget. Hvis du ikke vælger et billede, vil Facebook bruge det første billede fra indlægget.', 'fb-post-scheduler'); ?></span>
                </p>
                
                <div class="fb-post-preview">
                    <h4><?php _e('Forhåndsvisning af opslag', 'fb-post-scheduler'); ?></h4>
                    <div class="fb-post-preview-content">
                        <p class="fb-post-preview-text"></p>
                        <div class="fb-post-preview-link">
                            <div class="fb-post-preview-title"><?php echo get_the_title($post->ID); ?></div>
                            <div class="fb-post-preview-url"><?php echo get_permalink($post->ID); ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </template>
        <?php
    }
    
    /**
     * Gem meta box data
     */
    public function save_meta_box_data($post_id) {
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
            
            foreach ($_POST['fb_posts'] as $index => $fb_post) {
                $enabled = isset($fb_post['enabled']) ? true : false;
                $text = isset($fb_post['text']) ? sanitize_textarea_field($fb_post['text']) : '';
                $date = isset($fb_post['date']) ? sanitize_text_field($fb_post['date']) : '';
                $time = isset($fb_post['time']) ? sanitize_text_field($fb_post['time']) : '';
                $datetime = $date . ' ' . $time . ':00';
                $image_id = isset($fb_post['image_id']) ? absint($fb_post['image_id']) : 0;
                
                $post_data = array(
                    'text' => $text,
                    'date' => $datetime,
                    'enabled' => $enabled,
                    'image_id' => $image_id
                );
                
                // Bevar status og Facebook post ID hvis allerede postet
                if (isset($fb_post['status']) && $fb_post['status'] === 'posted') {
                    $post_data['status'] = 'posted';
                    
                    if (isset($fb_post['fb_post_id'])) {
                        $post_data['fb_post_id'] = sanitize_text_field($fb_post['fb_post_id']);
                    }
                    
                    if (isset($fb_post['posted_date'])) {
                        $post_data['posted_date'] = sanitize_text_field($fb_post['posted_date']);
                    }
                }
                
                $fb_posts[] = $post_data;
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
                // Fjern alle planlagte opslag for denne post fra databasen
                fb_post_scheduler_delete_scheduled_posts($post_id);
            }
        }
    }
    
    /**
     * Registrer taksonomien
     */
    public function register_taxonomy() {
        // Denne taksonomi bruges kun internt til at holde styr på opslag
        register_taxonomy(
            'fb_post_status',
            get_option('fb_post_scheduler_post_types', array()),
            array(
                'hierarchical' => false,
                'public' => false,
                'show_ui' => false,
                'show_in_menu' => false,
                'show_in_nav_menus' => false,
                'show_in_rest' => false,
                'show_tagcloud' => false,
                'show_in_quick_edit' => false,
                'show_admin_column' => false,
            )
        );
    }
    
    /**
     * Registrer custom post type til planlagte Facebook opslag
     * 
     * Denne metode er tom, da vi nu bruger databasetabellen i stedet.
     * Den beholdes for baglæns kompatibilitet.
     */
    public function register_scheduled_posts_post_type() {
        // Tom - vi bruger nu databasetabellen i stedet
        // Custom post type er ikke længere nødvendig
    }

    /**
     * Enqueue admin scripts og styles
     */
    public function enqueue_admin_scripts($hook) {
        // Indlæs kun på plugin-sider og post-edit skærmen
        if (strpos($hook, 'fb-post-scheduler') !== false || $hook == 'post.php' || $hook == 'post-new.php') {
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
                    'ajaxError' => __('Der opstod en fejl ved kommunikation med serveren. Prøv igen senere.', 'fb-post-scheduler'),
                    'aiError' => __('Kunne ikke generere tekst med AI. Tjek dine indstillinger og prøv igen.', 'fb-post-scheduler')
                )
            );
            
            // Kun på kalender-siden
            if (strpos($hook, 'fb-post-scheduler-calendar') !== false) {
                wp_enqueue_script(
                    'fb-post-scheduler-calendar-js',
                    FB_POST_SCHEDULER_URL . 'assets/js/calendar.js',
                    array('jquery'),
                    FB_POST_SCHEDULER_VERSION,
                    true
                );
                
                // Lokalisér script med data
                wp_localize_script(
                    'fb-post-scheduler-calendar-js',
                    'fbPostSchedulerData',
                    array(
                        'ajaxurl' => admin_url('admin-ajax.php'),
                        'nonce' => wp_create_nonce('fb-post-scheduler-calendar-nonce'),
                    )
                );
            }
        }
    }
    
    /**
     * Tilføj CSS klasse til admin menu
     */
    public function admin_body_class($classes) {
        if (isset($_GET['page']) && strpos($_GET['page'], 'fb-post-scheduler') !== false) {
            $classes .= ' fb-post-scheduler-admin-page';
        }
        return $classes;
    }
    
    /**
     * Tjek planlagte opslag og post dem hvis tiden er kommet
     */
    public function check_scheduled_posts() {
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
                            
                            // Post til Facebook
                            $message = $fb_post['text'];
                            $link = get_permalink($post_id);
                            $image_id = isset($fb_post['image_id']) ? $fb_post['image_id'] : 0;
                            
                            // Send til Facebook
                            $result = $api->post_to_facebook($message, $link, $image_id);
                            
                            if (is_wp_error($result)) {
                                // Log fejl
                                fb_post_scheduler_log('Fejl ved posting til Facebook: ' . $result->get_error_message(), $post_id);
                                
                                // Opdater status til fejl
                                $fb_posts[$index]['status'] = 'error';
                                $fb_posts[$index]['error_message'] = $result->get_error_message();
                            } else {
                                // Opdater status til posted
                                $fb_posts[$index]['status'] = 'posted';
                                $fb_posts[$index]['posted_date'] = $now;
                                $fb_posts[$index]['fb_post_id'] = isset($result['id']) ? $result['id'] : '';
                                
                                fb_post_scheduler_log('Opslag blev postet til Facebook. Post ID: ' . (isset($result['id']) ? $result['id'] : 'N/A'), $post_id);
                                
                                // Opret notifikation
                                do_action('fb_post_scheduler_post_success', $post_id, $fb_post, $index);
                                
                                // Fjern opslaget fra databasen
                                fb_post_scheduler_delete_scheduled_post($post_id, $index);
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
    public function process_manual_post_check() {
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
     * Opdater planlagte opslag i kalenderen (Legacy metode for baglæns kompatibilitet)
     * 
     * @param int $post_id ID på det oprindelige indlæg
     * @param array $fb_posts Array af Facebook opslag data
     */
    private function update_scheduled_posts_in_calendar($post_id, $fb_posts) {
        // Brug i stedet den nye database-baserede metode
        $this->update_scheduled_posts_in_database($post_id, $fb_posts);
    }
    
    /**
     * Fjern alle planlagte opslag fra kalenderen for en bestemt post (Legacy metode for baglæns kompatibilitet)
     * 
     * @param int $post_id ID på det oprindelige indlæg
     */
    private function remove_scheduled_posts_from_calendar($post_id) {
        // Brug den nye database-baserede funktion i stedet
        fb_post_scheduler_delete_scheduled_posts($post_id);
    }
    
    /**
     * Fjern et specifikt planlagt opslag fra kalenderen baseret på post ID og index (Legacy metode for baglæns kompatibilitet)
     * 
     * @param int $post_id ID på det oprindelige indlæg
     * @param int $index Index på opslaget i fb_posts array
     */
    private function remove_scheduled_post_with_index($post_id, $index) {
        // Brug den nye database-baserede funktion i stedet
        fb_post_scheduler_delete_scheduled_post($post_id, $index);
        fb_post_scheduler_log('Fjernede planlagt opslag fra databasen (Post ID: ' . $post_id . ', Index: ' . $index . ')', $post_id);
    }
    
    /**
     * Opdater planlagte opslag i databasen
     * 
     * @param int $post_id ID på det oprindelige indlæg
     * @param array $fb_posts Array af Facebook opslag data
     */
    private function update_scheduled_posts_in_database($post_id, $fb_posts) {
        if (!is_array($fb_posts)) {
            return;
        }
        
        // Slet alle eksisterende planlagte opslag for denne post
        fb_post_scheduler_delete_scheduled_posts($post_id);
        
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
 * Post et opslag til Facebook
 * 
 * @param int $post_id WordPress post ID
 * @param string $fb_text Tekst til Facebook-opslag
 * @param int $image_id (Optional) Image attachment ID
 * @return bool True hvis opslaget blev postet, ellers false
 */
function fb_post_scheduler_post_to_facebook($post_id, $fb_text, $image_id = 0) {
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
    
    // Hent API-hjælper
    $api = fb_post_scheduler_get_api();
    
    // Post til Facebook
    $result = $api->post_to_facebook($fb_text, $permalink, $image_id);
    
    if (is_wp_error($result)) {
        // Log fejl
        fb_post_scheduler_log('Fejl ved post til Facebook: ' . $result->get_error_message(), $post_id);
        
        // Tilføj notifikation om fejl
        if (function_exists('fb_post_scheduler_add_notification')) {
            fb_post_scheduler_add_notification(
                sprintf(__('Fejl ved post til Facebook: %s', 'fb-post-scheduler'), get_the_title($post_id)),
                $result->get_error_message(),
                admin_url('post.php?post=' . $post_id . '&action=edit')
            );
        }
        
        error_log("Facebook Post Scheduler fejl: " . $result->get_error_message());
        return false;
    } else {
        if (isset($result['id'])) {
            // Gem Facebook post ID
            update_post_meta($post_id, '_fb_post_id', $result['id']);
            
            // Log success
            fb_post_scheduler_log('Opslag postet til Facebook med ID: ' . $result['id'], $post_id);
            
            // Tilføj notifikation om success
            if (function_exists('fb_post_scheduler_add_notification')) {
                fb_post_scheduler_add_notification(
                    sprintf(__('Opslag postet til Facebook: %s', 'fb-post-scheduler'), get_the_title($post_id)),
                    __('Opslaget blev postet til Facebook med succes.', 'fb-post-scheduler'),
                    admin_url('post.php?post=' . $post_id . '&action=edit')
                );
            }
            
            // Hvis vi er i testmode, returnér resultatet fra API'en
            if (defined('FB_POST_SCHEDULER_TEST_MODE') && FB_POST_SCHEDULER_TEST_MODE) {
                return $result;
            }
            
            return true;
        } else {
            // Log ukendt fejl
            fb_post_scheduler_log('Ukendt fejl ved post til Facebook', $post_id);
            
            // Tilføj notifikation om ukendt fejl
            if (function_exists('fb_post_scheduler_add_notification')) {
                fb_post_scheduler_add_notification(
                    sprintf(__('Fejl ved post til Facebook: %s', 'fb-post-scheduler'), get_the_title($post_id)),
                    __('Der opstod en ukendt fejl ved forsøg på at poste til Facebook.', 'fb-post-scheduler'),
                    admin_url('post.php?post=' . $post_id . '&action=edit')
                );
            }
            
            error_log("Facebook Post Scheduler fejl: Ukendt fejl ved post til Facebook");
            return false;
        }
    }
}