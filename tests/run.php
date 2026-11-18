<?php
/**
 * Minimal test runner (no dependencies).   php tests/run.php
 * Database tests run when DB_HOST/DB_NAME/DB_USER/DB_PASS are set (see dev/compose.yml), otherwise skipped.
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
require __DIR__ . '/../src/bootstrap.php';

final class T
{
    public static $tests = [];
    public static $failures = 0;
    public static $passed = 0;
    public static $skipped = 0;
}

class SkipTest extends Exception {}

function test(string $name, callable $fn): void { T::$tests[] = [$name, $fn]; }
function skip(string $why): void { throw new SkipTest($why); }
function ok($cond, string $msg = 'expected true'): void { if (!$cond) throw new Exception($msg); }
function eq($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new Exception(($msg ? "$msg: " : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}
function contains(string $needle, string $haystack, string $msg = ''): void
{
    if (strpos($haystack, $needle) === false) {
        throw new Exception(($msg ? "$msg: " : '') . "'$needle' not found in: " . substr($haystack, 0, 300));
    }
}
function throws(callable $fn, string $class = Throwable::class): void
{
    try { $fn(); } catch (Throwable $e) { if ($e instanceof $class) return; throw new Exception('wrong exception ' . get_class($e) . ': ' . $e->getMessage()); }
    throw new Exception("expected $class");
}

/** PDO to the test database or null. */
function testPdo(): ?PDO
{
    static $pdo = false;
    if ($pdo === false) {
        $pdo = null;
        if (getenv('DB_HOST')) {
            $pdo = new PDO('mysql:host=' . getenv('DB_HOST') . ';dbname=' . getenv('DB_NAME') . ';charset=utf8mb4',
                getenv('DB_USER'), getenv('DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]); // same as Database
        }
    }
    return $pdo;
}

/** Fresh framework tables with $prefix; returns Database. */
function testDb(string $prefix = 't_'): WebApplication\Database\Database
{
    $pdo = testPdo();
    if ($pdo === null) {
        skip('no database (set DB_HOST etc.)');
    }
    foreach (['client', 'token', 'log', 'text'] as $t) {
        $pdo->exec("DROP TABLE IF EXISTS `{$prefix}{$t}`");
    }
    $sql = str_replace('{prefix}', $prefix, file_get_contents(__DIR__ . '/../sql/schema.sql'));
    foreach (array_filter(array_map('trim', explode(';', preg_replace('/^--.*$/m', '', $sql)))) as $stmt) {
        $pdo->exec($stmt);
    }
    return WebApplication\Database\Database::fromPdo($pdo, $prefix);
}

foreach (glob(__DIR__ . '/*Test.php') as $file) {
    require $file;
}

$filter = $argv[1] ?? '';
echo 'PHP ' . PHP_VERSION . "\n";
foreach (T::$tests as list($name, $fn)) {
    if ($filter !== '' && stripos($name, $filter) === false) {
        continue;
    }
    try {
        $fn();
        T::$passed++;
        echo "  ok    $name\n";
    } catch (SkipTest $e) {
        T::$skipped++;
        echo "  skip  $name (" . $e->getMessage() . ")\n";
    } catch (Throwable $e) {
        T::$failures++;
        echo "  FAIL  $name\n        " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    }
}
printf("\n%d passed, %d failed, %d skipped\n", T::$passed, T::$failures, T::$skipped);
exit(T::$failures > 0 ? 1 : 0);
