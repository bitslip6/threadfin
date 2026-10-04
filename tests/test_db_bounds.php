<?php declare(strict_types=1);
/** @covers ../db.php */
require_once __DIR__ . '/support/regression.php';

function test_db_offset_exists_uses_exclusive_bounds_for_both_backings(): void {
    $checks = [];
    foreach ([false, false, true, true, true, false, false, false, false, true, true, false] as $exists) {
        $checks[] = [$exists, $exists];
    }
    assert_eq(db_regression_observe('exists_bounds'), [$checks, $checks],
        'Direct offsetExists() and isset() must accept only offsets from zero through count minus one');
}

function test_db_offset_exists_returns_false_for_empty_and_closed_results(): void {
    $checks = [[false, false], [false, false], [false, false]];
    assert_eq(db_regression_observe('exists_empty_and_closed'), array_fill(0, 5, $checks),
        'Empty, null, uninitialized and closed results must not report any row as available');
}

function test_db_offset_exists_preserves_current_row_and_next_fetch(): void {
    $expected = [[false, true, true, false],
        [1, ['id' => '2', 'name' => 'bob']], [2, ['id' => '3', 'name' => 'carol']]];
    assert_eq(db_regression_observe('exists_preserves_cursor'), [$expected, $expected],
        'Existence checks must not fetch or seek rows in either backing type');
}

function test_db_offset_exists_can_check_rows_after_exhaustion_without_reviving_iterator(): void {
    $expected = [[false, true, true, false], [3, false, null], [4, false, null]];
    assert_eq(db_regression_observe('exists_after_exhaustion'), [$expected, $expected],
        'Buffered rows still exist after exhaustion, but existence checks must leave the iterator exhausted');
}

function test_db_offset_exists_checks_rows_not_their_falsey_column_values(): void {
    $row = ['nil' => null, 'zero' => 0, 'blank' => '', 'flag' => false];
    $expected = [false, true, false, $row];
    assert_eq(db_regression_observe('exists_single_falsey_row'), [$expected, $expected],
        'A single row with falsey columns must exist at zero, but not at negative offsets or one');
}
