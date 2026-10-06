<?php declare(strict_types=1);
/** @covers ../../db.php ../../db_stream.php ../../db_telemetry.php */
require_once __DIR__ . "/../support/telemetry_cases.php";
/** @type integration */
function test_db_telemetry_mysql_privacy(): void { assert_eq(db_telemetry_observe("privacy",true),true,"Native telemetry privacy"); }
/** @type integration */
function test_db_telemetry_mysql_failures(): void { assert_eq(db_telemetry_observe("failures",true),true,"Native telemetry failures"); }
/** @type integration */
function test_db_telemetry_mysql_simulation(): void { assert_eq(db_telemetry_observe("simulation",true),true,"Native telemetry simulation"); }
/** @type integration */
function test_db_telemetry_mysql_streams(): void { assert_eq(db_telemetry_observe("streams",true),true,"Native telemetry streams"); }
/** @type integration */
function test_db_telemetry_mysql_observer_isolation(): void { assert_eq(db_telemetry_observe("observer_isolation",true),true,"Native telemetry observer_isolation"); }
/** @type integration */
function test_db_telemetry_mysql_stream_recursion(): void { assert_eq(db_telemetry_observe("stream_recursion",true),true,"Native telemetry stream_recursion"); }
/** @type integration */
function test_db_telemetry_mysql_threshold(): void { assert_eq(db_telemetry_observe("threshold",true),true,"Native telemetry threshold"); }
/** @type integration */
function test_db_telemetry_mysql_controls(): void { assert_eq(db_telemetry_observe("controls",true),true,"Native telemetry controls"); }
/** @type integration */
function test_db_telemetry_mysql_stream_raw_failure(): void { assert_eq(db_telemetry_observe("stream_raw_failure",true),true,"Native telemetry stream_raw_failure"); }
/** @type integration */
function test_db_telemetry_mysql_stream_prepared_failure(): void { assert_eq(db_telemetry_observe("stream_prepared_failure",true),true,"Native telemetry stream_prepared_failure"); }
/** @type integration */
function test_db_telemetry_mysql_observer_disable_stream(): void { assert_eq(db_telemetry_observe('observer_disable_stream',true),true,'Native retained-stream observer disable isolation'); }
/** @type integration */
function test_db_telemetry_mysql_observer_replace_stream(): void { assert_eq(db_telemetry_observe('observer_replace_stream',true),true,'Native retained-stream observer replacement isolation'); }
/** @type integration */
function test_db_telemetry_mysql_nested_raw_success(): void { assert_eq(db_telemetry_observe("nested_raw_success",true),true,"Native telemetry nested_raw_success"); }
/** @type integration */
function test_db_telemetry_mysql_nested_raw_failure(): void { assert_eq(db_telemetry_observe("nested_raw_failure",true),true,"Native telemetry nested_raw_failure"); }
/** @type integration */
function test_db_telemetry_mysql_nested_buffered(): void { assert_eq(db_telemetry_observe("nested_buffered",true),true,"Native telemetry nested_buffered"); }
/** @type integration */
function test_db_telemetry_mysql_nested_context_restore(): void { assert_eq(db_telemetry_observe("nested_context_restore",true),true,"Native telemetry nested_context_restore"); }
/** @type integration */
function test_db_telemetry_mysql_nested_streams(): void { assert_eq(db_telemetry_observe("nested_streams",true),true,"Native telemetry nested_streams"); }
/** @type integration */
function test_db_telemetry_mysql_nested_stream_busy(): void { assert_eq(db_telemetry_observe("nested_stream_busy",true),true,"Native telemetry nested_stream_busy"); }
/** @type integration */
function test_db_telemetry_mysql_nested_stream_busy_disabled(): void { assert_eq(db_telemetry_observe("nested_stream_busy_disabled",true),true,"Native telemetry nested_stream_busy_disabled"); }
/** @type integration */
function test_db_telemetry_mysql_nested_sampling(): void { assert_eq(db_telemetry_observe("nested_sampling",true),true,"Native telemetry nested_sampling"); }
/** @type integration */
function test_db_telemetry_mysql_nested_disable(): void { assert_eq(db_telemetry_observe("nested_disable",true),true,"Native telemetry nested_disable"); }
/** @type integration */
function test_db_telemetry_mysql_nested_busy_transaction(): void { assert_eq(db_telemetry_observe('nested_busy_transaction',true),true,'Native nested busy rollback and reuse'); }
/** @type integration */
function test_db_telemetry_mysql_nested_simulation(): void { assert_eq(db_telemetry_observe('nested_simulation',true),true,'Native nested simulation has independent events without I/O'); }
/** @type integration */
function test_db_telemetry_mysql_mode_fetch_toggle(): void { assert_eq(db_telemetry_observe("mode_fetch_toggle",true),true,"Telemetry mode_fetch_toggle"); }
/** @type integration */
function test_db_telemetry_mysql_mode_stream_toggle(): void { assert_eq(db_telemetry_observe("mode_stream_toggle",true),true,"Telemetry mode_stream_toggle"); }
/** @type integration */
function test_db_telemetry_mysql_mode_nested(): void { assert_eq(db_telemetry_observe("mode_nested",true),true,"Telemetry mode_nested"); }
/** @type integration */
function test_db_telemetry_mysql_mode_retained(): void { assert_eq(db_telemetry_observe("mode_retained",true),true,"Telemetry mode_retained"); }
/** @type integration */
function test_db_telemetry_mysql_mode_validation(): void { assert_eq(db_telemetry_observe("mode_validation",true),true,"Telemetry mode_validation"); }
/** @type integration */
function test_db_telemetry_mysql_mode_execution_failure(): void { assert_eq(db_telemetry_observe("mode_execution_failure",true),true,"Telemetry mode_execution_failure"); }
