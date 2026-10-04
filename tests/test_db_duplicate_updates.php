<?php declare(strict_types=1);
/** @covers ../db.php */
require_once __DIR__ . '/support/regression.php';

function db_duplicate_update_suffix(string $sql): string {
    $parts = explode(' ON DUPLICATE KEY UPDATE ', $sql, 2);
    assert_count($parts, 2, 'The generated insert must include the duplicate-update clause');
    return $parts[1];
}

function test_db_duplicate_update_rejects_positional_and_empty_lists(): void {
    assert_eq(db_regression_observe('duplicate_reject_lists'), [true, true],
        'Duplicate updates require named columns; positional/empty lists cannot identify update targets');
}

function test_db_duplicate_update_excludes_protected_columns_only_from_update(): void {
    $sql = db_regression_observe('duplicate_no_update');
    assert_contains($sql, '(id, name) VALUES (7, \'bob\')', 'Protected columns must still appear in the initial insert');
    assert_eq(db_duplicate_update_suffix($sql), "`name` = 'bob'",
        'Protected primary-key columns must be omitted from the duplicate-update clause');
}

function test_db_duplicate_update_preserves_if_null_with_null_exclusion_map(): void {
    assert_eq(db_duplicate_update_suffix(db_regression_observe('duplicate_if_null')),
        "`name` = IF(`name` = '' OR `name` IS NULL, 'bob', `name`), `other` = 'alice'",
        'A null exclusion map must be accepted while conditional and ordinary columns retain their update policies');
}

function test_db_duplicate_update_quotes_text_and_retains_falsey_values(): void {
    $observed = db_regression_observe('duplicate_quoted_and_falsey');
    $suffix = db_duplicate_update_suffix($observed['sql']);
    assert_eq($suffix, "`name` = " . $observed['literal'] . ", `n` = 0, `flag` = 0, `nil` = null, `blank` = '', `code` = '00123'",
        'Duplicate-update values must use normal PHP escaping and retain zero, false, NULL, empty strings and numeric text');
    assert_contains($observed['sql'], $observed['literal'], 'The initial insert must use the same escaped text');
}
