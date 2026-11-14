<?php
/** Regression: existing projects must use WP's merge-preserving update API. */
$called = [];
function wp_slash($value) { return $value; }
function wp_update_post($values, $error) { $GLOBALS['called'][] = ['update', $values, $error]; return $values['ID']; }
function wp_insert_post($values, $error) { $GLOBALS['called'][] = ['insert', $values, $error]; return 1000; }
require dirname(__DIR__) . '/portfolio-import-post.php';
$local = ['post_title' => 'Title', 'post_excerpt' => 'Excerpt', 'post_content' => 'Content'];
portfolio_import_write_project(['action' => 'update', 'id' => 174], $local);
portfolio_import_write_project(['action' => 'create', 'slug' => 'polakiron'], $local);
if ($called[0][0] !== 'update' || $called[0][1]['ID'] !== 174 || (isset($called[0][1]['post_status']) || isset($called[0][1]['post_date']))) { throw new RuntimeException('Existing native fields must be preserved through wp_update_post.'); }
if ($called[1][0] !== 'insert' || $called[1][1]['post_status'] !== 'publish' || $called[1][1]['post_name'] !== 'polakiron') { throw new RuntimeException('New project publication must be explicit.'); }
echo "Existing-project update API regression passed.\n";
