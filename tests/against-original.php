<?php

/**
 * Runs the regression group against src/ as first committed and checks that
 * every test in it fails there. A regression test that passes against the
 * code it was written for is testing nothing.
 *
 * Needs git and the `main` branch. Run from the project root:
 *
 *   php tests/against-original.php
 */

$root = dirname(__DIR__);
$ref = $argv[1] ?? 'main';

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fras-original-' . getmypid();
@mkdir($tmp);

foreach (['FRAS.php', 'LCS.php', 'Levenshtein.php'] as $file) {
    $cmd = sprintf('git -C %s show %s', escapeshellarg($root), escapeshellarg("{$ref}:src/{$file}"));
    $code = shell_exec($cmd);
    if (!is_string($code) || $code === '') {
        fwrite(STDERR, "could not read {$ref}:src/{$file} with git\n");
        exit(2);
    }
    file_put_contents($tmp . DIRECTORY_SEPARATOR . $file, $code);
}

// If mbstring was enabled on this process's command line rather than in
// php.ini, a plain child PHP will not have it. Ask one, and pass it on.
$php = escapeshellarg(PHP_BINARY);
$childHasMbstring = trim((string) shell_exec($php . ' -r "echo extension_loaded(\'mbstring\') ? 1 : 0;"')) === '1';
if (!$childHasMbstring && extension_loaded('mbstring')) {
    $php .= ' -d ' . escapeshellarg('extension_dir=' . ini_get('extension_dir')) . ' -d extension=mbstring';
}

$junit = $tmp . DIRECTORY_SEPARATOR . 'junit.xml';
putenv('FRAS_SRC_DIR=' . $tmp);
$cmd = sprintf(
    '%s %s -c %s --group regression --log-junit %s',
    $php,
    escapeshellarg($root . '/vendor/phpunit/phpunit/phpunit'),
    escapeshellarg($root . '/phpunit.xml'),
    escapeshellarg($junit)
);
exec($cmd . ' 2>&1', $output);

$xml = @simplexml_load_file($junit);
foreach (glob($tmp . DIRECTORY_SEPARATOR . '*') as $f) {
    unlink($f);
}
rmdir($tmp);

if ($xml === false) {
    fwrite(STDERR, "PHPUnit produced no report:\n" . implode("\n", $output) . "\n");
    exit(2);
}

$passed = [];
$total = 0;
foreach ($xml->xpath('//testcase') as $case) {
    $total++;
    if (!isset($case->failure) && !isset($case->error)) {
        $passed[] = (string) $case['name'];
    }
}

if ($total === 0) {
    fwrite(STDERR, "the regression group is empty\n");
    exit(2);
}

foreach ($passed as $name) {
    echo "PASSES AGAINST {$ref}: {$name}\n";
}
printf("%d of %d regression tests fail against %s\n", $total - count($passed), $total, $ref);
exit($passed === [] ? 0 : 1);
