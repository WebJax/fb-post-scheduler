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
    
    // Hent alle post types der er valgt til Facebook-opslag
    $selected_post_types = get_option('fb_post_scheduler_post_types', array());
    
    if (empty($selected_post_types)) {
        wp_send_json_success(array());
        exit;
    }
    
    // Hent alle poster med Facebook-opslag i den valgte måned
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
    
    $posts_query = new WP_Query($args);
    $events = array();
    
    if ($posts_query->have_posts()) {
        while ($posts_query->have_posts()) {
            $posts_query->the_post();
            $post_id = get_the_ID();
            
            // Få FB data
            $fb_post_date = get_post_meta($post_id, '_fb_post_date', true);
            $fb_post_text = get_post_meta($post_id, '_fb_post_text', true);
            
            // Opdel dato og tid
            $date_parts = explode(' ', $fb_post_date);
            $date = isset($date_parts[0]) ? $date_parts[0] : '';
            $time = isset($date_parts[1]) ? substr($date_parts[1], 0, 5) : '';
            
            // Tjek for FB post status (postet eller ikke postet)
            $has_term = has_term('posted', 'fb_post_status', $post_id);
            $status = $has_term ? 'posted' : 'scheduled';
            
            $events[] = array(
                'post_id' => $post_id,
                'title' => get_the_title(),
                'date' => $date,
                'time' => $time,
                'text' => wp_trim_words($fb_post_text, 15),
                'status' => $status,
                'url' => get_edit_post_link($post_id, 'raw')
            );
        }
        
        wp_reset_postdata();
    }
    
    wp_send_json_success($events);
    exit;
}
add_action('wp_ajax_fb_post_scheduler_get_events', 'fb_post_scheduler_get_events');
