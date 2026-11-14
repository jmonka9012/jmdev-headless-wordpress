<?php
/**
 * Plugin Name: Portfolio Projects
 * Description: Versioned ACF project fields and repeatable rows compatible with ACF Free.
 */
if (!defined('ABSPATH')) { exit; }
require_once __DIR__ . '/portfolio-projects/acf-fields.php';
require_once __DIR__ . '/portfolio-projects/post-types.php';
require_once __DIR__ . '/portfolio-projects/schema.php';
require_once __DIR__ . '/portfolio-projects/legacy-media.php';
require_once __DIR__ . '/portfolio-projects/multilingual.php';
require_once __DIR__ . '/portfolio-projects/language-admin.php';
require_once __DIR__ . '/portfolio-projects/technology-categories.php';
require_once __DIR__ . '/portfolio-projects/technology-category-admin.php';
require_once __DIR__ . '/portfolio-projects/ordering.php';
add_action('acf/include_field_types', function () {
    require_once __DIR__ . '/portfolio-projects/rows-field.php';
    acf_register_field_type('Portfolio_Project_Rows');
});
add_action('acf/init', 'portfolio_register_project_fields', 30);
add_filter('acf/prepare_field', 'portfolio_hide_legacy_photo_fields');
add_action('rest_api_init', function () {
    register_rest_field('post', 'portfolio', [
        'get_callback' => function ($post) { return portfolio_project_payload($post['id']); },
        'schema' => ['description' => 'Read-only project presentation, schema version 1.', 'type' => 'object', 'context' => ['view', 'edit']],
    ]);
});
