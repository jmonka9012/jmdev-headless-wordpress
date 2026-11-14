<?php
define('ABSPATH', __DIR__);
$GLOBALS['actions'] = [];
$GLOBALS['groups'] = [];
function add_action($name, $callback, $priority = 10) { $GLOBALS['actions'][$name] = [$callback, $priority]; }
function acf_add_local_field_group($group) { $GLOBALS['groups'][$group['key']] = $group; }
function check($valid, $message) { if (!$valid) { throw new RuntimeException($message); } }
require dirname(__DIR__) . '/acf-fields.php';
[$register, $priority] = $GLOBALS['actions']['acf/init'];
check($priority < 30, 'Base fields must load before the project schema resolves relationships.');
$register();
$fields = [];
$walk = function ($items) use (&$walk, &$fields) {
    foreach ($items as $field) {
        check(!isset($fields[$field['key']]), 'Field keys must be unique across all groups.');
        $fields[$field['key']] = $field;
        $walk($field['sub_fields'] ?? []);
    }
};
foreach ($GLOBALS['groups'] as $group) { $walk($group['fields']); }
check(count($fields) === 42, 'All existing fields, including nested content groups, are registered without database definitions.');
foreach (['field_6a71ed14f9194' => 'template', 'field_6a71ed47f9195' => 'lang', 'field_6a7898ee9f68d' => 'order'] as $key => $name) {
    check(($fields[$key]['name'] ?? '') === $name, 'Existing metadata references must keep their field keys.');
}
check($fields['field_6a7f089193aeb']['return_format'] === 'url', 'Legacy image values retain the URL format expected by the exporter.');
check($fields['field_6a7f07c293aea']['return_format'] === 'url', 'Legacy project links retain their URL format.');
$count = count($GLOBALS['groups']);
$register();
check(count($GLOBALS['groups']) === $count, 'Registration is idempotent by group key.');
echo "PHP ACF registration and legacy field-key contracts passed.\n";
