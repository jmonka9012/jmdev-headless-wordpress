<?php
/** Regression tests against the approved package and actual pre-migration REST inventory. */
require dirname(__DIR__) . '/portfolio-import-package.php';
require dirname(__DIR__) . '/portfolio-import-plan.php';
$root = dirname(__DIR__, 3);
$package = portfolio_import_read_package($root . '/backend/.local-import');
$audit = $root . '/backend/.local-audit/before-migration';
$decode = static fn($file) => json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
$meta = [];
foreach ($decode($audit . '/content-meta.json') as $item) { $meta[$item['id']] = $item['meta']; }
$inventory = ['posts' => [], 'technologies' => [], 'attachments' => $decode($audit . '/attachments.json')];
foreach (glob($audit . '/rest/posts-*.json') as $file) {
    foreach ($decode($file)['body'] as $item) {
        $inventory['posts'][] = ['id' => $item['id'], 'slug' => $item['slug'], 'status' => $item['status'], 'legacy_locale' => $meta[$item['id']]['lang'][0] ?? '', 'project_key' => '', 'locale' => ''];
    }
}
foreach (glob($audit . '/rest/technology-*.json') as $file) {
    foreach ($decode($file)['body'] as $item) {
        $inventory['technologies'][] = ['id' => $item['id'], 'slug' => $item['slug'], 'status' => $item['status'], 'title' => $item['title']['rendered']];
    }
}
function verify($valid, $message) { if (!$valid) { throw new RuntimeException($message); } }
$plan = portfolio_import_plan($package, $inventory);
verify($plan['ready'], implode('; ', $plan['errors']));
verify($plan['counts']['projects'] === ['update' => 20, 'create' => 2], 'Exactly ten existing pairs and one new pair.');
verify($plan['counts']['technologies'] === ['reuse' => 17, 'create' => 17], 'Reuse current 17; create only the approved 17.');
verify(($plan['counts']['media']['reuse'] ?? 0) === 30 && ($plan['counts']['media']['create'] ?? 0) === 35, 'Reuse 30 images, create 18 images and 17 icons.');
verify(($plan['counts']['archive']['archive'] ?? 0) === 2, 'Archive both W Pełni Zdrowia translations, without deletion.');
$by_project = [];
foreach ($plan['operations']['projects'] as $operation) { $by_project[$operation['project_key']][$operation['locale']] = $operation; }
verify($by_project['bts']['pl']['public_slug'] === 'bts-develpoment-pl', 'Preserve existing public URL including historical spelling.');
verify($by_project['bts']['en']['id'] === 292, 'Preserve translated record ID.');
verify($by_project['bekuplast']['pl']['technology_ids'] === $by_project['bekuplast']['en']['technology_ids'], 'Shared technology relation.');

$conflict = $inventory;
$conflict['technologies'][] = $inventory['technologies'][0];
verify(!portfolio_import_plan($package, $conflict)['ready'], 'Duplicate technology must block plan.');
$conflict = $inventory;
$conflict['posts'][0]['legacy_locale'] = 'de';
verify(!portfolio_import_plan($package, $conflict)['ready'], 'Changed language assignment blocks stale mapping.');
$conflict = $inventory;
$conflict['posts'][] = ['id' => 9999, 'slug' => 'polakiron', 'status' => 'draft', 'legacy_locale' => '', 'project_key' => '', 'locale' => ''];
verify(!portfolio_import_plan($package, $conflict)['ready'], 'Unmapped Polak candidate blocks duplicate creation.');
$conflict = $inventory;
$conflict['attachments'][0]['source_ids'] = [array_key_first($package['media'])];
$conflict['attachments'][0]['sha256'] = str_repeat('0', 64);
verify(!portfolio_import_plan($package, $conflict)['ready'], 'Changed source media blocks silent replacement.');

// Simulate successful apply to prove a second plan cannot create new copies.
$next = $inventory;
$next_id = 10000;
foreach ($plan['operations']['technologies'] as $op) {
    if ($op['action'] === 'create') { $next['technologies'][] = ['id' => $next_id++, 'title' => $op['name'], 'slug' => $op['slug'], 'status' => 'publish']; }
}
foreach ($plan['operations']['media'] as $op) {
    if ($op['action'] === 'create') { $next['attachments'][] = ['id' => $next_id++, 'mime' => $op['mime'], 'sha256' => $op['sha256'], 'source_ids' => [$op['source_id']]]; }
}
foreach ($plan['operations']['projects'] as $op) {
    $existing = null;
    foreach ($next['posts'] as $index => $post) { if ($post['id'] === $op['id']) { $existing = $index; break; } }
    if ($existing === null) { $next['posts'][] = ['id' => $next_id++, 'slug' => $op['slug'], 'status' => 'publish', 'legacy_locale' => $op['locale'], 'project_key' => $op['project_key'], 'locale' => $op['locale']]; }
    else { $next['posts'][$existing]['project_key'] = $op['project_key']; $next['posts'][$existing]['locale'] = $op['locale']; }
}
foreach ($next['posts'] as &$post) { if (in_array($post['id'], [225, 299], true)) { $post['status'] = 'draft'; } }
unset($post);
$repeat = portfolio_import_plan($package, $next);
verify($repeat['ready'], implode('; ', $repeat['errors']));
foreach ($repeat['counts'] as $group => $counts) { verify(empty($counts['create']), 'Rerun must not create another ' . $group); }
verify(($repeat['counts']['archive']['unchanged'] ?? 0) === 2, 'Already archived records remain unchanged.');

foreach ([
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><script>alert(1)</script></svg>',
    '<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"/>',
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><path fill="currentColor" d="M0 0" onload="bad()"/></svg>',
] as $unsafe) {
    $rejected = false;
    try { portfolio_import_validate_svg($unsafe); } catch (RuntimeException $error) { $rejected = true; }
    verify($rejected, 'Unsafe SVG rejected.');
}
echo "Import planning, conflict, repeatability and SVG contracts passed.\n";
echo json_encode($plan['counts'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
