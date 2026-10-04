<?php declare(strict_types=1);
/** @covers ../db.php */
require_once __DIR__ . '/support/regression.php';

/** Normalize formatting only; no SQL interpretation or production replacement. */
function db_normalize_where_sql(string $sql): string {
    return preg_replace('/\s+/', ' ', trim($sql));
}

function test_db_where_multiple_null_values_use_is_null_and_keep_conjunctions(): void {
    assert_eq(db_normalize_where_sql(db_regression_observe('where_multiple_nulls')),
        'WHERE `first` IS NULL AND `second` IS NULL AND `third` IS NULL',
        'Every actual null must use IS NULL, with AND separating all predicates');
}

function test_db_where_mixed_null_falsey_and_text_values_keep_distinct_semantics(): void {
    $expected = "WHERE `missing` IS NULL AND `zero` = 0 AND `flag` = 0 AND `blank` = ''"
        . " AND `code` = '00123' AND `text` = 'O\\'Reilly' AND `literal` = 'NULL' AND `other` IS NULL";
    assert_eq(db_normalize_where_sql(db_regression_observe('where_mixed_values')), $expected,
        'Only actual PHP null gets IS NULL; falsey values and the text NULL keep normal equality and quoting');
}

function test_db_where_raw_null_uses_is_null_but_non_null_expressions_stay_trusted(): void {
    assert_eq(db_normalize_where_sql(db_regression_observe('where_raw_values')),
        'WHERE `nil` IS NULL AND `clock` = NOW() AND `text_null` = NULL AND `plain` IS NULL',
        'A ! prefix must be stripped for actual null too, while explicit raw SQL strings retain their existing semantics');
}

function test_db_where_non_null_predicates_retain_existing_sql(): void {
    $expected = "WHERE `zero` = 0 AND `flag` = 0 AND `blank` = '' AND `code` = '00123'"
        . " AND `literal` = 'NULL' AND `clock` = NOW()";
    assert_eq(db_normalize_where_sql(db_regression_observe('where_non_null_values')), $expected,
        'The null fix must not change non-null equality, falsey values, quoted strings or raw expressions');
}

function test_db_delete_and_update_emit_is_null_predicates_but_keep_null_assignments(): void {
    $observed = db_regression_observe('where_public_writes');
    assert_eq($observed['statuses'], [0, 0], 'Normal write return modes must remain unchanged');
    assert_eq(array_map('db_normalize_where_sql', $observed['queries']), [
        "DELETE FROM records WHERE `deleted_at` IS NULL AND `name` = 'NULL'",
        'UPDATE `records` set `name` = null WHERE `deleted_at` IS NULL AND `id` = 0',
    ], 'Public write builders must use IS NULL for predicates while retaining SET column = null assignments');
    assert_eq($observed['errors'], [], 'Generated predicates must reach the driver without wrapper errors');
}
