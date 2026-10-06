<?php declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);
$errorPath = ($argv[1] ?? '') === 'disconnect_errors' ? tempnam(sys_get_temp_dir(), 'threadfin-prepared-errors-') : false;
define('SQL_ERROR_FILE', $errorPath);
$GLOBALS['db_fixture_legacy_sql_exception'] = ($argv[1] ?? '') === 'old_exception';
require __DIR__ . '/mysqli.php';
require __DIR__ . '/../../core.php';
require __DIR__ . '/../../db.php';
use ThreadFin\DB\DB;
use ThreadFin\DB\BinaryParameter;
use ThreadFin\DB\ExecutionFailure;
use const ThreadFin\DB\DB_FETCH_INSERT_ID;
use const ThreadFin\DB\DB_FETCH_NUM_ROWS;
function prepared_failure(callable $fn): Throwable {
    try { $fn(); } catch (Throwable $e) { return $e; }
    throw new RuntimeException('Expected failure');
}
function prepared_case(string $case): bool {
    $handle = new mysqli(); $db = DB::from($handle);
    $GLOBALS['db_fixture_query_rows']['SELECT @@SESSION.autocommit AS autocommit'] = [['autocommit' => 1]];
    $before = count($handle->calls);
    $sql = 'SELECT ?';
    switch ($case) {
        case 'binding':
            $GLOBALS['db_fixture_prepared'] = ['count' => 8, 'affected' => 2, 'id' => 17];
            $bytes = str_repeat("\0\xff", 70000);
            assert($db->execute_prepared('INSERT INTO t VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [null, false, true, 0, 1.25, '00123', 'é😀', new BinaryParameter($bytes)], DB_FETCH_NUM_ROWS) === 2);
            $stmt = $GLOBALS['db_fixture_statements'][0];
            assert($stmt->types === 'siiidssb');
            assert(array_slice($stmt->bound, 0, 7) === [null, 0, 1, 0, 1.25, '00123', 'é😀']);
            assert(count($stmt->chunks) === 3);
            assert(implode('', array_column($stmt->chunks, 1)) === $bytes);
            foreach ($stmt->chunks as [$index, $chunk]) { assert($index === 7 && strlen($chunk) <= 65536); }
            assert($stmt->closed);
            $GLOBALS['db_fixture_prepared'] = ['count' => 1];
            assert($db->execute_prepared('INSERT INTO t VALUES (?)', [new BinaryParameter('')]) === 1);
            assert($GLOBALS['db_fixture_statements'][1]->chunks === [[0, '']]);
            break;
        case 'validation':
            $resource = fopen('php://memory', 'r+');
            try {
                foreach ([[1 => 'x'], ['x' => 1], [new stdClass()], [[]], [$resource], [INF], [-INF], [NAN], ["\xff"]] as $params) {
                    $error = prepared_failure(fn() => $db->fetch_prepared($sql, $params));
                    assert($error instanceof InvalidArgumentException);
                    assert(!str_contains($error->getMessage(), 'SECRET'));
                }
                assert(prepared_failure(fn() => $db->execute_prepared($sql, [], 999)) instanceof InvalidArgumentException);
                assert(count($handle->calls) === $before);
            } finally { fclose($resource); }
            break;
        case 'simulation':
            $db->enable_simulation(true);
            assert($db->execute_prepared('INVALID ? ?', ['SECRET']) === 1);
            assert($db->execute_prepared($sql, [0], DB_FETCH_NUM_ROWS) === 0);
            assert($db->execute_prepared($sql, [0], DB_FETCH_INSERT_ID) === 0);
            $result = $db->fetch_prepared('INVALID ? ?', ['SECRET']);
            assert($result->as_array() === []);
            assert(count($handle->calls) === $before);
            assert(!str_contains(json_encode([$db->logs, $db->errors, $db->last_stmt]), 'SECRET'));
            $field = new ReflectionProperty($result, '_sql');
            assert($field->getValue($result) === 'INVALID ? ?');
            assert(prepared_failure(fn() => $db->fetch_prepared($sql, [NAN])) instanceof InvalidArgumentException);
            break;
        case 'buffered': case 'empty':
            $rows = $case === 'empty' ? [] : [[null, 0, '00123', "\0\xff"], ['next', 7, 'text', '']];
            $GLOBALS['db_fixture_prepared'] = ['fields' => ['a', 'b', 'c', 'd'], 'rows' => $rows];
            $result = $db->fetch_prepared('SELECT a,b,c,d FROM t');
            $expected = array_map(fn($row) => array_combine(['a','b','c','d'], $row), $rows);
            assert($result instanceof ThreadFin\DB\SQL && $result->as_array() === $expected);
            $stmt = $GLOBALS['db_fixture_statements'][0];
            assert($stmt->closed && $stmt->metadata->free_calls === 1);
            $field = new ReflectionProperty($result, '_sql');
            assert($field->getValue($result) === 'SELECT a,b,c,d FROM t');
            if ($rows) {
                $result->next(); assert($result->current() === $expected[1]);
                assert($result[0] === $expected[0] && $result->key() === 1);
                $result->rewind(); assert($result->as_array() === $expected);
            }
            $result->close(); $result->close(); assert(count($result) === 0 && !isset($result[0]));
            break;
        case 'modes':
            $GLOBALS['db_fixture_prepared'] = ['affected' => 0, 'id' => 0];
            assert($db->execute_prepared('CREATE TABLE t (a INT)', [], DB_FETCH_INSERT_ID) === -1);
            assert($db->execute_prepared('UPDATE t SET a=1', [], DB_FETCH_NUM_ROWS) === 0);
            $GLOBALS['db_fixture_prepared'] = ['id' => 29, 'affected' => 1];
            assert($db->execute_prepared('INSERT INTO t VALUES (1)', [], DB_FETCH_INSERT_ID) === 29);
            break;
        case 'count':
            $GLOBALS['db_fixture_prepared'] = ['count' => 2];
            assert(prepared_failure(fn() => $db->fetch_prepared($sql, ['SECRET'])) instanceof ExecutionFailure);
            $stmt = $GLOBALS['db_fixture_statements'][0];
            assert($stmt->closed && $stmt->types === '');
            assert(!in_array(['execute'], $handle->calls, true));
            break;
        case 'no_rowset':
            assert(prepared_failure(fn() => $db->fetch_prepared('UPDATE t SET a=1')) instanceof ExecutionFailure);
            assert($GLOBALS['db_fixture_statements'][0]->closed);
            break;
        case 'failures': case 'old_exception':
            foreach (['prepare_result', 'bind_param', 'send_long_data', 'execute', 'store_result', 'result_metadata', 'fetch_fields', 'bind_result', 'fetch'] as $step) {
                foreach (['false', 'exception'] as $mode) {
                    $GLOBALS['db_fixture_prepared'] = ['count' => 1, 'fields' => ['a'], 'fail' => [$step => $mode]];
                    $error = prepared_failure(fn() => $db->fetch_prepared($sql, [new BinaryParameter('SECRET')]));
                    assert($error instanceof ExecutionFailure && $error->errno === 1064 && $error->sqlstate === '42000');
                    assert(!str_contains($error->getMessage(), 'SECRET') && $error->getPrevious() === null);
                    $stmt = end($GLOBALS['db_fixture_statements']);
                    assert($step === 'prepare_result' || $stmt->closed);
                    if ($stmt->metadata) { assert($stmt->metadata->free_calls === 1); }
                }
            }
            assert(!str_contains(json_encode([$db->errors, $db->logs]), 'SECRET'));
            break;
        case 'transaction':
            $GLOBALS['db_fixture_prepared'] = ['fail' => ['execute' => 'false']];
            $error = prepared_failure(fn() => $db->transaction(function($db) {
                prepared_failure(fn() => $db->execute_prepared('UPDATE t SET a=1'));
                $db->errors = [];
            }));
            assert($error instanceof ExecutionFailure && end($handle->queries) === 'ROLLBACK AND NO CHAIN NO RELEASE');
            $GLOBALS['db_fixture_prepared'] = [];
            $db->transaction(function($db) {
                assert(prepared_failure(fn() => $db->execute_prepared('UPDATE t SET a=?', [NAN])) instanceof InvalidArgumentException);
                assert($db->execute_prepared('UPDATE t SET a=1', [], DB_FETCH_INSERT_ID) === -1);
            });
            assert(end($handle->queries) === 'COMMIT AND NO CHAIN NO RELEASE');
            $calls = count($GLOBALS['db_fixture_statements']);
            assert(prepared_failure(fn() => $db->transaction(fn($db) => $db->execute_prepared('CREATE TABLE t (a INT)'))) instanceof ExecutionFailure);
            assert(count($GLOBALS['db_fixture_statements']) === $calls);
            break;
        case 'transaction_guards':
            foreach ([['count' => 1], []] as $scenario) {
                $GLOBALS['db_fixture_prepared'] = $scenario;
                assert(prepared_failure(fn() => $db->transaction(function($db) {
                    prepared_failure(fn() => $db->fetch_prepared('SELECT a FROM t'));
                })) instanceof ExecutionFailure);
                assert(end($handle->queries) === 'ROLLBACK AND NO CHAIN NO RELEASE');
            }
            $path = tempnam(sys_get_temp_dir(), 'threadfin-prepared-guard-');
            try {
                $db->enable_replay($path);
                assert(prepared_failure(fn() => $db->transaction(function($db) {
                    prepared_failure(fn() => $db->execute_prepared('UPDATE t SET a=1'));
                })) instanceof ExecutionFailure);
                assert(end($handle->queries) === 'ROLLBACK AND NO CHAIN NO RELEASE');
                $db->close();
            } finally { unlink($path); }
            break;
        case 'deadlock':
            $GLOBALS['db_fixture_prepared'] = ['fail' => ['execute' => 'exception'], 'errno' => 1213];
            $error = prepared_failure(fn() => $db->transaction(function($db) {
                prepared_failure(fn() => $db->transaction(fn($db) => $db->execute_prepared('UPDATE t SET a=1')));
            }));
            assert($error instanceof ExecutionFailure && $error->transactionInvalidated);
            assert(!in_array('COMMIT AND NO CHAIN NO RELEASE', $handle->queries, true));
            break;
        case 'replay':
            $path = tempnam(sys_get_temp_dir(), 'threadfin-prepared-');
            try {
                $db->enable_replay($path); $before = count($handle->calls);
                assert(prepared_failure(fn() => $db->execute_prepared('SELECT ?', ['SECRET'])) instanceof ExecutionFailure);
                assert(count($handle->calls) === $before);
                $GLOBALS['db_fixture_prepared'] = ['count' => 1, 'fields' => ['a']];
                assert($db->fetch_prepared('SELECT ?', ['SECRET'])->as_array() === []);
                $db->enable_simulation(true);
                assert($db->execute_prepared('INSERT INTO t VALUES (?)', ['SECRET']) === 1);
                $db->close(); assert(file_get_contents($path) === '');
            } finally { unlink($path); }
            break;
        case 'cleanup':
            for ($i = 0; $i < 18; $i++) {
                $GLOBALS['db_fixture_prepared'] = ['fields' => ['a'], 'fail' => ['fetch' => 'exception', 'free_result' => 'exception']];
                $error = prepared_failure(fn() => $db->fetch_prepared('SELECT a FROM t'));
                assert($error instanceof ExecutionFailure && $error->getMessage() === 'Prepared operation failed');
                assert(end($GLOBALS['db_fixture_statements'])->closed);
            }
            assert(count($db->prepared_cleanup_errors()) === 16);
            foreach ($db->prepared_cleanup_errors() as $error) { assert(!str_contains($error->getMessage(), 'SECRET')); }
            $GLOBALS['db_fixture_prepared'] = ['fail' => ['stmt_close' => 'false']];
            $error = prepared_failure(fn() => $db->execute_prepared('UPDATE t SET a=1'));
            assert($error instanceof ExecutionFailure && $error->transactionInvalidated && str_contains($error->getMessage(), 'cleanup'));
            assert(!$db->connected() && $handle->closed);
            $calls = count($handle->calls); $db->close(); $db->close(); assert(count($handle->calls) === $calls);
            break;
        case 'cleanup_success':
            foreach (['metadata_free', 'free_result'] as $step) {
                $GLOBALS['db_fixture_prepared'] = ['fields' => ['a'], 'rows' => [[1]], 'fail' => [$step => 'exception']];
                $error = prepared_failure(fn() => $db->transaction(function($db) {
                    prepared_failure(fn() => $db->fetch_prepared('SELECT a FROM t'));
                }));
                assert($error instanceof ExecutionFailure && $error->transactionInvalidated);
                assert(end($handle->queries) === 'ROLLBACK AND NO CHAIN NO RELEASE');
                assert(end($GLOBALS['db_fixture_statements'])->closed);
            }
            assert(count($db->prepared_cleanup_errors()) === 2);
            $GLOBALS['db_fixture_prepared'] = ['fail' => ['execute' => 'exception', 'stmt_close' => 'exception']];
            $error = prepared_failure(fn() => $db->execute_prepared('UPDATE t SET a=1'));
            assert($error instanceof ExecutionFailure && $error->getMessage() === 'Prepared operation failed' && $error->errno === 1064);
            assert(!$db->connected() && $handle->closed && count($db->prepared_cleanup_errors()) === 3);
            break;
        case 'disconnect_replay':
            $path = tempnam(sys_get_temp_dir(), 'threadfin-prepared-disconnect-');
            try {
                foreach ([true, false] as $destruct) {
                    $h = new mysqli(); $wrapper = DB::from($h); $wrapper->enable_replay($path);
                    $wrapper->unsafe_raw('INSERT INTO t VALUES (1)');
                    $GLOBALS['db_fixture_prepared'] = ['fields' => ['a'], 'fail' => ['stmt_close' => 'exception']];
                    assert(prepared_failure(fn() => $wrapper->fetch_prepared('SELECT a FROM t')) instanceof ExecutionFailure);
                    assert(!$wrapper->connected());
                    if (!$destruct) { $wrapper->close(); $wrapper->close(); }
                    unset($wrapper); gc_collect_cycles();
                }
                assert(substr_count(file_get_contents($path), 'INSERT INTO t VALUES (1)') === 2);
            } finally { unlink($path); }
            break;
        case 'disconnect_errors':
            try {
                // Disconnected factory wrappers still require explicit close to log their errors.
                $wrapper = DB::from(null); $wrapper->errors[] = 'ordinary-disconnected'; unset($wrapper);
                assert(file_get_contents(SQL_ERROR_FILE) === '');
                foreach ([true, false] as $destruct) {
                    $wrapper = DB::from(new mysqli()); $wrapper->errors[] = 'pending-error';
                    $GLOBALS['db_fixture_prepared'] = ['fail' => ['stmt_close' => 'false']];
                    assert(prepared_failure(fn() => $wrapper->execute_prepared('UPDATE t SET a=1')) instanceof ExecutionFailure);
                    if (!$destruct) {
                        $wrapper->close(); $wrapper->close(); assert($wrapper->errors === ['pending-error']);
                    }
                    unset($wrapper); gc_collect_cycles();
                }
                assert(substr_count(file_get_contents(SQL_ERROR_FILE), 'pending-error') === 2);
                $db->close();
            } finally { unlink(SQL_ERROR_FILE); }
            break;
        case 'disconnected':
            $db->close();
            assert(prepared_failure(fn() => $db->fetch_prepared('SELECT 1')) instanceof ExecutionFailure);
            assert(prepared_failure(fn() => $db->execute_prepared('UPDATE t SET a=1')) instanceof ExecutionFailure);
            break;
        case 'trace_configuration':
            $prior = ini_set('zend.exception_ignore_args', '1');
            try {
                $GLOBALS['db_fixture_prepared'] = ['count' => 1, 'fail' => ['execute' => 'exception']];
                $error = prepared_failure(fn() => $db->execute_prepared('UPDATE t SET a=?', ['SECRET']));
                assert($error instanceof ExecutionFailure && $error->getPrevious() === null);
                foreach ($error->getTrace() as $frame) { assert(!isset($frame['args'])); }
                assert(!str_contains($error->getMessage() . $error->getTraceAsString(), 'SECRET'));
            } finally { ini_set('zend.exception_ignore_args', $prior); }
            break;
        case 'warnings': case 'cleanup_warnings':
            $notices = [];
            $handler = static function(int $number, string $message) use (&$notices): bool { $notices[] = [$number, $message]; return true; };
            set_error_handler($handler);
            $settings = ini_get_all(null, false);
            try {
                $steps = $case === 'warnings'
                    ? ['prepare_result', 'bind_param', 'send_long_data', 'execute', 'store_result', 'result_metadata', 'fetch_fields', 'bind_result', 'fetch']
                    : ['metadata_free', 'free_result', 'stmt_close'];
                foreach ($steps as $step) {
                    $wrapper = DB::from(new mysqli());
                    $GLOBALS['db_fixture_prepared'] = ['count' => 1, 'fields' => ['a'],
                        'fail' => [$step => $case === 'warnings' ? 'warning' : 'advisory']];
                    $error = prepared_failure(fn() => $wrapper->fetch_prepared('SELECT ?', [new BinaryParameter('CANARY_BOUND_SECRET')]));
                    assert($error instanceof ExecutionFailure && $error->getPrevious() === null);
                    if ($case === 'warnings') { assert($error->errno === 1064 && $error->sqlstate === '42000'); }
                    else { assert($error->transactionInvalidated && count($wrapper->prepared_cleanup_errors()) === 1); }
                    assert($notices === [] && !str_contains($error->getMessage(), 'CANARY_BOUND_SECRET'));
                    assert(set_error_handler($handler) === $handler); restore_error_handler();
                    assert(ini_get_all(null, false) === $settings);
                    $wrapper->close();
                }
                $wrapper = DB::from(new mysqli());
                $GLOBALS['db_fixture_prepared'] = ['fields' => ['a'], 'fail' => ['free_result' => 'advisory']];
                $error = prepared_failure(fn() => $wrapper->transaction(function($db) {
                    prepared_failure(fn() => $db->fetch_prepared('SELECT a FROM t'));
                }));
                assert($error instanceof ExecutionFailure && $error->transactionInvalidated);
                assert(count($wrapper->prepared_cleanup_errors()) === 1 && $notices === []);
                $wrapper->close();
                $wrapper = DB::from(new mysqli());
                $GLOBALS['db_fixture_prepared'] = ['fail' => ['stmt_close' => 'advisory']];
                $GLOBALS['db_fixture_close_warning'] = true;
                assert(prepared_failure(fn() => $wrapper->execute_prepared('UPDATE t SET a=1')) instanceof ExecutionFailure);
                assert(!$wrapper->connected() && count($wrapper->prepared_cleanup_errors()) === 2 && $notices === []);
                $wrapper->close();
                $GLOBALS['db_fixture_prepared'] = ['fail' => ['execute' => 'advisory']];
                assert($db->transaction(fn($db) => $db->execute_prepared('UPDATE t SET a=1')) === 1);
                assert(end($handle->queries) === 'COMMIT AND NO CHAIN NO RELEASE' && $notices === []);
                $GLOBALS['db_fixture_prepared'] = ['fail' => ['execute' => 'warning']];
                $error = prepared_failure(fn() => $db->transaction(function($db) {
                    prepared_failure(fn() => $db->execute_prepared('UPDATE t SET a=1'));
                }));
                assert($error instanceof ExecutionFailure && $error->errno === 1064 && end($handle->queries) === 'ROLLBACK AND NO CHAIN NO RELEASE');
                $GLOBALS['db_fixture_prepared'] = ['fields' => ['a'], 'fail' => ['fetch' => 'warning', 'free_result' => 'advisory']];
                $error = prepared_failure(fn() => $db->fetch_prepared('SELECT a FROM t'));
                assert($error instanceof ExecutionFailure && $error->errno === 1064 && $error->getMessage() === 'Prepared operation failed');
                assert(count($db->prepared_cleanup_errors()) === 1 && $notices === []);
                assert(set_error_handler($handler) === $handler); restore_error_handler();
                trigger_error('outside prepared operation', E_USER_WARNING);
                assert($notices === [[E_USER_WARNING, 'outside prepared operation']]);
            } finally { restore_error_handler(); }
            break;
        case 'ordinary':
            $db->unsafe_raw('UPDATE t SET a=1'); $db->fetch('SELECT 1');
            assert(array_slice($handle->calls, $before) === [['query', 'UPDATE t SET a=1'], ['query', 'SELECT 1']]);
            break;
        default: throw new InvalidArgumentException('Unknown prepared case');
    }
    $db->close(); return true;
}
ob_start();
try { $report = ['value' => prepared_case($argv[1]), 'error' => null]; }
catch (Throwable $e) { $report = ['value' => null, 'error' => get_class($e) . ': ' . $e->getMessage()]; }
$report['output'] = ob_get_clean();
echo json_encode($report, JSON_THROW_ON_ERROR);
