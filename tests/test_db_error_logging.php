<?php declare(strict_types=1);
/** @covers ../db.php */
require_once __DIR__ . '/support/regression.php';

function test_db_close_appends_non_duplicate_errors_without_modifying_original_diagnostics(): void {
    $observed = db_regression_observe('error_log_mixed');
    $retained = [
        0 => '[BAD SQL] errno(1064) Syntax error near BAD',
        2 => '[LOCK SQL] errno(1213) Deadlock found when trying to get lock',
        3 => '[SELECT Duplicate FROM records] errno(1064) Syntax error near Duplicate',
        4 => 'Connection refused (fixture)',
    ];
    assert_eq($observed['log'], "existing log\n" . print_r($retained, true),
        'Append non-duplicate diagnostics in order, preserving keys and the existing log; use errno rather than SQL keywords');
    assert_eq($observed['errors'], $observed['before'],
        'Filtering file output must not remove duplicate or other errors from the public diagnostic list');
}

function test_db_close_duplicate_only_errors_do_not_append_empty_or_duplicate_log_entries(): void {
    assert_eq(db_regression_observe('error_log_duplicate_only'), array_fill(0, 4, "existing log\n"),
        'Duplicate filtering must handle offset zero and mixed case, and an empty filtered set must not append Array()');
}

function test_db_close_preserves_uncoded_diagnostics_including_falsey_text(): void {
    $errors = ['Connection refused', '0', '', 'Accès refusé'];
    assert_eq(db_regression_observe('error_log_uncoded'), [print_r($errors, true), $errors],
        'Diagnostics without errno must be retained unless explicitly recognized as duplicate messages');
}

function test_db_close_logs_public_write_and_read_failures_for_false_returns_and_exceptions(): void {
    $sqls = ['BAD WRITE', 'SELECT BAD', "UPDATE records\nSET Duplicate = BAD", "SELECT 'Duplicate entry' FROM broken"];
    $errors = array_map(fn($sql) => "[$sql] errno(1064) Syntax error (fixture)", $sqls);
    $one = [[-1, 0, -1, 0], $errors, true];
    assert_eq(db_regression_observe('error_log_public_failures'), [[$one, $one], str_repeat(print_r($errors, true), 2)],
        'Both driver failure modes must retain all generated write/read diagnostics, including multiline SQL containing duplicate words');
}

function test_db_close_logs_connection_setup_failures_even_when_already_disconnected(): void {
    $falseError = ['Unable to set database SQL mode: SQL mode setup rejected (fixture)'];
    $exceptionError = ['SQL mode setup rejected (fixture)'];
    assert_eq(db_regression_observe('error_log_setup_failures'), [
        [[false, true, $falseError], [false, true, $exceptionError]],
        print_r($falseError, true) . print_r($exceptionError, true),
    ], 'Explicit close must persist setup diagnostics even after factory cleanup has already closed the handle');
}

function test_db_close_with_no_errors_leaves_existing_log_untouched(): void {
    assert_eq(db_regression_observe('error_log_empty'), ["existing log\n", true, []],
        'Closing a healthy wrapper must close its handle without writing an empty error block');
}

function test_db_close_respects_disabled_error_logging_and_preserves_diagnostics(): void {
    assert_eq(db_regression_observe('error_log_disabled'), [false, true,
        ['[BAD SQL] errno(1064) Syntax error near BAD'], 0],
        'A false SQL_ERROR_FILE must disable file output without preventing close or clearing diagnostics');
}
