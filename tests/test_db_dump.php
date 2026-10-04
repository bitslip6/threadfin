<?php declare(strict_types=1);
/** @covers ../db.php */
require_once __DIR__ . '/support/regression.php';

function db_expected_unwritten_dump_offsets(): array {
    return [['records', 0, false], ['extras', 0, false]];
}

function test_db_dump_does_not_emit_a_header_that_exceeds_budget(): void {
    $expected = ['', 0, db_expected_unwritten_dump_offsets(), false];
    assert_eq(db_regression_observe('dump_small_budgets'), array_fill(0, 4, $expected),
        'Zero/tiny budgets must not emit partial SQL headers or begin exporting tables');
}

function test_db_dump_header_counts_toward_exact_budget(): void {
    assert_eq(db_regression_observe('dump_exact_header_budget'), [true, 1, db_expected_unwritten_dump_offsets(), false],
        'An exact header-sized budget must write only the header, leaving every table incomplete');
}

function test_db_dump_budget_stops_before_oversized_ddl_without_truncation(): void {
    assert_eq(db_regression_observe('dump_ddl_budget'), [true, 3, db_expected_unwritten_dump_offsets(), true, false, false],
        'A DDL chunk that does not fit must not be written, and no row or later-table queries may follow');
}

function test_db_dump_budget_preserves_checkpoint_after_a_complete_row_batch(): void {
    $offsets = [['records', 300, false], ['extras', 0, false]];
    assert_eq(db_regression_observe('dump_batch_budget'), [true, 5, $offsets, true, false, false],
        'Only fully written row batches may advance checkpoints; the shared budget must stop later tables');
}

function test_db_dump_sufficient_budget_works_with_chunk_and_cumulative_writer_totals(): void {
    $offsets = [['records', -1, true], ['extras', -1, true]];
    $expected = [$offsets, 10, true, true];
    assert_eq(db_regression_observe('dump_sufficient_budget'), [$expected, $expected],
        'Budget accounting must use emitted chunk lengths, not callback totals, and allow complete multi-table dumps');
}

function test_db_dump_header_writer_failure_stops_all_table_exports(): void {
    assert_eq(db_regression_observe('dump_header_writer_failure'), ['', 1, db_expected_unwritten_dump_offsets(), false],
        'A failed database-header write must not begin any table or claim completion');
}

function test_db_dump_ddl_writer_failures_stop_before_rows_and_later_tables(): void {
    $offsets = db_expected_unwritten_dump_offsets();
    assert_eq(db_regression_observe('dump_ddl_writer_failure'), [
        [2, $offsets, false, false], [3, $offsets, false, false], [4, $offsets, false, false],
    ], 'Table-header/drop/create failures must stop output and preserve zero row checkpoints');
}

function test_db_dump_batch_writer_failure_retains_last_successful_checkpoint(): void {
    assert_eq(db_regression_observe('dump_batch_writer_failure'), [6,
        [['records', 300, false], ['extras', 0, false]], false, false],
        'A failed second batch must retain the first batch checkpoint and prevent later-table output');
}

function test_db_dump_rejects_negative_byte_budget(): void {
    assert_true(db_regression_observe('dump_negative_budget'), 'A negative byte budget must raise InvalidArgumentException');
}

function test_db_dump_requested_database_controls_connection_and_table_column_without_mutating_credentials(): void {
    assert_eq(db_regression_observe('dump_requested_nonempty_database'), ['requested', 'configured',
        [['records', -1, true], ['extras', -1, true]], true],
        'The requested database must control connection selection, SHOW TABLES column lookup and labeling');
}
