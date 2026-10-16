<?php declare(strict_types=1);
/**
 * Opt-in disposable-server benchmark. Run with php -n -d extension=mysqli and
 * THREADFIN_STREAMING_BENCHMARK=1 plus the native-test socket/database/schema flags.
 * Fixture: 32768 rows x 8192 payload bytes (256 MiB), created server-side. Each raw
 * and prepared reader is a NEW PHP process with a 32 MiB limit; it never collects
 * rows. Reports baseline/peak deltas, counts/bytes, versions and elapsed time.
 * No production use; parent owns server lifecycle. No benchmark runs by default.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
define('SQL_ERROR_FILE', false);
if (getenv('THREADFIN_STREAMING_BENCHMARK') !== '1' || !getenv('THREADFIN_TEST_MYSQL_SOCKET')
    || !getenv('THREADFIN_TEST_MYSQL_DATABASE') || getenv('THREADFIN_TEST_MYSQL_ALLOW_SCHEMA_CHANGES') !== '1') {
    throw new RuntimeException('Explicit streaming benchmark and disposable socket/database/schema permission required');
}
require __DIR__ . '/../core.php';
require __DIR__ . '/../db.php';
use ThreadFin\DB\DB;
function streaming_benchmark_handle(): mysqli {
    return new mysqli('localhost', getenv('THREADFIN_TEST_MYSQL_USER') ?: 'root', '',
        getenv('THREADFIN_TEST_MYSQL_DATABASE'), null, getenv('THREADFIN_TEST_MYSQL_SOCKET'));
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$reader = ($argv[1] ?? '') === '--reader';
if ($reader) {
    $table = $argv[2] ?? ''; $mode = $argv[3] ?? '';
    if (!preg_match('/\Athreadfin_stream_bench_[a-f0-9]{12}\z/', $table) || !in_array($mode, ['raw','prepared'], true)) {
        throw new InvalidArgumentException('Invalid benchmark reader arguments');
    }
    $db = DB::from(streaming_benchmark_handle());
    $baseline = memory_get_usage(false); $allocated = memory_get_usage(true);
    $start = hrtime(true); $count = 0; $bytes = 0;
    $rows = $mode === 'raw' ? $db->stream("SELECT id,payload FROM `$table`")
        : $db->stream_prepared("SELECT id,payload FROM `$table`");
    try {
        foreach ($rows as $row) { $count++; $bytes += strlen($row['payload']); }
    } finally { $rows->close(); }
    $report = ['mode'=>$mode, 'pid'=>getmypid(), 'rows'=>$count, 'payload_bytes'=>$bytes,
        'baseline_bytes'=>$baseline, 'peak_bytes'=>memory_get_peak_usage(false),
        'peak_delta_bytes'=>memory_get_peak_usage(false)-$baseline,
        'baseline_allocated_bytes'=>$allocated, 'peak_allocated_bytes'=>memory_get_peak_usage(true),
        'peak_allocated_delta_bytes'=>memory_get_peak_usage(true)-$allocated,
        'seconds'=>(hrtime(true)-$start)/1e9, 'memory_limit'=>ini_get('memory_limit')];
    $db->close();
    if ($count !== 32768 || $bytes !== 268435456 || $report['peak_delta_bytes'] > 16*1024*1024) {
        throw new RuntimeException('Streaming memory/count benchmark failed');
    }
    echo json_encode($report, JSON_THROW_ON_ERROR); exit;
}
$admin = streaming_benchmark_handle(); $table = null;
try {
    if ($admin->query('SHOW TABLES')->num_rows !== 0) { throw new RuntimeException('Benchmark requires an empty disposable database'); }
    $table = 'threadfin_stream_bench_' . bin2hex(random_bytes(6));
    $admin->query("CREATE TABLE `$table` (id INT PRIMARY KEY AUTO_INCREMENT,payload BLOB NOT NULL) ENGINE=InnoDB");
    $admin->query("INSERT INTO `$table` (payload) VALUES (REPEAT('x',8192))");
    for ($i=0;$i<15;$i++) { $admin->query("INSERT INTO `$table` (payload) SELECT payload FROM `$table`"); }
    $results = [];
    foreach (['raw','prepared'] as $mode) {
        $command = [getenv('THREADFIN_TEST_PHP') ?: PHP_BINARY, '-n', '-d',
            'extension=' . (getenv('THREADFIN_TEST_MYSQLI_EXTENSION') ?: 'mysqli'), '-d', 'memory_limit=32M',
            '-d', 'display_errors=stderr', __FILE__, '--reader', $table, $mode];
        $process = proc_open($command, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
        if (!is_resource($process)) { throw new RuntimeException('Cannot start measured reader'); }
        fclose($pipes[0]); $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); $status = proc_close($process);
        if ($status !== 0 || $stderr !== '') { throw new RuntimeException('Reader failed: ' . $stderr . $stdout); }
        $results[] = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    }
    echo json_encode(['fixture_rows'=>32768, 'payload_bytes_per_row'=>8192, 'fixture_payload_bytes'=>268435456,
        'server'=>$admin->server_info, 'client'=>mysqli_get_client_info(), 'php'=>PHP_VERSION,
        'socket'=>getenv('THREADFIN_TEST_MYSQL_SOCKET'), 'readers'=>$results], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
} finally {
    if ($table !== null) { $admin->query("DROP TABLE IF EXISTS `$table`"); }
    $admin->close();
}
