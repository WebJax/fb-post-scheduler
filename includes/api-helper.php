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
     *
     * @var string
     */
    private $app_id;
    
    /**
     * Facebook App Secret
     *
     * @var string
     */
    private $app_secret;
    
    /**
     * Facebook Page ID
     *
     * @var string
     */
    private $page_id;
    
    /**
     * Facebook Access Token
     *
     * @var string
     */
    private $access_token;

    /**
     * Besked når Business-sider ikke kunne hentes.
     *
     * @var string
     */
    private $pages_notice = '';
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->app_id = trim((string) get_option('fb_post_scheduler_facebook_app_id', ''));
        $this->app_secret = trim((string) get_option('fb_post_scheduler_facebook_app_secret', ''));
        $this->page_id = trim((string) get_option('fb_post_scheduler_facebook_page_id', ''));
        $this->access_token = trim((string) get_option('fb_post_scheduler_facebook_access_token', ''));
    }

    /**
     * Facebook-fejl som én læsbar sætning, med kode og fbtrace_id.
     *
     * @param array  $data
     * @param string $fallback
     * @return string
     */
    private function graph_error_message($data, $fallback) {
        if (!isset($data['error']) || !is_array($data['error'])) {
            return $fallback;
        }

        $message = isset($data['error']['message']) ? (string) $data['error']['message'] : $fallback;
        $code = isset($data['error']['code']) ? (string) $data['error']['code'] : '';
        $trace = isset($data['error']['fbtrace_id']) ? (string) $data['error']['fbtrace_id'] : '';
        $detail = $message;

        if ($code !== '') {
            $detail .= ' (kode ' . $code . ')';
        }
        if ($trace !== '') {
            $detail .= ' [fbtrace_id ' . $trace . ']';
        }

        return $detail;
    }

    /**
     * @param WP_Error $error
     * @return int
     */
    private function graph_error_code($error) {
        $data = $error->get_error_data();
        if (!is_array($data) || !isset($data['code'])) {
            return 0;
        }

        return (int) $data['code'];
    }

    /**
     * @param array|WP_Error $response
     * @return array|WP_Error
     */
    private function decode_graph_response($response) {
        if (is_wp_error($response)) {
            return new WP_Error('api_error', sprintf(__('API forespørgsel fejlede: %s', 'fb-post-scheduler'), $response->get_error_message()));
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data)) {
            return new WP_Error('api_error', __('Ugyldigt svar fra Facebook', 'fb-post-scheduler'));
        }
        if (isset($data['error'])) {
            return new WP_Error('facebook_error', $this->graph_error_message($data, __('Uventet svar fra Facebook', 'fb-post-scheduler')), $data['error']);
        }

        return $data;
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
     * Læs Facebooks cached Open Graph-objekt for en URL uden at scrape.
     *
     * @param string $link URL der skal slås op
     * @return array|WP_Error Graph-svar eller fejl
     */
    public function get_url_og_object($link) {
        if (empty($this->access_token)) {
            return new WP_Error('missing_credentials', __('Manglende Facebook access token', 'fb-post-scheduler'));
        }

        if (empty($link)) {
            return new WP_Error('missing_link', __('Manglende URL til forhåndsvisning', 'fb-post-scheduler'));
        }

        $request_url = add_query_arg(
            array(
                'id' => $link,
                'fields' => 'og_object{title,description,image}',
            ),
            'https://graph.facebook.com/'
        );

        $response = wp_remote_get($request_url, array(
            'timeout' => 20,
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->access_token,
            ),
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['error'])) {
            $message = isset($body['error']['message']) ? $body['error']['message'] : __('Ukendt Facebook-fejl', 'fb-post-scheduler');
            return new WP_Error('facebook_og_lookup_error', $message);
        }

        return is_array($body) ? $body : new WP_Error('facebook_og_lookup_error', __('Ugyldigt svar fra Facebook', 'fb-post-scheduler'));
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

        if (is_wp_error($response)) {
            $this->cleanup_link_preview($link, $post_id, $image_id);
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (is_array($body) && isset($body['error']) && $this->is_page_publish_permission_error($body['error'])) {
            if ($this->refresh_stored_page_token()) {
                $data['access_token'] = $this->access_token;
                $response = wp_remote_post($url, array(
                    'method' => 'POST',
                    'timeout' => 45,
                    'redirection' => 5,
                    'httpversion' => '1.0',
                    'blocking' => true,
                    'body' => $data,
                ));
                if (!is_wp_error($response)) {
                    $body = json_decode(wp_remote_retrieve_body($response), true);
                }
            }
        }

        $this->cleanup_link_preview($link, $post_id, $image_id);

        if (is_array($body) && isset($body['error'])) {
            $message = $this->is_page_publish_permission_error($body['error'])
                ? $this->page_publish_permission_message()
                : $body['error']['message'];
            return new WP_Error('facebook_api_error', $message);
        }

        return $body;
    }

    /**
     * Facebook afviser opslag, når side-tokenet mangler de to side-tilladelser
     * eller personen ikke er administrator/redaktør på siden.
     *
     * @param array $error
     * @return bool
     */
    private function is_page_publish_permission_error($error) {
        $message = isset($error['message']) ? (string) $error['message'] : '';
        return strpos($message, 'pages_manage_posts') !== false
            && strpos($message, 'pages_read_engagement') !== false;
    }

    /**
     * @return string
     */
    private function page_publish_permission_message() {
        return __('Facebook afviser opslaget. Side-tokenet skal have både pages_read_engagement og pages_manage_posts, og du skal være administrator eller redaktør på siden. En rolle i Business-kontoen er ikke nok. Tildel dig selv rollen på siden, og opret side-tokenet igen.', 'fb-post-scheduler');
    }

    /**
     * Hent et nyt side-token fra det gemte bruger-token og gem det, hvis det kan udgive.
     *
     * @return bool
     */
    private function refresh_stored_page_token() {
        $user_token = (string) get_option('fb_post_scheduler_facebook_user_token', '');
        if ($user_token === '' || $this->page_id === '') {
            return false;
        }

        $token = $this->fetch_page_access_token($this->page_id, $user_token);
        if ($token === '' || $token === $this->access_token) {
            return false;
        }

        $debug = $this->debug_access_token($token);
        if (is_wp_error($debug) || !$this->token_can_publish($debug)) {
            return false;
        }

        $this->access_token = $token;
        update_option('fb_post_scheduler_facebook_access_token', $token);
        return true;
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

        $data = $this->request_token_exchange($short_term_token);
        if (is_wp_error($data) && $this->graph_error_code($data) === 2) {
            sleep(2);
            $data = $this->request_token_exchange($short_term_token);
        }
        if (is_wp_error($data)) {
            return $data;
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
     * POST til OAuth-udvekslingen. Token og secret sendes i body, ikke i URL'en.
     *
     * @param string $short_term_token
     * @return array|WP_Error
     */
    private function request_token_exchange($short_term_token) {
        $response = wp_remote_post('https://graph.facebook.com/oauth/access_token', array(
            'timeout' => 30,
            'headers' => array(
                'User-Agent' => 'WordPress/Facebook-Post-Scheduler',
            ),
            'body' => array(
                'grant_type' => 'fb_exchange_token',
                'client_id' => $this->app_id,
                'client_secret' => $this->app_secret,
                'fb_exchange_token' => $short_term_token,
            ),
        ));

        $data = $this->decode_graph_response($response);
        if (is_wp_error($data)) {
            $error_data = $data->get_error_data();
            return new WP_Error(
                'token_exchange_error',
                $data->get_error_message(),
                is_array($error_data) ? $error_data : array()
            );
        }

        return $data;
    }

    /**
     * Læs et vilkårligt token med app-tokenet.
     *
     * @param string $input_token
     * @return array|WP_Error
     */
    public function debug_access_token($input_token) {
        if ($input_token === '' || $this->app_id === '' || $this->app_secret === '') {
            return new WP_Error('missing_config', __('App ID, App Secret og access token er påkrævet', 'fb-post-scheduler'));
        }

        $url = add_query_arg(
            array(
                'input_token' => $input_token,
                'access_token' => $this->app_id . '|' . $this->app_secret,
            ),
            'https://graph.facebook.com/debug_token'
        );

        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'headers' => array(
                'User-Agent' => 'WordPress/Facebook-Post-Scheduler',
            ),
        ));

        $data = $this->decode_graph_response($response);
        if (is_wp_error($data)) {
            return $data;
        }
        if (empty($data['data']) || !is_array($data['data'])) {
            return new WP_Error('no_data', __('Ingen token-data modtaget fra Facebook', 'fb-post-scheduler'));
        }

        return $data['data'];
    }

    /**
     * Gør et indsat token klar til GET /me/accounts.
     *
     * Side-tokens kan ikke udveksles. Et gyldigt bruger-token udveksles til et
     * long-lived token. Er det allerede long-lived, bruges det, hvis udvekslingen fejler.
     *
     * @param string $token
     * @return string|WP_Error
     */
    public function resolve_long_lived_user_token($token) {
        $debug = $this->debug_access_token($token);
        if (is_wp_error($debug)) {
            return new WP_Error(
                'token_debug_error',
                sprintf(__('Facebook kunne ikke læse tokenet: %s', 'fb-post-scheduler'), $debug->get_error_message())
            );
        }

        $type = isset($debug['type']) ? strtoupper((string) $debug['type']) : '';
        if ($type === 'PAGE') {
            return new WP_Error(
                'page_token',
                __('Det indsatte token er et side-token. I Graph API Explorer skal du vælge denne app, tilføje tilladelserne og generere et bruger-token. Side-tokenet fra den avancerede sektion kan ikke udveksles.', 'fb-post-scheduler')
            );
        }
        if ($type !== '' && $type !== 'USER') {
            return new WP_Error(
                'wrong_token_type',
                sprintf(__('Tokenet er af typen %s. Der skal bruges et bruger-token fra Graph API Explorer.', 'fb-post-scheduler'), $type)
            );
        }

        $token_app_id = isset($debug['app_id']) ? (string) $debug['app_id'] : '';
        if ($token_app_id !== '' && $token_app_id !== (string) $this->app_id) {
            return new WP_Error(
                'wrong_app',
                __('Tokenet er udstedt til en anden Facebook-app end det gemte App ID. Vælg den samme app øverst i Graph API Explorer, og generér tokenet igen.', 'fb-post-scheduler')
            );
        }
        if (empty($debug['is_valid'])) {
            $reason = isset($debug['error']['message']) ? (string) $debug['error']['message'] : '';
            $message = __('Bruger-tokenet er ugyldigt eller udløbet. Generér et nyt i Graph API Explorer.', 'fb-post-scheduler');
            if ($reason !== '') {
                $message .= ' ' . $reason;
            }
            return new WP_Error('invalid_token', $message);
        }

        $exchanged = $this->exchange_for_long_term_token($token);
        if (!is_wp_error($exchanged) && !empty($exchanged['access_token'])) {
            return $exchanged['access_token'];
        }

        $expires_at = isset($debug['expires_at']) ? (int) $debug['expires_at'] : 0;
        $already_long_lived = ($expires_at === 0) || ($expires_at > time() + DAY_IN_SECONDS);
        if ($already_long_lived) {
            return $token;
        }

        $reason = is_wp_error($exchanged) ? $exchanged->get_error_message() : __('Ingen access token modtaget fra Facebook', 'fb-post-scheduler');
        return new WP_Error(
            'token_exchange_error',
            sprintf(
                __('Facebook afviste udvekslingen til et long-lived bruger-token: %s. Tokenet skal være et nyt bruger-token fra samme app, ikke et side-token.', 'fb-post-scheduler'),
                $reason
            )
        );
    }
    
    /**
     * Graph API sætter expires_at til 0 når tokenet ikke udløber.
     *
     * @param mixed $expires_at
     * @return int Unix-tid, eller 0 når tokenet ikke udløber.
     */
    private function normalize_expires_at($expires_at) {
        if (!is_numeric($expires_at)) {
            return 0;
        }

        $expires_at = (int) $expires_at;

        return $expires_at > 0 ? $expires_at : 0;
    }

    /**
     * Tjek om access token snart udløber.
     *
     * @return array|WP_Error Token-info. expires_at er 0 når tokenet ikke udløber.
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
            
            // Tjek udløbsdato. Graph API bruger 0 når et page token ikke udløber.
            $expires_at = $this->normalize_expires_at(isset($token_data['expires_at']) ? $token_data['expires_at'] : 0);
            if ($expires_at > 0) {
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
            }

            return array(
                'expires_at' => 0,
                'expires_date' => 'Aldrig',
                'days_until_expiry' => null,
                'expires_soon' => false,
                'app_id' => isset($token_data['app_id']) ? $token_data['app_id'] : null,
                'user_id' => isset($token_data['user_id']) ? $token_data['user_id'] : null,
                'scopes' => isset($token_data['scopes']) ? $token_data['scopes'] : array()
            );
        }
        
        return new WP_Error('no_data', __('Ingen token data modtaget', 'fb-post-scheduler'));
    }
    
    /**
     * Besked hvis sider i en Business-konto ikke kunne hentes.
     *
     * @return string
     */
    public function get_pages_notice() {
        return $this->pages_notice;
    }

    /**
     * Hent sider brugeren kan udgive på, både direkte roller og sider i en Business-konto.
     *
     * /me/accounts dækker sider med en rolle på personen. Sider der kun ligger i
     * Business-kontoen kræver business_management og hentes via owned_pages og client_pages.
     *
     * @param string $user_access_token Bruger access token
     * @return array|WP_Error Liste af sider eller fejl
     */
    public function get_user_pages($user_access_token) {
        $this->pages_notice = '';

        if (empty($user_access_token)) {
            return new WP_Error('no_user_token', __('Bruger access token er påkrævet', 'fb-post-scheduler'));
        }

        $direct = $this->fetch_graph_edge('me/accounts', $user_access_token, 'id,name,access_token,category,tasks');
        if (is_wp_error($direct)) {
            return $direct;
        }

        $pages = $this->collect_postable_pages($direct);
        $businesses = $this->fetch_graph_edge('me/businesses', $user_access_token, 'id,name');
        if (is_wp_error($businesses)) {
            if ($this->is_missing_business_permission($businesses)) {
                $this->pages_notice = __('Sider i Business-kontoen kræver tilladelsen business_management. Generér bruger-tokenet igen med den tilladelse.', 'fb-post-scheduler');
                return $this->with_publish_tokens($pages, $user_access_token);
            }
            if (empty($pages)) {
                return $businesses;
            }
            return $this->with_publish_tokens($pages, $user_access_token);
        }

        foreach ($businesses as $business) {
            if (empty($business['id'])) {
                continue;
            }
            foreach (array('owned_pages', 'client_pages') as $edge) {
                $found = $this->fetch_graph_edge($business['id'] . '/' . $edge, $user_access_token, 'id,name,access_token,category,tasks');
                if (is_wp_error($found)) {
                    continue;
                }
                $pages = $this->merge_postable_pages($pages, $this->collect_postable_pages($found));
            }
        }

        return $this->with_publish_tokens($pages, $user_access_token);
    }

    /**
     * Side-tokenet fra /{page-id}?fields=access_token arver bruger-tokenets tilladelser.
     * Tokenet fra Business-kontoens sideliste kan mangle pages_manage_posts.
     *
     * @param array  $pages
     * @param string $user_access_token
     * @return array
     */
    private function with_publish_tokens($pages, $user_access_token) {
        foreach ($pages as $index => $page) {
            if (empty($page['id'])) {
                continue;
            }
            $token = $this->fetch_page_access_token($page['id'], $user_access_token);
            if ($token !== '') {
                $pages[$index]['access_token'] = $token;
            }
        }
        return $pages;
    }

    /**
     * @param string $page_id
     * @param string $user_access_token
     * @return string
     */
    private function fetch_page_access_token($page_id, $user_access_token) {
        $url = add_query_arg(
            array(
                'fields' => 'access_token',
                'access_token' => $user_access_token,
            ),
            'https://graph.facebook.com/' . rawurlencode((string) $page_id)
        );

        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'headers' => array(
                'User-Agent' => 'WordPress/Facebook-Post-Scheduler',
            ),
        ));
        $data = $this->decode_graph_response($response);
        if (is_wp_error($data) || empty($data['access_token']) || !is_string($data['access_token'])) {
            return '';
        }

        return $data['access_token'];
    }

    /**
     * @param array $token_data debug_token-data
     * @return string[]
     */
    private function token_scope_names($token_data) {
        $names = array();
        if (!empty($token_data['scopes']) && is_array($token_data['scopes'])) {
            foreach ($token_data['scopes'] as $scope) {
                if (is_string($scope) && $scope !== '') {
                    $names[] = $scope;
                }
            }
        }
        if (!empty($token_data['granular_scopes']) && is_array($token_data['granular_scopes'])) {
            foreach ($token_data['granular_scopes'] as $scope) {
                if (is_array($scope) && !empty($scope['scope'])) {
                    $names[] = (string) $scope['scope'];
                }
            }
        }
        return array_values(array_unique($names));
    }

    /**
     * @param array $token_data
     * @return bool
     */
    private function token_can_publish($token_data) {
        $type = isset($token_data['type']) ? strtoupper((string) $token_data['type']) : '';
        if ($type !== '' && $type !== 'PAGE') {
            return false;
        }
        $scopes = $this->token_scope_names($token_data);
        if ($scopes === array()) {
            return true;
        }
        return in_array('pages_manage_posts', $scopes, true)
            && in_array('pages_read_engagement', $scopes, true);
    }

    /**
     * Tom streng når tokenet kan udgive. Ellers en forklaring til brugeren.
     *
     * @param array $token_info
     * @return string
     */
    public function explain_unpublished_page_token($token_info) {
        $type = isset($token_info['token_type']) ? (string) $token_info['token_type'] : '';
        $scopes = isset($token_info['scopes']) && is_array($token_info['scopes']) ? $token_info['scopes'] : array();
        if ($this->token_can_publish(array('type' => $type, 'scopes' => $scopes))) {
            return '';
        }
        if (strtoupper($type) === 'USER') {
            return __('Facebook returnerede et bruger-token. Opslag kræver et side-token fra en side, hvor du er administrator eller redaktør.', 'fb-post-scheduler');
        }
        return $this->page_publish_permission_message();
    }

    /**
     * @param WP_Error $error
     * @return bool
     */
    private function is_missing_business_permission($error) {
        $message = strtolower($error->get_error_message());
        return strpos($message, 'business_management') !== false || $this->graph_error_code($error) === 200;
    }

    /**
     * @param array $existing
     * @param array $incoming
     * @return array
     */
    private function merge_postable_pages($existing, $incoming) {
        $by_id = array();
        foreach (array_merge($existing, $incoming) as $page) {
            if (empty($page['id'])) {
                continue;
            }
            $by_id[(string) $page['id']] = $page;
        }
        return array_values($by_id);
    }

    /**
     * @param array $raw_pages
     * @return array
     */
    private function collect_postable_pages($raw_pages) {
        $pages = array();
        foreach ($raw_pages as $page) {
            if (empty($page['id']) || empty($page['access_token'])) {
                continue;
            }
            $tasks = isset($page['tasks']) && is_array($page['tasks']) ? $page['tasks'] : array();
            if (!$this->page_can_publish($tasks)) {
                continue;
            }
            $pages[] = array(
                'id' => (string) $page['id'],
                'name' => isset($page['name']) ? $page['name'] : 'Unavngivet side',
                'category' => isset($page['category']) ? $page['category'] : '',
                'access_token' => $page['access_token'],
                'tasks' => $tasks,
            );
        }
        return $pages;
    }

    /**
     * Tom opgaveliste beholdes, fordi Business-kanten nogle gange udelader tasks.
     * Moderator, annoncør og analytiker kan ikke udgive opslag.
     *
     * @param array $tasks
     * @return bool
     */
    private function page_can_publish($tasks) {
        if ($tasks === array()) {
            return true;
        }
        foreach ($tasks as $task) {
            $task = strtoupper((string) $task);
            if ($task === 'MANAGE' || $task === 'CREATE_CONTENT' || $task === 'PROFILE_PLUS_MANAGE' || $task === 'PROFILE_PLUS_CREATE_CONTENT') {
                return true;
            }
        }
        return false;
    }

    /**
     * Hent alle rækker fra et Graph-edge, inklusive næste side.
     *
     * @param string $path
     * @param string $access_token
     * @param string $fields
     * @return array|WP_Error
     */
    private function fetch_graph_edge($path, $access_token, $fields) {
        $items = array();
        $url = add_query_arg(
            array(
                'fields' => $fields,
                'limit' => 50,
                'access_token' => $access_token,
            ),
            'https://graph.facebook.com/' . ltrim($path, '/')
        );

        for ($page = 0; $page < 10 && $url !== ''; $page++) {
            $response = wp_remote_get($url, array(
                'timeout' => 30,
                'headers' => array(
                    'User-Agent' => 'WordPress/Facebook-Post-Scheduler',
                ),
            ));
            $data = $this->decode_graph_response($response);
            if (is_wp_error($data)) {
                return $items === array() ? $data : $items;
            }
            if (!empty($data['data']) && is_array($data['data'])) {
                $items = array_merge($items, $data['data']);
            }
            $url = isset($data['paging']['next']) ? (string) $data['paging']['next'] : '';
        }

        return $items;
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
            
            // Page access tokens udløber normalt aldrig. 0 fra Graph API betyder "udløber ikke".
            $expires_at = $this->normalize_expires_at(isset($token_data['expires_at']) ? $token_data['expires_at'] : 0);
            $expires_date = $expires_at > 0 ? date('Y-m-d H:i:s', $expires_at) : 'Aldrig';
            
            return array(
                'access_token' => $page_access_token,
                'token_type' => isset($token_data['type']) ? $token_data['type'] : 'page',
                'expires_at' => $expires_at,
                'expires_date' => $expires_date,
                'app_id' => isset($token_data['app_id']) ? $token_data['app_id'] : null,
                'scopes' => $this->token_scope_names($token_data)
            );
        }
        
        return new WP_Error('no_data', __('Ingen token data modtaget', 'fb-post-scheduler'));
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