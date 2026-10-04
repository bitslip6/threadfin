<?php declare(strict_types=1);
/** @covers ../db.php */
require_once __DIR__ . '/support/regression.php';

function test_db_simulation_return_modes_do_not_reuse_driver_metadata(): void {
    $observed = db_regression_observe('simulate_return_modes');
    assert_eq($observed['statuses'], [1, 0, 0], 'Simulation reports generation success, but invents no affected rows or insert ID');
    assert_eq($observed['queries'], [], 'Simulation must not execute queries');
    assert_eq($observed['errors'], ['existing diagnostic'], 'Stale driver errors must not become new errors or erase existing diagnostics');
    assert_eq($observed['last_stmt'], "INSERT INTO records VALUES (1)\n", 'Simulated writes must retain last_stmt');
    assert_count($observed['logs'], 3, 'Each generated statement must be logged once');
    foreach ($observed['logs'] as $log) {
        assert_contains($log, 'INSERT INTO records VALUES (1)', 'The generated SQL must be present');
        assert_contains($log, 'simulated', 'Logs must distinguish generation from execution');
    }
}

function test_db_simulated_read_is_empty_and_retains_interpolated_sql(): void {
    $observed = db_regression_observe('simulate_empty_read');
    $expected = "SELECT name FROM records WHERE name = 'O\\'Reilly'";
    assert_eq($observed['queries'], [], 'Simulated reads must not execute queries');
    assert_eq($observed['errors'], [], 'Stale driver diagnostics must not make a simulated read fail');
    assert_eq($observed['result'], [0, true, false, [], [], null], 'Simulated reads must return a safe empty SQL wrapper');
    assert_eq($observed['sql'], $expected, 'The wrapper must retain generated SQL, not the unresolved template');
    assert_count($observed['logs'], 1, 'A simulated read must log once');
    assert_contains($observed['logs'][0], $expected, 'Read logs must contain the complete interpolated statement');
}

function test_db_simulation_uses_real_insert_store_update_delete_and_bulk_builders(): void {
    $observed = db_regression_observe('simulate_value_builders');
    assert_eq($observed['statuses'], [1, 0, 0, 0, 0, 0], 'Public builders must retain simulation return-mode semantics');
    assert_eq($observed['queries'], [], 'No value builder may execute during simulation');
    assert_eq($observed['errors'], [], 'Generated statements are not failed queries');
    assert_count($observed['logs'], 7, 'Every builder must reach the real simulation log path');
    foreach (['alice', 'bob', 'carol', 'dan', 'eve', 'frank', 'DELETE FROM records'] as $text) {
        assert_contains(implode("\n", $observed['logs']), $text, 'Simulation must log SQL from the real builders');
    }
}

function test_db_simulation_does_not_record_unexecuted_statements_in_replay(): void {
    $observed = db_regression_observe('simulate_replay_is_empty');
    assert_eq($observed['queries'], [], 'Replay-enabled simulation must still execute nothing');
    assert_eq($observed['errors'], [], 'Replay-enabled simulation must not manufacture errors');
    assert_eq($observed['replay'], [], 'Unexecuted SQL must not enter the execution replay queue');
    assert_eq($observed['file'], '', 'Closing must not persist simulated SQL as executed writes');
    assert_count($observed['logs'], 3, 'Simulation query logs must remain available independently of replay');
}

function test_db_simulation_toggle_restores_real_execution_and_can_be_reenabled(): void {
    $observed = db_regression_observe('simulate_toggle_execution');
    assert_eq($observed['values'], [1, 7, 'bob', []], 'Disabling simulation must restore real driver results');
    assert_eq($observed['queries'], ['INSERT INTO records VALUES (2)', 'SELECT name FROM records'],
        'Only operations performed while simulation is disabled may reach the driver');
    assert_eq($observed['errors'], [], 'Toggling simulation must not leave false errors');
    assert_count($observed['logs'], 4, 'Logging enabled by simulation must continue after toggling');
}

function test_db_simulation_respects_explicit_logging_disable(): void {
    assert_eq(db_regression_observe('simulate_logging_disabled'), [1, [], [], [], []],
        'Explicitly disabling logging must suppress logs without executing queries or manufacturing errors');
}

function test_db_simulation_preserves_disconnected_write_assertion(): void {
    assert_true(db_regression_observe('simulate_disconnected_write'),
        'Simulation must not weaken the existing assertion-based connected-write contract');
}

function test_db_simulation_disabled_preserves_real_false_and_exception_errors(): void {
    $expected = [-1, [], ['BAD WRITE', 'SELECT bad'], [
        '[BAD WRITE] errno(1064) Syntax error (fixture)', '[SELECT bad] errno(1064) Syntax error (fixture)',
    ], []];
    assert_eq(db_regression_observe('simulate_disabled_real_failures'), [$expected, $expected],
        'After disabling simulation, real false-return and exception failures must still be reported');
}
