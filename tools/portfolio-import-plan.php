<?php
/** Pure planning functions. No WordPress hooks, filesystem or database writes. */
function portfolio_import_name(string $name): string {
    return strtolower(trim(html_entity_decode(strip_tags($name), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
}

function portfolio_import_plan(array $package, array $inventory): array {
    $errors = [];
    $warnings = [];
    $operations = ['technologies' => [], 'media' => [], 'projects' => [], 'archive' => []];
    $technology_refs = [];
    $media_refs = [];
    if (($package['schema_version'] ?? null) !== 1 || count($package['projects'] ?? []) !== 11) {
        return ['errors' => ['Unsupported package schema/project count.'], 'operations' => $operations];
    }
    $aliases = $package['technologies']['aliases'];
    $normalize = static fn($name) => portfolio_import_name($aliases[$name] ?? $name);
    $existing_techs = [];
    foreach ($inventory['technologies'] as $technology) {
        $existing_techs[$normalize($technology['title'])][] = $technology;
    }
    $new_techs = [];
    foreach ($package['technologies']['create'] as $technology) {
        $key = $normalize($technology['name']);
        if (isset($new_techs[$key])) { $errors[] = 'Duplicate technology declaration: ' . $technology['name']; }
        $new_techs[$key] = $technology;
    }
    $needed = array_values(array_unique(array_merge($package['technologies']['existing'], array_column($package['technologies']['create'], 'name'))));
    foreach ($needed as $name) {
        $key = $normalize($name);
        $matches = $existing_techs[$key] ?? [];
        if (count($matches) > 1) { $errors[] = 'Ambiguous technology: ' . $name; continue; }
        if ($matches) {
            if ($matches[0]['status'] !== 'publish') { $errors[] = 'Existing technology is not published: ' . $name; continue; }
            $technology_refs[$name] = $matches[0]['id'];
            $operations['technologies'][] = ['action' => 'reuse', 'name' => $name, 'id' => $matches[0]['id']];
        } elseif (isset($new_techs[$key])) {
            $source = $new_techs[$key];
            $collision = array_filter($inventory['technologies'], static fn($item) => $item['slug'] === $source['slug']);
            if ($collision) { $errors[] = 'Technology slug collision: ' . $source['slug']; continue; }
            $technology_refs[$name] = 'technology:' . $source['slug'];
            $operations['technologies'][] = ['action' => 'create', 'name' => $name, 'slug' => $source['slug'], 'ref' => $technology_refs[$name]];
        } else { $errors[] = 'Missing existing technology: ' . $name; }
    }

    // Hashes cover the attachment file AND its WordPress original, when available.
    $by_hash = [];
    $by_source = [];
    foreach ($inventory['attachments'] as $attachment) {
        foreach (array_unique(array_filter([$attachment['sha256'] ?? null, $attachment['original_sha256'] ?? null])) as $hash) {
            $by_hash[$hash][] = $attachment;
        }
        foreach ($attachment['source_ids'] ?? [] as $source_id) { $by_source[$source_id][] = $attachment; }
    }
    $assets = [];
    foreach ($package['media'] as $id => $asset) { $assets[$id] = $asset; }
    foreach ($new_techs as $key => $technology) {
        // Existing technologies keep their current images unchanged.
        if (!empty($existing_techs[$key])) { continue; }
        $id = 'technology:' . $technology['slug'];
        if (!isset($package['icons'][$technology['slug']])) { $errors[] = 'Missing icon: ' . $id; continue; }
        $assets[$id] = $package['icons'][$technology['slug']];
    }
    $planned_hashes = [];
    foreach ($assets as $id => $asset) {
        $hash = $asset['sha256'];
        $identified = $by_source[$id] ?? [];
        if (count($identified) > 1) { $errors[] = 'Duplicate source media ID: ' . $id; continue; }
        if ($identified && !in_array($hash, [$identified[0]['sha256'] ?? null, $identified[0]['original_sha256'] ?? null], true)) {
            $errors[] = 'Previously imported source has different/missing content: ' . $id; continue;
        }
        $matches = $identified ?: ($by_hash[$hash] ?? []);
        usort($matches, static fn($a, $b) => $a['id'] <=> $b['id']);
        if ($matches) {
            if ($matches[0]['mime'] !== $asset['mime']) { $errors[] = 'Media MIME mismatch: ' . $id; continue; }
            $media_refs[$id] = $matches[0]['id'];
            $operations['media'][] = ['action' => 'reuse', 'source_id' => $id, 'id' => $matches[0]['id'], 'sha256' => $hash];
            if (count($matches) > 1) { $warnings[] = 'Identical attachment candidates for ' . $id . '; using lowest ID ' . $matches[0]['id']; }
        } elseif (isset($planned_hashes[$hash])) {
            $media_refs[$id] = $planned_hashes[$hash];
            $operations['media'][] = ['action' => 'reuse-planned', 'source_id' => $id, 'ref' => $planned_hashes[$hash], 'sha256' => $hash];
        } else {
            $ref = 'media:' . $hash;
            $planned_hashes[$hash] = $ref;
            $media_refs[$id] = $ref;
            $operations['media'][] = ['action' => 'create', 'source_id' => $id, 'ref' => $ref, 'sha256' => $hash, 'file' => $asset['file'], 'mime' => $asset['mime']];
        }
    }

    $post_ids = [];
    $used_ids = [];
    foreach ($inventory['posts'] as $post) { $post_ids[$post['id']] = $post; }
    foreach ($package['projects'] as $key => $source) {
        foreach ($package['languages'] as $locale => $label) {
            $managed = array_values(array_filter($inventory['posts'], static fn($post) => ($post['project_key'] ?? '') === $key && ($post['locale'] ?? '') === $locale));
            if (count($managed) > 1) { $errors[] = "Duplicate managed project: $key/$locale"; continue; }
            $legacy = $package['existing_project_pairs'][$key][$locale] ?? null;
            $existing = $managed[0] ?? ($legacy ? ($post_ids[$legacy['id']] ?? null) : null);
            if ($legacy && !$managed && (!$existing || $existing['slug'] !== $legacy['slug'] || $existing['legacy_locale'] !== $locale)) {
                $errors[] = "Legacy project changed or missing: $key/$locale"; continue;
            }
            if ($existing) {
                if (($existing['project_key'] ?? '') !== '' && ($existing['project_key'] !== $key || $existing['locale'] !== $locale)) {
                    $errors[] = "Record already assigned elsewhere: $key/$locale"; continue;
                }
                if ($managed && $legacy && $existing['id'] !== $legacy['id']) { $errors[] = "Managed ID differs from approved legacy ID: $key/$locale"; continue; }
                if (in_array($existing['id'], $used_ids, true)) { $errors[] = 'Record assigned twice: ' . $existing['id']; continue; }
                $used_ids[] = $existing['id'];
                $action = 'update';
                $id = $existing['id'];
                $slug = $existing['slug'];
            } else {
                if ($key !== 'polakiron') { $errors[] = "Missing migration mapping: $key/$locale"; continue; }
                $action = 'create';
                $id = 'project:' . $key . ':' . $locale;
                $slug = $key . ($locale === 'pl' ? '' : '-2');
                if (array_filter($inventory['posts'], static fn($post) => $post['slug'] === $slug || str_contains($post['slug'], 'polak'))) {
                    // The other managed locale of the same project is expected on rerun.
                    $unmanaged = array_filter($inventory['posts'], static fn($post) => ($post['slug'] === $slug || str_contains($post['slug'], 'polak')) && ($post['project_key'] ?? '') !== $key);
                    if ($unmanaged) { $errors[] = "Unmapped existing Polak Iron candidate: $key/$locale"; continue; }
                }
            }
            $refs = [];
            foreach ($package['project_technologies'][$key] as $technology) {
                if (!isset($technology_refs[$technology])) { $errors[] = "$key: unresolved technology $technology"; }
                else { $refs[] = $technology_refs[$technology]; }
            }
            $post_ids[$id] = $existing;
            $operations['projects'][] = [
                'action' => $action, 'project_key' => $key, 'locale' => $locale,
                'id' => $id, 'slug' => $slug,
                'public_slug' => preg_replace('/-2$/', '', $slug),
                'before_fingerprint' => $existing['fingerprint'] ?? null,
                'title' => $source[$locale]['post_title'],
                'technology_ids' => array_values(array_unique($refs, SORT_REGULAR)),
                'hero_media' => $source['media']['gallery'],
                'challenge_ids' => array_column($source[$locale]['challenges'], 'id'),
                'seo' => $source[$locale]['seo'],
            ];
        }
    }
    foreach ($package['archive_candidates'] as $candidate) {
        $post = $post_ids[$candidate['id']] ?? null;
        if (!$post || $post['slug'] !== $candidate['slug'] || !in_array($candidate['slug'], ['wpelnizdrowia-pl', 'wpelnizdrowia-pl-2'], true)) {
            $errors[] = 'Archive candidate changed/missing: ' . $candidate['id']; continue;
        }
        $operations['archive'][] = ['action' => ($post['status'] === 'draft' ? 'unchanged' : 'archive'), 'id' => $post['id'], 'slug' => $post['slug'], 'from_status' => $post['status'], 'to_status' => 'draft', 'delete' => false];
    }
    $counts = [];
    foreach ($operations as $group => $items) {
        $counts[$group] = array_count_values(array_column($items, 'action'));
    }
    return [
        'schema_version' => 1, 'mode' => 'dry-run', 'ready' => !$errors,
        'errors' => array_values(array_unique($errors)), 'warnings' => array_values(array_unique($warnings)),
        'counts' => $counts, 'operations' => $operations, 'media_refs' => $media_refs,
        'notes' => ['No data changed.', 'Project ID and language are explicit; language categories are not used for matching.', 'Localized alt/captions belong to image usage, not global attachment metadata.', 'Update means planned content synchronization; no write is performed by this tool.'],
    ];
}
