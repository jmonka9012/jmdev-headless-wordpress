<?php
if (!defined('ABSPATH')) { exit; }

function portfolio_sanitize_category_labels($value) {
    $result = [];
    foreach (portfolio_technology_category_definitions() as $key => $name) {
        foreach (portfolio_category_languages() as $locale => $language) {
            if (!is_array($value) || !is_array($value[$key] ?? null) || !is_string($value[$key][$locale] ?? null)) {
                add_settings_error('portfolio_category_labels', 'portfolio_invalid_category_labels', 'Submit a text label for every category and language.');
                $previous = get_option('portfolio_category_labels', []);
                return is_array($previous) ? $previous : [];
            }
            $result[$key][$locale] = sanitize_text_field($value[$key][$locale]);
        }
    }
    return $result;
}

add_action('admin_init', function () {
    register_setting('portfolio_category_labels', 'portfolio_category_labels', [
        'type' => 'array', 'default' => [], 'sanitize_callback' => 'portfolio_sanitize_category_labels',
    ]);
});

add_action('admin_menu', function () {
    add_submenu_page('portfolio-projects', 'Technology category labels', 'Category labels', 'manage_options', 'portfolio-category-labels', 'portfolio_category_labels_page');
});

function portfolio_category_labels_page() {
    if (!current_user_can('manage_options')) { wp_die('You do not have permission to edit category labels.', '', ['response' => 403]); }
    $stored = get_option('portfolio_category_labels', []);
    echo '<div class="wrap"><h1>Technology category labels</h1>';
    settings_errors();
    echo '<p>These labels appear on the frontend. Admin category choices use the saved English labels. Category keys and order are defined in PHP.</p>';
    echo '<p>Leave a label empty to use the English label. If the English label is empty, the default from PHP is used. Refresh the exported data and rebuild the frontend after saving.</p>';
    echo '<form method="post" action="' . esc_url(admin_url('options.php')) . '">';
    settings_fields('portfolio_category_labels');
    echo '<table class="widefat striped"><thead><tr><th scope="col">Category</th>';
    foreach (portfolio_category_languages() as $locale => $language) {
        printf('<th scope="col">%s (%s)</th>', esc_html($language), esc_html($locale));
    }
    echo '</tr></thead><tbody>';
    foreach (portfolio_technology_category_choices() as $key => $default) {
        printf('<tr><th scope="row">%s<br><code>%s</code></th>', esc_html($default), esc_html($key));
        $values = is_array($stored) && is_array($stored[$key] ?? null) ? $stored[$key] : [];
        foreach (portfolio_category_languages() as $locale => $language) {
            $value = is_string($values[$locale] ?? null) ? $values[$locale] : '';
            printf('<td><label><span class="screen-reader-text">%s — %s</span><input class="regular-text" type="text" name="portfolio_category_labels[%s][%s]" value="%s" placeholder="%s"></label></td>', esc_html($default), esc_html($language), esc_attr($key), esc_attr($locale), esc_attr($value), esc_attr($default));
        }
        echo '</tr>';
    }
    echo '</tbody></table>';
    submit_button('Save category labels');
    echo '</form></div>';
}
