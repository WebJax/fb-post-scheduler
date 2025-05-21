<?php
/**
 * AI Helper Functions for Facebook Post Scheduler
 * 
 * Håndterer integration med Google Gemini API for at generere Facebook-opslagstekst
 */

// Hvis denne fil kaldes direkte, så afbryd
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Generer tekst til Facebook-opslag med Google Gemini AI
 *
 * @param int $post_id Post ID
 * @return string|WP_Error Genereret tekst eller fejl
 */
function fb_post_scheduler_generate_ai_text($post_id) {
    // Kontroller om AI er aktiveret
    $ai_enabled = get_option('fb_post_scheduler_ai_enabled', '');
    if (empty($ai_enabled)) {
        return new WP_Error('ai_disabled', __('AI tekstgenerering er ikke aktiveret', 'fb-post-scheduler'));
    }
    
    // Få API nøgle
    $api_key = get_option('fb_post_scheduler_gemini_api_key', '');
    if (empty($api_key)) {
        return new WP_Error('missing_api_key', __('Google Gemini API nøgle er ikke konfigureret', 'fb-post-scheduler'));
    }
    
    // Hent post
    $post = get_post($post_id);
    if (!$post) {
        return new WP_Error('invalid_post', __('Ugyldig post ID', 'fb-post-scheduler'));
    }
    
    // Forbered content - kombiner titel og indhold
    $title = $post->post_title;
    $content = wp_strip_all_tags($post->post_content);
    
    // Begræns indhold til ca. 1000 tegn for at spare tokens
    if (strlen($content) > 1000) {
        $content = substr($content, 0, 1000) . '...';
    }
    
    // Hent prompt skabelon
    $prompt_template = get_option('fb_post_scheduler_ai_prompt', '');
    if (empty($prompt_template)) {
        $prompt_template = __('Skriv et kortfattet og engagerende Facebook-opslag på dansk baseret på følgende indhold. Opslaget skal være mellem 2-3 sætninger og motivere til at læse hele artiklen:', 'fb-post-scheduler');
    }
    
    // Samlet prompt
    $prompt = $prompt_template . "\n\nTitel: " . $title . "\n\nIndhold: " . $content;
    
    // Kald Gemini API
    $api_url = 'https://generativelanguage.googleapis.com/v1/models/gemini-1.5-flash:generateContent?key=' . $api_key;
    
    $body = array(
        'contents' => array(
            array(
                'role' => 'user',
                'parts' => array(
                    array(
                        'text' => $prompt
                    )
                )
            )
        ),
        'generationConfig' => array(
            'temperature' => 0.7,
            'maxOutputTokens' => 300,
            'topP' => 0.95,
            'topK' => 40
        )
    );
    
    $response = wp_remote_post($api_url, array(
        'headers' => array(
            'Content-Type' => 'application/json'
        ),
        'timeout' => 30,
        'body' => json_encode($body)
    ));
    
    // Tjek for fejl i API-kaldet
    if (is_wp_error($response)) {
        fb_post_scheduler_log('AI API Error: ' . $response->get_error_message(), $post_id);
        return $response;
    }
    
    $response_code = wp_remote_retrieve_response_code($response);
    if ($response_code !== 200) {
        $error_message = wp_remote_retrieve_response_message($response);
        fb_post_scheduler_log('AI API Error: ' . $error_message . ' (Code: ' . $response_code . ')', $post_id);
        return new WP_Error('api_error', $error_message . ' (Code: ' . $response_code . ')');
    }
    
    $body = json_decode(wp_remote_retrieve_body($response), true);
    
    // Tjek for fejl i API responsen
    if (isset($body['error'])) {
        $error_message = isset($body['error']['message']) ? $body['error']['message'] : __('Ukendt fejl fra Google Gemini API', 'fb-post-scheduler');
        fb_post_scheduler_log('AI API Error: ' . $error_message, $post_id);
        return new WP_Error('api_error', $error_message);
    }
    
    // Hent genereret tekst fra Gemini response
    if (!empty($body['candidates']) && !empty($body['candidates'][0]['content']['parts'])) {
        $generated_text = trim($body['candidates'][0]['content']['parts'][0]['text']);
        fb_post_scheduler_log('AI genereret tekst for post ID: ' . $post_id, $post_id);
        return $generated_text;
    }
    
    return new WP_Error('unknown_error', __('Kunne ikke generere tekst med AI', 'fb-post-scheduler'));
}