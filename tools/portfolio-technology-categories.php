<?php
/** Assign existing technologies to categories once. */
if (!defined('WP_CLI') || !WP_CLI) { exit("Run with WP-CLI eval-file.\n"); }
if (count($args) !== 1) { WP_CLI::error('Usage: NEW_REPORT_DIRECTORY'); }
$output = $args[0];
$host = wp_parse_url(home_url(), PHP_URL_HOST);
if (!is_string($host) || !str_ends_with($host, '.ddev.site')) { WP_CLI::error('Local DDEV only.'); }
if (get_option('portfolio_technology_categories_initialized')) { WP_CLI::success('Kategorie zostały już przypisane.'); return; }
if (!str_starts_with($output, '/') || file_exists($output)) { WP_CLI::error('Use a new absolute report directory.'); }
$groups = [
    'frontend' => ['javascript', 'html', 'css', 'scss', 'vue-js', 'bootstrap'],
    'cms' => ['wordpress', 'bricks-builder', 'acf', 'typo3-fluid', 'woocommerce', 'polylang'],
    'motion' => ['gsap', 'animejs', 'jquery', 'swiper', 'splide', 'lenis', 'particlesjs', 'choicesjs', 'fancybox', 'p5-js-webgl'],
    'code' => ['php', 'laravel', 'inertia-js', 'stimulus', 'twig', 'nunjucks', 'relevanssi'],
    'workflow' => ['git', 'ddev', 'vite', 'bedrock', 'jira'],
];
global $wpdb;
$before = [];
$lock = 'portfolio-technology-categories';
if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock)) !== 1) { WP_CLI::error('Trwa przypisywanie kategorii.'); }
try {
    $expected = [];
    foreach ($groups as $key => $slugs) { foreach ($slugs as $slug) { $expected[$slug] = $key; } }
    $posts = get_posts(['post_type' => 'technology', 'post_status' => 'publish', 'numberposts' => -1]);
    if (count($posts) !== count($expected)) { throw new RuntimeException('Lista technologii wymaga sprawdzenia.'); }
    foreach ($posts as $post) {
        if (!isset($expected[$post->post_name])) { throw new RuntimeException('Nieznana technologia: ' . $post->post_name); }
        $current = get_field('technology_category', $post->ID);
        if ($current && $current !== $expected[$post->post_name]) { throw new RuntimeException('Istnieje inne przypisanie: ' . $post->post_name); }
        $before[$post->ID] = [
            'technology_category' => get_post_meta($post->ID, 'technology_category', true),
            '_technology_category' => get_post_meta($post->ID, '_technology_category', true),
        ];
    }
    if (!wp_mkdir_p($output) || file_put_contents($output . '/before-state.json', wp_json_encode($before, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
        throw new RuntimeException('Nie udało się zapisać kopii danych.');
    }
    if ($wpdb->query('START TRANSACTION') === false) { throw new RuntimeException('Nie udało się rozpocząć transakcji.'); }
    foreach ($posts as $post) {
        $key = $expected[$post->post_name];
        update_field('field_portfolio_technology_category', $key, $post->ID);
        if ((portfolio_technology_category($post->ID)['key'] ?? '') !== $key) { throw new RuntimeException('Nie udało się zapisać kategorii: ' . $post->post_name); }
    }
    if (!update_option('portfolio_technology_categories_initialized', true, false)) { throw new RuntimeException('Nie udało się zapisać stanu migracji.'); }
    if ($wpdb->query('COMMIT') === false) { throw new RuntimeException('Nie udało się zatwierdzić migracji.'); }
    WP_CLI::success('Przypisano 34 technologie do 5 kategorii.');
} catch (Throwable $error) {
    $wpdb->query('ROLLBACK');
    foreach (array_keys($before) as $id) { clean_post_cache($id); }
    wp_cache_delete('portfolio_technology_categories_initialized', 'options');
    WP_CLI::error($error->getMessage(), false);
    $failed = true;
} finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); }
if (!empty($failed)) { WP_CLI::halt(1); }
