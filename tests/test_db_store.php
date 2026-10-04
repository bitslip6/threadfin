<?php declare(strict_types=1);
/** @covers ../db.php */
require_once __DIR__ . '/support/regression.php';

function db_store_update_suffix(string $sql): string {
    $parts = explode(' ON DUPLICATE KEY UPDATE ', $sql, 2);
    assert_count($parts, 2, 'Object upserts must produce a duplicate-update clause');
    return $parts[1];
}

function test_db_store_no_update_attribute_protects_primary_key_in_sql(): void {
    $observed = db_regression_observe('store_no_update_sql');
    assert_eq($observed['id'], 7, 'store() must request/return the insert ID');
    assert_contains($observed['sql'], '(id, name) VALUES (7, \'bob\')', 'NoUpdate columns must remain in the initial insert');
    assert_eq(db_store_update_suffix($observed['sql']), "`name` = 'bob'",
        'The actual object upsert must exclude the NoUpdate primary key');
}

function test_db_store_if_null_attribute_generates_conditional_update(): void {
    $observed = db_regression_observe('store_if_null_sql');
    assert_eq($observed['id'], 7, 'A valid mysqli object must be accepted with native assertions enabled');
    assert_eq(db_store_update_suffix($observed['sql']), "`name` = IF(`name` = '' OR `name` IS NULL, 'bob', `name`)",
        'IfNull must reach the real SQL builder, not just an attribute extraction probe');
}

function test_db_store_not_null_attribute_retains_non_null_falsey_values(): void {
    $observed = db_regression_observe('store_not_null_sql');
    assert_contains($observed['sql'], "(zero, flag, blank) VALUES (0, 0, '')", 'NotNull must retain zero, false and empty text while omitting NULL');
    assert_eq(db_store_update_suffix($observed['sql']), "`zero` = 0, `flag` = 0, `blank` = ''",
        'NotNull means not NULL, not PHP nonempty');
}

function test_db_store_includes_public_dynamic_object_properties(): void {
    $observed = db_regression_observe('store_dynamic_sql');
    assert_eq($observed['id'], 7, 'Dynamic-property records must store successfully');
    assert_contains($observed['sql'], "(name, code) VALUES ('bob', '00123')",
        'stdClass public members must become columns rather than an empty default-value insert');
}

function test_db_store_ignores_non_instance_and_unset_properties(): void {
    $observed = db_regression_observe('store_public_instance_sql');
    assert_eq(trim($observed['sql']), "INSERT IGNORE INTO `records` (name) VALUES ('bob')",
        'Only initialized, non-null public instance properties are object data, not static/private/protected metadata');
}

function test_db_store_still_rejects_missing_connection(): void {
    assert_true(db_regression_observe('store_disconnected'),
        'Correcting the mysqli object assertion must not allow disconnected stores when assertions are enabled');
}
