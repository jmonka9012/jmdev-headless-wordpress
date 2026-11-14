<?php
/** Print a read-only configuration summary without exporting content or credentials. */
if (!defined('WP_CLI') || !WP_CLI) { exit("Run with WP-CLI eval-file.\n"); }
if (!function_exists('acf_get_field_groups') || !function_exists('portfolio_order_items')) { WP_CLI::error('ACF and Portfolio Projects must be loaded.'); }
$groups = [];
foreach (acf_get_field_groups() as $group) {
    $groups[] = ['key' => $group['key'], 'title' => $group['title'], 'source' => $group['local'] ?? 'database'];
}
$technologies = [];
foreach (portfolio_technology_categories() + ['uncategorized' => []] as $key => $labels) {
    $technologies[$key] = count(portfolio_order_items('technologies', $key));
}
$report = [
    'wordpress' => get_bloginfo('version'),
    'acf' => defined('ACF_VERSION') ? ACF_VERSION : null,
    'technology_registered' => post_type_exists('technology'),
    'technology_registration_in_php' => has_action('init', 'portfolio_register_technology_post_type') !== false,
    'field_groups' => $groups,
    'languages' => array_keys(portfolio_languages()),
    'projects' => count(portfolio_order_items('projects')),
    'technologies_by_category' => $technologies,
];
WP_CLI::log(wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
