<?php
if (!defined('ABSPATH')) { exit; }

/** Hide obsolete editing inputs only; keep definitions and values for import/API fallbacks. */
function portfolio_hide_legacy_photo_fields($field) {
    if (!$field || !in_array($field['_name'] ?? $field['name'] ?? '', ['photo_1', 'photo_2', 'photo_3'], true)) { return $field; }
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if ($screen && $screen->base === 'post' && $screen->post_type === 'post') { return false; }
    return $field;
}

/** Reuse existing media library attachments, never duplicate physical files. */
function portfolio_legacy_image_id($value) {
    if (is_array($value)) { $value = $value['ID'] ?? $value['id'] ?? $value['url'] ?? 0; }
    $id = is_numeric($value) ? absint($value) : (is_string($value) ? attachment_url_to_postid($value) : 0);
    return $id && wp_attachment_is_image($id) ? $id : 0;
}

/** Idempotent import; existing gallery (even explicitly empty) and featured image win. */
function portfolio_import_legacy_project_media($post_id) {
    if (get_post_meta($post_id, '_portfolio_legacy_media_imported_v1', true)) { return 'unchanged'; }
    $ids = [];
    foreach (['photo_1', 'photo_2', 'photo_3'] as $key) {
        $value = get_post_meta($post_id, $key, true);
        if (!$value) { continue; }
        $id = portfolio_legacy_image_id($value);
        // Do not import a partial gallery or mark unresolved photos as migrated.
        if (!$id) { return 'unresolved'; }
        $ids[] = $id;
    }
    if (!$ids) {
        update_post_meta($post_id, '_portfolio_legacy_media_imported_v1', 1);
        return 'unchanged';
    }
    if (!metadata_exists('post', $post_id, 'project_gallery')) {
        $rows = array_map(function ($id) { return ['image' => $id, 'caption' => '']; }, $ids);
        update_field('field_portfolio_project_gallery', $rows, $post_id);
        if (!metadata_exists('post', $post_id, 'project_gallery')) { return 'failed'; }
    }
    if (!get_post_thumbnail_id($post_id)) {
        // Keep the existing archive thumbnail; it is independent of Hero ordering.
        $first = $ids[0];
        if ($first) {
            set_post_thumbnail($post_id, $first);
            if (!get_post_thumbnail_id($post_id)) { return 'failed'; }
        }
    }
    update_post_meta($post_id, '_portfolio_legacy_media_imported_v1', 1);
    return 'imported';
}

function portfolio_legacy_media_candidates($limit = 50) {
    return get_posts([
        'post_type' => 'post', 'post_status' => 'any', 'posts_per_page' => $limit,
        'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC',
        'meta_query' => [
            ['key' => '_portfolio_legacy_media_imported_v1', 'compare' => 'NOT EXISTS'],
            ['relation' => 'OR', ['key' => 'photo_1', 'compare' => 'EXISTS'], ['key' => 'photo_2', 'compare' => 'EXISTS'], ['key' => 'photo_3', 'compare' => 'EXISTS']],
        ],
    ]);
}

// Only authorized admin requests write data. Public REST/build requests stay read-only.
add_action('admin_init', function () {
    if (!function_exists('update_field') || !acf_get_field('field_portfolio_project_gallery') || !current_user_can('edit_posts')) { return; }
    foreach (portfolio_legacy_media_candidates() as $post_id) {
        if (current_user_can('edit_post', $post_id)) { portfolio_import_legacy_project_media($post_id); }
    }
});

if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('portfolio import-legacy-media', function () {
        if (!function_exists('update_field')) { WP_CLI::error('ACF must be active.'); }
        $counts = ['imported' => 0, 'unchanged' => 0, 'unresolved' => 0, 'failed' => 0];
        foreach (portfolio_legacy_media_candidates(-1) as $post_id) {
            $result = portfolio_import_legacy_project_media($post_id);
            $counts[$result]++;
            if (in_array($result, ['unresolved', 'failed'], true)) { WP_CLI::warning("Project {$post_id}: {$result}; legacy fields preserved."); }
        }
        WP_CLI::log(wp_json_encode($counts));
        if ($counts['unresolved'] || $counts['failed']) { WP_CLI::error('Some projects need attention. Safe to retry.'); }
        WP_CLI::success('Legacy media import finished.');
    });
}
