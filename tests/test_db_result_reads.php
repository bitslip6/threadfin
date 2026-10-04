<?php declare(strict_types=1);
/** @covers ../db.php */
require_once __DIR__ . '/support/regression.php';

function db_expected_read_rows(): array {
    return [['id' => '1', 'name' => 'alice'], ['id' => '2', 'name' => 'bob'], ['id' => '3', 'name' => 'carol']];
}

function test_db_col_tracks_next_and_rewind(): void {
    assert_eq(db_regression_observe('column_moves'), ['bob', 'alice'],
        'Column lookup must use the row selected by next()/rewind()');
}

function test_db_col_preserves_empty_string_zero_and_null(): void {
    assert_eq(db_regression_observe('column_falsey_values'), ['', '0', null],
        'Empty text and string zero must not be treated as missing/null');
}

function test_db_col_missing_and_empty_results_return_null(): void {
    assert_eq(db_regression_observe('column_missing_empty'), [null, null, null],
        'Missing columns, empty queries and failed query wrappers must safely return an empty MaybeStr');
}

function test_db_col_accepts_numeric_column_alias(): void {
    assert_eq(db_regression_observe('column_numeric_name'), 'alice',
        'A numeric column alias must read the full value, not one character from it');
}

function test_db_as_array_returns_a_single_row(): void {
    assert_eq(db_regression_observe('as_array_single'), [['name' => 'alice']],
        'A one-row result must not become an empty array');
}

function test_db_as_array_is_repeatable(): void {
    assert_eq(db_regression_observe('as_array_repeat'), [db_expected_read_rows(), db_expected_read_rows()],
        'Repeated conversion must return the complete dataset each time');
}

function test_db_as_array_preserves_iteration_after_advancing(): void {
    $current = ['key' => 1, 'row' => ['id' => '2', 'name' => 'bob']];
    assert_eq(db_regression_observe('as_array_after_next'), [
        'rows' => db_expected_read_rows(), 'before' => $current, 'after' => $current,
        'next' => ['key' => 2, 'row' => ['id' => '3', 'name' => 'carol']],
    ], 'Array conversion must read all rows without changing the current row, key or subsequent next()');
}

function test_db_as_array_empty_error_and_closed_results_return_empty(): void {
    assert_eq(db_regression_observe('as_array_empty_error_closed'), [[], [], []],
        'Empty queries, failed queries and closed results must convert safely to an empty array');
}

function test_db_as_array_can_read_exhausted_result_without_reviving_iterator(): void {
    assert_eq(db_regression_observe('as_array_exhausted'), [
        'rows' => db_expected_read_rows(), 'key' => 3, 'valid' => false,
    ], 'An exhausted buffered result must still convert fully while its iterator remains exhausted');
}
