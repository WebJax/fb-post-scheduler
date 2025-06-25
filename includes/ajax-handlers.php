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