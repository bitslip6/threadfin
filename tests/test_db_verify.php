<?php declare(strict_types=1);
require_once __DIR__."/support/verify_transport.php";
function test_db_verify_quoted(): void { assert_eq(db_verify_observe("quoted"), true, "verifier contract"); }
function test_db_verify_shared(): void { assert_eq(db_verify_observe("shared"), true, "verifier contract"); }
function test_db_verify_duplicate(): void { assert_eq(db_verify_observe("duplicate"), true, "verifier contract"); }
function test_db_verify_incomplete(): void { assert_eq(db_verify_observe("incomplete"), true, "verifier contract"); }
function test_db_verify_marker_string(): void { assert_eq(db_verify_observe("marker_string"), true, "verifier contract"); }
function test_db_verify_modes(): void { assert_eq(db_verify_observe("modes"), true, "verifier contract"); }
function test_db_verify_comments(): void { assert_eq(db_verify_observe("comments"), true, "verifier contract"); }
function test_db_verify_metacommands(): void { assert_eq(db_verify_observe("metacommands"), true, "verifier contract"); }
function test_db_verify_unsafe_ddl(): void { assert_eq(db_verify_observe("unsafe_ddl"), true, "verifier contract"); }
function test_db_verify_session_changes(): void { assert_eq(db_verify_observe("session_changes"), true, "verifier contract"); }
function test_db_verify_malformed(): void { assert_eq(db_verify_observe("malformed"), true, "verifier contract"); }
function test_db_verify_baseline(): void { assert_eq(db_verify_observe("baseline"), true, "verifier contract"); }
function test_db_verify_manifest(): void { assert_eq(db_verify_observe("manifest"), true, "verifier contract"); }
function test_db_verify_opt_in(): void { assert_eq(db_verify_observe("opt_in"), true, "verifier contract"); }
function test_db_verify_destination(): void { assert_eq(db_verify_observe("destination"), true, "verifier contract"); }
function test_db_verify_framing(): void { assert_eq(db_verify_observe("framing"), true, "verifier contract"); }
function test_db_verify_limits(): void { assert_eq(db_verify_observe("limits"), true, "verifier contract"); }
function test_db_verify_backslashes(): void { assert_eq(db_verify_observe("backslashes"), true, "verifier contract"); }
function test_db_verify_backtick_escape(): void { assert_eq(db_verify_observe("backtick_escape"), true, "backtick backslashes reject before masking"); }
function test_db_verify_controls(): void { assert_eq(db_verify_observe("controls"), true, "verifier contract"); }
function test_db_verify_quoted_engine(): void { assert_eq(db_verify_observe("quoted_engine"), true, "verifier contract"); }
function test_db_verify_option_bounds(): void { assert_eq(db_verify_observe("option_bounds"), true, "verifier contract"); }
function test_db_verify_manifest_fields(): void { assert_eq(db_verify_observe("manifest_fields"), true, "verifier contract"); }
function test_db_verify_report_paths(): void { assert_eq(db_verify_observe("report_paths"), true, "verifier contract"); }
function test_db_verify_numeric_manifest(): void { assert_eq(db_verify_observe("numeric_manifest"), true, "numeric identifiers survive manifest JSON"); }
