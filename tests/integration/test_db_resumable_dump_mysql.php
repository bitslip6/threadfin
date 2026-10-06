<?php declare(strict_types=1);
require_once __DIR__ . "/../support/dump_transport.php";
function test_db_resumable_dump_mysql_restore(): void { assert_eq(db_dump_observe("restore",true),true,"native dump contract"); }
function test_db_resumable_dump_mysql_tiny_budget(): void { assert_eq(db_dump_observe("tiny_budget",true),true,"native dump contract"); }
function test_db_resumable_dump_mysql_ahead(): void { assert_eq(db_dump_observe("ahead",true),true,"native dump contract"); }
function test_db_resumable_dump_mysql_crash_header(): void { assert_eq(db_dump_observe("crash_header",true),true,"native dump contract"); }
function test_db_resumable_dump_mysql_crash_ddl(): void { assert_eq(db_dump_observe("crash_ddl",true),true,"native dump contract"); }
function test_db_resumable_dump_mysql_crash_batch(): void { assert_eq(db_dump_observe("crash_batch",true),true,"native dump contract"); }
function test_db_resumable_dump_mysql_crash_batch_after(): void { assert_eq(db_dump_observe("crash_batch_after",true),true,"native dump contract"); }
function test_db_resumable_dump_mysql_crash_batch_partial(): void { assert_eq(db_dump_observe("crash_batch_partial",true),true,"native dump contract"); }
function test_db_resumable_dump_mysql_crash_footer(): void { assert_eq(db_dump_observe("crash_footer",true),true,"native dump contract"); }
function test_db_resumable_dump_mysql_crash_footer_after(): void { assert_eq(db_dump_observe("crash_footer_after",true),true,"native dump contract"); }
function test_db_resumable_dump_mysql_schema_drift(): void { assert_eq(db_dump_observe("schema_drift",true),true,"native dump contract"); }
function test_db_resumable_dump_mysql_source_drift(): void { assert_eq(db_dump_observe("source_drift",true),true,"native dump contract"); }
function test_db_resumable_dump_mysql_short(): void { assert_eq(db_dump_observe("short",true),true,"native dump contract"); }
function test_db_resumable_dump_mysql_corrupt(): void { assert_eq(db_dump_observe("corrupt",true),true,"native dump contract"); }
function test_db_resumable_dump_mysql_identity(): void { assert_eq(db_dump_observe("identity",true),true,"native dump contract"); }
function test_db_resumable_dump_mysql_checkpoint_corrupt(): void { assert_eq(db_dump_observe("checkpoint_corrupt",true),true,"native dump contract"); }
function test_db_resumable_dump_mysql_unsupported_keys(): void { assert_eq(db_dump_observe("unsupported_keys",true),true,"native dump contract"); }
function test_db_resumable_dump_mysql_unsupported_types(): void { assert_eq(db_dump_observe("unsupported_types",true),true,"native dump contract"); }
function test_db_resumable_dump_mysql_unsupported_schema(): void { assert_eq(db_dump_observe("unsupported_schema",true),true,"native dump contract"); }
function test_db_resumable_dump_mysql_unsupported_modes(): void { assert_eq(db_dump_observe("unsupported_modes",true),true,"native dump contract"); }
function test_db_resumable_dump_mysql_metadata_visibility(): void { assert_eq(db_dump_observe("metadata_visibility",true),true,"native dump contract"); }
function test_db_resumable_dump_mysql_freeze(): void { assert_eq(db_dump_observe("freeze",true),true,"native dump contract"); }
function test_db_resumable_dump_mysql_simulation(): void { assert_eq(db_dump_observe("simulation",true),true,"native dump contract"); }
function test_db_resumable_dump_mysql_empty(): void { assert_eq(db_dump_observe("empty",true),true,"native dump contract"); }
function test_db_resumable_dump_mysql_unsupported_sinks(): void { assert_eq(db_dump_observe("unsupported_sinks",true),true,"native dump contract"); }
function test_db_resumable_dump_mysql_integer_keys(): void { assert_eq(db_dump_observe("integer_keys",true),true,"native dump contract"); }
function test_db_resumable_dump_mysql_autocommit(): void { assert_eq(db_dump_observe("autocommit",true),true,"native dump contract"); }
function test_db_resumable_dump_mysql_source_autocommit(): void { assert_eq(db_dump_observe("source_autocommit",true),true,"native dump contract"); }
function test_db_resumable_dump_mysql_source_idle(): void { assert_eq(db_dump_observe("source_idle",true),true,"native dump contract"); }
function test_db_resumable_dump_mysql_source_instance(): void { assert_eq(db_dump_observe("source_instance",true),true,"distinct source instances cannot share checkpoints"); }
function test_db_resumable_dump_mysql_year2(): void { assert_eq(db_dump_observe("year2",true),true,"YEAR(2) rejects before output"); }
function test_db_resumable_dump_mysql_year4(): void { assert_eq(db_dump_observe("year4",true),true,"YEAR and YEAR(4) preserve expanded years"); }
