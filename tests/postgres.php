<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use JoomEngine\Workspace\{Fault, PgStore};

$dsn = getenv('WP_TEST_DSN') ?: '';
if (!preg_match('/(?:^|;)dbname=[a-z0-9_]+_test(?:;|$)/D', $dsn)) {
    fwrite(STDERR, "Set WP_TEST_DSN to an explicitly disposable PostgreSQL database ending in _test.\n");
    exit(2);
}
$connect = static fn (): PDO => new PDO($dsn, getenv('WP_TEST_USER') ?: '', getenv('WP_TEST_PASSWORD') ?: '');
$a = new PgStore($connect());
$b = new PgStore($connect());
$a->migrate();
$b->migrate();
$assert = static function (bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException($message); }
};
$a->transaction(static function (array &$s): void { $s['test_counter'] = 0; });
$a->transaction(static function (array &$s): void { ++$s['test_counter']; });
$assert($b->transaction(static fn (array &$s): int => $s['test_counter']) === 1, 'Committed state is not visible.');
try {
    $a->transaction(static function (array &$s): void { $s['test_counter'] = 100; throw new RuntimeException('rollback'); });
} catch (RuntimeException $error) {
    $assert($error->getMessage() === 'rollback', 'Unexpected transaction failure.');
}
$assert($b->transaction(static fn (array &$s): int => $s['test_counter']) === 1, 'Rollback changed committed state.');
$a->exclusive(static function () use ($b, $assert): void {
    try {
        $b->exclusive(static fn (): bool => true);
        throw new RuntimeException('Executor lock was not exclusive.');
    } catch (Fault $error) {
        $assert($error->reason === 'busy', 'Unexpected lock failure.');
    }
    // The executor holds a session lock, not a transaction: another connection can submit work.
    $b->transaction(static function (array &$s): void { ++$s['test_counter']; });
});
$assert($b->exclusive(static fn (): bool => true), 'Executor lock was not released.');
$assert($a->transaction(static fn (array &$s): int => $s['test_counter']) === 2, 'Concurrent submission failed.');
echo "PASS PostgreSQL migration, commit/rollback, visibility and executor fencing\n";
