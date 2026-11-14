<?php
/** Real data/API assertions, called after import. Never invents a passing browser test. */
function portfolio_import_verify($package, $journal, $output) {
    $errors = [];
    $check = static function ($valid, $message) use (&$errors) { if (!$valid) { $errors[] = $message; } };
    $server = rest_get_server();
    foreach ($journal['projects'] as $key => $locales) {
        foreach ($locales as $locale => $id) {
            $post = get_post($id); $source = $package['projects'][$key][$locale]; $payload = portfolio_multilingual_payload($id);
            foreach (['post_title', 'post_excerpt', 'post_content'] as $field) { $check($post->$field === $source[$field], "$key/$locale: $field"); }
            $check($payload['seo'] === $source['seo'], "$key/$locale: SEO");
            $check($payload['outcome'] === ($source['outcome'] ?? ''), "$key/$locale: outcome");
            $check($payload['project_url'] === $package['projects'][$key]['project_url'], "$key/$locale: project_url");
            $expected_techs = array_map(static fn($name) => $journal['technologies'][$name], $package['project_technologies'][$key]);
            $check($payload['technology_ids'] === $expected_techs, "$key/$locale: technology relation");
            $check(count($payload['gallery']) === count($package['projects'][$key]['media']['gallery']), "$key/$locale: Hero count");
            foreach ($package['projects'][$key]['media']['gallery'] as $index => $media_id) {
                $image = $payload['gallery'][$index]['image'] ?? [];
                $check(($image['id'] ?? null) === $journal['media'][$media_id] && ($image['alt'] ?? null) === $package['media'][$media_id]['alt'][$locale], "$key/$locale: Hero image/order/alt $index");
            }
            foreach ($source['challenges'] as $index => $challenge) {
                $actual = $payload['challenges'][$index] ?? [];
                foreach (['title', 'challenge', 'effect'] as $field) { $check(($actual[$field] ?? null) === $challenge[$field], "$key/$locale: Challenge $index/$field"); }
                $check(($actual['solution'] ?? '') === wp_kses_post(wpautop($challenge['solution'])), "$key/$locale: solution");
                $check(($actual['id'] ?? '') === $challenge['id'], "$key/$locale: stable Challenge ID");
                $check(count($actual['screenshots'] ?? []) === count($challenge['materials']['images']), "$key/$locale: screenshot count");
                foreach ($challenge['materials']['images'] as $position => $shot) {
                    $image = $actual['screenshots'][$position]['image'] ?? []; $media_id = $shot['media_id'];
                    $check(($image['id'] ?? null) === $journal['media'][$media_id] && ($image['alt'] ?? null) === $package['media'][$media_id]['alt'][$locale], "$key/$locale: Challenge image/order/alt $index/$position");
                }
                $check(($actual['link_url'] ?? '') === ($challenge['materials']['links'][0]['url'] ?? ''), "$key/$locale: material URL");
                $check(($actual['link_label'] ?? '') === ($challenge['materials']['links'][0]['label'] ?? ''), "$key/$locale: material label");
            }
            $request = new WP_REST_Request('GET', '/wp/v2/posts/' . $id);
            $request->set_query_params(['acf_format' => 'standard', '_embed' => true]);
            $response = $server->dispatch($request); $body = $response->get_data();
            $check($response->get_status() === 200 && ($body['acf']['lang'] ?? '') === $locale && ($body['acf']['link'] ?? '') === $payload['project_url'] && ($body['portfolio']['technology_ids'] ?? []) === $expected_techs, "$key/$locale: REST adapter");
            $check(isset($payload['translations']['pl'], $payload['translations']['en']), "$key/$locale: published translation map");
            if (isset($package['existing_project_pairs'][$key][$locale])) {
                $old = $package['existing_project_pairs'][$key][$locale];
                $check($id === $old['id'] && $post->post_name === $old['slug'], "$key/$locale: original ID/slug retained");
            }
        }
    }
    foreach ($package['archive_candidates'] as $candidate) { $check(get_post_status($candidate['id']) === 'draft', 'Archive status: ' . $candidate['id']); }
    foreach ($package['technologies']['create'] as $technology) {
        $id = $journal['technologies'][$technology['name']];
        // Existing records are reused; new icons have exact media reference and remain SVG.
        $thumbnail = get_post_thumbnail_id($id);
        $check($thumbnail && get_post_mime_type($thumbnail) === 'image/svg+xml', 'Technology SVG: ' . $technology['name']);
    }
    $before = json_decode(file_get_contents($output . '/before-state.json'), true);
    foreach ($before['posts'] as $id => $record) {
        if (($record['post']['post_type'] ?? '') === 'attachment') {
            $old_alt = $record['meta']['_wp_attachment_image_alt'][0] ?? '';
            $check(get_post_meta($id, '_wp_attachment_image_alt', true) === $old_alt, 'Global attachment alt preserved: ' . $id);
        }
    }
    [$current_inventory] = portfolio_import_inventory();
    $repeat_plan = portfolio_import_plan($package, $current_inventory);
    $check($repeat_plan['ready'], 'Post-import repeat plan must be conflict-free.');
    foreach ($repeat_plan['counts'] ?? [] as $group => $counts) { $check(empty($counts['create']), 'Repeat import would create new ' . $group); }
    file_put_contents($output . '/repeat-plan.json', wp_json_encode($repeat_plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $report = ['errors' => $errors, 'checks' => ['raw_text', 'SEO', 'technology_relations', 'gallery_order_and_localized_alt', 'challenge_images_and_links', 'original_ids_and_slugs', 'REST_adapter', 'archived_projects', 'SVG_thumbnails', 'global_alts_preserved'], 'not_tested' => ['browser_editor_interactions', 'desktop_mobile_visuals', 'restore_in_separate_database']];
    if (!is_dir($output . '/rest')) { wp_mkdir_p($output . '/rest'); }
    foreach (['posts', 'pages', 'technology'] as $type) {
        $page = 1;
        do {
            $request = new WP_REST_Request('GET', '/wp/v2/' . $type);
            $request->set_query_params(['per_page' => 100, 'page' => $page, 'acf_format' => 'standard', '_embed' => true]);
            $response = $server->dispatch($request); $headers = $response->get_headers();
            $snapshot = ['status' => $response->get_status(), 'headers' => $headers, 'body' => $server->response_to_data($response, true)];
            file_put_contents($output . '/rest/' . $type . '-' . $page . '.json', wp_json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $page++;
        } while ($response->get_status() === 200 && $page <= (int) ($headers['X-WP-TotalPages'] ?? 1));
    }
    file_put_contents($output . '/languages.json', wp_json_encode(portfolio_languages(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    file_put_contents($output . '/verification.json', wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    return $report;
}
