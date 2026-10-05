# DB feature implementation plan — subagent handoff

## Mission

Implement these six additive features for ThreadFin's `db.php`, using bounded
subagent assignments and independent review:

1. Transaction callbacks with nested savepoints.
2. Native prepared-query APIs with typed parameter binding.
3. Memory-bounded streaming reads.
4. Privacy-safe query telemetry.
5. Resumable, primary-key-based dumps.
6. A sandboxed replay verification tool.

Deliver incrementally. Do not turn this into a wholesale database-layer rewrite.
The coordinating agent owns API decisions, integration, acceptance, and the final
report. This document is a plan, not an implementation or permission to publish.

## Starting context and baseline

- Repository: `/home/cory/Work/threadfin`; namespace: `ThreadFin\DB`.
- Main files: `db.php`, `core.php`, `tests/README.md`.
- Unit boundary: `tests/support/mysqli.php`; host/child transport:
  `tests/support/regression.php`, `tests/support/db_case.php`,
  `tests/support/hardening_cases.php`.
- Real-driver coverage: `tests/support/mysql_live.php`,
  `tests/integration/test_db_mysql_lifecycle.php`,
  `tests/integration/test_db_quote_mysql.php`.
- Latest working-tree baseline: **186 unit tests passed**, no failures, skips,
  incomplete cases, or runner errors. **7 opt-in live tests passed** on disposable
  MariaDB 12.3.3 with PHP 8.5.10/mysqlnd. MySQL itself has not been verified.
- PHP 8.1+ remains the supported floor; avoid accidentally requiring newer APIs.
- No Composer/autoloader requirement currently exists. Follow repository style;
  do not introduce a framework or dependencies without approval.

**Important working-tree caveat:** when this plan was written, branch `master`
was at `f6e4b8e`, but the latest close/simulation fixes were uncommitted. The
186-test baseline applies to the working tree, not necessarily that commit.
Changes included `db.php`, test documentation/fixtures, the live lifecycle tests,
and the new `tests/test_db_close_simulation.php`. Reinspect current status.

Before creating worker worktrees, obtain a clean, explicitly recorded implementation
base containing those changes. Use an operator-approved checkpoint or managed
snapshot; do not discard/stash user changes or start lanes from an older HEAD
while claiming the 186-test baseline. Preserve untracked files too.

## Invariants every feature must preserve

- Existing public methods, template semantics, return modes, error lists, bulk
  buffering, and SQL journal format remain compatible unless a change is approved.
- `SQL::offsetExists()` uses cached `_len`, **never `count()`**.
- Buffered `SQL` remains distinct from streaming results. Preserve cursor state,
  integer-string offset normalization, closed-result invalidation, and SQL metadata.
- Simulation performs no application-query execution, prepare calls, transaction
  controls, streaming fetches, or replay initialization I/O. Existing connection
  factory setup is still required. Deferred replay initialization occurs only when
  explicitly leaving simulation, and failure keeps simulation active.
- Repeated close does not duplicate error logs or replay. Public diagnostics are
  not cleared merely because they were logged.
- Quoting/charset/session contracts and caller-trusted raw SQL/identifiers remain
  documented. New native binding must not silently reinterpret legacy templates.
- A numeric sentinel is not proof of driver failure: successful DDL requested in
  insert-ID mode can return `-1`; simulated count/ID modes return `0`.
- Do not infer transaction success from parsing public error-message strings or
  comparing a caller-mutable `errors` array. Use actual internal execution outcomes.
- Never restore obsolete commented methods/dead fields. Add state only when it has
  a concrete owner, lifecycle, and tested purpose.
- Default tests require no server or real mysqli extension. Extend stand-ins only
  at driver boundaries; never build a fake SQL or transaction engine.
- Native tests use disposable infrastructure only. Never replay journals, change
  schemas, or run benchmarks against production.

## Subagent execution strategy

### Authority and topology

This is **multi-seam work**: transaction lifecycle, statement binding, cursor
streaming, telemetry, dump recovery, and sandbox verification are independently
testable contracts. Do not hand all six outcomes to one implementation child.

At the same time, most features touch `db.php`. Do **not** launch six concurrent
writers against it. Use serialized core-writer phases and parallelize only
independent evidence, tests/modules with frozen interfaces, and fresh review.

Suggested roles, using available configured equivalents:

- **Parent/coordinator:** maintain contracts and lane board; integrate accepted
  handoffs; update shared documentation; own disposable-server lifecycle.
- **Scout:** one bounded read-only reconnaissance/contract report, not a duplicate
  search per feature.
- **Feature worker:** one feature/component, explicit files and tests, isolated
  worktree or other approved snapshot.
- **Reviewer:** fresh-context, read-only correctness/security review of the exact
  candidate. Return evidence-backed findings, not broad speculative redesigns.

When executing in Pi, load the installed `pi-subagents` skill, enable the tools,
inspect available roles, and consult the actual tool guide before launch. Prefer
async workers/reviewers and native completion notifications. Do not hard-code model
names or invent tool fields. Children must not spawn children unless explicitly
allowed. An infrastructure failure is a blocker, not permission to silently switch
execution protocols or overwrite a partial worktree.

### Lane board

Create a durable progress record, such as `plans/db-feature-progress.md`:

`Lane | base/ref | cwd/worktree | feature/contract | exclusive files | authority | dependency | gate | handoff | status`

Suggested implementation sequence:

| Phase | Component owner | Dependency | Allowed parallel work |
| --- | --- | --- | --- |
| 0 | Parent + one scout | Baseline | Read-only design/risk review |
| 1 | Transaction worker | Approved execution-outcome contract | Prepared-API contract review |
| 2 | Prepared-statement worker | Phase 1 integrated | Streaming test/spec preparation |
| 3 | Streaming worker | Phase 2 integrated | Telemetry or checkpoint pure modules after interface approval |
| 4 | Telemetry worker | Operation/stream lifecycle stabilized | Resumable-dump contract/test preparation |
| 5 | Resumable-dump worker | Prepared reads, streaming, checkpoint contract | Replay-verifier sandbox/preflight module |
| 6 | Replay-verification worker | Sandbox and baseline-manifest contracts | Documentation/review |
| Final | Parent or integration-only owner | All durable component handoffs | Fresh correctness and privacy/security review |

One writer per worktree. One active owner of `db.php`, shared regression fixtures,
shared integration harnesses, or shared documentation at any time. File ownership
alone is insufficient if two workers change the same internal contract.

Prefer small new feature modules where they genuinely improve separation, but do
not refactor the entire core merely to manufacture parallel lanes. The parent
approves module-loading and driver-adapter seams first. Workers may use dedicated
feature test/fixture files; shared `tests/README.md` belongs to the parent.

Each child handoff must include base/ref, full diff plus new-file contents, changed
files, commands/results, unresolved decisions, and next action. `git diff` alone
omits untracked new files. A commit is optional and requires explicit authority;
a complete durable patch/artifact is sufficient. No push, PR, merge, release, or
package/system installation is authorized by this plan.

## Phase 0 — establish contracts before mutation

1. Read current source/tests and confirm the baseline, status, and implementation base.
2. Have the scout map execution, replay, simulation, ownership, and error signaling
   seams. Identify useful existing helpers rather than duplicating them.
3. Parent writes a short accepted API/internal-contract memo in the progress record:
   - Execution outcome/error type shared by new APIs; legacy return modes preserved.
   - Transaction ownership and treatment of external/raw transaction controls.
   - Prepared-statement lifetime and replay compatibility policy.
   - Streaming-result ownership, cancellation, and connection-busy behavior.
   - Privacy-safe telemetry event schema and observer-failure policy.
   - Dump consistency mode, sink/checkpoint protocol, supported key types.
   - Verifier sandbox isolation and baseline/expected-state manifest.
4. Resolve material ambiguity before assigning writers. Do not ask workers to invent
   incompatible contracts in parallel. Research vendor behavior only where needed.

The APIs below are proposed defaults, not permission to skip this contract gate.

## Phase 1 — transaction callbacks

### Proposed API

```php
$value = $db->transaction(function (DB $db) {
    // Supported reads/writes; flush any bulk closure explicitly before returning.
    return $value;
});
```

A small options object may expose explicit retry settings; default attempts = 1.
Do not add a generic automatic write-retry mechanism.

### Required behavior

- Outer scope begins, commits on successful completion, and rolls back on callback
  exceptions or genuine failed operations. Return callback values unchanged,
  including null, false, zero, and empty text.
- Failed legacy methods that return sentinels must still prevent an accidental
  commit, even if the callback ignores the return value. Use actual execution state.
- Nested helpers use uniquely named savepoints. A safely rolled-back inner scope
  may be caught by the outer callback; it must not falsely poison the outer scope.
  A deadlock/server-wide transaction abort must invalidate the whole affected scope.
- BEGIN/COMMIT/ROLLBACK/savepoint controls are recorded through the replay-aware
  execution seam; do not bypass the journal with unobserved native calls.
- Simulation invokes the callback for generation/validation without live controls.
- Document DML transaction guarantees: DDL/implicit commits, raw controls, adopted
  connections with pre-existing transactions, and nontransactional tables must not
  silently receive a false rollback guarantee. Resolve guards/ownership in Phase 0.
- Any retry is opt-in, outermost-only, bounded, and limited to verified rollback-safe
  outcomes such as an approved deadlock policy. No automatic retry after an
  ambiguous network/commit acknowledgement. Warn about non-DB callback side effects.
- Preserve the original exception when rollback also fails; surface cleanup failure
  without concealing the original cause. Always restore internal scope state.

### Acceptance

Unit and native tests for falsey returns, commit/rollback, ignored query failure,
nesting/savepoint recovery, whole-transaction abort, simulation, replay ordering,
cleanup failure, and ownership/implicit-commit policy. Live InnoDB tests must prove
actual committed rows, not merely inspect emitted SQL.

## Phase 2 — native prepared-query API

### Proposed APIs

```php
$result = $db->fetch_prepared('SELECT name FROM records WHERE id = ?', [$id]);
$status = $db->execute_prepared('UPDATE records SET name = ? WHERE id = ?', [$name, $id], $mode);
```

Use positional native placeholders in the initial release. Named binding and a
custom SQL placeholder parser are not required.

### Required behavior

- Bind null, bool, int, finite float, UTF-8 string, and explicitly supported binary
  values without concatenating them into SQL. Preserve numeric-looking text such
  as `00123`; reject unsupported values before driver I/O.
- Define binary versus text and large-payload behavior explicitly; do not guess
  from a string's contents. Native parameter counts are validated in real mode.
- Simulation performs no prepare/bind/execute calls; validate available local
  contracts without claiming server SQL/placeholder validation.
- Preserve affected-row/insert-ID/simulation conventions. Failed operations feed
  the transaction execution-outcome contract.
- Buffered prepared reads return ordinary `SQL` wrappers with origin/template
  metadata; do not leak parameter values into default logs or metadata.
- Close statements/results on every path. State any mysqlnd dependency explicitly;
  do not quietly add an extension requirement to default unit tests.
- Extend the canned driver boundary for statement operations, not SQL semantics.

### Replay decision — must be explicit

Do not silently execute a prepared write and omit it from an enabled journal.
Default first-release policy: reject real `execute_prepared()` while legacy SQL
replay is enabled, before execution, until an approved lossless replay adapter is
implemented. Prepared fetches follow the existing read-exclusion policy.

If the parent instead approves a versioned typed-event journal, make it opt-in,
encode binary/type information losslessly, keep legacy SQL journals unchanged,
and update the verifier contract. Do not claim a commented JSON record is
compatible with SQL clients that would silently skip it. Do not substitute `?`
with regexes or legacy quoting and call that native preparation.

### Acceptance

Native injection/byte/type round trips, null/false/zero/text/binary distinctions,
count/ID modes, parameter mismatch/error cleanup, simulation with zero prepare
calls, transaction failure integration, and the approved replay compatibility policy.

## Phase 3 — memory-bounded streaming reads

### Proposed surface

A separate closeable `StreamingRows` iterator, exposed through `stream()` for the
existing raw/template contract and/or `stream_prepared()` for native positional
parameters. Clearly distinguish those parameter syntaxes. Final names are a
Phase 0 decision.

### Required behavior

- Single-pass iteration; no implicit rewind/re-execution, Countable, random access,
  or whole-dataset conversion masquerading as a cheap operation.
- Native unbuffered fetching. Prepared streaming must not use a result getter or
  storage path that buffers the complete dataset.
- Yield independent row snapshots, not reused bound references that mutate earlier
  rows. Preserve NULL/zero/text/binary values and documented driver type semantics.
- Expose explicit `close()` and recommend `try/finally`. Breaking a foreach does not
  guarantee a retained generator is immediately finalized.
- Reject other operations on the same busy connection with a deterministic error
  before driver execution. Release busy state after exhaustion, cancellation,
  exception, or DB close; make cleanup idempotent.
- Simulation returns an empty stream without driver calls. Integrate statement,
  transaction, replay-read-exclusion, and origin metadata contracts.
- Document that callers can still allocate unbounded memory by collecting all rows.

### Acceptance

Native early break/close, retained iterator, fetch error, repeated close,
connection-busy and recovery paths, prepared row-copy correctness, empty/simulated
streams, and DB closure. Add an opt-in reproducible large-result memory benchmark
using a separate measured reader process; report the fixture size and memory delta.
A small canned-array test alone is not proof of bounded memory.

## Phase 4 — privacy-safe query telemetry

### Proposed surface

An optional observer receiving immutable operation events; no mandatory external
metrics dependency. Default observation is disabled.

### Required behavior

- Events distinguish real execution, simulation, failure, streaming completion,
  cancellation, and transaction controls. Define timing boundaries explicitly.
- Use monotonic timing. Include safe operation kind, duration, row/count availability,
  errno/SQLSTATE where available, and correlation identifiers.
- Default events contain **no SQL text, bound values, credentials, or driver error
  messages that can embed those values**. A hashed template identifier is a safe
  initial grouping mechanism; label it accurately rather than claiming a full SQL
  shape parser. Any plaintext/sample mode requires explicit opt-in/redaction policy.
- Slow-query thresholds/sampling are configurable and bounded. Streaming should
  distinguish start/first-row from full-consumption timing rather than pretending
  fetch startup is the entire duration.
- Observer exceptions/recursive observer queries must not alter database outcomes,
  poison transactions, or manufacture query failures. Define separate diagnostics
  and recursion protection. Avoid retaining unlimited events internally.
- Cover both native and prepared paths without duplicate notifications. Keep replay
  data requirements separate from telemetry redaction.

### Acceptance

Secret-canary assertions across successful/failed/raw/prepared/simulated operations,
stream timing/cancellation, callback failure/recursion isolation, disabled overhead,
and bounded sampling. Review security/privacy independently before acceptance.

## Phase 5 — resumable keyset dumps

### Proposed surface

A new resumable dump API with options, a checkpoint store, a recovery-capable
output sink, and a report. Keep existing `dump_database()`, `dump_table()`, and
`Offset` behavior unchanged; their offsets are progress markers, not a resume API.

### Required behavior

- Keyset pagination over approved stable, unique, non-null primary/unique keys;
  support composite ordering only after its comparison/type contract is tested.
  Explicitly reject unsupported/no-key cases; do not fall back to fragile OFFSET.
- Quote generated identifiers correctly and bind cursor values. Preserve column
  ordering, binary/text/type fidelity, NULLs, and schema output.
- Checkpoint has a format version, source/schema fingerprint, table/phase, cursor,
  output identity and committed position, validation hashes, and relevant options.
  Do not store passwords or connection secrets.
- Headers/DDL are phases too: restarting must not duplicate destructive DDL or
  already committed batches. Budget exits are whole-batch boundaries.
- First release requires a seekable/truncatable plain output sink. Arbitrary
  callbacks, nonseekable streams, and gzip continuation cannot be declared safely
  resumable; reject unsupported sinks before output. Chunked compression can be
  a later feature.
- Write/flush a complete batch, then atomically publish its checkpoint. On restart,
  truncate output ahead of the last committed checkpoint; reject output shorter
  than it or with a mismatched identity/hash. Cover interrupted checkpoint updates.
- Do not promise a portable MVCC snapshot across process restarts. Require a
  quiesced/frozen source for reproducible dumps, or an explicitly named best-effort
  mode with clear insert/update/delete semantics. A high-water key alone does not
  make concurrent changes consistent.
- Declare durability guarantees honestly: detected failures/process restart are
  distinct from machine-crash/power-loss guarantees and directory fsync behavior.

### Acceptance

Native dump/resume/restore equivalence across multiple tables/batches, composite
keys if supported, adversarial names and binary values, tiny budgets, interruptions
before/after output/checkpoint publication, schema drift, corrupt/truncated output,
unsupported keys/sinks, and declared source-change policy. Restore into disposable
infrastructure and compare rows/schema, not only checkpoint values.

## Phase 6 — sandboxed replay verification

### Proposed surface

A callable verifier plus a thin explicit CLI entry point. Inputs include a journal,
a starting-state/baseline manifest or dump, expected-state fingerprints, sandbox
configuration, and a machine-readable report destination.

### Required behavior

- A journal is not necessarily a complete backup. Require the matching starting
  state and a defined verification cut; comparing an old journal with a changing
  live source is not a meaningful consistency proof.
- Use a separate disposable server/container from the source/production instance.
  A database-name prefix on a shared production server is not isolation: replay
  SQL can select or reference other schemas.
- Explicit opt-in, destination/source separation checks, isolated temporary storage,
  bounded resources, and cleanup on success/failure. No global service or package
  changes without approval; use installed tools or ask.
- Execute via the native protocol, not a SQL CLI accepting shell/client metacommands.
  Sandbox replay credentials have only necessary schema privileges: no FILE,
  plugin/UDF installation, arbitrary grants, or access to external schemas. Disable
  LOCAL INFILE. Source credentials are read-only and never written into reports.
- Support current session envelopes and declared journal formats. Reject unsupported
  versions/formats; implement typed prepared events only if Phase 2 approved them.
- Compare schema, counts, and strong canonical row checksums. NULL differs from
  empty text; preserve byte boundaries/types and deterministic ordering. Specify
  timezone, collation, and unsupported-type policies to avoid false equivalence.
- Report mismatched tables/counts/hashes and failed journal position. Raw row diffs
  are opt-in, capped, privacy-reviewed, and not default report content.
- Replaying is not generally idempotent or exactly-once. Fresh sandbox state is
  mandatory for every verification attempt; do not blindly retry writes after
  ambiguous acknowledgement.

### Acceptance

Matching/mismatching baseline-state cases, committed versus rolled-back data,
shared-session journals, duplicate/incomplete packets, malformed formats, quoted
and binary bytes, source/destination collision rejection, cross-schema/FILE/LOCAL
INFILE/metacommand escape attempts, cleanup failure, and privacy-safe reports.
Native tests must prove the sandbox privilege boundary and actual execution.

## Validation and delivery gates

Run from each relevant worktree, substituting its paths where appropriate:

```sh
php /home/cory/Work/tinytest/tinytest.php -j -d tests
php /home/cory/Work/tinytest/tinytest.php -j -f tests/test_db_<feature>.php
php -l db.php
# Also lint every changed/new PHP file.
git diff --check
```

Live gates follow `tests/README.md`. Existing examples:

```sh
THREADFIN_TEST_MYSQL_SOCKET=/path/to/disposable/server.sock \
THREADFIN_TEST_MYSQL_DATABASE=empty_disposable_database \
THREADFIN_TEST_MYSQL_ALLOW_SCHEMA_CHANGES=1 \
php /home/cory/Work/tinytest/tinytest.php -j \
  -f tests/integration/test_db_mysql_lifecycle.php
```

Run the quoting suite separately and add feature-specific native cases. Real
mysqli is loaded only in isolated children through the existing module-selection
mechanism. Keep server tests opt-in; do not weaken default-suite isolation.

For each phase:

1. Add focused expected-behavior tests; demonstrate the missing behavior/failure.
2. Implement the accepted feature within the lane's exclusive boundary.
3. Produce a durable handoff; parent/integration-only owner applies approved glue.
4. Run focused tests, full default suite, lint, and diff checks on the integrated
   candidate. Run the relevant native gate and state exactly which server/version.
5. Obtain fresh-context read-only review of the actual diff and named contracts.
   For security-sensitive phases, use a distinct privacy/sandbox review angle.
6. Fix accepted concrete regressions with the original owner, then rerun affected
   gates. Bound review loops to three rounds; escalate remaining design blockers.
7. Parent updates `tests/README.md`, examples, and progress record; accept the phase
   before another writer claims shared core files.

Do not let a worker implement another major feature to fill an integration gap.
Return missing behavior to its component owner. Do not mark a feature complete
because only a mocked driver passed. If infrastructure blocks a native gate,
record the exact blocker and leave that acceptance gate open.

## Reusable child assignment packet

Supply this information to every child, customized to its single feature/seam:

```text
Goal: Implement/review [one named component and contract].
Repository/cwd/base: [absolute lane path and exact approved snapshot/ref].
Read first: plans/db-feature-roadmap.md, tests/README.md, [specific source/tests],
            [accepted Phase 0 contract memo and dependency handoffs].
Authority: [exclusive files/contracts]; no other shared-file edits; no push/release;
           no subagent fanout; commits only if explicitly granted.
Preserve: [relevant invariants from this plan].
Deliver: [API/module/tests], full patch/new files and concise durable report.
Validate: [focused command], full default suite, [specific native gate], PHP lint,
          diff check; label missing evidence honestly.
Stop/ask: ownership conflict, missing dependency, unsafe infrastructure,
          backward-incompatible semantics, new dependency, ambiguous protocol,
          or a product decision outside the accepted memo.
Report: changed files, test counts/commands, evidence paths, limitations,
        unresolved decisions, and recommended next action.
```

## Definition of done

- All six accepted feature contracts implemented, or explicitly blocked/deferred
  by the parent with reasons; no silent omissions disguised as completion.
- Original 186-test behavior preserved; new unit/native tests demonstrate each
  feature and its failure/lifecycle/security boundaries.
- Required review findings resolved; shared files have a single clear owner.
- Additive APIs and compatibility restrictions documented with usable examples.
- Telemetry/checkpoints/reports do not expose credentials or default raw values.
- Disposable servers, tables, processes, files, and lane worktrees cleaned up only
  after durable handoffs and validation; nothing production-facing touched.
- Final report lists completed phases, changed files, exact test/server evidence,
  approved deferrals, residual limitations, and any next operator decision.
