<?php
/** Pure projection and storage contracts; no database or browser claims. */
define('ABSPATH', __DIR__);
function add_action(...$args) {}
function add_filter(...$args) {}
$GLOBALS['meta'] = [];
function wp_slash($value) { return $value; }
function update_post_meta($id, $key, $value) { $GLOBALS['meta'][$id][$key] = is_scalar($value) ? (string) $value : $value; }
function get_post_meta($id, $key, $single = true) { return $GLOBALS['meta'][$id][$key] ?? ''; }
require dirname(__DIR__) . '/schema.php';
require dirname(__DIR__) . '/multilingual.php';
require dirname(__DIR__) . '/language-admin.php';
function check($valid, $message) { if (!$valid) { throw new RuntimeException($message); } }
portfolio_store_meta(1, '_portfolio_root_id', 204);
portfolio_store_meta(1, '_portfolio_local', ['images' => ['a' => ['alt' => 'A \\ B']]]);
check(get_post_meta(1, '_portfolio_root_id') === '204', 'WP scalar string representation accepted.');
$shared = ['gallery' => [['row_id' => 'same', 'image' => 10]], 'technology_ids' => [71], 'challenges' => [['row_id' => 'challenge-01', 'link_url' => 'https://example.test/shared', 'screenshots' => [['row_id' => 'same', 'image' => 10], ['row_id' => 'second', 'image' => 11]]], ['row_id' => 'empty', 'screenshots' => []]]];
$local = ['images' => ['hero/same' => ['alt' => 'Localized hero', 'caption' => 'Caption'], 'challenge-01/same' => ['alt' => 'Localized detail'], 'challenge-01/second' => ['alt' => '']], 'challenges' => ['challenge-01' => ['title' => 'Challenge', 'link_url' => '', 'solution' => 'Overview']], 'seo' => ['title' => 'SEO'], 'outcome' => ''];
$resolver = static fn($id) => ['id' => $id, 'url' => 'https://example.test/' . $id . '.png', 'alt' => 'Global'];
$payload = portfolio_project_present($shared, $local, $resolver);
check($payload['gallery'][0]['image']['alt'] === 'Localized hero', 'Hero uses localized alt.');
check($payload['challenges'][0]['screenshots'][0]['image']['alt'] === 'Localized detail', 'Same attachment can have a different usage alt.');
check($payload['challenges'][0]['screenshots'][1]['image']['alt'] === '', 'Explicit empty alt is respected.');
check(count($payload['challenges']) === 1 && count($payload['challenges'][0]['screenshots']) === 2, 'Empty sections omitted; ordered multiple images preserved.');
check($payload['challenges'][0]['link_url'] === '', 'Language override can hide shared link.');
$other = $local; $other['images']['hero/same']['alt'] = 'Alternative hero';
check(portfolio_project_present($shared, $other, $resolver)['gallery'][0]['image']['alt'] === 'Alternative hero', 'Languages do not overwrite each other.');
check($resolver(10)['alt'] === 'Global', 'Global attachment alt is unchanged.');
check($payload['project_type'] === '', 'Missing type has no default.');
foreach (['commercial', 'personal'] as $type) {
    $shared['project_type'] = $type;
    portfolio_store_meta(1, '_portfolio_shared', $shared);
    $saved = get_post_meta(1, '_portfolio_shared', true);
    check(portfolio_project_present($saved, $local, $resolver)['project_type'] === $type, 'Saved type is exposed in PL.');
    check(portfolio_project_present($saved, $other, $resolver)['project_type'] === $type, 'Saved type is shared with EN.');
}
$shared['project_type'] = 'invalid';
check(portfolio_project_present($shared, $local, $resolver)['project_type'] === '', 'Invalid type is omitted.');
$shared['gallery'] = [];
check(portfolio_project_present($shared, $local, $resolver)['gallery'] === [], 'Explicit empty gallery authoritative.');
echo "Multilingual projection and meta storage contracts passed.\n";
