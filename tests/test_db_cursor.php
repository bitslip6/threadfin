<?php declare(strict_types=1);
/** @covers ../db.php */
require_once __DIR__ . '/support/regression.php';

function test_db_seek_synchronizes_forward_backward_and_next(): void {
    assert_eq(db_regression_observe('seek_moves'), [[1, 'bob'], [2, 'carol'], [0, 'alice'], [1, 'bob']],
        'Seeking must select the row/key and leave next() ready for the following row');
}

function test_db_seek_can_resume_an_exhausted_iterator(): void {
    assert_eq(db_regression_observe('seek_after_exhaustion'), [[1, 'bob', true], [2, 'carol', true]],
        'A valid seek after exhaustion must reactivate the iterator at the selected row');
}

function test_db_invalid_seek_preserves_iterator_and_next_fetch(): void {
    assert_eq(db_regression_observe('invalid_seek_preserves_cursor'), [[true, true], [1, 'bob'], [2, 'carol']],
        'Invalid seeks must raise bounds errors without changing the current row or driver cursor');
}

function test_db_array_reads_preserve_an_advanced_iterator(): void {
    $first = ['id' => '1', 'name' => 'alice'];
    $third = ['id' => '3', 'name' => 'carol'];
    assert_eq(db_regression_observe('array_cursor_after_next'), [[$first, $third, $first], [1, 'bob'], [2, 'carol']],
        'Repeated random reads must return their rows without moving current(), key() or next()');
}

function test_db_array_read_preserves_eof_after_last_row(): void {
    assert_eq(db_regression_observe('array_cursor_at_last'), [
        ['id' => '1', 'name' => 'alice'], [2, 'carol'], [3, false, null],
    ], 'Reading an earlier row while on the last row must leave the next fetch at EOF');
}

function test_db_array_read_does_not_revive_an_exhausted_cursor(): void {
    assert_eq(db_regression_observe('array_cursor_exhausted'), [
        ['id' => '2', 'name' => 'bob'], [3, false, null], [4, false, null],
    ], 'Random reads after exhaustion must preserve the invalid iterator and driver EOF');
}

function test_db_array_reads_preserve_a_single_row_cursor(): void {
    assert_eq(db_regression_observe('array_cursor_single'), [
        [['name' => 'alice'], ['name' => 'alice']], [0, 'alice'], [1, false, null],
    ], 'A one-row result must support repeat reads without repeating the row on next()');
}
