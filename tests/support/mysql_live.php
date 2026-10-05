<?php declare(strict_types=1);
/** Real-mysqli integration child. Never loads the regression stand-in. */
error_reporting(E_ALL & ~E_DEPRECATED);
define('SQL_ERROR_FILE', false);
require __DIR__ . '/../../core.php';
require __DIR__ . '/../../db.php';

use ThreadFin\DB\DB;
use ThreadFin\DB\Credentials;
use function ThreadFin\DB\quote;
use function ThreadFin\DB\dump_database;
use function ThreadFin\DB\stream_output_fn;

function db_live_handle(): mysqli {
    return new mysqli('localhost', getenv('THREADFIN_TEST_MYSQL_USER') ?: 'root', '',
        getenv('THREADFIN_TEST_MYSQL_DATABASE'), null, getenv('THREADFIN_TEST_MYSQL_SOCKET'));
}
function db_live_script(mysqli $handle, string $sql): void {
    $handle->multi_query($sql);
    do {
        $result = $handle->store_result();
        if ($result instanceof mysqli_result) { $result->free(); }
        if (!$handle->more_results()) { break; }
        $handle->next_result();
    } while (true);
}
function db_live_rows(mysqli $handle, string $table): array {
    return $handle->query("SELECT CAST(id AS CHAR) AS id, HEX(name) AS name, nil FROM `$table` ORDER BY id")->fetch_all(MYSQLI_ASSOC);
}
function db_live_case(string $case, mysqli $admin, string $table): mixed {
    switch ($case) {
        case 'cursor':
            $handle = db_live_handle();
            $handle->query("SET SESSION sql_mode = 'NO_BACKSLASH_ESCAPES,ANSI_QUOTES'");
            $db = DB::from($handle);
            $settings = $handle->query('SELECT @@sql_mode AS mode, @@character_set_client AS charset')->fetch_assoc();
            $result = $db->fetch("SELECT '1' AS id UNION ALL SELECT '2' UNION ALL SELECT '3'");
            $result->next();
            $random = $result['02']['id'];
            $current = $result->current()['id'];
            $result->next();
            $next = $result->current()['id'];
            $result->next();
            $atEof = [$result['0']['id'], $result->valid(), $result->current()];
            $snapshot = $result->as_array();
            $result->close();
            $closed = [count($result), $result->valid(), $result->current(), iterator_to_array($result)];
            $empty = $db->fetch("SELECT '1' WHERE FALSE");
            $empty->rewind();
            $db->close();
            return [$settings, [$random, $current, $next], $atEof, $snapshot, $closed, $empty->current()];
        case 'replay':
            $admin->query("CREATE TABLE `$table` (id INT PRIMARY KEY, name VARCHAR(100) CHARACTER SET utf8mb4, nil INT NULL) ENGINE=InnoDB");
            $path = tempnam(sys_get_temp_dir(), 'threadfin-live-journal-');
            try {
                $handle = db_live_handle();
                $handle->autocommit(false);
                $db = DB::from($handle)->enable_replay($path);
                $db->unsafe_raw("INSERT INTO `$table` VALUES (1, 'pending autocommit', NULL)");
                $db->close();
                $db = DB::from(db_live_handle())->enable_replay($path);
                $db->unsafe_raw('BEGIN');
                $db->unsafe_raw("INSERT INTO `$table` VALUES (2, 'pending begin', NULL)");
                $db->close();
                $db = DB::from(db_live_handle())->enable_replay($path);
                $text = quote("café O'Reilly \\ 😀");
                foreach (["INSERT INTO `$table` VALUES (3, $text, NULL) -- trailing comment", 'BEGIN',
                    "INSERT INTO `$table` VALUES (4, 'committed', 0)", 'SAVEPOINT checkpoint',
                    "INSERT INTO `$table` VALUES (5, 'rolled back', 0)", 'ROLLBACK TO SAVEPOINT checkpoint', 'COMMIT'] as $sql) {
                    if ($db->unsafe_raw($sql) !== 1) { throw new RuntimeException('Live write failed: ' . implode(';', $db->errors)); }
                }
                $db->close();
                $journal = file_get_contents($path);
                $db->close();
                $once = $journal === file_get_contents($path);
                $source = db_live_rows($admin, $table);
                $admin->query("TRUNCATE TABLE `$table`");
                $target = db_live_handle();
                try {
                    $target->autocommit(false);
                    $target->query("SET SESSION sql_mode = 'NO_BACKSLASH_ESCAPES,ANSI_QUOTES'");
                    db_live_script($target, $journal);
                    $replayed = db_live_rows($target, $table);
                    $autocommit = $target->query('SELECT @@autocommit AS ac')->fetch_assoc()['ac'];
                } finally { $target->close(); }
                return [$source, $replayed, (string)$autocommit, $once];
            } finally { unlink($path); }
        case 'dump':
            $admin->query("CREATE TABLE `$table` (id INT PRIMARY KEY, name VARCHAR(100) CHARACTER SET utf8mb4, nil INT NULL) ENGINE=InnoDB");
            $db = DB::from(db_live_handle());
            $bulk = $db->bulk_fn($table, ['id', 'name', 'nil']);
            for ($id = 1; $id <= 301; $id++) {
                $bulk(['id' => $id, 'name' => $id === 1 ? '00123' : "row $id café O'Reilly \\ 😀\0\n\r\t", 'nil' => $id % 2 ? null : 0]);
            }
            $bulk();
            if ($db->errors) { throw new RuntimeException(implode(';', $db->errors)); }
            $db->close();
            $source = db_live_rows($admin, $table);
            $credentials = new Credentials(getenv('THREADFIN_TEST_MYSQL_USER') ?: 'root', '', 'localhost', getenv('THREADFIN_TEST_MYSQL_DATABASE'));
            $stream = fopen('php://memory', 'w+');
            try {
                $offsets = dump_database($credentials, $credentials->db_name, fn($chunk) =>
                    stream_output_fn($chunk, $stream, fn($s, $data) => fwrite($s, substr($data, 0, 11))));
                rewind($stream);
                $dump = stream_get_contents($stream);
            } finally { fclose($stream); }
            $admin->query("DROP TABLE `$table`");
            db_live_script($admin, $dump);
            $restored = db_live_rows($admin, $table);
            $tiny = '';
            $stopped = dump_database($credentials, $credentials->db_name, function($chunk) use (&$tiny): int {
                $tiny .= $chunk; return strlen($chunk);
            }, 1);
            return [count($source), $source === $restored, $offsets[0]->is_table_complete(),
                $tiny, $stopped[0]->is_table_complete(), $stopped[0]->offset];
        case 'connection':
            $db = DB::connect('localhost', getenv('THREADFIN_TEST_MYSQL_USER') ?: 'root', '', getenv('THREADFIN_TEST_MYSQL_DATABASE'));
            $connected = $db->connected();
            $charset = $db->fetch('SELECT @@character_set_client AS charset')->col('charset')->value();
            $write = $db->unsafe_raw('THIS IS NOT VALID SQL');
            $read = $db->fetch('SELECT missing_column');
            $errors = count($db->errors);
            $db->close();
            return [$connected, $charset, $write, count($read), $errors, $db->connected()];
        default: throw new InvalidArgumentException('Unknown live case');
    }
}

ob_start();
$admin = null;
$ownedDatabase = false;
$table = 'threadfin_live_' . bin2hex(random_bytes(6));
try {
    if (!extension_loaded('mysqli') || getenv('THREADFIN_TEST_MYSQL_ALLOW_SCHEMA_CHANGES') !== '1') {
        throw new RuntimeException('Real mysqli and THREADFIN_TEST_MYSQL_ALLOW_SCHEMA_CHANGES=1 are required');
    }
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $admin = db_live_handle();
    if ($admin->query('SHOW TABLES')->num_rows !== 0) {
        throw new RuntimeException('Lifecycle integration tests require an EMPTY disposable database');
    }
    $ownedDatabase = true;
    $value = db_live_case($argv[1] ?? '', $admin, $table);
    $report = ['value' => $value, 'error' => null];
} catch (Throwable $error) {
    $report = ['value' => null, 'error' => get_class($error) . ': ' . $error->getMessage()];
} finally {
    if ($admin) {
        if ($ownedDatabase) { $admin->query("DROP TABLE IF EXISTS `$table`"); }
        $admin->close();
    }
    $report['output'] = ob_get_clean();
}
echo json_encode($report, JSON_THROW_ON_ERROR);
