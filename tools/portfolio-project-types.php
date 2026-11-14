<?php
/** Backfill existing project types in local WordPress once. */
if (!defined('WP_CLI') || !WP_CLI) { exit("Run with WP-CLI eval-file.\n"); }
if (count($args) !== 1) { WP_CLI::error('Usage: NEW_REPORT_DIRECTORY'); }
$output = $args[0];
$host = wp_parse_url(home_url(), PHP_URL_HOST);
if (!is_string($host) || !str_ends_with($host, '.ddev.site')) { WP_CLI::error('Local DDEV only.'); }
if (get_option('portfolio_project_types_initialized')) { WP_CLI::success('Typy projektów zostały już uzupełnione.'); return; }
if (!str_starts_with($output, '/') || file_exists($output)) { WP_CLI::error('Use a new absolute report directory.'); }
global $wpdb;
$locks = [];
$before = [];
try {
    $roots = get_posts(['post_type' => 'post', 'post_status' => 'publish', 'numberposts' => -1, 'meta_key' => '_portfolio_shared']);
    if (count($roots) !== 11) { throw new RuntimeException('Oczekiwano dokładnie 11 obecnych projektów.'); }
    foreach ($roots as $post) {
        $root = $post->ID;
        if (portfolio_project_root($root) !== $root) { throw new RuntimeException('Nieprawidłowy projekt: ' . $root); }
        $lock = 'portfolio-project-' . $root;
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock)) !== 1) { throw new RuntimeException('Projekt jest zapisywany: ' . $root); }
        $locks[] = $lock;
        clean_post_cache($root);
        $shared = get_post_meta($root, '_portfolio_shared', true);
        if (!is_array($shared) || !in_array($shared['project_type'] ?? '', ['', 'commercial'], true)) {
            throw new RuntimeException('Typ projektu wymaga sprawdzenia: ' . $root);
        }
        $before[$root] = $shared;
    }
    if (!wp_mkdir_p($output) || file_put_contents($output . '/before-state.json', wp_json_encode($before, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
        throw new RuntimeException('Nie udało się zapisać kopii danych.');
    }
    $wpdb->query('START TRANSACTION');
    foreach ($before as $root => $shared) {
        $shared['project_type'] = 'commercial';
        portfolio_store_meta($root, '_portfolio_shared', $shared);
        foreach (portfolio_project_translations($root) as $post) {
            if ((portfolio_multilingual_payload($post->ID)['project_type'] ?? '') !== 'commercial') {
                throw new RuntimeException('Nieprawidłowy typ w API projektu: ' . $post->ID);
            }
        }
    }
    if (!update_option('portfolio_project_types_initialized', true, false)) { throw new RuntimeException('Nie udało się zapisać stanu migracji.'); }
    if ($wpdb->query('COMMIT') === false) { throw new RuntimeException('Nie udało się zatwierdzić migracji.'); }
    WP_CLI::success('Zapisano typ komercyjny dla 11 projektów i ich wersji językowych.');
} catch (Throwable $error) {
    $wpdb->query('ROLLBACK');
    foreach (array_keys($before) as $root) { clean_post_cache($root); }
    wp_cache_delete('portfolio_project_types_initialized', 'options');
    WP_CLI::error($error->getMessage(), false);
    $failed = true;
} finally {
    foreach ($locks as $lock) { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); }
}
if (!empty($failed)) { WP_CLI::halt(1); }
