<?php declare(strict_types=1);
/** @covers ../db.php */
require_once __DIR__ . '/support/regression.php';

function db_expected_array_result_rows(): array {
    return [['id' => '1', 'name' => 'alice'], ['id' => '2', 'name' => 'bob'], ['id' => '3', 'name' => 'carol']];
}

function test_db_from_selects_first_row_and_column_immediately(): void {
    $rows = db_expected_array_result_rows();
    assert_eq(db_regression_observe('from_current'), [0, $rows[0], 'alice', true, false],
        'An array-backed result must start with the first row selected, just like a mysqli result');
}

function test_db_from_next_rewind_and_repeated_iteration(): void {
    $rows = db_expected_array_result_rows();
    assert_eq(db_regression_observe('from_moves_and_repeat'), [
        [1, $rows[1]], [0, $rows[0]], $rows, [3, false, null, 3], $rows,
    ], 'Next/rewind must select rows, exhaustion must clear the current column, and iteration must be repeatable');
}

function test_db_from_random_reads_preserve_iterator_and_reject_invalid_offsets(): void {
    $rows = db_expected_array_result_rows();
    assert_eq(db_regression_observe('from_random_reads'), [
        [$rows[2], $rows[0], $rows[2]], [1, $rows[1]], [true, true], [2, $rows[2]],
    ], 'Array reads must not move the iterator, including when invalid offsets are rejected');
}

function test_db_from_seek_resumes_exhaustion_and_preserves_state_on_errors(): void {
    $rows = db_expected_array_result_rows();
    assert_eq(db_regression_observe('from_seek'), [
        [1, $rows[1], true], [true, true], [2, $rows[2]], [0, $rows[0]],
    ], 'Seek must work in both directions and after exhaustion, without changing state on invalid offsets');
}

function test_db_from_empty_and_null_are_empty_results(): void {
    $empty = [0, true, false, [], [], null];
    assert_eq(db_regression_observe('from_empty'), [$empty, $empty],
        'Empty and failed-query array wrappers must remain safe to count, iterate and convert');
}

function test_db_from_single_row_retains_falsey_values(): void {
    $row = ['blank' => '', 'zero' => 0, 'flag' => false, 'nil' => null];
    assert_eq(db_regression_observe('from_falsey_row'), [
        [$row, $row, '', null], [$row], [1, false, null], $row,
    ], 'A single row must retain empty/zero/false/null values through access, iteration and rewind');
}

function test_db_from_array_conversion_is_complete_and_preserves_cursor(): void {
    $rows = db_expected_array_result_rows();
    assert_eq(db_regression_observe('from_as_array'), [
        [$rows, $rows], [1, $rows[1]], [2, $rows[2]], $rows, [3, false, null],
    ], 'Conversion must return all rows repeatedly without moving or reviving the iterator');
}

function test_db_from_normalizes_outer_keys_to_row_positions(): void {
    $rows = db_expected_array_result_rows();
    assert_eq(db_regression_observe('from_nonsequential_keys'), [3, $rows[0], $rows[1], $rows, $rows],
        'Input keys must not create holes in zero-based row access or iteration');
}
