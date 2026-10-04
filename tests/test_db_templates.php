<?php declare(strict_types=1);
/** @covers ../db.php */
require_once __DIR__ . '/support/regression.php';

function test_db_template_array_and_object_values_preserve_null_falsey_and_quoted_text(): void {
    $expected = "SELECT null, 0, 0, '', '00123', 'O\\'Reilly', null";
    assert_eq(db_regression_observe('template_values'), [$expected, $expected],
        'Named values must retain null/zero/false/empty text and shared quoting, including repeated placeholders');
}

function test_db_template_missing_parameters_are_rejected_for_arrays_objects_and_raw_placeholders(): void {
    assert_eq(db_regression_observe('template_missing_values'), array_fill(0, 9, [true, true]),
        'Absent/uninitialized/inaccessible parameters must raise argument/bounds exceptions identifying the placeholder');
}

function test_db_template_reads_initialized_public_object_fields_including_null(): void {
    assert_eq(db_regression_observe('template_public_fields'), "SELECT 'bob', null",
        'Public object fields must be readable, with explicit null distinguished from absent/uninitialized fields');
}

function test_db_template_trusted_raw_expressions_remain_unquoted_and_null_stays_sql_null(): void {
    $expected = "SELECT COUNT(*), null, 'COUNT(*)'";
    assert_eq(db_regression_observe('template_raw_values'), [$expected, $expected],
        'Trusted ! expressions must remain unquoted, but an explicit null must not disappear into empty SQL');
}

function test_db_template_without_placeholders_accepts_empty_parameter_containers(): void {
    assert_eq(db_regression_observe('template_no_placeholders'), array_fill(0, 4, 'SELECT 1'),
        'Plain SQL must work without parameters or with empty array/object containers');
}

function test_db_template_rejects_scalar_parameter_containers_with_argument_exception(): void {
    assert_eq(db_regression_observe('template_invalid_input'), [true, true, true],
        'Unsupported scalar containers must raise InvalidArgumentException rather than fabricate parameter-name data');
}

function test_db_missing_template_parameter_is_rejected_before_execution_or_simulation_logging(): void {
    $expected = [true, [], [], [], 'unchanged'];
    assert_eq(db_regression_observe('template_public_missing_is_not_executed'), [$expected, $expected],
        'Partially substituted SQL must never execute or log as a generated statement when another parameter is missing');
}

function test_db_public_fetch_executes_generated_null_and_numeric_string_literals(): void {
    assert_eq(db_regression_observe('template_public_values'), [
        ["SELECT null, '00123'"], [['name' => 'bob']], [],
    ], 'The real public fetch path must send SQL null and quoted numeric-string values to the driver');
}
