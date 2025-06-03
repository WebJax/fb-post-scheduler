<?php
/**
 * Facebook API Helper Test for Facebook Post Scheduler
 * 
 * Dette er en testversion af API-hjælperen til brug på lokale udviklingsmiljøer
 * uden adgang til Facebook API.
 */

// Hvis denne fil kaldes direkte, så afbryd
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Klasse til simulering af Facebook API-kald under udvikling
 */
class FB_Post_Scheduler_API_Test extends FB_Post_Scheduler_API {
    
    /**
     * Constructor
     */
    public function __construct() {
        parent::__construct();
    }
    
    /**
     * Post til Facebook (TEST VERSION)
     *
     * @param string $message Beskedtekst til Facebook-opslag
     * @param string $link URL til at inkludere i opslaget
     * @param int $image_id Attachment ID of image to include (optional)
     * @return array|WP_Error Response fra Facebook eller fejl
     */
    public function post_to_facebook($message, $link, $image_id = 0) {
        // For at simulere mulige fejlscenarier, kan vi indføre en tilfældig fejl
        // hvis en bestemt besked eller test-flag er sat
        if (strpos($message, '[TEST_ERROR]') !== false) {
            return new WP_Error('api_error', 'Dette er en simuleret fejl fra test API');
        }
        
        // Log testopslaget i wp-content/uploads/fb-post-scheduler-test-logs
        $log_file = $this->log_test_post($message, $link, $image_id);
        
        // Generer et falsk post ID
        $post_id = 'test_' . time() . '_' . rand(1000, 9999);
        
        // Tilføj simuleret forsinkelse for at gøre oplevelsen mere realistisk
        usleep(500000); // 0.5 sekunder
        
        // Returner et simuleret response som om det kommer fra Facebook API
        return array(
            'id' => $post_id,
            'post_url' => 'https://facebook.com/' . $post_id,
            'test_mode' => true,
            'message' => $message,
            'link' => $link,
            'image_id' => $image_id ? $image_id : null,
            'log_file' => $log_file,
            'timestamp' => time(),
            'response_time_ms' => 500 // Simuleret responstid
        );
    }
    
    /**
     * Tjek om Facebook API-indstillinger er gyldige (TEST VERSION)
     *
     * @return boolean Altid true i testversion
     */
    public function validate_credentials() {
        // Altid returnér true i testversion
        return true;
    }
    
    /**
     * Logger testopslag til en fil
     *
     * @param string $message Beskedtekst til Facebook-opslag
     * @param string $link URL til at inkludere i opslaget
     * @param int $image_id Attachment ID of image to include (optional)
     * @return string Path til logfilen
     */
    private function log_test_post($message, $link, $image_id = 0) {
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/fb-post-scheduler-test-logs';
        
        // Opret logmappe hvis den ikke eksisterer
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
        }
        
        // Opret filnavn med dato og tid
        $log_file = $log_dir . '/test_post_' . date('Y-m-d') . '.log';
        
        // Få information om WordPress miljø
        $debug_info = array(
            'WordPress URL' => get_site_url(),
            'WordPress Version' => get_bloginfo('version'),
            'Plugin Version' => FB_POST_SCHEDULER_VERSION,
            'Test Mode' => 'Enabled',
            'PHP Version' => phpversion(),
            'Time' => date('Y-m-d H:i:s'),
            'Server' => isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : 'Unknown'
        );
        
        // Forbered logbesked
        $log_content = "=== Facebook Post Test - " . date('Y-m-d H:i:s') . " ===\n";
        
        // Debug information
        $log_content .= "\n--- Debug Information ---\n";
        foreach ($debug_info as $key => $value) {
            $log_content .= $key . ": " . $value . "\n";
        }
        
        // Post information
        $log_content .= "\n--- Post Content ---\n";
        $log_content .= "Message: " . $message . "\n";
        $log_content .= "Link: " . $link . "\n";
        
        if ($image_id) {
            $image_url = wp_get_attachment_url($image_id);
            $log_content .= "Image: " . ($image_url ? $image_url : 'Image ID ' . $image_id) . "\n";
        }
        
        // If we can get the caller info, add it
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        if (!empty($backtrace[2])) {
            $log_content .= "\n--- Caller Information ---\n";
            $caller = $backtrace[2];
            $log_content .= "Function: " . (isset($caller['function']) ? $caller['function'] : 'Unknown') . "\n";
            $log_content .= "File: " . (isset($caller['file']) ? $caller['file'] : 'Unknown') . "\n";
            $log_content .= "Line: " . (isset($caller['line']) ? $caller['line'] : 'Unknown') . "\n";
        }
        
        $log_content .= "\n=== End of Post ===\n\n";
        
        // Skriv til logfil
        file_put_contents($log_file, $log_content, FILE_APPEND);
        
        return $log_file;
    }
}
