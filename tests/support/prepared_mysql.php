<?php declare(strict_types=1);
/** Native prepared proof, only on the parent's explicitly configured disposable server. */
error_reporting(E_ALL & ~E_DEPRECATED);
define('SQL_ERROR_FILE', false);
require __DIR__ . '/../../core.php';
require __DIR__ . '/../../db.php';
use ThreadFin\DB\DB;
use ThreadFin\DB\BinaryParameter;
use ThreadFin\DB\ExecutionFailure;
use const ThreadFin\DB\DB_FETCH_INSERT_ID;
use const ThreadFin\DB\DB_FETCH_NUM_ROWS;
function prepared_native_handle(): mysqli {
    return new mysqli('localhost', getenv('THREADFIN_TEST_MYSQL_USER') ?: 'root', '',
        getenv('THREADFIN_TEST_MYSQL_DATABASE'), null, getenv('THREADFIN_TEST_MYSQL_SOCKET'));
}
function prepared_native_failure(callable $fn): Throwable {
    try { $fn(); } catch (Throwable $e) { return $e; }
    throw new RuntimeException('Expected native prepared failure');
}
function prepared_native_counters(mysqli $handle): array {
    return $handle->query("SHOW SESSION STATUS LIKE 'Com_stmt_%'")->fetch_all(MYSQLI_ASSOC);
}
function prepared_native_case(string $case, mysqli $admin, string $table): bool {
    $handle = prepared_native_handle(); $db = DB::from($handle);
    try {
        switch ($case) {
            case 'roundtrip':
                $payload = "SECRET' OR 1=1; DROP TABLE `$table`; -- \\" . "\0\n\r\x1aé😀";
                $binary = str_repeat("\0\xff\x80'\\é", 25000);
                assert($db->execute_prepared("INSERT INTO `$table` (txt,bin,n,d) VALUES (?,?,?,?)", [$payload, new BinaryParameter($binary), 0, 1.25], DB_FETCH_INSERT_ID) === 1);
                assert($db->execute_prepared("INSERT INTO `$table` (txt,bin,n,d) VALUES (?,?,?,?)", ['00123', new BinaryParameter(''), false, 0.0]) === 1);
                assert($db->execute_prepared("INSERT INTO `$table` (txt,bin,n,d) VALUES (?,?,?,?)", ['', null, null, null]) === 1);
                $rows = $db->fetch_prepared("SELECT txt,bin,n,d FROM `$table` ORDER BY id")->as_array();
                assert($rows === [
                    ['txt' => $payload, 'bin' => $binary, 'n' => 0, 'd' => 1.25],
                    ['txt' => '00123', 'bin' => '', 'n' => 0, 'd' => 0.0],
                    ['txt' => '', 'bin' => null, 'n' => null, 'd' => null],
                ]);
                $result = $db->fetch_prepared("SELECT id,txt FROM `$table` WHERE txt=?", [$payload]);
                assert(count($result) === 1 && $result[0]['txt'] === $payload);
                $meta = new ReflectionProperty($result, '_sql');
                assert($meta->getValue($result) === "SELECT id,txt FROM `$table` WHERE txt=?");
                $types = $db->fetch_prepared('SELECT ? AS n, ? AS f, ? AS t, ? AS i, ? AS d, ? AS s, ? AS b', [null, false, true, 0, 1.25, '00123', new BinaryParameter("\xff\0")])->as_array();
                assert($types === [['n' => null, 'f' => 0, 't' => 1, 'i' => 0, 'd' => 1.25, 's' => '00123', 'b' => "\xff\0"]]);
                assert($db->fetch_prepared('SELECT ? AS lo, ? AS hi', [PHP_INT_MIN, PHP_INT_MAX])->as_array()
                    === [['lo' => PHP_INT_MIN, 'hi' => PHP_INT_MAX]]);
                $all = $db->fetch_prepared("SELECT id FROM `$table` ORDER BY id");
                $all->next(); assert($all->current() === ['id' => 2] && $all[0] === ['id' => 1]);
                assert($all->as_array() === [['id' => 1], ['id' => 2], ['id' => 3]]);
                $all->close(); $all->close(); assert(!isset($all[0]));
                break;
            case 'modes':
                assert($db->execute_prepared("INSERT INTO `$table` (txt) VALUES (?)", ['one'], DB_FETCH_NUM_ROWS) === 1);
                assert($db->execute_prepared("INSERT INTO `$table` (txt) VALUES (?)", ['two'], DB_FETCH_INSERT_ID) === 2);
                assert($db->execute_prepared("UPDATE `$table` SET txt=? WHERE id=?", ['changed', 1], DB_FETCH_NUM_ROWS) === 1);
                assert($db->execute_prepared("UPDATE `$table` SET txt=? WHERE id=?", ['changed', 1], DB_FETCH_NUM_ROWS) === 0);
                assert($db->execute_prepared("UPDATE `$table` SET txt=? WHERE id=?", ['again', 1], DB_FETCH_INSERT_ID) === -1);
                assert($db->execute_prepared("ALTER TABLE `$table` ADD extra INT", [], DB_FETCH_INSERT_ID) === -1);
                assert($db->fetch_prepared("SELECT id FROM `$table` WHERE id=?", [-1])->as_array() === []);
                break;
            case 'errors':
                foreach ([MYSQLI_REPORT_OFF, MYSQLI_REPORT_ERROR, MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT] as $reporting) {
                    mysqli_report($reporting);
                    foreach ([
                        fn() => $db->fetch_prepared('SELECT ? AS a, ? AS b', [1]),
                        fn() => $db->fetch_prepared('SELECT ? AS a', [1,2]),
                        fn() => $db->fetch_prepared('INVALID NATIVE SQL', []),
                        fn() => $db->fetch_prepared("UPDATE `$table` SET txt=?", ['x']),
                    ] as $fn) {
                        $error = prepared_native_failure($fn);
                        assert($error instanceof ExecutionFailure && $error->getPrevious() === null);
                        assert($db->fetch_prepared('SELECT 7 AS n')->as_array() === [['n' => 7]]);
                    }
                    assert($db->execute_prepared("INSERT INTO `$table` (id,txt) VALUES (?,?)", [17, 'SECRET']) === 1);
                    $error = prepared_native_failure(fn() => $db->execute_prepared("INSERT INTO `$table` (id,txt) VALUES (?,?)", [17, 'SECRET']));
                    assert($error instanceof ExecutionFailure && $error->errno === 1062 && $error->sqlstate === '23000');
                    assert(!str_contains($error->getMessage(), 'SECRET') && !str_contains(json_encode([$db->logs, $db->errors]), 'SECRET'));
                    $db->execute_prepared("DELETE FROM `$table`");
                }
                assert($db->prepared_cleanup_errors() === []);
                break;
            case 'validation':
                $resource = fopen('php://memory', 'r+');
                try {
                    $before = prepared_native_counters($handle);
                    foreach ([[1 => 1], ['a' => 1], [new stdClass()], [[]], [$resource], [INF], [NAN], ["\xff"]] as $params) {
                        assert(prepared_native_failure(fn() => $db->fetch_prepared('SELECT ?', $params)) instanceof InvalidArgumentException);
                    }
                    assert(prepared_native_failure(fn() => $db->execute_prepared('SELECT 1', [], 999)) instanceof InvalidArgumentException);
                    assert(prepared_native_counters($handle) === $before);
                    assert($db->fetch_prepared('SELECT ? AS txt', ['é😀'])->as_array() === [['txt' => 'é😀']]);
                } finally { fclose($resource); }
                break;
            case 'simulation':
                $before = prepared_native_counters($handle); $db->enable_simulation(true);
                assert($db->execute_prepared("INSERT INTO `$table` (txt) VALUES (?)", ['SECRET']) === 1);
                assert($db->execute_prepared('INVALID ? ?', ['SECRET'], DB_FETCH_NUM_ROWS) === 0);
                assert($db->execute_prepared('INVALID ? ?', ['SECRET'], DB_FETCH_INSERT_ID) === 0);
                assert($db->fetch_prepared('INVALID ? ?', [new BinaryParameter("\xff")])->as_array() === []);
                assert(prepared_native_counters($handle) === $before);
                assert(!str_contains(json_encode([$db->logs, $db->errors, $db->last_stmt]), 'SECRET'));
                assert((int)$admin->query("SELECT COUNT(*) FROM `$table`")->fetch_row()[0] === 0);
                $db->enable_simulation(false);
                assert($db->fetch_prepared('SELECT ? AS value', [17])->as_array() === [['value' => 17]]);
                break;
            case 'transaction':
                $error = prepared_native_failure(fn() => $db->transaction(function($db) use ($table) {
                    $db->execute_prepared("INSERT INTO `$table` (id,txt) VALUES (?,?)", [1, 'first']);
                    prepared_native_failure(fn() => $db->execute_prepared("INSERT INTO `$table` (id,txt) VALUES (?,?)", [1, 'SECRET']));
                    $db->errors = [];
                }));
                assert($error instanceof ExecutionFailure && $error->errno === 1062);
                assert((int)$admin->query("SELECT COUNT(*) FROM `$table`")->fetch_row()[0] === 0);
                $db->transaction(function($db) use ($table) {
                    $db->execute_prepared("INSERT INTO `$table` (id) VALUES (?)", [1]);
                    $error = prepared_native_failure(fn() => $db->transaction(function($db) use ($table) {
                        $db->execute_prepared("INSERT INTO `$table` (id) VALUES (?)", [2]);
                        $db->fetch_prepared('SELECT missing_prepared_column');
                    }));
                    assert($error instanceof ExecutionFailure);
                    $db->execute_prepared("INSERT INTO `$table` (id) VALUES (?)", [3]);
                });
                assert($admin->query("SELECT id FROM `$table` ORDER BY id")->fetch_all(MYSQLI_ASSOC) === [['id' => '1'], ['id' => '3']]);
                $before = prepared_native_counters($handle);
                assert(prepared_native_failure(fn() => $db->transaction(fn($db) => $db->execute_prepared("ALTER TABLE `$table` ADD extra INT"))) instanceof ExecutionFailure);
                assert(prepared_native_counters($handle) === $before);
                break;
            case 'replay':
                $path = tempnam(sys_get_temp_dir(), 'threadfin-prepared-live-');
                try {
                    $db->enable_replay($path); $before = prepared_native_counters($handle);
                    assert(prepared_native_failure(fn() => $db->execute_prepared("INSERT INTO `$table` (txt) VALUES (?)", ['SECRET'])) instanceof ExecutionFailure);
                    assert(prepared_native_failure(fn() => $db->execute_prepared('SELECT 1')) instanceof ExecutionFailure);
                    assert(prepared_native_counters($handle) === $before);
                    assert($db->fetch_prepared('SELECT ? AS a', ['SECRET'])->as_array() === [['a' => 'SECRET']]);
                    $db->enable_simulation(true); $before = prepared_native_counters($handle);
                    assert($db->execute_prepared("INSERT INTO `$table` (txt) VALUES (?)", ['SECRET']) === 1);
                    assert(prepared_native_counters($handle) === $before);
                    $db->close(); assert(file_get_contents($path) === '');
                    assert((int)$admin->query("SELECT COUNT(*) FROM `$table`")->fetch_row()[0] === 0);
                } finally { unlink($path); }
                break;
            case 'warnings_off': case 'warnings_report': case 'warnings_strict':
                $admin->query("ALTER TABLE `$table` ADD UNIQUE KEY secret (txt(80))");
                $reporting = $case === 'warnings_off' ? MYSQLI_REPORT_OFF
                    : ($case === 'warnings_report' ? MYSQLI_REPORT_ERROR : MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
                mysqli_report($reporting);
                $driver = new mysqli_driver();
                foreach ([false, true] as $custom) {
                    $warnings = [];
                    $handler = static function(int $number, string $message) use (&$warnings): bool { $warnings[] = [$number, $message]; return true; };
                    if ($custom) { set_error_handler($handler); }
                    $settings = ini_get_all(null, false);
                    $check = static function() use ($custom, $handler, &$warnings, $settings, $driver, $reporting): void {
                        assert($warnings === []);
                        assert(set_error_handler($handler) === ($custom ? $handler : null)); restore_error_handler();
                        assert(ini_get_all(null, false) === $settings && $driver->report_mode === $reporting);
                    };
                    try {
                        assert($db->execute_prepared("INSERT INTO `$table` (txt) VALUES (?)", ['CANARY_BOUND_SECRET']) === 1);
                        $check();
                        $error = prepared_native_failure(fn() => $db->execute_prepared("INSERT INTO `$table` (txt) VALUES (?)", ['CANARY_BOUND_SECRET']));
                        assert($error instanceof ExecutionFailure && $error->errno === 1062 && $error->sqlstate === '23000');
                        assert($error->getPrevious() === null && !str_contains($error->getMessage(), 'CANARY_BOUND_SECRET'));
                        $check();
                        assert($db->fetch_prepared('SELECT ? AS a', [7])->as_array() === [['a' => 7]]); $check();
                        $error = prepared_native_failure(fn() => $db->fetch_prepared('SELECT CANARY_BOUND_SECRET_missing_column'));
                        assert($error instanceof ExecutionFailure && $error->errno === 1054 && $error->sqlstate === '42S22'); $check();
                        $error = prepared_native_failure(fn() => $db->transaction(function($db) use ($table, $check) {
                            $db->execute_prepared("INSERT INTO `$table` (txt) VALUES (?)", ['transient']); $check();
                            prepared_native_failure(fn() => $db->execute_prepared("INSERT INTO `$table` (txt) VALUES (?)", ['CANARY_BOUND_SECRET'])); $check();
                        }));
                        assert($error instanceof ExecutionFailure && $error->errno === 1062 && $error->sqlstate === '23000'); $check();
                        assert((int)$admin->query("SELECT COUNT(*) FROM `$table`")->fetch_row()[0] === 1);
                        assert(!str_contains(json_encode([$db->logs, $db->errors]), 'CANARY_BOUND_SECRET'));
                        $db->execute_prepared("DELETE FROM `$table`"); $check();
                        if ($custom) {
                            trigger_error('outside prepared operation', E_USER_WARNING);
                            assert($warnings === [[E_USER_WARNING, 'outside prepared operation']]);
                        }
                    } finally { if ($custom) { restore_error_handler(); } }
                }
                break;
            case 'ordinary':
                $before = prepared_native_counters($handle);
                $db->fetch('SELECT 1'); $db->unsafe_raw("UPDATE `$table` SET n=1");
                assert(prepared_native_counters($handle) === $before);
                break;
            default: throw new InvalidArgumentException('Unknown native prepared case');
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
    $admin = prepared_native_handle();
    if ($admin->query('SHOW TABLES')->num_rows !== 0) { throw new RuntimeException('Native prepared tests require an empty disposable database'); }
    $table = 'threadfin_prepared_' . bin2hex(random_bytes(6));
    $admin->query("CREATE TABLE `$table` (id BIGINT PRIMARY KEY AUTO_INCREMENT, txt VARCHAR(1000) CHARACTER SET utf8mb4, bin MEDIUMBLOB, n INT, d DOUBLE) ENGINE=InnoDB");
    $report = ['value' => prepared_native_case($argv[1], $admin, $table), 'error' => null];
} catch (Throwable $e) { $report = ['value' => null, 'error' => get_class($e) . ': ' . $e->getMessage()]; }
finally {
    if ($admin !== null) {
        if ($table !== null) { $admin->query("DROP TABLE IF EXISTS `$table`"); }
        $admin->close();
    }
}
$report['output'] = ob_get_clean();
echo json_encode($report, JSON_THROW_ON_ERROR);
