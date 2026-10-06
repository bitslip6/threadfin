<?php declare(strict_types=1);
/** @covers ../../db.php */
require_once __DIR__ . '/../support/prepared_transport.php';
/** @type integration */
function test_db_prepared_mysql_roundtrip(): void {
    assert_eq(db_prepared_observe('roundtrip', true), true, 'Native prepared roundtrip contract');
}
/** @type integration */
function test_db_prepared_mysql_modes(): void {
    assert_eq(db_prepared_observe('modes', true), true, 'Native prepared modes contract');
}
/** @type integration */
function test_db_prepared_mysql_errors(): void {
    assert_eq(db_prepared_observe('errors', true), true, 'Native prepared errors contract');
}
/** @type integration */
function test_db_prepared_mysql_validation(): void {
    assert_eq(db_prepared_observe('validation', true), true, 'Native prepared validation contract');
}
/** @type integration */
function test_db_prepared_mysql_simulation(): void {
    assert_eq(db_prepared_observe('simulation', true), true, 'Native prepared simulation contract');
}
/** @type integration */
function test_db_prepared_mysql_transaction(): void {
    assert_eq(db_prepared_observe('transaction', true), true, 'Native prepared transaction contract');
}
/** @type integration */
function test_db_prepared_mysql_replay(): void {
    assert_eq(db_prepared_observe('replay', true), true, 'Native prepared replay contract');
}
/** @type integration */
function test_db_prepared_mysql_ordinary(): void {
    assert_eq(db_prepared_observe('ordinary', true), true, 'Native prepared ordinary contract');
}
/** @type integration */
function test_db_prepared_mysql_warnings_off(): void {
    assert_eq(db_prepared_observe('warnings_off', true), true, 'OFF mode safe errors and handler restoration');
}
/** @type integration */
function test_db_prepared_mysql_warnings_report(): void {
    assert_eq(db_prepared_observe('warnings_report', true), true, 'Warning-only mode cannot expose bound values');
}
/** @type integration */
function test_db_prepared_mysql_warnings_strict(): void {
    assert_eq(db_prepared_observe('warnings_strict', true), true, 'STRICT mode safe errors and handler restoration');
}
