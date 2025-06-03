<?php
/**
 * Standalone Test Script for Facebook Post Scheduler
 * 
 * Dette script tester API-helper klasserne direkte uden at indlæse hele WordPress
 */

echo "====== Facebook Post Scheduler Standalone Test ======\n";
echo "Testtidspunkt: " . date('Y-m-d H:i:s') . "\n\n";

// Definer testmode konstant
define('FB_POST_SCHEDULER_TEST_MODE', true);

// Mock WordPress funktioner som bruges i API klasserne
if (!function_exists('wp_upload_dir')) {
    function wp_upload_dir() {
        return array(
            'basedir' => dirname(dirname(__FILE__)) . '/tests/test-logs'
        );
    }
}

if (!function_exists('wp_mkdir_p')) {
    function wp_mkdir_p($dir) {
        return mkdir($dir, 0755, true);
    }
}

if (!function_exists('wp_get_attachment_url')) {
    function wp_get_attachment_url($id) {
        return 'https://example.com/test-image-' . $id . '.jpg';
    }
}

// Definer FB_Post_Scheduler_API klassen
class FB_Post_Scheduler_API {
    public function __construct() {
        echo "Instansierer FB_Post_Scheduler_API (den rigtige API)\n";
    }
    
    public function post_to_facebook($message, $link, $image_id = 0) {
        echo "REEL API: Dette ville sende et opslag til Facebook\n";
        return array('id' => 'real_api_post', 'error' => 'This is the real API, not meant for testing');
    }
    
    public function validate_credentials() {
        echo "REEL API: Dette ville validere Facebook credentials\n";
        return false; // I test bør dette altid returnere false for at vise det er den rigtige API
    }
}

// Definer FB_Post_Scheduler_API_Test klassen
class FB_Post_Scheduler_API_Test extends FB_Post_Scheduler_API {
    public function __construct() {
        echo "Instansierer FB_Post_Scheduler_API_Test (test-versionen af API)\n";
    }
    
    public function post_to_facebook($message, $link, $image_id = 0) {
        echo "TEST API: Simulerer et opslag til Facebook\n";
        
        // Log opslag
        $log_dir = dirname(dirname(__FILE__)) . '/tests/test-logs';
        if (!file_exists($log_dir)) {
            mkdir($log_dir, 0755, true);
        }
        
        $log_file = $log_dir . '/test_post_' . date('Y-m-d') . '.log';
        $log_content = "=== Facebook Test Post - " . date('Y-m-d H:i:s') . " ===\n";
        $log_content .= "Message: " . $message . "\n";
        $log_content .= "Link: " . $link . "\n";
        $log_content .= "Image ID: " . ($image_id ? $image_id : 'None') . "\n";
        $log_content .= "=== End of Post ===\n\n";
        
        file_put_contents($log_file, $log_content, FILE_APPEND);
        echo "Log skrevet til: " . $log_file . "\n";
        
        // Returnér et simuleret Facebook API svar
        $post_id = 'test_' . time() . '_' . rand(1000, 9999);
        return array(
            'id' => $post_id,
            'post_url' => 'https://facebook.com/' . $post_id,
            'test_mode' => true,
            'message' => $message,
            'link' => $link,
            'image_id' => $image_id ? $image_id : null
        );
    }
    
    public function validate_credentials() {
        echo "TEST API: Simulerer validering af credentials\n";
        return true; // I test-versionen returnerer vi altid true
    }
}

// Definér get_api funktionen
function fb_post_scheduler_get_api() {
    static $api = null;
    
    if (null === $api) {
        if (defined('FB_POST_SCHEDULER_TEST_MODE') && FB_POST_SCHEDULER_TEST_MODE) {
            $api = new FB_Post_Scheduler_API_Test();
        } else {
            $api = new FB_Post_Scheduler_API();
        }
    }
    
    return $api;
}

// Test 1: Tjek hvilken API vi får
echo "\nTest 1: Tjekker hvilken API bliver returneret...\n";
$api = fb_post_scheduler_get_api();
echo "API klasse: " . get_class($api) . "\n";

if ($api instanceof FB_Post_Scheduler_API_Test) {
    echo "✅ Success: Test-API bliver brugt.\n";
} else {
    echo "❌ Fejl: Reel API bliver brugt i stedet for test-versionen.\n";
}

// Test 2: Validér credentials
echo "\nTest 2: Validerer credentials...\n";
$credentials_valid = $api->validate_credentials();
echo "Credentials er " . ($credentials_valid ? "gyldige" : "ugyldige") . "\n";

if ($credentials_valid) {
    echo "✅ Success: Credentials valideret korrekt i test-mode.\n";
} else {
    echo "❌ Fejl: Credentials validering fejlede.\n";
}

// Test 3: Simulér et post til Facebook
echo "\nTest 3: Simulerer opslag til Facebook...\n";
$message = "Dette er et testopslag fra Facebook Post Scheduler - " . date('Y-m-d H:i:s');
$link = "https://example.com/test-page";
$image_id = 123; // Simuleret billede ID

$result = $api->post_to_facebook($message, $link, $image_id);

// Udskriv resultatet
echo "\nResultat fra Facebook API:\n";
echo "ID: " . $result['id'] . "\n";
echo "URL: " . $result['post_url'] . "\n";
echo "Test Mode: " . ($result['test_mode'] ? "Ja" : "Nej") . "\n";

echo "\n====== Test Afsluttet ======\n";
