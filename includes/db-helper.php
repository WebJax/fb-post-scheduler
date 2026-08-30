<?php
/**
 * Database Helper Functions
 * 
 * Provides functions for interacting with the Facebook Post Scheduler database table
 */

// Hvis denne fil kaldes direkte, så afbryd
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Henter tabel navn for planlagte opslag
 * 
 * @return string Tabelnavnet med WordPress prefix
 */
function fb_post_scheduler_get_table_name() {
    global $wpdb;
    return $wpdb->prefix . 'fb_scheduled_posts';
}

/**
 * Indsæt eller opdater et planlagt opslag i databasen
 * 
 * @param int $post_id ID på det oprindelige WordPress indlæg
 * @param array $fb_post Data for Facebook-opslaget
 * @param int $post_index Indeks for opslaget i tilfælde af flere FB opslag for samme post
 * @return int|false ID på det indsatte/opdaterede post eller false ved fejl
 */
function fb_post_scheduler_save_scheduled_post($post_id, $fb_post, $post_index) {
    global $wpdb;
    $table_name = fb_post_scheduler_get_table_name();
    
    // Tjek om opslaget allerede findes i databasen
    $existing_record = $wpdb->get_row($wpdb->prepare(
        "SELECT id FROM $table_name 
         WHERE post_id = %d AND post_index = %d",
        $post_id, $post_index
    ));
    
    $schedule_date = isset($fb_post['date']) ? $fb_post['date'] : '';
    $message = isset($fb_post['text']) ? $fb_post['text'] : '';
    $image_id = isset($fb_post['image_id']) ? intval($fb_post['image_id']) : 0;
    $post_title = get_the_title($post_id);
    
    $data = array(
        'post_id' => $post_id,
        'message' => $message,
        'status' => 'scheduled', // Default status er 'scheduled'
        'created_at' => current_time('mysql'),
        'scheduled_time' => $schedule_date,
        'post_index' => $post_index,
        'image_id' => $image_id,
        'post_title' => $post_title
    );
    
    $formats = array(
        '%d',  // post_id
        '%s',  // message
        '%s',  // status
        '%s',  // created_at
        '%s',  // scheduled_time
        '%d',  // post_index
        '%d',  // image_id
        '%s'   // post_title
    );
    
    if ($existing_record) {
        // Opdater eksisterende post
        $where = array('id' => $existing_record->id);
        $where_format = array('%d');
        $result = $wpdb->update($table_name, $data, $where, $formats, $where_format);
        
        if ($result !== false) {
            return $existing_record->id;
        }
        return false;
    } else {
        // Indsæt ny post
        $result = $wpdb->insert($table_name, $data, $formats);
        
        if ($result !== false) {
            return $wpdb->insert_id;
        }
        return false;
    }
}

/**
 * Slet alle planlagte opslag for en bestemt post
 * 
 * @param int $post_id ID på det oprindelige WordPress indlæg
 * @return int|false Antal slettede rækker eller false ved fejl
 */
function fb_post_scheduler_delete_scheduled_posts($post_id) {
    global $wpdb;
    $table_name = fb_post_scheduler_get_table_name();
    
    return $wpdb->delete(
        $table_name,
        array('post_id' => $post_id),
        array('%d')
    );
}

/**
 * Antal planlagte opslag hvis post_id er en WordPress-revision.
 *
 * @return int
 */
function fb_post_scheduler_count_scheduled_revision_posts() {
    global $wpdb;
    $table_name = fb_post_scheduler_get_table_name();
    $posts_table = $wpdb->posts;

    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table_name} sp
         INNER JOIN {$posts_table} p ON p.ID = sp.post_id
         WHERE p.post_type = %s AND sp.status = %s",
        'revision',
        'scheduled'
    ));
}

/**
 * Visningsnavn for den WP-post et planlagt opslag er knyttet til.
 *
 * Revisioner vises som forældreindlæggets type.
 *
 * @param int $post_id WordPress post ID
 * @return string
 */
function fb_post_scheduler_get_linked_post_type_label($post_id) {
    $post_id = absint($post_id);
    $parent_id = wp_is_post_revision($post_id);
    if ($parent_id) {
        $post_id = (int) $parent_id;
    }

    $post_type = get_post_type($post_id);
    if (!$post_type) {
        return __('Ukendt', 'fb-post-scheduler');
    }

    $post_type_obj = get_post_type_object($post_type);
    if ($post_type_obj && !empty($post_type_obj->labels->singular_name)) {
        return $post_type_obj->labels->singular_name;
    }

    return $post_type;
}

/**
 * Slet planlagte opslag knyttet til revisioner (ikke postede).
 *
 * @return int Antal slettede rækker
 */
function fb_post_scheduler_delete_scheduled_revision_posts() {
    global $wpdb;
    $table_name = fb_post_scheduler_get_table_name();
    $posts_table = $wpdb->posts;

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT sp.id, sp.post_id FROM {$table_name} sp
         INNER JOIN {$posts_table} p ON p.ID = sp.post_id
         WHERE p.post_type = %s AND sp.status = %s",
        'revision',
        'scheduled'
    ));

    if (empty($rows)) {
        return 0;
    }

    $deleted = 0;
    $revision_ids = array();

    foreach ($rows as $row) {
        $row_id = absint($row->id);
        $revision_id = absint($row->post_id);
        if (!$row_id) {
            continue;
        }

        $result = $wpdb->delete($table_name, array('id' => $row_id), array('%d'));
        if ($result) {
            $deleted += (int) $result;
            if ($revision_id) {
                $revision_ids[$revision_id] = $revision_id;
            }
        }
    }

    foreach ($revision_ids as $revision_id) {
        delete_post_meta($revision_id, '_fb_posts');
        delete_post_meta($revision_id, '_fb_post_enabled');
    }

    if ($deleted) {
        fb_post_scheduler_log('Slettede ' . $deleted . ' planlagte opslag knyttet til revisioner');
    }

    return $deleted;
}

/**
 * Slet valgte rækker fra admin-listerne (planlagt eller postet).
 *
 * @param int[]  $ids   Række-ID'er i fb_scheduled_posts
 * @param string $scope 'scheduled' eller 'posted'
 * @return int Antal slettede rækker
 */
function fb_post_scheduler_bulk_delete_list_rows($ids, $scope) {
    global $wpdb;
    $table_name = fb_post_scheduler_get_table_name();
    $scope = ($scope === 'posted') ? 'posted' : 'scheduled';
    $ids = array_values(array_unique(array_filter(array_map('absint', (array) $ids))));

    if (empty($ids)) {
        return 0;
    }

    $deleted = 0;
    $posted_indexes = array();

    foreach ($ids as $id) {
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, post_id, post_index, status FROM {$table_name} WHERE id = %d",
            $id
        ));

        if (!$row || $row->status !== $scope) {
            continue;
        }

        $result = $wpdb->delete($table_name, array('id' => $id), array('%d'));
        if (!$result) {
            continue;
        }

        $deleted++;

        if ($scope === 'scheduled') {
            $scheduled_times = get_post_meta($row->post_id, '_fb_scheduled_times', true);
            if (is_array($scheduled_times) && isset($scheduled_times[$row->post_index])) {
                unset($scheduled_times[$row->post_index]);
                update_post_meta($row->post_id, '_fb_scheduled_times', $scheduled_times);
            }
        } else {
            $posted_indexes[(int) $row->post_id][] = (int) $row->post_index;
        }
    }

    foreach ($posted_indexes as $post_id => $indexes) {
        rsort($indexes, SORT_NUMERIC);
        $fb_posts = get_post_meta($post_id, '_fb_posts', true);
        if (!is_array($fb_posts)) {
            continue;
        }

        foreach ($indexes as $index) {
            if (isset($fb_posts[$index])) {
                array_splice($fb_posts, $index, 1);
            }
        }

        $fb_posts = array_values($fb_posts);
        if (empty($fb_posts)) {
            delete_post_meta($post_id, '_fb_posts');
            delete_post_meta($post_id, '_fb_post_enabled');
        } else {
            update_post_meta($post_id, '_fb_posts', $fb_posts);
        }
    }

    return $deleted;
}

/**
 * Slet et specifikt planlagt opslag baseret på post ID og index
 * 
 * @param int $post_id ID på det oprindelige WordPress indlæg
 * @param int $post_index Indeks for opslaget i tilfælde af flere FB opslag for samme post
 * @return int|false Antal slettede rækker eller false ved fejl
 */
function fb_post_scheduler_delete_scheduled_post($post_id, $post_index) {
    global $wpdb;
    $table_name = fb_post_scheduler_get_table_name();
    
    return $wpdb->delete(
        $table_name,
        array(
            'post_id' => $post_id,
            'post_index' => $post_index
        ),
        array('%d', '%d')
    );
}

/**
 * Hent alle planlagte opslag for et bestemt tidsrum
 * 
 * @param string $start_date Start dato i Y-m-d H:i:s format
 * @param string $end_date Slut dato i Y-m-d H:i:s format
 * @return array Planlagte opslag
 */
function fb_post_scheduler_get_scheduled_posts($start_date, $end_date) {
    global $wpdb;
    $table_name = fb_post_scheduler_get_table_name();
    
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table_name 
         WHERE scheduled_time BETWEEN %s AND %s
         ORDER BY scheduled_time ASC",
        $start_date, $end_date
    ));
}

/**
 * Opdater status for et opslag
 * 
 * @param int $id ID på det planlagte opslag i tabellen
 * @param string $status Ny status ('scheduled', 'posting', 'posted', 'error')
 * @param string $fb_post_id Optional Facebook post ID hvis status er 'posted'
 * @return int|false Antal opdaterede rækker eller false ved fejl
 */
function fb_post_scheduler_update_status($id, $status, $fb_post_id = '') {
    global $wpdb;
    $table_name = fb_post_scheduler_get_table_name();
    
    $data = array('status' => $status);
    $formats = array('%s');
    
    if ($status === 'posted' && !empty($fb_post_id)) {
        $data['fb_post_id'] = $fb_post_id;
        $formats[] = '%s';
    }
    
    return $wpdb->update(
        $table_name,
        $data,
        array('id' => $id),
        $formats,
        array('%d')
    );
}

/**
 * Migrerer eksisterende planlagte opslag fra custom post type til database
 * 
 * @return array Resultater af migreringen
 */
function fb_post_scheduler_migrate_from_post_type() {
    $results = array(
        'success' => 0,
        'failed' => 0,
        'total' => 0
    );
    
    // Hent alle eksisterende opslag fra custom post type
    $existing_posts = get_posts(array(
        'post_type' => 'fb_scheduled_post',
        'posts_per_page' => -1,
        'post_status' => 'any'
    ));
    
    $results['total'] = count($existing_posts);
    
    if (empty($existing_posts)) {
        return $results;
    }
    
    // Overfør hver post til databasen
    foreach ($existing_posts as $post) {
        $linked_post_id = get_post_meta($post->ID, '_fb_linked_post_id', true);
        $post_index = get_post_meta($post->ID, '_fb_post_index', true);
        $scheduled_time = get_post_meta($post->ID, '_fb_post_datetime', true);
        $image_id = get_post_meta($post->ID, '_fb_post_image_id', true);
        
        if (!$linked_post_id || !$scheduled_time) {
            $results['failed']++;
            continue;
        }
        
        // Lav et FB post array til at sende til save_scheduled_post
        $fb_post = array(
            'text' => $post->post_content,
            'date' => $scheduled_time,
            'image_id' => $image_id ? $image_id : 0
        );
        
        // Gem i databasen
        $saved_id = fb_post_scheduler_save_scheduled_post($linked_post_id, $fb_post, $post_index ? $post_index : 0);
        
        if ($saved_id) {
            $results['success']++;
            // Slet den gamle post for at undgå duplikater
            wp_delete_post($post->ID, true);
        } else {
            $results['failed']++;
        }
    }
    
    return $results;
}
