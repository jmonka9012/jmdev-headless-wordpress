<?php
/** Migration contracts: isolated storage doubles, no WordPress database writes. */
define('ABSPATH', __DIR__);
function add_action(...$args) {}
function absint($value) { return abs((int) $value); }
function wp_attachment_is_image($id) { return in_array($id, [10, 11, 12], true); }
function attachment_url_to_postid($url) { return $url === 'https://example.test/photo.png' ? 12 : 0; }
function get_post_meta($id, $key, $single) { return $GLOBALS['meta'][$id][$key] ?? ''; }
function metadata_exists($type, $id, $key) { return array_key_exists($key, $GLOBALS['meta'][$id] ?? []); }
function update_post_meta($id, $key, $value) { $GLOBALS['meta'][$id][$key] = $value; }
function get_post_thumbnail_id($id) { return get_post_meta($id, '_thumbnail_id', true); }
function set_post_thumbnail($id, $value) { update_post_meta($id, '_thumbnail_id', $value); }
function update_field($key, $value, $id) { update_post_meta($id, 'project_gallery', $value); }
function check($valid, $message) { if (!$valid) { throw new RuntimeException($message); } }
require dirname(__DIR__) . '/projects/legacy-media.php';
$GLOBALS['meta'] = [
    1 => ['photo_1' => '10', 'photo_2' => 11, 'photo_3' => 'https://example.test/photo.png'],
    2 => ['photo_1' => 10, 'project_gallery' => [['image' => 12, 'caption' => 'Keep me']], '_thumbnail_id' => 11],
    3 => ['photo_1' => 10, 'photo_2' => 'https://missing.test/image.png'],
    4 => ['photo_1' => 10, 'project_gallery' => []],
];
check(portfolio_import_legacy_project_media(1) === 'imported', 'Imports old photos.');
check(array_column(get_post_meta(1, 'project_gallery', true), 'image') === [10, 11, 12], 'Keeps all three photos in order.');
check(get_post_thumbnail_id(1) === 10, 'Copies archive thumbnail into featured media.');
check(get_post_meta(1, 'photo_1', true) === '10', 'Preserves old values.');
update_post_meta(1, 'project_gallery', [['image' => 11, 'caption' => 'Edited']]);
check(portfolio_import_legacy_project_media(1) === 'unchanged', 'Does not repeat migration.');
check(get_post_meta(1, 'project_gallery', true)[0]['image'] === 11, 'Preserves later edits.');
portfolio_import_legacy_project_media(2);
check(get_post_thumbnail_id(2) === 11, 'Keeps existing featured image.');
check(get_post_meta(2, 'project_gallery', true)[0]['caption'] === 'Keep me', 'Keeps existing gallery.');
check(portfolio_import_legacy_project_media(3) === 'unresolved', 'Reports unresolved media.');
check(!metadata_exists('post', 3, 'project_gallery'), 'Does not partially import.');
check(!get_post_meta(3, '_portfolio_legacy_media_imported_v1', true), 'Allows retry.');
portfolio_import_legacy_project_media(4);
check(get_post_meta(4, 'project_gallery', true) === [], 'Preserves intentionally empty gallery.');
echo "Legacy media migration contracts passed.\n";
