<?php declare(strict_types=1);
/** @covers ../db.php */
require_once __DIR__ . '/support/regression.php';

function test_db_bulk_explicit_flush_combines_rows_resets_buffer_and_empty_flush_is_noop(): void {
    assert_eq(db_regression_observe('bulk_flush_lifecycle'), [[0, 1, 2, 1, 0, 1, 1], [], [
        "INSERT IGNORE INTO records (name) VALUES ('alice'),\n('bob')",
        "INSERT IGNORE INTO records (name) VALUES ('carol')",
    ]], 'Rows must remain buffered until flush, successful flush must reset state, and empty flush must execute nothing');
}

function test_db_bulk_automatically_flushes_at_exact_limit_and_buffers_next_row(): void {
    $rows = array_map(fn($id) => '(' . $id . ')', range(1, 64));
    assert_eq(db_regression_observe('bulk_limit_boundary'), [[63, 0], [1, 1], [1, 1], 1, 0, [
        'INSERT IGNORE INTO records (id) VALUES ' . implode(",\n", $rows),
        'INSERT IGNORE INTO records (id) VALUES (65)',
    ]], 'The 64th row must flush exactly one batch; row 65 must start a new buffer without duplicating earlier rows');
}

function test_db_bulk_list_and_mapped_column_forms_preserve_names_order_and_falsey_values(): void {
    $expected = [0, ["INSERT IGNORE INTO records (name,nil,zero,flag,code) VALUES ('O\\'Reilly',null,0,0,'00123')"]];
    assert_eq(db_regression_observe('bulk_column_forms'), [$expected, $expected],
        'List columns map to same-named fields, mapped columns use source keys, and all values retain shared quoting');
}

function test_db_bulk_closures_have_independent_buffers_and_preserve_ignore_option(): void {
    assert_eq(db_regression_observe('bulk_independent_closures'), [[], [
        "INSERT  INTO first (name) VALUES ('alice')",
        "INSERT IGNORE INTO second (name) VALUES ('bob')",
        "INSERT  INTO first (name) VALUES ('carol')",
    ]], 'Interleaved closures must not mix rows, share flush state, or change the duplicate-ignore setting');
}

function test_db_bulk_rejects_missing_fields_without_corrupting_buffered_rows(): void {
    assert_eq(db_regression_observe('bulk_missing_row_is_atomic'), [true, [], [
        "INSERT IGNORE INTO records (name,nil) VALUES ('alice',null)",
    ]], 'An invalid row must raise InvalidArgumentException before changing the counter or buffered SQL; present null is valid');
}

function test_db_bulk_rejects_empty_column_declarations(): void {
    assert_true(db_regression_observe('bulk_empty_columns'), 'Bulk inserts require at least one declared column');
}

function test_db_bulk_simulation_logs_only_when_the_batch_is_flushed(): void {
    $observed = db_regression_observe('bulk_simulated_batch');
    assert_eq($observed['statuses'], [1, 2, 1], 'Buffering returns the pending row count, while simulated flush reports generation success');
    assert_eq($observed['before'], [[], []], 'Unflushed rows must not execute or log a statement');
    assert_eq($observed['queries'], [], 'Simulated flush must execute nothing');
    assert_eq($observed['errors'], [], 'Simulated bulk output must not manufacture errors');
    assert_count($observed['logs'], 1, 'Exactly one complete batch must be logged');
    assert_contains($observed['logs'][0], "INSERT IGNORE INTO records (name) VALUES ('alice'),\n('bob')",
        'Simulation must receive the full batched statement');
}

function test_db_bulk_failed_flush_can_be_retried_without_losing_or_duplicating_rows(): void {
    $sql = "INSERT IGNORE INTO records (name) VALUES ('alice'),\n('bob')";
    assert_eq(db_regression_observe('bulk_failed_flush_retry'), [[1, 2, -1, 1, 0], [$sql, $sql], 1],
        'A failed flush must retain the exact batch for retry; a successful retry must clear it');
}

function test_db_bulk_failed_full_batch_is_retried_before_accepting_more_rows(): void {
    assert_eq(db_regression_observe('bulk_failed_full_batch_stays_bounded'), [
        [-1, -1, 1, 1, 0], [64, 64, 64, 1], 2, 'INSERT IGNORE INTO records (id) VALUES (65)',
    ], 'A failed full batch must never grow beyond the limit or accept a new row when its retry fails');
}
