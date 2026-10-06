<?php declare(strict_types=1);
/** @covers ../db.php */
require_once __DIR__ . '/support/transaction_transport.php';
function test_db_transaction_falsey(): void {
    assert_eq(db_transaction_observe('falsey'), true, 'falsey transaction contract');
}
function test_db_transaction_callback(): void {
    assert_eq(db_transaction_observe('callback'), true, 'callback transaction contract');
}
function test_db_transaction_write_failure(): void {
    assert_eq(db_transaction_observe('write_failure'), true, 'write_failure transaction contract');
}
function test_db_transaction_read_failure(): void {
    assert_eq(db_transaction_observe('read_failure'), true, 'read_failure transaction contract');
}
function test_db_transaction_exception_failure(): void {
    assert_eq(db_transaction_observe('exception_failure'), true, 'exception_failure transaction contract');
}
function test_db_transaction_exception_without_sqlstate_write(): void {
    assert_eq(db_transaction_observe('exception_without_sqlstate_write'), true, 'Old mysqli exceptions still latch ignored write failures');
}
function test_db_transaction_exception_without_sqlstate_read(): void {
    assert_eq(db_transaction_observe('exception_without_sqlstate_read'), true, 'Old mysqli exceptions still latch ignored read failures');
}
function test_db_transaction_sentinel(): void {
    assert_eq(db_transaction_observe('sentinel'), true, 'sentinel transaction contract');
}
function test_db_transaction_nested(): void {
    assert_eq(db_transaction_observe('nested'), true, 'nested transaction contract');
}
function test_db_transaction_deadlock(): void {
    assert_eq(db_transaction_observe('deadlock'), true, 'deadlock transaction contract');
}
function test_db_transaction_lost_connection(): void {
    assert_eq(db_transaction_observe('lost_connection'), true, 'lost_connection transaction contract');
}
function test_db_transaction_cleanup(): void {
    assert_eq(db_transaction_observe('cleanup'), true, 'cleanup transaction contract');
}
function test_db_transaction_commit_failure(): void {
    assert_eq(db_transaction_observe('commit_failure'), true, 'commit_failure transaction contract');
}
function test_db_transaction_release_failure(): void {
    assert_eq(db_transaction_observe('release_failure'), true, 'release_failure transaction contract');
}
function test_db_transaction_ownership(): void {
    assert_eq(db_transaction_observe('ownership'), true, 'ownership transaction contract');
}
function test_db_transaction_policy(): void {
    assert_eq(db_transaction_observe('policy'), true, 'policy transaction contract');
}
function test_db_transaction_lifecycle_guards(): void {
    assert_eq(db_transaction_observe('lifecycle_guards'), true, 'lifecycle_guards transaction contract');
}
function test_db_transaction_simulation(): void {
    assert_eq(db_transaction_observe('simulation'), true, 'simulation transaction contract');
}
function test_db_transaction_replay(): void {
    assert_eq(db_transaction_observe('replay'), true, 'replay transaction contract');
}
function test_db_transaction_ordinary(): void {
    assert_eq(db_transaction_observe('ordinary'), true, 'ordinary transaction contract');
}

function test_db_transaction_builder_repair(): void {
    assert_eq(db_transaction_observe('builder_repair'), true, 'Caught pure builder validation can be repaired');
}
