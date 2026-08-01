<?php
/**
 * Open Graph helpers for Facebook link previews
 *
 * Facebook ignorerer custom picture på link-posts. Preview-billedet styres
 * via og:image på den delte URL. Vi sætter et midlertidigt override før scrape
 * og sørger for featured image som fallback når intet SEO-plugin håndterer det.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Transient-nøgle for midlertidigt OG-billede under Facebook-scrape.
 *
 * @param int $post_id WordPress post ID
 * @return string
 */
function fb_post_scheduler_og_transient_key($post_id) {
    return 'fb_ps_og_image_' . absint($post_id);
}

/**
 * Sæt midlertidigt billede som Facebook skal scrapе til link-preview.
 *
 * @param int $post_id WordPress post ID
 * @param int $image_id Attachment ID
 * @return void
 */
function fb_post_scheduler_set_og_image_override($post_id, $image_id) {
    $post_id = absint($post_id);
    $image_id = absint($image_id);

    if (!$post_id || !$image_id || !wp_attachment_is_image($image_id)) {
        return;
    }

    set_transient(fb_post_scheduler_og_transient_key($post_id), $image_id, 10 * MINUTE_IN_SECONDS);
}

/**
 * Fjern midlertidigt OG-billede override.
 *
 * @param int $post_id WordPress post ID
 * @return void
 */
function fb_post_scheduler_clear_og_image_override($post_id) {
    delete_transient(fb_post_scheduler_og_transient_key(absint($post_id)));
}

/**
 * Find attachment-ID der skal bruges som OG/link-preview billede.
 *
 * @param int $post_id WordPress post ID
 * @return int
 */
function fb_post_scheduler_get_og_image_id($post_id) {
    $post_id = absint($post_id);
    if (!$post_id) {
        return 0;
    }

    $override = get_transient(fb_post_scheduler_og_transient_key($post_id));
    if ($override && wp_attachment_is_image($override)) {
        return (int) $override;
    }

    $thumbnail_id = get_post_thumbnail_id($post_id);
    if ($thumbnail_id && wp_attachment_is_image($thumbnail_id)) {
        return (int) $thumbnail_id;
    }

    return 0;
}

/**
 * Hent billede-data til Open Graph.
 *
 * @param int $post_id WordPress post ID
 * @return array{url:string,width:int,height:int,type:string,alt:string}|null
 */
function fb_post_scheduler_get_og_image_data($post_id) {
    $image_id = fb_post_scheduler_get_og_image_id($post_id);
    if (!$image_id) {
        return null;
    }

    $src = wp_get_attachment_image_src($image_id, 'full');
    if (!$src || empty($src[0])) {
        return null;
    }

    $mime = get_post_mime_type($image_id);
    $alt = get_post_meta($image_id, '_wp_attachment_image_alt', true);

    return array(
        'url' => $src[0],
        'width' => isset($src[1]) ? (int) $src[1] : 0,
        'height' => isset($src[2]) ? (int) $src[2] : 0,
        'type' => $mime ? $mime : '',
        'alt' => is_string($alt) ? $alt : '',
    );
}

/**
 * Er et kendt SEO-plugin aktivt der allerede udskriver og:image?
 *
 * @return bool
 */
function fb_post_scheduler_has_seo_og_plugin() {
    return defined('WPSEO_VERSION')
        || defined('RANK_MATH_VERSION')
        || class_exists('RankMath')
        || defined('THE_SEO_FRAMEWORK_VERSION')
        || defined('SEOPRESS_VERSION')
        || defined('JETPACK__VERSION');
}

/**
 * Er der aktivt override for aktuelt indlæg?
 *
 * @param int $post_id WordPress post ID
 * @return bool
 */
function fb_post_scheduler_has_og_image_override($post_id) {
    $override = get_transient(fb_post_scheduler_og_transient_key($post_id));
    return !empty($override) && wp_attachment_is_image($override);
}

/**
 * Filtrer OG-billede-URL fra SEO-plugins.
 *
 * @param string $url Nuværende billede-URL
 * @return string
 */
function fb_post_scheduler_filter_seo_og_image($url) {
    if (!is_singular()) {
        return $url;
    }

    $post_id = get_queried_object_id();
    if (!$post_id) {
        return $url;
    }

    // Under scrape/override: tving det valgte/featured billede
    if (!fb_post_scheduler_has_og_image_override($post_id)) {
        return $url;
    }

    $data = fb_post_scheduler_get_og_image_data($post_id);
    return ($data && !empty($data['url'])) ? $data['url'] : $url;
}

/**
 * Filtrer Jetpack Open Graph tags under aktivt override.
 *
 * @param array $tags Open Graph tags
 * @return array
 */
function fb_post_scheduler_filter_jetpack_og_tags($tags) {
    if (!is_array($tags) || !is_singular()) {
        return $tags;
    }

    $post_id = get_queried_object_id();
    if (!$post_id || !fb_post_scheduler_has_og_image_override($post_id)) {
        return $tags;
    }

    $data = fb_post_scheduler_get_og_image_data($post_id);
    if (!$data) {
        return $tags;
    }

    $tags['og:image'] = $data['url'];
    if (!empty($data['width'])) {
        $tags['og:image:width'] = $data['width'];
    }
    if (!empty($data['height'])) {
        $tags['og:image:height'] = $data['height'];
    }
    if (!empty($data['type'])) {
        $tags['og:image:type'] = $data['type'];
    }

    return $tags;
}

/**
 * Udskriv og:image når intet SEO-plugin gør det, eller under aktivt override.
 *
 * @return void
 */
function fb_post_scheduler_output_og_image_tags() {
    if (!is_singular()) {
        return;
    }

    $post_id = get_queried_object_id();
    if (!$post_id) {
        return;
    }

    $has_seo = fb_post_scheduler_has_seo_og_plugin();

    // SEO-plugins udskriver selv og:image. Ved override retter filtrene deres URL.
    if ($has_seo) {
        return;
    }

    // Uden SEO-plugin: sørg for featured/override-billede som og:image, så Facebook
    // ikke falder tilbage til et tilfældigt indholdsbillede
    $data = fb_post_scheduler_get_og_image_data($post_id);
    if (!$data) {
        return;
    }

    echo "\n<!-- Facebook Post Scheduler Open Graph image -->\n";
    echo '<meta property="og:image" content="' . esc_url($data['url']) . '" />' . "\n";

    if (!empty($data['width'])) {
        echo '<meta property="og:image:width" content="' . esc_attr((string) $data['width']) . '" />' . "\n";
    }
    if (!empty($data['height'])) {
        echo '<meta property="og:image:height" content="' . esc_attr((string) $data['height']) . '" />' . "\n";
    }
    if (!empty($data['type'])) {
        echo '<meta property="og:image:type" content="' . esc_attr($data['type']) . '" />' . "\n";
    }
    if (!empty($data['alt'])) {
        echo '<meta property="og:image:alt" content="' . esc_attr($data['alt']) . '" />' . "\n";
    }
}

/**
 * Registrer frontend hooks til Open Graph.
 *
 * @return void
 */
function fb_post_scheduler_register_open_graph_hooks() {
    add_action('wp_head', 'fb_post_scheduler_output_og_image_tags', 1);

    // Yoast SEO
    add_filter('wpseo_opengraph_image', 'fb_post_scheduler_filter_seo_og_image', 20);
    add_filter('wpseo_opengraph_image_url', 'fb_post_scheduler_filter_seo_og_image', 20);

    // Rank Math
    add_filter('rank_math/opengraph/facebook/image', 'fb_post_scheduler_filter_seo_og_image', 20);

    // The SEO Framework
    add_filter('the_seo_framework_ogimage_output', 'fb_post_scheduler_filter_seo_og_image', 20);

    // SEOPress
    add_filter('seopress_social_og_thumb', 'fb_post_scheduler_filter_seo_og_image', 20);

    // Jetpack
    add_filter('jetpack_open_graph_tags', 'fb_post_scheduler_filter_jetpack_og_tags', 20);
}

fb_post_scheduler_register_open_graph_hooks();
