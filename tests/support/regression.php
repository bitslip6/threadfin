<?php declare(strict_types=1);
/** Expected journal envelope for the isolated driver's configured session. */
function db_expected_replay_packet(array $sqls, int $autocommit = 1): string {
    return "\n-- ThreadFin replay session\nROLLBACK;\nSET NAMES utf8mb4;\n"
        . "SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ANSI_QUOTES,NO_ENGINE_SUBSTITUTION';\n"
        . "SET SESSION autocommit = $autocommit;\n"
        . implode("\n;\n", $sqls) . "\n;\nROLLBACK;\nSET SESSION autocommit = 1;\n-- End ThreadFin replay session\n";
}

/** TinyTest assertions stay in the host; mysqli stand-ins stay in child processes. */
function db_regression_observe(string $case, bool $nativeAssertions = true): mixed {
    $php = getenv('THREADFIN_TEST_PHP') ?: (PHP_SAPI === 'cli' ? PHP_BINARY : '/usr/bin/php');
    $command = [$php, '-n', '-d', 'zend.assertions=' . ($nativeAssertions ? '1' : '-1'),
        '-d', 'assert.exception=1', __DIR__ . '/db_case.php', $case];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start the isolated DB regression fixture');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    assert_eq($exit, 0, "$case fixture must execute successfully; stderr: $stderr; stdout: $stdout");
    $report = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    $detail = $report['error'] === null ? '' : json_encode($report['error'], JSON_THROW_ON_ERROR);
    assert_eq($report['error'], null, "$case must complete without a production exception: $detail");
    return $report['value'];
}
