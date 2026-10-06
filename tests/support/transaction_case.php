<?php declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);
define('SQL_ERROR_FILE', false);
$GLOBALS['db_fixture_legacy_sql_exception'] = in_array($argv[1] ?? '',
    ['exception_without_sqlstate_write', 'exception_without_sqlstate_read'], true);
require __DIR__ . '/mysqli.php';
require __DIR__ . '/../../core.php';
require __DIR__ . '/../../db.php';
use ThreadFin\DB\DB;
use ThreadFin\DB\ExecutionFailure;
use const ThreadFin\DB\DB_FETCH_INSERT_ID;
use const ThreadFin\DB\DB_FETCH_NUM_ROWS;

function tx_failure(callable $fn): Throwable {
    try { $fn(); } catch (Throwable $e) { return $e; }
    throw new RuntimeException('Expected transaction failure');
}
function tx_case(string $case): bool {
    $handle = new mysqli();
    $db = DB::from($handle);
    $GLOBALS['db_fixture_query_rows']['SELECT @@SESSION.autocommit AS autocommit'] = [['autocommit' => 1]];
    $start = count($handle->queries);
    switch ($case) {
        case 'falsey':
            foreach ([null, false, 0, '', ['x']] as $value) { assert($db->transaction(fn() => $value) === $value); }
            assert(count(array_filter($handle->queries, fn($q) => $q === 'COMMIT AND NO CHAIN NO RELEASE')) === 5);
            break;
        case 'callback':
            $original = new LogicException('original');
            assert(tx_failure(fn() => $db->transaction(function($db) use ($original) {
                $db->unsafe_raw('INSERT INTO t VALUES (1)'); throw $original;
            })) === $original);
            assert(end($handle->queries) === 'ROLLBACK AND NO CHAIN NO RELEASE');
            assert($db->transaction(fn() => 7) === 7);
            break;
        case 'write_failure': case 'read_failure': case 'exception_failure':
            $sql = $case === 'read_failure' ? 'SELECT missing' : 'UPDATE t SET a=1';
            $GLOBALS['db_fixture_query_failures'][$sql] = $case === 'exception_failure' ? 'exception' : 'false';
            if ($case === 'exception_failure') {
                assert(method_exists(mysqli_sql_exception::class, 'getSqlState'));
                $GLOBALS['db_fixture_exception_errno'][$sql] = 1064;
                $GLOBALS['db_fixture_query_errno'][$sql] = 0; // Exception is authoritative when handle diagnostics differ.
            }
            $error = tx_failure(fn() => $db->transaction(function($db) use ($sql, $case) {
                if ($case === 'read_failure') { $db->fetch($sql); } else { $db->unsafe_raw($sql); }
                $db->errors = []; // Caller-mutable diagnostics cannot erase actual failure.
            }));
            assert($error instanceof ExecutionFailure && $error->errno === 1064 && $error->sqlstate === '42000');
            assert(end($handle->queries) === 'ROLLBACK AND NO CHAIN NO RELEASE');
            break;
        case 'exception_without_sqlstate_write': case 'exception_without_sqlstate_read':
            assert(!method_exists(mysqli_sql_exception::class, 'getSqlState'));
            $read = $case === 'exception_without_sqlstate_read';
            $sql = $read ? 'SELECT missing' : 'UPDATE t SET a=1';
            $GLOBALS['db_fixture_query_failures'][$sql] = 'exception';
            $GLOBALS['db_fixture_exception_errno'][$sql] = 1064;
            $GLOBALS['db_fixture_query_errno'][$sql] = 0;
            $error = null;
            try {
                $db->transaction(function($db) use ($sql, $read) {
                    $db->unsafe_raw('INSERT INTO t VALUES (1)');
                    try {
                        if ($read) { $db->fetch($sql); } else { $db->unsafe_raw($sql); }
                    } catch (Throwable $ignored) {
                        // A caught compatibility Error must not let a failed operation commit.
                    }
                    $db->errors = [];
                });
            } catch (Throwable $caught) { $error = $caught; }
            assert(!in_array('COMMIT AND NO CHAIN NO RELEASE', $handle->queries, true));
            assert(end($handle->queries) === 'ROLLBACK AND NO CHAIN NO RELEASE');
            assert($error instanceof ExecutionFailure && $error->errno === 1064 && $error->sqlstate === '42000');
            break;
        case 'sentinel':
            assert($db->transaction(fn($db) => $db->unsafe_raw('UPDATE t SET a=1', DB_FETCH_INSERT_ID)) === -1);
            assert($db->transaction(fn($db) => $db->unsafe_raw('UPDATE t SET a=1', DB_FETCH_NUM_ROWS)) === 0);
            assert(end($handle->queries) === 'COMMIT AND NO CHAIN NO RELEASE');
            break;
        case 'nested':
            $GLOBALS['db_fixture_query_failures']['UPDATE bad SET a=1'] = 'false';
            $value = $db->transaction(function($db) {
                assert($db->transaction(fn() => false) === false);
                assert(tx_failure(fn() => $db->transaction(fn($db) => $db->unsafe_raw('UPDATE bad SET a=1'))) instanceof ExecutionFailure);
                return $db->transaction(fn() => 'recovered');
            });
            assert($value === 'recovered');
            $saves = array_values(array_filter($handle->queries, fn($q) => str_starts_with($q, 'SAVEPOINT ')));
            assert(count($saves) === 3 && count(array_unique($saves)) === 3);
            assert(in_array('ROLLBACK TO SAVEPOINT ' . substr($saves[1], 10), $handle->queries, true));
            assert(end($handle->queries) === 'COMMIT AND NO CHAIN NO RELEASE');
            break;
        case 'deadlock': case 'lost_connection':
            $GLOBALS['db_fixture_query_failures']['UPDATE bad SET a=1'] = 'false';
            $GLOBALS['db_fixture_query_errno']['UPDATE bad SET a=1'] = $case === 'deadlock' ? 1213 : 2013;
            $error = tx_failure(fn() => $db->transaction(function($db) {
                tx_failure(fn() => $db->transaction(fn($db) => $db->unsafe_raw('UPDATE bad SET a=1')));
                tx_failure(fn() => $db->unsafe_raw('INSERT INTO t VALUES (9)'));
            }));
            assert($error instanceof ExecutionFailure && $error->transactionInvalidated);
            assert(!in_array('COMMIT AND NO CHAIN NO RELEASE', $handle->queries, true));
            assert(!in_array('INSERT INTO t VALUES (9)', $handle->queries, true));
            break;
        case 'cleanup':
            $GLOBALS['db_fixture_query_failures']['ROLLBACK AND NO CHAIN NO RELEASE'] = 'false';
            $original = new LogicException('primary');
            assert(tx_failure(fn() => $db->transaction(function() use ($original) { throw $original; })) === $original);
            $errors = $db->transaction_cleanup_errors();
            assert(count($errors) === 1 && $errors[0] instanceof ExecutionFailure);
            $GLOBALS['db_fixture_query_failures']['ROLLBACK AND NO CHAIN NO RELEASE'] = 'error';
            for ($i = 0; $i < 20; $i++) {
                assert(tx_failure(fn() => $db->transaction(function() use ($original) { throw $original; })) === $original);
            }
            assert(count($db->transaction_cleanup_errors()) === 16);
            unset($GLOBALS['db_fixture_query_failures']['ROLLBACK AND NO CHAIN NO RELEASE']);
            assert($db->transaction(fn() => 1) === 1);
            break;
        case 'commit_failure': case 'release_failure':
            $sql = $case === 'commit_failure' ? 'COMMIT AND NO CHAIN NO RELEASE' : 'RELEASE SAVEPOINT threadfin_sp_1';
            $GLOBALS['db_fixture_query_failures'][$sql] = 'false';
            $error = tx_failure(fn() => $db->transaction(function($db) use ($case) {
                if ($case === 'release_failure') { tx_failure(fn() => $db->transaction(fn() => 1)); }
            }));
            assert($error instanceof ExecutionFailure && $error->transactionInvalidated);
            assert(end($handle->queries) === 'ROLLBACK AND NO CHAIN NO RELEASE');
            break;
        case 'ownership':
            $GLOBALS['db_fixture_query_rows']['SELECT @@SESSION.autocommit AS autocommit'] = [['autocommit' => 0]];
            assert(tx_failure(fn() => $db->transaction(fn() => 1)) instanceof ExecutionFailure);
            assert(!in_array('SET TRANSACTION READ WRITE', $handle->queries, true));
            $GLOBALS['db_fixture_query_rows']['SELECT @@SESSION.autocommit AS autocommit'] = [['autocommit' => 1]];
            $GLOBALS['db_fixture_query_failures']['SET TRANSACTION READ WRITE'] = 'false';
            assert(tx_failure(fn() => $db->transaction(fn() => 1)) instanceof ExecutionFailure);
            assert(!in_array('START TRANSACTION', $handle->queries, true));
            assert(!in_array('ROLLBACK AND NO CHAIN NO RELEASE', $handle->queries, true));
            break;
        case 'policy':
            foreach (['CREATE TABLE t (a INT)', 'COMMIT', 'SET autocommit=0', 'CALL p()', 'WITH x AS (SELECT 1) SELECT * FROM x',
                'SELECT 1; COMMIT', '/*! COMMIT */', 'SELECT 1 /*M! ; COMMIT */', 'EXPLAIN ANALYZE DELETE FROM t'] as $sql) {
                $error = tx_failure(fn() => $db->transaction(function($db) use ($sql) {
                    tx_failure(fn() => $db->unsafe_raw($sql)); // Caught guard still poisons this scope.
                }));
                assert($error instanceof ExecutionFailure);
                assert(!in_array($sql, $handle->queries, true));
            }
            $sql = "/* ordinary */ SELECT ';COMMIT', 'it\\'s', `semi;colon`; -- tail";
            $GLOBALS['db_fixture_query_rows'][$sql] = [['value' => 1]];
            $db->transaction(fn($db) => $db->fetch($sql));
            break;
        case 'lifecycle_guards':
            foreach ([fn() => $db->close(), fn() => $db->enable_simulation(true), fn() => $db->enable_replay('/tmp/unused-tx-journal')] as $fn) {
                assert(tx_failure(fn() => $db->transaction(function() use ($fn) { tx_failure($fn); })) instanceof ExecutionFailure);
                assert($db->connected());
            }
            break;
        case 'simulation':
            $db->enable_simulation(true);
            $before = count($handle->queries);
            assert($db->transaction(fn($db) => $db->transaction(fn($db) => $db->unsafe_raw('INSERT INTO t VALUES (1)', DB_FETCH_INSERT_ID))) === 0);
            assert(tx_failure(fn() => $db->transaction(fn($db) => $db->unsafe_raw('COMMIT'))) instanceof ExecutionFailure);
            assert(count($handle->queries) === $before);
            break;
        case 'replay':
            $path = tempnam(sys_get_temp_dir(), 'threadfin-tx-');
            try {
                $db->enable_replay($path)->transaction(function($db) {
                    $db->unsafe_raw('INSERT INTO t VALUES (1)');
                    tx_failure(fn() => $db->transaction(function($db) { $db->unsafe_raw('INSERT INTO t VALUES (2)'); throw new LogicException(); }));
                });
                tx_failure(fn() => $db->transaction(function($db) { $db->unsafe_raw('INSERT INTO t VALUES (3)'); throw new LogicException(); }));
                $db->close();
                $journal = file_get_contents($path);
                $expected = ['SET TRANSACTION READ WRITE', 'START TRANSACTION', 'INSERT INTO t VALUES (1)', 'SAVEPOINT threadfin_sp_1',
                    'INSERT INTO t VALUES (2)', 'ROLLBACK TO SAVEPOINT threadfin_sp_1', 'RELEASE SAVEPOINT threadfin_sp_1', 'COMMIT AND NO CHAIN NO RELEASE',
                    'SET TRANSACTION READ WRITE', 'START TRANSACTION', 'INSERT INTO t VALUES (3)', 'ROLLBACK AND NO CHAIN NO RELEASE'];
                $position = 0;
                foreach ($expected as $sql) { $next = strpos($journal, $sql . "\n;\n", $position); assert($next !== false); $position = $next + strlen($sql); }
            } finally { unlink($path); }
            break;
        case 'builder_repair':
            $db->transaction(function($db) {
                assert(tx_failure(fn() => $db->fetch('SELECT {value}', [])) instanceof InvalidArgumentException);
                $db->fetch('SELECT {value}', ['value' => 1]);
            });
            assert(end($handle->queries) === 'COMMIT AND NO CHAIN NO RELEASE');
            break;
        case 'ordinary':
            $db->unsafe_raw('CREATE TABLE t (a INT)', DB_FETCH_INSERT_ID);
            $db->fetch('SELECT 1');
            foreach (['BEGIN', 'COMMIT', 'ROLLBACK'] as $sql) { $db->unsafe_raw($sql); }
            assert(array_slice($handle->queries, $start) === ['CREATE TABLE t (a INT)', 'SELECT 1', 'BEGIN', 'COMMIT', 'ROLLBACK']);
            break;
        default: throw new InvalidArgumentException('Unknown transaction case');
    }
    $db->close();
    return true;
}
ob_start();
try { $report = ['value' => tx_case($argv[1]), 'error' => null]; }
catch (Throwable $e) { $report = ['value' => null, 'error' => get_class($e) . ': ' . $e->getMessage()]; }
$report['output'] = ob_get_clean();
echo json_encode($report, JSON_THROW_ON_ERROR);
