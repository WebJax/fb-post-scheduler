<?php
/**
 * Test Script for Facebook Post Scheduler
 * 
 * Dette script tester de grundlæggende funktioner i Facebook Post Scheduler
 * uden at bruge det rigtige Facebook API.
 */

// Find den korrekte sti til WordPress installationen
$possible_paths = [
    // Standardsti (4 niveauer op fra tests-mappen)
    dirname(dirname(dirname(dirname(__FILE__)))) . '/wp-load.php',
    
    // Specifikke stier
    '/Users/jacobthygesen/Sites/dianalund/wp-load.php',
    '/Users/jacobthygesen/Sites/boinaturen/wp-load.php',
    '/Users/jacobthygesen/Sites/igmsteel/wp-load.php',
    '/Users/jacobthygesen/Sites/jaxweb/wp-load.php',
    '/Users/jacobthygesen/Sites/llp/wp-load.php'
];

$wp_load_path = null;
foreach ($possible_paths as $path) {
    if (file_exists($path)) {
        $wp_load_path = $path;
        break;
    }
}

if (!$wp_load_path) {
    die("Fejl: Kunne ikke finde WordPress wp-load.php filen. Sørg for at køre dette script fra en WordPress installation.\n");
}

echo "WordPress load path: " . $wp_load_path . "\n";

// Vi indlæser WordPress først - konstanten defineres i pluginet
require_once $wp_load_path;

// Ekstra sikring - opsæt filter til at overskrive API objekt
function fb_post_scheduler_ensure_test_mode() {
    // Registrer et filter med højeste prioritet (1) for at sikre det kører først
    add_filter('fb_post_scheduler_api_instance', function($api) {
        // Bekræft vi altid bruger testversionen i dette script
        if (!($api instanceof FB_Post_Scheduler_API_Test)) {
            require_once dirname(dirname(__FILE__)) . '/includes/api-helper-test.php';
            return new FB_Post_Scheduler_API_Test();
        }
        return $api;
    }, 1);
}
fb_post_scheduler_ensure_test_mode();

echo "====== Facebook Post Scheduler Test ======\n";
echo "Testtidspunkt: " . date('Y-m-d H:i:s') . "\n";
echo "WordPress site URL: " . get_site_url() . "\n";
echo "Test mode aktiv: " . (defined('FB_POST_SCHEDULER_TEST_MODE') && FB_POST_SCHEDULER_TEST_MODE ? 'Ja' : 'Nej') . "\n";

// Tjek at test-logmappe eksisterer og er skrivebar
$upload_dir = wp_upload_dir();
$log_dir = $upload_dir['basedir'] . '/fb-post-scheduler-test-logs';
if (!file_exists($log_dir)) {
    wp_mkdir_p($log_dir);
    echo "Oprettede test-logmappe: " . $log_dir . "\n";
} else {
    echo "Test-logmappe eksisterer: " . $log_dir . "\n";
}

// Test 1: Check om API helper virker
echo "\nTest 1: Tjekker om API helper bliver indlæst korrekt...\n";

// Tjek om test modus er aktiveret
echo "Test modus: " . (defined('FB_POST_SCHEDULER_TEST_MODE') && FB_POST_SCHEDULER_TEST_MODE ? 'Aktiveret' : 'Deaktiveret') . "\n";

// Tjek om funktionen findes
if (!function_exists('fb_post_scheduler_get_api')) {
    echo "❌ Fejl: fb_post_scheduler_get_api funktionen eksisterer ikke.\n";
} else {
    // Få API instance
    $api = fb_post_scheduler_get_api();
    echo "API klasse: " . get_class($api) . "\n";
    
    if ($api instanceof FB_Post_Scheduler_API_Test) {
        echo "✅ Success: API helper indlæst og test-versionen blev brugt.\n";
    } else if ($api instanceof FB_Post_Scheduler_API) {
        echo "⚠️ Advarsel: API helper indlæst men den rigtige version bruges i stedet for test-versionen.\n";
        echo "   For at bruge test-versionen, sikr at FB_POST_SCHEDULER_TEST_MODE er sat til true.\n";
    } else {
        echo "❌ Fejl: API helper ikke indlæst korrekt. Uventet klasse: " . get_class($api) . "\n";
    }
}

// Test 2: Validér credentials (skal altid returnere true i test-mode)
echo "\nTest 2: Validerer credentials...\n";
$credentials_valid = $api->validate_credentials();

if ($credentials_valid) {
    echo "✅ Success: Credentials valideret korrekt i test-mode.\n";
} else {
    echo "❌ Fejl: Credentials validering fejlede.\n";
}

// Test 3: Simuler et post til Facebook
echo "\nTest 3: Simulerer opslag til Facebook...\n";

$message = "Dette er et testopslag til Facebook fra Facebook Post Scheduler.";
$link = get_site_url() . "/test-side";
$image_id = 0; // Ingen billede for denne test

$result = $api->post_to_facebook($message, $link, $image_id);

if (isset($result['id']) && $result['test_mode'] === true) {
    echo "✅ Success: Facebook post simuleret korrekt med ID: " . $result['id'] . "\n";
    echo "   Besked: " . $result['message'] . "\n";
    echo "   Link: " . $result['link'] . "\n";
    echo "   Check logfilen i wp-content/uploads/fb-post-scheduler-test-logs/ for detaljer.\n";
} else {
    echo "❌ Fejl: Facebook post simulering fejlede.\n";
    if (is_wp_error($result)) {
        echo "   Fejl: " . $result->get_error_message() . "\n";
    }
}

// Test 4: Test fb_post_scheduler_post_to_facebook helper funktion
echo "\nTest 4: Tester fb_post_scheduler_post_to_facebook hjælperfunktion...\n";

// Find en passende post til test
$recent_posts = get_posts(array(
    'numberposts' => 1,
    'post_status' => 'publish'
));

$test_post_id = !empty($recent_posts) ? $recent_posts[0]->ID : get_option('page_on_front');

if ($test_post_id) {
    echo "Bruger post ID: " . $test_post_id . " (" . get_the_title($test_post_id) . ")\n";
    
    $custom_message = "Test opslag via hjælperfunktion - " . date('Y-m-d H:i:s');
    $result2 = fb_post_scheduler_post_to_facebook($test_post_id, $custom_message);
    
    if ($result2) {
        echo "✅ Success: Hjælperfunktionen fungerer korrekt.\n";
        if (is_array($result2)) {
            echo "   Post URL (simuleret): " . (isset($result2['post_url']) ? $result2['post_url'] : 'N/A') . "\n";
            echo "   Post ID (simuleret): " . (isset($result2['id']) ? $result2['id'] : 'N/A') . "\n";
        } else {
            // Hvis resultatet ikke er et array, vis hvordan det ser ud
            echo "   Resultat: " . (is_bool($result2) ? ($result2 ? 'true' : 'false') : gettype($result2)) . "\n";
        }
    } else {
        echo "❌ Fejl: Hjælperfunktionen fejlede.\n";
        // Hvis resultatet er en WP_Error, vis fejlbeskeden
        if (is_wp_error($result2) && is_object($result2)) {
            echo "   Fejlbesked: " . $result2->get_error_message() . "\n";
        } elseif ($result2 === false) {
            echo "   Fejlbesked: Ukendt fejl (false returneret)\n";
        }
    }
} else {
    echo "⚠️ Advarsel: Kunne ikke finde en test-post til at køre testen med.\n";
}

// Test 5: Test planlagte Facebook-opslag
echo "\nTest 5: Tester planlagte Facebook-opslag...\n";

// Find planlagte opslag
$scheduled_posts = get_posts(array(
    'post_type' => 'fb_scheduled_post',
    'posts_per_page' => 5,
    'meta_query' => array(
        array(
            'key' => '_fb_post_datetime',
            'value' => date('Y-m-d H:i:s'),
            'compare' => '>=',
            'type' => 'DATETIME'
        )
    )
));

if (!empty($scheduled_posts)) {
    echo "Fandt " . count($scheduled_posts) . " planlagte Facebook-opslag:\n";
    
    foreach ($scheduled_posts as $index => $post) {
        $post_time = get_post_meta($post->ID, '_fb_post_datetime', true);
        $linked_post_id = get_post_meta($post->ID, '_fb_linked_post_id', true);
        $linked_post_title = $linked_post_id ? get_the_title($linked_post_id) : 'Ingen tilknyttet post';
        
        echo "  " . ($index + 1) . ". ID: " . $post->ID . "\n";
        echo "     Titel: " . $post->post_title . "\n";
        echo "     Planlagt dato: " . $post_time . "\n";
        echo "     Tekst: " . wp_trim_words($post->post_content, 10) . "...\n";
        echo "     Tilknyttet post: " . $linked_post_title . " (ID: " . $linked_post_id . ")\n";
        echo "\n";
    }
} else {
    echo "Ingen planlagte Facebook-opslag fundet. Du kan oprette et planlagt opslag i WordPress admin.\n";
}

echo "\n====== Test Afsluttet ======\n";
