<?php declare(strict_types=1);
/** @covers ../db.php */
require_once __DIR__ . '/support/regression.php';

function test_db_upsert_updates_every_supplied_non_pk_value_including_zero_and_false(): void {
    $observed = db_regression_observe('upsert_all_values');
    assert_eq($observed['id'], 7, 'The upsert closure must preserve downstream insert-ID return behavior');
    assert_eq($observed['sql'], "INSERT INTO records  (id, zero, float_zero, flag, blank, nil, code, text)"
        . " VALUES (0, 0, 0, 0, '', null, '00123', 'O\\'Reilly')"
        . " ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), zero = 0, float_zero = 0, flag = 0,"
        . " blank = '', nil = null, code = '00123', text = 'O\\'Reilly'",
        'Every supplied non-PK value must reach both insert and update clauses without truthiness filtering');
}

function test_db_upsert_allowed_key_filter_retains_zero_and_false(): void {
    assert_eq(db_regression_observe('upsert_allowed_keys'),
        'INSERT INTO records  (id, zero, flag) VALUES (0, 0, 0)'
        . ' ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), zero = 0, flag = 0',
        'Allowed-key filtering must exclude only disallowed columns, not allowed falsey values');
}

function test_db_upsert_custom_pk_is_protected_and_trusted_raw_zero_is_updated(): void {
    assert_eq(db_regression_observe('upsert_custom_pk_and_raw_zero'),
        'INSERT INTO records  (record_key, raw_zero, changed_at, flag) VALUES (0, 0, NOW(), 0)'
        . ' ON DUPLICATE KEY UPDATE record_key = LAST_INSERT_ID(record_key), raw_zero = 0, changed_at = NOW(), flag = 0',
        'Custom PK protection must survive ! normalization while valid raw zero/expression values remain unquoted');
}

function test_db_upsert_repeated_calls_generate_independent_falsey_updates(): void {
    assert_eq(db_regression_observe('upsert_repeated_calls'), [[
        'INSERT INTO records  (zero) VALUES (0) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), zero = 0',
        'INSERT INTO records  (flag) VALUES (0) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), flag = 0',
    ], [7, 7]], 'Repeated closure calls must not drop falsey updates or carry columns from previous calls');
}

function test_db_upsert_pk_only_and_positional_input_preserve_existing_behavior(): void {
    assert_eq(db_regression_observe('upsert_pk_only_and_list'), [
        'INSERT INTO records  (id) VALUES (0) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)',
        'INSERT INTO records  VALUES (0,0) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)',
    ], 'PK-only and positional calls must retain their existing LAST_INSERT_ID behavior without inventing update columns');
}

function test_db_upsert_real_execution_and_simulation_both_receive_falsey_updates(): void {
    $observed = db_regression_observe('upsert_public_execution');
    $expected = 'INSERT INTO records  (flag) VALUES (0) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), flag = 0';
    assert_eq($observed['ids'], [7, 0], 'Real insert IDs and simulation ID sentinels must remain unchanged');
    assert_eq($observed['queries'], [$expected], 'Real execution must receive the zero update while simulation executes nothing');
    assert_count($observed['logs'], 1, 'The simulated false update must be logged once');
    assert_contains($observed['logs'][0], $expected, 'Simulation must receive the same falsey update SQL');
    assert_eq($observed['errors'], [], 'Falsey update values must not manufacture execution errors');
}
