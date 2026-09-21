<?php
/**
 * Parse check for every PHP file in the app (no extensions needed under wasm).
 *
 *   node tools/php-cli.mjs tools/lint.php
 */

chdir(dirname(__DIR__));

$roots = ['platform', 'admin', 'includes', 'tests', 'tools', 'auth', 'ajax', 'orders'];
$bad   = 0;
$n     = 0;

$walk = function (string $dir) use (&$walk, &$bad, &$n): void {
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        if (is_dir($path)) {
            $walk($path);
            continue;
        }
        if (!str_ends_with($entry, '.php')) {
            continue;
        }
        $n++;
        try {
            token_get_all(file_get_contents($path), TOKEN_PARSE);
        } catch (ParseError $e) {
            $bad++;
            printf("PARSE ERROR %s:%d %s\n", $path, $e->getLine(), $e->getMessage());
        }
    }
};

foreach ($roots as $root) {
    if (is_dir($root)) {
        $walk($root);
    }
}

foreach (glob('*.php') ?: [] as $file) {
    $n++;
    try {
        token_get_all(file_get_contents($file), TOKEN_PARSE);
    } catch (ParseError $e) {
        $bad++;
        printf("PARSE ERROR %s:%d %s\n", $file, $e->getLine(), $e->getMessage());
    }
}

printf("linted %d files, %d parse errors\n", $n, $bad);
exit($bad > 0 ? 1 : 0);
