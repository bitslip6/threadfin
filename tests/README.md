# db.php regression tests

These are expected-behavior TinyTest tests, not tests that expect the current bugs.
The initial baseline was **41 failed / 0 passed / 0 incomplete / 0 skipped**.
The SQL-injection work expanded it to **44 failed / 0 passed** before the fix.
The current suite has **137 tests: 127 passed / 10 failed**, exit 1, with no
incomplete cases, skips, or runner errors. Quoting, connection failure handling,
the dump charset-statement terminator, current-row column lookup, complete
buffered-result array conversion, associative duplicate updates, object
store/attribute mapping, cursor synchronization, invalid array-read rejection,
array-backed result operations, row-offset existence bounds, the dumper's
result map/reduce methods, dump byte budgets, requested-database selection,
query simulation, null/missing template-parameter handling, NULL predicates, and
falsey `upsert_fn()` updates now pass. Other reviewed bugs remain unfixed.

## Current status

Verified with the default TinyTest runner: **137 total, 127 passed, 10 failed**.
There are **0 incomplete tests, 0 skipped tests, and 0 runner errors**. Exit code
1 comes from the outstanding regressions, not a runner/setup failure. Counts below
are test functions, not assertions or separate bugs.

| Test file | Passed | Failed | Total |
| --- | ---: | ---: | ---: |
| `test_db_array_results.php` | 8 | 0 | 8 |
| `test_db_bounds.php` | 5 | 0 | 5 |
| `test_db_connection.php` | 5 | 0 | 5 |
| `test_db_cursor.php` | 7 | 0 | 7 |
| `test_db_dump.php` | 10 | 0 | 10 |
| `test_db_duplicate_updates.php` | 4 | 0 | 4 |
| `test_db_regressions.php` | 36 | 10 | 46 |
| `test_db_result_reads.php` | 9 | 0 | 9 |
| `test_db_simulation.php` | 8 | 0 | 8 |
| `test_db_store.php` | 6 | 0 | 6 |
| `test_db_templates.php` | 8 | 0 | 8 |
| `test_db_transforms.php` | 10 | 0 | 10 |
| `test_db_upsert.php` | 6 | 0 | 6 |
| `test_db_where.php` | 5 | 0 | 5 |
| **Total** | **127** | **10** | **137** |

### Remaining failures

All 10 failing functions are in `test_db_regressions.php`. The names below omit
only the common **`test_db_`** prefix. This is the current fix backlog; the broader
coverage table below includes both fixed and outstanding regressions.

| Outstanding issue | Failing test suffixes | Count |
| --- | --- | ---: |
| Bulk inserts flush immediately/use numeric column names | `bulk_insert_buffers_until_flush_or_limit`, `bulk_insert_accepts_list_column_names` | 2 |
| Non-duplicate SQL errors discarded | `close_logs_non_duplicate_sql_errors` | 1 |
| Replay loses DDL/rollback semantics and duplicates on close | `replay_records_successful_ddl`, `replay_does_not_commit_rolled_back_writes`, `repeated_close_does_not_duplicate_replay` | 3 |
| Stream falsey strings, short writes, cross-stream totals | `stream_writes_literal_zero`, `stream_retries_short_writes`, `stream_byte_totals_are_per_stream` | 3 |
| Stored SQL text replaced by result wrapper | `result_retains_original_sql_text` | 1 |
| **Total** | | **10** |

The opt-in live-server suite is separate from these totals. Its last verification
was **3 passed, 0 failed** on disposable MariaDB 12.3.3; it was not rerun for this
falsey `upsert_fn()` fix. See the integration section below.

## Running tests

Run from the project root:

```sh
php /home/cory/Work/tinytest/tinytest.php -j -d tests
# Show only failures while preserving the full-suite summary:
php /home/cory/Work/tinytest/tinytest.php -x -d tests
php /home/cory/Work/tinytest/tinytest.php -v -f tests/test_db_regressions.php
php /home/cory/Work/tinytest/tinytest.php -j -f tests/test_db_array_results.php
php /home/cory/Work/tinytest/tinytest.php -j -f tests/test_db_bounds.php
php /home/cory/Work/tinytest/tinytest.php -j -f tests/test_db_connection.php
php /home/cory/Work/tinytest/tinytest.php -j -f tests/test_db_cursor.php
php /home/cory/Work/tinytest/tinytest.php -j -f tests/test_db_dump.php
php /home/cory/Work/tinytest/tinytest.php -j -f tests/test_db_result_reads.php
php /home/cory/Work/tinytest/tinytest.php -j -f tests/test_db_simulation.php
php /home/cory/Work/tinytest/tinytest.php -j -f tests/test_db_duplicate_updates.php
php /home/cory/Work/tinytest/tinytest.php -j -f tests/test_db_store.php
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

- PHP 8.1+ CLI, `proc_open`, and writable temporary storage; no database server,
  mysqli extension, network access, or Composer dependencies are required.
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
  No test writes the production `/tmp/php_sql_errors.log`.
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
  recording false errors. Dump budgets include headers. Replay may preserve
  rollback boundaries in order or omit rolled-back writes. Byte totals belong
  to individual streams. These explicit contracts avoid asserting current bugs.

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
backing-data check makes closed mysqli results report no available rows.
Numeric-string offsets retain their existing support. Empty/null wrappers and
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
markers, not a newly implemented resumable-dump API. Generic writer short-write
handling and the separate stream regressions remain outstanding.

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
after the run. MySQL itself has not been exercised on this machine. This PHP
installation lacks mysqli, so factory calls are verified with the stand-in; real
server parsing/charset/mode behavior is exercised through the SQL CLI.

A passing regression is not proof of full database correctness. Dump/restore,
transactions, and broader driver integration still need coverage during their
respective production fixes.
