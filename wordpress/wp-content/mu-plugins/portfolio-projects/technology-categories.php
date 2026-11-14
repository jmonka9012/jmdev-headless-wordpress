<?php
if (!defined('ABSPATH')) { exit; }

function portfolio_technology_categories() {
    return [
        'frontend' => ['pl' => 'Front-end', 'en' => 'Front-end'],
        'cms' => ['pl' => 'CMS i sklepy', 'en' => 'CMS & commerce'],
        'motion' => ['pl' => 'Ruch i UI', 'en' => 'Motion & UI'],
        'code' => ['pl' => 'Kod i dane', 'en' => 'Code & data'],
        'workflow' => ['pl' => 'Warsztat', 'en' => 'Workflow'],
    ];
}

function portfolio_technology_category($id) {
    $key = get_field('technology_category', $id);
    $categories = portfolio_technology_categories();
    if (!is_string($key) || !isset($categories[$key])) { return null; }
    return ['key' => $key, 'labels' => $categories[$key], 'order' => array_search($key, array_keys($categories), true)];
}

add_action('acf/init', function () {
    acf_add_local_field_group([
        'key' => 'group_portfolio_technology_category', 'title' => 'Portfolio — technology category',
        'fields' => [[
            'key' => 'field_portfolio_technology_category', 'name' => 'technology_category',
            'label' => 'Category', 'type' => 'select', 'required' => 1, 'allow_null' => 1,
            'choices' => array_map(static fn($labels) => $labels['en'], portfolio_technology_categories()),
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
