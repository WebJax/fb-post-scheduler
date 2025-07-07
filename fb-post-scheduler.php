<?php
/**
 * Plugin Name: Facebook Post Scheduler
 * Plugin URI: https://jaxweb.dk/fb-post-scheduler
 * Description: Planlæg og administrer Facebook-opslag direkte fra WordPress med automatisk link til indholdet, AI-tekst generering, intelligent billede-håndtering og avanceret administration
 * Version: 1.2.0
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
define('FB_POST_SCHEDULER_VERSION', '1.2.0');

// Inkluder nødvendige filer
require_once FB_POST_SCHEDULER_PATH . 'includes/ajax-handlers.php';
require_once FB_POST_SCHEDULER_PATH . 'includes/api-helper.php';
require_once FB_POST_SCHEDULER_PATH . 'includes/db-helper.php'; 
require_once FB_POST_SCHEDULER_PATH . 'includes/dashboard-widget.php';
require_once FB_POST_SCHEDULER_PATH . 'includes/log.php';
require_once FB_POST_SCHEDULER_PATH . 'includes/notifications.php';
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
    fb_post_scheduler_log('Plugin aktiveret', null, '', 'info');
    
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
}

/**
 * Log funktion
 */
function fb_post_scheduler_log($message, $post_id = null, $fb_post_id = '', $status = 'info') {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'fb_post_scheduler_logs';
    
    // Forbered data til database
    $data = array(
        'post_id' => $post_id ? intval($post_id) : 0,
        'fb_post_id' => sanitize_text_field($fb_post_id),
        'status' => sanitize_text_field($status),
        'message' => sanitize_text_field($message),
        'created_at' => current_time('mysql')
    );
    
    $formats = array('%d', '%s', '%s', '%s', '%s');
    
    // Indsæt i database
    $result = $wpdb->insert($table_name, $data, $formats);
    
    // Fallback til fil hvis database-insert fejler
    if ($result === false) {
        $log_file = FB_POST_SCHEDULER_PATH . 'logs/fb-post-scheduler.log';
        $timestamp = date('Y-m-d H:i:s');
        $post_info = $post_id ? " [Post ID: $post_id]" : "";
        $log_message = "[$timestamp]$post_info $message" . PHP_EOL;
        file_put_contents($log_file, $log_message, FILE_APPEND);
    }
}

/**
 * Få logs fra databasen
 * 
 * @param int $limit Antal logs at hente
 * @param string $status Filter efter status (optional)
 * @param int $post_id Filter efter post ID (optional)
 * @return array Array af log-poster
 */
function fb_post_scheduler_get_logs($limit = 50, $status = '', $post_id = null) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'fb_post_scheduler_logs';
    
    $where_conditions = array();
    $where_values = array();
    
    if (!empty($status)) {
        $where_conditions[] = "status = %s";
        $where_values[] = $status;
    }
    
    if (!empty($post_id)) {
        $where_conditions[] = "post_id = %d";
        $where_values[] = $post_id;
    }
    
    $where_clause = '';
    if (!empty($where_conditions)) {
        $where_clause = ' WHERE ' . implode(' AND ', $where_conditions);
    }
    
    $sql = "SELECT * FROM $table_name$where_clause ORDER BY created_at DESC LIMIT %d";
    $where_values[] = $limit;
    
    return $wpdb->get_results($wpdb->prepare($sql, $where_values));
}

/**
 * Ryd gamle logs fra databasen
 * 
 * @param int $days_old Slet logs ældre end antal dage
 * @return int Antal slettede rækker
 */
function fb_post_scheduler_cleanup_logs($days_old = 30) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'fb_post_scheduler_logs';
    $cutoff_date = date('Y-m-d H:i:s', strtotime("-$days_old days"));
    
    return $wpdb->delete(
        $table_name,
        array('created_at' => $cutoff_date),
        array('created_at' => '<')
    );
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
        
        // Force meta box position for Gutenberg
        add_action('do_meta_boxes', array($this, 'force_meta_box_position'), 10, 3);
        
        // Additional hook for Gutenberg meta box positioning
        add_filter('postbox_classes_post_fb_post_scheduler_meta_box', array($this, 'add_meta_box_classes'));
        add_filter('postbox_classes_page_fb_post_scheduler_meta_box', array($this, 'add_meta_box_classes'));
        
        // WP-Cron hook
        add_action('fb_post_scheduler_check_posts', array($this, 'check_scheduled_posts'));
        
        // Manual process hook
        add_action('admin_init', array($this, 'process_manual_post_check'));
        
        // Check for token expiration warnings
        add_action('admin_notices', array($this, 'check_token_expiration_notice'));
        
        // Tilføj Facebook App ID meta tag til head for bedre Open Graph scraping
        add_action('wp_head', array($this, 'add_facebook_meta_tags'));
        
        // Tilføj skjult billede efter body-tag for Facebook scraper backup
        add_action('wp_body_open', array($this, 'add_hidden_facebook_image'));
        
        // Tilføj Facebook share count columns til post lists
        $this->add_facebook_share_columns();
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
        
        add_submenu_page(
            'fb-post-scheduler',
            __('Logs', 'fb-post-scheduler'),
            __('Logs', 'fb-post-scheduler'),
            'manage_options',
            'fb-post-scheduler-logs',
            'fb_post_scheduler_logs_page'
        );
    }
    
    /**
     * Admin hovedside indhold
     */
    public function admin_page_content() {
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <div class="notice notice-info">
                <p><strong><?php _e('⏰ Hvordan fungerer Facebook Post Scheduler?', 'fb-post-scheduler'); ?></strong></p>
                <ul style="margin-left: 20px;">
                    <li><?php _e('Systemet bruger WordPress cron til at tjekke for planlagte opslag hver time', 'fb-post-scheduler'); ?></li>
                    <li><?php _e('Opslag planlagt til f.eks. 14:00 postes mellem 14:00 og 15:00', 'fb-post-scheduler'); ?></li>
                    <li><?php _e('Facebook finder automatisk det bedste billede på din side når den deles', 'fb-post-scheduler'); ?></li>
                    <li><?php _e('For øjeblikkelig posting kan du bruge "Kør Facebook-opslag nu" knappen', 'fb-post-scheduler'); ?></li>
                </ul>
            </div>
            
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
                    <?php _e('🚀 Kør Facebook-opslag nu', 'fb-post-scheduler'); ?>
                </a>
                <span class="description"><?php _e('Tvinger et øjeblikkeligt tjek af alle planlagte opslag, selvom cron ikke er kørt', 'fb-post-scheduler'); ?></span>
                
                <a href="<?php echo admin_url('admin.php?page=fb-post-scheduler-logs'); ?>" class="button">
                    <?php _e('📋 Se logs', 'fb-post-scheduler'); ?>
                </a>
                <span class="description"><?php _e('Se hvornår cron sidst kørte og alle posting-aktiviteter', 'fb-post-scheduler'); ?></span>
            </div>
            
            <h2><?php _e('Kommende Facebook-opslag', 'fb-post-scheduler'); ?></h2>
            
            <?php
            // Vis information om sidste cron kørsel
            $last_cron_time = get_option('fb_post_scheduler_last_cron_run', '');
            $next_cron_time = wp_next_scheduled('fb_post_scheduler_check_posts');
            
            if ($last_cron_time) {
                $last_run_display = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($last_cron_time));
                echo '<div class="notice notice-success inline" style="margin-bottom: 15px;">';
                echo '<p><strong>⏰ ' . __('Sidste automatiske tjek:', 'fb-post-scheduler') . '</strong> ' . $last_run_display . '</p>';
                
                if ($next_cron_time) {
                    $next_run_display = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $next_cron_time);
                    echo '<p><strong>📅 ' . __('Næste planlagte tjek:', 'fb-post-scheduler') . '</strong> ' . $next_run_display . '</p>';
                }
                echo '</div>';
            } else {
                echo '<div class="notice notice-warning inline" style="margin-bottom: 15px;">';
                echo '<p><strong>⚠️ ' . __('Cron har ikke kørt endnu.', 'fb-post-scheduler') . '</strong> ';
                echo __('Brug "Kør Facebook-opslag nu" for at teste systemet manuelt.', 'fb-post-scheduler') . '</p>';
                echo '</div>';
            }
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
                <table class="widefat striped" id="scheduled-posts-table">
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
                                    <button type="button" class="button button-link-delete fb-delete-scheduled-post" data-post-id="<?php echo $post->post_id; ?>" data-index="<?php echo $post->post_index; ?>" data-scheduled-id="<?php echo $post->id; ?>"><?php _e('Slet', 'fb-post-scheduler'); ?></button>
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
            
            <!-- Open Graph Test Sektion -->
            <div class="fb-og-test-section" style="margin-top: 30px; padding: 20px; border: 1px solid #ddd; border-radius: 5px; background: #f9f9f9;">
                <h2>🔍 <?php _e('Test Facebook Open Graph Scraping', 'fb-post-scheduler'); ?></h2>
                <p><?php _e('Test hvordan Facebook ser en specifik side på din hjemmeside og hvilke Open Graph data den finder.', 'fb-post-scheduler'); ?></p>
                
                <table class="form-table" style="background: white; padding: 15px; border-radius: 3px;">
                    <tr>
                        <th scope="row"><?php _e('URL til test:', 'fb-post-scheduler'); ?></th>
                        <td>
                            <input type="url" id="fb-og-test-url" placeholder="https://dianalund.dk/arrangement/..." class="regular-text" />
                            <button type="button" id="fb-test-og-button" class="button button-secondary"><?php _e('Test Open Graph', 'fb-post-scheduler'); ?></button>
                            <p class="description"><?php _e('Indtast URL til en side du vil teste for Open Graph data.', 'fb-post-scheduler'); ?></p>
                        </td>
                    </tr>
                </table>
                
                <div id="fb-og-test-results" style="margin-top: 15px; display: none;">
                    <h3><?php _e('Test Resultater:', 'fb-post-scheduler'); ?></h3>
                    <div id="fb-og-results-content"></div>
                </div>
            </div>
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
        
        // Vis Open Graph information
        $app_id = get_option('fb_post_scheduler_facebook_app_id', '');
        echo '<div class="notice notice-info inline" style="margin-top: 15px;">';
        echo '<h4 style="margin-top: 0;">🔧 ' . __('Open Graph Optimering', 'fb-post-scheduler') . '</h4>';
        
        if (!empty($app_id)) {
            echo '<p>✅ <strong>' . __('Facebook App ID er konfigureret og tilføjes automatisk til alle sider', 'fb-post-scheduler') . '</strong></p>';
            echo '<p>' . __('Dette hjælper Facebook med at identificere din app og forbedrer Open Graph scraping.', 'fb-post-scheduler') . '</p>';
        } else {
            echo '<p>⚠️ <strong>' . __('Facebook App ID mangler', 'fb-post-scheduler') . '</strong></p>';
            echo '<p>' . __('Uden App ID kan Facebook have sværere ved at finde det rigtige billede på dine sider.', 'fb-post-scheduler') . '</p>';
        }
        
        echo '<details style="margin-top: 10px;">';
        echo '<summary style="cursor: pointer; font-weight: bold;">' . __('📖 Sådan forbedrer du Facebook billedvalg', 'fb-post-scheduler') . '</summary>';
        echo '<div style="margin-top: 10px; padding: 10px; background: #f9f9f9;">';
        echo '<ol>';
        echo '<li>' . __('Sørg for at alle indlæg har et <strong>fremhævet billede</strong> (featured image)', 'fb-post-scheduler') . '</li>';
        echo '<li>' . __('Brug billeder på mindst <strong>1200x630 pixels</strong> for bedst kvalitet', 'fb-post-scheduler') . '</li>';
        echo '<li>' . __('Systemet tilføjer automatisk <code>&lt;meta property="fb:app_id"&gt;</code> når App ID er konfigureret', 'fb-post-scheduler') . '</li>';
        echo '<li>' . __('Du kan teste hvordan Facebook ser dine sider med <a href="https://developers.facebook.com/tools/debug/" target="_blank">Facebook Sharing Debugger</a>', 'fb-post-scheduler') . '</li>';
        echo '</ol>';
        echo '</div>';
        echo '</details>';
        echo '</div>';
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
        echo '<p class="description">' . __('App ID tilføjes automatisk som &lt;meta property="fb:app_id"&gt; tag til alle sider for bedre Open Graph scraping.', 'fb-post-scheduler') . '</p>';
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
        echo '<br><br>';
        
        // Token management buttons
        echo '<div class="fb-token-management">';
        echo '<button type="button" id="fb-test-connection" class="button button-secondary">' . __('Test Facebook API Forbindelse', 'fb-post-scheduler') . '</button>';
        echo '<button type="button" id="fb-check-token-expiry" class="button button-secondary" style="margin-left: 10px;">' . __('Tjek Token Udløb', 'fb-post-scheduler') . '</button>';
        echo '<span class="spinner" id="fb-test-spinner" style="float: none; margin-left: 10px;"></span>';
        echo '</div>';
        
        echo '<div id="fb-test-result" style="margin-top: 10px;"></div>';
        
        // Long-term token section
        echo '<div class="fb-longterm-token" style="margin-top: 15px; padding: 15px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">';
        echo '<h4 style="margin-top: 0;">' . __('Long-term Access Token', 'fb-post-scheduler') . '</h4>';
        echo '<p>' . __('Facebook access tokens udløber. Brug funktionen nedenfor til at udveksle dit kort-term token til et long-term token, der er gyldig i 60 dage.', 'fb-post-scheduler') . '</p>';
        
        echo '<label for="fb-short-term-token">' . __('Short-term Access Token:', 'fb-post-scheduler') . '</label><br>';
        echo '<input type="text" id="fb-short-term-token" class="regular-text" placeholder="' . __('Indsæt dit short-term access token her', 'fb-post-scheduler') . '">';
        echo '<br><br>';
        echo '<button type="button" id="fb-exchange-token" class="button button-primary">' . __('Udveksle til Long-term Token', 'fb-post-scheduler') . '</button>';
        echo '<span class="spinner" id="fb-exchange-spinner" style="float: none; margin-left: 10px;"></span>';
        echo '<div id="fb-exchange-result" style="margin-top: 10px;"></div>';
        echo '</div>';
        
        echo '<p class="description">' . __('Brug "Test Facebook API Forbindelse" for at verificere dine indstillinger. Brug "Tjek Token Udløb" for at se hvornår dit token udløber.', 'fb-post-scheduler') . '</p>';
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
    private function is_gutenberg_active() {
        return function_exists('use_block_editor_for_post_type');
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
            <div class="notice notice-info inline">
                <p><strong><?php _e('🕐 Sådan fungerer scheduling:', 'fb-post-scheduler'); ?></strong></p>
                <ul style="margin-left: 20px;">
                    <li><?php _e('Systemet tjekker for planlagte opslag hver time på hele timer (12:00, 13:00, 14:00 osv.)', 'fb-post-scheduler'); ?></li>
                    <li><?php _e('Hvis du planlægger til kl. 14:00, postes opslaget mellem 14:00 og 15:00', 'fb-post-scheduler'); ?></li>
                    <li><?php _e('For øjeblikkelig posting, vælg den aktuelle time eller tidligere', 'fb-post-scheduler'); ?></li>
                </ul>
                <details style="margin-top: 10px;">
                    <summary style="cursor: pointer; font-weight: bold;"><?php _e('📖 Læs mere om hvorfor kun timer kan vælges', 'fb-post-scheduler'); ?></summary>
                    <div style="margin-top: 10px; padding: 10px; background: #f9f9f9; border-radius: 4px;">
                        <p><?php _e('WordPress cron (scheduled tasks) kører kun når nogen besøger din hjemmeside. For at sikre pålidelig posting uden at overbelaste din server, tjekker systemet kun for planlagte opslag en gang i timen.', 'fb-post-scheduler'); ?></p>
                        <p><?php _e('Dette betyder at opslag ikke kan postes på et præcist minut, men kun indenfor den time du vælger. Dette er normalt for de fleste WordPress scheduling-plugins.', 'fb-post-scheduler'); ?></p>
                    </div>
                </details>
                <details style="margin-top: 10px;">
                    <summary style="cursor: pointer; font-weight: bold;"><?php _e('🖼️ Sådan fungerer billeder på Facebook', 'fb-post-scheduler'); ?></summary>
                    <div style="margin-top: 10px; padding: 10px; background: #f9f9f9; border-radius: 4px;">
                        <p><?php _e('Facebook finder automatisk det bedste billede på din side når den deles.', 'fb-post-scheduler'); ?></p>
                        <p><?php _e('For bedst resultat: Sørg for at dit indlæg har et fremhævet billede (featured image) og Open Graph meta tags.', 'fb-post-scheduler'); ?></p>
                        <p><?php _e('Tip: Brug billeder på mindst 1200x630 pixels for bedst kvalitet på Facebook.', 'fb-post-scheduler'); ?></p>
                    </div>
                </details>
            </div>
            
            <div id="fb-posts-container">
                <?php foreach ($fb_posts as $index => $fb_post) : 
                    // Formatér dato til input-felt
                    $date_parts = explode(' ', $fb_post['date']);
                    $date = isset($date_parts[0]) ? $date_parts[0] : date('Y-m-d');
                    $time_part = isset($date_parts[1]) ? $date_parts[1] : '12:00:00';
                    $selected_hour = intval(substr($time_part, 0, 2));
                    
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
                        <input type="date" id="fb_post_date_<?php echo $index; ?>" name="fb_posts[<?php echo $index; ?>][date]" value="<?php echo esc_attr($date); ?>" class="" <?php disabled($is_posted, true); ?>>
                    </p>
                    
                    <p>
                        <label for="fb_post_hour_<?php echo $index; ?>"><?php _e('Time for opslag:', 'fb-post-scheduler'); ?></label>
                        <select id="fb_post_hour_<?php echo $index; ?>" name="fb_posts[<?php echo $index; ?>][hour]" <?php disabled($is_posted, true); ?>>
                            <?php 
                            for ($h = 0; $h < 24; $h++) : 
                                $hour_label = sprintf('%02d:00', $h);
                            ?>
                                <option value="<?php echo $h; ?>" <?php selected($selected_hour, $h); ?>><?php echo $hour_label; ?></option>
                            <?php endfor; ?>
                        </select>
                        <span class="description"><?php _e('⏰ Opslag postes automatisk på den næste hele time efter det planlagte tidspunkt. Vælg f.eks. 14:00 for posting mellem 14:00-15:00.', 'fb-post-scheduler'); ?></span>
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
                        <span class="description"><?php _e('📝 Denne tekst vil blive brugt til Facebook-opslaget. Link til indlægget vil automatisk blive tilføjet, og Facebook finder selv det bedste billede fra siden.', 'fb-post-scheduler'); ?></span>
                    </p>
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
                    <label for="fb_post_hour_{{index}}"><?php _e('Time for opslag:', 'fb-post-scheduler'); ?></label>
                    <select id="fb_post_hour_{{index}}" name="fb_posts[{{index}}][hour]">
                        <?php for ($h = 0; $h < 24; $h++) : 
                            $hour_label = sprintf('%02d:00', $h);
                        ?>
                            <option value="<?php echo $h; ?>"<?php selected($h, 12); ?>><?php echo $hour_label; ?></option>
                        <?php endfor; ?>
                    </select>
                    <span class="description"><?php _e('⏰ Opslag postes automatisk på den næste hele time efter det planlagte tidspunkt. Vælg f.eks. 14:00 for posting mellem 14:00-15:00.', 'fb-post-scheduler'); ?></span>
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
                    <span class="description"><?php _e('📝 Denne tekst vil blive brugt til Facebook-opslaget. Link til indlægget vil automatisk blive tilføjet, og Facebook finder selv det bedste billede fra siden.', 'fb-post-scheduler'); ?></span>
                </p>
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
                $hour = isset($fb_post['hour']) ? absint($fb_post['hour']) : 12;
                
                // Formatér datetime korrekt - brug hele timer
                $datetime = $date . ' ' . sprintf('%02d:00:00', $hour);
                
                $post_data = array(
                    'text' => $text,
                    'date' => $datetime,
                    'enabled' => $enabled
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
     * Enqueue admin scripts og styles
     */
    public function enqueue_admin_scripts($hook) {
        // Indlæs kun på plugin-sider og post-edit skærmen  
        // Hook eksempler: 'toplevel_page_fb-post-scheduler', 'facebook-scheduler_page_fb-post-scheduler-settings'
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
                    'aiError' => __('Kunne ikke generere tekst med AI. Tjek dine indstillinger og prøv igen.', 'fb-post-scheduler')
                )
            );
            
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
        $current_time = current_time('Y-m-d H:i:s');
        $current_time_display = current_time(get_option('date_format') . ' ' . get_option('time_format'));
        
        fb_post_scheduler_log('🕐 Automatisk tjek af planlagte opslag startet kl. ' . $current_time_display, null, '', 'info');
        fb_post_scheduler_log('WordPress cron kører - dette sker normalt hver time når nogen besøger sitet', null, '', 'info');
        
        // Dato nu
        $now = current_time('mysql');
        
        // Opret API instance
        $api = new FB_Post_Scheduler_API();
        
        // Tjek for gyldige indstillinger
        if (!$api->validate_credentials()) {
            fb_post_scheduler_log('Fejl: Facebook API indstillinger er ikke gyldige', null, '', 'error');
            
            // Specifik fejlmeddelelse
            $app_id = get_option('fb_post_scheduler_facebook_app_id', '');
            $app_secret = get_option('fb_post_scheduler_facebook_app_secret', '');
            $page_id = get_option('fb_post_scheduler_facebook_page_id', '');
            $access_token = get_option('fb_post_scheduler_facebook_access_token', '');
            
            if (empty($app_id)) fb_post_scheduler_log('Mangler: Facebook App ID', null, '', 'error');
            if (empty($app_secret)) fb_post_scheduler_log('Mangler: Facebook App Secret', null, '', 'error');
            if (empty($page_id)) fb_post_scheduler_log('Mangler: Facebook Page ID', null, '', 'error');
            if (empty($access_token)) fb_post_scheduler_log('Mangler: Facebook Access Token', null, '', 'error');
            
            return;
        } else {
            fb_post_scheduler_log('Facebook API credentials valideret OK', null, '', 'info');
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
        
        fb_post_scheduler_log('Fandt ' . $query->post_count . ' poster med aktiverede Facebook-opslag', null, '', 'info');
        
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
                            
                            // Log posting
                            fb_post_scheduler_log('Poster med enkel link sharing - Facebook finder automatisk billede på siden', $post_id, '', 'info');
                            
                            // Send til Facebook med post_id for logging
                            fb_post_scheduler_log('Kalder post_to_facebook metode', $post_id, '', 'info');
                            
                            try {
                                $result = $api->post_to_facebook($message, $link, 0, $post_id);
                                fb_post_scheduler_log('post_to_facebook kaldet færdigt', $post_id, '', 'info');
                            } catch (Exception $e) {
                                fb_post_scheduler_log('FATAL: Exception i post_to_facebook: ' . $e->getMessage(), $post_id, '', 'error');
                                $result = new WP_Error('exception', $e->getMessage());
                            } catch (Error $e) {
                                fb_post_scheduler_log('FATAL: PHP Error i post_to_facebook: ' . $e->getMessage(), $post_id, '', 'error');
                                $result = new WP_Error('php_error', $e->getMessage());
                            }
                            
                            if (is_wp_error($result)) {
                                // Log fejl
                                fb_post_scheduler_log('Fejl ved posting til Facebook: ' . $result->get_error_message(), $post_id, '', 'error');
                                
                                // Opdater status til fejl
                                $fb_posts[$index]['status'] = 'error';
                                $fb_posts[$index]['error_message'] = $result->get_error_message();
                            } else {
                                // Opdater status til posted
                                $fb_posts[$index]['status'] = 'posted';
                                $fb_posts[$index]['posted_date'] = $now;
                                $fb_posts[$index]['fb_post_id'] = isset($result['id']) ? $result['id'] : '';
                                
                                fb_post_scheduler_log('Opslag blev postet til Facebook', $post_id, isset($result['id']) ? $result['id'] : '', 'posted');
                                
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
        
        $end_time = current_time(get_option('date_format') . ' ' . get_option('time_format'));
        fb_post_scheduler_log('✅ Tjek af planlagte opslag afsluttet kl. ' . $end_time, null, '', 'info');
        fb_post_scheduler_log('Næste automatiske tjek sker på den næste hele time eller når nogen besøger sitet', null, '', 'info');
        
        // Gem timestamp for sidste cron kørsel
        update_option('fb_post_scheduler_last_cron_run', current_time('mysql'));
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
    
    /**
     * Tilføjer Facebook share count kolonner til post type oversigter
     */
    public function add_facebook_share_columns() {
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
    public function add_facebook_share_column_header($columns) {
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
    public function display_facebook_share_column($column, $post_id) {
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
    public function make_facebook_share_column_sortable($columns) {
        $columns['fb_share_count'] = 'fb_share_count';
        return $columns;
    }
    
    /**
     * Håndterer sortering af Facebook share count kolonnen
     */
    public function handle_facebook_share_column_sorting($query) {
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
    public function facebook_share_count_join($join) {
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
    public function facebook_share_count_orderby($orderby) {
        global $wpdb;
        
        $order = isset($_GET['order']) && $_GET['order'] === 'desc' ? 'DESC' : 'ASC';
        $orderby = "COALESCE(fb_shares.share_count, 0) $order";
        
        return $orderby;
    }
    
    /**
     * GROUP BY for Facebook share count sortering
     */
    public function facebook_share_count_groupby($groupby) {
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
    public function clear_facebook_share_cache($post_id) {
        delete_transient('fb_share_count_' . $post_id);
    }
    
    /**
     * Hook til at rydde cache når en post status opdateres
     */
    public function maybe_clear_share_cache_on_status_update($post_id, $new_status) {
        if ($new_status === 'posted') {
            $this->clear_facebook_share_cache($post_id);
        }
    }
    
    /**
     * Tjek for token udløb og vis admin notice hvis nødvendigt
     */
    public function check_token_expiration_notice() {
        // Vis kun på plugin sider
        if (!isset($_GET['page']) || strpos($_GET['page'], 'fb-post-scheduler') === false) {
            return;
        }
        
        // Tjek om App ID mangler og vis venlig advarsel
        $app_id = get_option('fb_post_scheduler_facebook_app_id', '');
        if (empty($app_id)) {
            echo '<div class="notice notice-info is-dismissible">';
            echo '<p><strong>💡 ' . __('Facebook Post Scheduler Tip:', 'fb-post-scheduler') . '</strong> ';
            echo sprintf(
                __('For bedre Open Graph scraping og billedvalg anbefaler vi at konfigurere dit Facebook App ID i <a href="%s">indstillinger</a>.', 'fb-post-scheduler'),
                admin_url('admin.php?page=fb-post-scheduler-settings')
            );
            echo '</p></div>';
        }
        
        // Tjek kun en gang per dag for at undgå for mange API kald
        $last_check = get_transient('fb_post_scheduler_token_check');
        if ($last_check !== false) {
            return;
        }
        
        // Sæt transient for at undgå gentagne tjek
        set_transient('fb_post_scheduler_token_check', time(), DAY_IN_SECONDS);
        
        // Tjek token udløb
        $api = new FB_Post_Scheduler_API();
        $token_info = $api->check_token_expiration();
        
        if (is_wp_error($token_info)) {
            // Vis ikke fejl for token tjek da det kan være forvirrende
            return;
        }
        
        // Vis advarsel hvis token udløber snart
        if ($token_info['expires_soon']) {
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
    public function force_meta_box_position($post_type, $context, $post) {
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
    public function add_meta_box_classes($classes) {
        $classes[] = 'fb-post-scheduler-metabox';
        $classes[] = 'postbox-below-editor';
        return $classes;
    }
    
    /**
     * Tilføj Facebook meta tags til head for bedre Open Graph scraping
     */
    public function add_facebook_meta_tags() {
        // Hent Facebook App ID fra indstillinger
        $app_id = get_option('fb_post_scheduler_facebook_app_id', '');
        
        // Tilføj App ID meta tag hvis konfigureret
        if (!empty($app_id)) {
            echo '<meta property="fb:app_id" content="' . esc_attr($app_id) . '" />' . "\n";
            echo '<!-- Facebook Post Scheduler: App ID for bedre Open Graph scraping -->' . "\n";
            
            // Log til PHP error log for debugging
            error_log('FB Post Scheduler: Added fb:app_id meta tag - ' . $app_id);
        } else {
            // Log manglende App ID
            error_log('FB Post Scheduler: No App ID configured - Open Graph scraping may be sub-optimal');
        }
        
        // Tilføj grundlæggende Open Graph tags hvis ikke allerede tilstede
        if (is_single() || is_page()) {
            global $post;
            
            // Tjek at andre SEO plugins ikke allerede tilføjer OG tags
            if (!has_action('wp_head', 'jetpack_og_tags') && 
                !defined('WPSEO_VERSION') && 
                !class_exists('RankMath\\Head') && 
                !defined('AIOSEOP_VERSION')) {
                
                // Post URL (vigtigt for Facebook scraping)
                $url = get_permalink($post->ID);
                if ($url && !$this->has_og_url_tag()) {
                    echo '<meta property="og:url" content="' . esc_url($url) . '" />' . "\n";
                }
                
                // Post titel
                $title = get_the_title($post->ID);
                if ($title && !$this->has_og_title_tag()) {
                    echo '<meta property="og:title" content="' . esc_attr($title) . '" />' . "\n";
                }
                
                // Post type
                if (!$this->has_og_type_tag()) {
                    echo '<meta property="og:type" content="article" />' . "\n";
                }
                
                // Featured image (vigtigst for Facebook billede-deling)
                if (has_post_thumbnail($post->ID) && !$this->has_og_image_tag()) {
                    $image_url = get_the_post_thumbnail_url($post->ID, 'large');
                    if ($image_url) {
                        echo '<meta property="og:image" content="' . esc_url($image_url) . '" />' . "\n";
                        
                        // Tilføj billede dimensioner hvis tilgængelige
                        $image_id = get_post_thumbnail_id($post->ID);
                        if ($image_id) {
                            $image_meta = wp_get_attachment_metadata($image_id);
                            if (isset($image_meta['width']) && isset($image_meta['height'])) {
                                echo '<meta property="og:image:width" content="' . esc_attr($image_meta['width']) . '" />' . "\n";
                                echo '<meta property="og:image:height" content="' . esc_attr($image_meta['height']) . '" />' . "\n";
                            }
                        }
                    }
                }
                
                // Post beskrivelse
                $description = get_the_excerpt($post->ID);
                if (empty($description) && !empty($post->post_content)) {
                    $description = wp_trim_words(strip_tags($post->post_content), 30);
                }
                if ($description && !$this->has_og_description_tag()) {
                    echo '<meta property="og:description" content="' . esc_attr($description) . '" />' . "\n";
                }
                
                echo '<!-- Facebook Post Scheduler: Grundlæggende Open Graph tags for bedre deling -->' . "\n";
            }
        }
    }
    
    /**
     * Tilføj skjult billede efter body-tag for Facebook scraper backup
     * 
     * Dette sikrer at Facebook's scraper altid finder featured image,
     * selv hvis Open Graph tags ikke er optimale
     */
    public function add_hidden_facebook_image() {
        // Kun på enkelt posts og sider
        if (!is_single() && !is_page()) {
            return;
        }
        
        global $post;
        
        // Tjek om der er et featured image
        if (!has_post_thumbnail($post->ID)) {
            return;
        }
        
        // Hent featured image URL
        $image_url = get_the_post_thumbnail_url($post->ID, 'large');
        
        if (!$image_url) {
            return;
        }
        
        // Indsæt skjult billede-tag (helt skjult, påvirker ikke layout)
        echo sprintf(
            '<!-- Facebook Post Scheduler: Skjult billede for scraper backup -->%s<img src="%s" alt="Facebook Scraper Backup Image" style="position:absolute;top:-9999px;left:-9999px;width:1px;height:1px;visibility:hidden;opacity:0;" id="fb-scraper-backup-%d" />%s',
            "\n",
            esc_url($image_url),
            $post->ID,
            "\n"
        );
        
        // Log til debug
        error_log('FB Post Scheduler: Added hidden backup image for post ' . $post->ID . ' - ' . $image_url);
    }

    /**
     * Hjælpefunktioner til at tjekke om Open Graph tags allerede findes
     */
    private function has_og_url_tag() {
        return $this->og_tag_exists('og:url');
    }
    
    private function has_og_title_tag() {
        return $this->og_tag_exists('og:title');
    }
    
    private function has_og_type_tag() {
        return $this->og_tag_exists('og:type');
    }
    
    private function has_og_image_tag() {
        return $this->og_tag_exists('og:image');
    }
    
    private function has_og_description_tag() {
        return $this->og_tag_exists('og:description');
    }
    
    private function og_tag_exists($property) {
        // Simpel tjek - i praksis vil andre SEO plugins normalt være aktive
        return false;
    }
}

/**
 * AJAX handler til at teste Facebook Open Graph scraping
 */
add_action('wp_ajax_fb_test_og_scraping', 'fb_test_og_scraping_ajax');
function fb_test_og_scraping_ajax() {
    // Tjek nonce og permissions
    if (!wp_verify_nonce($_POST['nonce'], 'fb_post_scheduler_nonce') || !current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    
    $url = isset($_POST['url']) ? esc_url_raw($_POST['url']) : '';
    
    if (empty($url)) {
        wp_send_json_error('Ingen URL angivet');
    }
    
    // Få App ID og Access Token
    $app_id = get_option('fb_post_scheduler_facebook_app_id', '');
    $access_token = get_option('fb_post_scheduler_facebook_access_token', '');
    
    if (empty($app_id) || empty($access_token)) {
        wp_send_json_error('Facebook App ID eller Access Token mangler');
    }
    
    // Test Facebook Graph API scraping af URL
    $api_url = 'https://graph.facebook.com/v17.0/?id=' . urlencode($url) . '&fields=og_object{title,description,image}&access_token=' . urlencode($access_token);
    
    $response = wp_remote_get($api_url, array(
        'timeout' => 30,
        'headers' => array(
            'User-Agent' => 'WordPress/Facebook-Post-Scheduler'
        )
    ));
    
    if (is_wp_error($response)) {
        wp_send_json_error('Fejl ved kald til Facebook API: ' . $response->get_error_message());
    }
    
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    
    if (isset($data['error'])) {
        wp_send_json_error('Facebook API fejl: ' . $data['error']['message']);
    }
    
    wp_send_json_success(array(
        'data' => $data,
        'api_url' => $api_url,
        'message' => 'Test gennemført - se hvad Facebook finder på din side'
    ));
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
 * @param int $image_id (Ikke brugt - bevaret for kompatibilitet)
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
    
    // Post til Facebook med enkel link sharing
    $result = $api->post_to_facebook($fb_text, $permalink, 0, $post_id);
    
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
