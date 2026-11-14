<?php
require dirname(__DIR__) . '/runtime-contracts.php';
set_error_handler(static function ($severity, $message) { throw new RuntimeException($message); });
function check($valid, $message) { if (!$valid) { throw new RuntimeException($message); } }
$languages = ['pl' => 'Polish', 'en' => 'English'];
$categories = ['cms' => ['pl' => 'Localized CMS', 'en' => 'CMS']];
$record = ['id' => 1, 'slug' => 'example', 'title' => ['rendered' => 'Example'], 'acf' => ['lang' => 'pl']];
$project = $record + ['portfolio' => [
    'schema_version' => 2, 'project_type' => 'commercial', 'gallery' => [], 'challenges' => [],
    'technology_ids' => [71], 'featured_image' => null, 'outcome' => '', 'seo' => [], 'order' => 10,
    'locale' => 'pl', 'public_slug' => 'example', 'project_key' => 'example', 'translations' => ['en' => '/en/project/example'],
]];
check(portfolio_check_record('posts', $project, $languages, $categories) === [], 'Valid managed projects pass without production content.');
$legacy = $project; $legacy['portfolio'] = array_intersect_key($legacy['portfolio'], array_flip(['schema_version', 'project_type', 'gallery', 'challenges', 'technology_ids', 'featured_image', 'outcome']));
$legacy['portfolio']['schema_version'] = 1; $legacy['portfolio']['project_type'] = '';
check(portfolio_check_record('posts', $legacy, $languages, $categories) === [], 'Legacy projects do not require managed identity or a migrated type.');
$image = ['url' => 'https://example.test/image.png', 'alt' => ''];
check(portfolio_check_image($image), 'Legacy URL-only images remain supported.');
foreach ([null, [], $image + ['width' => -1], ['url' => 'javascript:alert(1)', 'alt' => '']] as $invalid) {
    check(!portfolio_check_image($invalid), 'Invalid image structures and URLs are rejected.');
}
foreach ([
    ['schema_version' => 3], ['project_type' => ''], ['gallery' => 'invalid'],
    ['technology_ids' => [['invalid']]], ['challenges' => [null]], ['locale' => 'en'],
    ['translations' => ['en' => '//example.test']], ['public_slug' => ''],
] as $override) {
    $invalid = $project; $invalid['portfolio'] = array_replace($project['portfolio'], $override);
    check(portfolio_check_record('posts', $invalid, $languages, $categories) !== [], 'Broken managed response contracts fail.');
}
foreach ([null, [], $record + ['portfolio' => []], array_replace($record, ['acf' => 'invalid'])] as $invalid) {
    check(portfolio_check_record('posts', $invalid, $languages, $categories) !== [], 'Malformed responses fail without PHP warnings.');
}
$technology = $record + ['portfolio_category' => null];
check(portfolio_check_record('technology', $technology, $languages, $categories) === [], 'Unassigned technologies are valid.');
$technology['portfolio_category'] = ['key' => 'cms', 'labels' => $categories['cms'], 'order' => 0];
check(portfolio_check_record('technology', $technology, $languages, $categories) === [], 'Configured category labels and rank pass.');
$technology['portfolio_category']['labels']['pl'] = 'Changed';
check(portfolio_check_record('technology', $technology, $languages, $categories) !== [], 'Public labels must match CMS configuration.');
$technology['portfolio_category'] = ['key' => []];
check(portfolio_check_record('technology', $technology, $languages, $categories) !== [], 'Malformed category keys fail without PHP warnings.');
check(portfolio_check_record('technology', $record, $languages, $categories) !== [], 'Missing category registration is distinct from an unassigned category.');

$make_page = static fn($id) => array_replace($record, ['id' => $id]);
$pages = [array_map($make_page, range(1, 100)), [$make_page(101)]];
$calls = [];
$fetch = static function ($route, $query) use (&$pages, &$calls) {
    $calls[] = [$route, $query];
    return ['status' => 200, 'headers' => ['X-WP-TotalPages' => 2, 'X-WP-Total' => 101], 'data' => $pages[$query['page'] - 1]];
};
$result = portfolio_check_collection($fetch, 'pages', $languages, $categories);
check($result === ['count' => 101, 'errors' => []] && count($calls) === 2, 'Every page is checked, including collections larger than 100 records.');
check($calls[1][1]['context'] === 'view' && $calls[1][1]['acf_format'] === 'standard' && $calls[1][1]['_embed'] === true, 'Requests match the public frontend export context.');
$pages[1] = [$make_page(1)];
check(portfolio_check_collection($fetch, 'pages', $languages, $categories)['errors'] !== [], 'Duplicate records across pages fail.');
$pages[1] = [];
check(portfolio_check_collection($fetch, 'pages', $languages, $categories)['errors'] !== [], 'Truncated responses fail rather than silently skipping records.');
foreach ([
    ['status' => 503, 'headers' => [], 'data' => []],
    ['status' => 200, 'headers' => [], 'data' => []],
    ['status' => 200, 'headers' => ['X-WP-TotalPages' => 0, 'X-WP-Total' => 0], 'data' => 'invalid'],
    ['status' => 200, 'headers' => ['X-WP-TotalPages' => 1, 'X-WP-Total' => 101], 'data' => []],
] as $response) {
    check(portfolio_check_collection(static fn() => $response, 'pages', $languages, $categories)['errors'] !== [], 'HTTP, header, collection and pagination failures are reported.');
}
$empty = static fn() => ['status' => 200, 'headers' => ['X-WP-TotalPages' => 0, 'X-WP-Total' => 0], 'data' => []];
check(portfolio_check_collection($empty, 'pages', $languages, $categories) === ['count' => 0, 'errors' => []], 'Empty installations are valid and report zero checked records.');
echo "Runtime response, fallback and pagination contracts passed.\n";
