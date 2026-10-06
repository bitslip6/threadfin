<?php declare(strict_types=1);
/** Driver selection stays in isolated children, never the TinyTest host. */
function db_transaction_observe(string $case, bool $native = false): mixed {
    $command = [getenv('THREADFIN_TEST_PHP') ?: PHP_BINARY, '-n', '-d', 'zend.assertions=1', '-d', 'assert.exception=1'];
    if ($native) {
        if (!getenv('THREADFIN_TEST_MYSQL_SOCKET') || !getenv('THREADFIN_TEST_MYSQL_DATABASE') || getenv('THREADFIN_TEST_MYSQL_ALLOW_SCHEMA_CHANGES') !== '1') {
            throw new RuntimeException('Disposable socket/database and schema permission required');
        }
        array_push($command, '-d', 'extension=' . (getenv('THREADFIN_TEST_MYSQLI_EXTENSION') ?: 'mysqli'));
    }
    array_push($command, __DIR__ . ($native ? '/transaction_mysql.php' : '/transaction_case.php'), $case);
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Cannot start transaction fixture'); }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    assert_eq(proc_close($process), 0, $stderr . $stdout);
    $report = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    assert_eq($report['error'], null, $report['error'] ?? 'Transaction case completes');
    return $report['value'];
}
