<?php
/**
 * AI Helper Function for Facebook Post Scheduler
 * 
 * Håndterer integration med Google Gemma API for at generere Facebook-opslagstekst
 */

// Hvis denne fil kaldes direkte, så afbryd
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Generer tekst til Facebook-opslag med lokal Ollama (Gemma 4)
 *
 * @param int $post_id Post ID
 * @return string|WP_Error Genereret tekst eller fejl
 */
function fb_post_scheduler_generate_ai_text( $post_id ) {
    // Hent post
    $post = get_post( $post_id );
    if ( ! $post ) {
        return new WP_Error( 'invalid_post', __( 'Ugyldig post ID', 'fb-post-scheduler' ) );
    }

    // Forbered indhold
    $title   = $post->post_title;
    $content = wp_strip_all_tags( $post->post_content );

    if ( strlen( $content ) > 700 ) {
        $content = substr( $content, 0, 700 ) . '...';
    }

    // Prompt
    $prompt_template = get_option( 'fb_post_scheduler_ai_prompt', '' );
    if ( empty( $prompt_template ) ) {
        $prompt_template = __( 'Skriv et kort, engagerende Facebook-opslag på dansk baseret på følgende indhold. Brug 2 korte sætninger. Undgå hashtags og emojis. Hold det enkelt og færdigt.', 'fb-post-scheduler' );
    }

    $prompt = $prompt_template
        . "\n\nTitel: " . $title
        . "\n\nIndhold: " . $content
        . "\n\nKrav: Skriv kun 2 korte sætninger på dansk. Undgå hashtags og emojis. Afslut præcist og færdigt.";

    // Ollama endpoint og model (kan gøres til indstillinger senere)
    $api_url = 'http://localhost:11434/api/chat';
    $model   = 'gemma4:latest';

    $body = array(
        'model'    => $model,
        'messages' => array(
            array(
                'role'    => 'user',
                'content' => $prompt,
            ),
        ),
        'stream'  => false,
        'options' => array(
            'temperature' => 0.7,
            'top_p'       => 0.9,
            'num_ctx'     => 4096,
        ),
    );

    $response = wp_remote_post( $api_url, array(
        'headers' => array( 'Content-Type' => 'application/json' ),
        'timeout' => 60, // Lokal model kan være langsommere end cloud API
        'body'    => wp_json_encode( $body ),
    ) );

    if ( is_wp_error( $response ) ) {
        fb_post_scheduler_log( 'Ollama API Error: ' . $response->get_error_message(), $post_id );
        return $response;
    }

    $response_code = wp_remote_retrieve_response_code( $response );
    if ( 200 !== $response_code ) {
        $error_message = wp_remote_retrieve_response_message( $response );
        fb_post_scheduler_log( 'Ollama API Error: ' . $error_message . ' (Code: ' . $response_code . ')', $post_id );
        return new WP_Error( 'api_error', $error_message . ' (Code: ' . $response_code . ')' );
    }

    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( isset( $body['error'] ) ) {
        $error_message = is_string( $body['error'] ) ? $body['error'] : __( 'Ukendt fejl fra Ollama', 'fb-post-scheduler' );
        fb_post_scheduler_log( 'Ollama API Error: ' . $error_message, $post_id );
        return new WP_Error( 'api_error', $error_message );
    }

    // Ollama svarer i $body['message']['content']
    $generated_text = '';
    if ( ! empty( $body['message']['content'] ) && is_string( $body['message']['content'] ) ) {
        $generated_text = trim( $body['message']['content'] );
    }

    if ( ! empty( $generated_text ) ) {
        fb_post_scheduler_log( 'AI genereret tekst for post ID: ' . $post_id, $post_id );
        return $generated_text;
    }

    return new WP_Error( 'unknown_error', __( 'Kunne ikke generere tekst med Ollama', 'fb-post-scheduler' ) );
}