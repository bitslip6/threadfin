# db.php regression tests

These are expected-behavior TinyTest tests, not tests that expect the current bugs.
The initial baseline was **41 failed / 0 passed / 0 incomplete / 0 skipped**.
The SQL-injection work expanded it to **44 failed / 0 passed** before the fix.
The current suite has **186 tests: 186 passed / 0 failed**, exit 0, with no
incomplete cases, skips, or runner errors. Quoting, connection failure handling,
the dump charset-statement terminator, current-row column lookup, complete
buffered-result array conversion, associative duplicate updates, object
store/attribute mapping, cursor synchronization, invalid array-read rejection,
array-backed result operations, row-offset existence bounds, the dumper's
result map/reduce methods, dump byte budgets, requested-database selection,
query simulation, null/missing template-parameter handling, NULL predicates,
falsey `upsert_fn()` updates, buffered bulk inserts, non-duplicate error logging,
replay recording/flush behavior, complete per-stream writes, and original SQL-text
retention now pass. Result-close/type hardening, replay append recovery and session
envelopes, obsolete-code cleanup, idempotent error-log closure, and simulation-safe
replay initialization also pass. All reviewed regressions are fixed.

## Current status

Verified with the default TinyTest runner: **186 total, 186 passed, 0 failed**.
There are **0 incomplete tests, 0 skipped tests, and 0 runner errors**, exit code 0.
Counts below are test functions, not assertions or separate bugs.

| Test file | Passed | Failed | Total |
| --- | ---: | ---: | ---: |
| `test_db_array_results.php` | 8 | 0 | 8 |
| `test_db_bounds.php` | 5 | 0 | 5 |
| `test_db_bulk.php` | 9 | 0 | 9 |
| `test_db_close_simulation.php` | 7 | 0 | 7 |
| `test_db_connection.php` | 5 | 0 | 5 |
| `test_db_cursor.php` | 7 | 0 | 7 |
| `test_db_dump.php` | 10 | 0 | 10 |
| `test_db_duplicate_updates.php` | 4 | 0 | 4 |
| `test_db_error_logging.php` | 7 | 0 | 7 |
| `test_db_hardening.php` | 9 | 0 | 9 |
| `test_db_regressions.php` | 46 | 0 | 46 |
| `test_db_replay.php` | 7 | 0 | 7 |
| `test_db_result_reads.php` | 9 | 0 | 9 |
| `test_db_simulation.php` | 8 | 0 | 8 |
| `test_db_sql_metadata.php` | 3 | 0 | 3 |
| `test_db_store.php` | 6 | 0 | 6 |
| `test_db_streams.php` | 7 | 0 | 7 |
| `test_db_templates.php` | 8 | 0 | 8 |
| `test_db_transforms.php` | 10 | 0 | 10 |
| `test_db_upsert.php` | 6 | 0 | 6 |
| `test_db_where.php` | 5 | 0 | 5 |
| **Total** | **186** | **0** | **186** |

### Remaining failures

None in the default regression suite. The original reviewed regressions, nine
close/offset/replay/cleanup cases, and seven close/simulation ordering cases pass. The coverage table below
records the reviewed issues; integration limitations are noted separately.

The opt-in live-server suites are separate from these totals: **7 passed, 0 failed**
(3 quoting + 4 real-mysqli lifecycle tests), verified on a disposable MariaDB 12.3.3
server for these close/simulation fixes. See the integration sections below.

## Running tests

Run from the project root:

```sh
php /home/cory/Work/tinytest/tinytest.php -j -d tests
# Show only failures while preserving the full-suite summary:
php /home/cory/Work/tinytest/tinytest.php -x -d tests
php /home/cory/Work/tinytest/tinytest.php -v -f tests/test_db_regressions.php
php /home/cory/Work/tinytest/tinytest.php -j -f tests/test_db_array_results.php
php /home/cory/Work/tinytest/tinytest.php -j -f tests/test_db_bounds.php
php /home/cory/Work/tinytest/tinytest.php -j -f tests/test_db_bulk.php
php /home/cory/Work/tinytest/tinytest.php -j -f tests/test_db_close_simulation.php
php /home/cory/Work/tinytest/tinytest.php -j -f tests/test_db_connection.php
php /home/cory/Work/tinytest/tinytest.php -j -f tests/test_db_cursor.php
php /home/cory/Work/tinytest/tinytest.php -j -f tests/test_db_dump.php
php /home/cory/Work/tinytest/tinytest.php -j -f tests/test_db_result_reads.php
php /home/cory/Work/tinytest/tinytest.php -j -f tests/test_db_replay.php
php /home/cory/Work/tinytest/tinytest.php -j -f tests/test_db_simulation.php
php /home/cory/Work/tinytest/tinytest.php -j -f tests/test_db_duplicate_updates.php
php /home/cory/Work/tinytest/tinytest.php -j -f tests/test_db_error_logging.php
php /home/cory/Work/tinytest/tinytest.php -j -f tests/test_db_hardening.php
php /home/cory/Work/tinytest/tinytest.php -j -f tests/test_db_store.php
php /home/cory/Work/tinytest/tinytest.php -j -f tests/test_db_streams.php
php /home/cory/Work/tinytest/tinytest.php -j -f tests/test_db_sql_metadata.php
php /home/cory/Work/tinytest/tinytest.php -j -f tests/test_db_templates.php
php /home/cory/Work/tinytest/tinytest.php -j -f tests/test_db_transforms.php
php /home/cory/Work/tinytest/tinytest.php -j -f tests/test_db_upsert.php
php /home/cory/Work/tinytest/tinytest.php -j -f tests/test_db_where.php
php /home/cory/Work/tinytest/tinytest.php -j -f tests/test_db_regressions.php \
  -t test_db_col_reads_current_associative_row
```

Tests must run through TinyTest; do not include its executable inside the tests.
The same suite can be selected with a server-owned TinyTest `TestRunnerTool` using
`directory: "tests"` and the project root `/home/cory/Work/threadfin`. The tool
runs the CLI runner rather than executing mysqli stand-ins in the web process.

## Isolation and requirements

- PHP 8.1+ CLI, `proc_open`, writable temporary storage, and zlib available in
  `php -n` for the gzip round-trip test; no database server, mysqli extension,
  network access, or Composer dependencies are required.
- Each test observation runs in a fresh `php -n` child. `tests/support/mysqli.php`
  supplies the driver's boundary only: result cursor operations, affected-row
  counts, connection exceptions, and canned responses. Real `db.php` executes.
  No stand-in is loaded in TinyTest's host or an application process, including
  on machines that have the real mysqli extension installed.
- `THREADFIN_TEST_PHP=/absolute/path/to/php` selects the child CLI executable.
- All current regression cases enable native assertions in their child process.
  Attribute/value-builder cases no longer bypass the store connection assertion.
  Statement/attribute probes replace only downstream execution/building, never
  the behavior under test. The helper retains an optional assertion-mode argument
  for future isolation cases.
- PHP 8.4+ implicit-nullable deprecations from existing `core.php` declarations
  are excluded in the child. Other diagnostics become reported exceptions.
- Error/replay logs use unique temporary files and are deleted in `finally`.
  Replay write/flush-failure cases use an isolated in-memory stream wrapper with
  seek/truncate behavior, not a SQL or replay implementation stand-in. No test writes the production
  `/tmp/php_sql_errors.log`.
- The default suite consists of deterministic unit regressions. Its small
  literal lexer handles ordinary quoted strings and UTF-8 hex literals in both
  backslash modes; it does not pretend to execute SQL. Separate opt-in real-server
  tests are documented below.

## Review issue coverage

All function names below have the `test_db_` prefix.

| Review issue / source | Test suffixes |
| --- | --- |
| Quoting/injection and numeric-string corruption (`quote`) | `quote_is_one_literal_after_connection_setup`, `quote_round_trips_after_configuring_either_initial_mode`, `quote_stringable_cast_cannot_inject_sql`, `quote_array_elements_cannot_escape_their_literals`, `quote_preserves_numeric_string_type`, `quote_uses_client_backslash_escape_sequences`, `all_value_builders_use_the_same_php_escaping` |
| Required connection charset/mode and fail-closed behavior (`from`, `connect`) | `new_connection_enforces_escaping_contract_once`, `wrapped_connection_enforces_escaping_contract_once`, `connection_setup_handles_empty_sql_mode`, `connection_setup_failures_close_and_reject_handle`, `connection_false_return_closes_handle_and_retains_error` |
| Backwards upsert guard and nullable exclusion defaults (`insert_stmt`) | `associative_upsert_accepts_column_value_pairs`, `associative_upsert_works_with_default_options`, `duplicate_update_rejects_positional_and_empty_lists`, `duplicate_update_excludes_protected_columns_only_from_update`, `duplicate_update_preserves_if_null_with_null_exclusion_map`, `duplicate_update_quotes_text_and_retains_falsey_values` |
| Incorrect current-row column lookup (`col`) | `col_reads_current_associative_row`, `col_tracks_next_and_rewind`, `col_preserves_empty_string_zero_and_null`, `col_missing_and_empty_results_return_null`, `col_accepts_numeric_column_alias` |
| First row consumed before array conversion (`as_array`) | `as_array_includes_first_row`, `as_array_returns_a_single_row`, `as_array_is_repeatable`, `as_array_preserves_iteration_after_advancing`, `as_array_empty_error_and_closed_results_return_empty`, `as_array_can_read_exhausted_result_without_reviving_iterator` |
| Cursor/iterator desynchronization (`747–749`, `788–791`) | `seek_updates_current_row_and_key`, `array_read_does_not_move_iterator` |
| Broken array-backed results (`728–732`, `766–771`) | `from_initializes_result_length`, `from_counts_array_backed_rows`, `from_supports_array_access`, `from_iterates_array_backed_rows` |
| Invalid array bounds / unchecked failed seeks (`743–749`) | `negative_offset_does_not_exist`, `offset_equal_to_count_does_not_exist`, `invalid_array_read_is_rejected` |
| Dump calls commented-out methods (`1043`, `1083`) | `dump_map_method_is_callable`, `dump_reduce_method_is_callable` |
| Missing dump statement terminator (`1075`) | `dump_charset_statement_is_terminated` |
| Ignored dump budget/database argument (`1072–1084`) | `dump_respects_byte_budget`, `dump_uses_requested_database` |
| mysqli object rejected by store assertion (`store`) | `store_accepts_mysqli_object_with_assertions_enabled`, `store_still_rejects_missing_connection` |
| Attribute policies and object-property mapping (`store`) | `store_recognizes_no_update_attribute`, `store_recognizes_if_null_attribute`, `store_no_update_attribute_protects_primary_key_in_sql`, `store_if_null_attribute_generates_conditional_update`, `store_not_null_attribute_retains_non_null_falsey_values`, `store_includes_public_dynamic_object_properties`, `store_ignores_non_instance_and_unset_properties` |
| Simulation treated as failed query (`270–281`, `312–323`) | `simulated_write_logs_without_execution_or_errors`, `simulated_read_logs_without_execution_or_errors` |
| Null/missing template keys fabricated as data (`383`) | `null_template_parameter_remains_sql_null`, `missing_template_parameter_is_rejected` |
| SQL NULL compared with equality (`132`) | `null_where_uses_is_null` |
| Upsert drops zero/false updates (`553`) | `upsert_can_update_integer_zero`, `upsert_can_update_boolean_false` |
| Bulk insert immediately flushes / numeric column names (`602–603`) | `bulk_insert_buffers_until_flush_or_limit`, `bulk_insert_accepts_list_column_names` |
| Non-duplicate errors discarded (`680`) | `close_logs_non_duplicate_sql_errors` |
| Replay omits zero-affected changes and transaction boundaries (`290`) | `replay_records_successful_ddl`, `replay_does_not_commit_rolled_back_writes` |
| Replay duplicated by repeated close (`692–701`) | `repeated_close_does_not_duplicate_replay` |
| Falsey string, short writes, cross-stream totals (`998–1009`) | `stream_writes_literal_zero`, `stream_retries_short_writes`, `stream_byte_totals_are_per_stream` |
| Connection exceptions escape (`230–240`) | `connection_failure_returns_disconnected_wrapper` |
| Result stores itself rather than SQL text (`775–778`) | `result_retains_original_sql_text` |

## Known masking and chosen contracts

- The explicit-options upsert test supplies `[]`, isolating the former backwards
  guard. The public default-options test covers both that guard and nullable
  exclusion defaults. Both now pass without any source patch inside the tests.
- Array-backed iteration is an API-level regression and needs both length
  initialization and array-backed iteration support. Separate length/count/read
  cases localize failures so a partial fix does not falsely clear the API case.
- Empty-database dump observations no longer mask a missing `SQL::map()` error;
  all exceptions propagate now that the methods are implemented. Terminator,
  budget, and selected-database tests still isolate their own issues using an
  empty table list. New nonempty-dump coverage executes both real result methods
  with canned mysqli boundary responses; it does not simulate SQL parsing.
- Ordinary null/uninitialized object properties are still omitted by the existing
  `isset()` policy. `NotNull` recognition must not accidentally omit non-null zero,
  false, or empty text; the store SQL regression explicitly checks those values.
  General null-clearing behavior is not changed by this store fix.
- Missing template keys must raise `InvalidArgumentException` or
  `OutOfBoundsException`; out-of-range row reads must raise `OutOfBoundsException`
  or `ValueError`. Simulation must log generated SQL without executing it or
  recording false errors. Dump budgets include headers. Replay preserves executed
  transaction boundaries in order rather than filtering rolled-back statements.
  Byte totals belong to individual streams. These explicit contracts avoid
  asserting current bugs.

## Associative duplicate-update fixes

`insert_stmt(..., DB_DUPLICATE_UPDATE)` now accepts associative column/value data
and rejects positional/empty lists. A null/default exclusion map is treated as
`[]` before `array_diff_key`. Explicit protected columns remain in the insert but
are excluded from updates; conditional updates retain the existing empty-or-null
policy. All values use the shared quoting policy, including falsey values.

The two original upsert regressions pass, as do four additional tests in
`test_db_duplicate_updates.php` (all four failed before this fix). Attribute
extraction in `store()` is now also fixed. The distinct `upsert_fn()` zero/false
bug is now fixed as described below.

## Object store and attribute fixes

`store()` accepts a mysqli object rather than requiring a PHP resource. It still
rejects missing connections when native assertions are enabled, matching the
existing assertion-based write contract. Attribute matching uses `NoUpdate::class`,
`NotNull::class`, and `IfNull::class`, avoiding incorrect hard-coded namespaces.
The resulting exclusion/conditional maps reach the real duplicate-update builder.
`NotNull` skips null/unset values, not valid zero, false, or empty strings.

Reflection now inspects the object, including public dynamic/stdClass fields,
and ignores static metadata. Private/protected, uninitialized, and null fields
remain omitted. Six new cases in `test_db_store.php` exercise generated SQL and
mapping; five failed before the fix and all six now pass. The three original
store regressions also pass with native assertions enabled (no bypass).

## Current-row and buffered-result read fixes

`col()` now looks directly in the current associative row, preserving empty text,
string zero, and numeric column aliases. Missing/null columns and empty/error
results return an empty `MaybeStr`.

`as_array()` rewinds the buffered mysqli cursor before reading all rows, then
restores the next-fetch position associated with the iterator's current row.
It does not alter the current row/key; repeat calls return the same complete
rows, and an exhausted iterator stays exhausted. Empty/error/closed mysqli
results return `[]`. Cursor synchronization and the separate array-backed
`SQL::from(array)` API are now also fixed as described below.

`test_db_result_reads.php` adds nine edge/regression cases. Before the read fixes,
eight failed and the missing/empty-column case already passed; afterward all
nine pass. The two original `col`/first-row regression tests also now pass.

## Cursor synchronization fixes

`seek()` now updates the current row and key, leaving the driver ready to fetch
its successor. Valid seeks also resume exhausted iterators. Invalid seeks throw
`OutOfBoundsException` without changing iterator state.

Array reads return the requested row without altering the current row/key or the
next-fetch position. Reads at the last row or after exhaustion restore driver
EOF by seeking to and consuming the last row, since mysqli cannot seek directly
to EOF. Out-of-range reads are rejected rather than returning an unrelated row.
The separate `offsetExists()` boundary bugs are now fixed as described below.

`test_db_cursor.php` adds seven focused cases (six failed before the fix, one
already passed); all seven now pass. The two original synchronization regressions
and the original invalid-read regression also pass. No live-server tests were
rerun for this change.

## Array-backed result fixes

`SQL::from()` stores the full dataset separately from the current row, initializes
its length, and selects the first row immediately. Outer array keys are normalized
to zero-based row positions; column keys and values are preserved.

Array-backed results now support `count()`, `empty()`, array reads, `seek()`,
`next()`, `rewind()`, and repeatable iteration without a mysqli handle. Exhaustion
clears the current row; valid seeks and rewinds can resume iteration. Invalid
reads/seeks throw bounds errors without moving the iterator. `as_array()` returns
the complete dataset without changing the current row/key, even after exhaustion.
`SQL::from(null)` and `SQL::from([])` remain safe empty results. The separate
`offsetExists()` boundary bugs are now fixed for both backing types.

`test_db_array_results.php` adds eight cases (seven failed before the fix, one
already passed); all eight now pass. The four original array-backed regressions
also pass. No live-server tests were rerun for this change.

## Row-offset existence bounds fix

`offsetExists()` now checks `0 <= offset < _len` using the cached length, excluding
negative positions and the row count itself without calling `count()`. A separate
backing-data check makes closed results report no available rows. Integer-string
offsets retain support and now normalize consistently for reads and existence
checks; floats/booleans and other types are rejected as described below. Empty/null wrappers and
uninitialized results report no rows; buffered rows remain available after
iterator exhaustion.

Existence checks do not seek or fetch, so they preserve the current row/key and
next-fetch position for both mysqli- and array-backed results. Row existence does
not depend on whether column values are null, zero, false, or empty strings.

`test_db_bounds.php` adds five cases; all five failed before the fix and now pass.
The two original existence-boundary regressions also pass. No live-server tests
were rerun for this change.

## Result map/reduce fixes

`SQL::map()` and `SQL::reduce()` are now callable and process every row in order
for both mysqli- and array-backed results. They use the complete `as_array()`
snapshot, preserving the iterator's current row/key and next-fetch position before
callbacks run, including at EOF or after exhaustion. Callback exceptions propagate
without an internal cursor move; falsey callback returns are retained.

`map()` returns `[]` for empty results. `reduce()` accepts arbitrary accumulator
types, retains the existing empty-string default seed, and returns the supplied
initial value unchanged for empty results. Empty/null/uninitialized/closed mysqli
results invoke no callbacks and produce no artificial errors.

`test_db_transforms.php` adds ten cases; all ten failed before the fix and now
pass. Coverage includes both backing types, empty/advanced/exhausted cursors,
callback errors, and actual empty/nonempty dumper execution against canned mysqli
responses. The two original callable-method regressions also pass. The obsolete
empty-dump missing-method exception mask was removed. Dump budget/database bugs
are now also fixed as described below. No live-server tests were rerun for this
change.

## Dump budget and database-selection fixes

`dump_database()` now connects to the requested database rather than the database
stored in `Credentials`; the caller's credentials are not mutated. This also
aligns `SHOW TABLES` column lookup with the selected database and dump label.

One shared byte budget covers the database header, table headers/DDL, and all row
batches across all tables. Chunks that do not fit are not sent to the writer;
they are never truncated. A tiny/zero budget can therefore produce no output,
while a negative budget raises `InvalidArgumentException`. Accounting uses chunk
byte lengths, supporting both per-chunk and cumulative writer return values.
The old hard-coded 20 MiB callback-total cutoff is removed in favor of the budget.

A budget stop or negative writer result stops further output and later-table
queries. `dump_table()` now checks header/DDL writes as well as row-batch writes.
Only successful complete batches advance row checkpoints; interrupted tables
retain the last written row offset, and unvisited tables receive incomplete zero
offsets. Every table still has an `Offset` entry. These offsets are progress
markers, not a newly implemented resumable-dump API. The shared stream helpers now
complete positive short writes as described below; arbitrary dump callbacks remain
responsible for honoring their complete-chunk output contract.

`test_db_dump.php` adds ten cases (nine failed before the fix, one already passed);
all ten now pass. Coverage includes header/DDL/batch boundaries, multi-table
budgets, 301-row checkpoints, writer failures, cumulative totals, and nonempty
requested-database selection. The two original budget/database regressions also
pass. No live-server tests were rerun for this change.

## Query simulation fixes

`_qb()` and `_qr()` now return through a dedicated simulation path before query
execution or driver metadata/error reads. Generated SQL is logged once with a
`simulated (not executed)` marker; prior errors remain unchanged. Simulation
continues to enable logging by default, while an explicit later
`enable_log(false)` suppresses logs without causing execution or false errors.

Status-only simulated writes return `1` for successful SQL generation. Affected-row
and insert-ID modes return `0`: no rows were affected and no ID was generated.
They never reuse stale driver values or manufacture a positive ID. Write
`last_stmt` is retained. Simulated reads return an empty `SQL` wrapper that retains
the interpolated statement and is safe to count, iterate, and convert.

Unexecuted statements never enter the execution replay queue. Turning simulation
off restores real execution, results, and false-return/exception diagnostics.
The existing assertion-based connected-write contract is unchanged; simulation
is not a new offline connection factory, and connection setup still runs normally.

`test_db_simulation.php` adds eight cases (six failed before the fix, two already
passed); all eight now pass. They use real public builders and execution methods,
cover stale metadata, return modes, empty reads, logging, replay, and toggling,
and retain disconnected-write and real-error behavior. The two original simulation
regressions also pass. No live-server tests were rerun for this change.

## Template-parameter fixes

`fetch_to_statement()` now uses key-existence checks instead of null-coalescing
fallback data. Present null values become SQL `null`, including repeated and raw
`!` placeholders. Zero, false, empty text, numeric-looking strings, and ordinary
quoted text retain the shared quoting policy. Non-null `!` values remain trusted
raw SQL expressions, not safe dynamic identifiers or untrusted-input APIs.

Parameter containers must be arrays, objects, or null; other types raise
`InvalidArgumentException`. Objects supply their initialized readable fields via
`get_object_vars()`, preserving explicit null while omitting inaccessible or
uninitialized fields. This is a field-bag API, not magic-property resolution.
A referenced missing field/key raises `InvalidArgumentException` identifying the
parameter instead of producing `NO_SUCH_KEY` or parameter-name literals. Empty
containers/no data are accepted for SQL without placeholders, but cannot supply
referenced values. The template regex remains unchanged; this is not a SQL lexer.

Missing parameters are rejected before driver execution or simulation logging,
without manufacturing database errors or sending partially substituted SQL.
`test_db_templates.php` adds eight cases (seven failed before the fix, one already
passed); all eight now pass. The two original null/missing regressions also pass.
No live-server tests were rerun for this change.

## NULL-predicate fix

`where_clause()` now uses `IS NULL` for actual PHP null values, instead of SQL
`= null`, for both ordinary and `!`-prefixed column keys. Multiple predicates
remain joined by `AND`. Zero, false, empty text, numeric-looking strings, and the
literal string `'NULL'` retain ordinary equality and shared quoting. Non-null
`!` values remain trusted raw SQL; callers are still responsible for the semantics
of raw expressions such as the string `NULL`.

Public `delete()` and `update()` paths inherit the corrected predicates. NULL
assignments remain `SET column = null`; this fix does not change assignment
builders or the existing object-store null-omission policy.

`test_db_where.php` adds five cases (four failed before the fix, one already
passed); all five now pass. They cover mixed/multiple nulls, falsey/text/raw
values, and generated public write statements against the mysqli boundary. The
original NULL-predicate regression also passes. No live-server tests were rerun
for this change.

## Falsey upsert-function updates fix

`upsert_fn()` no longer truthiness-filters rendered update values. SQL zero was
considered empty by PHP, dropping integer zero, floating zero, false, and trusted
raw `'0'` expressions from the duplicate-update clause. Every supplied non-primary-
key value now reaches the update clause. Quoting, allowed-key filtering, primary-
key normalization/protection, and `LAST_INSERT_ID(pk)` behavior remain unchanged.
Empty text, null, numeric-looking strings, and ordinary text retain their existing
insert/update semantics. Non-null `!` values remain caller-trusted raw SQL.

`test_db_upsert.php` adds six cases (five failed before the fix, one already passed);
all six now pass. They cover mixed values, allowed keys, custom/raw-prefixed primary
keys, raw zero, repeated closure calls, PK-only/positional compatibility, and real
execution/simulation paths against the mysqli boundary. The two original zero/false
regressions also pass. No live-server tests were rerun for this change.

## Buffered bulk-insert fixes

`bulk_fn()` now buffers rows until an explicit null/no-argument flush or exactly
`DB_MAX_BULK_INSERT` (64) pending rows. List-form columns map to same-named input
fields; associative declarations retain column-to-source-key mapping. Both forms
preserve declaration order, falsey/null values, and shared quoting. Identifiers
remain trusted input, and the duplicate-ignore option is unchanged.

Each returned closure has independent buffer state. Row construction validates
all source fields and completes quoting before appending, so missing-field errors
do not corrupt pending SQL. Empty column declarations and missing row fields
raise `InvalidArgumentException`; explicit null field values are valid.

Buffering returns the pending row count. Flush returns `1` on success, `0` when
empty, and `-1` on execution failure. Only successful flushes clear pending data.
A failed full batch is retried before accepting another row, keeping every batch
within the limit; if that retry fails, the new row is not accepted. Explicit
flush is still required for any remainder; closing DB is not an automatic flush.
Retaining failed SQL permits retry, not exactly-once delivery after ambiguous
network failures. Simulation logs a complete batch only when it is flushed.

`test_db_bulk.php` adds nine cases; all nine failed before the fix and now pass.
They cover flush lifecycle, exact-limit boundaries, column forms, independent
closures, validation, simulation, and partial/full-batch retries against the
mysqli boundary. The two original bulk regressions also pass. No live-server
tests were rerun for this change.

## Non-duplicate error-logging fix

`DB::close()` now retains non-duplicate errors for file output instead of selecting
only errors containing `Duplicate`. Generated `[sql] errno(n) message` diagnostics
use code `1062` to identify duplicate-key errors; other codes remain logged even
when SQL or error text contains `Duplicate`. Diagnostics without that query-error
format retain case-insensitive keyword suppression, using strict comparison so
matches at offset zero are also suppressed.

Only a nonempty filtered set is appended, avoiding empty `Array()` blocks when
all errors are duplicates. Existing file contents, `print_r` formatting, array
keys/order, and the complete public `errors` list are preserved. A false
`SQL_ERROR_FILE` still disables file output without preventing handle closure.
Explicit close can log setup errors even when factory cleanup already disconnected
the wrapper. This error-logging fix did not alter replay; its separate fixes are
described below. Repeated-close error-log duplication is now also fixed as
described in the close/simulation section.

`test_db_error_logging.php` adds seven cases (five failed before the fix, two
already passed); all seven now pass. They cover mixed/duplicate-only diagnostics,
uncoded and falsey text, public write/read failures through both driver failure
modes, multiline SQL, connection-setup errors, empty logs, and disabled output.
The original non-duplicate logging regression also passes. All file output uses
isolated temporary paths (or is disabled); no live-server tests were rerun.

## Replay recording and flush fixes

Replay now records every successfully executed statement through the write/raw
`_qb()` path, independent of affected rows and requested return mode. This includes
DDL, no-op writes, session settings, and transaction controls. `BEGIN`/`START
TRANSACTION`, savepoints, rollback, and commit remain in execution order; replay
no longer converts explicit rolled-back inserts into standalone committed writes.
Ordinary `fetch()` reads, query failures, and all simulated statements stay out
of the queue. Replay remains opt-in; no SQL parsing or transaction emulation is
added, and existing return-mode semantics are unchanged.

`close()` appends the complete queued journal under the file lock using the shared
complete-write helper, then flushes before clearing the queue. Repeated close does
not append it again; normal destructor closure still writes pending replay. Failed
open/write/flush/lock operations report failure without consuming the queue, and
locks/handles are released after a locked write attempt. Detected append/flush
failures now truncate back to the checkpoint under the same lock before retry,
as described below. This is not a crash-safe or exactly-once database replication
mechanism; power loss/process termination and failed recovery still require
operator attention.

`test_db_replay.php` adds seven cases (five failed before the fix, two already
passed); all seven now pass. They cover zero-affected statements and return modes,
transaction/savepoint ordering, exclusion paths, repeated close, destructor and
disabled behavior, and zero-progress write failure followed by explicit retry.
The three original replay regressions also pass.

## Complete per-stream writing fixes

`stream_output_fn()` now writes nonempty `'0'` and binary/UTF-8 data, looping over
only the remaining suffix after each positive short write. Null/empty chunks do
not call the writer; they return that handle's current cumulative byte total.
Totals are keyed by resource ID rather than shared across streams, without keeping
strong resource references that would prevent automatic closure.

Writer callbacks must report integer byte counts. False, zero, negative,
non-integer, or oversized reports return `-1` without inventing progress or
looping indefinitely. Successfully written prefixes remain counted even when a
later callback fails or throws; exceptions still propagate. Callers must account
for partial output rather than blindly resending a failed chunk. `gz_output_fn()`
uses the same loop and tracks uncompressed input bytes for its own handle.

`test_db_streams.php` adds seven cases; all seven failed before the fix and now
pass. They cover zero/binary text, empty calls, exact retry suffixes, interleaved
totals, failure/invalid reports, exception progress, and actual gzip round trips.
The three original stream regressions also pass.

## Original SQL-text retention fix

`SQL::fetch()` no longer shadows its SQL argument with the result wrapper. Its
`_sql` field retains the exact input text for empty and nonempty driver results,
including after array conversion, cursor movement, and close. Public successful,
failed, and simulated reads retain the generated/interpolated SQL. Existing
array/null-backed metadata behavior is unchanged.

`test_db_sql_metadata.php` adds three cases (two failed before the fix, one already
passed); all three now pass, as does the original stored-SQL regression. No
live-server tests were rerun for these final fixes; replay tests verify generated
journal text and driver boundaries, not server-side transaction execution.

## Result lifecycle, offset, replay, and cleanup hardening

`SQL::close()` now releases both driver- and array-backed rows and clears cached
length/current/cursor state, retaining only original SQL metadata. Repeated close
is safe. Closed/uninitialized/empty results yield no rows; `current()` returns
null instead of throwing a return-type error. Empty driver rewind avoids seeking
a nonexistent row. `next()` keeps the historical position increments without
reviving closed data. Read-only ArrayAccess mutators remain required interface
methods, but explicitly report that results are read-only.

Offsets accept integers and signed decimal integer strings, including leading
zeros, without overflow. Floats (even `0.0`), booleans, null, whitespace, decimals,
exponents, containers, and out-of-range strings do not coerce into row positions.
Existence returns false; reads throw `OutOfBoundsException`, consistently for both
backings and without cursor changes. `offsetExists()` still uses cached `_len`,
never `count()`. Disconnected reads also retain interpolated SQL metadata.

Replay captures initial SQL mode/autocommit at `enable_replay()` in real mode,
or when explicitly leaving simulation if initialization was deferred. Enable it
on a fresh owned connection before real application queries or transactions;
already-open external transactions and arbitrary unrecorded session state cannot
be reconstructed.
One destination belongs to one DB session: re-enabling it is idempotent, while
switching files throws `LogicException`. Invalid/unwritable paths throw exceptions
instead of terminating the process; the library no longer echoes replay status.

Each journal packet starts with rollback, utf8mb4, captured SQL mode/autocommit,
and ends with rollback plus autocommit reset. This reproduces implicit rollback
on close for pending transactions, including initial autocommit-off sessions,
and prevents those transactions from leaking into later packets. SQL delimiters
occupy their own lines so trailing line comments cannot swallow them. The importer
selects the target database. Arbitrary user variables, temporary tables, procedures,
SQL-dependent session state, and external connection activity are not a complete
session-replication contract; the envelope restores the documented defaults.

Replay uses a seekable/truncatable journal. Under the exclusive lock it checkpoints
EOF, completes the append, and flushes before consuming the queue. On detected
write/flush failure it truncates to that checkpoint and flushes recovery while
still locked, then reports failure with the queue retained. If recovery itself
fails, the exception explicitly requires journal repair before retry. Lock/handle
cleanup runs in `finally`. This protects cooperating writers and retry after I/O
failure, not machine-crash/power-loss atomicity.

Removed obsolete commented-out SQL methods (`in_set`, `row`, `has_row`, `ondata`,
`effect`, conditional/data/error/string helpers and duplicate count/empty bodies),
dead `_data`, `_errors`, `_fetch_all`, `_err_filter_fn` fields, unused imports, and
stale statement-building alternatives. The historical `SQL::from(fetch_all: ...)`
argument remains accepted for compatibility; it no longer stores dead state.

`test_db_hardening.php` adds nine cases, each demonstrated failing before its
corresponding fix; all nine now pass. Four additional real-mysqli integration
cases verify cursor/EOF/close behavior, multi-session replay and quoted bytes,
301-row dump/restore with positive short writes and tiny budgets, and real factory
setup/native query exceptions. See the live-server section below.

## Idempotent error-log close and simulation-safe replay initialization

`DB::close()` now compares diagnostic entries against the last completed logging
snapshot by array key and value. Unchanged entries are not appended again, while
new or replaced entries are logged once with their original keys/order. Identical
text at a new key is a distinct diagnostic, not globally deduplicated. Closing an
observably cleared list resets the snapshot; the public `errors` list is never
cleared by logging. Duplicate filtering and disabled-output behavior are unchanged.
Only a complete successful append advances the snapshot; false/partial writes
leave entries eligible for retry. Partial error-log I/O recovery and concurrent
append locking remain separate hardening concerns, not guarantees of this fix.

`enable_replay()` during simulation validates/records the destination but executes
no session-inspection query and creates no journal. Explicitly calling
`enable_simulation(false)` initializes deferred replay before allowing real
application execution, capturing the current real session's settings. Failed
initialization leaves simulation active and can be explicitly retried. Existing
replay initialized before simulation retains its original header and does not
repeat inspection when simulation is toggled. Connected/path/destination guards
remain unchanged, and simulated statements never enter the replay queue.

`test_db_close_simulation.php` adds seven cases (six failed before the fix, one
already passed); all seven now pass. They cover repeated close, new/replaced and
reset diagnostics, simulation-before-replay ordering without driver/file I/O,
real-mode initialization exactly once, initialization failure/retry, and the
existing replay-before-simulation path. The live replay test now also measures the
server's Questions counter to prove initialization/generated writes do not reach
the driver while simulation is active, then verifies real replay after transition.
All 4 lifecycle and 3 quoting tests were rerun successfully on disposable MariaDB.

## Required connection and client-escaping contract

`DB::connect()` and `DB::from()` configure the handle before exposing it:

1. `mysqli_set_charset(..., 'utf8mb4')` establishes the UTF-8 client, connection,
   and result charset, including the driver's charset metadata.
2. One `SET SESSION sql_mode` statement removes only `NO_BACKSLASH_ESCAPES`,
   preserving strict, ANSI, and other flags. It does not issue a separate SELECT.
3. Any failed setup (false return or exception) closes the handle and returns a
   disconnected wrapper with an error diagnostic. Connect exceptions similarly
   return a disconnected wrapper. Reconnecting/re-wrapping repeats setup.

`quote_utf8()` now returns ordinary single-quoted literals using PHP `strtr`, not
hex. It escapes backslashes, both quotes, NUL, newline, carriage return, tab,
backspace, and control-Z in a single replacement pass. `quote()` shares this path
for strings and implicit string casts; arrays recurse through it. Numeric-looking
strings stay quoted to preserve their type/leading zeros. All value builders are
checked against the same policy, including insert/update/bulk/template/store.

No database connection or escape function is called per value. For clarity,
`mysqli_real_escape_string()` normally runs client-side too; it is not used here.
The connection setup is performed once per factory call, not per parameter.

**Do not change charset or enable NO_BACKSLASH_ESCAPES after connection setup.**
Ordinary backslash escaping is intentionally not valid in that unconfigured mode.
`DB::from()` also changes these settings on an externally supplied handle. Raw SQL,
`!` expressions/placeholders, and dynamic identifiers remain trusted-input APIs.
Standalone `quote()` callers must use the same session/charset contract. Dump
headers establish utf8mb4 and the same SQL mode before restoring escaped values;
the dumper no longer changes the live connection to three-byte utf8.

## Opt-in real-server security tests

`integration/test_db_quote_mysql.php` contains three TinyTest tests for actual SQL
parsing, byte/charset round trips, injection-resistant predicates, text collations,
and preservation of other SQL-mode flags. They start with default mode,
`NO_BACKSLASH_ESCAPES`, `ANSI_QUOTES`, and both flags together, then execute the
exact production mode-setup statement before using PHP-escaped literals. They
also cover empty modes and the removed flag in first/middle/last positions.
They are outside the shallow default scan because they require an explicitly
configured disposable test database/server.

```sh
THREADFIN_TEST_MYSQL_SOCKET=/path/to/disposable/server.sock \
THREADFIN_TEST_MYSQL_DATABASE=threadfin_quote_tests \
php /home/cory/Work/tinytest/tinytest.php -j \
  -f tests/integration/test_db_quote_mysql.php
```

The database must already exist. These tests create only a connection-local
TEMPORARY table. The client defaults to `mariadb`, user `root`, socket transport,
and no option files/password; override `THREADFIN_TEST_MYSQL_CLIENT` and
`THREADFIN_TEST_MYSQL_USER` for a compatible CLI/socket-auth setup. Do not point
them at production. The server is not started or stopped by the test file.

Verified on a newly initialized, socket-only MariaDB 12.3.3 instance: **3 passed,
0 failed**, no runner errors. That temporary server/data directory was removed
after the run. MySQL itself has not been exercised on this machine. Quoting tests
use the SQL CLI. mysqli is not enabled in the default PHP configuration, but the
installed module is explicitly loaded only in isolated lifecycle-test children.

## Opt-in real-mysqli lifecycle tests

`integration/test_db_mysql_lifecycle.php` contains four additional tests using
real production `db.php` and real mysqli, not the stand-in. They verify:

- Native buffered cursors, normalized random offsets, EOF restoration, empty and
  closed results, charset/mode configuration.
- Executing a shared journal from multiple connections, initial autocommit-off and
  explicit uncommitted transactions, committed/savepoint data, UTF-8/quoted bytes,
  importer mode/autocommit defaults, trailing SQL comments, repeated close, and
  simulation-before-replay initialization verified with the native Questions counter.
- Actual 301-row dump/restore with NULL/zero/numeric text and binary-safe quoting,
  300-row checkpoints, real positive short writes, and tiny byte-budget stopping.
- `DB::connect()` and native mysqli exceptions through write/read/close paths.

These tests require an **EMPTY disposable database**, socket authentication with
no password, and explicit permission for schema changes. They create randomly
named ordinary tables, drop them in cleanup, and reject nonempty databases before
changing anything. Never point them at production. As with the quoting suite,
tests do not start/stop the database server themselves.

```sh
THREADFIN_TEST_MYSQL_SOCKET=/path/to/disposable/server.sock \
THREADFIN_TEST_MYSQL_DATABASE=threadfin_lifecycle_tests \
THREADFIN_TEST_MYSQL_ALLOW_SCHEMA_CHANGES=1 \
php /home/cory/Work/tinytest/tinytest.php -j \
  -f tests/integration/test_db_mysql_lifecycle.php
```

`THREADFIN_TEST_MYSQL_USER` defaults to `root`. `THREADFIN_TEST_PHP` selects the
child PHP CLI; `THREADFIN_TEST_MYSQLI_EXTENSION` selects a module name/absolute path
(default `mysqli`). Children use `php -n -d extension=...`; the host process never
loads the stand-in or real mysqli module on behalf of tests.

Verified on a fresh socket-only MariaDB 12.3.3 server with PHP 8.5.10/mysqlnd:
**4 lifecycle + 3 quoting tests passed, 0 failed**, no runner errors. The temporary
server/data directory was shut down and removed after verification. MySQL itself,
crash/power-loss recovery, nontransactional engines, arbitrary SQL/session state,
and concurrent journal-reader behavior still warrant broader integration testing.
A green regression suite is not a proof of complete database correctness.
