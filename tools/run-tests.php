<?php
/** Run isolated MU-plugin contracts without WordPress or a database. */
$tests = glob(dirname(__DIR__) . '/wordpress/wp-content/mu-plugins/portfolio-projects/tests/*.php');
if (!$tests) { fwrite(STDERR, "No contract tests found.\n"); exit(1); }
$failed = 0;
foreach ($tests as $test) {
    echo PHP_EOL . basename($test) . PHP_EOL;
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($test), $status);
    if ($status !== 0) { $failed++; }
}
echo PHP_EOL . count($tests) . ' test files; failures: ' . $failed . PHP_EOL;
exit($failed ? 1 : 0);
