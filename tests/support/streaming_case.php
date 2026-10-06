<?php declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);
define('SQL_ERROR_FILE', false);
require __DIR__ . '/mysqli.php';
require __DIR__ . '/../../core.php';
require __DIR__ . '/../../db.php';
use ThreadFin\DB\DB;
use ThreadFin\DB\ExecutionFailure;
function streaming_failure(callable $fn): Throwable {
    try { $fn(); } catch (Throwable $e) { return $e; }
    throw new RuntimeException('Expected failure');
}
function streaming_case(string $case): bool {
    $h = new mysqli(); $db = DB::from($h);
    $sql = 'SELECT a FROM t';
    $rows = [['a' => null], ['a' => 0], ['a' => '00123'], ['a' => "\0\xff"]];
    $GLOBALS['db_fixture_query_rows'][$sql] = $rows;
    $GLOBALS['db_fixture_query_rows']['SELECT @@SESSION.autocommit AS autocommit'] = [['autocommit' => 1]];
    $GLOBALS['db_fixture_prepared'] = ['fields' => ['a'], 'rows' => array_map('array_values', $rows)];
    switch ($case) {
        case 'raw': case 'prepared':
            $s = $case === 'raw' ? $db->stream('SELECT a FROM {!table}', ['table' => 't']) : $db->stream_prepared($sql);
            assert($s instanceof ThreadFin\DB\StreamingRows && !$s instanceof Countable && !$s instanceof ArrayAccess);
            assert(streaming_failure(fn() => clone $s) instanceof Error);
            assert($s->_sql === $sql);
            assert(iterator_to_array($s) === $rows);
            $s->close(); $s->close();
            assert($db->unsafe_raw('DO 0') === 1);
            if ($case === 'raw') { assert(in_array(['query_mode', MYSQLI_USE_RESULT], $h->calls, true)); }
            else {
                assert(!in_array(['store_result'], $h->calls, true));
                assert($GLOBALS['db_fixture_statements'][0]->closed);
            }
            break;
        case 'empty':
            $GLOBALS['db_fixture_query_rows'][$sql] = [];
            $GLOBALS['db_fixture_prepared']['rows'] = [];
            foreach (['stream', 'stream_prepared'] as $method) {
                $s = $db->$method($sql); assert(iterator_to_array($s) === []); $s->close();
                assert($db->unsafe_raw('DO 0') === 1);
            }
            break;
        case 'simulation':
            $db->enable_simulation(true); $before = $h->calls;
            $s = $db->stream('INVALID {x}', ['x' => null]);
            $p = $db->stream_prepared('INVALID ?', ['CANARY']);
            assert(iterator_to_array($s) === [] && iterator_to_array($p) === []);
            assert($s->_sql === 'INVALID null' && $p->_sql === 'INVALID ?');
            assert($h->calls === $before);
            $db->enable_simulation(false); assert($db->unsafe_raw('DO 0') === 1);
            break;
        case 'busy':
            foreach (['stream','stream_prepared'] as $method) {
                $s = $db->$method($sql);
                foreach ($s as $row) { break; }
                $before = $h->calls;
                foreach ([fn() => $db->fetch($sql), fn() => $db->unsafe_raw('DO 0'), fn() => $db->fetch_prepared($sql),
                    fn() => $db->execute_prepared('DO 0'), fn() => $db->stream($sql), fn() => $db->stream_prepared($sql),
                    fn() => $db->transaction(fn() => null), fn() => $db->enable_simulation(true),
                    fn() => $db->enable_replay('/nonexistent/streaming-journal')] as $op) {
                    assert(streaming_failure($op) instanceof ExecutionFailure);
                }
                assert($h->calls === $before);
                $s->close(); $s->close(); assert($db->unsafe_raw('DO 0') === 1);
            }
            break;
        case 'rewind':
            $s = $db->stream($sql); $s->rewind(); $first = $s->current(); $s->next();
            assert($first === ['a' => null] && $s->current() === ['a' => 0] && $s->key() === 1);
            assert(streaming_failure(fn() => $s->rewind()) instanceof LogicException);
            $s->close(); assert(!$s->valid() && $s->current() === null);
            break;
        case 'fetch_failure':
            foreach (['stream','stream_prepared'] as $method) {
                $s = $db->$method($sql);
                if ($method === 'stream') { $GLOBALS['db_fixture_result_fetch_failure'] = true; }
                else { end($GLOBALS['db_fixture_statements'])->scenario['fail']['fetch'] = 'exception'; }
                $e = streaming_failure(fn() => $s->rewind());
                unset($GLOBALS['db_fixture_result_fetch_failure']);
                assert($e instanceof ExecutionFailure && !str_contains($e->getMessage(), 'SECRET'));
                assert(!$s->valid()); $s->close(); assert($db->unsafe_raw('DO 0') === 1);
            }
            break;
        case 'startup_failure':
            $GLOBALS['db_fixture_query_failures'][$sql] = 'exception';
            assert(streaming_failure(fn() => $db->stream($sql)) instanceof ExecutionFailure);
            unset($GLOBALS['db_fixture_query_failures'][$sql]);
            assert(streaming_failure(fn() => $db->stream('UPDATE t SET a=1')) instanceof ExecutionFailure);
            foreach (['prepare_result','result_metadata','fetch_fields','bind_result'] as $step) {
                $GLOBALS['db_fixture_prepared']['fail'] = [$step => 'exception'];
                assert(streaming_failure(fn() => $db->stream_prepared($sql)) instanceof ExecutionFailure);
                assert($db->unsafe_raw('DO 0') === 1);
            }
            break;
        case 'cleanup':
            $s = $db->stream_prepared($sql);
            end($GLOBALS['db_fixture_statements'])->scenario['fail']['stmt_close'] = 'warning';
            assert(streaming_failure(fn() => $s->close()) instanceof ExecutionFailure);
            assert(!$db->connected() && count($db->stream_cleanup_errors()) > 0);
            $before = $h->calls; $s->close(); $db->close(); assert($h->calls === $before);
            break;
        case 'raw_cleanup':
            $s = $db->stream($sql); $GLOBALS['db_fixture_result_free_failure'] = true;
            assert(streaming_failure(fn() => $s->close()) instanceof ExecutionFailure);
            unset($GLOBALS['db_fixture_result_free_failure']);
            assert(!$db->connected() && count($db->stream_cleanup_errors()) === 1);
            $s->close(); $db->close(); assert(count($db->stream_cleanup_errors()) === 1);
            break;
        case 'cleanup_primary':
            $original = new RuntimeException('original callback');
            $error = streaming_failure(function() use ($db,$sql,$original) {
                $db->transaction(function() use ($db,$sql,$original) {
                    $db->stream_prepared($sql);
                    end($GLOBALS['db_fixture_statements'])->scenario['fail']['stmt_close'] = 'exception';
                    throw $original;
                });
            });
            assert($error === $original && !$db->connected());
            assert(count($db->stream_cleanup_errors()) === 1 && count($db->transaction_cleanup_errors()) === 1);
            break;
        case 'disconnect_replay':
            $path = tempnam(sys_get_temp_dir(), 'streaming-disconnect-');
            try {
                $db->enable_replay($path); $db->unsafe_raw('DO 0');
                $s = $db->stream_prepared($sql);
                end($GLOBALS['db_fixture_statements'])->scenario['fail']['stmt_close'] = 'exception';
                assert(streaming_failure(fn() => $s->close()) instanceof ExecutionFailure);
                unset($db);
                $journal = file_get_contents($path); assert(substr_count($journal, 'DO 0') === 1);
                $s->close(); assert(file_get_contents($path) === $journal);
            } finally { unlink($path); }
            break;
        case 'transaction_fetch_failure':
            $error = streaming_failure(function() use ($db,$sql) {
                $db->transaction(function() use ($db,$sql) {
                    $s = $db->stream_prepared($sql);
                    end($GLOBALS['db_fixture_statements'])->scenario['fail']['fetch'] = 'exception';
                    assert(streaming_failure(fn() => $s->rewind()) instanceof ExecutionFailure);
                });
            });
            assert($error instanceof ExecutionFailure);
            assert(in_array('ROLLBACK AND NO CHAIN NO RELEASE', $h->queries, true));
            assert(!in_array('COMMIT AND NO CHAIN NO RELEASE', $h->queries, true));
            break;
        case 'db_close':
            $s = $db->stream($sql); $s->rewind(); $db->close(); $db->close(); $s->close();
            assert(!$s->valid() && !$db->connected());
            $db = DB::from(new mysqli()); $s = $db->stream_prepared($sql); $s->rewind(); unset($db);
            assert(!$s->valid()); $s->close();
            break;
        case 'transaction_exit': case 'transaction_exception': case 'transaction_busy':
            $original = new RuntimeException('callback'); $s = null;
            $e = streaming_failure(function() use ($db, $sql, $case, $original, &$s) {
                $db->transaction(function() use ($db, $sql, $case, $original, &$s) {
                    $s = $db->stream_prepared($sql);
                    if ($case === 'transaction_exception') { throw $original; }
                    if ($case === 'transaction_busy') {
                        assert(streaming_failure(fn() => $db->fetch($sql)) instanceof ExecutionFailure); $s->close();
                    }
                });
            });
            assert($case === 'transaction_exception' ? $e === $original : $e instanceof ExecutionFailure);
            assert(!$s->valid());
            assert(in_array('ROLLBACK AND NO CHAIN NO RELEASE', $h->queries, true));
            assert(!in_array('COMMIT AND NO CHAIN NO RELEASE', $h->queries, true));
            assert($db->transaction(fn() => 7) === 7);
            break;
        case 'transaction_consumed':
            assert($db->transaction(function() use ($db,$sql,$rows) {
                assert(iterator_to_array($db->stream_prepared($sql)) === $rows); return 7;
            }) === 7);
            assert(in_array('COMMIT AND NO CHAIN NO RELEASE', $h->queries, true));
            break;
        case 'replay':
            $path = tempnam(sys_get_temp_dir(), 'streaming-replay-');
            try {
                $db->enable_replay($path); iterator_to_array($db->stream($sql)); iterator_to_array($db->stream_prepared($sql));
                $db->unsafe_raw('DO 0'); $db->close();
                assert(!str_contains(file_get_contents($path), $sql)); assert(str_contains(file_get_contents($path), 'DO 0'));
            } finally { unlink($path); }
            break;
        case 'validation':
            $before = $h->calls;
            assert(streaming_failure(fn() => $db->stream('SELECT {missing}', [])) instanceof InvalidArgumentException);
            foreach ([[NAN], [new stdClass()], ['x' => 1], ["\xff"]] as $params) {
                assert(streaming_failure(fn() => $db->stream_prepared('SELECT ?', $params)) instanceof InvalidArgumentException);
            }
            assert($h->calls === $before);
            assert(streaming_failure(fn() => $db->stream_prepared('SELECT ?', [1])) instanceof ExecutionFailure);
            break;
        case 'ordinary':
            assert(!class_exists(ThreadFin\DB\StreamingRows::class, false));
            $before = count($h->calls); $db->unsafe_raw('DO 0'); $db->fetch($sql)->close();
            assert(count($h->calls) === $before + 2);
            assert(!class_exists(ThreadFin\DB\StreamingRows::class, false));
            break;
        case 'warnings':
            $warnings = [];
            set_error_handler(function($n,$m) use (&$warnings) { $warnings[] = $m; return true; });
            try {
                $s = $db->stream_prepared($sql);
                end($GLOBALS['db_fixture_statements'])->scenario['fail']['fetch'] = 'warning';
                assert(streaming_failure(fn() => $s->rewind()) instanceof ExecutionFailure);
                assert($warnings === []); trigger_error('restored', E_USER_WARNING); assert($warnings === ['restored']);
            } finally { restore_error_handler(); }
            break;
        default: throw new InvalidArgumentException('Unknown streaming case');
    }
    if (isset($db)) { $db->close(); } return true;
}
ob_start(); $value = null; $error = null;
try { $value = streaming_case($argv[1]); } catch (Throwable $e) { $error = get_class($e) . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine(); }
$output = ob_get_clean(); echo json_encode(compact('value','error','output'), JSON_THROW_ON_ERROR);
