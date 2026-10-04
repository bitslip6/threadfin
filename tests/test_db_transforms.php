<?php declare(strict_types=1);
/** @covers ../db.php */
require_once __DIR__ . '/support/regression.php';

function test_db_map_visits_every_row_in_order_and_keeps_falsey_returns(): void {
    $expected = [['alice', 'bob', 'carol'], [0, false, null]];
    assert_eq(db_regression_observe('map_rows'), [$expected, $expected],
        'Map must receive whole associative rows in order, including the first, for both backings');
}

function test_db_reduce_visits_every_row_in_order_with_default_and_explicit_seed(): void {
    $expected = ['alice,bob,carol,', 'prefix:alice,bob,carol,'];
    assert_eq(db_regression_observe('reduce_order_and_seed'), [$expected, $expected],
        'Reduce must fold the entire result using its empty-string default or the supplied initial value');
}

function test_db_reduce_supports_mixed_accumulators_and_falsey_returns(): void {
    $expected = [6, ['seed', '1', '2', '3'], 0, false, null];
    assert_eq(db_regression_observe('reduce_mixed_values'), [$expected, $expected],
        'Reduce must support arbitrary accumulator types without replacing valid falsey values');
}

function test_db_map_and_reduce_preserve_advanced_iterator_and_next_fetch(): void {
    $names = ['alice', 'bob', 'carol'];
    $expected = [$names, 6, $names, [1, ['id' => '2', 'name' => 'bob']], [2, ['id' => '3', 'name' => 'carol']]];
    assert_eq(db_regression_observe('map_reduce_advanced'), [$expected, $expected],
        'Transforms must be repeatable over all rows without changing the current row/key or next fetch');
}

function test_db_map_and_reduce_read_exhausted_results_without_reviving_iterator(): void {
    $expected = [['alice', 'bob', 'carol'], 6, [3, false, null], [4, false, null]];
    assert_eq(db_regression_observe('map_reduce_exhausted'), [$expected, $expected],
        'Transforms must still read the complete buffered dataset after exhaustion while preserving EOF');
}

function test_db_map_and_reduce_preserve_eof_on_last_and_single_rows(): void {
    $last = [['alice', 'bob', 'carol'], 6, [2, 'carol'], [3, false, null]];
    $single = [['alice'], 1, [0, 'alice'], [1, false, null]];
    assert_eq(db_regression_observe('map_reduce_at_eof'), [$last, $last, $single, $single],
        'Transforming at the last row, including a single-row result, must leave next() at EOF');
}

function test_db_empty_transforms_return_empty_map_and_initial_reduce_without_callbacks(): void {
    $expected = [[], '', ['seed'], null, 0];
    assert_eq(db_regression_observe('map_reduce_empty'), array_fill(0, 5, $expected),
        'Empty/null/uninitialized/closed results must not invoke callbacks and must return the reduction seed');
}

function test_db_transform_callback_exceptions_propagate_without_moving_cursor(): void {
    $expected = ['callback failed', 2, [1, ['id' => '2', 'name' => 'bob']], [2, ['id' => '3', 'name' => 'carol']]];
    assert_eq(db_regression_observe('map_reduce_callback_exception'), array_fill(0, 4, $expected),
        'Callback exceptions must propagate while preserving current row/key and subsequent next()');
}

function test_db_empty_database_dump_completes_without_missing_method_mask(): void {
    assert_eq(db_regression_observe('dump_empty_methods'), [[], true, true],
        'An empty database must return no offsets and write its header without a missing-method exception');
}

function test_db_nonempty_database_dump_maps_tables_and_reduces_all_rows(): void {
    $observed = db_regression_observe('dump_nonempty_methods');
    assert_eq($observed['offsets'], [['records', true]], 'Map must return a completed offset for the exported table');
    assert_contains($observed['output'], "DROP TABLE IF EXISTS `records`;\n", 'The real dumper must export table DDL');
    assert_contains($observed['output'], "CREATE TABLE records (id INT, name TEXT);\n", 'The real dumper must export the create statement');
    assert_contains($observed['output'], "INSERT IGNORE INTO records VALUES('1','alice'),\n('2','bob'),\n('3','carol');\n",
        'Reduce must export every row in order, including the first');
    assert_true(in_array('SELECT * FROM records LIMIT 3 OFFSET 0', $observed['queries'], true),
        'The real dumper must request the row batch');
}
