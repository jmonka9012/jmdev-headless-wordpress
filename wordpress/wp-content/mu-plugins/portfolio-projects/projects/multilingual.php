<?php
if (!defined('ABSPATH')) { exit; }

function portfolio_languages() {
    return get_option('portfolio_languages', ['pl' => 'Polish', 'en' => 'English']);
}
function portfolio_project_local_defaults() {
    return ['images' => [], 'challenges' => [], 'seo' => [], 'outcome' => ''];
}
function portfolio_project_root($id) {
    $root = (int) get_post_meta($id, '_portfolio_root_id', true);
    return $root && get_post_meta($root, '_portfolio_project_key', true) === get_post_meta($id, '_portfolio_project_key', true) ? $root : 0;
}
function portfolio_project_translations($root, $public = false) {
    return get_posts(['post_type' => 'post', 'post_status' => $public ? 'publish' : ['publish', 'draft', 'pending', 'private', 'future'], 'numberposts' => -1, 'meta_key' => '_portfolio_root_id', 'meta_value' => $root]);
}
function portfolio_project_path($post) {
    $lang = get_post_meta($post->ID, '_portfolio_locale', true);
    $slug = get_post_meta($post->ID, '_portfolio_public_slug', true) ?: preg_replace('/-2$/', '', $post->post_name);
    return $lang === 'pl' ? '/projekt/' . $slug : '/' . $lang . '/project/' . $slug;
}

/** Pure projection: attachment data is resolved once; translations belong to usage. */
function portfolio_project_present($shared, $local, $resolve) {
    $image_row = static function ($row, $usage) use ($local, $resolve) {
        $image = $resolve($row['image']);
        if (!$image) { return null; }
        $text = $local['images'][$usage] ?? [];
        if (array_key_exists('alt', $text)) { $image['alt'] = $text['alt']; }
        return ['image' => $image, 'caption' => $text['caption'] ?? ''];
    };
    $gallery = [];
    foreach ($shared['gallery'] ?? [] as $row) {
        $entry = $image_row($row, 'hero/' . $row['row_id']);
        if ($entry) { $gallery[] = $entry; }
    }
    $challenges = [];
    foreach ($shared['challenges'] ?? [] as $row) {
        $id = $row['row_id'];
        $text = $local['challenges'][$id] ?? [];
        $shots = [];
        foreach ($row['screenshots'] ?? [] as $shot) {
            $entry = $image_row($shot, $id . '/' . $shot['row_id']);
            if ($entry) { $shots[] = $entry; }
        }
        $item = [
            'id' => $id, 'title' => $text['title'] ?? '', 'challenge' => $text['challenge'] ?? '',
            'solution' => $text['solution'] ?? '', 'effect' => $text['effect'] ?? '',
            'screenshots' => $shots, 'link_url' => $text['link_url'] ?? ($row['link_url'] ?? ''),
            'link_label' => $text['link_label'] ?? '',
        ];
        if ($shots || $item['link_url'] || trim(strip_tags(implode('', array_slice($item, 1, 4))))) { $challenges[] = $item; }
    }
    return ['schema_version' => 2, 'project_type' => portfolio_project_type($shared['project_type'] ?? ''), 'gallery' => $gallery, 'challenges' => $challenges, 'technology_ids' => $shared['technology_ids'] ?? [], 'outcome' => $local['outcome'] ?? '', 'seo' => $local['seo'] ?? [], 'project_url' => $shared['project_url'] ?? ''];
}
function portfolio_multilingual_payload($id) {
    $root = portfolio_project_root($id);
    if (!$root) { return null; }
    $shared = get_post_meta($root, '_portfolio_shared', true);
    $local = get_post_meta($id, '_portfolio_local', true);
    if (!is_array($shared) || !is_array($local)) { return null; }
    $data = portfolio_project_present($shared, $local, 'portfolio_project_image');
    foreach ($data['challenges'] as &$challenge) { $challenge['solution'] = wp_kses_post(wpautop($challenge['solution'])); }
    unset($challenge);
    $data['featured_image'] = portfolio_project_image(get_post_thumbnail_id($root));
    if ($data['featured_image']) {
        foreach ($data['gallery'] as $entry) {
            if (($entry['image']['id'] ?? 0) === $data['featured_image']['id']) { $data['featured_image']['alt'] = $entry['image']['alt']; break; }
        }
    }
    $data['locale'] = get_post_meta($id, '_portfolio_locale', true);
    $data['project_key'] = get_post_meta($id, '_portfolio_project_key', true);
    $data['order'] = $shared['order'] ?? 0;
    $data['public_slug'] = get_post_meta($id, '_portfolio_public_slug', true);
    $data['translations'] = [];
    foreach (portfolio_project_translations($root, true) as $translation) {
        $data['translations'][get_post_meta($translation->ID, '_portfolio_locale', true)] = portfolio_project_path($translation);
    }
    return $data;
}

add_filter('rest_prepare_post', function ($response, $post) {
    $data = portfolio_multilingual_payload($post->ID);
    if (!$data) { return $response; }
    $body = $response->get_data();
    $body['portfolio'] = $data;
    $body['acf'] = array_merge(is_array($body['acf'] ?? null) ? $body['acf'] : [], [
        'lang' => $data['locale'], 'template' => 'project', 'link' => $data['project_url'],
        'order' => $data['order'], 'project_technologies' => $data['technology_ids'],
        'project_type' => $data['project_type'],
        'project_gallery' => $data['gallery'], 'project_challenges' => $data['challenges'], 'project_outcome' => $data['outcome'],
    ]);
    $response->set_data($body);
    return $response;
}, 30, 2);
add_filter('rest_prepare_page', function ($response, $post) {
    $lang = get_post_meta($post->ID, '_portfolio_locale', true);
    if (isset(portfolio_languages()[$lang])) {
        $body = $response->get_data();
        $body['acf']['lang'] = $lang;
        $response->set_data($body);
    }
    return $response;
}, 30, 2);
add_action('rest_api_init', function () {
    register_rest_route('portfolio/v1', '/languages', ['methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => function () { return portfolio_languages(); }]);
});
