<?php
define('ABSPATH', __DIR__);
$GLOBALS['actions'] = [];
$GLOBALS['types'] = [];
function add_action($name, $callback, $priority = 10) { $GLOBALS['actions'][$name] = [$callback, $priority]; }
function post_type_exists($name) { return isset($GLOBALS['types'][$name]); }
function register_post_type($name, $args) { $GLOBALS['types'][$name] = $args; $GLOBALS['registrations'] = ($GLOBALS['registrations'] ?? 0) + 1; }
function check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
require dirname(__DIR__) . '/schema/post-types.php';
[$register, $priority] = $GLOBALS['actions']['init'];
check($priority === 0, 'Code must register before ACF reads database post types.');
$register();
$args = $GLOBALS['types']['technology'];
check($args['show_in_rest'] === true, 'Technology remains available through REST.');
check(in_array('thumbnail', $args['supports'], true) && in_array('custom-fields', $args['supports'], true), 'Existing media and metadata support preserved.');
$register();
check($GLOBALS['registrations'] === 1, 'Repeated initialization does not replace an existing post type.');
echo "Technology PHP registration contracts passed.\n";
