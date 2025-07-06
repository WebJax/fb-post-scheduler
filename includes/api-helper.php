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
     * @param int $image_id Ikke brugt - bevaret for kompatibilitet
     * @param int $post_id WordPress post ID til logging
     * @return array|WP_Error Response fra Facebook eller fejl
     */
    public function post_to_facebook($message, $link, $image_id = 0, $post_id = 0) {
        // Tjek at alle nødvendige indstillinger er sat
        if (empty($this->page_id) || empty($this->access_token)) {
            return new WP_Error('missing_credentials', __('Manglende Facebook API-indstillinger', 'fb-post-scheduler'));
        }
        
        // Log start af posting
        if (!empty($post_id)) {
            fb_post_scheduler_log('Poster til Facebook med link sharing - Facebook finder automatisk det bedste billede på siden', $post_id, '', 'info');
        }
        
        // Post direkte med link sharing - enkel og pålidelig metode
        $result = $this->post_with_link_share($message, $link);
        
        // Log resultat
        if (!is_wp_error($result) && !empty($post_id)) {
            fb_post_scheduler_log('SUCCESS: Post oprettet på Facebook', $post_id, isset($result['id']) ? $result['id'] : '', 'success');
        } elseif (is_wp_error($result) && !empty($post_id)) {
            fb_post_scheduler_log('FEJL: Facebook posting fejlede: ' . $result->get_error_message(), $post_id, '', 'error');
        }
        
        return $result;
    }
    
    /**
     * Post med link sharing til Facebook
     * 
     * Enkel og pålidelig metode - Facebook finder automatisk det bedste billede
     * 
     * @param string $message Beskedtekst
     * @param string $link URL til at dele
     * @return array|WP_Error Response fra Facebook eller fejl
     */
    private function post_with_link_share($message, $link) {
        error_log('FB Post Scheduler: Starting simple link share to Facebook');
        
        $url = "https://graph.facebook.com/{$this->page_id}/feed";
        
        $post_data = array(
            'message' => $message,
            'access_token' => $this->access_token,
            'published' => 'true'
        );
        
        // Tilføj link hvis angivet
        if (!empty($link)) {
            $post_data['link'] = $link;
        }
        
        $response = wp_remote_post($url, array(
            'timeout' => 30,
            'body' => $post_data,
            'headers' => array(
                'User-Agent' => 'WordPress/Facebook-Post-Scheduler'
            )
        ));
        
        if (is_wp_error($response)) {
            error_log('FB Post Scheduler: Link sharing failed - ' . $response->get_error_message());
            return new WP_Error('post_error', sprintf(__('Link sharing fejlede: %s', 'fb-post-scheduler'), $response->get_error_message()));
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        error_log('FB Post Scheduler: Facebook response - ' . $body);
        
        if (isset($data['error'])) {
            error_log('FB Post Scheduler: Facebook API error - ' . $data['error']['message']);
            return new WP_Error('facebook_error', sprintf(__('Facebook fejl: %s', 'fb-post-scheduler'), $data['error']['message']));
        }
        
        error_log('FB Post Scheduler: Link sharing successful');
        
        return $data;
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
     * Valider access token
     * 
     * @return array|WP_Error Token information or error
     */
    public function validate_access_token() {
        if (empty($this->access_token)) {
            return new WP_Error('no_token', __('Access token er ikke konfigureret', 'fb-post-scheduler'));
        }
        
        $url = 'https://graph.facebook.com/me?access_token=' . urlencode($this->access_token);
        
        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'headers' => array(
                'User-Agent' => 'WordPress/Facebook-Post-Scheduler'
            )
        ));
        
        if (is_wp_error($response)) {
            return new WP_Error('api_error', sprintf(__('API forespørgsel fejlede: %s', 'fb-post-scheduler'), $response->get_error_message()));
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['error'])) {
            return new WP_Error('token_error', sprintf(__('Access token fejl: %s', 'fb-post-scheduler'), $data['error']['message']));
        }
        
        return $data;
    }
    
    /**
     * Hent information om Facebook siden
     * 
     * @return array|WP_Error Side information or error
     */
    public function get_page_info() {
        if (empty($this->page_id) || empty($this->access_token)) {
            return new WP_Error('missing_config', __('Side ID eller access token mangler', 'fb-post-scheduler'));
        }
        
        $url = sprintf(
            'https://graph.facebook.com/%s?fields=id,name,category,fan_count,verification_status&access_token=%s',
            urlencode($this->page_id),
            urlencode($this->access_token)
        );
        
        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'headers' => array(
                'User-Agent' => 'WordPress/Facebook-Post-Scheduler'
            )
        ));
        
        if (is_wp_error($response)) {
            return new WP_Error('api_error', sprintf(__('API forespørgsel fejlede: %s', 'fb-post-scheduler'), $response->get_error_message()));
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['error'])) {
            return new WP_Error('page_error', sprintf(__('Side fejl: %s', 'fb-post-scheduler'), $data['error']['message']));
        }
        
        return $data;
    }
    
    /**
     * Tjek posting tilladelser for siden
     * 
     * @return array|WP_Error Permissions or error
     */
    public function check_posting_permissions() {
        if (empty($this->page_id) || empty($this->access_token)) {
            return new WP_Error('missing_config', __('Side ID eller access token mangler', 'fb-post-scheduler'));
        }
        
        // Tjek om vi kan hente page's access token
        $url = sprintf(
            'https://graph.facebook.com/%s?fields=access_token&access_token=%s',
            urlencode($this->page_id),
            urlencode($this->access_token)
        );
        
        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'headers' => array(
                'User-Agent' => 'WordPress/Facebook-Post-Scheduler'
            )
        ));
        
        if (is_wp_error($response)) {
            return new WP_Error('api_error', sprintf(__('API forespørgsel fejlede: %s', 'fb-post-scheduler'), $response->get_error_message()));
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['error'])) {
            return new WP_Error('permission_error', sprintf(__('Tilladelse fejl: %s', 'fb-post-scheduler'), $data['error']['message']));
        }
        
        // Hvis vi får et access token tilbage, har vi posting tilladelser
        if (isset($data['access_token'])) {
            return array('status' => 'ok', 'message' => __('Posting tilladelser er OK', 'fb-post-scheduler'));
        }
        
        return new WP_Error('no_permissions', __('Ingen posting tilladelser til denne side', 'fb-post-scheduler'));
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
    
    /**
     * Udveksler short-term access token til long-term access token
     * 
     * @param string $short_term_token Det korte access token
     * @return array|WP_Error Long-term token information or error
     */
    public function exchange_for_long_term_token($short_term_token = null) {
        if (empty($short_term_token)) {
            $short_term_token = $this->access_token;
        }
        
        if (empty($short_term_token) || empty($this->app_id) || empty($this->app_secret)) {
            return new WP_Error('missing_config', __('App ID, App Secret og access token er påkrævet', 'fb-post-scheduler'));
        }
        
        $url = sprintf(
            'https://graph.facebook.com/oauth/access_token?grant_type=fb_exchange_token&client_id=%s&client_secret=%s&fb_exchange_token=%s',
            urlencode($this->app_id),
            urlencode($this->app_secret),
            urlencode($short_term_token)
        );
        
        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'headers' => array(
                'User-Agent' => 'WordPress/Facebook-Post-Scheduler'
            )
        ));
        
        if (is_wp_error($response)) {
            return new WP_Error('api_error', sprintf(__('API forespørgsel fejlede: %s', 'fb-post-scheduler'), $response->get_error_message()));
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['error'])) {
            return new WP_Error('token_exchange_error', sprintf(__('Token udveksling fejl: %s', 'fb-post-scheduler'), $data['error']['message']));
        }
        
        if (isset($data['access_token'])) {
            // Beregn udløbsdato
            $expires_in = isset($data['expires_in']) ? intval($data['expires_in']) : 5184000; // 60 dage som standard
            $expires_at = time() + $expires_in;
            
            return array(
                'access_token' => $data['access_token'],
                'token_type' => isset($data['token_type']) ? $data['token_type'] : 'bearer',
                'expires_in' => $expires_in,
                'expires_at' => $expires_at,
                'expires_date' => date('Y-m-d H:i:s', $expires_at)
            );
        }
        
        return new WP_Error('no_token', __('Ingen access token modtaget fra Facebook', 'fb-post-scheduler'));
    }
    
    /**
     * Tjek om access token snart udløber
     * 
     * @return bool|WP_Error True hvis token snart udløber, false hvis ikke, WP_Error ved fejl
     */
    public function check_token_expiration() {
        if (empty($this->access_token)) {
            return new WP_Error('no_token', __('Ingen access token konfigureret', 'fb-post-scheduler'));
        }
        
        $url = sprintf(
            'https://graph.facebook.com/debug_token?input_token=%s&access_token=%s',
            urlencode($this->access_token),
            urlencode($this->app_id . '|' . $this->app_secret)
        );
        
        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'headers' => array(
                'User-Agent' => 'WordPress/Facebook-Post-Scheduler'
            )
        ));
        
        if (is_wp_error($response)) {
            return new WP_Error('api_error', sprintf(__('API forespørgsel fejlede: %s', 'fb-post-scheduler'), $response->get_error_message()));
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['error'])) {
            return new WP_Error('debug_error', sprintf(__('Token debug fejl: %s', 'fb-post-scheduler'), $data['error']['message']));
        }
        
        if (isset($data['data'])) {
            $token_data = $data['data'];
            
            // Tjek om token er gyldigt
            if (!isset($token_data['is_valid']) || !$token_data['is_valid']) {
                return new WP_Error('invalid_token', __('Access token er ugyldigt', 'fb-post-scheduler'));
            }
            
            // Tjek udløbsdato
            if (isset($token_data['expires_at'])) {
                $expires_at = intval($token_data['expires_at']);
                $days_until_expiry = ($expires_at - time()) / (24 * 60 * 60);
                
                return array(
                    'expires_at' => $expires_at,
                    'expires_date' => date('Y-m-d H:i:s', $expires_at),
                    'days_until_expiry' => round($days_until_expiry, 1),
                    'expires_soon' => $days_until_expiry < 7, // Advarsel hvis mindre end 7 dage
                    'app_id' => isset($token_data['app_id']) ? $token_data['app_id'] : null,
                    'user_id' => isset($token_data['user_id']) ? $token_data['user_id'] : null,
                    'scopes' => isset($token_data['scopes']) ? $token_data['scopes'] : array()
                );
            } else {
                // Token udløber aldrig (long-term token)
                return array(
                    'expires_at' => null,
                    'expires_date' => 'Aldrig',
                    'days_until_expiry' => null,
                    'expires_soon' => false,
                    'app_id' => isset($token_data['app_id']) ? $token_data['app_id'] : null,
                    'user_id' => isset($token_data['user_id']) ? $token_data['user_id'] : null,
                    'scopes' => isset($token_data['scopes']) ? $token_data['scopes'] : array()
                );
            }
        }
        
        return new WP_Error('no_data', __('Ingen token data modtaget', 'fb-post-scheduler'));
    }
}

/**
 * Helper funktion til at få instance af API-klassen
 */
function fb_post_scheduler_get_api() {
    static $api = null;
    
    if (null === $api) {
        $api = new FB_Post_Scheduler_API();
    }
    
    return $api;
}