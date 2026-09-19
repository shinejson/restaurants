<?php
/**
 * Minimal dependency-free test harness.
 *
 *   node tools/php-cli.mjs tests/run.php            # all suites
 *   node tools/php-cli.mjs tests/run.php database   # one suite
 */

require_once __DIR__ . '/../platform/bootstrap.php';

final class TestRunner
{
    public array $results = [];
    public int $assertions = 0;
    private string $suite = '';

    public function suite(string $name, callable $fn): void
    {
        $this->suite = $name;
        $this->section($name);
        try {
            $fn($this);
        } catch (\Throwable $e) {
            $this->fail('suite threw ' . get_class($e), $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        }
    }

    public function section(string $name): void
    {
        echo "\n\033[1m{$name}\033[0m\n";
    }

    public function ok(bool $condition, string $description, string $detail = ''): void
    {
        $this->assertions++;
        if ($condition) {
            echo "  \033[32m✓\033[0m {$description}\n";
            $this->results[] = true;
        } else {
            echo "  \033[31m✗\033[0m {$description}" . ($detail !== '' ? "  \033[90m{$detail}\033[0m" : '') . "\n";
            $this->results[] = false;
        }
    }

    public function same(mixed $expected, mixed $actual, string $description): void
    {
        $this->ok(
            $expected === $actual,
            $description,
            'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }

    public function fail(string $description, string $detail = ''): void
    {
        $this->ok(false, $description, $detail);
    }

    public function summary(): int
    {
        $failed = count(array_filter($this->results, static fn ($r) => !$r));
        $total  = count($this->results);
        echo "\n" . str_repeat('─', 60) . "\n";
        if ($failed === 0) {
            echo "\033[32m{$total} assertions passed\033[0m\n";
        } else {
            echo "\033[31m{$failed} of {$total} assertions FAILED\033[0m\n";
        }
        return $failed === 0 ? 0 : 1;
    }
}

$t     = new TestRunner();
$only  = $argv[1] ?? null;
$tests = glob(__DIR__ . '/suites/*.php') ?: [];

foreach ($tests as $file) {
    $name = basename($file, '.php');
    if ($only !== null && $only !== $name) {
        continue;
    }
    (require $file)($t);
}

exit($t->summary());
