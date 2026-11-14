<?php
/** Targeted recovery for the completed first import. Never reruns the importer. */
if (!defined('WP_CLI') || !WP_CLI) { exit("Run with WP-CLI eval-file.\n"); }
require_once __DIR__ . '/portfolio-import-package.php';
require_once __DIR__ . '/portfolio-import-plan.php';
require_once __DIR__ . '/portfolio-import-inventory.php';
require_once __DIR__ . '/portfolio-import-verify.php';
require_once __DIR__ . '/portfolio-repair-values.php';
if (count($args) !== 3) { WP_CLI::error('Usage: PACKAGE_DIRECTORY ORIGINAL_APPLY_DIRECTORY NEW_RECOVERY_DIRECTORY'); }
[$directory, $original, $output] = $args;
$host = wp_parse_url(home_url(), PHP_URL_HOST);
if (!is_string($host) || !str_ends_with($host, '.ddev.site')) { WP_CLI::error('Local DDEV only.'); }
if (!str_starts_with($output, '/') || file_exists($output)) { WP_CLI::error('Use a new absolute recovery report directory.'); }
try {
    $package = portfolio_import_read_package($directory);
    $journal = json_decode(file_get_contents($original . '/journal.json'), true, 512, JSON_THROW_ON_ERROR);
    $before = json_decode(file_get_contents($original . '/before-state.json'), true, 512, JSON_THROW_ON_ERROR);
    if (empty($journal['completed']) || $journal['package_sha256'] !== hash_file('sha256', realpath($directory) . '/package.json')) { throw new RuntimeException('Completed matching import journal required.'); }
    $baseline = [];
    foreach (glob(dirname($original) . '/before-migration/rest/posts-*.json') as $file) {
        foreach (json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR)['body'] as $post) { $baseline[$post['id']] = $post; }
    }
    $restore_fields = ['post_status', 'post_author', 'post_date', 'post_date_gmt', 'post_name', 'post_password', 'comment_status', 'ping_status', 'post_parent', 'menu_order', 'post_mime_type', 'post_content_filtered', 'to_ping', 'pinged', 'guid'];
    $targets = [];
    $evidence = [];
    foreach ($package['existing_project_pairs'] as $key => $locales) {
        foreach ($locales as $locale => $reference) {
            $id = $reference['id'];
            $saved = $before['posts'][$id]['post'] ?? null;
            $current = get_post($id, ARRAY_A);
            $source = $package['projects'][$key][$locale];
            if (!$saved || !$current || ($journal['projects'][$key][$locale] ?? 0) !== $id || $current['post_name'] !== $reference['slug'] || get_post_meta($id, '_portfolio_import_sha256', true) !== $journal['package_sha256']) { throw new RuntimeException('Mapping changed: ' . $id); }
            if ($saved['post_status'] !== 'publish' || !in_array($current['post_status'], ['draft', 'publish'], true)) { throw new RuntimeException('Unexpected status: ' . $id); }
            foreach (['post_title', 'post_excerpt', 'post_content'] as $field) {
                if ($current[$field] !== $source[$field]) { throw new RuntimeException('Content edited after import; recovery stopped: ' . $id . '/' . $field); }
            }
            if (!isset($baseline[$id]['categories'], $baseline[$id]['tags'])) { throw new RuntimeException('Original taxonomy snapshot missing: ' . $id); }
            $values = portfolio_repair_post_values($id, $saved, $restore_fields, $baseline[$id]['categories'], $baseline[$id]['tags']);
            $differences = [];
            foreach ($restore_fields as $field) {
                $values[$field] = $saved[$field];
                if ((string) $current[$field] !== (string) $saved[$field]) { $differences[$field] = ['before_repair' => $current[$field], 'restore' => $saved[$field]]; }
            }
            $values['post_category'] = $baseline[$id]['categories'];
            $values['tags_input'] = $baseline[$id]['tags'];
            $targets[$id] = $values;
            $evidence[$id] = ['current_post' => $current, 'restored_fields' => $differences, 'original_categories' => $values['post_category'], 'original_tags' => $values['tags_input']];
        }
    }
    if (count($targets) !== 20) { throw new RuntimeException('Expected exactly 20 original translation records.'); }
    if (!wp_mkdir_p($output)) { throw new RuntimeException('Cannot create recovery report directory.'); }
    foreach (['before-state.json' => $before, 'journal.json' => $journal, 'repair-plan.json' => $evidence] as $file => $data) {
        if (file_put_contents($output . '/' . $file, wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) { throw new RuntimeException('Cannot write recovery report.'); }
    }
} catch (Throwable $error) { WP_CLI::error($error->getMessage()); }

global $wpdb;
$lock = 'portfolio-language-import';
if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock)) !== 1) { WP_CLI::error('Another import is running.'); }
$committed = false;
try {
    if ($wpdb->query('START TRANSACTION') === false) { throw new RuntimeException('Cannot start recovery transaction.'); }
    foreach ($targets as $id => $values) {
        $result = wp_update_post(wp_slash($values), true);
        if (is_wp_error($result)) { throw new RuntimeException($result->get_error_message()); }
        clean_post_cache($id);
        $post = get_post($id, ARRAY_A);
        foreach ($restore_fields as $field) {
            if ((string) $post[$field] !== (string) $values[$field]) { throw new RuntimeException('Restore mismatch: ' . $id . '/' . $field . ' (expected ' . json_encode($values[$field]) . ', actual ' . json_encode($post[$field]) . ')'); }
        }
        $cats = wp_get_post_categories($id); $expected_cats = $values['post_category']; sort($cats); sort($expected_cats);
        $tags = wp_get_post_tags($id, ['fields' => 'ids']); $expected_tags = $values['tags_input']; sort($tags); sort($expected_tags);
        if ($cats !== $expected_cats || $tags !== $expected_tags) { throw new RuntimeException('Taxonomy restore mismatch: ' . $id); }
    }
    $verification = portfolio_import_verify($package, $journal, $output);
    if ($verification['errors']) { throw new RuntimeException('Verification still fails: ' . implode(' | ', $verification['errors'])); }
    update_option('portfolio_language_ui_enabled', true, false);
    if ($wpdb->query('COMMIT') === false) { throw new RuntimeException('Recovery commit failed.'); }
    $committed = true;
    WP_CLI::success('Restored publication/native settings for 20 existing records. Data/API verification passed; unified editor enabled. No projects, technologies or media created. Report: ' . $output);
} catch (Throwable $error) {
    if (!$committed) { $wpdb->query('ROLLBACK'); foreach (array_keys($targets) as $id) { clean_post_cache($id); } wp_cache_delete('alloptions', 'options'); }
    WP_CLI::error('Recovery stopped and rolled back: ' . $error->getMessage() . '. Existing reports retained.');
} finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); }
