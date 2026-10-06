<?php declare(strict_types=1);
require_once __DIR__."/../support/verify_transport.php";
function test_db_verify_mysql_match(): void { assert_eq(db_verify_observe("match",true), true, "native sandbox contract"); }
function test_db_verify_mysql_readonly(): void { assert_eq(db_verify_observe("readonly",true), true, "native sandbox contract"); }
function test_db_verify_mysql_rollback(): void { assert_eq(db_verify_observe("rollback",true), true, "native sandbox contract"); }
function test_db_verify_mysql_shared(): void { assert_eq(db_verify_observe("shared",true), true, "native sandbox contract"); }
function test_db_verify_mysql_actual_journal(): void { assert_eq(db_verify_observe("actual_journal",true), true, "native sandbox contract"); }
function test_db_verify_mysql_dump_baseline(): void { assert_eq(db_verify_observe("dump_baseline",true), true, "native sandbox contract"); }
function test_db_verify_mysql_fresh(): void { assert_eq(db_verify_observe("fresh",true), true, "native sandbox contract"); }
function test_db_verify_mysql_cut(): void { assert_eq(db_verify_observe("cut",true), true, "native sandbox contract"); }
function test_db_verify_mysql_hash(): void { assert_eq(db_verify_observe("hash",true), true, "native sandbox contract"); }
function test_db_verify_mysql_schema_mismatch(): void { assert_eq(db_verify_observe("schema_mismatch",true), true, "native sandbox contract"); }
function test_db_verify_mysql_count_mismatch(): void { assert_eq(db_verify_observe("count_mismatch",true), true, "native sandbox contract"); }
function test_db_verify_mysql_row_mismatch(): void { assert_eq(db_verify_observe("row_mismatch",true), true, "native sandbox contract"); }
function test_db_verify_mysql_baseline_mismatch(): void { assert_eq(db_verify_observe("baseline_mismatch",true), true, "native sandbox contract"); }
function test_db_verify_mysql_duplicate(): void { assert_eq(db_verify_observe("duplicate",true), true, "native sandbox contract"); }
function test_db_verify_mysql_incomplete(): void { assert_eq(db_verify_observe("incomplete",true), true, "native sandbox contract"); }
function test_db_verify_mysql_sql_error(): void { assert_eq(db_verify_observe("sql_error",true), true, "native sandbox contract"); }
function test_db_verify_mysql_cross_schema(): void { assert_eq(db_verify_observe("cross_schema",true), true, "native sandbox contract"); }
function test_db_verify_mysql_file(): void { assert_eq(db_verify_observe("file",true), true, "native sandbox contract"); }
function test_db_verify_mysql_local(): void { assert_eq(db_verify_observe("local",true), true, "native sandbox contract"); }
function test_db_verify_mysql_metacommand(): void { assert_eq(db_verify_observe("metacommand",true), true, "native sandbox contract"); }
function test_db_verify_mysql_privileges(): void { assert_eq(db_verify_observe("privileges",true), true, "native sandbox contract"); }
function test_db_verify_mysql_timeout(): void { assert_eq(db_verify_observe("timeout",true), true, "native sandbox contract"); }
function test_db_verify_mysql_disk(): void { assert_eq(db_verify_observe("disk",true), true, "native sandbox contract"); }
function test_db_verify_mysql_cleanup_identity(): void { assert_eq(db_verify_observe("cleanup_identity",true), true, "native sandbox contract"); }
function test_db_verify_mysql_cleanup_failure(): void { assert_eq(db_verify_observe("cleanup_failure",true), true, "native sandbox contract"); }
function test_db_verify_mysql_report(): void { assert_eq(db_verify_observe("report",true), true, "native sandbox contract"); }
function test_db_verify_mysql_adversarial(): void { assert_eq(db_verify_observe("adversarial",true), true, "native sandbox contract"); }
function test_db_verify_mysql_actual_dump(): void { assert_eq(db_verify_observe("actual_dump",true), true, "native sandbox contract"); }
function test_db_verify_mysql_unsupported_type(): void { assert_eq(db_verify_observe("unsupported_type",true), true, "native sandbox contract"); }
function test_db_verify_mysql_policy(): void { assert_eq(db_verify_observe("policy",true), true, "native sandbox contract"); }
function test_db_verify_mysql_cli(): void { assert_eq(db_verify_observe("cli",true), true, "native sandbox contract"); }
function test_db_verify_mysql_baseline_hash(): void { assert_eq(db_verify_observe("baseline_hash",true), true, "baseline hash integrity"); }
function test_db_verify_mysql_counter(): void { assert_eq(db_verify_observe("counter",true), true, "schema/name regression"); }
function test_db_verify_mysql_counter_quoted(): void { assert_eq(db_verify_observe("counter_quoted",true), true, "schema/name regression"); }
function test_db_verify_mysql_numeric_match(): void { assert_eq(db_verify_observe("numeric_match",true), true, "schema/name regression"); }
function test_db_verify_mysql_numeric_mismatch(): void { assert_eq(db_verify_observe("numeric_mismatch",true), true, "schema/name regression"); }
function test_db_verify_mysql_numeric_zero(): void { assert_eq(db_verify_observe("numeric_zero",true), true, "schema/name regression"); }
