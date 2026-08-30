<?php
/**
 * AI Helper for Facebook Post Scheduler
 *
 * Genererer Facebook-opslagstekst via lokal Ollama eller Google Gemini API.
 */

// Hvis denne fil kaldes direkte, så afbryd
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Returnerer den valgte AI-provider.
 *
 * @return string 'ollama' eller 'gemini'
 */
function fb_post_scheduler_get_ai_provider() {
    $provider = get_option( 'fb_post_scheduler_ai_provider', 'ollama' );
    return fb_post_scheduler_sanitize_ai_provider( $provider );
}

/**
 * Saniterer AI-provider-indstillingen.
 *
 * @param mixed $value Rå værdi.
 * @return string
 */
function fb_post_scheduler_sanitize_ai_provider( $value ) {
    $value = is_string( $value ) ? sanitize_key( $value ) : '';
    return in_array( $value, array( 'ollama', 'gemini' ), true ) ? $value : 'ollama';
}

/**
 * Knaptekst til den valgte AI-provider.
 *
 * @return string
 */
function fb_post_scheduler_get_ai_button_label() {
    if ( 'gemini' === fb_post_scheduler_get_ai_provider() ) {
        return __( 'Generer tekst med Gemini', 'fb-post-scheduler' );
    }

    return __( 'Generer tekst med Ollama', 'fb-post-scheduler' );
}

/**
 * Udskriv AI-generer-knappen i metaboxen.
 *
 * @param string|int $index   Opslagsindeks eller skabelon-placeholder.
 * @param int        $post_id Post ID.
 */
function fb_post_scheduler_render_ai_generate_button( $index, $post_id ) {
    $icon  = ( 'gemini' === fb_post_scheduler_get_ai_provider() ) ? 'dashicons-google' : 'dashicons-desktop';
    $label = fb_post_scheduler_get_ai_button_label();
    ?>
    <button type="button" class="button fb-generate-ai-text" data-index="<?php echo esc_attr( (string) $index ); ?>" data-post-id="<?php echo esc_attr( (string) (int) $post_id ); ?>">
        <span class="dashicons <?php echo esc_attr( $icon ); ?>" style="vertical-align: text-top;"></span>
        <?php echo esc_html( $label ); ?>
    </button>
    <span class="spinner fb-ai-spinner" style="float: none; margin-top: 0;"></span>
    <?php
}

/**
 * Byg prompt ud fra indlæg og den gemte skabelon.
 *
 * @param WP_Post $post Indlægget.
 * @return string
 */
function fb_post_scheduler_build_ai_prompt( $post ) {
    $title   = $post->post_title;
    $content = wp_strip_all_tags( $post->post_content );

    if ( strlen( $content ) > 700 ) {
        $content = substr( $content, 0, 700 ) . '...';
    }

    $prompt_template = get_option( 'fb_post_scheduler_ai_prompt', '' );
    if ( empty( $prompt_template ) ) {
        $prompt_template = __( 'Skriv et kort, engagerende Facebook-opslag på dansk baseret på følgende indhold. Brug 2 korte sætninger. Undgå hashtags og emojis. Hold det enkelt og færdigt.', 'fb-post-scheduler' );
    }

    return $prompt_template
        . "\n\nTitel: " . $title
        . "\n\nIndhold: " . $content
        . "\n\nKrav: Skriv kun 2 korte sætninger på dansk. Undgå hashtags og emojis. Afslut præcist og færdigt.";
}

/**
 * Generer tekst til Facebook-opslag med den valgte AI-provider.
 *
 * @param int $post_id Post ID.
 * @return string|WP_Error Genereret tekst eller fejl.
 */
function fb_post_scheduler_generate_ai_text( $post_id ) {
    $post = get_post( $post_id );
    if ( ! $post ) {
        return new WP_Error( 'invalid_post', __( 'Ugyldig post ID', 'fb-post-scheduler' ) );
    }

    $prompt = fb_post_scheduler_build_ai_prompt( $post );

    if ( 'gemini' === fb_post_scheduler_get_ai_provider() ) {
        return fb_post_scheduler_generate_ai_text_gemini( $prompt, $post_id );
    }

    return fb_post_scheduler_generate_ai_text_ollama( $prompt, $post_id );
}

/**
 * Generer tekst via lokal Ollama (Gemma).
 *
 * @param string $prompt  Færdig prompt.
 * @param int    $post_id Post ID til log.
 * @return string|WP_Error
 */
function fb_post_scheduler_generate_ai_text_ollama( $prompt, $post_id ) {
    $api_url = apply_filters( 'fb_post_scheduler_ollama_url', 'http://localhost:11434/api/chat' );
    $model   = apply_filters( 'fb_post_scheduler_ollama_model', 'gemma4:latest' );

    $body = array(
        'model'    => $model,
        'messages' => array(
            array(
                'role'    => 'user',
                'content' => $prompt,
            ),
        ),
        'stream'   => false,
        'options'  => array(
            'temperature' => 0.7,
            'top_p'       => 0.9,
            'num_ctx'     => 4096,
        ),
    );

    $response = wp_remote_post(
        $api_url,
        array(
            'headers' => array( 'Content-Type' => 'application/json' ),
            'timeout' => 60,
            'body'    => wp_json_encode( $body ),
        )
    );

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

    $generated_text = '';
    if ( ! empty( $body['message']['content'] ) && is_string( $body['message']['content'] ) ) {
        $generated_text = trim( $body['message']['content'] );
    }

    if ( ! empty( $generated_text ) ) {
        fb_post_scheduler_log( 'AI genereret tekst med Ollama for post ID: ' . $post_id, $post_id );
        return $generated_text;
    }

    return new WP_Error( 'unknown_error', __( 'Kunne ikke generere tekst med Ollama', 'fb-post-scheduler' ) );
}

/**
 * Generer tekst via Google Gemini API.
 *
 * @param string $prompt  Færdig prompt.
 * @param int    $post_id Post ID til log.
 * @return string|WP_Error
 */
function fb_post_scheduler_generate_ai_text_gemini( $prompt, $post_id ) {
    $api_key = get_option( 'fb_post_scheduler_gemini_api_key', '' );
    if ( empty( $api_key ) ) {
        return new WP_Error(
            'missing_api_key',
            __( 'Google Gemini API-nøgle mangler. Tilføj den under FB Opslag → Indstillinger.', 'fb-post-scheduler' )
        );
    }

    $model   = apply_filters( 'fb_post_scheduler_gemini_model', 'gemini-3.6-flash' );
    $api_url = 'https://generativelanguage.googleapis.com/v1beta/interactions';

    $body = array(
        'model'             => $model,
        'input'             => $prompt,
        'store'             => false,
        'generation_config' => array(
            'thinking_level' => 'minimal',
        ),
    );

    $response = wp_remote_post(
        $api_url,
        array(
            'headers' => array(
                'Content-Type'   => 'application/json',
                'x-goog-api-key' => $api_key,
            ),
            'timeout' => 30,
            'body'    => wp_json_encode( $body ),
        )
    );

    if ( is_wp_error( $response ) ) {
        fb_post_scheduler_log( 'Gemini API Error: ' . $response->get_error_message(), $post_id );
        return $response;
    }

    $response_code = wp_remote_retrieve_response_code( $response );
    $decoded       = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( 200 !== $response_code ) {
        $error_message = '';
        if ( is_array( $decoded ) && ! empty( $decoded['error']['message'] ) && is_string( $decoded['error']['message'] ) ) {
            $error_message = $decoded['error']['message'];
        } else {
            $error_message = wp_remote_retrieve_response_message( $response );
        }
        fb_post_scheduler_log( 'Gemini API Error: ' . $error_message . ' (Code: ' . $response_code . ')', $post_id );
        return new WP_Error( 'api_error', $error_message . ' (Code: ' . $response_code . ')' );
    }

    if ( ! empty( $decoded['status'] ) && 'completed' !== $decoded['status'] ) {
        $status = sanitize_text_field( $decoded['status'] );
        fb_post_scheduler_log( 'Gemini API Error: Interaction status ' . $status, $post_id );
        if ( 'failed' === $status ) {
            return new WP_Error( 'api_error', __( 'Gemini kunne ikke fuldføre forespørgslen. Prøv igen.', 'fb-post-scheduler' ) );
        }
    }

    $generated_text = fb_post_scheduler_extract_gemini_text( $decoded );

    if ( ! empty( $generated_text ) ) {
        fb_post_scheduler_log( 'AI genereret tekst med Gemini for post ID: ' . $post_id, $post_id );
        return $generated_text;
    }

    return new WP_Error( 'unknown_error', __( 'Kunne ikke generere tekst med Gemini', 'fb-post-scheduler' ) );
}

/**
 * Træk tekst ud af et Gemini Interactions-svar.
 *
 * @param array|null $body Dekodet JSON.
 * @return string
 */
function fb_post_scheduler_extract_gemini_text( $body ) {
    if ( empty( $body['steps'] ) || ! is_array( $body['steps'] ) ) {
        return '';
    }

    $parts = array();
    foreach ( $body['steps'] as $step ) {
        if ( empty( $step['type'] ) || 'model_output' !== $step['type'] ) {
            continue;
        }
        if ( empty( $step['content'] ) || ! is_array( $step['content'] ) ) {
            continue;
        }
        foreach ( $step['content'] as $item ) {
            if ( empty( $item['text'] ) || ! is_string( $item['text'] ) ) {
                continue;
            }
            if ( isset( $item['type'] ) && 'text' !== $item['type'] ) {
                continue;
            }
            $parts[] = $item['text'];
        }
    }

    return trim( implode( "\n", $parts ) );
}
