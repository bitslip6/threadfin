<?php declare(strict_types=1);
/** @covers ../db.php */
require_once __DIR__ . '/support/regression.php';

function test_db_sql_driver_results_retain_exact_original_text_through_reads_and_close(): void {
    $text = "SELECT '0';\n-- déjà vu";
    assert_eq(db_regression_observe('stored_sql_driver_lifecycle'), [
        [$text, [], $text], [$text, [['id' => '1']], $text],
    ], 'Empty and nonempty driver-backed wrappers must store SQL text rather than a reference to themselves');
}

function test_db_sql_array_and_null_results_preserve_exact_metadata(): void {
    assert_eq(db_regression_observe('stored_sql_array_lifecycle'), array_fill(0, 3, "SELECT '0'\0\n-- metadata"),
        'The driver fix must preserve existing array/null-backed metadata behavior');
}

function test_db_sql_public_fetch_retains_interpolated_success_failed_and_simulated_text(): void {
    assert_eq(db_regression_observe('stored_sql_public_paths'), [
        ["SELECT name FROM records WHERE name = 'O\\'Reilly'", 1], ['SELECT bad', 0], ['SELECT 0', 0],
    ], 'Every public read path must retain the actual generated SQL alongside its result');
}
