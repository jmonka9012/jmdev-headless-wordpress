<?php
if (!defined('ABSPATH')) { exit; }

function portfolio_admin_header($title, $actions = []) {
    echo '<header class="portfolio-admin-header"><h1>' . esc_html($title) . '</h1><div>';
    foreach ($actions as $label => $url) { printf('<a class="button" href="%s">%s</a> ', esc_url($url), esc_html($label)); }
    echo '</div></header>';
}

function portfolio_admin_language_tabs($languages) {
    echo '<div class="portfolio-language-tabs" role="group" aria-label="Editing language">';
    foreach ($languages as $locale => $label) { printf('<button type="button" class="button" data-portfolio-language="%s" aria-pressed="false">%s</button> ', esc_attr($locale), esc_html($label)); }
    echo '</div>';
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
