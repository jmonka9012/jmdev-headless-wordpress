<?php
/** Local WP-CLI dry-run and explicit apply against an unchanged approved plan. */
if (!defined('WP_CLI') || !WP_CLI) { exit("Run with WP-CLI eval-file.\n"); }
require_once __DIR__ . '/portfolio-import-package.php';
require_once __DIR__ . '/portfolio-import-plan.php';
if (!((count($args) === 3 && $args[2] === 'dry-run') || (count($args) === 4 && $args[2] === 'apply'))) {
    WP_CLI::error('Usage: wp eval-file portfolio-import.php PACKAGE NEW_OUTPUT dry-run; or PACKAGE NEW_OUTPUT apply APPROVED_DRY_RUN_DIRECTORY.');
}
[$package_dir, $output] = $args;
$mode = $args[2];
$host = wp_parse_url(home_url(), PHP_URL_HOST);
if (!is_string($host) || !str_ends_with($host, '.ddev.site')) { WP_CLI::error('This migration tool is restricted to the local DDEV installation.'); }
if (!str_starts_with($output, '/') || file_exists($output)) { WP_CLI::error('Provide a new absolute report directory; existing evidence is never overwritten.'); }
try { $package = portfolio_import_read_package($package_dir); }
catch (Throwable $error) { WP_CLI::error($error->getMessage()); }
if (!function_exists('get_field') || !function_exists('portfolio_technology_field')) { WP_CLI::error('ACF and Portfolio Projects are required.'); }
require_once __DIR__ . '/portfolio-import-inventory.php';
[$inventory, $fields] = portfolio_import_inventory();
$plan = portfolio_import_plan($package, $inventory);
$plan['package_sha256'] = hash_file('sha256', realpath($package_dir) . '/package.json');
$plan['generated_at'] = gmdate('c');
$plan['technology_fields'] = $fields;
foreach ($plan['operations']['projects'] as $operation) {
    if (is_int($operation['id']) && empty($fields[$operation['id']])) {
        $plan['errors'][] = 'Missing technology relation for post ' . $operation['id'];
        $plan['ready'] = false;
    }
}
if ($mode === 'apply') {
    $approved_file = rtrim($args[3], '/') . '/plan.json';
    if (!is_file($approved_file)) { WP_CLI::error('Approved dry-run report missing.'); }
    $approved = json_decode(file_get_contents($approved_file), true, 512, JSON_THROW_ON_ERROR);
    if (!$plan['ready'] || empty($approved['ready']) || $approved['package_sha256'] !== $plan['package_sha256'] || $approved['operations'] !== $plan['operations']) {
        WP_CLI::error('Live data/package differ from the approved dry-run. Generate and review a fresh dry-run first.');
    }
}
if (!wp_mkdir_p($output)) { WP_CLI::error('Cannot create report directory.'); }
foreach (['plan.json' => $plan, 'inventory.json' => $inventory] as $name => $value) {
    $json = wp_json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || file_put_contents($output . '/' . $name, $json . "\n", LOCK_EX) === false) { WP_CLI::error('Cannot save ' . $name); }
}
WP_CLI::log(wp_json_encode($plan['counts'], JSON_UNESCAPED_UNICODE));
if (!$plan['ready']) { WP_CLI::error('Dry-run blocked: ' . implode(' | ', $plan['errors']) . '. Report: ' . $output); }
if ($mode === 'apply') {
    require_once __DIR__ . '/portfolio-import-apply.php';
    require_once __DIR__ . '/portfolio-import-verify.php';
    try {
        $journal = portfolio_import_apply($package, $plan, $package_dir, $output);
        $verification = portfolio_import_verify($package, $journal, $output);
        if ($verification['errors']) { WP_CLI::error('Imported, but verification failed; old editor remains available. See verification.json.'); }
        update_option('portfolio_language_ui_enabled', true, false);
        WP_CLI::success('Import and data/API verification passed. Unified editor enabled. Report: ' . $output);
    } catch (Throwable $error) { WP_CLI::error('Import stopped: ' . $error->getMessage() . '. See journal.json; do not blindly retry.'); }
} else { WP_CLI::success('Dry-run ready. No data changed. Report: ' . $output); }
