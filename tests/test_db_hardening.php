<?php declare(strict_types=1);
/** @covers ../db.php */
require_once __DIR__ . '/support/regression.php';

function test_db_closed_results_are_empty_for_every_access_path_and_both_backings(): void {
    assert_eq(db_regression_observe('closed_result_lifecycle'), array_fill(0, 3, [
        [false, 0, false, null], [], null, false, [], [true, true],
    ]), 'Close must invalidate both backing types, current row and iterator state; repeated close is safe');
}

function test_db_row_offsets_accept_integer_strings_and_reject_other_types_consistently(): void {
    $accepted = array_merge(array_fill(0, 5, [true, '1']), array_fill(0, 3, [true, '3']));
    $expected = [$accepted, array_fill(0, 15, [false, true]), [1, '2']];
    assert_eq(db_regression_observe('offset_types_consistent'), [$expected, $expected],
        'Normalize in-range integer strings without overflow; invalid offsets must neither coerce nor move the cursor');
}

function test_db_empty_current_is_null_and_result_mutation_remains_read_only(): void {
    assert_eq(db_regression_observe('empty_current_and_read_only'), array_fill(0, 3, [null, false, 0, [true, true]]),
        'Empty/uninitialized results must support safe cursor operations while rejecting writes');
}

function test_db_disconnected_fetch_retains_generated_sql_without_stale_iterator_state(): void {
    assert_eq(db_regression_observe('disconnected_fetch_metadata'), ['SELECT 0', 0, null, false],
        'Disconnected read wrappers must retain interpolated SQL just like failed/simulated reads');
}

function test_db_cleanup_removes_dead_fields_without_breaking_legacy_factory_parameter(): void {
    assert_eq(db_regression_observe('obsolete_fields_removed'), [[true, true, true, true], [['id' => '1']]],
        'Remove obsolete state while keeping the historical fetch_all named argument compatible');
}

function test_db_replay_partial_append_is_rolled_back_before_retry(): void {
    assert_eq(db_regression_observe('replay_partial_recovery'), [true, "# existing\n",
        ['BEGIN', 'INSERT INTO records VALUES (1)'],
        "# existing\n" . db_expected_replay_packet(['BEGIN', 'INSERT INTO records VALUES (1)']), []],
        'A failed append must truncate to the locked checkpoint and retain the queue; retry must append once');
}

function test_db_replay_flush_failure_recovers_existing_file_and_retains_queue(): void {
    assert_eq(db_regression_observe('replay_flush_recovery'), [true, "# existing\n",
        ['BEGIN', 'INSERT INTO records VALUES (1)'],
        "# existing\n" . db_expected_replay_packet(['BEGIN', 'INSERT INTO records VALUES (1)']), []],
        'Flush failure must restore the checkpoint rather than duplicating a fully written packet on retry');
}

function test_db_replay_destination_cannot_change_during_a_session(): void {
    assert_eq(db_regression_observe('replay_destination_guard'), [true, true,
        db_expected_replay_packet(['INSERT INTO records VALUES (1)']), ''],
        'Re-enabling the same destination is idempotent; changing it must not silently reroute pending SQL');
}

function test_db_replay_preserves_initial_autocommit_and_closes_pending_transactions(): void {
    assert_eq(db_regression_observe('replay_initial_autocommit'),
        db_expected_replay_packet(['INSERT INTO records VALUES (1)'], 0),
        'Replay must restore initial autocommit and roll back any transaction left pending at connection close');
}
