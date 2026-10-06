<?php declare(strict_types=1);
/** @covers ../db.php ../db_stream.php */
require_once __DIR__ . "/support/streaming_cases.php";
function test_db_streaming_raw(): void { assert_eq(db_streaming_observe("raw"), true, "Streaming raw contract"); }
function test_db_streaming_prepared(): void { assert_eq(db_streaming_observe("prepared"), true, "Streaming prepared contract"); }
function test_db_streaming_empty(): void { assert_eq(db_streaming_observe("empty"), true, "Streaming empty contract"); }
function test_db_streaming_simulation(): void { assert_eq(db_streaming_observe("simulation"), true, "Streaming simulation contract"); }
function test_db_streaming_busy(): void { assert_eq(db_streaming_observe("busy"), true, "Streaming busy contract"); }
function test_db_streaming_rewind(): void { assert_eq(db_streaming_observe("rewind"), true, "Streaming rewind contract"); }
function test_db_streaming_fetch_failure(): void { assert_eq(db_streaming_observe("fetch_failure"), true, "Streaming fetch_failure contract"); }
function test_db_streaming_startup_failure(): void { assert_eq(db_streaming_observe("startup_failure"), true, "Streaming startup_failure contract"); }
function test_db_streaming_cleanup(): void { assert_eq(db_streaming_observe("cleanup"), true, "Streaming cleanup contract"); }
function test_db_streaming_db_close(): void { assert_eq(db_streaming_observe("db_close"), true, "Streaming db_close contract"); }
function test_db_streaming_transaction_exit(): void { assert_eq(db_streaming_observe("transaction_exit"), true, "Streaming transaction_exit contract"); }
function test_db_streaming_transaction_exception(): void { assert_eq(db_streaming_observe("transaction_exception"), true, "Streaming transaction_exception contract"); }
function test_db_streaming_transaction_busy(): void { assert_eq(db_streaming_observe("transaction_busy"), true, "Streaming transaction_busy contract"); }
function test_db_streaming_transaction_consumed(): void { assert_eq(db_streaming_observe("transaction_consumed"), true, "Streaming transaction_consumed contract"); }
function test_db_streaming_replay(): void { assert_eq(db_streaming_observe("replay"), true, "Streaming replay contract"); }
function test_db_streaming_validation(): void { assert_eq(db_streaming_observe("validation"), true, "Streaming validation contract"); }
function test_db_streaming_ordinary(): void { assert_eq(db_streaming_observe("ordinary"), true, "Streaming ordinary contract"); }
function test_db_streaming_warnings(): void { assert_eq(db_streaming_observe("warnings"), true, "Streaming warnings contract"); }
function test_db_streaming_raw_cleanup(): void { assert_eq(db_streaming_observe("raw_cleanup"), true, "Streaming raw_cleanup contract"); }
function test_db_streaming_cleanup_primary(): void { assert_eq(db_streaming_observe("cleanup_primary"), true, "Streaming cleanup_primary contract"); }
function test_db_streaming_disconnect_replay(): void { assert_eq(db_streaming_observe("disconnect_replay"), true, "Streaming disconnect_replay contract"); }
function test_db_streaming_transaction_fetch_failure(): void { assert_eq(db_streaming_observe("transaction_fetch_failure"), true, "Streaming transaction_fetch_failure contract"); }
