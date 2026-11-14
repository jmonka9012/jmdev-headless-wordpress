<?php
/** Project creation contracts with isolated WordPress persistence doubles. */
define('ABSPATH', __DIR__);
function add_action(...$args) {}
function add_filter(...$args) {}
function current_user_can($capability, ...$args) { return $GLOBALS['allowed'] ?? true; }
function sanitize_text_field($value) { return trim(strip_tags($value)); }
function sanitize_title($value) { return strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $value), '-')); }
function wp_is_uuid($value, $version) { return preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD', $value); }
function get_option($name, $default = null) { return $default; }
function wp_slash($value) { return $value; }
function wp_attachment_is_image($id) { return $id === 71; }
function portfolio_order_next($kind) { return 20; }
function get_post_meta($id, $key, $single = true) { return $GLOBALS['meta'][$id][$key] ?? ''; }
function portfolio_store_meta($id, $key, $value) { $GLOBALS['meta'][$id][$key] = $value; }
function get_post_status($id) { return $GLOBALS['posts'][$id]->post_status; }
function get_posts($query) {
    return array_values(array_filter($GLOBALS['posts'] ?? [], static fn($post) => !isset($query['meta_key']) || get_post_meta($post->ID, $query['meta_key']) === $query['meta_value']));
}
function wp_insert_post($data, $error) {
    $id = count($GLOBALS['posts'] ?? []) + 1;
    $GLOBALS['posts'][$id] = (object) ($data + ['ID' => $id]);
    return $id;
}
function is_wp_error($result) { return false; }
function clean_post_cache($id) {}
class Project_Test_DB {
    public $queries = [];
    public function prepare($query, ...$args) { return $query; }
    public function get_var($query) { return 1; }
    public function query($query) { $this->queries[] = $query; return 0; }
}
$GLOBALS['wpdb'] = new Project_Test_DB();
require dirname(__DIR__) . '/projects/schema.php';
require dirname(__DIR__) . '/projects/multilingual.php';
require dirname(__DIR__) . '/admin/shared/ui.php';
require dirname(__DIR__) . '/admin/project-create.php';
function check($valid, $message) { if (!$valid) { throw new RuntimeException($message); } }
function rejects($callback) { try { $callback(); } catch (RuntimeException $error) { return; } throw new RuntimeException('Invalid operation accepted.'); }
check(portfolio_project_available_slug('Example', ['example', 'example-2']) === 'example-3', 'New public slugs avoid existing projects across languages.');
check(portfolio_project_available_slug('', []) !== '', 'Empty sanitized slugs have a usable fallback.');
$input = ['title' => 'Example', 'locale' => 'en', 'project_type' => 'personal', 'key' => '11111111-1111-4111-8111-111111111111'];
foreach ([['title' => ''], ['locale' => 'xx'], ['locale' => []], ['project_type' => 'other'], ['key' => 'invalid']] as $change) {
    rejects(fn() => portfolio_create_project(array_replace($input, $change)));
}
$GLOBALS['allowed'] = false; rejects(fn() => portfolio_create_project($input)); $GLOBALS['allowed'] = true;
check(empty($GLOBALS['posts']), 'Rejected requests create no records.');
$id = portfolio_create_project($input);
check(get_post_status($id) === 'draft', 'Creation cannot publish a project.');
check(portfolio_project_root($id) === $id, 'New projects enter the managed editor directly.');
check(get_post_meta($id, '_portfolio_locale') === 'en', 'Initial locale matches the requested registered language.');
check(get_post_meta($id, '_portfolio_shared')['order'] === 20 && get_post_meta($id, '_portfolio_shared')['project_type'] === 'personal', 'Shared fields and ordering initialize without a content import.');
check(get_post_meta($id, '_portfolio_local') === portfolio_project_local_defaults(), 'Localized collections initialize consistently.');
check(portfolio_create_project($input) === $id && count($GLOBALS['posts']) === 1, 'Repeated form submissions do not create duplicate projects.');
$other = $input; $other['key'] = '22222222-2222-4222-8222-222222222222';
$second = portfolio_create_project($other);
check(get_post_meta($second, '_portfolio_public_slug') !== get_post_meta($id, '_portfolio_public_slug'), 'Independent projects with the same title receive distinct public slugs.');
$GLOBALS['posts'][$id]->post_status = 'trash'; rejects(fn() => portfolio_create_project($input));
check(portfolio_admin_image_id('71') === 71 && portfolio_admin_image_id('') === 0, 'Thumbnail selection supports valid media and explicit clearing.');
foreach ([[], -71, 'invalid', '72'] as $value) { rejects(fn() => portfolio_admin_image_id($value)); }
echo "Project creation, repeat submission, permissions, slugs and image contracts passed.\n";
