<?php declare(strict_types=1);
/** Opt-in real-mysqli tests. Requires an EMPTY disposable database. @covers ../../db.php */
function db_mysql_lifecycle_observe(string $case): mixed {
    $socket = getenv('THREADFIN_TEST_MYSQL_SOCKET');
    $database = getenv('THREADFIN_TEST_MYSQL_DATABASE');
    if (!$socket || !$database || getenv('THREADFIN_TEST_MYSQL_ALLOW_SCHEMA_CHANGES') !== '1') {
        throw new RuntimeException('Set disposable socket/database and THREADFIN_TEST_MYSQL_ALLOW_SCHEMA_CHANGES=1');
    }
    $command = [getenv('THREADFIN_TEST_PHP') ?: PHP_BINARY, '-n', '-d',
        'extension=' . (getenv('THREADFIN_TEST_MYSQLI_EXTENSION') ?: 'mysqli'),
        '-d', 'mysqli.default_socket=' . $socket, '-d', 'zend.assertions=1', '-d', 'assert.exception=1',
        __DIR__ . '/../support/mysql_live.php', $case];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Cannot start live-mysqli child'); }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    assert_eq(proc_close($process), 0, 'Live child must complete: ' . $stderr . $stdout);
    $report = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    assert_eq($report['error'], null, 'Live production methods must succeed: ' . ($report['error'] ?? ''));
    return $report['value'];
}

/** @type integration */
function test_db_mysql_real_cursor_offsets_eof_and_closed_result_lifecycle(): void {
    assert_eq(db_mysql_lifecycle_observe('cursor'), [
        ['mode' => 'ANSI_QUOTES', 'charset' => 'utf8mb4'], ['3', '2', '3'], ['1', false, null],
        [['id' => '1'], ['id' => '2'], ['id' => '3']], [0, false, null, []], null,
    ], 'Real buffered cursors must preserve random-read position, EOF, empty/closed state, and connection quoting setup');
}

/** @type integration */
function test_db_mysql_replay_restores_session_defaults_and_preserves_commits_and_implicit_rollbacks(): void {
    $observed = db_mysql_lifecycle_observe('replay');
    assert_eq(array_column($observed[0], 'id'), ['3', '4'], 'Source connection close must roll back pending writes; committed IDs survive');
    assert_eq($observed[1], $observed[0], 'Replaying multiple sessions must exactly reproduce committed rows and quoted bytes');
    assert_eq(array_slice($observed, 2), ['1', true], 'Replay must leave no pending transaction/autocommit state and repeated close must append nothing');
}

/** @type integration */
function test_db_mysql_dump_restore_round_trips_multiple_batches_and_short_stream_writes(): void {
    assert_eq(db_mysql_lifecycle_observe('dump'), [301, true, true, '', false, 0],
        'Real dump/restore must preserve NULL/zero/text bytes across 300-row batches and complete short writes, while honoring tiny budgets');
}

/** @type integration */
function test_db_mysql_connect_and_real_query_errors_preserve_public_contracts(): void {
    assert_eq(db_mysql_lifecycle_observe('connection'), [true, 'utf8mb4', -1, 0, 2, false],
        'Real mysqli factory setup and native exceptions must preserve connected/error/empty-result/close contracts');
}
