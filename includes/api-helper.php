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
    
    /**
     * Hent brugerens Facebook Pages
     * 
     * @param string $user_access_token Bruger access token
     * @return array|WP_Error Liste af sider eller fejl
     */
    public function get_user_pages($user_access_token) {
        if (empty($user_access_token)) {
            return new WP_Error('no_user_token', __('Bruger access token er påkrævet', 'fb-post-scheduler'));
        }
        
        $url = sprintf(
            'https://graph.facebook.com/me/accounts?access_token=%s&fields=id,name,access_token,category,tasks',
            urlencode($user_access_token)
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
            return new WP_Error('pages_error', sprintf(__('Fejl ved hentning af sider: %s', 'fb-post-scheduler'), $data['error']['message']));
        }
        
        if (isset($data['data']) && is_array($data['data'])) {
            $pages = array();
            
            foreach ($data['data'] as $page) {
                // Filtrer kun sider hvor brugeren kan lave indlæg
                if (isset($page['tasks']) && is_array($page['tasks']) && 
                    (in_array('MODERATE', $page['tasks']) || in_array('CREATE_CONTENT', $page['tasks']))) {
                    $pages[] = array(
                        'id' => $page['id'],
                        'name' => isset($page['name']) ? $page['name'] : 'Unavngivet side',
                        'category' => isset($page['category']) ? $page['category'] : '',
                        'access_token' => isset($page['access_token']) ? $page['access_token'] : '',
                        'tasks' => $page['tasks']
                    );
                }
            }
            
            return $pages;
        }
        
        return array();
    }
    
    /**
     * Udveksle page access token til long-term page access token
     * 
     * @param string $page_access_token Page access token
     * @return array|WP_Error Long-term token info eller fejl
     */
    public function exchange_for_page_long_term_token($page_access_token) {
        if (empty($page_access_token)) {
            return new WP_Error('no_page_token', __('Page access token er påkrævet', 'fb-post-scheduler'));
        }
        
        if (empty($this->app_id) || empty($this->app_secret)) {
            return new WP_Error('no_app_credentials', __('Facebook App ID og App Secret skal være konfigureret', 'fb-post-scheduler'));
        }
        
        // Page access tokens bliver automatisk long-term når de hentes via bruger long-term token
        // Vi tjekker bare om token er gyldigt og returnerer info
        $url = sprintf(
            'https://graph.facebook.com/debug_token?input_token=%s&access_token=%s',
            urlencode($page_access_token),
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
                return new WP_Error('invalid_token', __('Page access token er ugyldigt', 'fb-post-scheduler'));
            }
            
            // Page access tokens udløber normalt aldrig, men tjek hvis der er et udløb
            if (isset($token_data['expires_at'])) {
                $expires_at = intval($token_data['expires_at']);
                $expires_date = date('Y-m-d H:i:s', $expires_at);
            } else {
                $expires_at = null;
                $expires_date = 'Aldrig';
            }
            
            return array(
                'access_token' => $page_access_token,
                'token_type' => 'page',
                'expires_at' => $expires_at,
                'expires_date' => $expires_date,
                'app_id' => isset($token_data['app_id']) ? $token_data['app_id'] : null,
                'scopes' => isset($token_data['scopes']) ? $token_data['scopes'] : array()
            );
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