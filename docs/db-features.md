# Additive database APIs

Load `db.php` for the standalone `ThreadFin\DB\DB` class (connections, queries,
prepared statements, streams, transactions and result helpers). To use logging,
simulation, replay, telemetry or exports, load `db-extended.php` instead and create
`ThreadFin\DB\DBExtended` connections with `DBExtended::from()` or
`DBExtended::connect()`. The extended file loads `db.php`; the base file never
loads the extended file. Existing applications using opt-in operations on `DB`
need to switch to `DBExtended`.

PHP 8.1+ is supported; no Composer dependency is required.

## Transaction callbacks

```php
use ThreadFin\DB\DB;

$value = $db->transaction(function (DB $db) {
    $db->update('records', ['name' => 'updated'], ['id' => 1]);
    return false; // Returned unchanged after a successful commit.
});
```

The outer callback owns a transaction on an idle, autocommit-enabled connection.
Existing transactions (including adopted connections) and autocommit-off sessions
are rejected without committing their pending work. Nested callbacks use unique
savepoints. An inner failure that is safely rolled back can be caught by the outer
callback; deadlocks, connection loss, and failed controls invalidate enclosing
scopes. There are no automatic retries.

An ignored database failure still prevents commit, independently of the public
`errors` array and legacy numeric return values. Uncaught callback exceptions
roll back. Caught, repaired input-validation errors occurring before execution
retain existing builder behavior; transaction SQL/lifecycle guard failures are
not repairable within that scope. If rollback also fails, the original exception
remains primary; `transaction_cleanup_errors()` exposes at most 16 safe cleanup
failures. `ExecutionFailure` provides `errno`, `sqlstate`, and
`transactionInvalidated` without embedding SQL/driver error text.

### Boundaries

- Guarantees require transactional tables and exclusive use of the connection.
  Direct use of an adopted raw handle, nontransactional tables, and function/trigger
  side effects remain caller responsibilities.
- Only single SELECT/INSERT/UPDATE/DELETE/REPLACE/SHOW/DESCRIBE and EXPLAIN SELECT
  statements are eligible inside helpers. DDL, CALL, raw transaction/session
  controls, executable comments, and unsupported syntax are rejected before I/O.
  The conservative scanner runs only inside an explicitly invoked helper.
- Do not close the DB or change simulation/replay settings inside a callback.
- Explicitly flush bulk closures before returning; pending buffers are not
  automatically executed or rolled back.
- Simulation invokes callbacks and local validation without transaction controls
  or session inspection. Connection-factory setup still occurs normally.
- Controls retain execution order in enabled replay journals. Explicit NO CHAIN /
  NO RELEASE controls leave the connection idle and reusable regardless of
  `completion_type`.
- An ambiguous COMMIT acknowledgement cannot guarantee rollback or exactly-once
  execution. Callback side effects outside the DB cannot be rolled back.

## Native prepared queries

```php
use ThreadFin\DB\BinaryParameter;
use const ThreadFin\DB\DB_FETCH_NUM_ROWS;

$rows = $db->fetch_prepared('SELECT name FROM records WHERE id = ?', [42]);
$changed = $db->execute_prepared(
    'UPDATE records SET name = ? WHERE id = ?', ['00123', 42], DB_FETCH_NUM_ROWS
);
$db->execute_prepared('INSERT INTO blobs (payload) VALUES (?)', [new BinaryParameter("\x00\xff")]);
```

Only positional `?` placeholders and list parameters are supported; legacy
`{name}` templates are unchanged. Bindable inputs are null, bool, int, finite
float, UTF-8 strings (including numeric-looking text), and explicit
`BinaryParameter` bytes. Unsupported values/list shapes/modes throw
`InvalidArgumentException` before I/O. Binary payloads remain resident and are
sent in 64KiB chunks, subject to server packet limits; this is not a file-stream
upload API.

Driver failures, parameter-count mismatches, missing rowsets and lifecycle/replay
rejections throw `ExecutionFailure`; ignored/caught execution failures still
prevent transaction commit. Successful count/ID modes match the legacy API,
including `-1` for a successful write with no generated ID. Simulation returns
empty buffered results or status/count/ID 1/0/0, without prepare/bind/execute calls;
it validates local types, not server SQL or placeholder counts.

- `fetch_prepared()` is caller-trusted read-only SQL, excluded from replay. It
  buffers results and returns ordinary `SQL`; independent row copies use portable
  statement binding rather than requiring mysqlnd. Use streaming for large reads.
- Real `execute_prepared()` is rejected **before prepare** while legacy SQL replay
  is enabled. Prepared writes cannot silently disappear from that journal.
- New prepared logs/error messages omit bound values and raw driver error text.
  Native warning-only reporting is contained only during synchronous prepared
  work/cleanup; caller handlers are restored, and global reporting is unchanged.
- `prepared_cleanup_errors()` retains at most 16 failures. Primary exceptions are
  preserved; failed cleanup after successful execution does **not** mean a write
  was undone. Do not blindly retry. Unknown statement-close state disconnects the
  handle while preserving pending legacy journal/error cleanup.

### Exception trace privacy

PHP may retain function arguments in exception traces. Applications logging full
exceptions should configure `zend.exception_ignore_args=1` before execution.
The library does not change process-global INI. Dumping/serializing exception
objects (including cleanup accessors) is not a privacy-safe export without that
setting; library privacy guarantees cover emitted messages and explicit safe
fields, not PHP-managed traces. No raw driver exception is chained.

## Streaming reads

```php
$rows = $db->stream_prepared('SELECT id, name FROM records WHERE id > ?', [42]);
try {
    foreach ($rows as $row) {
        // Process one independent associative row at a time.
    }
} finally {
    $rows->close();
}
// Legacy template alternative:
$rows = $db->stream('SELECT id FROM records WHERE id > {id}', ['id' => 42]);
$rows->close();
```

`StreamingRows` is a separate single-pass iterator, not buffered `SQL`. It has no
count, random access, seek, or all-rows conversion API; a second rewind throws.
Raw streams use MYSQLI_USE_RESULT; prepared streams use bind_result/fetch without
store_result/get_result. Row value types follow their respective native driver
paths. `_sql` contains rendered legacy SQL or the prepared template, not bindings.

One live stream owns its DB connection. Competing queries, transaction entry,
replay initialization and mode changes fail before driver I/O. Breaking a foreach
while retaining the iterator does **not** release ownership: use `try/finally`.
Exhaustion or close releases it; repeated close is safe. DB close also closes its
stream. A transaction callback returning with a stream open closes it and rolls
back instead of committing; a callback exception stays primary.

Simulation returns an empty iterator with no driver resources or busy ownership.
`stream_cleanup_errors()` retains at most 16 safe cleanup failures, subject to the
exception-trace privacy note above. Lost connections require a fresh connection,
not an automatic retry. Cancellation may drain unread server data: memory-bounded
does not mean latency-bounded. Memory scales with the largest row and supplied
parameters, and collecting rows yourself can still consume unbounded memory.

Opt-in `benchmark/streaming_memory.php` runs separate raw/prepared reader processes.
On the tested mysqlnd setup, each streamed 32,768 rows containing 256MiB of payload
under a 32MiB PHP limit with about **414KB peak PHP memory delta** (2MiB allocated
peak). This measures PHP allocation, not OS RSS, and is not a maximum-row guarantee.
Ordinary queries acquire only cheap inactive lifecycle guards; no added driver
round trips, parsing, timing or hashing. Paired native timings varied in both
directions, so no general throughput improvement or zero-overhead claim is made.

## Privacy-safe telemetry (disabled by default)

```php
use ThreadFin\DB\QueryEvent;

$db->observe(function (QueryEvent $event): void {
    // Send this immutable scalar event to your metrics sink.
}, ['sample_rate' => 0.1, 'slow_threshold_ms' => 50]);
// Later, after closing any active real stream:
$db->observe(null);
```

`QueryEvent` contains operation/correlation identifiers, kind, real/simulated mode,
status, monotonic duration/startup/first-row timing, available rows/affected counts,
errno/SQLSTATE, and an HMAC-SHA256 identifier of the exact input template. It never
contains SQL, parameter values, credentials, driver error text or exception objects.
The identifier is **not** a normalized SQL shape. Legacy builders have only rendered
SQL available for grouping; `fetch()`/`stream()` preserve their original template.
A random HMAC key is used per observer installation; an explicitly supplied
`hmac_key` (at least 16 bytes) permits stable grouping across installations.

- `sample_rate`: finite 0–1, default 1 after opt-in. Zero does no per-operation
  timing/hash/event allocation. Sampling does not change application `mt_rand` state.
- `slow_threshold_ms`: finite 0–86400000, default 0. At zero, sampled streams emit
  start and terminal events. A positive threshold emits only terminal events that
  meet the threshold, including separate startup/first-row timing.
- Timings include execution/setup/cleanup; stream totals also include consumer
  delay and cancellation drain. Sampling/hash setup and final callback dispatch
  are excluded; stream-start observer time is included in its eventual total.
- Nested calls triggered by template conversion have independent identities and
  outcomes. Real/simulated mode reflects the execution boundary after conversion;
  earlier validation failures retain entry mode. Stream mode freezes at startup.
- Observer exceptions are swallowed; same-DB recursive execution/configuration
  and retained-stream mutation are rejected before driver I/O without poisoning
  transactions. `telemetry_diagnostics()` returns bounded saturating counters for
  the current installation, not an event history or captured exceptions.
- Retained simulated streams finish through their originating observer after
  disable/replacement; recursion protection remains effective. Real streams must
  be closed before observer replacement. Callbacks run after warning handlers are
  restored. Legacy SQL logs and replay journals are **not** redacted by telemetry.

## Resumable keyset dumps

```php
use function ThreadFin\DB\dump_resumable;

$report = dump_resumable($db, '/private/export.sql', '/private/export.checkpoint', [
    'source_frozen' => true,
    'source_idle' => true,
    'source_id' => 'unique-nonsecret-freeze-identifier',
    'tables' => ['records'], // Omit to export all tables.
    'batch_rows' => 300,
    'max_bytes' => 8 * 1024 * 1024,
    'max_batches' => 10,
]);
// Repeat with the same paths/source/options until $report->complete.
// If required_bytes exceeds max_bytes, increase the budget before retrying.
```

Equivalent method: `$db->dump_resumable($output, $checkpoint, $options)`.
Existing `dump_database()`, `dump_table()` and `Offset` are unchanged.

**Preconditions:** keep all selected data/schema frozen from first call through
completion, with the same unique freeze identifier. Each invocation requires an
idle, exclusively owned source connection without an external transaction or old
snapshot; fresh connections are recommended. Autocommit=1 is checked but is not
proof of idle ownership. The acknowledgements are operator preconditions, not a
portable snapshot or automatic row-change detector.

### Supported first-release scope

- InnoDB base tables with one non-null integer PRIMARY KEY. Signed/unsigned BIGINT
  extrema remain decimal strings; cursors are bound numerically, never OFFSET.
- Integer/DECIMAL, CHAR/VARCHAR/TEXT, BINARY/VARBINARY/BLOB, DATE/DATETIME/TIME and
  YEAR/YEAR(4). Column order, charset and byte/type fidelity are preserved.
- Rejects composite/noninteger/missing keys, views/non-InnoDB, FLOAT/DOUBLE/BIT,
  JSON/spatial/ENUM/SET/TIMESTAMP/YEAR(2), ZEROFILL, generated/unsupported automatic
  columns, foreign keys, CHECK constraints and triggers. MariaDB JSON aliases are
  rejected by their CHECK constraints. All selected schemas are checked before
  output. No routines/events/grants/database creation: not a whole-server backup.
- Direct global/schema/table **TRIGGER** privilege is required to prove trigger
  visibility. SELECT-only or unverified role-only metadata visibility rejects;
  no privileges are granted by the library. Metadata account names must match
  `[a-zA-Z0-9_.-]+@[a-zA-Z0-9_.:%-]+`.
- `sql_quote_show_create=1` is required. Accepted SQL modes: empty or combinations
  of STRICT_TRANS_TABLES, STRICT_ALL_TABLES, ONLY_FULL_GROUP_BY, NO_ZERO_IN_DATE,
  NO_ZERO_DATE, ERROR_FOR_DIVISION_BY_ZERO, NO_ENGINE_SUBSTITUTION,
  NO_AUTO_VALUE_ON_ZERO, NO_AUTO_CREATE_USER. Other modes reject explicitly.

### Recovery and restore

Output/checkpoint must be distinct local regular files in trusted directories,
not symlinks, wrappers, gzip streams or arbitrary callbacks. Output and a stable
checkpoint `.lock` sidecar are locked; do not remove that sidecar while writers
may be active. Every complete header/DDL/row/footer unit is written and flushed
before atomic checkpoint publication. Resume validates schema/options/source
fingerprints, inode identity, committed length and prefix hash, then truncates
uncommitted excess output. Missing checkpoint plus nonempty output always rejects;
inspect/repair or explicitly remove both files before restarting.

Source fingerprints include actual database/server/socket/datadir/server-id
identity, stored only as hashes. This prevents accidental endpoint mixups, not
malicious cloning. Checkpoints contain cursor/progress data but no credentials or
raw row payloads; their checksums detect corruption, not forgery. Protect checkpoint
files. Prefix hashing is incremental, with one validation pass on resume.

`DumpReport` exposes complete/status/phase, cumulative rows/position, invocation
bytes_written/batches_written, and required_bytes for an insufficient budget.
Budgets end at whole units; batch_rows is 1–10000. Memory scales with one batch and
its largest cells. No hidden throttling or global source locks.

Restore a **complete** dump into a fresh target schema using a fresh idle session
on a compatible server. DDL/autocommit may commit existing work; restore is not
atomic. The envelope forces autocommit1 and preserves zero auto-IDs/bytes, then
restores importer SQL mode/autocommit; utf8mb4 remains set. Recovery covers detected
failures and process restarts, **not power loss**: no file/directory fsync guarantee.

## Sandboxed replay verification

Explicitly include `db_verify.php`; ordinary DB calls never load it. This offline
verifier accepts **no source credentials or existing destination endpoint**. Each
attempt starts a fresh private installed MariaDB instance, restores a baseline,
replays the selected journal prefix, compares an expected snapshot, and tears down
its own instance. It never restores into the source database.

```php
require_once '/path/to/threadfin/db_verify.php';

$report = \ThreadFin\DB\verify_replay(
    '/private/journal.sql', '/private/baseline.sql', '/private/manifest.json',
    ['enabled' => true, 'timeout_seconds' => 60, 'max_disk_bytes' => 536870912]
);
// Success requires both matching data/schema and successful cleanup.
if (!$report->matched) { /* Inspect safe status, position and table hashes. */ }
\ThreadFin\DB\verify_write_report($report, '/private/report.json', [
    '/private/journal.sql', '/private/baseline.sql', '/private/manifest.json',
]);
```

With mysqli and POSIX enabled in the CLI PHP:

```sh
php bin/db-verify.php --enable-sandbox JOURNAL BASELINE MANIFEST REPORT
```

CLI output is safe JSON; exit 0 means matched and cleaned, 1 means unsuccessful
verification, and 2 means invocation/report failure. Reports are atomically
published with mode0600, must not alias inputs, and contain only status/cleanup,
optional journal byte position, hashed table identifiers and expected/actual
schema/row hashes/counts. No rows, SQL, credentials, raw errors or exceptions.
Hashes are not anonymization or authentication; protect all artifacts.

### Preparing the offline manifest

The operator must establish a consistent initial baseline and final expected
state at an exact **complete journal-session cut**. Freeze the source at that cut,
use an idle exclusively owned read-only mysqli connection, and configure it for
UTC (`+00:00`), utf8mb4/utf8mb4_bin, `sql_quote_show_create=1` and one of the dump
SQL modes above. `verify_snapshot($source)` issues only metadata/SELECT queries;
it does not establish a snapshot or prove hidden triggers/routines/events absent.
The required source-policy attestation is the operator's responsibility.

A version1 manifest has exactly these fields (construct while inputs are frozen):

```php
$journalPrefix = file_get_contents('/private/journal.sql'); // Entire complete cut here.
$manifest = [
    'version' => 1,
    'source_id' => 'unique-nonsecret-freeze-identifier',
    'source_policy' => 'frozen-cut-no-hidden-schema-objects',
    'policy' => ['timezone' => '+00:00', 'charset' => 'utf8mb4',
                 'collation' => 'utf8mb4_bin', 'types' => 'integer-pk-bytes-v1'],
    'journal' => ['sha256' => hash('sha256', $journalPrefix), 'cut' => strlen($journalPrefix)],
    'baseline_sha256' => hash_file('sha256', '/private/baseline.sql'),
    'expected' => \ThreadFin\DB\verify_snapshot($source),
];
// Write JSON to a private manifest file without exposing source credentials.
```

Each expected table entry contains `schema_sha256`, `rows_sha256`, and integer
`count`. Numeric table names survive PHP/JSON key coercion; `00123` remains distinct
from `123`. Rows use integer-primary-key ordering and type/length/NULL framing.
Schema comparison preserves SHOW CREATE bytes except the unquoted AUTO_INCREMENT
counter. Different server-version DDL formatting can legitimately mismatch.

### Restrictions and resource limits

- Concrete backend: Linux, PHP CLI/proc_open/POSIX, loadable `mysqli.so`, and
  installed `/usr/bin/{setsid,mariadb-install-db,mariadbd}`. Nothing is installed or
  configured globally. No Oracle MySQL provisioning backend is supplied.
- Inputs must be trusted local regular nonsymlink files; directories and filenames
  must remain stable during a run. An optional trusted `temp_parent` must resolve
  to a short absolute path containing only letters/digits/underscore/dot/slash/hyphen.
- Baselines support the complete resumable-dump format above or eligible SQL.
  Journals require complete legacy session envelopes, ordinary backslash quoting
  and supported modes. Rejects CLI commands/DELIMITER, executable comments,
  unsupported SQL/session controls, incomplete envelopes and byte-identical repeated
  packets. This conservative duplicate check is not an exactly-once guarantee.
- Supported comparison schema is the dump's narrow integer-key/type scope above.
  Arbitrary dumps, views, triggers, routines, grants, non-InnoDB engines and
  external-file/network DDL options are unsupported.
- Only controlled bootstrap uses root. All baseline/journal SQL uses a schema-only
  account without FILE/SUPER/grant/routine/trigger/cross-schema privileges. Networking
  and LOCAL INFILE are disabled. SQL goes through mysqli, never a SQL CLI.
- `timeout_seconds`: integer5–300, default60, covers provisioning/replay/comparison
  under an independent process supervisor. TERM/KILL process teardown has a separate
  three-second grace. Per-statement timeout is at most10 seconds.
- `max_disk_bytes`: integer128MiB–2GiB, default512MiB, sampled about50ms. This is a
  **soft cap, not an OS quota**; initialization and writes may overshoot. Worker PHP
  memory128MiB, server pool32MiB, packet4MiB, connections8, temp tables8MiB. These
  are not kernel CPU/RSS isolation limits.
- Each input≤16MiB, at most10,000 statements/input and1MiB/statement, at most100
  snapshot tables. No automatic retries, repair or raw-row diff mode. Source snapshot
  capture itself runs in the caller and is not covered by the sandbox deadline.
- Only the positively owned private tree is removed, without following symlinks.
  Cleanup failure makes the report unsuccessful. Supervisor SIGKILL, machine crash
  and power loss can leave resources requiring operator cleanup; no crash-proof
  daemon cleanup or power-loss durability is promised.

## Validation status

All six phases: 348 default tests and 138 native tests passed on disposable MariaDB
12.3.3 with PHP8.5.10/mysqlnd. Native Oracle MySQL and a PHP8.1 runtime have not been
executed. Early PHP8.1 exception compatibility is covered at the driver boundary
and checked against official PHP source. Native non-mysqlnd execution and driver
cleanup-protocol fault injection remain unverified. Final independent review is
in progress; see `plans/db-feature-progress.md`.
