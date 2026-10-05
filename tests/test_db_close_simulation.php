<?php declare(strict_types=1);
/** @covers ../db.php */
require_once __DIR__ . '/support/regression.php';

function test_db_repeated_close_does_not_duplicate_error_log_or_clear_public_errors(): void {
    $first = print_r(['[BAD] errno(1064) Syntax error'], true);
    assert_eq(db_regression_observe('error_close_idempotent'), [$first, $first,
        ['[BAD] errno(1064) Syntax error', '[INSERT] errno(1062) Duplicate entry']],
        'Repeated close must not append unchanged diagnostics, and public errors remain intact');
}

function test_db_later_close_logs_only_new_or_replaced_diagnostic_entries(): void {
    assert_eq(db_regression_observe('error_close_new_entries'), [print_r(['first'], true)
        . print_r([0 => 'changed', 2 => 'first', 3 => 'new'], true),
        ['changed', 'Duplicate entry', 'first', 'new']],
        'Track entries by key/value rather than globally deduplicating text; changed/new entries are logged once');
}

function test_db_close_observes_cleared_diagnostics_without_suppressing_later_events(): void {
    assert_eq(db_regression_observe('error_close_observed_reset'), str_repeat(print_r(['first'], true), 2),
        'An observed reset starts a new diagnostic list; subsequent repeated close still appends nothing');
}

function test_db_enabling_replay_during_simulation_does_not_inspect_driver_or_create_journal(): void {
    assert_eq(db_regression_observe('replay_simulation_defers_init'), [[], [], [], '', false, 1],
        'Replay configuration during simulation must defer driver/file initialization, even if the driver would reject inspection');
}

function test_db_disabling_simulation_initializes_deferred_replay_once_with_real_session_settings(): void {
    $inspection = 'SELECT @@SESSION.sql_mode AS sql_mode, @@SESSION.autocommit AS autocommit';
    assert_eq(db_regression_observe('replay_simulation_transition'), [[[], ''], [1, 1],
        [$inspection, 'INSERT INTO records VALUES (1)'],
        db_expected_replay_packet(['INSERT INTO records VALUES (1)'], 0), []],
        'Capture settings only when leaving simulation; simulated SQL never enters the real journal');
}

function test_db_failed_deferred_replay_initialization_keeps_simulation_active_until_retry(): void {
    $inspection = 'SELECT @@SESSION.sql_mode AS sql_mode, @@SESSION.autocommit AS autocommit';
    assert_eq(db_regression_observe('replay_simulation_transition_failure'), [true, [true, ''],
        [$inspection, $inspection, 'INSERT INTO records VALUES (1)'],
        db_expected_replay_packet(['INSERT INTO records VALUES (1)']), []],
        'Initialization failure must not silently switch to real execution; an explicit retry can recover');
}

function test_db_existing_real_replay_header_is_not_recaptured_when_simulation_is_toggled(): void {
    assert_eq(db_regression_observe('replay_real_before_simulation'), [['INSERT INTO records VALUES (1)'],
        db_expected_replay_packet(['INSERT INTO records VALUES (1)']), []],
        'Ordinary replay-before-simulation behavior remains unchanged and does not add inspection queries on toggles');
}
