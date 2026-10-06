<?php declare(strict_types=1);
/** Driver selection stays in isolated children, never the TinyTest host. */
function db_streaming_observe(string $case, bool $native = false): mixed {
    $command = [getenv('THREADFIN_TEST_PHP') ?: PHP_BINARY, '-n', '-d', 'zend.assertions=1', '-d', 'assert.exception=1',
        '-d', 'display_errors=1', '-d', 'log_errors=1', '-d', 'error_log=/dev/stderr'];
    if ($native) {
        if (!getenv('THREADFIN_TEST_MYSQL_SOCKET') || !getenv('THREADFIN_TEST_MYSQL_DATABASE') || getenv('THREADFIN_TEST_MYSQL_ALLOW_SCHEMA_CHANGES') !== '1') {
            throw new RuntimeException('Disposable socket/database and schema permission required');
        }
        array_push($command, '-d', 'extension=' . (getenv('THREADFIN_TEST_MYSQLI_EXTENSION') ?: 'mysqli'));
    }
    array_push($command, __DIR__ . ($native ? '/streaming_mysql_case.php' : '/streaming_case.php'), $case);
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Cannot start streaming fixture'); }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    assert_eq(proc_close($process), 0, $stderr . $stdout);
    assert_eq($stderr, '', 'Streaming child must not emit stderr');
    $report = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    assert_eq($report['output'], '', 'Streaming child must not emit unexpected output');
    assert_eq($report['error'], null, $report['error'] ?? 'Streaming case completes');
    return $report['value'];
}
