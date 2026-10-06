<?php declare(strict_types=1);
require_once __DIR__ . "/support/dump_transport.php";
function test_db_resumable_dump_simulation(): void { assert_eq(db_dump_observe("simulation"), true, "dump contract"); }
function test_db_resumable_dump_options(): void { assert_eq(db_dump_observe("options"), true, "dump contract"); }
function test_db_resumable_dump_paths(): void { assert_eq(db_dump_observe("paths"), true, "dump contract"); }
function test_db_resumable_dump_budget(): void { assert_eq(db_dump_observe("budget"), true, "dump contract"); }
function test_db_resumable_dump_resume(): void { assert_eq(db_dump_observe("resume"), true, "dump contract"); }
function test_db_resumable_dump_ahead(): void { assert_eq(db_dump_observe("ahead"), true, "dump contract"); }
function test_db_resumable_dump_short(): void { assert_eq(db_dump_observe("short"), true, "dump contract"); }
function test_db_resumable_dump_corrupt(): void { assert_eq(db_dump_observe("corrupt"), true, "dump contract"); }
function test_db_resumable_dump_identity(): void { assert_eq(db_dump_observe("identity"), true, "dump contract"); }
function test_db_resumable_dump_checkpoint_corrupt(): void { assert_eq(db_dump_observe("checkpoint_corrupt"), true, "dump contract"); }
function test_db_resumable_dump_schema_drift(): void { assert_eq(db_dump_observe("schema_drift"), true, "dump contract"); }
function test_db_resumable_dump_source_drift(): void { assert_eq(db_dump_observe("source_drift"), true, "dump contract"); }
function test_db_resumable_dump_unsupported(): void { assert_eq(db_dump_observe("unsupported"), true, "dump contract"); }
function test_db_resumable_dump_checkpoint_before(): void { assert_eq(db_dump_observe("checkpoint_before"), true, "dump contract"); }
function test_db_resumable_dump_checkpoint_after(): void { assert_eq(db_dump_observe("checkpoint_after"), true, "dump contract"); }
function test_db_resumable_dump_header_orphan(): void { assert_eq(db_dump_observe("header_orphan"), true, "dump contract"); }
function test_db_resumable_dump_ddl_before(): void { assert_eq(db_dump_observe("ddl_before"), true, "dump contract"); }
function test_db_resumable_dump_write_failure(): void { assert_eq(db_dump_observe("write_failure"), true, "dump contract"); }
function test_db_resumable_dump_flush_failure(): void { assert_eq(db_dump_observe("flush_failure"), true, "dump contract"); }
function test_db_resumable_dump_lock(): void { assert_eq(db_dump_observe("lock"), true, "dump contract"); }
function test_db_resumable_dump_cursor_fidelity(): void { assert_eq(db_dump_observe("cursor_fidelity"), true, "dump contract"); }
function test_db_resumable_dump_disabled(): void { assert_eq(db_dump_observe("disabled"), true, "dump contract"); }
function test_db_resumable_dump_lifecycle(): void { assert_eq(db_dump_observe("lifecycle"), true, "dump contract"); }
function test_db_resumable_dump_options_changed(): void { assert_eq(db_dump_observe("options_changed"), true, "dump contract"); }
function test_db_resumable_dump_checkpoint_write(): void { assert_eq(db_dump_observe("checkpoint_write"), true, "dump contract"); }
function test_db_resumable_dump_checkpoint_flush(): void { assert_eq(db_dump_observe("checkpoint_flush"), true, "dump contract"); }
function test_db_resumable_dump_year_variants(): void { assert_eq(db_dump_observe("year_variants"), true, "unsupported YEAR variants reject before output"); }
function test_db_resumable_dump_instance_identity(): void { assert_eq(db_dump_observe("instance_identity"), true, "each private instance-identity component is hashed"); }
