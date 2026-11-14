<?php
define('ABSPATH', __DIR__);
function get_post($id) { return $id === 8 ? (object) ['ID' => 8] : null; }
function acf_enqueue_scripts() {}
function acf_localize_data($data) { $GLOBALS['context'] = $data; }
function wp_enqueue_media($args) { $GLOBALS['media'] = $args; }
function wp_enqueue_editor() {}
function wp_enqueue_script(...$args) {}
function wp_enqueue_style(...$args) {}
function plugins_url($path, $file) { return $path; }
require dirname(__DIR__) . '/admin-ui.php';
foreach ([0, 999] as $id) {
    portfolio_admin_enqueue_editor($id);
    if ($GLOBALS['media'] !== [] || $GLOBALS['context']['post_id'] !== 0) { throw new RuntimeException('List/new/invalid views must omit the media post argument.'); }
}
portfolio_admin_enqueue_editor(8);
if ($GLOBALS['media'] !== ['post' => 8] || $GLOBALS['context']['post_id'] !== 8) { throw new RuntimeException('Existing records must retain their media context.'); }
echo "Media contexts for lists, new forms, missing and existing records passed.\n";
