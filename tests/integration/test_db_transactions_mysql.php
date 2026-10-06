<?php declare(strict_types=1);
/** Opt-in native transaction tests. @covers ../../db.php */
require_once __DIR__ . '/../support/transaction_transport.php';
/** @type integration */
function test_db_transaction_mysql_commit_rollback(): void {
    assert_eq(db_transaction_observe('commit_rollback', true), true, 'Native commit_rollback contract');
}
/** @type integration */
function test_db_transaction_mysql_ignored_failure_nested(): void {
    assert_eq(db_transaction_observe('ignored_failure_nested', true), true, 'Native ignored_failure_nested contract');
}
/** @type integration */
function test_db_transaction_mysql_ownership(): void {
    assert_eq(db_transaction_observe('ownership', true), true, 'Native ownership contract');
}
/** @type integration */
function test_db_transaction_mysql_policy_simulation(): void {
    assert_eq(db_transaction_observe('policy_simulation', true), true, 'Native policy_simulation contract');
}
/** @type integration */
function test_db_transaction_mysql_replay(): void {
    assert_eq(db_transaction_observe('replay', true), true, 'Native replay contract');
}
/** @type integration */
function test_db_transaction_mysql_deadlock(): void {
    assert_eq(db_transaction_observe('deadlock', true), true, 'Native deadlock contract');
}
/** @type integration */
function test_db_transaction_mysql_cleanup(): void {
    assert_eq(db_transaction_observe('cleanup', true), true, 'Native cleanup contract');
}
/** @type integration */
function test_db_transaction_mysql_completion_chain_commit(): void {
    assert_eq(db_transaction_observe('completion_chain_commit', true), true, 'Native completion_chain_commit leaves an idle reusable session');
}
/** @type integration */
function test_db_transaction_mysql_completion_chain_rollback(): void {
    assert_eq(db_transaction_observe('completion_chain_rollback', true), true, 'Native completion_chain_rollback leaves an idle reusable session');
}
/** @type integration */
function test_db_transaction_mysql_completion_release_commit(): void {
    assert_eq(db_transaction_observe('completion_release_commit', true), true, 'Native completion_release_commit leaves an idle reusable session');
}
/** @type integration */
function test_db_transaction_mysql_completion_release_rollback(): void {
    assert_eq(db_transaction_observe('completion_release_rollback', true), true, 'Native completion_release_rollback leaves an idle reusable session');
}
