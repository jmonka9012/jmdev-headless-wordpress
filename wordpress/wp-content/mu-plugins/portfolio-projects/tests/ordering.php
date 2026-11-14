<?php
define('ABSPATH', __DIR__);
function add_action($name, $callback, ...$args) { $GLOBALS["hooks"][$name] = $callback; }
function add_filter($name, $callback, ...$args) { $GLOBALS["filters"][$name] = $callback; }
function portfolio_technology_categories() { return ['frontend' => ['pl' => 'Front-end'], 'cms' => ['pl' => 'CMS']]; }
function check($valid, $message) { if (!$valid) { throw new RuntimeException($message); } }
function rejects($callback) { try { $callback(); } catch (RuntimeException $e) { return; } throw new RuntimeException('Invalid input was accepted.'); }
function absint($id) { return abs((int) $id); }
function get_current_screen() { return (object) ['post_type' => $GLOBALS['screen_type'] ?? 'post']; }
function metadata_exists($type, $id, $key) { return array_key_exists($key, $GLOBALS['meta'][$id] ?? []); }
function get_posts($query) { return $GLOBALS['posts'] ?? []; }
function get_post_meta($id, $key, $single = true) { return $GLOBALS['meta'][$id][$key] ?? ''; }
function portfolio_store_meta($id, $key, $value) { $GLOBALS['meta'][$id][$key] = $value; }
function acf_get_field_groups($query) { return [[]]; }
function acf_get_fields($group) { return [['name' => 'order', 'key' => 'field_actual_order']]; }
function update_field($key, $value, $id) { check($key === 'field_actual_order', 'Existing ACF field key is reused.'); $GLOBALS['meta'][$id]['order'] = (string) $value; }
require dirname(__DIR__) . '/admin/ordering.php';
$items = [['id' => 1, 'version' => 'one'], ['id' => 2, 'version' => 'two']];
$token = portfolio_order_token($items);
check(portfolio_order_validate($items, ['2', '1'], $token) === [2, 1], 'Complete permutation accepted.');
foreach ([[1], [1, 1], [1, 3], [-1, 2], ['1x', 2], [[1], 2], '1,2'] as $ids) {
    rejects(fn() => portfolio_order_validate($items, $ids, $token));
}
$changed = $items; $changed[0]['version'] = 'new';
rejects(fn() => portfolio_order_validate($changed, [1, 2], $token));
check(portfolio_order_validate([], [], portfolio_order_token([])) === [], 'Empty list accepted.');
check(portfolio_order_scope(['kind' => 'projects']) === ['projects', ''], 'Projects use shared scope.');
check(portfolio_order_scope(['kind' => 'technologies', 'category' => 'uncategorized']) === ['technologies', 'uncategorized'], 'Unassigned technologies remain manageable.');
foreach ([['kind' => 'other'], ['kind' => 'technologies', 'category' => 'other'], ['kind' => 'technologies', 'category' => []]] as $scope) {
    rejects(fn() => portfolio_order_scope($scope));
}
$GLOBALS['meta'] = [
    1 => ['_portfolio_shared' => ['order' => 50, 'project_type' => 'personal', 'gallery' => [11], 'technology_ids' => [71]]],
    2 => ['_portfolio_shared' => ['order' => 60, 'gallery' => [12]]],
    3 => ['order' => '30', 'technology_category' => 'cms'],
    4 => ['order' => '40', 'technology_category' => 'cms'],
];
$before = $GLOBALS['meta'];
portfolio_order_write('projects', [2, 1]);
check($GLOBALS['meta'][2]['_portfolio_shared']['order'] === 10 && $GLOBALS['meta'][1]['_portfolio_shared']['order'] === 20, 'Projects get sequence ranks.');
$after = $GLOBALS['meta'][1]['_portfolio_shared']; $after['order'] = 50;
check($after === $before[1]['_portfolio_shared'], 'Only shared order changes.');
portfolio_order_write('technologies', [4, 3]);
check($GLOBALS['meta'][4]['order'] === '10' && $GLOBALS['meta'][3]['order'] === '20', 'Technology ranks stored in the existing ACF field.');
check($GLOBALS['meta'][3]['technology_category'] === 'cms', 'Category unchanged.');
$GLOBALS['screen_type'] = 'technology';
$prepared = ['_name' => 'order', 'name' => 'acf[field_actual_order]'];
check($GLOBALS['filters']['acf/prepare_field']($prepared) === false, 'Prepared numeric order field is hidden in technology editor.');
$GLOBALS['screen_type'] = 'post';
check($GLOBALS['filters']['acf/prepare_field']($prepared) === $prepared, 'Other editors keep their fields.');
$GLOBALS['posts'] = [(object) ['ID' => 3, 'post_title' => 'One', 'post_status' => 'publish'], (object) ['ID' => 4, 'post_title' => 'Two', 'post_status' => 'publish']];
$append = $GLOBALS['hooks']['wp_after_insert_post'];
$append(5, (object) ['post_type' => 'technology', 'post_status' => 'auto-draft'], false, null);
check(!isset($GLOBALS['meta'][5]['order']), 'Automatic draft does not get a premature rank.');
$append(5, (object) ['post_type' => 'technology', 'post_status' => 'draft'], true, (object) ['post_status' => 'auto-draft']);
check($GLOBALS['meta'][5]['order'] === '30', 'First actual save appends a technology.');
$append(6, (object) ['post_type' => 'technology', 'post_status' => 'publish'], true, (object) ['post_status' => 'publish']);
check(!isset($GLOBALS['meta'][6]['order']), 'Existing unranked technology keeps its original position.');
$GLOBALS['posts'] = [];
check(portfolio_order_next('technologies') === 10, 'First item in an empty list gets the initial rank.');
echo "Ordering validation, stale data, storage and new-item contracts passed.\n";
