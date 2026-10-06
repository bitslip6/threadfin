<?php declare(strict_types=1);
/** @covers ../../db.php ../../db_stream.php */
require_once __DIR__ . "/../support/streaming_cases.php";
/** @type integration */
function test_db_streaming_mysql_raw(): void { assert_eq(db_streaming_observe("raw", true), true, "Native streaming raw contract"); }
/** @type integration */
function test_db_streaming_mysql_prepared(): void { assert_eq(db_streaming_observe("prepared", true), true, "Native streaming prepared contract"); }
/** @type integration */
function test_db_streaming_mysql_cancel(): void { assert_eq(db_streaming_observe("cancel", true), true, "Native streaming cancel contract"); }
/** @type integration */
function test_db_streaming_mysql_empty(): void { assert_eq(db_streaming_observe("empty", true), true, "Native streaming empty contract"); }
/** @type integration */
function test_db_streaming_mysql_simulation(): void { assert_eq(db_streaming_observe("simulation", true), true, "Native streaming simulation contract"); }
/** @type integration */
function test_db_streaming_mysql_db_close(): void { assert_eq(db_streaming_observe("db_close", true), true, "Native streaming db_close contract"); }
/** @type integration */
function test_db_streaming_mysql_transaction(): void { assert_eq(db_streaming_observe("transaction", true), true, "Native streaming transaction contract"); }
/** @type integration */
function test_db_streaming_mysql_fetch_raw_failure(): void { assert_eq(db_streaming_observe("fetch_raw_failure", true), true, "Native streaming fetch_raw_failure contract"); }
/** @type integration */
function test_db_streaming_mysql_fetch_prepared_failure(): void { assert_eq(db_streaming_observe("fetch_prepared_failure", true), true, "Native streaming fetch_prepared_failure contract"); }
/** @type integration */
function test_db_streaming_mysql_errors(): void { assert_eq(db_streaming_observe("errors", true), true, "Native streaming errors contract"); }
/** @type integration */
function test_db_streaming_mysql_replay(): void { assert_eq(db_streaming_observe("replay", true), true, "Native streaming replay contract"); }
