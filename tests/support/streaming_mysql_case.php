<?php declare(strict_types=1);
/** Real driver streaming gates; only the parent's disposable socket server. */
error_reporting(E_ALL & ~E_DEPRECATED);
define('SQL_ERROR_FILE', false);
require __DIR__ . '/../../core.php';
require __DIR__ . '/../../db.php';
use ThreadFin\DB\DB;
use ThreadFin\DB\BinaryParameter;
use ThreadFin\DB\ExecutionFailure;
function streaming_native_handle(): mysqli {
    return new mysqli('localhost', getenv('THREADFIN_TEST_MYSQL_USER') ?: 'root', '',
        getenv('THREADFIN_TEST_MYSQL_DATABASE'), null, getenv('THREADFIN_TEST_MYSQL_SOCKET'));
}
function streaming_native_failure(callable $fn): Throwable {
    try { $fn(); } catch (Throwable $e) { return $e; }
    throw new RuntimeException('Expected native streaming failure');
}
function streaming_native_case(string $case, mysqli $admin, string $table): bool {
    $h = streaming_native_handle(); $db = DB::from($h);
    $sql = "SELECT id,txt,bin,n FROM `$table` ORDER BY id";
    try {
        foreach ([['00123', new BinaryParameter("\xff\0"), 0], ['', new BinaryParameter(''), null], ['é😀', null, 7]] as $row) {
            $db->execute_prepared("INSERT INTO `$table` (txt,bin,n) VALUES (?,?,?)", $row);
        }
        switch ($case) {
            case 'raw': case 'prepared':
                $s = $case === 'raw' ? $db->stream("SELECT id,txt,bin,n FROM {!table} WHERE id>{lo} ORDER BY id", ['table' => "`$table`", 'lo' => 0])
                    : $db->stream_prepared("SELECT id,txt,bin,n FROM `$table` WHERE id>? ORDER BY id", [0]);
                $rows = iterator_to_array($s);
                $ids = $case === 'raw' ? ['1','2','3'] : [1,2,3];
                assert(array_column($rows,'id') === $ids);
                assert(array_column($rows,'txt') === ['00123','','é😀']);
                assert(array_column($rows,'bin') === ["\xff\0",'',null]);
                assert(array_column($rows,'n') === ($case === 'raw' ? ['0',null,'7'] : [0,null,7]));
                assert(streaming_native_failure(fn() => $s->rewind()) instanceof LogicException);
                $s->close(); $s->close(); assert(count($db->fetch('SELECT 1')) === 1);
                if ($case === 'prepared') {
                    $p = $db->stream_prepared('SELECT ? AS a, ? AS b, ? AS c, ? AS d', [null, false, '00123', new BinaryParameter("\xff\0")]);
                    assert(iterator_to_array($p) === [['a'=>null,'b'=>0,'c'=>'00123','d'=>"\xff\0"]]);
                }
                break;
            case 'cancel':
                foreach (['stream','stream_prepared'] as $method) {
                    $s = $db->$method($sql); foreach ($s as $first) { break; }
                    foreach ([fn() => $db->fetch('SELECT 1'), fn() => $db->unsafe_raw('DO 0'), fn() => $db->fetch_prepared('SELECT 1'),
                        fn() => $db->execute_prepared('DO 0'), fn() => $db->stream('SELECT 1'), fn() => $db->stream_prepared('SELECT 1'),
                        fn() => $db->enable_simulation(true), fn() => $db->transaction(fn() => null)] as $op) {
                        $error = streaming_native_failure($op);
                        assert($error instanceof ExecutionFailure && $error->getMessage() === 'Connection is busy with an open stream' && $error->errno === 0);
                    }
                    $s->next(); assert($first['txt'] === '00123' && $s->current()['txt'] === '');
                    $s->close(); $s->close(); assert(count($db->fetch('SELECT 1')) === 1);
                }
                break;
            case 'empty':
                foreach (['stream','stream_prepared'] as $method) {
                    $s = $db->$method("SELECT id FROM `$table` WHERE FALSE"); assert(iterator_to_array($s) === []);
                    $s->close(); assert(count($db->fetch('SELECT 1')) === 1);
                }
                break;
            case 'simulation':
                $db->enable_simulation(true);
                $before = (int)$h->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch_assoc()['Value'];
                $s = $db->stream('INVALID {value}', ['value' => null]); $p = $db->stream_prepared('INVALID ? ?', [1]);
                assert(iterator_to_array($s) === [] && iterator_to_array($p) === []);
                $after = (int)$h->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch_assoc()['Value'];
                assert($after - $before === 1);
                $db->enable_simulation(false); assert(count($db->fetch('SELECT 1')) === 1);
                break;
            case 'db_close':
                foreach (['stream','stream_prepared'] as $method) {
                    $s = $db->$method($sql); $s->rewind(); assert($s->valid());
                    $db->close(); $db->close(); $s->close(); assert(!$s->valid() && $s->current() === null);
                    $db = DB::from(streaming_native_handle());
                }
                break;
            case 'transaction':
                foreach (['stream','stream_prepared'] as $method) {
                    $s = null;
                    $error = streaming_native_failure(function() use ($db,$method,$sql,$table,&$s) {
                        $db->transaction(function() use ($db,$method,$sql,$table,&$s) {
                            $db->execute_prepared("UPDATE `$table` SET txt=? WHERE id=1", ['rollback']);
                            $s = $db->$method($sql); foreach ($s as $row) { break; }
                        });
                    });
                    assert($error instanceof ExecutionFailure && !$s->valid());
                    assert($db->fetch_prepared("SELECT txt FROM `$table` WHERE id=1")->as_array() === [['txt'=>'00123']]);
                    $original = new RuntimeException('original');
                    assert(streaming_native_failure(function() use ($db,$sql,$original,$method) {
                        $db->transaction(function() use ($db,$sql,$original,$method) { $db->$method($sql); throw $original; });
                    }) === $original);
                    assert($db->transaction(function() use ($db,$method,$sql) { foreach ($db->$method($sql) as $row) {} return 7; }) === 7);
                }
                break;
            case 'fetch_raw_failure': case 'fetch_prepared_failure':
                // Far more than the socket buffers, so a killed connection fails during fetch,
                // not merely after all rows have already arrived in client memory.
                $admin->query("UPDATE `$table` SET bin=REPEAT('x',32768)");
                for ($i=0;$i<7;$i++) { $admin->query("INSERT INTO `$table` (txt,bin,n) SELECT txt,bin,n FROM `$table`"); }
                $method = $case === 'fetch_raw_failure' ? 'stream' : 'stream_prepared';
                $id = $h->thread_id; $s = $db->$method($sql); $s->rewind(); assert($s->valid());
                $admin->query('KILL CONNECTION ' . $id);
                $error = streaming_native_failure(function() use ($s) { while ($s->valid()) { $s->next(); } });
                assert($error instanceof ExecutionFailure && $error->transactionInvalidated);
                assert(!$s->valid()); $s->close(); $s->close(); $db->close();
                $db = DB::from(streaming_native_handle()); assert(count($db->fetch('SELECT 1')) === 1);
                break;
            case 'errors':
                foreach ([MYSQLI_REPORT_OFF, MYSQLI_REPORT_ERROR, MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT] as $mode) {
                    mysqli_report($mode); $warnings = [];
                    set_error_handler(function($n,$m) use (&$warnings) { $warnings[] = $m; return true; });
                    try {
                        foreach (['stream','stream_prepared'] as $method) {
                            assert(streaming_native_failure(fn() => $db->$method('SELECT CANARY_missing')) instanceof ExecutionFailure);
                            assert(streaming_native_failure(fn() => $db->$method("UPDATE `$table` SET n=0")) instanceof ExecutionFailure);
                            assert(count($db->fetch('SELECT 1')) === 1);
                        }
                        assert($warnings === []); trigger_error('restored', E_USER_WARNING); assert($warnings === ['restored']);
                    } finally { restore_error_handler(); }
                }
                break;
            case 'replay':
                $path = tempnam(sys_get_temp_dir(), 'streaming-native-replay-');
                try {
                    $db->enable_replay($path);
                    foreach ($db->stream($sql) as $row) {} foreach ($db->stream_prepared($sql) as $row) {}
                    $db->unsafe_raw('DO 0'); $db->close();
                    assert(!str_contains(file_get_contents($path), $sql)); assert(str_contains(file_get_contents($path), 'DO 0'));
                } finally { unlink($path); }
                break;
            default: throw new InvalidArgumentException('Unknown native streaming case');
        }
    } finally { mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT); $db->close(); }
    return true;
}
ob_start(); $admin = null; $table = null;
try {
    if (!getenv('THREADFIN_TEST_MYSQL_SOCKET') || !getenv('THREADFIN_TEST_MYSQL_DATABASE') || getenv('THREADFIN_TEST_MYSQL_ALLOW_SCHEMA_CHANGES') !== '1') {
        throw new RuntimeException('Explicit disposable socket/database/schema permission required');
    }
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $admin = streaming_native_handle();
    if ($admin->query('SHOW TABLES')->num_rows !== 0) { throw new RuntimeException('Streaming tests require an empty disposable database'); }
    $table = 'threadfin_streaming_' . bin2hex(random_bytes(6));
    $admin->query("CREATE TABLE `$table` (id INT PRIMARY KEY AUTO_INCREMENT, txt VARCHAR(100) CHARACTER SET utf8mb4, bin MEDIUMBLOB, n INT) ENGINE=InnoDB");
    $report = ['value'=>streaming_native_case($argv[1],$admin,$table), 'error'=>null];
} catch (Throwable $e) { $report = ['value'=>null, 'error'=>get_class($e) . ': ' . $e->getMessage() . ' at line ' . $e->getLine()]; }
finally {
    if ($admin !== null) {
        if ($table !== null) { $admin->query("DROP TABLE IF EXISTS `$table`"); }
        $admin->close();
    }
}
$report['output'] = ob_get_clean(); echo json_encode($report, JSON_THROW_ON_ERROR);
