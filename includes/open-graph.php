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
 * Transient-nøgle for cached OG-tags til admin-preview.
 *
 * @param int $post_id WordPress post ID
 * @return string
 */
function fb_post_scheduler_og_tags_transient_key($post_id) {
    return 'fb_ps_og_tags_' . absint($post_id);
}

/**
 * Ryd cached OG-tags for et indlæg.
 *
 * @param int $post_id WordPress post ID
 * @return void
 */
function fb_post_scheduler_clear_og_tags_cache($post_id) {
    delete_transient(fb_post_scheduler_og_tags_transient_key(absint($post_id)));
}

/**
 * Ryd OG-tag-cache når indlægget gemmes.
 *
 * @param int $post_id WordPress post ID
 * @return void
 */
function fb_post_scheduler_clear_og_tags_cache_on_save($post_id) {
    $post_id = absint($post_id);
    if (!$post_id) {
        return;
    }

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    if (wp_is_post_revision($post_id)) {
        return;
    }

    fb_post_scheduler_clear_og_tags_cache($post_id);
}

/**
 * Tomt OG-tag-sæt.
 *
 * @return array{image:string,title:string,description:string,site_name:string}
 */
function fb_post_scheduler_get_empty_og_tags() {
    return array(
        'image' => '',
        'title' => '',
        'description' => '',
        'site_name' => '',
    );
}

/**
 * Standard site-navn til link-kortet.
 *
 * @return string
 */
function fb_post_scheduler_get_default_site_name() {
    $host = wp_parse_url(home_url(), PHP_URL_HOST);
    if (is_string($host) && $host !== '') {
        return $host;
    }

    return get_bloginfo('name');
}

/**
 * Kort excerpt til preview-fallback.
 *
 * @param int $post_id WordPress post ID
 * @return string
 */
function fb_post_scheduler_get_preview_excerpt($post_id) {
    $post = get_post($post_id);
    if (!$post) {
        return '';
    }

    $excerpt = is_string($post->post_excerpt) ? trim($post->post_excerpt) : '';
    if ($excerpt !== '') {
        return wp_strip_all_tags($excerpt);
    }

    $content = wp_strip_all_tags((string) $post->post_content);
    if ($content === '') {
        return '';
    }

    return wp_trim_words($content, 40, '…');
}

/**
 * Læs kendte SEO-plugin OG-felter uden HTTP.
 *
 * @param int $post_id WordPress post ID
 * @return array{image:string,title:string,description:string,site_name:string}
 */
function fb_post_scheduler_get_seo_og_fallback($post_id) {
    $post_id = absint($post_id);
    $tags = fb_post_scheduler_get_empty_og_tags();

    if (!$post_id) {
        return $tags;
    }

    $meta_map = array(
        'image' => array(
            '_yoast_wpseo_opengraph-image',
            'rank_math_facebook_image',
            '_social_image_url',
            '_seopress_social_fb_img',
        ),
        'title' => array(
            '_yoast_wpseo_opengraph-title',
            'rank_math_facebook_title',
            '_open_graph_title',
            '_seopress_social_fb_title',
        ),
        'description' => array(
            '_yoast_wpseo_opengraph-description',
            'rank_math_facebook_description',
            '_open_graph_description',
            '_seopress_social_fb_desc',
        ),
    );

    foreach ($meta_map as $field => $keys) {
        foreach ($keys as $key) {
            $value = get_post_meta($post_id, $key, true);
            if (is_string($value) && $value !== '') {
                $tags[$field] = $value;
                break;
            }
        }
    }

    return $tags;
}

/**
 * Parse første og:image / title / description / site_name fra HTML.
 *
 * @param string $html Rå HTML
 * @return array{image:string,title:string,description:string,site_name:string}
 */
function fb_post_scheduler_parse_og_tags_from_html($html) {
    $tags = fb_post_scheduler_get_empty_og_tags();
    if (!is_string($html) || $html === '') {
        return $tags;
    }

    $map = array(
        'og:image' => 'image',
        'og:title' => 'title',
        'og:description' => 'description',
        'og:site_name' => 'site_name',
    );

    if (!preg_match_all('/<meta\s[^>]*>/i', $html, $matches)) {
        return $tags;
    }

    foreach ($matches[0] as $meta) {
        $property = '';
        $content = '';

        if (preg_match('/(?:property|name)\s*=\s*["\']([^"\']+)["\']/i', $meta, $property_match)) {
            $property = strtolower($property_match[1]);
        }

        if (preg_match('/content\s*=\s*["\']([^"\']*)["\']/i', $meta, $content_match)) {
            $content = html_entity_decode($content_match[1], ENT_QUOTES, 'UTF-8');
        }

        if ($property === '' || $content === '' || !isset($map[$property])) {
            continue;
        }

        $field = $map[$property];
        if ($tags[$field] === '') {
            $tags[$field] = $content;
        }
    }

    return $tags;
}

/**
 * Er indlægget offentligt tilgængeligt så Facebook/HTTP kan læse det?
 *
 * @param int $post_id WordPress post ID
 * @return bool
 */
function fb_post_scheduler_is_post_publicly_scrapeable($post_id) {
    $post = get_post($post_id);
    if (!$post) {
        return false;
    }

    if ($post->post_status !== 'publish') {
        return false;
    }

    if (function_exists('is_post_publicly_viewable')) {
        return is_post_publicly_viewable($post);
    }

    return true;
}

/**
 * Hent OG-tags for et indlæg (HTTP for publicerede, ellers SEO/WP-fallback).
 *
 * @param int $post_id WordPress post ID
 * @return array{image:string,title:string,description:string,site_name:string}
 */
function fb_post_scheduler_fetch_post_og_tags($post_id) {
    $post_id = absint($post_id);
    $fallback = fb_post_scheduler_get_empty_og_tags();

    if (!$post_id) {
        return $fallback;
    }

    $cached = get_transient(fb_post_scheduler_og_tags_transient_key($post_id));
    if (is_array($cached) && isset($cached['image'], $cached['title'], $cached['description'], $cached['site_name'])) {
        return $cached;
    }

    $seo = fb_post_scheduler_get_seo_og_fallback($post_id);
    $fallback['image'] = $seo['image'];
    $fallback['title'] = $seo['title'] !== '' ? $seo['title'] : get_the_title($post_id);
    $fallback['description'] = $seo['description'] !== '' ? $seo['description'] : fb_post_scheduler_get_preview_excerpt($post_id);
    $fallback['site_name'] = $seo['site_name'] !== '' ? $seo['site_name'] : fb_post_scheduler_get_default_site_name();

    $tags = $fallback;

    if (fb_post_scheduler_is_post_publicly_scrapeable($post_id)) {
        $permalink = get_permalink($post_id);
        if ($permalink) {
            $request_args = array(
                'timeout' => 8,
                'redirection' => 3,
            );

            // Same-host frontend often uses a local/self-signed cert.
            $permalink_host = wp_parse_url($permalink, PHP_URL_HOST);
            $home_host = wp_parse_url(home_url(), PHP_URL_HOST);
            if ($permalink_host && $home_host && strtolower($permalink_host) === strtolower($home_host)) {
                $request_args['sslverify'] = apply_filters('https_local_ssl_verify', false);
            }

            $response = wp_remote_get($permalink, $request_args);

            if (!is_wp_error($response) && (int) wp_remote_retrieve_response_code($response) < 400) {
                $parsed = fb_post_scheduler_parse_og_tags_from_html(wp_remote_retrieve_body($response));
                foreach ($parsed as $field => $value) {
                    if ($value !== '') {
                        $tags[$field] = $value;
                    }
                }
            }
        }
    }

    set_transient(fb_post_scheduler_og_tags_transient_key($post_id), $tags, 10 * MINUTE_IN_SECONDS);

    return $tags;
}

/**
 * Dansk kilde-label til preview-kortet.
 *
 * @param string $source selected|og|featured|facebook|empty
 * @return string
 */
function fb_post_scheduler_get_preview_source_label($source) {
    switch ($source) {
        case 'selected':
            return __('Valgt preview-billede', 'fb-post-scheduler');
        case 'og':
            return __('og:image fra siden', 'fb-post-scheduler');
        case 'featured':
            return __('Udvalgt billede (fallback)', 'fb-post-scheduler');
        case 'facebook':
            return __('Facebooks cache', 'fb-post-scheduler');
        default:
            return __('Intet preview-billede', 'fb-post-scheduler');
    }
}

/**
 * Resolve billede, titel og beskrivelse til admin-preview.
 *
 * @param int $post_id  WordPress post ID
 * @param int $image_id Valgt attachment ID (0 hvis intet valgt)
 * @return array{
 *   image_url:string,
 *   image_alt:string,
 *   title:string,
 *   description:string,
 *   site_name:string,
 *   permalink:string,
 *   source:string,
 *   og_image_url:string,
 *   featured_image_url:string,
 *   featured_image_alt:string
 * }
 */
function fb_post_scheduler_get_resolved_preview_data($post_id, $image_id = 0) {
    $post_id = absint($post_id);
    $image_id = absint($image_id);
    $og = fb_post_scheduler_fetch_post_og_tags($post_id);

    $featured_image_url = '';
    $featured_image_alt = '';
    $thumbnail_id = $post_id ? (int) get_post_thumbnail_id($post_id) : 0;
    if ($thumbnail_id && wp_attachment_is_image($thumbnail_id)) {
        $featured_src = wp_get_attachment_image_url($thumbnail_id, 'large');
        $featured_image_url = $featured_src ? $featured_src : '';
        $featured_alt = get_post_meta($thumbnail_id, '_wp_attachment_image_alt', true);
        $featured_image_alt = is_string($featured_alt) ? $featured_alt : '';
    }

    $title = $og['title'] !== '' ? $og['title'] : ($post_id ? get_the_title($post_id) : '');
    $description = $og['description'];
    $site_name = $og['site_name'] !== '' ? $og['site_name'] : fb_post_scheduler_get_default_site_name();
    $permalink = $post_id ? (string) get_permalink($post_id) : '';

    $image_url = '';
    $image_alt = '';
    $source = 'empty';

    if ($image_id && wp_attachment_is_image($image_id)) {
        $selected_src = wp_get_attachment_image_url($image_id, 'large');
        if ($selected_src) {
            $image_url = $selected_src;
            $selected_alt = get_post_meta($image_id, '_wp_attachment_image_alt', true);
            $image_alt = is_string($selected_alt) ? $selected_alt : '';
            $source = 'selected';
        }
    }

    if ($source === 'empty' && $og['image'] !== '') {
        $image_url = $og['image'];
        $source = 'og';
    }

    if ($source === 'empty' && $featured_image_url !== '') {
        $image_url = $featured_image_url;
        $image_alt = $featured_image_alt;
        $source = 'featured';
    }

    return array(
        'image_url' => $image_url,
        'image_alt' => $image_alt,
        'title' => $title,
        'description' => $description,
        'site_name' => $site_name,
        'permalink' => $permalink,
        'source' => $source,
        'og_image_url' => $og['image'],
        'featured_image_url' => $featured_image_url,
        'featured_image_alt' => $featured_image_alt,
    );
}

/**
 * Map Graph scrape/GET-svar til preview-felter.
 *
 * @param array $body Decoded Graph JSON
 * @return array{image_url:string,title:string,description:string,site_name:string,source:string}
 */
function fb_post_scheduler_map_graph_og_response($body) {
    $mapped = array(
        'image_url' => '',
        'title' => '',
        'description' => '',
        'site_name' => '',
        'source' => 'facebook',
    );

    if (!is_array($body)) {
        return $mapped;
    }

    $image_candidates = array(
        isset($body['og_object']['image'][0]['url']) ? $body['og_object']['image'][0]['url'] : '',
        isset($body['og_object']['image']['url']) ? $body['og_object']['image']['url'] : '',
        isset($body['og_object']['image']['data'][0]['url']) ? $body['og_object']['image']['data'][0]['url'] : '',
        isset($body['image'][0]['url']) ? $body['image'][0]['url'] : '',
        isset($body['image']['url']) ? $body['image']['url'] : '',
    );
    foreach ($image_candidates as $candidate) {
        if (is_string($candidate) && $candidate !== '') {
            $mapped['image_url'] = $candidate;
            break;
        }
    }

    if (isset($body['og_object']['title'])) {
        $mapped['title'] = (string) $body['og_object']['title'];
    } elseif (isset($body['title'])) {
        $mapped['title'] = (string) $body['title'];
    }

    if (isset($body['og_object']['description'])) {
        $mapped['description'] = (string) $body['og_object']['description'];
    } elseif (isset($body['description'])) {
        $mapped['description'] = (string) $body['description'];
    }

    if (isset($body['og_object']['site_name'])) {
        $mapped['site_name'] = (string) $body['og_object']['site_name'];
    } elseif (isset($body['site_name'])) {
        $mapped['site_name'] = (string) $body['site_name'];
    }

    return $mapped;
}

/**
 * Formatér planlagt tid til preview-header.
 *
 * @param string $date Y-m-d
 * @param string $time H:i
 * @return string
 */
function fb_post_scheduler_format_preview_schedule($date, $time) {
    $date = is_string($date) ? trim($date) : '';
    $time = is_string($time) ? trim($time) : '';

    if ($date === '' || $time === '') {
        return '';
    }

    $timestamp = strtotime($date . ' ' . $time);
    if (!$timestamp) {
        return $date . ' · ' . $time;
    }

    return date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
}

/**
 * Render den fælles Facebook-link-preview i metaboxen.
 *
 * @param int   $post_id WordPress post ID
 * @param array $fb_post Opslagsdata (text, image_id, date, target_type)
 * @return void
 */
function fb_post_scheduler_render_link_preview($post_id, $fb_post = array()) {
    $post_id = absint($post_id);
    $image_id = isset($fb_post['image_id']) ? absint($fb_post['image_id']) : 0;
    $data = fb_post_scheduler_get_resolved_preview_data($post_id, $image_id);

    $page_name = get_option('fb_post_scheduler_facebook_page_name', '');
    $group_name = get_option('fb_post_scheduler_facebook_group_name', '');
    $target = isset($fb_post['target_type']) ? $fb_post['target_type'] : 'page';
    $actor_name = ($target === 'group' && $group_name) ? $group_name : $page_name;

    $date_parts = !empty($fb_post['date']) ? explode(' ', $fb_post['date']) : array();
    $date = !empty($date_parts[0]) ? $date_parts[0] : '';
    $time = !empty($date_parts[1]) ? substr($date_parts[1], 0, 5) : '';
    $schedule_label = fb_post_scheduler_format_preview_schedule($date, $time);

    $text = isset($fb_post['text']) ? $fb_post['text'] : '';
    $source_label = fb_post_scheduler_get_preview_source_label($data['source']);
    $placeholder = __('Intet preview-billede', 'fb-post-scheduler');
    ?>
    <div class="fb-post-preview"
        data-post-id="<?php echo esc_attr((string) $post_id); ?>"
        data-source="<?php echo esc_attr($data['source']); ?>"
        data-featured-image-url="<?php echo esc_attr($data['featured_image_url']); ?>"
        data-featured-image-alt="<?php echo esc_attr($data['featured_image_alt']); ?>"
        data-og-image-url="<?php echo esc_attr($data['og_image_url']); ?>"
        data-og-title="<?php echo esc_attr($data['title']); ?>"
        data-og-description="<?php echo esc_attr($data['description']); ?>"
        data-og-site-name="<?php echo esc_attr($data['site_name']); ?>"
        data-permalink="<?php echo esc_attr($data['permalink']); ?>"
        data-page-name="<?php echo esc_attr($page_name); ?>"
        data-group-name="<?php echo esc_attr($group_name); ?>">
        <h4 class="fb-post-preview-toggle" role="button" tabindex="0" aria-expanded="false">
            <?php esc_html_e('Forhåndsvisning af opslag', 'fb-post-scheduler'); ?>
            <span class="dashicons dashicons-arrow-down-alt2 fb-post-preview-toggle-icon" aria-hidden="true"></span>
        </h4>
        <div class="fb-post-preview-content">
            <div class="fb-post-preview-card">
                <div class="fb-post-preview-actor">
                    <div class="fb-post-preview-actor-name"><?php echo esc_html($actor_name); ?></div>
                    <div class="fb-post-preview-actor-meta"><?php echo esc_html($schedule_label); ?></div>
                </div>
                <p class="fb-post-preview-text"><?php echo $text !== '' ? wp_kses_post($text) : ''; ?></p>
                <div class="fb-post-preview-link-container">
                    <div class="fb-post-preview-image">
                        <?php if ($data['image_url'] !== '') : ?>
                            <img class="fb-post-preview-image-element" src="<?php echo esc_url($data['image_url']); ?>" alt="<?php echo esc_attr($data['image_alt']); ?>">
                        <?php else : ?>
                            <div class="fb-post-preview-image-placeholder"><?php echo esc_html($placeholder); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="fb-post-preview-link-content">
                        <div class="fb-post-preview-website-name"><?php echo esc_html($data['site_name']); ?></div>
                        <div class="fb-post-preview-title"><?php echo esc_html($data['title']); ?></div>
                        <div class="fb-post-preview-description"><?php echo esc_html($data['description']); ?></div>
                    </div>
                </div>
            </div>
            <p class="fb-post-preview-source"><?php echo esc_html($source_label); ?></p>
            <p class="fb-post-preview-error" hidden></p>
            <div class="fb-post-preview-actions">
                <button type="button" class="button fb-check-facebook-preview">
                    <?php esc_html_e('Tjek hos Facebook', 'fb-post-scheduler'); ?>
                </button>
                <button type="button" class="button fb-refresh-facebook-cache">
                    <?php esc_html_e('Opdater Facebooks cache', 'fb-post-scheduler'); ?>
                </button>
                <button type="button" class="button-link fb-reset-facebook-preview" hidden>
                    <?php esc_html_e('Brug sidens forhåndsvisning igen', 'fb-post-scheduler'); ?>
                </button>
                <span class="spinner fb-preview-spinner"></span>
            </div>
        </div>
    </div>
    <?php
}

/**
 * Registrer frontend hooks til Open Graph.
 *
 * @return void
 */
function fb_post_scheduler_register_open_graph_hooks() {
    add_action('wp_head', 'fb_post_scheduler_output_og_image_tags', 1);
    add_action('save_post', 'fb_post_scheduler_clear_og_tags_cache_on_save');

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
