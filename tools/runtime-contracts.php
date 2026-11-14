<?php
/** Validate public response shapes without WordPress or content writes. */
function portfolio_check_image($image) {
    if (!is_array($image) || !is_string($image['url'] ?? null) || !is_string($image['alt'] ?? null)) { return false; }
    $url = parse_url($image['url']);
    if (!$url || !in_array($url['scheme'] ?? '', ['http', 'https'], true) || empty($url['host'])) { return false; }
    foreach (['id', 'width', 'height'] as $key) {
        if (isset($image[$key]) && (!is_int($image[$key]) || $image[$key] < 1)) { return false; }
    }
    return true;
}

function portfolio_check_image_rows($rows) {
    if (!is_array($rows) || !array_is_list($rows)) { return false; }
    foreach ($rows as $row) {
        if (!is_array($row) || !portfolio_check_image($row['image'] ?? null) || !is_string($row['caption'] ?? null)) { return false; }
    }
    return true;
}

function portfolio_check_record($kind, $record, $languages, $categories) {
    if (!is_array($record)) { return ['Expected a record object.']; }
    $errors = [];
    if (!is_int($record['id'] ?? null) || $record['id'] < 1) { $errors[] = 'Missing positive integer id.'; }
    if (!is_string($record['slug'] ?? null) || $record['slug'] === '') { $errors[] = 'Missing slug.'; }
    if (!is_string($record['title']['rendered'] ?? null)) { $errors[] = 'Missing title.rendered.'; }
    if (!is_array($record['acf'] ?? null)) { $errors[] = 'Missing ACF response object.'; }
    $locale = $record['acf']['lang'] ?? null;
    if ($locale !== null && $locale !== '' && (!is_string($locale) || !array_key_exists($locale, $languages))) { $errors[] = 'Unknown acf.lang locale.'; }
    if ($kind === 'technology') {
        if (!array_key_exists('portfolio_category', $record)) { $errors[] = 'Missing portfolio_category field.'; }
        elseif ($record['portfolio_category'] !== null) {
            $category = $record['portfolio_category'];
            $key = is_array($category) ? ($category['key'] ?? null) : null;
            if (!is_string($key) || !isset($categories[$key])) { $errors[] = 'Unknown portfolio_category key.'; }
            elseif (($category['labels'] ?? null) !== $categories[$key] || ($category['order'] ?? null) !== array_search($key, array_keys($categories), true)) { $errors[] = 'Category labels/order differ from configuration.'; }
        }
    }
    if ($kind !== 'posts') { return $errors; }
    $data = $record['portfolio'] ?? null;
    if (!is_array($data) || !in_array($data['schema_version'] ?? null, [1, 2], true)) { return [...$errors, 'Missing or unsupported portfolio.schema_version.']; }
    foreach (['gallery', 'challenges', 'technology_ids'] as $key) {
        if (!is_array($data[$key] ?? null) || !array_is_list($data[$key])) { $errors[] = 'Expected portfolio.' . $key . ' list.'; }
    }
    if (!portfolio_check_image_rows($data['gallery'] ?? null)) { $errors[] = 'Invalid portfolio.gallery image rows.'; }
    if (!is_string($data['outcome'] ?? null)) { $errors[] = 'Expected portfolio.outcome text.'; }
    if (!array_key_exists('featured_image', $data) || ($data['featured_image'] !== null && !portfolio_check_image($data['featured_image']))) { $errors[] = 'Invalid portfolio.featured_image.'; }
    foreach (is_array($data['technology_ids'] ?? null) ? $data['technology_ids'] : [] as $id) {
        if (!is_int($id) || $id < 1) { $errors[] = 'Expected positive integer portfolio.technology_ids.'; break; }
    }
    foreach (is_array($data['challenges'] ?? null) ? $data['challenges'] : [] as $index => $challenge) {
        if (!is_array($challenge)) { $errors[] = 'Invalid challenge at index ' . $index . '.'; continue; }
        foreach (['title', 'challenge', 'solution', 'effect', 'link_url', 'link_label'] as $key) {
            if (!is_string($challenge[$key] ?? null)) { $errors[] = 'Expected challenge ' . $index . '.' . $key . ' text.'; }
        }
        if (!portfolio_check_image_rows($challenge['screenshots'] ?? null)) { $errors[] = 'Invalid challenge ' . $index . ' screenshots.'; }
    }
    $allowed_types = $data['schema_version'] === 2 ? ['commercial', 'personal'] : ['', 'commercial', 'personal'];
    if (!in_array($data['project_type'] ?? null, $allowed_types, true)) { $errors[] = 'Invalid portfolio.project_type.'; }
    if ($data['schema_version'] === 2) {
        foreach (['locale', 'project_key', 'public_slug'] as $key) {
            if (!is_string($data[$key] ?? null) || $data[$key] === '') { $errors[] = 'Missing portfolio.' . $key . '.'; }
        }
        if (!is_string($data['locale'] ?? null) || !isset($languages[$data['locale']]) || $locale !== $data['locale']) { $errors[] = 'Managed project locale differs from language configuration or acf.lang.'; }
        if (!is_numeric($data['order'] ?? null) || !is_array($data['seo'] ?? null)) { $errors[] = 'Missing managed project order/SEO data.'; }
        if (!is_array($data['translations'] ?? null)) { $errors[] = 'Expected portfolio.translations object.'; }
        else {
            foreach ($data['translations'] as $language => $path) {
                if (!isset($languages[$language]) || !is_string($path) || !str_starts_with($path, '/') || str_starts_with($path, '//')) { $errors[] = 'Invalid public translation path.'; }
            }
        }
    }
    return $errors;
}

function portfolio_check_collection($fetch, $kind, $languages, $categories) {
    $errors = []; $count = 0; $pages = 1; $total = null; $seen = [];
    for ($page = 1; $page <= $pages; $page++) {
        $response = $fetch('/wp/v2/' . $kind, ['per_page' => 100, 'page' => $page, 'context' => 'view', 'acf_format' => 'standard', '_embed' => true]);
        if ($response['status'] !== 200) { return ['count' => $count, 'errors' => [$kind . ' page ' . $page . ': HTTP ' . $response['status'] . '. Check REST registration and public permissions.']]; }
        $headers = array_change_key_case($response['headers'], CASE_LOWER);
        if (!ctype_digit((string) ($headers['x-wp-totalpages'] ?? '')) || !ctype_digit((string) ($headers['x-wp-total'] ?? ''))) { return ['count' => $count, 'errors' => [$kind . ': missing pagination headers.']]; }
        $next_total = (int) $headers['x-wp-total']; $next_pages = (int) $headers['x-wp-totalpages'];
        if (($total !== null && ($total !== $next_total || $pages !== max(1, $next_pages))) || $next_pages !== (int) ceil($next_total / 100)) { return ['count' => $count, 'errors' => [$kind . ': inconsistent pagination; retry if content changed during the check.']]; }
        $total = $next_total; $pages = max(1, $next_pages);
        if (!is_array($response['data']) || !array_is_list($response['data'])) { return ['count' => $count, 'errors' => [$kind . ': expected a collection list.']]; }
        foreach ($response['data'] as $record) {
            $id = is_array($record) && is_int($record['id'] ?? null) ? $record['id'] : '?';
            foreach (portfolio_check_record($kind, $record, $languages, $categories) as $error) { $errors[] = $kind . ' #' . $id . ': ' . $error; }
            if ($id !== '?' && isset($seen[$id])) { $errors[] = $kind . ' #' . $id . ': duplicate record across pages.'; }
            $seen[$id] = true; $count++;
        }
    }
    if ($count !== $total) { $errors[] = $kind . ': returned record count differs from pagination total.'; }
    return ['count' => $count, 'errors' => $errors];
}
