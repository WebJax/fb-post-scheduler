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
    
    $start_raw = isset($_POST['start_date']) ? sanitize_text_field(wp_unslash($_POST['start_date'])) : '';
    $end_raw = isset($_POST['end_date']) ? sanitize_text_field(wp_unslash($_POST['end_date'])) : '';
    $has_range = preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_raw) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_raw);

    // Få første og sidste dag baseret på visningstype
    if ($has_range) {
        $start_date = $start_raw . ' 00:00:00';
        $end_date = $end_raw . ' 23:59:59';
    } elseif ($view_type === 'week') {
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
            $image = fb_post_scheduler_get_event_image($linked_post_id, isset($post->image_id) ? (int) $post->image_id : 0, $post->post_title);
            
            $events[] = array(
                'post_id' => $post->id, // ID i databasen
                'linked_post_id' => $linked_post_id,
                'title' => wp_specialchars_decode((string) $post->post_title, ENT_QUOTES),
                'date' => $date,
                'time' => $time,
                'text' => wp_specialchars_decode(wp_trim_words($post->message, 15), ENT_QUOTES),
                'message' => wp_specialchars_decode((string) $post->message, ENT_QUOTES),
                'status' => $status,
                'url' => get_edit_post_link($linked_post_id, 'raw'),
                'image_url' => $image['url'],
                'image_alt' => wp_specialchars_decode($image['alt'], ENT_QUOTES)
            );
        }
    }
    
    wp_send_json_success($events);
    exit;
}
add_action('wp_ajax_fb_post_scheduler_get_events', 'fb_post_scheduler_get_events');

/**
 * Billede til et kalenderkort: udvalgt billede, ellers opslagets eget billede.
 *
 * @param int    $linked_post_id WordPress-indlægget opslaget peger på.
 * @param int    $image_id       Vedhæftning gemt på det planlagte opslag.
 * @param string $title          Titel bruges som alt-tekst når billedet ikke har en.
 * @return array{url:string,alt:string}
 */
function fb_post_scheduler_get_event_image($linked_post_id, $image_id, $title) {
    $attachment_id = 0;

    if (!empty($linked_post_id)) {
        $attachment_id = (int) get_post_thumbnail_id($linked_post_id);
    }

    if ($attachment_id <= 0 && $image_id > 0) {
        $attachment_id = $image_id;
    }

    if ($attachment_id <= 0) {
        return array(
            'url' => '',
            'alt' => ''
        );
    }

    $src = wp_get_attachment_image_url($attachment_id, 'medium');
    $alt = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);

    if (!is_string($alt) || $alt === '') {
        $alt = is_string($title) ? $title : '';
    }

    return array(
        'url' => $src ? esc_url_raw($src) : '',
        'alt' => $alt
    );
}

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
    
    // Kopier opslaget. Uden dato: én dag frem. Med dato: samme klokkeslæt på den dag (Shift-træk i kalenderen).
    $target_date = isset($_POST['new_date']) ? sanitize_text_field(wp_unslash($_POST['new_date'])) : '';

    if ($target_date !== '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $target_date)) {
            wp_send_json_error(array(
                'message' => __('Ugyldig dato format', 'fb-post-scheduler')
            ));
            exit;
        }

        $original_time = '12:00:00';
        if (!empty($original_post->scheduled_time)) {
            $original_datetime = date_create($original_post->scheduled_time);
            if ($original_datetime instanceof DateTime) {
                $original_time = $original_datetime->format('H:i:s');
            }
        }

        $new_date = $target_date . ' ' . $original_time;
        $new_timestamp = strtotime($new_date);

        if ($new_timestamp !== false && $new_timestamp <= current_time('timestamp')) {
            wp_send_json_error(array(
                'message' => __('Du kan ikke kopiere et opslag til en dato i fortiden', 'fb-post-scheduler')
            ));
            exit;
        }
    } else {
        $new_date = date('Y-m-d H:i:s', strtotime($original_post->scheduled_time . ' +1 day'));
    }
    
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
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'fb_scheduled_posts';

    $current_post = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_name WHERE id = %d",
        $id
    ));

    if (!$current_post) {
        wp_send_json_error(array(
            'message' => __('Opslaget blev ikke fundet', 'fb-post-scheduler')
        ));
        exit;
    }

    // Hvis ingen tid er angivet, behold klokkeslættet og skift kun datoen.
    if (empty($new_time)) {
        $current_datetime = date_create($current_post->scheduled_time);
        $new_time = $current_datetime instanceof DateTime
            ? $current_datetime->format('H:i:s')
            : current_time('H:i:s');
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
    
    // Opret ny datetime i webstedets tidszone. scheduled_time gemmes som lokal tid.
    $new_datetime = $new_date . ' ' . $new_time;
    $timezone = wp_timezone();
    $new_dt = date_create_immutable($new_datetime, $timezone);
    $current_dt = ($current_post && !empty($current_post->scheduled_time))
        ? date_create_immutable($current_post->scheduled_time, $timezone)
        : false;
    $now = new DateTimeImmutable('now', $timezone);

    if (!$new_dt instanceof DateTimeImmutable) {
        wp_send_json_error(array(
            'message' => __('Ugyldig dato format', 'fb-post-scheduler')
        ));
        exit;
    }

    // Et opslag der stadig ligger i fremtiden må ikke trækkes til fortiden.
    // Opslag der allerede er forfaldne kan flyttes frit, også til en ny fremtidig dag.
    if ($new_dt <= $now && $current_dt instanceof DateTimeImmutable && $current_dt > $now) {
        wp_send_json_error(array(
            'message' => __('Du kan ikke flytte et opslag til en dato i fortiden', 'fb-post-scheduler')
        ));
        exit;
    }
    
    // Opdater opslaget i databasen
    $result = $wpdb->update(
        $table_name,
        array('scheduled_time' => $new_dt->format('Y-m-d H:i:s')),
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
            fb_post_scheduler_sync_moved_post_meta($updated_post, $new_dt->format('Y-m-d H:i:s'));

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
 * AJAX-handler til at gemme tidspunkt og tekst for et opslag fra kalenderen.
 */
function fb_post_scheduler_update_schedule_ajax() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'fb-post-scheduler-calendar-nonce')) {
        wp_send_json_error(array(
            'message' => __('Ugyldig sikkerhedsnøgle', 'fb-post-scheduler')
        ));
    }

    if (!current_user_can('edit_posts')) {
        wp_send_json_error(array(
            'message' => __('Utilstrækkelige rettigheder', 'fb-post-scheduler')
        ));
    }

    $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
    $new_date = isset($_POST['new_date']) ? sanitize_text_field(wp_unslash($_POST['new_date'])) : '';
    $new_time = isset($_POST['new_time']) ? sanitize_text_field(wp_unslash($_POST['new_time'])) : '';
    $message = isset($_POST['message']) ? sanitize_textarea_field(wp_unslash($_POST['message'])) : '';

    if (!$id || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $new_date) || !preg_match('/^\d{2}:\d{2}$/', $new_time)) {
        wp_send_json_error(array(
            'message' => __('Angiv en gyldig dato og et tidspunkt', 'fb-post-scheduler')
        ));
    }

    if ($message === '') {
        wp_send_json_error(array(
            'message' => __('Skriv en tekst til opslaget', 'fb-post-scheduler')
        ));
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'fb_scheduled_posts';
    $current_post = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_name WHERE id = %d",
        $id
    ));

    if (!$current_post) {
        wp_send_json_error(array(
            'message' => __('Opslaget blev ikke fundet', 'fb-post-scheduler')
        ));
    }

    if (in_array($current_post->status, array('posted', 'posting'), true)) {
        wp_send_json_error(array(
            'message' => __('Opslaget er allerede sendt og kan ikke ændres', 'fb-post-scheduler')
        ));
    }

    $timezone = wp_timezone();
    $new_dt = date_create_immutable($new_date . ' ' . $new_time . ':00', $timezone);
    $current_dt = !empty($current_post->scheduled_time)
        ? date_create_immutable($current_post->scheduled_time, $timezone)
        : false;
    $now = new DateTimeImmutable('now', $timezone);

    if (!$new_dt instanceof DateTimeImmutable) {
        wp_send_json_error(array(
            'message' => __('Ugyldig dato format', 'fb-post-scheduler')
        ));
    }

    if ($new_dt <= $now && $current_dt instanceof DateTimeImmutable && $current_dt > $now) {
        wp_send_json_error(array(
            'message' => __('Du kan ikke lægge et kommende opslag i fortiden', 'fb-post-scheduler')
        ));
    }

    $scheduled_time = $new_dt->format('Y-m-d H:i:s');
    $updated = $wpdb->update(
        $table_name,
        array(
            'scheduled_time' => $scheduled_time,
            'message' => $message,
        ),
        array('id' => $id),
        array('%s', '%s'),
        array('%d')
    );

    if ($updated === false) {
        wp_send_json_error(array(
            'message' => __('Opslaget kunne ikke gemmes. Prøv igen.', 'fb-post-scheduler')
        ));
    }

    $current_post->scheduled_time = $scheduled_time;
    $current_post->message = $message;
    fb_post_scheduler_sync_moved_post_meta($current_post, $scheduled_time, $message);

    wp_send_json_success(array(
        'message' => __('Opslaget blev gemt', 'fb-post-scheduler'),
        'scheduled_time' => $scheduled_time,
        'time' => $new_dt->format('H:i'),
        'date' => $new_dt->format('Y-m-d'),
    ));
}
add_action('wp_ajax_fb_post_scheduler_update_schedule', 'fb_post_scheduler_update_schedule_ajax');

/**
 * Skriver den nye tid tilbage i indlæggets Facebook-meta, så cron bruger samme tid som kalenderen.
 *
 * @param object      $scheduled_post Række fra fb_scheduled_posts.
 * @param string      $new_datetime   Lokal tid i Y-m-d H:i:s.
 * @param string|null $message        Ny opslagstekst, eller null hvis teksten ikke skal ændres.
 */
function fb_post_scheduler_sync_moved_post_meta($scheduled_post, $new_datetime, $message = null) {
    $post_id = isset($scheduled_post->post_id) ? (int) $scheduled_post->post_id : 0;
    $post_index = isset($scheduled_post->post_index) ? (int) $scheduled_post->post_index : -1;

    if ($post_id <= 0 || $post_index < 0) {
        return;
    }

    $fb_posts = get_post_meta($post_id, '_fb_posts', true);
    if (is_array($fb_posts) && isset($fb_posts[$post_index]) && is_array($fb_posts[$post_index])) {
        $fb_posts[$post_index]['date'] = $new_datetime;
        if (is_string($message)) {
            $fb_posts[$post_index]['text'] = $message;
        }
        update_post_meta($post_id, '_fb_posts', $fb_posts);
    }

    $scheduled_times = get_post_meta($post_id, '_fb_scheduled_times', true);
    if (is_array($scheduled_times) && array_key_exists($post_index, $scheduled_times)) {
        $scheduled_times[$post_index] = $new_datetime;
        update_post_meta($post_id, '_fb_scheduled_times', $scheduled_times);
    }
}

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
 * Nøgle til de sider, der blev hentet under den seneste token-udveksling.
 *
 * Side-tokens ligger kun i transienten og sendes ikke til browseren.
 *
 * @return string
 */
function fb_post_scheduler_page_choices_key() {
    return 'fb_ps_page_choices_' . get_current_user_id();
}

/**
 * Gem sider med page-token i kort tid, så valget kan ske uden at sende tokenet til browseren.
 *
 * @param array $pages
 */
function fb_post_scheduler_store_page_choices($pages) {
    set_transient(fb_post_scheduler_page_choices_key(), $pages, 15 * MINUTE_IN_SECONDS);
}

/**
 * Find en side fra den seneste udveksling, eller hent den igen med det gemte bruger-token.
 *
 * @param string $page_id
 * @return array|WP_Error
 */
function fb_post_scheduler_find_page_choice($page_id) {
    $stored = get_transient(fb_post_scheduler_page_choices_key());
    if (is_array($stored)) {
        foreach ($stored as $page) {
            if (isset($page['id']) && (string) $page['id'] === (string) $page_id) {
                return $page;
            }
        }
    }

    $user_token = get_option('fb_post_scheduler_facebook_user_token', '');
    if ($user_token === '') {
        return new WP_Error('missing_pages', __('Sidelisten er udløbet. Indsæt bruger-tokenet igen.', 'fb-post-scheduler'));
    }

    $api = fb_post_scheduler_get_api();
    $pages = $api->get_user_pages($user_token);
    if (is_wp_error($pages)) {
        return $pages;
    }

    foreach ($pages as $page) {
        if (isset($page['id']) && (string) $page['id'] === (string) $page_id) {
            return $page;
        }
    }

    return new WP_Error('missing_page', __('Den valgte side blev ikke fundet.', 'fb-post-scheduler'));
}

/**
 * Gem side-id, sidenavn og page-token. Tokenet kontrolleres, men udveksles ikke igen.
 *
 * @param array $page
 * @return array|WP_Error
 */
function fb_post_scheduler_save_connected_page($page) {
    if (empty($page['id']) || empty($page['access_token'])) {
        return new WP_Error('missing_page', __('Manglende side-data fra Facebook.', 'fb-post-scheduler'));
    }

    $api = fb_post_scheduler_get_api();
    $token_info = $api->exchange_for_page_long_term_token($page['access_token']);
    if (is_wp_error($token_info)) {
        return $token_info;
    }

    $page_name = isset($page['name']) ? $page['name'] : '';
    update_option('fb_post_scheduler_facebook_page_id', $page['id']);
    update_option('fb_post_scheduler_facebook_page_name', $page_name);
    update_option('fb_post_scheduler_facebook_access_token', $token_info['access_token']);
    update_option('fb_post_scheduler_token_expires_at', $token_info['expires_at']);
    update_option('fb_post_scheduler_token_expires_date', $token_info['expires_date']);
    delete_option('fb_post_scheduler_facebook_group_id');
    delete_option('fb_post_scheduler_facebook_group_name');

    return array(
        'id' => $page['id'],
        'name' => $page_name,
        'expires' => $token_info['expires_date'],
    );
}

/**
 * Sider til dropdownen, uden access tokens.
 *
 * @param array $pages
 * @return array
 */
function fb_post_scheduler_public_page_choices($pages) {
    $public = array();
    foreach ($pages as $page) {
        $public[] = array(
            'id' => isset($page['id']) ? $page['id'] : '',
            'name' => isset($page['name']) ? $page['name'] : '',
            'category' => isset($page['category']) ? $page['category'] : '',
        );
    }
    return $public;
}

/**
 * Udveksl et short-term bruger-token og gem det side-token, det giver.
 */
function fb_post_scheduler_connect_facebook_ajax() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'fb_post_scheduler_nonce')) {
        wp_send_json_error(array(
            'message' => __('Ugyldig sikkerhedsnøgle', 'fb-post-scheduler')
        ));
        exit;
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array(
            'message' => __('Utilstrækkelige rettigheder', 'fb-post-scheduler')
        ));
        exit;
    }

    $short_term_token = isset($_POST['short_term_token']) ? sanitize_text_field(wp_unslash($_POST['short_term_token'])) : '';
    if ($short_term_token === '') {
        wp_send_json_error(array(
            'message' => __('Short-term bruger-token er påkrævet', 'fb-post-scheduler')
        ));
        exit;
    }

    $api = fb_post_scheduler_get_api();
    $exchanged = $api->exchange_for_long_term_token($short_term_token);
    if (is_wp_error($exchanged)) {
        wp_send_json_error(array(
            'message' => sprintf(__('Token udveksling fejl: %s', 'fb-post-scheduler'), $exchanged->get_error_message())
        ));
        exit;
    }

    update_option('fb_post_scheduler_facebook_user_token', $exchanged['access_token']);

    $pages = $api->get_user_pages($exchanged['access_token']);
    if (is_wp_error($pages)) {
        wp_send_json_error(array(
            'message' => sprintf(__('Fejl ved hentning af sider: %s', 'fb-post-scheduler'), $pages->get_error_message())
        ));
        exit;
    }

    if (empty($pages)) {
        wp_send_json_error(array(
            'message' => __('Ingen Facebook-sider fundet. Tokenet skal have pages_show_list, pages_read_engagement og pages_manage_posts, og du skal kunne oprette indhold på siden.', 'fb-post-scheduler')
        ));
        exit;
    }

    fb_post_scheduler_store_page_choices($pages);

    if (count($pages) === 1) {
        $saved = fb_post_scheduler_save_connected_page($pages[0]);
        if (is_wp_error($saved)) {
            wp_send_json_error(array(
                'message' => sprintf(__('Fejl ved gemning af side-token: %s', 'fb-post-scheduler'), $saved->get_error_message())
            ));
            exit;
        }

        wp_send_json_success(array(
            'connected' => true,
            'message' => sprintf(
                __('Side-token er gemt for %1$s. %2$s.', 'fb-post-scheduler'),
                $saved['name'],
                ($saved['expires'] === '' || $saved['expires'] === 'Aldrig')
                    ? __('Tokenet udløber ikke', 'fb-post-scheduler')
                    : sprintf(__('Tokenet udløber %s', 'fb-post-scheduler'), $saved['expires'])
            ),
            'page' => $saved,
        ));
        exit;
    }

    wp_send_json_success(array(
        'connected' => false,
        'needs_selection' => true,
        'message' => sprintf(__('Fandt %d sider. Vælg den side, opslagene skal udgives på.', 'fb-post-scheduler'), count($pages)),
        'pages' => fb_post_scheduler_public_page_choices($pages),
    ));
    exit;
}
add_action('wp_ajax_fb_post_scheduler_connect_facebook', 'fb_post_scheduler_connect_facebook_ajax');

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
    
    if (is_wp_error($result) || !is_array($result)) {
        $message = is_wp_error($result) ? $result->get_error_message() : __('Ingen token-data modtaget', 'fb-post-scheduler');
        wp_send_json_error(array(
            'message' => sprintf(__('Token tjek fejl: %s', 'fb-post-scheduler'), $message)
        ));
        exit;
    }

    // Graph API bruger 0, når et page-token ikke udløber.
    $expires_at = isset($result['expires_at']) ? $result['expires_at'] : 0;
    if ($expires_at === 0 || $expires_at === '0' || $expires_at === '') {
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
    
    $page_id = isset($_POST['page_id']) ? sanitize_text_field(wp_unslash($_POST['page_id'])) : '';
    if ($page_id === '') {
        wp_send_json_error(array(
            'message' => __('Vælg en Facebook-side', 'fb-post-scheduler')
        ));
        exit;
    }

    $page = fb_post_scheduler_find_page_choice($page_id);
    if (is_wp_error($page)) {
        wp_send_json_error(array(
            'message' => $page->get_error_message()
        ));
        exit;
    }

    $saved = fb_post_scheduler_save_connected_page($page);
    if (is_wp_error($saved)) {
        wp_send_json_error(array(
            'message' => sprintf(__('Fejl ved gemning af side-token: %s', 'fb-post-scheduler'), $saved->get_error_message())
        ));
        exit;
    }

    wp_send_json_success(array(
        'connected' => true,
        'message' => sprintf(
            __('Side-token er gemt for %1$s. %2$s.', 'fb-post-scheduler'),
            $saved['name'],
            ($saved['expires'] === '' || $saved['expires'] === 'Aldrig')
                ? __('Tokenet udløber ikke', 'fb-post-scheduler')
                : sprintf(__('Tokenet udløber %s', 'fb-post-scheduler'), $saved['expires'])
        ),
        'page' => $saved,
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
    ));
    
    exit;
}
add_action('wp_ajax_fb_post_scheduler_renew_page_token', 'fb_post_scheduler_renew_page_token_ajax');

/**
 * AJAX-handler til at hente kommende planlagte opslag til meta box overblik
 */
function fb_post_scheduler_get_upcoming_posts_ajax() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'fb_post_scheduler_nonce')) {
        wp_send_json_error(array('message' => __('Ugyldig sikkerhedsnøgle', 'fb-post-scheduler')));
        exit;
    }

    if (!current_user_can('edit_posts')) {
        wp_send_json_error(array('message' => __('Utilstrækkelige rettigheder', 'fb-post-scheduler')));
        exit;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'fb_scheduled_posts';
    $now = current_time('mysql');

    $posts = $wpdb->get_results($wpdb->prepare(
        "SELECT scheduled_time, post_title FROM $table_name WHERE scheduled_time >= %s AND status = 'scheduled' ORDER BY scheduled_time ASC LIMIT 50",
        $now
    ));

    $items = array();
    foreach ($posts as $post) {
        $date_parts = explode(' ', $post->scheduled_time);
        $items[] = array(
            'date'  => isset($date_parts[0]) ? $date_parts[0] : '',
            'time'  => isset($date_parts[1]) ? substr($date_parts[1], 0, 5) : '',
            'title' => $post->post_title,
        );
    }

    wp_send_json_success($items);
    exit;
}
add_action('wp_ajax_fb_post_scheduler_get_upcoming_posts', 'fb_post_scheduler_get_upcoming_posts_ajax');

/**
 * AJAX-handler til at slette opslagsinformationer fra databasen og post meta
 *
 * Bruges til at fjerne et allerede postet Facebook-opslag fra tabellen og post meta,
 * så indlægget kan genplanlægges – fx efter genudgivelse via et tredjeparts-plugin.
 */
function fb_post_scheduler_clear_post_record_ajax() {
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'fb_post_scheduler_nonce' ) ) {
        wp_send_json_error( array( 'message' => __( 'Ugyldig sikkerhedsnøgle', 'fb-post-scheduler' ) ) );
        exit;
    }

    if ( ! current_user_can( 'edit_posts' ) ) {
        wp_send_json_error( array( 'message' => __( 'Utilstrækkelige rettigheder', 'fb-post-scheduler' ) ) );
        exit;
    }

    $post_id    = isset( $_POST['post_id'] )    ? intval( $_POST['post_id'] )    : 0;
    $post_index = isset( $_POST['post_index'] ) ? intval( $_POST['post_index'] ) : -1;

    if ( ! $post_id || $post_index < 0 ) {
        wp_send_json_error( array( 'message' => __( 'Ugyldig post ID eller opslag-indeks', 'fb-post-scheduler' ) ) );
        exit;
    }

    // Slet posten fra databasen
    global $wpdb;
    $table_name = $wpdb->prefix . 'fb_scheduled_posts';

    $wpdb->delete(
        $table_name,
        array(
            'post_id'    => $post_id,
            'post_index' => $post_index,
        ),
        array( '%d', '%d' )
    );

    // Fjern posten fra _fb_posts meta
    $fb_posts = get_post_meta( $post_id, '_fb_posts', true );
    if ( is_array( $fb_posts ) && isset( $fb_posts[ $post_index ] ) ) {
        array_splice( $fb_posts, $post_index, 1 );
        $fb_posts = array_values( $fb_posts );
        if ( empty( $fb_posts ) ) {
            delete_post_meta( $post_id, '_fb_posts' );
            delete_post_meta( $post_id, '_fb_post_enabled' );
        } else {
            update_post_meta( $post_id, '_fb_posts', $fb_posts );
        }
    }

    wp_send_json_success( array( 'message' => __( 'Opslagsinformationer slettet', 'fb-post-scheduler' ) ) );
    exit;
}
add_action( 'wp_ajax_fb_post_scheduler_clear_post_record', 'fb_post_scheduler_clear_post_record_ajax' );

/**
 * AJAX-handler: live-søgning efter offentlige Facebook Pages til @-mentions.
 *
 * Uses Meta Pages Search. Tries user token, then app token, then page token.
 *
 * @see FB_Post_Scheduler_API::search_pages() for App Review permission docs.
 */
function fb_post_scheduler_search_pages_ajax() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'fb_post_scheduler_nonce')) {
        wp_send_json_error(array(
            'message' => __('Ugyldig sikkerhedsnøgle', 'fb-post-scheduler'),
        ));
    }

    if (!current_user_can('edit_posts')) {
        wp_send_json_error(array(
            'message' => __('Utilstrækkelige rettigheder', 'fb-post-scheduler'),
        ));
    }

    $search_term = isset($_POST['q']) ? sanitize_text_field(wp_unslash($_POST['q'])) : '';
    $term_length = function_exists('mb_strlen') ? mb_strlen($search_term, 'UTF-8') : strlen($search_term);

    if ($term_length < 3) {
        wp_send_json_error(array(
            'message' => __('Søgningen skal være mindst 3 tegn', 'fb-post-scheduler'),
        ));
    }

    $api = fb_post_scheduler_get_api();
    $result = $api->search_pages($search_term);

    if (is_wp_error($result)) {
        wp_send_json_error(array(
            'message' => $result->get_error_message(),
        ));
    }

    wp_send_json_success($result);
}
add_action('wp_ajax_fb_post_scheduler_search_pages', 'fb_post_scheduler_search_pages_ajax');
/**
 * Sanitize saved mention pages option (array of id + name).
 *
 * @param mixed $pages Raw option value.
 * @return array<int, array{id: string, name: string}>
 */
function fb_post_scheduler_sanitize_saved_mention_pages($pages) {
    if (!is_array($pages)) {
        return array();
    }

    $normalized = array();
    $seen_ids = array();

    foreach ($pages as $page) {
        if (!is_array($page)) {
            continue;
        }

        $id = isset($page['id']) ? preg_replace('/\D+/', '', (string) $page['id']) : '';
        $name = isset($page['name']) ? sanitize_text_field($page['name']) : '';

        if ($id === '' || $name === '') {
            continue;
        }

        if (isset($seen_ids[$id])) {
            continue;
        }

        $seen_ids[$id] = true;
        $normalized[] = array(
            'id' => $id,
            'name' => $name,
        );
    }

    usort($normalized, function ($a, $b) {
        return strcasecmp($a['name'], $b['name']);
    });

    return array_values($normalized);
}

/**
 * Get normalized list of saved mention pages.
 *
 * @return array<int, array{id: string, name: string}>
 */
function fb_post_scheduler_get_saved_mention_pages() {
    return fb_post_scheduler_sanitize_saved_mention_pages(
        get_option('fb_post_scheduler_saved_mention_pages', array())
    );
}

/**
 * Persist saved mention pages.
 *
 * @param array $pages Pages to store.
 * @return array<int, array{id: string, name: string}>
 */
function fb_post_scheduler_update_saved_mention_pages($pages) {
    $normalized = fb_post_scheduler_sanitize_saved_mention_pages($pages);
    update_option('fb_post_scheduler_saved_mention_pages', $normalized);
    return $normalized;
}

/**
 * AJAX: søg i gemte sider til @[mention]-autocomplete.
 */
function fb_post_scheduler_search_saved_pages_ajax() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'fb_post_scheduler_nonce')) {
        wp_send_json_error(array(
            'message' => __('Ugyldig sikkerhedsnøgle', 'fb-post-scheduler'),
        ));
    }

    if (!current_user_can('edit_posts')) {
        wp_send_json_error(array(
            'message' => __('Utilstrækkelige rettigheder', 'fb-post-scheduler'),
        ));
    }

    $search_term = isset($_POST['q']) ? sanitize_text_field(wp_unslash($_POST['q'])) : '';
    $pages = fb_post_scheduler_get_saved_mention_pages();

    if ($search_term !== '') {
        $needle = function_exists('mb_strtolower')
            ? mb_strtolower($search_term, 'UTF-8')
            : strtolower($search_term);

        $pages = array_values(array_filter($pages, function ($page) use ($needle) {
            $haystack = function_exists('mb_strtolower')
                ? mb_strtolower($page['name'], 'UTF-8')
                : strtolower($page['name']);

            return strpos($haystack, $needle) !== false;
        }));
    }

    wp_send_json_success($pages);
}
add_action('wp_ajax_fb_post_scheduler_search_saved_pages', 'fb_post_scheduler_search_saved_pages_ajax');

/**
 * AJAX: tilføj en gemt side (navn + Page ID).
 */
function fb_post_scheduler_add_saved_page_ajax() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'fb_post_scheduler_nonce')) {
        wp_send_json_error(array(
            'message' => __('Ugyldig sikkerhedsnøgle', 'fb-post-scheduler'),
        ));
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array(
            'message' => __('Utilstrækkelige rettigheder', 'fb-post-scheduler'),
        ));
    }

    $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
    $id = isset($_POST['id']) ? preg_replace('/\D+/', '', (string) wp_unslash($_POST['id'])) : '';

    if ($name === '' || $id === '') {
        wp_send_json_error(array(
            'message' => __('Udfyld både side-navn og Page ID.', 'fb-post-scheduler'),
        ));
    }

    $pages = fb_post_scheduler_get_saved_mention_pages();

    foreach ($pages as $page) {
        if ($page['id'] === $id) {
            wp_send_json_error(array(
                'message' => __('En side med dette Page ID er allerede gemt.', 'fb-post-scheduler'),
            ));
        }
    }

    $pages[] = array(
        'id' => $id,
        'name' => $name,
    );

    $pages = fb_post_scheduler_update_saved_mention_pages($pages);

    wp_send_json_success(array(
        'message' => __('Siden er gemt.', 'fb-post-scheduler'),
        'pages' => $pages,
    ));
}
add_action('wp_ajax_fb_post_scheduler_add_saved_page', 'fb_post_scheduler_add_saved_page_ajax');

/**
 * AJAX: fjern en gemt side.
 */
function fb_post_scheduler_remove_saved_page_ajax() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'fb_post_scheduler_nonce')) {
        wp_send_json_error(array(
            'message' => __('Ugyldig sikkerhedsnøgle', 'fb-post-scheduler'),
        ));
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array(
            'message' => __('Utilstrækkelige rettigheder', 'fb-post-scheduler'),
        ));
    }

    $id = isset($_POST['id']) ? preg_replace('/\D+/', '', (string) wp_unslash($_POST['id'])) : '';

    if ($id === '') {
        wp_send_json_error(array(
            'message' => __('Ugyldigt Page ID.', 'fb-post-scheduler'),
        ));
    }

    $pages = fb_post_scheduler_get_saved_mention_pages();
    $pages = array_values(array_filter($pages, function ($page) use ($id) {
        return $page['id'] !== $id;
    }));

    $pages = fb_post_scheduler_update_saved_mention_pages($pages);

    wp_send_json_success(array(
        'message' => __('Siden er fjernet.', 'fb-post-scheduler'),
        'pages' => $pages,
    ));
}
add_action('wp_ajax_fb_post_scheduler_remove_saved_page', 'fb_post_scheduler_remove_saved_page_ajax');

/**
 * AJAX-handler: hent Facebooks cached link-preview eller opdater cachen.
 */
function fb_post_scheduler_fetch_facebook_preview_ajax() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'fb_post_scheduler_nonce')) {
        wp_send_json_error(array(
            'message' => __('Ugyldig sikkerhedsnøgle', 'fb-post-scheduler'),
        ));
    }

    if (!current_user_can('edit_posts')) {
        wp_send_json_error(array(
            'message' => __('Utilstrækkelige rettigheder', 'fb-post-scheduler'),
        ));
    }

    $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
    if (!$post_id || !current_user_can('edit_post', $post_id)) {
        wp_send_json_error(array(
            'message' => __('Utilstrækkelige rettigheder', 'fb-post-scheduler'),
        ));
    }

    if (get_post_status($post_id) !== 'publish') {
        wp_send_json_error(array(
            'message' => __('Facebook kan kun læse offentliggjorte indlæg. Publicér indlægget først.', 'fb-post-scheduler'),
        ));
    }

    $permalink = get_permalink($post_id);
    if (empty($permalink)) {
        wp_send_json_error(array(
            'message' => __('Kunne ikke finde permalink til indlægget.', 'fb-post-scheduler'),
        ));
    }

    $refresh = !empty($_POST['refresh']);
    $api = new FB_Post_Scheduler_API();
    $result = $refresh ? $api->scrape_url($permalink) : $api->get_url_og_object($permalink);

    if (is_wp_error($result)) {
        wp_send_json_error(array(
            'message' => $result->get_error_message(),
        ));
    }

    $mapped = fb_post_scheduler_map_graph_og_response($result);
    if ($mapped['image_url'] === '' && $mapped['title'] === '') {
        wp_send_json_error(array(
            'message' => __('Facebook har endnu ikke scrapet denne URL. Prøv at opdatere Facebooks cache.', 'fb-post-scheduler'),
        ));
    }

    $mapped['site_name'] = $mapped['site_name'] !== '' ? $mapped['site_name'] : fb_post_scheduler_get_default_site_name();
    $mapped['source'] = 'facebook';
    $mapped['source_label'] = fb_post_scheduler_get_preview_source_label('facebook');
    $mapped['refreshed'] = $refresh;

    wp_send_json_success($mapped);
}
add_action('wp_ajax_fb_post_scheduler_fetch_facebook_preview', 'fb_post_scheduler_fetch_facebook_preview_ajax');
