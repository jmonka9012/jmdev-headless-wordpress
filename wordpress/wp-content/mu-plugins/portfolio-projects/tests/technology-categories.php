<?php
define('ABSPATH', __DIR__);
$GLOBALS['actions'] = [];
function add_action($hook, $callback) { $GLOBALS['actions'][$hook] = $callback; }
function get_field($name, $id) { return $GLOBALS['values'][$id] ?? null; }
function acf_add_local_field_group($group) { $GLOBALS['group'] = $group; }
function register_rest_field($type, $name, $settings) { $GLOBALS['rest'] = $settings; }
function check($valid, $message) { if (!$valid) { throw new RuntimeException($message); } }
require dirname(__DIR__) . '/technology-categories.php';
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
echo "Technology category fields and REST contracts passed.\n";
