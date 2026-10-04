<?php declare(strict_types=1);
/** @covers ../db.php */
require_once __DIR__ . '/support/regression.php';

function db_assert_connection_setup(array $observed): void {
    assert_true($observed['connected'], 'A successfully configured connection must be usable');
    assert_eq($observed['charset'], 'utf8mb4', 'Connection charset must be utf8mb4');
    assert_eq($observed['modes'], ['STRICT_TRANS_TABLES', 'ANSI_QUOTES', 'NO_ENGINE_SUBSTITUTION'],
        'Disable only NO_BACKSLASH_ESCAPES, preserving all other flags');
    assert_count($observed['calls'], 3, 'Use one charset setup and one mode statement before the application query');
    assert_eq($observed['calls'][0], ['charset', 'utf8mb4'], 'Charset must be configured first');
    assert_eq($observed['calls'][1][0], 'query', 'SQL mode must be configured second');
    assert_matches($observed['calls'][1][1], '/^SET SESSION sql_mode = /', 'Configure the session without a separate SELECT');
    assert_eq($observed['calls'][2], ['query', 'UPDATE records SET id = 7'], 'Only then run application SQL');
}

function test_db_new_connection_enforces_escaping_contract_once(): void {
    db_assert_connection_setup(db_regression_observe('connection_setup_new'));
}

function test_db_wrapped_connection_enforces_escaping_contract_once(): void {
    db_assert_connection_setup(db_regression_observe('connection_setup_wrapped'));
}

function test_db_connection_false_return_closes_handle_and_retains_error(): void {
    $observed = db_regression_observe('connection_failure_false');
    assert_false($observed['connected'], 'A false connect return must not expose an initialized but unconnected handle');
    assert_true($observed['closed'], 'Close handles on non-exception connection failures too');
    assert_contains(implode('\n', $observed['errors']), 'Connection refused', 'Use the connection-error diagnostic on failed handshakes');
}

function test_db_connection_setup_handles_empty_sql_mode(): void {
    assert_eq(db_regression_observe('connection_setup_empty_mode'), ['connected' => true, 'mode' => ''],
        'An empty SQL mode must remain valid and backslash-enabled');
}

function test_db_connection_setup_failures_close_and_reject_handle(): void {
    $observed = db_regression_observe('connection_setup_failures');
    assert_count($observed, 8, 'Exercise false returns and exceptions at both setup stages, through both factories');
    foreach ($observed as $failure) {
        $label = $failure['factory'] . ': ' . $failure['failure'];
        assert_false($failure['connected'], 'Do not expose an unsafe connection: ' . $label);
        assert_true($failure['closed'], 'Close rejected handles: ' . $label);
        assert_not_empty($failure['errors'], 'Retain a useful setup diagnostic: ' . $label);
        assert_count($failure['queries'], str_starts_with($failure['failure'], 'charset') ? 0 : 1,
            'Stop at the failed setup stage, without application queries: ' . $label);
    }
}
