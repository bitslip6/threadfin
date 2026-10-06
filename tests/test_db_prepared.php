<?php declare(strict_types=1);
/** @covers ../db.php */
require_once __DIR__ . '/support/prepared_transport.php';
function test_db_prepared_binding(): void {
    assert_eq(db_prepared_observe('binding'), true, 'Prepared binding contract');
}
function test_db_prepared_validation(): void {
    assert_eq(db_prepared_observe('validation'), true, 'Prepared validation contract');
}
function test_db_prepared_simulation(): void {
    assert_eq(db_prepared_observe('simulation'), true, 'Prepared simulation contract');
}
function test_db_prepared_buffered(): void {
    assert_eq(db_prepared_observe('buffered'), true, 'Prepared buffered contract');
}
function test_db_prepared_empty(): void {
    assert_eq(db_prepared_observe('empty'), true, 'Prepared empty contract');
}
function test_db_prepared_modes(): void {
    assert_eq(db_prepared_observe('modes'), true, 'Prepared modes contract');
}
function test_db_prepared_count(): void {
    assert_eq(db_prepared_observe('count'), true, 'Prepared count contract');
}
function test_db_prepared_no_rowset(): void {
    assert_eq(db_prepared_observe('no_rowset'), true, 'Prepared no_rowset contract');
}
function test_db_prepared_failures(): void {
    assert_eq(db_prepared_observe('failures'), true, 'Prepared failures contract');
}
function test_db_prepared_old_exception(): void {
    assert_eq(db_prepared_observe('old_exception'), true, 'Prepared old_exception contract');
}
function test_db_prepared_transaction(): void {
    assert_eq(db_prepared_observe('transaction'), true, 'Prepared transaction contract');
}
function test_db_prepared_deadlock(): void {
    assert_eq(db_prepared_observe('deadlock'), true, 'Prepared deadlock contract');
}
function test_db_prepared_replay(): void {
    assert_eq(db_prepared_observe('replay'), true, 'Prepared replay contract');
}
function test_db_prepared_cleanup(): void {
    assert_eq(db_prepared_observe('cleanup'), true, 'Prepared cleanup contract');
}
function test_db_prepared_disconnected(): void {
    assert_eq(db_prepared_observe('disconnected'), true, 'Prepared disconnected contract');
}
function test_db_prepared_ordinary(): void {
    assert_eq(db_prepared_observe('ordinary'), true, 'Prepared ordinary contract');
}
function test_db_prepared_cleanup_success(): void {
    assert_eq(db_prepared_observe('cleanup_success'), true, 'Prepared cleanup_success contract');
}
function test_db_prepared_disconnect_replay(): void {
    assert_eq(db_prepared_observe('disconnect_replay'), true, 'Prepared disconnect_replay contract');
}
function test_db_prepared_disconnect_errors(): void {
    assert_eq(db_prepared_observe('disconnect_errors'), true, 'Prepared disconnect_errors contract');
}
function test_db_prepared_trace_configuration(): void {
    assert_eq(db_prepared_observe('trace_configuration'), true, 'Configured PHP traces omit parameter arguments');
}
function test_db_prepared_transaction_guards(): void {
    assert_eq(db_prepared_observe('transaction_guards'), true, 'Caught prepared count/rowset/replay failures prevent commit');
}
function test_db_prepared_warnings(): void {
    assert_eq(db_prepared_observe('warnings'), true, 'Prepared warnings are private and handler state restored');
}
function test_db_prepared_cleanup_warnings(): void {
    assert_eq(db_prepared_observe('cleanup_warnings'), true, 'Void cleanup warnings are private safe cleanup failures');
}
