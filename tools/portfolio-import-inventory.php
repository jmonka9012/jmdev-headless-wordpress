<?php
/** Read current state; shared by planner and actual repeatability verification. */
function portfolio_import_inventory() {
$inventory = ['posts' => [], 'technologies' => [], 'attachments' => []];
$fields = [];
foreach (get_posts(['post_type' => 'post', 'post_status' => 'any', 'numberposts' => -1]) as $post) {
    $meta = get_post_meta($post->ID);
    unset($meta['_edit_lock'], $meta['_edit_last']);
    ksort($meta);
    $inventory['posts'][] = [
        'id' => $post->ID, 'slug' => $post->post_name, 'status' => $post->post_status,
        'legacy_locale' => get_post_meta($post->ID, 'lang', true),
        'project_key' => get_post_meta($post->ID, '_portfolio_project_key', true),
        'locale' => get_post_meta($post->ID, '_portfolio_locale', true),
        'fingerprint' => hash('sha256', serialize([$post->post_title, $post->post_excerpt, $post->post_content, $post->post_name, $post->post_status, $meta])),
    ];
    $relation = portfolio_technology_field($post->ID);
    $fields[$post->ID] = $relation ? ['key' => $relation['key'], 'path' => $relation['_portfolio_path']] : null;
}
foreach (get_posts(['post_type' => 'technology', 'post_status' => 'any', 'numberposts' => -1]) as $post) {
    $inventory['technologies'][] = ['id' => $post->ID, 'slug' => $post->post_name, 'status' => $post->post_status, 'title' => $post->post_title];
}
foreach (get_posts(['post_type' => 'attachment', 'post_status' => 'inherit', 'numberposts' => -1]) as $post) {
    $file = get_attached_file($post->ID);
    $original = wp_get_original_image_path($post->ID);
    $source_ids = get_post_meta($post->ID, '_portfolio_source_ids', true);
    $inventory['attachments'][] = [
        'id' => $post->ID, 'mime' => $post->post_mime_type,
        'sha256' => $file && is_file($file) && is_readable($file) ? hash_file('sha256', $file) : null,
        'original_sha256' => $original && is_file($original) && is_readable($original) ? hash_file('sha256', $original) : null,
        'source_ids' => is_array($source_ids) ? $source_ids : [],
    ];
}
return [$inventory, $fields];
}
