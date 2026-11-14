<?php
/**
 * Read-only content audit. Run with WP-CLI eval-file, after a database backup.
 * No content updates, migrations, media creation or admin_init requests.
 * Output directory must not exist: existing evidence is never overwritten.
 */
if (!defined('WP_CLI') || !WP_CLI) { exit("Run this file with WP-CLI eval-file.\n"); }

$destination = $args[0] ?? '';
if (!$destination || !str_starts_with($destination, '/') || file_exists($destination)) {
    WP_CLI::error('Provide a new absolute output directory. Existing directories are never overwritten.');
}
if (!wp_mkdir_p($destination)) { WP_CLI::error('Cannot create audit directory.'); }
$write = static function ($name, $value) use ($destination) {
    $path = $destination . '/' . $name;
    if (!wp_mkdir_p(dirname($path))) { WP_CLI::error('Cannot create output subdirectory.'); }
    $json = wp_json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || file_put_contents($path, $json . "\n", LOCK_EX) === false) {
        WP_CLI::error('Cannot write ' . $name);
    }
};

// Explicit candidates, checked against current records. Never infer identity by title.
$slugs = [
    'agroas' => 'agroas-pl', 'bekuplast' => 'bekuplast-pl',
    'bts' => 'bts-develpoment-pl', 'drutplast' => 'drutplast-pl',
    'epc' => 'epcsolar-eu', 'funk' => 'funk-pl', 'ledson' => 'ledson-eu',
    'lobster' => 'lobstersoftware-com', 'rbtsolar' => 'rbtsolar-com',
    'remondis' => 'remondis-szczecin-pl',
];
$problems = [];
$pairs = [];
$posts = get_posts(['post_type' => 'post', 'post_status' => ['publish', 'draft', 'pending', 'private', 'future'], 'numberposts' => -1]);
foreach ($slugs as $key => $slug) {
    foreach (['pl' => $slug, 'en' => $slug . '-2'] as $locale => $wp_slug) {
        $found = array_values(array_filter($posts, static fn($post) => $post->post_name === $wp_slug));
        if (count($found) !== 1) {
            $problems[] = "Expected exactly one record: $key/$locale ($wp_slug).";
            continue;
        }
        $post = $found[0];
        if (get_post_meta($post->ID, 'lang', true) !== $locale) { $problems[] = "Language mismatch for post {$post->ID}."; }
        $pairs[$key][$locale] = ['id' => $post->ID, 'slug' => $post->post_name, 'status' => $post->post_status];
    }
}
$polak = array_values(array_filter($posts, static fn($post) => str_contains($post->post_name, 'polak')));
if ($polak) { $problems[] = 'Existing Polak candidates require explicit mapping before creation.'; }
$archive = array_values(array_map(static fn($post) => ['id' => $post->ID, 'slug' => $post->post_name], array_filter($posts, static fn($post) => in_array($post->post_name, ['wpelnizdrowia-pl', 'wpelnizdrowia-pl-2'], true))));

$meta = [];
foreach (array_merge($posts, get_posts(['post_type' => 'page', 'post_status' => 'any', 'numberposts' => -1])) as $post) {
    $values = get_post_meta($post->ID);
    $selected = [];
    foreach ($values as $key => $value) {
        if (preg_match('/^(?:_?(?:lang|link|order|template|photo_[123]|thumbnail_id|project_.+)|_portfolio_.+)$/', $key)) {
            $selected[$key] = array_map('maybe_unserialize', $value);
        }
    }
    $meta[] = ['id' => $post->ID, 'type' => $post->post_type, 'slug' => $post->post_name, 'meta' => $selected];
}
$write('content-meta.json', $meta);

// Initialize the actual REST server and preserve responses before the exporter's transformations.
$server = rest_get_server();
foreach (['posts', 'pages', 'technology', 'categories'] as $type) {
    $page = 1;
    do {
        $request = new WP_REST_Request('GET', '/wp/v2/' . $type);
        $request->set_query_params(['per_page' => 100, 'page' => $page, '_embed' => true, 'acf_format' => 'standard']);
        $response = $server->dispatch($request);
        $headers = $response->get_headers();
        $write("rest/$type-$page.json", [
            'status' => $response->get_status(), 'headers' => $headers,
            'body' => $server->response_to_data($response, true),
        ]);
        if ($response->get_status() !== 200) { $problems[] = "REST $type page $page returned {$response->get_status()}."; break; }
        $total = (int) ($headers['X-WP-TotalPages'] ?? 1);
        $page++;
    } while ($page <= $total);
}

$groups = [];
$expand_fields = static function ($fields) use (&$expand_fields) {
    $fields = $fields ?: [];
    foreach ($fields as &$field) {
        if (in_array($field['type'] ?? '', ['group', 'repeater', 'portfolio_rows'], true)) {
            $field['sub_fields'] = $expand_fields($field['sub_fields'] ?? acf_get_fields($field));
        }
    }
    return $fields ?: [];
};
if (function_exists('acf_get_field_groups')) {
    foreach (acf_get_field_groups() as $group) {
        $group['fields'] = $expand_fields(acf_get_fields($group));
        $groups[] = $group;
    }
} else { $problems[] = 'ACF is unavailable.'; }
$write('acf-fields.json', $groups);

$attachments = [];
foreach (get_posts(['post_type' => 'attachment', 'post_status' => 'inherit', 'numberposts' => -1]) as $attachment) {
    $file = get_attached_file($attachment->ID);
    $attachments[] = [
        'id' => $attachment->ID, 'mime' => $attachment->post_mime_type,
        'file' => get_post_meta($attachment->ID, '_wp_attached_file', true),
        'url' => wp_get_attachment_url($attachment->ID),
        'sha256' => is_string($file) && is_file($file) && is_readable($file) ? hash_file('sha256', $file) : null,
        'original_image' => wp_get_original_image_path($attachment->ID),
        'alt' => get_post_meta($attachment->ID, '_wp_attachment_image_alt', true),
    ];
}
$write('attachments.json', $attachments);
$post_types = [];
foreach (['post', 'page', 'technology'] as $name) {
    $type = get_post_type_object($name);
    $post_types[$name] = $type ? ['rest_base' => $type->rest_base, 'show_in_rest' => $type->show_in_rest, 'supports' => get_all_post_type_supports($name)] : null;
}
$report = [
    'generated_at' => gmdate('c'), 'mode' => 'read-only', 'wordpress' => get_bloginfo('version'),
    'active_plugins' => get_option('active_plugins'), 'post_types' => $post_types,
    'existing_project_pairs' => $pairs, 'new_project' => 'polakiron',
    'archive_candidates' => $archive, 'attachment_count' => count($attachments),
    'problems' => $problems,
];
$write('report.json', $report);
WP_CLI::success('Audit saved to ' . $destination . '; problems: ' . count($problems) . '. No project data changed.');
