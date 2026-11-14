<?php
define('ABSPATH', __DIR__);
$GLOBALS['actions'] = [];
function add_action($hook, $callback) { $GLOBALS['actions'][$hook] = $callback; }
function get_field($name, $id) { return $GLOBALS['values'][$id] ?? null; }
function acf_add_local_field_group($group) { $GLOBALS['group'] = $group; }
function register_rest_field($type, $name, $settings) { $GLOBALS['rest'] = $settings; }
function get_option($name, $default = false) { return $GLOBALS['options'][$name] ?? $default; }
function portfolio_languages() { return $GLOBALS['languages'] ?? ['pl' => 'Polish', 'en' => 'English']; }
function sanitize_text_field($value) { return trim(preg_replace('/\s+/', ' ', strip_tags($value))); }
function add_settings_error(...$args) { $GLOBALS['errors'][] = $args; }
function check($valid, $message) { if (!$valid) { throw new RuntimeException($message); } }
require dirname(__DIR__) . '/technology-categories.php';
require dirname(__DIR__) . '/technology-category-admin.php';
$GLOBALS['actions']['acf/init']();
$field = $GLOBALS['group']['fields'][0];
check(count($field['choices']) === 5 && $field['required'] === 1, 'Category requires an explicit selection.');
check(!isset($field['default_value']), 'No automatic category assignment.');
$GLOBALS['actions']['rest_api_init']();
$callback = $GLOBALS['rest']['get_callback'];
foreach (portfolio_technology_categories() as $key => $labels) {
    $GLOBALS['values'][71] = $key;
    $category = $callback(['id' => 71]);
    check($category['key'] === $key && $category['labels'] === $labels, 'REST preserves the saved category and labels.');
    check(is_int($category['order']), 'REST supplies deterministic category order.');
}
foreach ([null, '', 'invalid', ['frontend']] as $value) {
    $GLOBALS['values'][71] = $value;
    check($callback(['id' => 71]) === null, 'Missing or invalid category is unassigned.');
}

$defaults = portfolio_technology_category_definitions();
foreach (portfolio_technology_categories() as $key => $labels) {
    check($labels['pl'] === $defaults[$key] && $labels['en'] === $defaults[$key], 'An empty installation uses English defaults without an import.');
}
$GLOBALS['options']['portfolio_category_labels'] = ['cms' => ['pl' => 'Localized category', 'en' => 'Custom category'], 'unknown' => ['pl' => 'Ignored']];
$categories = portfolio_technology_categories();
check($categories['cms'] === ['pl' => 'Localized category', 'en' => 'Custom category'], 'Configured labels override defaults independently for each locale.');
check(array_keys($categories) === array_keys($defaults), 'Stored data cannot add or reorder categories.');
$GLOBALS['options']['portfolio_category_labels']['cms']['pl'] = ' ';
check(portfolio_technology_categories()['cms']['pl'] === 'Custom category', 'An empty translation uses the configured English label.');
$GLOBALS['options']['portfolio_category_labels']['cms']['en'] = ['invalid'];
check(portfolio_technology_categories()['cms']['pl'] === $defaults['cms'], 'Invalid English labels fall back to PHP defaults.');
$GLOBALS['options']['portfolio_category_labels'] = 'invalid';
check(portfolio_technology_categories()['cms']['en'] === $defaults['cms'], 'Malformed stored options remain readable through defaults.');
$GLOBALS['languages']['fr'] = 'French';
check(portfolio_technology_categories()['cms']['fr'] === $defaults['cms'], 'New registered languages receive a fallback label.');
unset($GLOBALS['languages']);

$input = portfolio_technology_categories();
$input['cms']['pl'] = '  <b>Localized</b> category  ';
$input['cms']['en'] = '';
$input['unknown'] = ['pl' => 'Ignored'];
$input['cms']['unknown'] = 'Ignored';
$clean = portfolio_sanitize_category_labels($input);
check($clean['cms'] === ['pl' => 'Localized category', 'en' => ''], 'Settings sanitize text and allow intentional empty fallback values.');
check(!isset($clean['unknown']), 'Settings cannot introduce category identifiers.');
check(!isset($clean['cms']['unknown']), 'Settings accept only registered language identifiers.');
$GLOBALS['options']['portfolio_category_labels'] = $clean;
$invalid = $input; unset($invalid['cms']['pl']);
$nested = $input; $nested['cms']['pl'] = ['invalid'];
foreach ([null, 'invalid', $invalid, $nested] as $value) {
    check(portfolio_sanitize_category_labels($value) === $clean, 'Malformed or partial submissions preserve previous settings.');
}
check(count($GLOBALS['errors']) === 4, 'Invalid submissions report settings errors.');
$GLOBALS['values'][71] = 'cms';
check($callback(['id' => 71])['labels']['pl'] === 'Localized category', 'REST reads the same sanitized settings as the editor.');
$GLOBALS['actions']['acf/init']();
check($GLOBALS['group']['fields'][0]['choices'] === $defaults, 'Admin choices remain stable when public labels change.');
echo "Technology category fields and REST contracts passed.\n";
