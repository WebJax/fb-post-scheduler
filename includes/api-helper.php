<?php
/**
 * Facebook API Helper for Facebook Post Scheduler
 * 
 * Håndterer kald til Facebook Graph API
 */

// Hvis denne fil kaldes direkte, så afbryd
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Klasse til håndtering af Facebook API-kald
 */
class FB_Post_Scheduler_API {
    
    /**
     * Facebook App ID
     */
    private $app_id;
    
    /**
     * Facebook App Secret
     */
    private $app_secret;
    
    /**
     * Facebook Page ID
     */
    private $page_id;
    
    /**
     * Facebook Access Token
     */
    private $access_token;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->app_id = get_option('fb_post_scheduler_facebook_app_id', '');
        $this->app_secret = get_option('fb_post_scheduler_facebook_app_secret', '');
        $this->page_id = get_option('fb_post_scheduler_facebook_page_id', '');
        $this->access_token = get_option('fb_post_scheduler_facebook_access_token', '');
    }
    
    /**
     * Post til Facebook
     *
     * @param string $message Beskedtekst til Facebook-opslag
     * @param string $link URL til at inkludere i opslaget
     * @param int $image_id Attachment ID of image to include (optional)
     * @return array|WP_Error Response fra Facebook eller fejl
     */
    public function post_to_facebook($message, $link, $image_id = 0) {
        // Tjek at alle nødvendige indstillinger er sat
        if (empty($this->page_id) || empty($this->access_token)) {
            return new WP_Error('missing_credentials', __('Manglende Facebook API-indstillinger', 'fb-post-scheduler'));
        }
        
        // API endpoint
        $url = "https://graph.facebook.com/{$this->page_id}/feed";
        
        // Forbered data
        $data = array(
            'message' => $message,
            'link' => $link,
            'access_token' => $this->access_token
        );
        
        // Hvis der er et billede, brug photos endpoint i stedet
        if (!empty($image_id)) {
            // Få billedfil-url
            $image_url = wp_get_attachment_url($image_id);
            
            if ($image_url) {
                $url = "https://graph.facebook.com/{$this->page_id}/photos";
                $data['url'] = $image_url;
                $data['caption'] = $message;
                // Tilføj link til caption
                $data['caption'] .= "\n\n" . $link;
            }
        }
        
        // Send POST-anmodning til Facebook Graph API
        $response = wp_remote_post($url, array(
            'method' => 'POST',
            'timeout' => 45,
            'redirection' => 5,
            'httpversion' => '1.0',
            'blocking' => true,
            'body' => $data,
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['error'])) {
            return new WP_Error('facebook_api_error', $body['error']['message']);
        }
        
        return $body;
    }
    
    /**
     * Tjek om Facebook API-indstillinger er gyldige
     *
     * @return boolean True hvis indstillinger er gyldige, ellers false
     */
    public function validate_credentials() {
        // Tjek at alle nødvendige indstillinger er sat
        if (empty($this->app_id) || empty($this->app_secret) || 
            empty($this->page_id) || empty($this->access_token)) {
            return false;
        }
        
        // API endpoint for at tjekke token
        $url = "https://graph.facebook.com/oauth/access_token_info?client_id={$this->app_id}&access_token={$this->access_token}";
        
        // Send GET-anmodning til Facebook Graph API
        $response = wp_remote_get($url);
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['error'])) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Log Facebook API-kald
     *
     * @param int $post_id WordPress post ID
     * @param string $fb_post_id Facebook post ID (hvis success)
     * @param string $status Status for API-kald ('success' eller 'error')
     * @param string $message Besked eller fejlbesked
     */
    public function log_api_call($post_id, $fb_post_id, $status, $message) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'fb_post_scheduler_logs';
        
        $wpdb->insert(
            $table_name,
            array(
                'post_id' => $post_id,
                'fb_post_id' => $fb_post_id,
                'status' => $status,
                'message' => $message,
                'created_at' => current_time('mysql')
            ),
            array('%d', '%s', '%s', '%s', '%s')
        );
    }
}

/**
 * Helper funktion til at få instance af API-klassen
 * 
 * Denne funktion bruger et WordPress filter, så vi kan skifte mellem
 * rigtig API og test API baseret på FB_POST_SCHEDULER_TEST_MODE konstanten
 */
function fb_post_scheduler_get_api() {
    static $api = null;
    
    if (null === $api) {
        $api = new FB_Post_Scheduler_API();
        
        // Tillad andre (især api-wrapper.php) at erstatte API objektet
        // når vi er i test mode
        $api = apply_filters('fb_post_scheduler_api_instance', $api);
    }
    
    return $api;
}
