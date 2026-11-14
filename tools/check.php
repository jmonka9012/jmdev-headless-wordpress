<?php
/** Check the loaded configuration and anonymous public REST responses without writes. */
if (!defined('WP_CLI') || !WP_CLI) { fwrite(STDERR, "Run with WP-CLI eval-file after loading WordPress.\n"); exit(1); }
require_once __DIR__ . '/runtime-contracts.php';
foreach (['acf_get_field_groups', 'acf_get_field', 'portfolio_technology_categories', 'portfolio_languages'] as $function) {
    if (!function_exists($function)) { WP_CLI::error('Required function missing: ' . $function . '. Load ACF and the complete Portfolio MU-plugin.'); }
}
$errors = [];
$groups = ['group_6a7710557030b', 'group_6a71ecf287c04', 'group_6a7f079d0c363', 'group_6a7898cc8e4be', 'group_portfolio_project', 'group_portfolio_technology_category'];
$active_groups = array_column(acf_get_field_groups(), null, 'key');
foreach ($groups as $key) {
    $group = $active_groups[$key] ?? null;
    if (!$group || ($group['local'] ?? null) !== 'php' || empty($group['active']) || empty($group['show_in_rest'])) { $errors[] = 'ACF group ' . $key . ' must be active, registered in PHP and exposed through REST.'; }
}
$fields = [
    'field_6a71ed47f9195' => ['lang', 'text'],
    'field_6a7898ee9f68d' => ['order', 'text'],
    'field_portfolio_project_type' => ['project_type', 'select'],
    'field_portfolio_project_gallery' => ['project_gallery', 'portfolio_rows'],
    'field_portfolio_project_challenges' => ['project_challenges', 'portfolio_rows'],
    'field_portfolio_project_outcome' => ['project_outcome', 'textarea'],
    'field_portfolio_technology_category' => ['technology_category', 'select'],
];
foreach ($fields as $key => [$name, $type]) {
    $field = acf_get_field($key);
    if (!$field || $field['name'] !== $name || $field['type'] !== $type) { $errors[] = 'ACF field ' . $key . ' must retain name ' . $name . ' and type ' . $type . '.'; }
}
if (!class_exists('Portfolio_Project_Rows') || !acf_get_field_type('portfolio_rows')) { $errors[] = 'The portfolio_rows ACF field type is not loaded.'; }
$type = get_post_type_object('technology');
if (!$type || !$type->show_in_rest || ($type->rest_base ?: 'technology') !== 'technology' || ($type->rest_namespace ?: 'wp/v2') !== 'wp/v2') { $errors[] = 'Technology must be available at /wp/v2/technology.'; }
if (has_action('init', 'portfolio_register_technology_post_type') === false || !post_type_supports('technology', 'thumbnail') || !post_type_supports('technology', 'custom-fields')) { $errors[] = 'Technology PHP registration, thumbnail support or custom-fields support is missing.'; }
$languages = portfolio_languages();
if (!is_array($languages) || !$languages) { $errors[] = 'Register at least one portfolio language.'; }
else {
    foreach ($languages as $locale => $label) {
        if (!is_string($locale) || !preg_match('/^[a-z]{2}$/D', $locale) || !is_string($label) || trim($label) === '') { $errors[] = 'Portfolio languages require two-letter codes and nonempty text labels.'; break; }
    }
}
if ($errors) {
    foreach ($errors as $error) { WP_CLI::warning($error); }
    WP_CLI::error('Configuration checks failed. Correct these errors before checking public responses.');
}
WP_CLI::log('Configuration: required PHP fields, Technology REST support and language definitions passed.');
$user = get_current_user_id();
try {
    // Match the frontend exporter, including anonymous permissions and embedded media.
    wp_set_current_user(0);
    $server = rest_get_server();
    $fetch = static function ($route, $query = [], $method = 'GET') use ($server) {
        $request = new WP_REST_Request($method, $route);
        $request->set_query_params($query);
        $response = $server->dispatch($request);
        return ['status' => $response->get_status(), 'headers' => $response->get_headers(), 'data' => $server->response_to_data($response, true)];
    };
    $response = $fetch('/portfolio/v1/languages');
    if ($response['status'] !== 200 || $response['data'] !== $languages) { $errors[] = 'Public /portfolio/v1/languages does not match registered languages.'; }
    $categories = portfolio_technology_categories();
    foreach (['posts' => ['portfolio', 'acf'], 'pages' => ['acf'], 'technology' => ['portfolio_category', 'acf']] as $kind => $required) {
        $schema = $fetch('/wp/v2/' . $kind, [], 'OPTIONS');
        if ($schema['status'] !== 200) { $errors[] = $kind . ': public REST schema is unavailable.'; }
        else {
            foreach ($required as $field) {
                if (!isset($schema['data']['schema']['properties'][$field])) { $errors[] = $kind . ': REST schema is missing ' . $field . ' registration.'; }
            }
        }
        $result = portfolio_check_collection($fetch, $kind, $languages, $categories);
        $errors = array_merge($errors, $result['errors']);
        WP_CLI::log($kind . ': ' . $result['count'] . ' public records checked' . ($result['count'] === 0 && !$result['errors'] ? ' (no records available for response-shape checks)' : '') . '.');
    }
} catch (Throwable $error) {
    WP_CLI::warning('REST checks interrupted (' . get_class($error) . '). Inspect the local WordPress error log.');
    $errors[] = 'Public responses could not be checked completely.';
} finally { wp_set_current_user($user); }
if ($errors) {
    foreach ($errors as $error) { WP_CLI::warning($error); }
    WP_CLI::error('Runtime checks failed: ' . count($errors) . ' error(s).');
}
WP_CLI::success('Configuration and public REST contracts passed.');
