<?php declare(strict_types=1);
/** Native transaction proof on the explicitly configured disposable server only. */
error_reporting(E_ALL & ~E_DEPRECATED);
define('SQL_ERROR_FILE', false);
require __DIR__ . '/../../core.php';
require __DIR__ . '/../../db.php';
use ThreadFin\DB\DB;
use ThreadFin\DB\ExecutionFailure;

function tx_native_handle(): mysqli {
    return new mysqli('localhost', getenv('THREADFIN_TEST_MYSQL_USER') ?: 'root', '',
        getenv('THREADFIN_TEST_MYSQL_DATABASE'), null, getenv('THREADFIN_TEST_MYSQL_SOCKET'));
}
function tx_native_failure(callable $fn): Throwable {
    try { $fn(); } catch (Throwable $e) { return $e; }
    throw new RuntimeException('Expected native transaction failure');
}
function tx_native_ids(mysqli $admin, string $table): array {
    return array_map('intval', array_column($admin->query("SELECT id FROM `$table` ORDER BY id")->fetch_all(MYSQLI_ASSOC), 'id'));
}
function tx_native_case(string $case, mysqli $admin, string $table): bool {
    $handle = tx_native_handle();
    $db = DB::from($handle);
    try {
        switch ($case) {
            case 'completion_chain_commit': case 'completion_chain_rollback':
            case 'completion_release_commit': case 'completion_release_rollback':
                $mode = str_contains($case, '_chain_') ? 1 : 2;
                $rollback = str_ends_with($case, '_rollback');
                $handle->query('SET SESSION completion_type = ' . $mode);
                $stateSql = 'SELECT CONNECTION_ID() AS id, @@SESSION.autocommit AS autocommit, @@SESSION.completion_type AS completion';
                $before = $handle->query($stateSql)->fetch_assoc();
                $original = new LogicException('completion_type callback failure');
                $callback = function($db) use ($table, $rollback, $original) {
                    $db->unsafe_raw("INSERT INTO `$table` VALUES (1, 0)");
                    if ($rollback) { throw $original; }
                    return 'committed';
                };
                if ($rollback) { assert(tx_native_failure(fn() => $db->transaction($callback)) === $original); }
                else { assert($db->transaction($callback) === 'committed'); }
                $expected = $rollback ? [] : [1];
                assert(tx_native_ids($admin, $table) === $expected);
                assert($db->connected() && $handle->query($stateSql)->fetch_assoc() === $before);
                assert((string)$before['autocommit'] === '1');
                assert(tx_native_ids($handle, $table) === $expected);
                // This portable ownership probe fails if completion_type chained a transaction.
                assert($handle->query('SET TRANSACTION READ WRITE') === true);
                assert($db->transaction(fn($db) => $db->unsafe_raw("INSERT INTO `$table` VALUES (2, 0)")) === 1);
                $expected[] = 2;
                assert(tx_native_ids($admin, $table) === $expected);
                assert($handle->query($stateSql)->fetch_assoc() === $before);
                assert($handle->query('SET TRANSACTION READ WRITE') === true);
                assert($db->transaction_cleanup_errors() === []);
                break;
            case 'commit_rollback':
                foreach ([null, false, 0, '', ['x']] as $id => $value) {
                    assert($db->transaction(function($db) use ($table, $id, $value) {
                        $db->unsafe_raw("INSERT INTO `$table` VALUES ($id, 0)"); return $value;
                    }) === $value);
                }
                $original = new LogicException('callback');
                assert(tx_native_failure(fn() => $db->transaction(function($db) use ($table, $original) {
                    $db->unsafe_raw("INSERT INTO `$table` VALUES (10, 0)"); throw $original;
                })) === $original);
                assert(tx_native_ids($admin, $table) === [0, 1, 2, 3, 4]);
                assert($db->transaction(fn($db) => $db->unsafe_raw("UPDATE `$table` SET value=1 WHERE id=0", \ThreadFin\DB\DB_FETCH_INSERT_ID)) === -1);
                assert((int)$admin->query("SELECT value FROM `$table` WHERE id=0")->fetch_row()[0] === 1);
                break;
            case 'ignored_failure_nested':
                $error = tx_native_failure(fn() => $db->transaction(function($db) use ($table) {
                    $db->unsafe_raw("INSERT INTO `$table` VALUES (1, 0)");
                    $db->unsafe_raw("INSERT INTO `$table` VALUES (1, 0)");
                    $db->errors = [];
                }));
                assert($error instanceof ExecutionFailure && $error->errno === 1062 && $error->sqlstate === '23000');
                assert(tx_native_ids($admin, $table) === []);
                mysqli_report(MYSQLI_REPORT_OFF);
                try {
                    $falseError = tx_native_failure(fn() => $db->transaction(function($db) use ($table) {
                        $db->unsafe_raw("INSERT INTO `$table` VALUES (1, 0)");
                        $db->unsafe_raw("INSERT INTO `$table` VALUES (1, 0)");
                    }));
                    assert($falseError instanceof ExecutionFailure && $falseError->errno === 1062 && $falseError->sqlstate === '23000');
                } finally { mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT); }
                assert(tx_native_ids($admin, $table) === []);
                $db->transaction(function($db) use ($table) {
                    $db->unsafe_raw("INSERT INTO `$table` VALUES (1, 0)");
                    assert(tx_native_failure(fn() => $db->transaction(function($db) use ($table) {
                        $db->unsafe_raw("INSERT INTO `$table` VALUES (2, 0)");
                        $db->fetch('SELECT missing_transaction_column');
                    })) instanceof ExecutionFailure);
                    $db->transaction(fn($db) => $db->unsafe_raw("INSERT INTO `$table` VALUES (3, 0)"));
                });
                assert(tx_native_ids($admin, $table) === [1, 3]);
                break;
            case 'ownership':
                foreach ([true, false] as $autocommit) {
                    $handle->autocommit($autocommit);
                    $handle->begin_transaction();
                    $handle->query("INSERT INTO `$table` VALUES (1, 0)");
                    $called = false;
                    $error = tx_native_failure(fn() => $db->transaction(function() use (&$called) { $called = true; }));
                    assert($error instanceof ExecutionFailure && !$called);
                    if ($autocommit) { assert($error->errno === 1568); }
                    assert(tx_native_ids($admin, $table) === []); // Probe must not implicitly commit.
                    assert(tx_native_ids($handle, $table) === [1]); // Nor may it roll back someone else's work.
                    $handle->rollback();
                    assert(tx_native_ids($handle, $table) === []);
                }
                $handle->autocommit(true);
                $handle->begin_transaction();
                assert(tx_native_failure(fn() => $db->transaction(fn() => null)) instanceof ExecutionFailure);
                $handle->rollback();
                $adoptedHandle = tx_native_handle();
                $adoptedHandle->begin_transaction();
                $adoptedHandle->query("INSERT INTO `$table` VALUES (1, 0)");
                $adopted = DB::from($adoptedHandle);
                try {
                    assert(tx_native_failure(fn() => $adopted->transaction(fn() => null)) instanceof ExecutionFailure);
                    assert(tx_native_ids($admin, $table) === []);
                    assert(tx_native_ids($adoptedHandle, $table) === [1]);
                    $adoptedHandle->rollback();
                } finally { $adopted->close(); }
                $db->transaction(fn($db) => $db->unsafe_raw("INSERT INTO `$table` VALUES (2, 0)"));
                assert(tx_native_ids($admin, $table) === [2]);
                break;
            case 'policy_simulation':
                foreach (["CREATE TABLE `{$table}_forbidden` (id INT)", 'COMMIT', 'SET autocommit=0', '/*! COMMIT */', 'CALL unknown_proc()'] as $sql) {
                    assert(tx_native_failure(fn() => $db->transaction(function($db) use ($table, $sql) {
                        $db->unsafe_raw("INSERT INTO `$table` VALUES (1, 0)");
                        tx_native_failure(fn() => $db->unsafe_raw($sql));
                    })) instanceof ExecutionFailure);
                    assert(tx_native_ids($admin, $table) === []);
                }
                $before = (int)$handle->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch_assoc()['Value'];
                $db->enable_simulation(true)->transaction(fn($db) => $db->transaction(fn($db) => $db->unsafe_raw("INSERT INTO `$table` VALUES (1, 0)")));
                $after = (int)$handle->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch_assoc()['Value'];
                assert($after - $before - 1 === 0);
                assert(tx_native_ids($admin, $table) === []);
                break;
            case 'replay':
                $path = tempnam(sys_get_temp_dir(), 'threadfin-native-tx-');
                try {
                    $db->enable_replay($path)->transaction(function($db) use ($table) {
                        $db->unsafe_raw("INSERT INTO `$table` VALUES (1, 0)");
                        tx_native_failure(fn() => $db->transaction(function($db) use ($table) {
                            $db->unsafe_raw("INSERT INTO `$table` VALUES (2, 0)"); throw new LogicException();
                        }));
                        $db->unsafe_raw("INSERT INTO `$table` VALUES (3, 0)");
                    });
                    tx_native_failure(fn() => $db->transaction(function($db) use ($table) {
                        $db->unsafe_raw("INSERT INTO `$table` VALUES (4, 0)"); throw new LogicException();
                    }));
                    $db->close();
                    $source = tx_native_ids($admin, $table);
                    assert($source === [1, 3]);
                    $admin->query("TRUNCATE TABLE `$table`");
                    $admin->multi_query(file_get_contents($path));
                    do {
                        $result = $admin->store_result();
                        if ($result instanceof mysqli_result) { $result->free(); }
                        if (!$admin->more_results()) { break; }
                        $admin->next_result();
                    } while (true);
                    assert(tx_native_ids($admin, $table) === $source);
                } finally { unlink($path); }
                break;
            case 'deadlock':
                for ($i = 1; $i <= 30; $i++) { $admin->query("INSERT INTO `$table` VALUES ($i, 0)"); }
                $peer = tx_native_handle();
                try {
                    $peer->query('SET SESSION innodb_lock_wait_timeout=3');
                    $handle->query('SET SESSION innodb_lock_wait_timeout=3');
                    $peer->begin_transaction();
                    $peer->query("UPDATE `$table` SET value=1 WHERE id >= 2");
                    $error = tx_native_failure(fn() => $db->transaction(function($db) use ($table, $peer) {
                        $db->unsafe_raw("UPDATE `$table` SET value=2 WHERE id=1");
                        $peer->query("UPDATE `$table` SET value=1 WHERE id=1", MYSQLI_ASYNC);
                        $inner = tx_native_failure(fn() => $db->transaction(fn($db) => $db->unsafe_raw("UPDATE `$table` SET value=2 WHERE id=2")));
                        assert($inner instanceof ExecutionFailure && $inner->errno === 1213 && $inner->transactionInvalidated);
                        assert(tx_native_failure(fn() => $db->unsafe_raw("INSERT INTO `$table` VALUES (40, 0)")) instanceof ExecutionFailure);
                    }));
                    assert($error instanceof ExecutionFailure && $error->errno === 1213);
                    assert($peer->reap_async_query() === true);
                    $peer->rollback();
                    assert((int)$admin->query("SELECT SUM(value) FROM `$table`")->fetch_row()[0] === 0);
                    assert(count(tx_native_ids($admin, $table)) === 30);
                } finally { $peer->close(); }
                break;
            case 'cleanup':
                $original = new LogicException('preserve callback exception');
                assert(tx_native_failure(fn() => $db->transaction(function($db) use ($table, $admin, $handle, $original) {
                    $db->unsafe_raw("INSERT INTO `$table` VALUES (1, 0)");
                    $admin->query('KILL CONNECTION ' . $handle->thread_id);
                    throw $original;
                })) === $original);
                $errors = $db->transaction_cleanup_errors();
                assert(count($errors) === 1 && $errors[0] instanceof ExecutionFailure && $errors[0]->transactionInvalidated);
                assert(tx_native_ids($admin, $table) === []);
                break;
            default: throw new InvalidArgumentException('Unknown native transaction case');
        }
    } finally { $db->close(); }
    return true;
}
ob_start();
$admin = null; $owned = false;
$table = 'threadfin_tx_' . bin2hex(random_bytes(6));
try {
    if (!extension_loaded('mysqli') || getenv('THREADFIN_TEST_MYSQL_ALLOW_SCHEMA_CHANGES') !== '1') { throw new RuntimeException('Native permission required'); }
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $admin = tx_native_handle();
    if ($admin->query('SHOW TABLES')->num_rows !== 0) { throw new RuntimeException('An EMPTY disposable database is required'); }
    $admin->query("CREATE TABLE `$table` (id INT PRIMARY KEY, value INT NOT NULL) ENGINE=InnoDB");
    $owned = true;
    $report = ['value' => tx_native_case($argv[1], $admin, $table), 'error' => null];
} catch (Throwable $e) { $report = ['value' => null, 'error' => get_class($e) . ': ' . $e->getMessage()]; }
finally {
    if ($admin) {
        if ($owned) { $admin->query("DROP TABLE IF EXISTS `$table`, `{$table}_forbidden`"); }
        $admin->close();
    }
    $report['output'] = ob_get_clean();
}
echo json_encode($report, JSON_THROW_ON_ERROR);
