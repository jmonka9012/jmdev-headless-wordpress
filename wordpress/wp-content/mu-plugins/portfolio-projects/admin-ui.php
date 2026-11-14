<?php
if (!defined('ABSPATH')) { exit; }

function portfolio_admin_header($title, $actions = []) {
    echo '<header class="portfolio-admin-header"><h1>' . esc_html($title) . '</h1><div>';
    foreach ($actions as $label => $url) { printf('<a class="button" href="%s">%s</a> ', esc_url($url), esc_html($label)); }
    echo '</div></header>';
}

function portfolio_admin_language_tabs($languages, $urls = [], $active = '') {
    echo '<div class="portfolio-language-tabs" role="group" aria-label="Editing language">';
    foreach ($languages as $locale => $label) {
        if (isset($urls[$locale])) { printf('<a class="button" href="%s" %s>%s</a> ', esc_url($urls[$locale]), $active === $locale ? 'aria-current="page"' : '', esc_html($label)); }
        else { printf('<button type="button" class="button" data-portfolio-language="%s" aria-pressed="false">%s</button> ', esc_attr($locale), esc_html($label)); }
    }
    echo '</div>';
}

function portfolio_admin_version_link($locale, $status, $url) {
    printf('<a class="button" href="%s">%s · %s</a> ', esc_url($url), esc_html(strtoupper($locale)), esc_html(get_post_status_object($status)->label));
}

function portfolio_admin_enqueue_editor($post_id = 0) {
    $post = $post_id ? get_post($post_id) : null;
    acf_enqueue_scripts(); acf_localize_data(['post_id' => $post ? $post->ID : 0]);
    wp_enqueue_media($post ? ['post' => $post->ID] : []); wp_enqueue_editor();
    wp_enqueue_script('portfolio-language-editor', plugins_url('language-admin.js', __FILE__), ['acf-input'], (string) filemtime(__DIR__ . '/language-admin.js'), true);
    wp_enqueue_style('portfolio-language-editor', plugins_url('language-admin.css', __FILE__), [], (string) filemtime(__DIR__ . '/language-admin.css'));
}

function portfolio_admin_creation_fields($action) {
    wp_nonce_field($action);
    printf('<input type="hidden" name="action" value="%s"><input type="hidden" name="portfolio[key]" value="%s">', esc_attr($action), esc_attr(wp_generate_uuid4()));
    echo '<p><label>Title <input class="regular-text" name="portfolio[title]" required></label></p><p><label>Initial language <select name="portfolio[locale]" required>';
    foreach (portfolio_languages() as $locale => $label) { printf('<option value="%s">%s</option>', esc_attr($locale), esc_html($label)); }
    echo '</select></label></p>';
}

function portfolio_admin_project_type($value = '') {
    echo '<p><label>Project type <select name="portfolio[project_type]" required><option value="">Choose a type</option>';
    foreach (portfolio_project_types() as $key => $label) { printf('<option value="%s" %s>%s</option>', esc_attr($key), selected($value, $key, false), esc_html($label)); }
    echo '</select></label></p>';
}

function portfolio_admin_image($name, $label, $value) {
    $field = acf_validate_field(['key' => 'field_portfolio_admin_' . $name, 'name' => 'portfolio[' . $name . ']', 'label' => $label, 'type' => 'image', 'return_format' => 'id', 'preview_size' => 'medium', 'value' => $value]);
    $field['_prepare'] = true; $field['_valid'] = 1;
    acf_render_field_wrap($field);
}

function portfolio_admin_image_id($value) {
    if ($value === '' || $value === null) { return 0; }
    if ((!is_int($value) && !is_string($value)) || !ctype_digit((string) $value)) { throw new RuntimeException('Select a valid image from the media library.'); }
    $id = (int) $value;
    if ($id && !wp_attachment_is_image($id)) { throw new RuntimeException('Select a valid image from the media library.'); }
    return $id;
}
