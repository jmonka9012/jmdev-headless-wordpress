<?php
/** Exercise the actual local WordPress update implementation, without a database. */
define('ARRAY_A', 'ARRAY_A');
function wp_slash($value) { return $value; }
function get_post($id, $format) { return $GLOBALS['existing']; }
function current_time($format) { return '2026-11-12 20:00:00'; }
function is_object_in_taxonomy($type, $taxonomy) { return false; }
function wp_insert_post($values, $error, $hooks = true) { $GLOBALS['written'] = $values; return $values['ID']; }
$source = file_get_contents(dirname(__DIR__, 2) . '/wordpress/wp-includes/post.php');
$start = strpos($source, 'function wp_update_post(');
$end = strpos($source, "\n}\n", $start);
if ($start === false || $end === false) { throw new RuntimeException('Cannot locate installed WordPress wp_update_post implementation.'); }
eval(substr($source, $start, $end - $start + 2));
require dirname(__DIR__) . '/portfolio-repair-values.php';
require dirname(__DIR__) . '/portfolio-import-post.php';
$GLOBALS['existing'] = ['ID' => 290, 'post_type' => 'post', 'post_status' => 'draft', 'post_date' => '2026-11-12 19:03:18', 'post_date_gmt' => '0000-00-00 00:00:00', 'post_category' => [1], 'post_author' => 0];
$saved = ['post_status' => 'publish', 'post_date' => '2026-08-26 16:43:53', 'post_date_gmt' => '2026-08-26 16:43:53', 'post_author' => 1];
$fields = array_keys($saved);
$values = portfolio_repair_post_values(290, $saved, $fields, [4], []);
// Prove the original failure: WordPress clears provided dates without edit_date.
$without_flag = $values; unset($without_flag['edit_date']);
wp_update_post($without_flag, true);
if ($GLOBALS['written']['post_date'] === $saved['post_date']) { throw new RuntimeException('Fixture no longer reproduces the undated-draft behavior.'); }
wp_update_post($values, true);
foreach ($fields as $field) {
    if ($GLOBALS['written'][$field] !== $saved[$field]) { throw new RuntimeException('Recovery failed to preserve ' . $field); }
}
if ($GLOBALS['written']['post_category'] !== [4]) { throw new RuntimeException('Original category not restored.'); }
portfolio_import_write_project(['action' => 'update', 'id' => 290], ['post_title' => 'Updated', 'post_excerpt' => 'Excerpt', 'post_content' => 'Content']);
if ($GLOBALS['written']['post_date'] !== $GLOBALS['existing']['post_date'] || $GLOBALS['written']['post_status'] !== 'draft') { throw new RuntimeException('Import update must preserve existing draft date/status.'); }
echo "Installed WordPress regression passed: failure reproduced, repair restores dates, import preserves draft date/status.\n";

// Also exercise every original recovery record captured from the live failed attempt.
$root = dirname(__DIR__, 3);
$plan = json_decode(file_get_contents($root . '/backend/.local-audit/import-repair/repair-plan.json'), true, 512, JSON_THROW_ON_ERROR);
$before = json_decode(file_get_contents($root . '/backend/.local-audit/import-apply/before-state.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ($plan as $id => $entry) {
    $GLOBALS['existing'] = $entry['current_post'];
    $GLOBALS['existing']['post_category'] = $GLOBALS['existing']['post_category'] ?? [1];
    $saved = $before['posts'][$id]['post'];
    $fields = ['post_status', 'post_author', 'post_date', 'post_date_gmt', 'post_name', 'post_password', 'comment_status', 'ping_status', 'post_parent', 'menu_order', 'post_mime_type', 'post_content_filtered', 'to_ping', 'pinged', 'guid'];
    $values = portfolio_repair_post_values((int) $id, $saved, $fields, $entry['original_categories'], $entry['original_tags']);
    wp_update_post($values, true);
    foreach ($fields as $field) {
        if ((string) $GLOBALS['written'][$field] !== (string) $saved[$field]) { throw new RuntimeException('Recovery fixture mismatch: ' . $id . '/' . $field); }
    }
}
echo "All 20 captured recovery records preserve their original native values through the installed WordPress update function.\n";
