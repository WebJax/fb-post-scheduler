<?php
/**
 * AJAX-håndtering for Facebook Post Scheduler
 * 
 * Håndterer AJAX-kald til hentning af data til kalender
 */

// Hvis denne fil kaldes direkte, så afbryd
if (!defined('ABSPATH')) {
    exit;
}

/**
 * AJAX-handler til at hente kalenderhændelser
 */
function fb_post_scheduler_get_events() {
    // Tjek nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'fb-post-scheduler-calendar-nonce')) {
        wp_send_json_error('Ugyldig sikkerhedsnøgle');
        exit;
    }
    
    // Tjek brugerrettigheder
    if (!current_user_can('edit_posts')) {
        wp_send_json_error('Utilstrækkelige rettigheder');
        exit;
    }
    
    // Få måned og år fra anmodning
    $year = isset($_POST['year']) ? intval($_POST['year']) : date('Y');
    $month = isset($_POST['month']) ? intval($_POST['month']) : date('n');
    $view_type = isset($_POST['view_type']) ? sanitize_text_field($_POST['view_type']) : 'month';
    
    // Få første og sidste dag baseret på visningstype
    if ($view_type === 'week') {
        $week = isset($_POST['week']) ? intval($_POST['week']) : date('W');
        
        // Få dato for første dag i ugen
        $dto = new DateTime();
        $dto->setISODate($year, $week);
        $start_date = $dto->format('Y-m-d 00:00:00');
        
        // Få dato for sidste dag i ugen
        $dto->modify('+6 days');
        $end_date = $dto->format('Y-m-d 23:59:59');
    } else {
        // Månedlig visning (standard)
        $start_date = $year . '-' . str_pad($month, 2, '0', STR_PAD_LEFT) . '-01 00:00:00';
        $end_date = date('Y-m-t 23:59:59', strtotime($start_date));
    }
    
    // Hent alle planlagte Facebook opslag fra databasen
    $events = array();
    $posts = fb_post_scheduler_get_scheduled_posts($start_date, $end_date);
    
    if (!empty($posts)) {
        foreach ($posts as $post) {
            // Få den tilknyttede originale post
            $linked_post_id = $post->post_id;
            
            // Opdel dato og tid
            $date_parts = explode(' ', $post->scheduled_time);
            $date = isset($date_parts[0]) ? $date_parts[0] : '';
            $time = isset($date_parts[1]) ? substr($date_parts[1], 0, 5) : '';
            
            // Status (alle opslag i databasen er planlagte)
            $status = $post->status;
            
            $events[] = array(
                'post_id' => $post->id, // ID i databasen
                'linked_post_id' => $linked_post_id,
                'title' => $post->post_title, // Gemt i databasen
                'date' => $date,
                'time' => $time,
                'text' => wp_trim_words($post->message, 15),
                'status' => $status,
                'url' => get_edit_post_link($linked_post_id, 'raw')
            );
        }
    }
    
    wp_send_json_success($events);
    exit;
}
add_action('wp_ajax_fb_post_scheduler_get_events', 'fb_post_scheduler_get_events');

/**
 * AJAX-handler til at generere AI-tekst til Facebook-opslag
 */
function fb_post_scheduler_generate_ai_text_ajax() {
    // Tjek nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'fb-post-scheduler-ai-nonce')) {
        wp_send_json_error(array(
            'message' => __('Ugyldig sikkerhedsnøgle', 'fb-post-scheduler')
        ));
        exit;
    }
    
    // Tjek brugerrettigheder
    if (!current_user_can('edit_posts')) {
        wp_send_json_error(array(
            'message' => __('Utilstrækkelige rettigheder', 'fb-post-scheduler')
        ));
        exit;
    }
    
    // Få post ID
    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    if (empty($post_id)) {
        wp_send_json_error(array(
            'message' => __('Ingen post ID angivet', 'fb-post-scheduler')
        ));
        exit;
    }
    
    // Generer tekst med AI
    $result = fb_post_scheduler_generate_ai_text($post_id);
    
    if (is_wp_error($result)) {
        wp_send_json_error(array(
            'message' => $result->get_error_message()
        ));
    } else {
        wp_send_json_success(array(
            'text' => $result
        ));
    }
    
    exit;
}
add_action('wp_ajax_fb_post_scheduler_generate_ai_text', 'fb_post_scheduler_generate_ai_text_ajax');

/**
 * AJAX-handler til at kopiere et planlagt opslag
 */
function fb_post_scheduler_copy_post_ajax() {
    // Tjek nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'fb-post-scheduler-calendar-nonce')) {
        wp_send_json_error(array(
            'message' => __('Ugyldig sikkerhedsnøgle', 'fb-post-scheduler')
        ));
        exit;
    }
    
    // Tjek brugerrettigheder
    if (!current_user_can('edit_posts')) {
        wp_send_json_error(array(
            'message' => __('Utilstrækkelige rettigheder', 'fb-post-scheduler')
        ));
        exit;
    }
    
    // Få post ID fra database
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    if (empty($id)) {
        wp_send_json_error(array(
            'message' => __('Ingen opslag ID angivet', 'fb-post-scheduler')
        ));
        exit;
    }
    
    // Hent det oprindelige opslag fra databasen
    global $wpdb;
    $table_name = $wpdb->prefix . 'fb_scheduled_posts';
    
    $original_post = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_name WHERE id = %d",
        $id
    ));
    
    if (!$original_post) {
        wp_send_json_error(array(
            'message' => __('Opslaget blev ikke fundet', 'fb-post-scheduler')
        ));
        exit;
    }
    
    // Hent alle eksisterende opslag for den samme WordPress post
    $existing_posts = $wpdb->get_results($wpdb->prepare(
        "SELECT post_index FROM $table_name WHERE post_id = %d",
        $original_post->post_id
    ));
    
    // Find det højeste index
    $highest_index = -1;
    foreach ($existing_posts as $post) {
        if ($post->post_index > $highest_index) {
            $highest_index = $post->post_index;
        }
    }
    
    // Ny index er det næste nummer
    $new_index = $highest_index + 1;
    
    // Kopier opslaget med en ny dato (1 dag frem)
    $new_date = date('Y-m-d H:i:s', strtotime($original_post->scheduled_time . ' +1 day'));
    
    $fb_post_data = array(
        'text' => $original_post->message,
        'date' => $new_date,
        'image_id' => $original_post->image_id
    );
    
    // Gem den nye kopi
    $new_id = fb_post_scheduler_save_scheduled_post($original_post->post_id, $fb_post_data, $new_index);
    
    if ($new_id) {
        wp_send_json_success(array(
            'message' => __('Opslaget blev kopieret succesfuldt', 'fb-post-scheduler')
        ));
    } else {
        wp_send_json_error(array(
            'message' => __('Fejl ved kopiering af opslaget', 'fb-post-scheduler')
        ));
    }
    
    exit;
}
add_action('wp_ajax_fb_post_scheduler_copy_post', 'fb_post_scheduler_copy_post_ajax');

/**
 * AJAX-handler til at slette et planlagt opslag
 */
function fb_post_scheduler_delete_post_ajax() {
    // Tjek nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'fb-post-scheduler-calendar-nonce')) {
        wp_send_json_error(array(
            'message' => __('Ugyldig sikkerhedsnøgle', 'fb-post-scheduler')
        ));
        exit;
    }
    
    // Tjek brugerrettigheder
    if (!current_user_can('edit_posts')) {
        wp_send_json_error(array(
            'message' => __('Utilstrækkelige rettigheder', 'fb-post-scheduler')
        ));
        exit;
    }
    
    // Få post ID fra database
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    if (empty($id)) {
        wp_send_json_error(array(
            'message' => __('Ingen opslag ID angivet', 'fb-post-scheduler')
        ));
        exit;
    }
    
    // Slet opslaget fra databasen
    global $wpdb;
    $table_name = $wpdb->prefix . 'fb_scheduled_posts';
    
    $result = $wpdb->delete(
        $table_name,
        array('id' => $id),
        array('%d')
    );
    
    if ($result !== false && $result > 0) {
        wp_send_json_success(array(
            'message' => __('Opslaget blev slettet succesfuldt', 'fb-post-scheduler')
        ));
    } else {
        wp_send_json_error(array(
            'message' => __('Fejl ved sletning af opslaget', 'fb-post-scheduler')
        ));
    }
    
    exit;
}
add_action('wp_ajax_fb_post_scheduler_delete_post', 'fb_post_scheduler_delete_post_ajax');

/**
 * AJAX-handler til at flytte et opslag til en ny dato
 */
function fb_post_scheduler_move_post_ajax() {
    // Tjek nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'fb-post-scheduler-calendar-nonce')) {
        wp_send_json_error(array(
            'message' => __('Ugyldig sikkerhedsnøgle', 'fb-post-scheduler')
        ));
        exit;
    }
    
    // Tjek brugerrettigheder
    if (!current_user_can('edit_posts')) {
        wp_send_json_error(array(
            'message' => __('Utilstrækkelige rettigheder', 'fb-post-scheduler')
        ));
        exit;
    }
    
    // Få og valider parametre
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $new_date = isset($_POST['new_date']) ? sanitize_text_field($_POST['new_date']) : '';
    $new_time = isset($_POST['new_time']) ? sanitize_text_field($_POST['new_time']) : '';
    
    if (!$id || !$new_date) {
        wp_send_json_error(array(
            'message' => __('Manglende parametre for flytning af opslag', 'fb-post-scheduler')
        ));
        exit;
    }
    
    // Valider dato format (YYYY-MM-DD)
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $new_date)) {
        wp_send_json_error(array(
            'message' => __('Ugyldig dato format', 'fb-post-scheduler')
        ));
        exit;
    }
    
    // Hvis ingen tid er angivet, brug nuværende tid fra opslaget
    if (empty($new_time)) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'fb_scheduled_posts';
        
        $current_post = $wpdb->get_row($wpdb->prepare(
            "SELECT scheduled_time FROM $table_name WHERE id = %d",
            $id
        ));
        
        if ($current_post && $current_post->scheduled_time) {
            // Udskift kun dato-delen, behold tiden
            $current_datetime = new DateTime($current_post->scheduled_time);
            $new_time = $current_datetime->format('H:i:s');
        } else {
            // Fallback til nuværende tid
            $new_time = current_time('H:i:s');
        }
    } else {
        // Valider tid format (HH:MM eller HH:MM:SS)
        if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $new_time)) {
            wp_send_json_error(array(
                'message' => __('Ugyldig tid format', 'fb-post-scheduler')
            ));
            exit;
        }
        
        // Tilføj sekunder hvis ikke angivet
        if (strlen($new_time) === 5) {
            $new_time .= ':00';
        }
    }
    
    // Opret ny datetime
    $new_datetime = $new_date . ' ' . $new_time;
    
    // Valider at datoen ikke er i fortiden
    $new_timestamp = strtotime($new_datetime);
    if ($new_timestamp <= current_time('timestamp')) {
        wp_send_json_error(array(
            'message' => __('Du kan ikke flytte et opslag til en dato i fortiden', 'fb-post-scheduler')
        ));
        exit;
    }
    
    // Opdater opslaget i databasen
    global $wpdb;
    $table_name = $wpdb->prefix . 'fb_scheduled_posts';
    
    $result = $wpdb->update(
        $table_name,
        array('scheduled_time' => $new_datetime),
        array('id' => $id),
        array('%s'),
        array('%d')
    );
    
    if ($result !== false) {
        // Få det opdaterede opslag for at returnere de nye data
        $updated_post = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d",
            $id
        ));
        
        if ($updated_post) {
            wp_send_json_success(array(
                'message' => __('Opslaget blev flyttet succesfuldt', 'fb-post-scheduler'),
                'post' => array(
                    'id' => $updated_post->id,
                    'post_id' => $updated_post->post_id,
                    'title' => $updated_post->post_title,
                    'scheduled_time' => $updated_post->scheduled_time,
                    'new_date' => $new_date,
                    'new_time' => date('H:i', strtotime($new_datetime))
                )
            ));
        } else {
            wp_send_json_error(array(
                'message' => __('Opslaget blev opdateret, men kunne ikke hentes', 'fb-post-scheduler')
            ));
        }
    } else {
        wp_send_json_error(array(
            'message' => __('Fejl ved flytning af opslaget. Prøv igen senere.', 'fb-post-scheduler')
        ));
    }
    
    exit;
}
add_action('wp_ajax_fb_post_scheduler_move_post', 'fb_post_scheduler_move_post_ajax');

/**
 * AJAX-handler til at slette et planlagt opslag fra admin listen
 */
function fb_post_scheduler_delete_scheduled_ajax() {
    // Tjek nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'fb_post_scheduler_nonce')) {
        wp_send_json_error(array(
            'message' => __('Ugyldig sikkerhedsnøgle', 'fb-post-scheduler')
        ));
        exit;
    }
    
    // Tjek brugerrettigheder
    if (!current_user_can('edit_posts')) {
        wp_send_json_error(array(
            'message' => __('Utilstrækkelige rettigheder', 'fb-post-scheduler')
        ));
        exit;
    }
    
    // Få parametre
    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    $post_index = isset($_POST['post_index']) ? intval($_POST['post_index']) : 0;
    $scheduled_id = isset($_POST['scheduled_id']) ? intval($_POST['scheduled_id']) : 0;
    
    if (!$post_id || !$scheduled_id) {
        wp_send_json_error(array(
            'message' => __('Ugyldig post ID eller scheduled ID', 'fb-post-scheduler')
        ));
        exit;
    }
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'fb_scheduled_posts';
    
    // Slet det specifikke planlagte opslag
    $deleted = $wpdb->delete(
        $table_name,
        array('id' => $scheduled_id),
        array('%d')
    );
    
    if ($deleted === false) {
        wp_send_json_error(array(
            'message' => __('Kunne ikke slette det planlagte opslag', 'fb-post-scheduler')
        ));
        exit;
    }
    
    // Opdater post meta for at fjerne den planlagte tid for dette index
    $scheduled_times = get_post_meta($post_id, '_fb_scheduled_times', true);
    if (is_array($scheduled_times) && isset($scheduled_times[$post_index])) {
        unset($scheduled_times[$post_index]);
        update_post_meta($post_id, '_fb_scheduled_times', $scheduled_times);
    }
    
    wp_send_json_success(array(
        'message' => __('Planlagt opslag slettet', 'fb-post-scheduler')
    ));
    
    exit;
}
add_action('wp_ajax_fb_post_scheduler_delete_scheduled', 'fb_post_scheduler_delete_scheduled_ajax');

/**
 * AJAX-handler til at teste Facebook API forbindelse
 */
function fb_post_scheduler_test_api_connection_ajax() {
    // Tjek nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'fb_post_scheduler_nonce')) {
        wp_send_json_error(array(
            'message' => __('Ugyldig sikkerhedsnøgle', 'fb-post-scheduler')
        ));
        exit;
    }
    
    // Tjek brugerrettigheder
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array(
            'message' => __('Utilstrækkelige rettigheder', 'fb-post-scheduler')
        ));
        exit;
    }
    
    // Hent API indstillinger
    $app_id = get_option('fb_post_scheduler_facebook_app_id', '');
    $app_secret = get_option('fb_post_scheduler_facebook_app_secret', '');
    $page_id = get_option('fb_post_scheduler_facebook_page_id', '');
    $access_token = get_option('fb_post_scheduler_facebook_access_token', '');
    
    // Tjek om alle felter er udfyldt
    if (empty($app_id) || empty($app_secret) || empty($page_id) || empty($access_token)) {
        wp_send_json_error(array(
            'message' => __('Alle Facebook API felter skal være udfyldt før test kan køres.', 'fb-post-scheduler')
        ));
        exit;
    }
    
    // Test API forbindelse
    require_once FB_POST_SCHEDULER_PATH . 'includes/api-helper.php';
    $api = new FB_Post_Scheduler_API();
    
    // Test 1: Valider access token
    $token_info = $api->validate_access_token();
    if (is_wp_error($token_info)) {
        wp_send_json_error(array(
            'message' => sprintf(__('Access Token fejl: %s', 'fb-post-scheduler'), $token_info->get_error_message())
        ));
        exit;
    }
    
    // Test 2: Hent side information
    $page_info = $api->get_page_info();
    if (is_wp_error($page_info)) {
        wp_send_json_error(array(
            'message' => sprintf(__('Side information fejl: %s', 'fb-post-scheduler'), $page_info->get_error_message())
        ));
        exit;
    }
    
    // Test 3: Tjek om vi kan poste til siden
    $posting_permissions = $api->check_posting_permissions();
    if (is_wp_error($posting_permissions)) {
        wp_send_json_error(array(
            'message' => sprintf(__('Posting tilladelser fejl: %s', 'fb-post-scheduler'), $posting_permissions->get_error_message())
        ));
        exit;
    }
    
    // Alle tests bestået
    $success_message = sprintf(
        __('✅ Facebook API forbindelse er OK!<br><strong>Side:</strong> %s<br><strong>ID:</strong> %s<br><strong>Kategori:</strong> %s<br><strong>Følgere:</strong> %s', 'fb-post-scheduler'),
        isset($page_info['name']) ? esc_html($page_info['name']) : 'N/A',
        isset($page_info['id']) ? esc_html($page_info['id']) : 'N/A',
        isset($page_info['category']) ? esc_html($page_info['category']) : 'N/A',
        isset($page_info['fan_count']) ? number_format($page_info['fan_count']) : 'N/A'
    );
    
    wp_send_json_success(array(
        'message' => $success_message,
        'page_info' => $page_info
    ));
    
    exit;
}
add_action('wp_ajax_fb_post_scheduler_test_api_connection', 'fb_post_scheduler_test_api_connection_ajax');

/**
 * AJAX-handler til at udveksle short-term token til long-term token
 */
function fb_post_scheduler_exchange_token_ajax() {
    // Tjek nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'fb_post_scheduler_nonce')) {
        wp_send_json_error(array(
            'message' => __('Ugyldig sikkerhedsnøgle', 'fb-post-scheduler')
        ));
        exit;
    }
    
    // Tjek brugerrettigheder
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array(
            'message' => __('Utilstrækkelige rettigheder', 'fb-post-scheduler')
        ));
        exit;
    }
    
    // Hent short-term token fra POST data
    $short_term_token = isset($_POST['short_term_token']) ? sanitize_text_field($_POST['short_term_token']) : '';
    
    if (empty($short_term_token)) {
        wp_send_json_error(array(
            'message' => __('Short-term access token er påkrævet', 'fb-post-scheduler')
        ));
        exit;
    }
    
    // Udveksle token
    require_once FB_POST_SCHEDULER_PATH . 'includes/api-helper.php';
    $api = new FB_Post_Scheduler_API();
    
    $result = $api->exchange_for_long_term_token($short_term_token);
    
    if (is_wp_error($result)) {
        wp_send_json_error(array(
            'message' => sprintf(__('Token udveksling fejl: %s', 'fb-post-scheduler'), $result->get_error_message())
        ));
        exit;
    }
    
    // Gem det nye long-term token
    update_option('fb_post_scheduler_facebook_access_token', $result['access_token']);
    
    // Gem udløbsinfo
    update_option('fb_post_scheduler_token_expires_at', $result['expires_at']);
    update_option('fb_post_scheduler_token_expires_date', $result['expires_date']);
    
    wp_send_json_success(array(
        'message' => sprintf(
            __('✅ Long-term access token genereret!<br><strong>Udløber:</strong> %s<br><strong>Gyldig i:</strong> %d dage', 'fb-post-scheduler'),
            $result['expires_date'],
            round($result['expires_in'] / (24 * 60 * 60))
        ),
        'token_info' => $result
    ));
    
    exit;
}
add_action('wp_ajax_fb_post_scheduler_exchange_token', 'fb_post_scheduler_exchange_token_ajax');

/**
 * AJAX-handler til at tjekke token udløb
 */
function fb_post_scheduler_check_token_expiry_ajax() {
    // Tjek nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'fb_post_scheduler_nonce')) {
        wp_send_json_error(array(
            'message' => __('Ugyldig sikkerhedsnøgle', 'fb-post-scheduler')
        ));
        exit;
    }
    
    // Tjek brugerrettigheder
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array(
            'message' => __('Utilstrækkelige rettigheder', 'fb-post-scheduler')
        ));
        exit;
    }
    
    // Tjek token udløb
    require_once FB_POST_SCHEDULER_PATH . 'includes/api-helper.php';
    $api = new FB_Post_Scheduler_API();
    
    $result = $api->check_token_expiration();
    
    if (is_wp_error($result)) {
        wp_send_json_error(array(
            'message' => sprintf(__('Token tjek fejl: %s', 'fb-post-scheduler'), $result->get_error_message())
        ));
        exit;
    }
    
    // Formater besked baseret på udløbsstatus
    if ($result['expires_at'] === null) {
        $message = __('✅ Token udløber aldrig (long-term token)', 'fb-post-scheduler');
        $status = 'success';
    } elseif ($result['expires_soon']) {
        $message = sprintf(
            __('⚠️ Token udløber snart!<br><strong>Udløber:</strong> %s<br><strong>Dage tilbage:</strong> %.1f', 'fb-post-scheduler'),
            $result['expires_date'],
            $result['days_until_expiry']
        );
        $status = 'warning';
    } else {
        $message = sprintf(
            __('✅ Token er gyldigt<br><strong>Udløber:</strong> %s<br><strong>Dage tilbage:</strong> %.1f', 'fb-post-scheduler'),
            $result['expires_date'],
            $result['days_until_expiry']
        );
        $status = 'success';
    }
    
    wp_send_json_success(array(
        'message' => $message,
        'status' => $status,
        'token_info' => $result
    ));
    
    exit;
}
add_action('wp_ajax_fb_post_scheduler_check_token_expiry', 'fb_post_scheduler_check_token_expiry_ajax');

/**
 * AJAX-handler til at gemme bruger access token
 */
function fb_post_scheduler_save_user_token_ajax() {
    // Tjek nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'fb_post_scheduler_nonce')) {
        wp_send_json_error(array(
            'message' => __('Ugyldig sikkerhedsnøgle', 'fb-post-scheduler')
        ));
        exit;
    }
    
    // Tjek brugerrettigheder
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array(
            'message' => __('Utilstrækkelige rettigheder', 'fb-post-scheduler')
        ));
        exit;
    }
    
    // Hent bruger token fra POST data
    $user_token = isset($_POST['user_token']) ? sanitize_text_field($_POST['user_token']) : '';
    
    if (empty($user_token)) {
        wp_send_json_error(array(
            'message' => __('Bruger access token er påkrævet', 'fb-post-scheduler')
        ));
        exit;
    }
    
    // Gem bruger token
    update_option('fb_post_scheduler_facebook_user_token', $user_token);
    
    wp_send_json_success(array(
        'message' => __('✅ Bruger access token gemt!', 'fb-post-scheduler')
    ));
    
    exit;
}
add_action('wp_ajax_fb_post_scheduler_save_user_token', 'fb_post_scheduler_save_user_token_ajax');

/**
 * AJAX-handler til at hente Facebook Pages
 */
function fb_post_scheduler_load_facebook_pages_ajax() {
    // Tjek nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'fb_post_scheduler_nonce')) {
        wp_send_json_error(array(
            'message' => __('Ugyldig sikkerhedsnøgle', 'fb-post-scheduler')
        ));
        exit;
    }
    
    // Tjek brugerrettigheder
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array(
            'message' => __('Utilstrækkelige rettigheder', 'fb-post-scheduler')
        ));
        exit;
    }
    
    // Hent bruger access token
    $user_token = get_option('fb_post_scheduler_facebook_user_token', '');
    
    if (empty($user_token)) {
        wp_send_json_error(array(
            'message' => __('Bruger access token mangler. Gem dit token først.', 'fb-post-scheduler')
        ));
        exit;
    }
    
    // Hent Facebook Pages
    require_once FB_POST_SCHEDULER_PATH . 'includes/api-helper.php';
    $api = new FB_Post_Scheduler_API();
    
    $result = $api->get_user_pages($user_token);
    
    if (is_wp_error($result)) {
        wp_send_json_error(array(
            'message' => sprintf(__('Fejl ved hentning af sider: %s', 'fb-post-scheduler'), $result->get_error_message())
        ));
        exit;
    }
    
    if (empty($result)) {
        wp_send_json_error(array(
            'message' => __('Ingen Facebook-sider fundet. Sørg for at dit token har de korrekte rettigheder.', 'fb-post-scheduler')
        ));
        exit;
    }
    
    wp_send_json_success(array(
        'message' => sprintf(__('✅ Fandt %d Facebook-sider', 'fb-post-scheduler'), count($result)),
        'pages' => $result
    ));
    
    exit;
}
add_action('wp_ajax_fb_post_scheduler_load_facebook_pages', 'fb_post_scheduler_load_facebook_pages_ajax');

/**
 * AJAX-handler til at vælge en Facebook Page og generere page access token
 */
function fb_post_scheduler_select_facebook_page_ajax() {
    // Tjek nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'fb_post_scheduler_nonce')) {
        wp_send_json_error(array(
            'message' => __('Ugyldig sikkerhedsnøgle', 'fb-post-scheduler')
        ));
        exit;
    }
    
    // Tjek brugerrettigheder
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array(
            'message' => __('Utilstrækkelige rettigheder', 'fb-post-scheduler')
        ));
        exit;
    }
    
    // Hent data fra POST
    $page_id = isset($_POST['page_id']) ? sanitize_text_field($_POST['page_id']) : '';
    $page_name = isset($_POST['page_name']) ? sanitize_text_field($_POST['page_name']) : '';
    $page_access_token = isset($_POST['page_access_token']) ? sanitize_text_field($_POST['page_access_token']) : '';
    
    if (empty($page_id) || empty($page_name) || empty($page_access_token)) {
        wp_send_json_error(array(
            'message' => __('Manglende side data', 'fb-post-scheduler')
        ));
        exit;
    }
    
    // Udveksle til long-term page access token
    require_once FB_POST_SCHEDULER_PATH . 'includes/api-helper.php';
    $api = new FB_Post_Scheduler_API();
    
    $long_term_result = $api->exchange_for_page_long_term_token($page_access_token);
    
    if (is_wp_error($long_term_result)) {
        wp_send_json_error(array(
            'message' => sprintf(__('Fejl ved generering af long-term token: %s', 'fb-post-scheduler'), $long_term_result->get_error_message())
        ));
        exit;
    }
    
    // Gem alle nødvendige indstillinger
    update_option('fb_post_scheduler_facebook_page_id', $page_id);
    update_option('fb_post_scheduler_facebook_page_name', $page_name);
    update_option('fb_post_scheduler_facebook_access_token', $long_term_result['access_token']);
    
    // Gem udløbsinfo hvis tilgængelig
    if (isset($long_term_result['expires_at'])) {
        update_option('fb_post_scheduler_token_expires_at', $long_term_result['expires_at']);
        update_option('fb_post_scheduler_token_expires_date', $long_term_result['expires_date']);
    }
    
    wp_send_json_success(array(
        'message' => sprintf(
            __('✅ Facebook-side valgt og konfigureret!<br><strong>Side:</strong> %s<br><strong>Long-term token genereret</strong>', 'fb-post-scheduler'),
            $page_name
        ),
        'page_info' => array(
            'id' => $page_id,
            'name' => $page_name,
            'token_expires' => isset($long_term_result['expires_date']) ? $long_term_result['expires_date'] : 'Aldrig'
        )
    ));
    
    exit;
}
add_action('wp_ajax_fb_post_scheduler_select_facebook_page', 'fb_post_scheduler_select_facebook_page_ajax');

/**
 * AJAX-handler til at forny page access token
 */
function fb_post_scheduler_renew_page_token_ajax() {
    // Tjek nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'fb_post_scheduler_nonce')) {
        wp_send_json_error(array(
            'message' => __('Ugyldig sikkerhedsnøgle', 'fb-post-scheduler')
        ));
        exit;
    }
    
    // Tjek brugerrettigheder
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array(
            'message' => __('Utilstrækkelige rettigheder', 'fb-post-scheduler')
        ));
        exit;
    }
    
    // Hent nuværende indstillinger
    $page_id = get_option('fb_post_scheduler_facebook_page_id', '');
    $user_token = get_option('fb_post_scheduler_facebook_user_token', '');
    
    if (empty($page_id) || empty($user_token)) {
        wp_send_json_error(array(
            'message' => __('Mangler side ID eller bruger token. Sørg for at have valgt en side og gemt dit bruger token.', 'fb-post-scheduler')
        ));
        exit;
    }
    
    // Hent nyt page access token
    require_once FB_POST_SCHEDULER_PATH . 'includes/api-helper.php';
    $api = new FB_Post_Scheduler_API();
    
    // Først hent siden igen for at få nyt access token
    $pages_result = $api->get_user_pages($user_token);
    
    if (is_wp_error($pages_result)) {
        wp_send_json_error(array(
            'message' => sprintf(__('Fejl ved hentning af sider: %s', 'fb-post-scheduler'), $pages_result->get_error_message())
        ));
        exit;
    }
    
    // Find den korrekte side
    $page_access_token = null;
    foreach ($pages_result as $page) {
        if ($page['id'] === $page_id) {
            $page_access_token = $page['access_token'];
            break;
        }
    }
    
    if (!$page_access_token) {
        wp_send_json_error(array(
            'message' => __('Kunne ikke finde den valgte side. Sørg for at siden stadig eksisterer og du har adgang til den.', 'fb-post-scheduler')
        ));
        exit;
    }
    
    // Udveksle til long-term page access token
    $long_term_result = $api->exchange_for_page_long_term_token($page_access_token);
    
    if (is_wp_error($long_term_result)) {
        wp_send_json_error(array(
            'message' => sprintf(__('Fejl ved fornyelse af token: %s', 'fb-post-scheduler'), $long_term_result->get_error_message())
        ));
        exit;
    }
    
    // Opdater access token
    update_option('fb_post_scheduler_facebook_access_token', $long_term_result['access_token']);
    
    // Opdater udløbsinfo hvis tilgængelig
    if (isset($long_term_result['expires_at'])) {
        update_option('fb_post_scheduler_token_expires_at', $long_term_result['expires_at']);
        update_option('fb_post_scheduler_token_expires_date', $long_term_result['expires_date']);
    }
    
    wp_send_json_success(array(
        'message' => sprintf(
            __('✅ Token fornyet!<br><strong>Nyt udløb:</strong> %s', 'fb-post-scheduler'),
            isset($long_term_result['expires_date']) ? $long_term_result['expires_date'] : 'Aldrig'
        ),
        'token_info' => $long_term_result
    ));
    
    exit;
}
add_action('wp_ajax_fb_post_scheduler_renew_page_token', 'fb_post_scheduler_renew_page_token_ajax');