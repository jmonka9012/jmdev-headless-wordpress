<?php
if (!defined('ABSPATH')) { exit; }

function portfolio_technology_category_definitions() {
    return [
        'frontend' => 'Front-end',
        'cms' => 'CMS & commerce',
        'motion' => 'Motion & UI',
        'code' => 'Code & data',
        'workflow' => 'Workflow',
    ];
}

function portfolio_category_languages() {
    return array_replace(['pl' => 'Polish', 'en' => 'English'], portfolio_languages());
}

function portfolio_technology_categories() {
    $stored = get_option('portfolio_category_labels', []);
    $label = static function ($value, $fallback) {
        $text = is_string($value) ? sanitize_text_field($value) : '';
        return $text !== '' ? $text : $fallback;
    };
    $categories = [];
    foreach (portfolio_technology_category_definitions() as $key => $default) {
        $values = is_array($stored) && is_array($stored[$key] ?? null) ? $stored[$key] : [];
        $fallback = $label($values['en'] ?? null, $default);
        foreach (portfolio_category_languages() as $locale => $name) {
            $categories[$key][$locale] = $label($values[$locale] ?? null, $fallback);
        }
    }
    return $categories;
}

function portfolio_technology_category($id) {
    $key = get_field('technology_category', $id);
    $categories = portfolio_technology_categories();
    if (!is_string($key) || !isset($categories[$key])) { return null; }
    return ['key' => $key, 'labels' => $categories[$key], 'order' => array_search($key, array_keys($categories), true)];
}

function portfolio_technology_category_choices() {
    return array_map(static fn($labels) => $labels['en'], portfolio_technology_categories());
}

add_action('acf/init', function () {
    acf_add_local_field_group([
        'key' => 'group_portfolio_technology_category', 'title' => 'Portfolio — technology category',
        'fields' => [[
            'key' => 'field_portfolio_technology_category', 'name' => 'technology_category',
            'label' => 'Category', 'type' => 'select', 'required' => 1, 'allow_null' => 1,
            'choices' => portfolio_technology_category_choices(),
            'return_format' => 'value',
        ]],
        'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'technology']]],
        'show_in_rest' => 1,
    ]);
});

add_action('rest_api_init', function () {
    register_rest_field('technology', 'portfolio_category', [
        'get_callback' => static fn($post) => portfolio_technology_category($post['id']),
        'schema' => ['type' => ['object', 'null'], 'context' => ['view', 'edit'], 'readonly' => true],
    ]);
});
