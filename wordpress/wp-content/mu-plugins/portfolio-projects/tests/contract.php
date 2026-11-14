<?php
/** Isolated contract tests. No database writes; WP sanitizers are not reimplemented/tested here. */
define('ABSPATH', __DIR__);
class acf_field { public function __construct() {} }
class WP_Error { public function __construct(...$args) {} }
function absint($v) { return abs((int) $v); }
function wp_attachment_is_image($id) { return in_array((int) $id, [10, 11], true); }
function sanitize_text_field($s) { return trim(strip_tags($s)); }
function sanitize_textarea_field($s) { return trim(strip_tags($s)); }
function wp_kses_post($s) { return $s; }
function wpautop($s) { return $s; }
function wp_strip_all_tags($s) { return strip_tags($s); }
function esc_url_raw($s) { return $s; }
function wp_get_attachment_image_src($id, $size) { return ['https://example.test/' . $id . '.png', 1200, 800]; }
function get_post_meta($id, $key, $single) { return 'Image alt'; }
function get_post_thumbnail_id($id) { return 0; }
function get_post_type($id) { return $id === 77 || $id === 78 ? 'technology' : 'post'; }
function get_post_status($id) { return $id === 78 ? 'draft' : 'publish'; }
function acf_get_field_groups($filter = []) { return $GLOBALS['groups'] ?? []; }
function acf_get_fields($parent) { return $parent['fields'] ?? []; }
function acf_add_local_field_group($group) { $GLOBALS['registered'] = $group; }
function get_field($key, $id, $format = true) { return $GLOBALS['values'][$key] ?? null; }
function check($valid, $message) { if (!$valid) { throw new RuntimeException($message); } }
require dirname(__DIR__) . '/projects/schema.php';
require dirname(__DIR__) . '/fields/rows.php';

$field = new Portfolio_Project_Rows();
check(portfolio_project_rows_are_images(['_name' => 'portfolio[gallery]', '_portfolio_row_kind' => 'image']), 'Managed galleries retain image controls after ACF normalizes field names.');
check(!portfolio_project_rows_are_images(['_name' => 'portfolio[challenges]', '_portfolio_row_kind' => 'challenge']), 'Challenge collections retain their own controls.');
$gallery = ['sub_fields' => portfolio_project_row_fields('project_gallery')];
$challenges = ['sub_fields' => portfolio_project_row_fields('project_challenges')];
check($field->update_value('', 1, $gallery) === [], 'Last row can be removed.');
check($field->update_value([['title' => '  ']], 1, $challenges) === [], 'Empty rows are removed.');
$rows = $field->update_value(['row_3' => ['image' => 11, 'caption' => 'Second'], 'row_1' => ['image' => 10, 'caption' => 'First']], 1, $gallery);
check(array_column($rows, 'image') === [11, 10], 'Submitted visual order is preserved.');
check($field->validate_value(true, [['image' => 99]], $gallery, '') !== true, 'Invalid media rejected.');
check($field->validate_value(true, [['image' => 10]], $gallery, '') === true, 'Valid image accepted.');
check($field->validate_rest_value(true, [['image' => 99]], $gallery) instanceof WP_Error, 'REST validation rejects invalid media.');

$GLOBALS['groups'] = [['fields' => [['key' => 'existing_tech', 'name' => 'stack', 'type' => 'relationship', 'post_type' => ['technology']]]]];
portfolio_register_project_fields();
check(count(array_filter($GLOBALS['registered']['fields'], fn($f) => !in_array($f['type'], ['tab', 'message'], true))) === 4, 'Existing relationship is not duplicated.');
$type_field = array_values(array_filter($GLOBALS['registered']['fields'], fn($f) => ($f['name'] ?? '') === 'project_type'))[0];
check(array_keys($type_field['choices']) === ['commercial', 'personal'] && $type_field['required'] === 1, 'Project type is an explicit choice.');
check(!isset($type_field['default_value']), 'New projects have no inferred type.');
$GLOBALS['values'] = ['existing_tech' => [77, 77, 78, 99], 'photo_1' => 10, 'photo_3' => 11];
$payload = portfolio_project_payload(1);
check($payload['technology_ids'] === [77], 'Relation reused, deduplicated, unpublished targets omitted.');
check(count($payload['gallery']) === 2, 'Legacy gallery fallback works.');
check($payload['outcome'] === '', 'Outcome stays optional.');
check($payload['project_type'] === '', 'Missing type stays empty.');
foreach (['commercial', 'personal', 'invalid', ['personal']] as $type) {
    $GLOBALS['values']['project_type'] = $type;
    check(portfolio_project_payload(1)['project_type'] === (is_string($type) && in_array($type, ['commercial', 'personal'], true) ? $type : ''), 'Legacy payload validates type.');
}
$GLOBALS['values']['project_gallery'] = [['image' => 11, 'caption' => 'New']];
check(portfolio_project_payload(1)['gallery'][0]['image']['id'] === 11, 'New gallery wins.');
$GLOBALS['groups'] = [];
portfolio_register_project_fields();
check(count(array_filter($GLOBALS['registered']['fields'], fn($f) => !in_array($f['type'], ['tab', 'message'], true))) === 5, 'Missing relation gets exactly one field.');
echo "Project PHP contracts passed.\n";

check($field->validate_value(true, [['link_url' => 'javascript:alert(1)']], $challenges, '') !== true, 'Unsafe link rejected.');
check($field->validate_value(true, [['link_url' => 'https://example.com/demo']], $challenges, '') === true, 'Web link accepted.');
$GLOBALS['values']['project_challenges'] = [['screenshot' => 10, 'link_url' => 'https://example.com/demo', 'link_label' => 'Demo'], ['link_url' => 'https://example.com/report'], ['link_label' => 'No URL']];
$materials = portfolio_project_payload(1)['challenges'];
check(count($materials) === 2, 'Link-only rows survive; label-only rows omitted.');
check($materials[0]['screenshots'][0]['image']['id'] === 10 && $materials[0]['link_url'] === 'https://example.com/demo', 'Screenshot and link coexist.');
echo "Challenge material contracts passed.\n";

$input = [['screenshots' => [['image' => 11, 'caption' => 'Second'], ['image' => 10, 'caption' => 'First']]]];
$saved = $field->update_value($input, 1, $challenges);
check(array_column($saved[0]['screenshots'], 'image') === [11, 10], 'Nested screenshot order survives saving.');
check($field->validate_value(true, $input, $challenges, '') === true, 'Nested images validate.');
check($field->validate_value(true, [['screenshots' => [['image' => 99]]]], $challenges, '') !== true, 'Invalid nested image rejected.');
check($field->get_rest_schema($challenges)['items']['properties']['screenshots']['items']['properties']['image']['type'] === 'integer', 'REST describes nested image rows.');
$legacy = $field->update_value([['screenshot' => 10]], 1, $challenges);
check($legacy[0]['screenshots'][0]['image'] === 10, 'Saving preserves legacy screenshot.');
check($field->update_value([['screenshot' => 10, 'screenshots' => '']], 1, $challenges) === [], 'Removing final image does not restore legacy image.');
$GLOBALS['values']['project_challenges'] = $saved;
check(count(portfolio_project_payload(1)['challenges'][0]['screenshots']) === 2, 'Payload includes all screenshots.');
echo "Nested screenshot contracts passed.\n";
