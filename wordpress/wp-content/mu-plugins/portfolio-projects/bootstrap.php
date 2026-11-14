<?php
if (!defined('ABSPATH')) { exit; }
require_once __DIR__ . '/schema/acf-fields.php';
require_once __DIR__ . '/schema/post-types.php';
require_once __DIR__ . '/projects/schema.php';
require_once __DIR__ . '/projects/legacy-media.php';
require_once __DIR__ . '/projects/multilingual.php';
require_once __DIR__ . '/admin/shared/ui.php';
require_once __DIR__ . '/admin/shared/fields.php';
require_once __DIR__ . '/admin/projects.php';
require_once __DIR__ . '/admin/project-create.php';
require_once __DIR__ . '/admin/pages.php';
require_once __DIR__ . '/technologies/categories.php';
require_once __DIR__ . '/admin/category-labels.php';
require_once __DIR__ . '/admin/technologies.php';
require_once __DIR__ . '/admin/ordering.php';
add_action('acf/include_field_types', function () {
    require_once __DIR__ . '/fields/rows.php';
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
