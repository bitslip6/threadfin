<?php declare(strict_types=1);
/** @covers ../db.php */
require_once __DIR__ . '/support/regression.php';

function test_db_replay_records_successful_zero_affected_statements_independently_of_return_mode(): void {
    $sqls = ['CREATE TABLE records (id INT)', 'UPDATE records SET id = 1 WHERE id = 1',
        'SET autocommit = 1', 'ALTER TABLE records ADD name TEXT', 'INSERT INTO records VALUES (1)'];
    assert_eq(db_regression_observe('replay_zero_affected_and_modes'), [
        [1, 0, 1, -1, 7], $sqls, "\n" . implode(";\n", $sqls) . ";\n", [], [],
    ], 'Successful execution, not affected rows or insert-ID availability, determines replay membership');
}

function test_db_replay_preserves_rollback_commit_and_savepoint_statements_in_order(): void {
    $sequences = [['BEGIN', 'INSERT INTO records VALUES (1)', 'ROLLBACK'],
        ['START TRANSACTION', 'INSERT INTO records VALUES (2)', 'SAVEPOINT checkpoint',
         'INSERT INTO records VALUES (3)', 'ROLLBACK TO SAVEPOINT checkpoint', 'COMMIT']];
    assert_eq(db_regression_observe('replay_transaction_sequences'),
        array_map(fn($sqls) => "\n" . implode(";\n", $sqls) . ";\n", $sequences),
        'Replay must preserve executed transaction controls rather than turning rolled-back writes into standalone inserts');
}

function test_db_replay_excludes_failed_queries_fetch_reads_and_simulated_statements(): void {
    assert_eq(db_regression_observe('replay_excludes_failures_reads_and_simulation'), [
        "\nCREATE TABLE records (id INT);\n", 2, [],
    ], 'Neither driver failure mode, ordinary fetches, nor simulation may enter the write/raw execution journal');
}

function test_db_replay_successful_close_consumes_queue_and_repeated_close_does_not_append_again(): void {
    $expected = "# prior journal\n\nINSERT INTO records VALUES (1);\n";
    assert_eq(db_regression_observe('replay_append_once'), [$expected, $expected, [], true],
        'A successful append must preserve existing content and consume the journal queue exactly once');
}

function test_db_replay_destructor_closes_and_persists_pending_journal(): void {
    assert_eq(db_regression_observe('replay_destructor'), ["\nINSERT INTO records VALUES (1);\n", true],
        'Normal destructor-based closure must still persist pending replay statements');
}

function test_db_replay_disabled_does_not_queue_or_persist_successful_statements(): void {
    assert_eq(db_regression_observe('replay_disabled'), ['', []], 'Replay remains opt-in');
}

function test_db_replay_failed_write_retains_queue_for_explicit_retry(): void {
    assert_eq(db_regression_observe('replay_failed_write_retry'), [true,
        ['INSERT INTO records VALUES (1)'], [], "\nINSERT INTO records VALUES (1);\n", true],
        'Zero-progress file output must report failure and retain pending SQL; a successful retry consumes it');
}
