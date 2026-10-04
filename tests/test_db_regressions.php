<?php declare(strict_types=1);
/**
 * Expected-behavior regressions for the db.php review; intentionally red until fixed.
 * @covers ../db.php
 */
require_once __DIR__ . '/support/regression.php';

function test_db_quote_is_one_literal_after_connection_setup(): void {
    assert_eq(db_regression_observe('quote_configured_mode'),
        ['value' => "x' OR 1=1 -- ", 'trailing' => ''],
        'Quoting must preserve the input as one literal, without executable trailing SQL');
}

function test_db_quote_preserves_numeric_string_type(): void {
    assert_eq(db_regression_observe('quote_numeric_string'), ['value' => '00123', 'trailing' => ''],
        'Numeric-looking strings must remain strings so leading zeros survive insertion and backup');
}

function test_db_quote_round_trips_after_configuring_either_initial_mode(): void {
    foreach (db_regression_observe('quote_mode_matrix') as $row) {
        assert_false($row['no_backslash_escapes'], 'Setup must disable NO_BACKSLASH_ESCAPES before quoting');
        assert_eq($row['charset'], 'utf8mb4', 'Setup must use a UTF-8 connection charset');
        assert_eq($row['decoded'], ['value' => $row['input'], 'trailing' => ''],
            'Text, UTF-8, quotes, backslashes and controls must survive the configured SQL mode');
    }
}

function test_db_quote_stringable_cast_cannot_inject_sql(): void {
    assert_eq(db_regression_observe('quote_stringable'), ['value' => "\\' OR 1=1 -- ", 'trailing' => ''],
        'Implicit string casts must use the same safe quoting as ordinary strings');
}

function test_db_quote_array_elements_cannot_escape_their_literals(): void {
    assert_eq(db_regression_observe('quote_array'),
        ['values' => ["x' OR 1=1 -- ", 'two,three', '中文 😀'], 'trailing' => ''],
        'Every string in a quoted array must remain one literal without introducing SQL or extra elements');
}

function test_db_quote_uses_client_backslash_escape_sequences(): void {
    $expected = "'" . "\\\\" . "\\'" . '\\"' . '\\0\\n\\r\\t\\b\\Z' . "'";
    assert_eq(db_regression_observe('quote_exact_escapes'), $expected,
        'PHP escaping must cover backslashes, both quotes, NUL, newline, return, tab, backspace and control-Z');
}

function test_db_all_value_builders_use_the_same_php_escaping(): void {
    $observed = db_regression_observe('quote_builder_values');
    assert_eq($observed['literal'][0], "'", 'Values must use ordinary quoted literals, not hex encoding');
    assert_count($observed['statements'], 9, 'Exercise glue, WHERE, templates, insert, insert_fn, upsert_fn, bulk, update and store');
    foreach ($observed['statements'] as $statement) {
        assert_contains($statement, $observed['literal'], 'Every value builder must use the same escaping policy');
    }
}

function test_db_associative_upsert_accepts_column_value_pairs(): void {
    assert_matches(db_regression_observe('upsert_associative'), '/ON DUPLICATE KEY UPDATE\s+`?name`?\s*=\s*(?:\x27bob\x27|_utf8mb4\s+X\x27626f62\x27)/is',
        'Associative upserts with explicit exclusion options must build the requested update');
}

function test_db_associative_upsert_works_with_default_options(): void {
    assert_eq(db_regression_observe('upsert_defaults'), 1,
        'Public insert(..., DB_DUPLICATE_UPDATE) must work without supplying optional exclusion arrays');
}

function test_db_col_reads_current_associative_row(): void {
    assert_eq(db_regression_observe('column'), 'alice',
        'col(name) must look up name in the current row, not index that row as a dataset');
}

function test_db_as_array_includes_first_row(): void {
    assert_eq(db_regression_observe('as_array'),
        [['id' => '1', 'name' => 'alice'], ['id' => '2', 'name' => 'bob'], ['id' => '3', 'name' => 'carol']],
        'A freshly fetched result converted to an array must include every row');
}

function test_db_seek_updates_current_row_and_key(): void {
    assert_eq(db_regression_observe('seek'), ['key' => 2, 'current' => ['id' => '3', 'name' => 'carol']],
        'seek(2) must synchronize the iterator key and current row');
}

function test_db_array_read_does_not_move_iterator(): void {
    assert_eq(db_regression_observe('array_cursor'), ['key' => 1, 'current' => ['id' => '2', 'name' => 'bob']],
        'Reading result[2] must not disturb the iterator positioned at row zero');
}

function test_db_from_initializes_result_length(): void {
    assert_true(db_regression_observe('from_length'), 'A nonempty array-backed result must initially be valid');
}

function test_db_from_counts_array_backed_rows(): void {
    assert_eq(db_regression_observe('from_count'), 3, 'count() must support SQL::from(array), not just mysqli results');
}

function test_db_from_supports_array_access(): void {
    assert_eq(db_regression_observe('from_access'), ['id' => '2', 'name' => 'bob'],
        'An array-backed result must read rows without dereferencing a nonexistent mysqli result');
}

function test_db_from_iterates_array_backed_rows(): void {
    assert_eq(db_regression_observe('from_iteration'),
        [['id' => '1', 'name' => 'alice'], ['id' => '2', 'name' => 'bob'], ['id' => '3', 'name' => 'carol']],
        'SQL::from(array) must yield every supplied row in order');
}

function test_db_negative_offset_does_not_exist(): void {
    assert_false(db_regression_observe('negative_exists'), 'Negative row offsets must not report as existing');
}

function test_db_offset_equal_to_count_does_not_exist(): void {
    assert_false(db_regression_observe('past_end_exists'), 'The row count is an exclusive upper bound');
}

function test_db_invalid_array_read_is_rejected(): void {
    assert_true(db_regression_observe('invalid_read'),
        'A failed seek for an out-of-range read must not return an unrelated row');
}

function test_db_dump_map_method_is_callable(): void {
    assert_true(db_regression_observe('map_available'), 'dump_database requires a callable SQL::map()');
}

function test_db_dump_reduce_method_is_callable(): void {
    assert_true(db_regression_observe('reduce_available'), 'dump_table requires a callable SQL::reduce()');
}

function test_db_dump_charset_statement_is_terminated(): void {
    assert_matches(db_regression_observe('dump_header'), '/SET NAMES[^\r\n;]+;[ \t]*\r?\n/i',
        'The exported SET NAMES statement must end before the following SQL statement');
}

function test_db_dump_respects_byte_budget(): void {
    assert_true(db_regression_observe('dump_budget') <= 10,
        'A ten-byte dump budget must cap output, including the header');
}

function test_db_dump_uses_requested_database(): void {
    assert_eq(db_regression_observe('dump_database_name'), 'requested',
        'The dump database argument must select the actual connection database, not merely label the header');
}

function test_db_store_accepts_mysqli_object_with_assertions_enabled(): void {
    assert_eq(db_regression_observe('store_connection'), 7,
        'store() must accept a mysqli object instead of requiring a PHP resource');
}

function test_db_store_recognizes_no_update_attribute(): void {
    assert_eq(db_regression_observe('attribute_no_update'), ['id' => true],
        'ThreadFin\\DB\\NoUpdate must add the marked property to the exclusion map');
}

function test_db_store_recognizes_if_null_attribute(): void {
    assert_eq(db_regression_observe('attribute_if_null'), ['name' => true],
        'ThreadFin\\DB\\IfNull must add the marked property to the conditional-update map');
}

function test_db_simulated_write_logs_without_execution_or_errors(): void {
    $observed = db_regression_observe('simulate_write');
    assert_eq($observed['queries'], [], 'Simulation must not execute database queries');
    assert_eq($observed['errors'], [], 'A skipped simulated write is not a database error');
    assert_eq($observed['status'], 1, 'A simulated write must report successful generation');
    assert_contains(implode('\n', $observed['logs']), 'INSERT INTO records VALUES (1)', 'Simulation must log the generated statement');
}

function test_db_simulated_read_logs_without_execution_or_errors(): void {
    $observed = db_regression_observe('simulate_read');
    assert_eq($observed['queries'], [], 'Read simulation must not execute queries');
    assert_eq($observed['errors'], [], 'A skipped simulated read is not a database error');
    assert_contains(implode('\n', $observed['logs']), 'SELECT name FROM records', 'Read simulation must log the generated statement');
}

function test_db_null_template_parameter_remains_sql_null(): void {
    assert_eq(strtolower(db_regression_observe('null_placeholder')), 'select null',
        'A present null template parameter must not become NO_SUCH_KEY data');
}

function test_db_missing_template_parameter_is_rejected(): void {
    assert_true(db_regression_observe('missing_placeholder'),
        'Missing template keys must raise an argument/bounds error instead of fabricating a value');
}

function test_db_null_where_uses_is_null(): void {
    assert_matches(db_regression_observe('null_where'), '/`name`\s+IS\s+NULL/i',
        'NULL equality must use IS NULL so matching rows can be found');
}

function test_db_upsert_can_update_integer_zero(): void {
    assert_matches(db_regression_observe('upsert_zero'), '/UPDATE.*\b`?flag`?\s*=\s*0\b/is',
        'Zero is a legitimate new value and must appear in the duplicate-update clause');
}

function test_db_upsert_can_update_boolean_false(): void {
    assert_matches(db_regression_observe('upsert_false'), '/UPDATE.*\b`?flag`?\s*=\s*0\b/is',
        'False must become SQL zero in the duplicate-update clause, not be omitted');
}

function test_db_bulk_insert_buffers_until_flush_or_limit(): void {
    assert_eq(db_regression_observe('bulk_batch'), [],
        'Two rows below the bulk limit must remain buffered until explicit flush');
}

function test_db_bulk_insert_accepts_list_column_names(): void {
    assert_matches(db_regression_observe('bulk_list_columns'), '/\(\s*`?name`?\s*,\s*`?email`?\s*\)/',
        'List-form column declarations must generate column names, not numeric array indexes');
}

function test_db_close_logs_non_duplicate_sql_errors(): void {
    assert_contains(db_regression_observe('error_logging'), 'Syntax error near BAD',
        'Error logging must retain non-duplicate failures');
}

function test_db_replay_records_successful_ddl(): void {
    assert_contains(db_regression_observe('replay_ddl'), 'CREATE TABLE records (id INT)',
        'Successful schema changes belong in replay even when affected_rows is zero');
}

function test_db_replay_does_not_commit_rolled_back_writes(): void {
    assert_true(db_regression_observe('replay_rollback'),
        'Replay must preserve rollback boundaries or omit the rolled-back insertion');
}

function test_db_repeated_close_does_not_duplicate_replay(): void {
    assert_eq(db_regression_observe('replay_close_twice'), 1,
        'Calling close() twice must not append the same replay statement twice');
}

function test_db_stream_writes_literal_zero(): void {
    assert_eq(db_regression_observe('stream_zero'), '0', 'The nonempty string zero must not be skipped by the writer');
}

function test_db_stream_retries_short_writes(): void {
    assert_eq(db_regression_observe('stream_short_write'), 'abcdef',
        'The writer must retry positive short writes until the entire chunk is written');
}

function test_db_stream_byte_totals_are_per_stream(): void {
    assert_eq(db_regression_observe('stream_independent_totals'), 1,
        'A second stream must not inherit the first stream byte count');
}

function test_db_connection_failure_returns_disconnected_wrapper(): void {
    assert_false(db_regression_observe('connection_failure'),
        'A mysqli connection exception must produce the documented disconnected DB wrapper');
}

function test_db_result_retains_original_sql_text(): void {
    assert_eq(db_regression_observe('stored_sql'), 'SELECT id, name FROM records',
        'SQL::fetch must retain SQL text rather than assigning the wrapper to its own _sql property');
}
