<?php
/** Local, explicitly invoked apply. The caller has already revalidated the live plan. */
function portfolio_import_apply($package, $plan, $directory, $output) {
    global $wpdb;
    require_once __DIR__ . '/portfolio-import-post.php';
    $journal = ['created_posts' => [], 'created_files' => [], 'completed' => false, 'package_sha256' => $plan['package_sha256']];
    $write = static function ($name, $data) use ($output) {
        $encoded = wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false || file_put_contents($output . '/' . $name, $encoded . "\n", LOCK_EX) === false) { throw new RuntimeException('Cannot save ' . $name); }
    };
    $lock = 'portfolio-language-import';
    if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock)) !== 1) { throw new RuntimeException('Another import is running.'); }
    $uploads = wp_upload_dir();
    if ($uploads['error']) { throw new RuntimeException($uploads['error']); }
    $before = ['posts' => [], 'options' => []];
    foreach (array_merge($plan['operations']['projects'], $plan['operations']['archive'], $plan['operations']['technologies'], $plan['operations']['media']) as $operation) {
        if (isset($operation['id']) && is_int($operation['id'])) {
            $before['posts'][$operation['id']] = ['post' => get_post($operation['id'], ARRAY_A), 'meta' => get_post_meta($operation['id'])];
        }
    }
    foreach (['portfolio_languages', 'portfolio_language_ui_enabled'] as $option) { $before['options'][$option] = get_option($option, null); }
    $write('before-state.json', $before);
    $write('journal.json', $journal);
    $created_ids = [];
    $in_transaction = false;
    $project_locks = [];
    $filter = static fn() => false;
    try {
        // Verify transactional storage rather than promising rollback for MyISAM tables.
        foreach ([$wpdb->posts, $wpdb->postmeta, $wpdb->options, $wpdb->term_relationships, $wpdb->term_taxonomy] as $table) {
            $status = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS WHERE Name = %s', $table));
            if (!$status || strcasecmp($status->Engine, 'InnoDB') !== 0) { throw new RuntimeException('Transactional table required: ' . $table); }
        }
        if ($wpdb->query('START TRANSACTION') === false) { throw new RuntimeException('Cannot start transaction.'); }
        $in_transaction = true;
        foreach ($plan['operations']['projects'] as $operation) {
            if (!is_int($operation['id'])) { continue; }
            $id = $operation['id'];
            $root = portfolio_project_root($id) ?: $id;
            $name = 'portfolio-project-' . $root;
            if (!in_array($name, $project_locks, true)) {
                if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $name)) !== 1) { throw new RuntimeException('Project is being edited: ' . $id); }
                $project_locks[] = $name;
            }
            $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE ID = %d FOR UPDATE", $id));
            $wpdb->get_results($wpdb->prepare("SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d FOR UPDATE", $id));
            clean_post_cache($id);
            $post = get_post($id); $meta = get_post_meta($id);
            unset($meta['_edit_lock'], $meta['_edit_last']); ksort($meta);
            $fingerprint = hash('sha256', serialize([$post->post_title, $post->post_excerpt, $post->post_content, $post->post_name, $post->post_status, $meta]));
            if (!hash_equals($operation['before_fingerprint'], $fingerprint)) { throw new RuntimeException('Project changed since dry-run: ' . $id); }
        }
        require_once ABSPATH . 'wp-admin/includes/image.php';
        add_filter('big_image_size_threshold', $filter);
        $media = [];
        foreach ($plan['operations']['media'] as $operation) {
            $source_id = $operation['source_id'];
            if ($operation['action'] === 'reuse') { $id = $operation['id']; }
            elseif ($operation['action'] === 'reuse-planned') { $id = $media[$operation['ref']]; }
            else {
                $source = realpath($directory . '/' . $operation['file']);
                $name = wp_unique_filename($uploads['path'], 'portfolio-' . basename($source));
                $destination = $uploads['path'] . '/' . $name;
                if (!copy($source, $destination)) { throw new RuntimeException('Cannot copy staged asset.'); }
                $journal['created_files'][$destination] = hash_file('sha256', $destination);
                $id = wp_insert_attachment(wp_slash(['post_title' => $source_id, 'post_status' => 'inherit', 'post_mime_type' => $operation['mime']]), $destination, 0, true);
                if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); }
                $created_ids[] = $id; $journal['created_posts'][] = $id;
                if ($operation['mime'] === 'image/svg+xml') {
                    $xml = new DOMDocument(); $xml->loadXML(file_get_contents($source), LIBXML_NONET);
                    $box = preg_split('/[\s,]+/', trim($xml->documentElement->getAttribute('viewBox')));
                    wp_update_attachment_metadata($id, ['width' => max(1, (int) ceil((float) $box[2])), 'height' => max(1, (int) ceil((float) $box[3])), 'file' => _wp_relative_upload_path($destination)]);
                } else {
                    $metadata = wp_generate_attachment_metadata($id, $destination);
                    if (!$metadata) { throw new RuntimeException('Cannot generate image metadata.'); }
                    wp_update_attachment_metadata($id, $metadata);
                    foreach ($metadata['sizes'] ?? [] as $size) {
                        $file = dirname($destination) . '/' . basename($size['file']);
                        if (is_file($file)) { $journal['created_files'][$file] = hash_file('sha256', $file); }
                    }
                }
                $media[$operation['ref']] = $id;
            }
            $media[$source_id] = $id;
            $sources = get_post_meta($id, '_portfolio_source_ids', true);
            $sources = is_array($sources) ? $sources : [];
            if (!in_array($source_id, $sources, true)) { $sources[] = $source_id; }
            portfolio_store_meta($id, '_portfolio_source_ids', $sources);
            if (isset($package['media'][$source_id])) {
                $asset = $package['media'][$source_id];
                $provenance = get_post_meta($id, '_portfolio_media_sources', true);
                $provenance = is_array($provenance) ? $provenance : [];
                $provenance[$source_id] = ['sha256' => $asset['sha256'], 'original_path' => $asset['provenance']['original_path'] ?? null, 'asset_path' => $asset['provenance']['asset_path'] ?? null];
                portfolio_store_meta($id, '_portfolio_media_sources', $provenance);
            }
            // Global attachment alt is deliberately left untouched.
            $write('journal.json', $journal);
        }
        $technologies = [];
        foreach ($plan['operations']['technologies'] as $operation) {
            if ($operation['action'] === 'reuse') { $id = $operation['id']; }
            else {
                $id = wp_insert_post(wp_slash(['post_type' => 'technology', 'post_status' => 'publish', 'post_title' => $operation['name'], 'post_name' => $operation['slug']]), true);
                if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); }
                $created_ids[] = $id; $journal['created_posts'][] = $id;
                portfolio_store_meta($id, '_portfolio_technology_key', $operation['slug']);
                foreach ($package['technologies']['create'] as $source) {
                    if ($source['slug'] === $operation['slug']) { portfolio_store_meta($id, '_portfolio_technology_source', $source); break; }
                }
                set_post_thumbnail($id, $media['technology:' . $operation['slug']]);
                if (get_post_thumbnail_id($id) !== $media['technology:' . $operation['slug']]) { throw new RuntimeException('Cannot assign technology SVG thumbnail.'); }
            }
            $technologies[$operation['name']] = $id;
        }
        $projects = [];
        foreach ($plan['operations']['projects'] as $operation) {
            $key = $operation['project_key']; $locale = $operation['locale']; $local = $package['projects'][$key][$locale];
            $id = portfolio_import_write_project($operation, $local);
            if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); }
            if ($operation['action'] === 'create') { $created_ids[] = $id; $journal['created_posts'][] = $id; }
            $projects[$key][$locale] = $id;
            portfolio_store_meta($id, '_portfolio_project_key', $key);
            portfolio_store_meta($id, '_portfolio_locale', $locale);
            portfolio_store_meta($id, '_portfolio_public_slug', $operation['public_slug']);
        }
        foreach ($package['projects'] as $key => $source) {
            $root = $projects[$key]['pl'];
            $current_shared = get_post_meta($root, '_portfolio_shared', true);
            $order = is_array($current_shared) && array_key_exists('order', $current_shared) ? $current_shared['order'] : get_post_meta($root, 'order', true);
            if (in_array($root, $created_ids, true) && $order === '') { $order = portfolio_order_next('projects'); }
            if ($order === '') {
                $old = $package['archive_candidates'][0]['id'] ?? 0;
                $order = $key === 'polakiron' ? (int) get_post_meta($old, 'order', true) : 0;
            }
            $shared = ['project_url' => $source['project_url'], 'order' => (int) $order, 'technology_ids' => array_map(static fn($name) => $technologies[$name], $package['project_technologies'][$key]), 'gallery' => [], 'challenges' => []];
            $shared['project_type'] = portfolio_project_type($current_shared['project_type'] ?? '');
            foreach ($source['media']['gallery'] as $media_id) { $shared['gallery'][] = ['row_id' => $media_id, 'image' => $media[$media_id]]; }
            foreach ($source['pl']['challenges'] as $challenge) {
                $entry = ['row_id' => $challenge['id'], 'screenshots' => [], 'link_url' => $challenge['materials']['links'][0]['url'] ?? ''];
                foreach ($challenge['materials']['images'] as $shot) { $entry['screenshots'][] = ['row_id' => $shot['media_id'], 'image' => $media[$shot['media_id']]]; }
                $shared['challenges'][] = $entry;
            }
            foreach ($projects[$key] as $locale => $id) {
                $local_source = $source[$locale];
                $local = ['seo' => $local_source['seo'], 'outcome' => $local_source['outcome'] ?? '', 'challenges' => [], 'images' => []];
                foreach ($source['media']['gallery'] as $media_id) {
                    $asset = $package['media'][$media_id];
                    $caption = $asset['caption'];
                    $local['images']['hero/' . $media_id] = ['alt' => $asset['alt'][$locale], 'caption' => is_array($caption) ? ($caption[$locale] ?? '') : ($caption ?? '')];
                }
                foreach ($local_source['challenges'] as $challenge) {
                    $text = array_intersect_key($challenge, array_flip(['title', 'challenge', 'solution', 'effect']));
                    $text['link_label'] = $challenge['materials']['links'][0]['label'] ?? '';
                    $url = $challenge['materials']['links'][0]['url'] ?? '';
                    foreach ($shared['challenges'] as $row) { if ($row['row_id'] === $challenge['id'] && $url !== $row['link_url']) { $text['link_url'] = $url; } }
                    $local['challenges'][$challenge['id']] = $text;
                    foreach ($challenge['materials']['images'] as $shot) {
                        $asset = $package['media'][$shot['media_id']]; $caption = $asset['caption'];
                        $local['images'][$challenge['id'] . '/' . $shot['media_id']] = ['alt' => $asset['alt'][$locale], 'caption' => is_array($caption) ? ($caption[$locale] ?? '') : ($caption ?? '')];
                    }
                }
                portfolio_store_meta($id, '_portfolio_root_id', $root);
                portfolio_store_meta($id, '_portfolio_local', $local);
                portfolio_store_meta($id, '_portfolio_import_sha256', $plan['package_sha256']);
            }
            portfolio_store_meta($root, '_portfolio_shared', $shared);
            if (!get_post_thumbnail_id($root) && $shared['gallery']) { set_post_thumbnail($root, $shared['gallery'][0]['image']); }
        }
        foreach ($plan['operations']['archive'] as $operation) {
            $result = wp_update_post(['ID' => $operation['id'], 'post_status' => 'draft'], true);
            if (is_wp_error($result)) { throw new RuntimeException($result->get_error_message()); }
            portfolio_store_meta($operation['id'], '_portfolio_archived_reason', 'Replaced in the public portfolio by Polak Iron.');
        }
        update_option('portfolio_languages', array_replace(portfolio_languages(), $package['languages']), false);
        foreach (get_posts(['post_type' => 'page', 'post_status' => 'publish', 'numberposts' => -1]) as $page) {
            $lang = get_post_meta($page->ID, 'lang', true);
            if (isset($package['languages'][$lang]) && get_field('template', $page->ID) === 'home') {
                $before['posts'][$page->ID] = ['post' => get_post($page->ID, ARRAY_A), 'meta' => get_post_meta($page->ID)];
                portfolio_store_meta($page->ID, '_portfolio_locale', $lang);
            }
        }
        $write('before-state.json', $before);
        // Verify raw content and projection before committing; old editor stays enabled until live verification.
        foreach ($projects as $key => $locales) {
            foreach ($locales as $locale => $id) {
                $source = $package['projects'][$key][$locale]; $post = get_post($id); $payload = portfolio_multilingual_payload($id);
                if (isset($before['posts'][$id])) {
                    foreach (['post_author', 'post_date', 'post_date_gmt', 'post_status', 'comment_status', 'ping_status', 'post_parent', 'menu_order', 'post_name', 'guid'] as $field) {
                        if ((string) $post->$field !== (string) $before['posts'][$id]['post'][$field]) { throw new RuntimeException('Native field changed unexpectedly: ' . $id . '/' . $field); }
                    }
                }
                if ($post->post_title !== $source['post_title'] || $post->post_excerpt !== $source['post_excerpt'] || $post->post_content !== $source['post_content'] || $post->post_status !== ($before['posts'][$id]['post']['post_status'] ?? 'publish') || $payload['seo'] !== $source['seo'] || count($payload['challenges']) !== count($source['challenges'])) {
                    throw new RuntimeException('Content verification failed: ' . $key . '/' . $locale);
                }
            }
        }
        if ($wpdb->query('COMMIT') === false) { throw new RuntimeException('Commit failed.'); }
        $in_transaction = false;
        $journal['completed'] = true; $journal['projects'] = $projects; $journal['technologies'] = $technologies; $journal['media'] = $media;
        $write('journal.json', $journal);
        return $journal;
    } catch (Throwable $error) {
        if ($in_transaction) {
            $wpdb->query('ROLLBACK');
            foreach (array_merge(array_keys($before['posts']), $created_ids) as $id) { clean_post_cache($id); }
            wp_cache_delete('alloptions', 'options');
            // Files are journaled for manual recovery; never delete unrelated or concurrently created uploads.
        }
        $journal['error'] = $error->getMessage();
        $write('journal.json', $journal);
        throw $error;
    } finally {
        foreach ($project_locks as $name) { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name)); }
        remove_filter('big_image_size_threshold', $filter);
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
    }
}
