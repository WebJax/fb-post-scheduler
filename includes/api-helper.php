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
     * Opdater Facebooks cache af Open Graph-data for en URL.
     *
     * @param string $link URL der skal scrapes
     * @return array|WP_Error Scrape-svar eller fejl
     */
    public function scrape_url($link) {
        if (empty($this->access_token)) {
            return new WP_Error('missing_credentials', __('Manglende Facebook access token', 'fb-post-scheduler'));
        }

        if (empty($link)) {
            return new WP_Error('missing_link', __('Manglende URL til scrape', 'fb-post-scheduler'));
        }

        $response = wp_remote_post('https://graph.facebook.com/', array(
            'timeout' => 45,
            'body' => array(
                'id' => $link,
                'scrape' => 'true',
                'access_token' => $this->access_token,
            ),
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['error'])) {
            $message = isset($body['error']['message']) ? $body['error']['message'] : __('Ukendt scrape-fejl', 'fb-post-scheduler');
            return new WP_Error('facebook_scrape_error', $message);
        }

        return $body;
    }

    /**
     * Forbered link-preview billede og opdater Facebooks scrape-cache.
     *
     * @param string $link URL der postes
     * @param int $post_id WordPress post ID
     * @param int $image_id Attachment ID til preview-billede
     * @return void
     */
    private function prepare_link_preview($link, $post_id = 0, $image_id = 0) {
        $post_id = absint($post_id);
        $image_id = absint($image_id);

        if ($post_id && $image_id) {
            fb_post_scheduler_set_og_image_override($post_id, $image_id);
        }

        $scrape = $this->scrape_url($link);
        if (is_wp_error($scrape)) {
            fb_post_scheduler_log('Advarsel: Facebook scrape fejlede før posting: ' . $scrape->get_error_message(), $post_id ? $post_id : null);
        } else {
            $image_from_scrape = '';
            if (isset($scrape['image'][0]['url'])) {
                $image_from_scrape = $scrape['image'][0]['url'];
            } elseif (isset($scrape['og_object']['image'][0]['url'])) {
                $image_from_scrape = $scrape['og_object']['image'][0]['url'];
            }
            if ($image_from_scrape) {
                fb_post_scheduler_log('Facebook scrape OK. Preview-billede: ' . $image_from_scrape, $post_id ? $post_id : null);
            } else {
                fb_post_scheduler_log('Facebook scrape OK (ingen billede i scrape-svar)', $post_id ? $post_id : null);
            }
        }
    }

    /**
     * Ryd midlertidigt OG-override og genopfrisk cache til featured image.
     *
     * @param string $link URL der er postet
     * @param int $post_id WordPress post ID
     * @param int $image_id Attachment ID der blev brugt til preview
     * @return void
     */
    private function cleanup_link_preview($link, $post_id = 0, $image_id = 0) {
        $post_id = absint($post_id);
        $image_id = absint($image_id);

        if (!$post_id) {
            return;
        }

        fb_post_scheduler_clear_og_image_override($post_id);

        // Hvis et andet billede end featured blev brugt, scrape igen så
        // organiske delinger fremover viser featured image
        $featured_id = (int) get_post_thumbnail_id($post_id);
        if ($image_id && $featured_id && $image_id !== $featured_id) {
            $scrape = $this->scrape_url($link);
            if (is_wp_error($scrape)) {
                fb_post_scheduler_log('Advarsel: Kunne ikke genskrape featured image efter posting: ' . $scrape->get_error_message(), $post_id);
            }
        }
    }

    /**
     * Post til Facebook som link-opslag (klikbart preview til artiklen).
     *
     * Preview-billedet styres via og:image på den delte URL (valgt billede
     * eller featured image), ikke via photos-endpointet.
     *
     * @param string $message Beskedtekst til Facebook-opslag
     * @param string $link URL til at inkludere i opslaget
     * @param int $image_id Attachment ID til link-preview (optional)
     * @param int $post_id WordPress post ID (bruges til OG override/scrape)
     * @return array|WP_Error Response fra Facebook eller fejl
     */
    public function post_to_facebook($message, $link, $image_id = 0, $post_id = 0) {
        // Tjek at alle nødvendige indstillinger er sat
        if (empty($this->page_id) || empty($this->access_token)) {
            return new WP_Error('missing_credentials', __('Manglende Facebook API-indstillinger', 'fb-post-scheduler'));
        }

        $image_id = absint($image_id);
        $post_id = absint($post_id);

        $this->prepare_link_preview($link, $post_id, $image_id);

        // Page mentions must use @[PAGE_ID] in the message body.
        // @see https://developers.facebook.com/docs/graph-api/reference/page/feed/
        $message = $this->format_page_mentions($message);

        // API endpoint – altid link-post via feed
        $url = "https://graph.facebook.com/{$this->page_id}/feed";

        $data = array(
            'message' => $message,
            'link' => $link,
            'access_token' => $this->access_token,
        );

        // Send POST-anmodning til Facebook Graph API
        $response = wp_remote_post($url, array(
            'method' => 'POST',
            'timeout' => 45,
            'redirection' => 5,
            'httpversion' => '1.0',
            'blocking' => true,
            'body' => $data,
        ));

        $this->cleanup_link_preview($link, $post_id, $image_id);

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
    
    /**
     * Hent brugerens Facebook Grupper
     * 
     * @param string $user_access_token Bruger access token
     * @return array|WP_Error Liste af grupper eller fejl
     */
    public function get_user_groups($user_access_token) {
        if (empty($user_access_token)) {
            return new WP_Error('no_user_token', __('Bruger access token er påkrævet', 'fb-post-scheduler'));
        }
        
        $url = sprintf(
            'https://graph.facebook.com/me/groups?access_token=%s&fields=id,name,description,privacy,administrator',
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
            return new WP_Error('groups_error', sprintf(__('Fejl ved hentning af grupper: %s', 'fb-post-scheduler'), $data['error']['message']));
        }
        
        if (isset($data['data']) && is_array($data['data'])) {
            $groups = array();
            
            foreach ($data['data'] as $group) {
                // Filtrer kun grupper hvor brugeren kan lave indlæg (administrator eller moderator)
                if (isset($group['administrator']) && $group['administrator']) {
                    $groups[] = array(
                        'id' => $group['id'],
                        'name' => isset($group['name']) ? $group['name'] : 'Unavngivet gruppe',
                        'description' => isset($group['description']) ? $group['description'] : '',
                        'privacy' => isset($group['privacy']) ? $group['privacy'] : 'UNKNOWN',
                        'type' => 'group'
                    );
                }
            }
            
            return $groups;
        }
        
        return array();
    }
    
    /**
     * Search public Facebook Pages for @-mention autocomplete.
     *
     * Official Meta API references (App Review):
     *
     * Pages Search endpoint:
     * @link https://developers.facebook.com/docs/pages-api/search-pages
     * GET https://graph.facebook.com/{version}/pages/search?q={search_term}&fields=id,name&access_token={token}
     *
     * Search requires one of the following (request these in Meta App Review):
     * - pages_read_engagement (Pages the user manages)
     * - Page Public Metadata Access
     *   https://developers.facebook.com/docs/apps/review/feature#page-public-metadata-access
     * - Page Public Content Access
     *   https://developers.facebook.com/docs/apps/review/feature#reference-PAGES_ACCESS
     *
     * Page mentioning in published posts uses message syntax @[PAGE_ID]:
     * @link https://developers.facebook.com/docs/graph-api/reference/page/feed/
     * Required permission: pages_manage_posts
     * Related App Review feature: Page Mentions
     * @link https://developers.facebook.com/docs/pages/mentions
     * @link https://developers.facebook.com/docs/apps/review/login-permissions#manage-pages
     *
     * Token order for /pages/search (Page tokens usually fail this endpoint):
     * 1. User token (`fb_post_scheduler_facebook_user_token`) — pages_read_engagement
     *    only covers Pages the user manages.
     * 2. App token (`{app_id}|{app_secret}`) — required for PPMA/PPCA public search.
     * 3. Page token (`fb_post_scheduler_facebook_access_token`) — last resort.
     *
     * Login permissions (pages_manage_posts, pages_read_engagement, …) are not enough
     * to search arbitrary public Pages. That needs the App Review *features*
     * Page Public Metadata Access or Page Public Content Access.
     *
     * @param string $search_term Query typed after '@' (min. 3 characters).
     * @return array|WP_Error Array of array( 'id' => string, 'name' => string ) or error.
     */
    public function search_pages($search_term) {
        $search_term = is_string($search_term) ? trim($search_term) : '';
        $term_length = function_exists('mb_strlen') ? mb_strlen($search_term, 'UTF-8') : strlen($search_term);

        if ($term_length < 3) {
            return new WP_Error('invalid_query', __('Søgningen skal være mindst 3 tegn', 'fb-post-scheduler'));
        }

        $pages = $this->search_managed_pages($search_term);
        $tokens = $this->get_pages_search_tokens();
        $last_error = null;

        if (empty($tokens) && empty($pages)) {
            return new WP_Error('missing_token', __('Mangler Facebook access token til sidesøgning', 'fb-post-scheduler'));
        }

        foreach ($tokens as $access_token) {
            $result = $this->request_pages_search($search_term, $access_token);

            if (is_wp_error($result)) {
                $last_error = $result;
                continue;
            }

            $pages = $this->merge_page_results($pages, $result);

            if (!empty($result)) {
                break;
            }
        }

        if (!empty($pages)) {
            return array_slice($pages, 0, 8);
        }

        if ($last_error) {
            return $this->humanize_pages_search_error($last_error);
        }

        return array();
    }

    /**
     * Tokens to try for GET /pages/search, most likely to succeed first.
     *
     * @return string[]
     */
    private function get_pages_search_tokens() {
        $tokens = array();

        $user_token = get_option('fb_post_scheduler_facebook_user_token', '');
        if (!empty($user_token)) {
            $tokens[] = $user_token;
        }

        if (!empty($this->app_id) && !empty($this->app_secret)) {
            $tokens[] = $this->app_id . '|' . $this->app_secret;
        }

        if (!empty($this->access_token)) {
            $tokens[] = $this->access_token;
        }

        return array_values(array_unique($tokens));
    }

    /**
     * Call Meta Pages Search with a single access token.
     *
     * @param string $search_term
     * @param string $access_token
     * @return array|WP_Error
     */
    private function request_pages_search($search_term, $access_token) {
        $url = add_query_arg(
            array(
                'q'            => $search_term,
                'fields'       => 'id,name',
                'access_token' => $access_token,
            ),
            'https://graph.facebook.com/v18.0/pages/search'
        );

        $response = wp_remote_get($url, array(
            'timeout'     => 15,
            'redirection' => 3,
        ));

        if (is_wp_error($response)) {
            return new WP_Error(
                'api_error',
                sprintf(__('API forespørgsel fejlede: %s', 'fb-post-scheduler'), $response->get_error_message())
            );
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!is_array($body)) {
            return new WP_Error('api_error', __('Ugyldigt svar fra Facebook Pages Search', 'fb-post-scheduler'));
        }

        if (isset($body['error']['message'])) {
            return new WP_Error('pages_search_error', $body['error']['message']);
        }

        if ($code < 200 || $code >= 300) {
            return new WP_Error('api_error', __('Facebook Pages Search returnerede en fejl', 'fb-post-scheduler'));
        }

        $pages = array();
        if (!empty($body['data']) && is_array($body['data'])) {
            foreach ($body['data'] as $page) {
                $normalized = $this->normalize_page_search_result($page);
                if ($normalized) {
                    $pages[] = $normalized;
                }
            }
        }

        return $pages;
    }

    /**
     * Filter Pages the connected user already manages (works with current login permissions).
     *
     * @param string $search_term
     * @return array
     */
    private function search_managed_pages($search_term) {
        $user_token = get_option('fb_post_scheduler_facebook_user_token', '');
        if (empty($user_token)) {
            return array();
        }

        $managed = $this->get_user_pages($user_token);
        if (is_wp_error($managed) || empty($managed)) {
            return array();
        }

        $matches = array();
        foreach ($managed as $page) {
            $name = isset($page['name']) ? $page['name'] : '';
            if (!$this->page_name_matches($name, $search_term)) {
                continue;
            }

            $normalized = $this->normalize_page_search_result($page);
            if ($normalized) {
                $matches[] = $normalized;
            }
        }

        return $matches;
    }

    /**
     * @param array $page
     * @return array|null
     */
    private function normalize_page_search_result($page) {
        if (empty($page['id']) || empty($page['name'])) {
            return null;
        }

        $id = preg_replace('/[^0-9]/', '', (string) $page['id']);
        if ($id === '') {
            return null;
        }

        return array(
            'id'   => $id,
            'name' => sanitize_text_field($page['name']),
        );
    }

    /**
     * @param array $existing
     * @param array $incoming
     * @return array
     */
    private function merge_page_results($existing, $incoming) {
        $seen = array();
        $merged = array();

        foreach (array_merge($existing, $incoming) as $page) {
            if (empty($page['id']) || isset($seen[$page['id']])) {
                continue;
            }
            $seen[$page['id']] = true;
            $merged[] = $page;
        }

        return $merged;
    }

    /**
     * @param string $name
     * @param string $term
     * @return bool
     */
    private function page_name_matches($name, $term) {
        if (function_exists('mb_stripos')) {
            return mb_stripos($name, $term, 0, 'UTF-8') !== false;
        }

        return stripos($name, $term) !== false;
    }

    /**
     * Translate Graph (#10) into an actionable Danish message.
     *
     * @param WP_Error $error
     * @return WP_Error
     */
    private function humanize_pages_search_error($error) {
        $message = $error->get_error_message();

        if (
            strpos($message, '(#10)') !== false
            || stripos($message, 'Page Public') !== false
            || stripos($message, 'pages_read_engagement') !== false
        ) {
            return new WP_Error(
                'pages_search_feature',
                __('Offentlig @-søgning kræver App Review-featuren “Page Public Metadata Access” (ikke en login-permission). Dine nuværende permissions (pages_read_engagement, pages_manage_posts m.fl.) dækker kun sider du administrerer. Tilføj featuren under Meta App Dashboard → App Review → Permissions and Features. Indtil den er godkendt: indsæt @[PAGE_ID] manuelt, eller tag kun egne sider.', 'fb-post-scheduler')
            );
        }

        return $error;
    }

    /**
     * Normalize Page @-mentions for the Graph API `message` parameter.
     *
     * Editor may store mentions as @[PAGE_ID:Page Name] for readability.
     * Facebook requires the official syntax @[PAGE_ID] in the published payload.
     *
     * Example: "Check out this article about @[123456789]!"
     *
     * @link https://developers.facebook.com/docs/graph-api/reference/page/feed/
     *
     * @param string $message Raw post text from the editor.
     * @return string Message with mentions in @[PAGE_ID] form.
     */
    public function format_page_mentions($message) {
        if (!is_string($message) || $message === '') {
            return $message;
        }

        $formatted = preg_replace('/@\[(\d+)(?::[^\]]*)?\]/', '@[$1]', $message);

        return is_string($formatted) ? $formatted : $message;
    }

    /**
     * Post til Facebook Gruppe som link-opslag (klikbart preview til artiklen).
     *
     * @param string $message Beskedtekst til Facebook-opslag
     * @param string $link URL til at inkludere i opslaget
     * @param string $group_id Facebook gruppe ID
     * @param int $image_id Attachment ID til link-preview (optional)
     * @param int $post_id WordPress post ID (bruges til OG override/scrape)
     * @return array|WP_Error Response fra Facebook eller fejl
     */
    public function post_to_facebook_group($message, $link, $group_id, $image_id = 0, $post_id = 0) {
        // Tjek at alle nødvendige indstillinger er sat
        if (empty($group_id) || empty($this->access_token)) {
            return new WP_Error('missing_credentials', __('Manglende Facebook API-indstillinger for gruppe', 'fb-post-scheduler'));
        }

        $image_id = absint($image_id);
        $post_id = absint($post_id);

        $this->prepare_link_preview($link, $post_id, $image_id);

        // Page mentions must use @[PAGE_ID] in the message body.
        // @see https://developers.facebook.com/docs/graph-api/reference/page/feed/
        $message = $this->format_page_mentions($message);

        // API endpoint for gruppe – altid link-post via feed
        $url = "https://graph.facebook.com/{$group_id}/feed";

        $data = array(
            'message' => $message,
            'link' => $link,
            'access_token' => $this->access_token,
        );

        // Send POST-anmodning til Facebook Graph API
        $response = wp_remote_post($url, array(
            'method' => 'POST',
            'timeout' => 45,
            'redirection' => 5,
            'httpversion' => '1.0',
            'blocking' => true,
            'body' => $data,
        ));

        $this->cleanup_link_preview($link, $post_id, $image_id);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['error'])) {
            return new WP_Error('facebook_error', $body['error']['message']);
        }

        return $body;
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