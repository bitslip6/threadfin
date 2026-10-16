<?php

declare(strict_types=1);

namespace ThreadFin\DB;

require_once __DIR__ . '/db.php';

use Exception;
use mysqli_result;
use RuntimeException;
use function ThreadFin\Util\utc_time;

if (!defined('SQL_ERROR_FILE')) {
    define('SQL_ERROR_FILE', '/tmp/php_sql_errors.log');
}

/** Opt-in operational DB: logging, simulation, replay, telemetry and dumps. */
class DBExtended extends DB
{
    public $logs = [];

    protected $_log_enabled = false;
    protected $_simulation = false;
    protected $_replay_enabled = false;
    protected $_replay_file = "";
    protected $_replay = [];
    protected string $_replay_header = '';
    protected array $_logged_errors = [];
    /** @var array<?ExecutionFailure> Failure latch per active helper, independent of public errors. */
    private array $_transaction_scopes = [];
    private int $_transaction_savepoint = 0;
    private bool $_transaction_control = false;
    private ?ExecutionFailure $_last_execution_failure = null;
    private array $_transaction_cleanup_errors = [];
    private array $_prepared_cleanup_errors = [];
    private array $_stream_cleanup_errors = [];
    private ?StreamingRows $_active_stream = null;
    private ?TelemetryObserver $_telemetry = null;
    private ?TelemetryGuard $_telemetry_guard = null;
    private ?TelemetryOperation $_telemetry_operation = null;
    /** One internal call may consume this marker before any application-capable work. */
    private ?string $_telemetry_delegate = null;
    /** Forced resource disconnect still owes the normal journal/error close lifecycle. */
    private bool $_resource_close_pending = false;

    /**
     * Optional privacy-safe immutable QueryEvent observer. No SQL/value/sample mode.
     * Options: sample_rate [0,1], slow_threshold_ms [0,86400000], hmac_key >=16 bytes.
     * Defaults: all operations, no threshold, fresh random HMAC key per installation.
     * Identifiers hash exact input templates, not normalized SQL shapes. Raw builders
     * have only rendered SQL available. No changes to legacy logs/replay redaction.
     * Sampling occurs once; rate zero performs no timing/hashing/event allocation.
     * Timing begins at the selected execution seam (including local validation and
     * fetch/stream template rendering, but excluding builders before that seam) and
     * ends after result setup/cleanup. HMAC/sampling and terminal dispatch are excluded.
     * Stream duration includes consumer delays and cancellation drain; startup/first-row
     * are separate. Start callback time is consequently part of the terminal duration.
     * Threshold zero emits stream start+terminal; positive thresholds terminal-only.
     * Observer errors are swallowed into saturating scalar counters. Same-DB recursive
     * operations/configuration and retained stream mutation reject without poisoning
     * transactions. Close an active stream before replacing/disabling observation.
     * Callbacks run after scoped driver warning handlers are restored. Retained
     * simulated streams finish through their originating observer even after disable
     * or replacement; the shared recursion guard remains effective across installations.
     */
    public function observe(?callable $observer, array $options = []): DB
    {
        $this->_telemetry_guard?->guard();
        if ($this->_active_stream !== null) {
            throw new \LogicException('Cannot replace observer with an open stream');
        }
        if ($observer === null) {
            if ($options !== []) {
                throw new \InvalidArgumentException('Disabled observation takes no options');
            }
            $this->_telemetry = null;
        } else {
            require_once __DIR__ . '/db_telemetry.php';
            $guard = $this->_telemetry_guard ?? new TelemetryGuard();
            $this->_telemetry = new TelemetryObserver($observer, $options, $guard);
            $this->_telemetry_guard = $guard;
        }
        return $this;
    }

    /** Counters for the current installation, reset on replace/disable; no event history. */
    public function telemetry_diagnostics(): array
    {
        return $this->_telemetry?->diagnostics() ?? ['observer_failures' => 0, 'recursive_rejections' => 0];
    }

    /** Call-scoped context: internal re-entry consumes a single delegation marker.
     * Application calls made later (e.g. Stringable rendering) start independent
     * operations, even when unsampled or disabled, then restore the enclosing context.
     * self:: re-entry avoids invoking a subclass override a second time.
     * Never-observed ordinary execution allocates neither contexts nor closures.
     */
    private function observe_operation(string $kind, string $template, string $delegate, callable $execute): mixed
    {
        $observer = $this->_telemetry;
        $operation = $observer?->begin($kind, $template, $this->_simulation);
        $previousOperation = $this->_telemetry_operation;
        $previousDelegate = $this->_telemetry_delegate;
        $this->_telemetry_delegate = $delegate;
        $this->_telemetry_operation = $operation;
        try {
            $result = $execute();
        } catch (\Throwable $error) {
            // Restore the caller's context before dispatch; observer recursion has its
            // own stable guard and must not be confused with application nesting.
            $this->_telemetry_delegate = $previousDelegate;
            $this->_telemetry_operation = $previousOperation;
            $operation?->finish('failure', $error);
            throw $error;
        } finally {
            $this->_telemetry_delegate = $previousDelegate;
            $this->_telemetry_operation = $previousOperation;
        }
        if ($result instanceof StreamingRows) {
            if ($observer !== null) {
                $result->attach_observer($observer, $operation);
            }
            $operation?->stream_start();
        } else {
            $operation?->finish();
        }
        return $result;
    }

    public function __destruct()
    {
        if ($this->_db || $this->_resource_close_pending) {
            $this->close();
        }
    }

    /** Frozen-source, integer-primary-key export; see db_dump.php for limits/recovery. */
    public function dump_resumable(string $output_path, string $checkpoint_path, array $options = []): DumpReport
    {
        $this->guard_dump_read();
        require_once __DIR__ . '/db_dump.php';
        // Defensive against custom fetch overrides changing lifecycle mid-operation.
        // Ordinary observer callbacks already reject same-DB lifecycle mutations.
        $read = function (string $sql, array $parameters): SQL {
            $this->guard_dump_read();
            $result = $this->fetch_prepared($sql, $parameters);
            try {
                $this->guard_dump_read();
            } catch (\Throwable $error) {
                $result->close();
                throw $error;
            }
            return $result;
        };
        return ResumableDump::run($read, $output_path, $checkpoint_path, $options);
    }

    private function guard_dump_read(): void
    {
        $this->_telemetry_guard?->guard();
        $this->reject_transaction_change();
        if ($this->_active_stream !== null) {
            $this->reject_busy_stream();
        }
        if ($this->_simulation || !$this->connected()) {
            throw new \LogicException('Resumable dumps require a real connected frozen source');
        }
    }

    /**
     * @param bool $enable - enable or disable logging
     * @return DB 
     */
    public function enable_log(bool $enable): DB
    {
        $this->_telemetry_guard?->guard();
        $this->_log_enabled = $enable;
        return $this;
    }

    /**
     * @param string $replay_file_name - the name of the replay log to record to
     * @return DB 
     */
    public function enable_replay(string $replay_file_name): DB
    {
        $this->_telemetry_guard?->guard();
        if ($this->_active_stream !== null) {
            $this->reject_busy_stream();
        }
        $this->reject_transaction_change();
        if ($this->_replay_enabled) {
            if ($replay_file_name !== $this->_replay_file) {
                throw new \LogicException('Cannot change replay destination during a database session');
            }
            return $this;
        }
        if ($replay_file_name === '' || stripos($replay_file_name, '.php') !== false) {
            throw new \InvalidArgumentException('Replay requires a non-PHP journal path');
        }
        if (!$this->_db) {
            throw new RuntimeException('Replay requires a connected database');
        }
        // Simulation records intent only; leaving simulation initializes the real journal.
        if (!$this->_simulation) {
            $this->initialize_replay($replay_file_name);
        }
        $this->_replay_file = $replay_file_name;
        $this->_replay_enabled = true;
        return $this;
    }

    private function initialize_replay(string $replay_file_name): void
    {
        // Enable before application queries; capture the session defaults used by those queries.
        $result = mysqli_query($this->_db, 'SELECT @@SESSION.sql_mode AS sql_mode, @@SESSION.autocommit AS autocommit');
        if (!$result instanceof mysqli_result) {
            throw new RuntimeException('Unable to inspect replay session');
        }
        try {
            $session = $result->fetch_assoc();
        } finally {
            $result->free();
        }
        if (!isset($session['sql_mode'], $session['autocommit']) || !in_array((string)$session['autocommit'], ['0', '1'], true)) {
            throw new RuntimeException('Invalid replay session settings');
        }
        $fp = @fopen($replay_file_name, 'c+b');
        if ($fp === false) {
            throw new RuntimeException('Unable to create replay log: ' . $replay_file_name);
        }
        fclose($fp);
        $this->_replay_header = "\n-- ThreadFin replay session\nROLLBACK;\nSET NAMES utf8mb4;\n"
            . 'SET SESSION sql_mode = ' . quote($session['sql_mode']) . ";\n"
            . 'SET SESSION autocommit = ' . $session['autocommit'] . ";\n";
    }


    /**
     * @param bool $enable - enable or disable simulation (no queries, just the query log)
     * @return DB 
     */
    public function enable_simulation(bool $enable): DB
    {
        $this->_telemetry_guard?->guard();
        if ($this->_active_stream !== null) {
            $this->reject_busy_stream();
        }
        $this->reject_transaction_change();
        if (!$enable && $this->_db && $this->_replay_enabled && $this->_replay_header === '') {
            // Failure must leave simulation active rather than allow unjournaled execution.
            $this->initialize_replay($this->_replay_file);
        }
        $this->_simulation = $enable;
        $this->enable_log(true);
        return $this;
    }


    /**
     * Own an idle autocommit transaction, or nest with a savepoint. No retries.
     * Only transactional-table DML/read SQL is supported: implicit-commit SQL,
     * raw controls and session changes are rejected, including during simulation.
     * Raw handles must not be used concurrently. Functions/triggers and nontransactional
     * side effects remain caller responsibility. Flush bulk closures before returning.
     * Simulation validates/generates only; it does not inspect or control the session.
     * Caught pure builder validation can be repaired; actual execution/guard failures
     * latch the scope. Ambiguous commit acknowledgements are failures, never retried.
     */
    public function transaction(callable $callback): mixed
    {
        $this->_telemetry_guard?->guard();
        if ($this->_active_stream !== null) {
            $this->reject_busy_stream();
        }
        $outer = $this->_transaction_scopes === [];
        if (!$outer) {
            $this->assert_transaction_usable();
        }
        $index = count($this->_transaction_scopes);
        $this->_transaction_scopes[] = null;
        $savepoint = $outer ? '' : 'threadfin_sp_' . ++$this->_transaction_savepoint;
        $owned = false;
        try {
            if (!$this->_simulation) {
                if (!$this->_db) {
                    throw $this->record_execution_failure('Transaction requires a connection');
                }
                if ($outer) {
                    $this->_transaction_control = true;
                    try {
                        $session = $this->_qr('SELECT @@SESSION.autocommit AS autocommit');
                    } finally {
                        $this->_transaction_control = false;
                    }
                    $this->assert_transaction_usable();
                    try {
                        $rows = $session->as_array();
                    } finally {
                        $session->close();
                    }
                    if (count($rows) !== 1 || (string)($rows[0]['autocommit'] ?? '') !== '1') {
                        throw $this->record_execution_failure('Transaction requires idle autocommit ownership');
                    }
                    // MySQL and MariaDB reject this without committing an existing transaction.
                    $this->transaction_control('SET TRANSACTION READ WRITE');
                    $this->transaction_control('START TRANSACTION');
                } else {
                    $this->transaction_control('SAVEPOINT ' . $savepoint);
                }
            }
            $owned = true;
            $value = $callback($this);
            if ($this->_active_stream !== null) {
                $failure = $this->record_execution_failure('Transaction callback left a stream open');
                try {
                    $this->_active_stream->close();
                } catch (\Throwable $cleanup) {
                }
                throw $failure;
            }
            $this->assert_transaction_usable();
            if (!$this->_simulation) {
                // Session completion_type must neither chain a new transaction nor release our connection.
                $this->transaction_control($outer ? 'COMMIT AND NO CHAIN NO RELEASE' : 'RELEASE SAVEPOINT ' . $savepoint);
            }
            return $value;
        } catch (\Throwable $original) {
            if ($owned && $this->_active_stream !== null) {
                try {
                    $this->_active_stream->close();
                } catch (\Throwable $cleanup) {
                }
            }
            if ($owned && !$this->_simulation) {
                // A server-wide abort destroys savepoints; only the outer owner can clean up.
                $invalid = $this->_transaction_scopes[$index]?->transactionInvalidated ?? false;
                if ($outer || !$invalid) {
                    try {
                        $this->transaction_control($outer ? 'ROLLBACK AND NO CHAIN NO RELEASE' : 'ROLLBACK TO SAVEPOINT ' . $savepoint);
                        if (!$outer) {
                            $this->transaction_control('RELEASE SAVEPOINT ' . $savepoint);
                        }
                    } catch (ExecutionFailure $cleanup) {
                        $this->_transaction_cleanup_errors[] = $cleanup;
                        if (count($this->_transaction_cleanup_errors) > 16) {
                            array_shift($this->_transaction_cleanup_errors);
                        }
                    }
                }
            }
            throw $original;
        } finally {
            array_pop($this->_transaction_scopes);
            if ($outer) {
                $this->_last_execution_failure = null;
            }
        }
    }

    /** Bounded safe cleanup failures; never replaces a callback's original throwable. */
    public function transaction_cleanup_errors(): array
    {
        return $this->_transaction_cleanup_errors;
    }

    /** Shared failure seam for native/prepared/stream execution; never infer failure from public sentinels. */
    private function record_execution_failure(string $message, int $errno = 0, string $sqlstate = 'HY000', bool $invalidate = false): ExecutionFailure
    {
        $invalidate = $invalidate || in_array($errno, [1205, 1213, 2006, 2013, 2055], true)
            || $sqlstate === '40001' || str_starts_with($sqlstate, '08');
        $failure = new ExecutionFailure($message, $errno, $sqlstate, $invalidate);
        $this->_last_execution_failure = $failure;
        if ($invalidate) {
            // Upgrade an earlier local failure: a later lost savepoint/control invalidates every scope.
            foreach ($this->_transaction_scopes as $index => $prior) {
                $this->_transaction_scopes[$index] = $failure;
            }
        } elseif ($this->_transaction_scopes !== []) {
            $index = count($this->_transaction_scopes) - 1;
            $this->_transaction_scopes[$index] ??= $failure;
        }
        return $failure;
    }

    private function record_driver_failure(?\Throwable $exception): void
    {
        $errno = $exception !== null && $exception->getCode() !== 0 ? (int)$exception->getCode() : mysqli_errno($this->_db);
        // mysqli_sql_exception::getSqlState() was added in PHP 8.1.2.
        $sqlstate = $exception instanceof \mysqli_sql_exception && method_exists($exception, 'getSqlState')
            ? $exception->getSqlState() : mysqli_sqlstate($this->_db);
        $this->record_execution_failure('Database operation failed', $errno, $sqlstate, $this->_transaction_control);
    }

    private function assert_transaction_usable(): void
    {
        foreach ($this->_transaction_scopes as $failure) {
            if ($failure !== null) {
                throw $failure;
            }
        }
    }

    private function transaction_control(string $sql): void
    {
        $this->_transaction_control = true;
        try {
            if ($this->_qb($sql) !== 1) {
                throw $this->_last_execution_failure;
            }
        } catch (\Throwable $error) {
            if ($error instanceof ExecutionFailure) {
                throw $error;
            }
            throw $this->record_execution_failure('Transaction control failed', (int)$error->getCode(), 'HY000', true);
        } finally {
            $this->_transaction_control = false;
        }
    }

    protected function reject_transaction_change(): void
    {
        if ($this->_transaction_scopes !== []) {
            throw $this->record_execution_failure('Cannot change database lifecycle inside a transaction callback');
        }
    }

    /** Conservative eligibility lexer, not SQL validation; only runs in opted-in helper scopes. */
    private function guard_transaction_sql(string $sql): void
    {
        $this->assert_transaction_usable();
        $length = strlen($sql);
        $plain = '';
        for ($i = 0; $i < $length;) {
            $c = $sql[$i];
            if ($c === "'" || $c === '"' || $c === '`') {
                $quote = $c;
                $plain .= ' ? ';
                $closed = false;
                for ($i++; $i < $length; $i++) {
                    if ($sql[$i] === '\\') {
                        // ANSI_QUOTES affects double quotes; reject ambiguous escaping in identifiers.
                        if ($quote !== "'") {
                            break;
                        }
                        $i++;
                    } elseif ($sql[$i] === $quote) {
                        if ($i + 1 < $length && $sql[$i + 1] === $quote) {
                            $i++;
                        } else {
                            $i++;
                            $closed = true;
                            break;
                        }
                    }
                }
                if (!$closed) {
                    throw $this->record_execution_failure('Unsupported transaction SQL quoting');
                }
            } elseif ($c === '#' || ($c === '-' && substr($sql, $i, 2) === '--' && ($i + 2 === $length || ord($sql[$i + 2]) <= 32))) {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $length : $end + 1;
                $plain .= ' ';
            } elseif (substr($sql, $i, 2) === '/*') {
                if (substr($sql, $i, 3) === '/*!' || strcasecmp(substr($sql, $i, 4), '/*M!') === 0) {
                    throw $this->record_execution_failure('Executable comments are unsupported in transaction callbacks');
                }
                $end = strpos($sql, '*/', $i + 2);
                if ($end === false) {
                    throw $this->record_execution_failure('Unterminated transaction SQL comment');
                }
                $i = $end + 2;
                $plain .= ' ';
            } else {
                $plain .= $c;
                $i++;
            }
        }
        $plain = trim($plain);
        if (str_ends_with($plain, ';')) {
            $plain = rtrim(substr($plain, 0, -1));
        }
        if (
            str_contains($plain, ';') || str_contains($plain, "\0")
            || !preg_match('/\A(SELECT|INSERT|UPDATE|DELETE|REPLACE|SHOW|DESCRIBE|EXPLAIN\s+SELECT)\b/i', $plain)
        ) {
            throw $this->record_execution_failure('Only single DML/read statements are supported in transaction callbacks');
        }
    }

    /**
     * run SQL $sql return result as bool. errors stored tail($this->errors)
     * @return int -1 on error, 0 if no rows affected / no insert id, >0 if rows affected / insert id, 1 = success
     */
    protected function _qb(string $sql, int $return_type = DB_FETCH_SUCCESS): int
    {
        if ($this->_telemetry_guard !== null) {
            $this->_telemetry_guard->guard();
            if ($this->_telemetry_delegate === __FUNCTION__) {
                $this->_telemetry_delegate = null;
            } elseif ($this->_telemetry !== null || $this->_telemetry_operation !== null) {
                return $this->observe_operation($this->_transaction_control ? 'transaction_control' : 'raw', $sql, __FUNCTION__, fn() => self::_qb($sql, $return_type));
            }
        }
        if ($this->_active_stream !== null) {
            $this->reject_busy_stream();
        }
        assert(!empty($this->_db), "database: {$this->database} is not connected");

        if ($this->_transaction_scopes !== [] && !$this->_transaction_control) {
            $this->guard_transaction_sql($sql);
        }
        $this->last_stmt = "$sql\n";
        if ($this->_simulation) {
            if ($this->_log_enabled) {
                $this->logs[] = "# [$sql] simulated (not executed)";
            }
            // No driver counts/IDs exist for a statement that was not executed.
            return ($return_type === DB_FETCH_NUM_ROWS || $return_type === DB_FETCH_INSERT_ID) ? 0 : 1;
        }
        $r = false;
        $affected = -1;
        $errno = 0;
        try {
            $r = mysqli_query($this->_db, $sql);
        }
        // silently swallow exceptions, will catch them in next line
        catch (Exception $ex) {
            $r = false;
        }
        if ($r === false) {
            if ($this->_transaction_scopes !== []) {
                $this->record_driver_failure($ex ?? null);
            }
            $errno = mysqli_errno($this->_db);
            $this->_telemetry_operation?->fail($errno, mysqli_sqlstate($this->_db));
            $err = "[$sql] errno($errno) " . mysqli_error($this->_db);
            $this->errors[] = $err;
            return -1;
        } else {
            if ($this->_replay_enabled || $this->_log_enabled || $return_type == DB_FETCH_NUM_ROWS) {
                $affected = mysqli_affected_rows($this->_db);
                if ($this->_log_enabled) {
                    $msg = "# [$sql] errno($errno) affected rows($affected)";
                    $this->logs[] = $msg;
                }
                // Successful DDL, no-op writes, and transaction controls also belong in replay.
                if ($this->_replay_enabled) {
                    $this->_replay[] = $sql;
                }
            }
            if ($this->_telemetry_operation !== null) {
                $this->_telemetry_operation->counts(null, (int)mysqli_affected_rows($this->_db));
            }
            if ($return_type == DB_FETCH_NUM_ROWS) {
                return intval($affected);
            } else if ($return_type == DB_FETCH_INSERT_ID) {
                $id = intval(mysqli_insert_id($this->_db));
                return ($id == 0) ? -1 : $id;
            }
            return 1;
        }
    }

    /**
     * run SQL $sql return result as bool. errors stored tail($this->errors)
     * convert $sql to SQL result
     */
    protected function _qr(string $sql, $mode = MYSQLI_ASSOC): SQL
    {
        if ($this->_telemetry_guard !== null) {
            $this->_telemetry_guard->guard();
            if ($this->_telemetry_delegate === __FUNCTION__) {
                $this->_telemetry_delegate = null;
            } elseif ($this->_telemetry !== null || $this->_telemetry_operation !== null) {
                return $this->observe_operation($this->_transaction_control ? 'transaction_control' : 'fetch', $sql, __FUNCTION__, fn() => self::_qr($sql, $mode));
            }
        }
        if ($this->_active_stream !== null) {
            $this->reject_busy_stream();
        }
        if ($this->_transaction_scopes !== [] && !$this->_transaction_control) {
            $this->guard_transaction_sql($sql);
        }
        assert(! empty($this->_db), "database: {$this->database} is not connected");
        $this->_telemetry_operation?->execution_mode($this->_simulation);
        if ($this->_simulation) {
            if ($this->_log_enabled) {
                $this->logs[] = "# [$sql] simulated (not executed)";
            }
            return SQL::from(null, $sql);
        }
        $r = false;
        $errno = 0;
        try {
            $r = mysqli_query($this->_db, $sql);
        }
        // silently swallow exceptions, will catch them in next line
        catch (Exception $ex) {
            $r = false;
        }
        if ($r == false || !$r instanceof mysqli_result) {
            if ($this->_transaction_scopes !== []) {
                $this->record_driver_failure($ex ?? null);
            }
            $errno = mysqli_errno($this->_db);
            $this->_telemetry_operation?->fail($errno, mysqli_sqlstate($this->_db));
            $err = "[$sql] errno($errno) " . mysqli_error($this->_db);
            $this->errors[] = $err;
            return SQL::from(NULL, $sql);
        } else {
            if ($this->_log_enabled) {
                $e = mysqli_affected_rows($this->_db);
                $msg = "# [$sql] errno($errno) selected rows($e)";
                $this->logs[] = $msg;
            }
        }

        $this->_telemetry_operation?->counts((int)$r->num_rows, null);
        return SQL::fetch($r, $sql);
    }

    protected function reject_busy_stream(): never
    {
        throw $this->record_execution_failure('Connection is busy with an open stream');
    }

    /** Last 16 safe stream cleanup failures; exception traces are not a safe export. */
    public function stream_cleanup_errors(): array
    {
        return $this->_stream_cleanup_errors;
    }

    /**
     * Caller-trusted read-only SQL using legacy {name}/{!name} templates, not ? binding.
     * Unbuffered rows, excluded from replay. Always close in finally, including after
     * foreach break. The same connection rejects competing operations until close/EOF.
     */
    public function stream(string $sql, $data = null): StreamingRows
    {
        if ($this->_telemetry_guard !== null) {
            $this->_telemetry_guard->guard();
            if ($this->_telemetry_delegate === __FUNCTION__) {
                $this->_telemetry_delegate = null;
            } elseif ($this->_telemetry !== null || $this->_telemetry_operation !== null) {
                return $this->observe_operation('stream', $sql, __FUNCTION__, fn() => self::stream($sql, $data));
            }
        }
        if ($this->_active_stream !== null) {
            $this->reject_busy_stream();
        }
        require_once __DIR__ . '/db_stream.php';
        $sql = $this->fetch_to_statement($sql, $data);
        // A Stringable template value may have opened and retained its own stream.
        if ($this->_active_stream !== null) {
            $this->reject_busy_stream();
        }
        if ($this->_transaction_scopes !== []) {
            $this->guard_transaction_sql($sql);
        }
        $this->_telemetry_operation?->execution_mode($this->_simulation);
        if ($this->_simulation) {
            if ($this->_log_enabled) {
                $this->logs[] = "# [$sql] simulated (not executed)";
            }
            return new StreamingRows($sql, null, null);
        }
        if (!$this->_db) {
            throw $this->record_execution_failure('Stream requires a connection');
        }
        set_error_handler(static fn() => true, E_WARNING);
        try {
            $result = mysqli_query($this->_db, $sql, MYSQLI_USE_RESULT);
            if (!$result instanceof mysqli_result) {
                throw $this->stream_driver_failure(null);
            }
            $columns = [];
            return $this->own_stream($sql, $result, null, null, [], $columns);
        } catch (\Throwable $error) {
            throw $error instanceof ExecutionFailure ? $error : $this->stream_driver_failure($error);
        } finally {
            restore_error_handler();
        }
    }

    /** Native positional parameters; same validation as fetch_prepared, never buffers rows. */
    public function stream_prepared(string $sql, array $parameters = []): StreamingRows
    {
        $this->_telemetry_guard?->guard();
        require_once __DIR__ . '/db_stream.php';
        return $this->run_prepared($sql, $parameters, true, DB_FETCH_SUCCESS, true);
    }

    private function stream_driver_failure(?\Throwable $error): ExecutionFailure
    {
        $errno = $error !== null && $error->getCode() !== 0 ? (int)$error->getCode() : mysqli_errno($this->_db);
        $state = $error instanceof \mysqli_sql_exception && method_exists($error, 'getSqlState')
            ? $error->getSqlState() : mysqli_sqlstate($this->_db);
        return $this->record_execution_failure('Streaming operation failed', $errno, $state);
    }

    /** The DB owns the stream; weak callbacks avoid retaining DB or creating a cycle. */
    private function own_stream(
        string $sql,
        ?mysqli_result $result,
        ?\mysqli_stmt $statement,
        ?mysqli_result $metadata,
        array $fields,
        array &$columns
    ): StreamingRows {
        $owner = \WeakReference::create($this);
        $fetch = static function () use ($owner, $result, $statement, $fields, &$columns): ?array {
            $db = $owner->get();
            if ($db === null) {
                return null;
            }
            set_error_handler(static fn() => true, E_WARNING);
            try {
                if ($statement !== null) {
                    $fetched = $statement->fetch();
                    if ($fetched === false) {
                        throw $db->prepared_driver_failure($statement, null);
                    }
                    if ($fetched === null) {
                        return null;
                    }
                    $row = [];
                    foreach ($fields as $index => $field) {
                        $row[$field->name] = $columns[$index];
                    }
                    return $row;
                }
                $row = $result->fetch_assoc();
                if ($row === false || ($row === null && mysqli_errno($db->_db) !== 0)) {
                    throw $db->stream_driver_failure(null);
                }
                return $row;
            } catch (\Throwable $error) {
                throw $error instanceof ExecutionFailure ? $error : ($statement !== null
                    ? $db->prepared_driver_failure($statement, $error) : $db->stream_driver_failure($error));
            } finally {
                restore_error_handler();
            }
        };
        $cleanup = static function () use ($owner, $result, $statement, $metadata): void {
            $db = $owner->get();
            if ($db === null) {
                return;
            } // DB destructor closes while it still owns these resources.
            $warning = false;
            set_error_handler(static function () use (&$warning): bool {
                $warning = true;
                return true;
            }, E_WARNING);
            try {
                if ($statement !== null) {
                    $failure = $db->cleanup_prepared($statement, $metadata, true, $warning, true);
                } else {
                    try {
                        $result->free();
                    } catch (\Throwable $error) {
                        $warning = true;
                    }
                    $failure = null;
                    if ($warning) {
                        $failure = $db->record_execution_failure('Stream resource cleanup failed', 0, 'HY000', true);
                        $db->_stream_cleanup_errors[] = $failure;
                        if (count($db->_stream_cleanup_errors) > 16) {
                            array_shift($db->_stream_cleanup_errors);
                        }
                        // Uncertain protocol state must not be reused.
                        try {
                            mysqli_close($db->_db);
                        } catch (\Throwable $error) {
                        } finally {
                            $db->_db = null;
                            $db->_resource_close_pending = true;
                        }
                    }
                }
                if ($failure !== null) {
                    throw $failure;
                }
            } finally {
                $db->_active_stream = null;
                restore_error_handler();
            }
        };
        return $this->_active_stream = new StreamingRows($sql, $fetch, $cleanup);
    }

    /** Last 16 prepared cleanup failures; export safe fields only, not exception traces. */
    public function prepared_cleanup_errors(): array
    {
        return $this->_prepared_cleanup_errors;
    }

    /**
     * Native positional binding; not the legacy {name} template syntax.
     * Text must be UTF-8; arbitrary bytes require BinaryParameter. All payloads
     * remain resident; binary is sent in 64KiB chunks, subject to server packet limits.
     * Local validation throws InvalidArgumentException before I/O; execution errors
     * throw value-free ExecutionFailure and latch active transaction callbacks.
     * Real execution is rejected before prepare whenever legacy replay is enabled.
     * Success/count/ID modes match unsafe_raw, including -1 for a successful zero ID.
     * A cleanup failure after execution does not prove a write failed: never blindly retry.
     */
    public function execute_prepared(string $sql, array $parameters = [], int $mode = DB_FETCH_SUCCESS): int
    {
        $this->_telemetry_guard?->guard();
        if (!in_array($mode, [DB_FETCH_SUCCESS, DB_FETCH_NUM_ROWS, DB_FETCH_INSERT_ID], true)) {
            throw new \InvalidArgumentException('Invalid prepared return mode');
        }
        return $this->run_prepared($sql, $parameters, false, $mode);
    }

    /**
     * Caller-trusted read-only SQL, excluded from replay like fetch(). A real rowset
     * is required; this is not a SQL read-only validator. Buffers with store_result
     * and independent row copies, without get_result or a mysqlnd requirement.
     * Returns ordinary SQL with the original template only, never parameter values.
     * Simulation validates local types only, not SQL syntax or placeholder counts.
     */
    public function fetch_prepared(string $sql, array $parameters = []): SQL
    {
        return $this->run_prepared($sql, $parameters, true, DB_FETCH_SUCCESS);
    }

    private function run_prepared(string $sql, array $parameters, bool $read, int $mode, bool $stream = false): SQL|int|StreamingRows
    {
        if ($this->_telemetry_guard !== null) {
            $this->_telemetry_guard->guard();
            if ($this->_telemetry_delegate === __FUNCTION__) {
                $this->_telemetry_delegate = null;
            } elseif ($this->_telemetry !== null || $this->_telemetry_operation !== null) {
                return $this->observe_operation($stream ? 'stream_prepared' : ($read ? 'prepared_fetch' : 'prepared_execute'), $sql, __FUNCTION__, fn() => self::run_prepared($sql, $parameters, $read, $mode, $stream));
            }
        }
        if ($this->_active_stream !== null) {
            $this->reject_busy_stream();
        }
        if (!array_is_list($parameters)) {
            throw new \InvalidArgumentException('Prepared parameters must be a positional list');
        }
        $types = '';
        $values = [];
        foreach ($parameters as $value) {
            if ($value instanceof BinaryParameter) {
                $types .= 'b';
                $values[] = '';
            } elseif ($value === null) {
                $types .= 's';
                $values[] = null;
            } elseif (is_bool($value) || is_int($value)) {
                $types .= 'i';
                $values[] = (int)$value;
            } elseif (is_float($value) && is_finite($value)) {
                $types .= 'd';
                $values[] = $value;
            } elseif (is_string($value) && preg_match('//u', $value) === 1) {
                $types .= 's';
                $values[] = $value;
            } else {
                throw new \InvalidArgumentException('Unsupported prepared parameter type or encoding');
            }
        }
        if ($this->_transaction_scopes !== []) {
            $this->guard_transaction_sql($sql);
        }
        if (!$this->_db) {
            throw $this->record_execution_failure('Prepared operation requires a connection');
        }
        if (!$read && !$this->_simulation && $this->_replay_enabled) {
            throw $this->record_execution_failure('Prepared execution is incompatible with legacy replay');
        }
        if (!$read) {
            $this->last_stmt = "$sql\n";
        }
        if ($this->_simulation) {
            if ($this->_log_enabled) {
                $this->logs[] = '# Prepared operation simulated (not executed)';
            }
            return $stream ? new StreamingRows($sql, null, null) : ($read ? SQL::from(null, $sql) : ($mode === DB_FETCH_SUCCESS ? 1 : 0));
        }

        $statement = null;
        $metadata = null;
        $primary = null;
        $warning = false;
        // Native warning-only mysqli reporting can expose bound values. Contain only
        // this synchronous prepared lifecycle; never forward warning text to callers.
        // Driver outcomes remain authoritative (advisory warnings can accompany success).
        set_error_handler(static function () use (&$warning): bool {
            $warning = true;
            return true;
        }, E_WARNING);
        try {
            $statement = mysqli_prepare($this->_db, $sql);
            if ($statement === false) {
                throw $this->prepared_driver_failure(null, null);
            }
            if ($statement->param_count !== count($parameters)) {
                throw $this->record_execution_failure('Prepared parameter count mismatch');
            }
            // Unpacked list elements stay referenced until execution completes.
            if ($values !== [] && !$statement->bind_param($types, ...$values)) {
                throw $this->prepared_driver_failure($statement, null);
            }
            foreach ($parameters as $index => $value) {
                if (!$value instanceof BinaryParameter) {
                    continue;
                }
                $length = strlen($value->bytes);
                // Even an empty binary value is explicitly sent, distinct from NULL.
                for ($offset = 0; $offset < $length || $offset === 0; $offset += 65536) {
                    if (!$statement->send_long_data($index, substr($value->bytes, $offset, 65536))) {
                        throw $this->prepared_driver_failure($statement, null);
                    }
                }
            }
            if (!$statement->execute()) {
                throw $this->prepared_driver_failure($statement, null);
            }
            if ($read) {
                if (!$stream && !$statement->store_result()) {
                    throw $this->prepared_driver_failure($statement, null);
                }
                $metadata = $statement->result_metadata();
                if ($metadata === false) {
                    if ($statement->errno !== 0) {
                        throw $this->prepared_driver_failure($statement, null);
                    }
                    throw $this->record_execution_failure('Prepared fetch requires a rowset');
                }
                $fields = $metadata->fetch_fields();
                $columns = array_fill(0, count($fields), null);
                if (!$statement->bind_result(...$columns)) {
                    throw $this->prepared_driver_failure($statement, null);
                }
                if ($stream) {
                    $result = $this->own_stream($sql, null, $statement, $metadata, $fields, $columns);
                    // Ownership transfers only after all startup work has succeeded.
                    $statement = $metadata = null;
                } else {
                    $rows = [];
                    while (($fetched = $statement->fetch()) === true) {
                        $row = [];
                        // Copy values, not bind_result references; duplicate labels match fetch_assoc.
                        foreach ($fields as $index => $field) {
                            $row[$field->name] = $columns[$index];
                        }
                        $rows[] = $row;
                    }
                    if ($fetched === false) {
                        throw $this->prepared_driver_failure($statement, null);
                    }
                    $result = SQL::from($rows, $sql);
                }
            } elseif ($mode === DB_FETCH_NUM_ROWS) {
                $result = (int)$statement->affected_rows;
            } elseif ($mode === DB_FETCH_INSERT_ID) {
                $result = (int)$statement->insert_id ?: -1;
            } else {
                $result = 1;
            }
            if ($this->_telemetry_operation !== null && !$stream) {
                $this->_telemetry_operation->counts($read ? count($rows) : null, $read ? null : (int)$statement->affected_rows);
            }
        } catch (\Throwable $error) {
            $primary = $error instanceof ExecutionFailure ? $error : $this->prepared_driver_failure($statement ?: null, $error);
        } finally {
            try {
                $cleanup = $this->cleanup_prepared($statement ?: null, $metadata ?: null, $read, $warning, $stream);
            } finally {
                restore_error_handler();
            }
        }
        if ($primary !== null) {
            throw $primary;
        }
        if ($cleanup !== null) {
            throw $cleanup;
        }
        if ($this->_log_enabled) {
            $this->logs[] = $stream ? '# Prepared stream opened' : '# Prepared operation completed';
        }
        return $result;
    }

    private function prepared_driver_failure(?\mysqli_stmt $statement, ?\Throwable $error): ExecutionFailure
    {
        $errno = $error !== null && $error->getCode() !== 0 ? (int)$error->getCode()
            : ($statement !== null ? $statement->errno : mysqli_errno($this->_db));
        // PHP 8.1.0/8.1.1 exceptions lack getSqlState(); statement diagnostics are authoritative.
        $state = $error instanceof \mysqli_sql_exception && method_exists($error, 'getSqlState')
            ? $error->getSqlState() : ($statement !== null ? $statement->sqlstate : mysqli_sqlstate($this->_db));
        return $this->record_execution_failure('Prepared operation failed', $errno, $state);
    }

    /** Always attempt all cleanup; preserve the primary failure, never expose driver text. */
    private function cleanup_prepared(?\mysqli_stmt $statement, ?mysqli_result $metadata, bool $read, bool &$warning, bool $stream = false): ?ExecutionFailure
    {
        $failure = null;
        $record = function () use (&$failure, $stream): void {
            $error = $this->record_execution_failure(($stream ? 'Stream' : 'Prepared') . ' resource cleanup failed; execution may have completed', 0, 'HY000', true);
            $failure ??= $error;
            if ($stream) {
                $this->_stream_cleanup_errors[] = $error;
                if (count($this->_stream_cleanup_errors) > 16) {
                    array_shift($this->_stream_cleanup_errors);
                }
            } else {
                $this->_prepared_cleanup_errors[] = $error;
                if (count($this->_prepared_cleanup_errors) > 16) {
                    array_shift($this->_prepared_cleanup_errors);
                }
            }
        };
        // Void cleanup methods have no false return: a warning is their failure signal.
        // Reset the scalar flag per action, so execution advisories cannot poison cleanup.
        if ($metadata !== null) {
            $warning = false;
            try {
                $metadata->free();
            } catch (\Throwable $error) {
                $warning = true;
            }
            if ($warning) {
                $record();
            }
        }
        if ($statement !== null) {
            if ($read) {
                $warning = false;
                try {
                    $statement->free_result();
                } catch (\Throwable $error) {
                    $warning = true;
                }
                if ($warning) {
                    $record();
                }
            }
            $warning = false;
            try {
                if (!$statement->close()) {
                    $warning = true;
                }
            } catch (\Throwable $error) {
                $warning = true;
            }
            if ($warning) {
                $record();
                // Unknown statement/protocol state must not be reused, even outside a helper.
                $warning = false;
                try {
                    if (!mysqli_close($this->_db)) {
                        $warning = true;
                    }
                } catch (\Throwable $closeError) {
                    $warning = true;
                } finally {
                    $this->_db = null;
                    $this->_resource_close_pending = true;
                }
                if ($warning) {
                    $record();
                }
            }
        }
        return $failure;
    }

    /**
     * build sql replacing {name} with values from $data[name] = value
     * auto quote values,  use {!name} to not quote the value
     * 
     * $sql = $db->fetch("SELECT * FROM my_table WHERE id = {id} AND name = {name}", ['id' => 1, 'name' => 'bob']);
     * 
     * @see SQL
     * @param string $sql - SELECT QUERY FROM your_table WHERE id = {id} 
     * @param null|array|object $data - named values or initialized readable object fields
     * @return SQL - SQL result abstraction
     * @throws \InvalidArgumentException for unsupported containers or missing template parameters
     */
    public function fetch(string $sql, $data = NULL, $mode = MYSQLI_ASSOC): SQL
    {
        if ($this->_telemetry_guard !== null) {
            $this->_telemetry_guard->guard();
            if ($this->_telemetry_delegate === __FUNCTION__) {
                $this->_telemetry_delegate = null;
                $observedFetch = true;
            } elseif ($this->_telemetry !== null || $this->_telemetry_operation !== null) {
                return $this->observe_operation('fetch', $sql, __FUNCTION__, fn() => self::fetch($sql, $data, $mode));
            }
        }
        $new_sql = $this->fetch_to_statement($sql, $data, $mode);

        // runtime errors
        if ($this->_db == NULL) {
            $this->_telemetry_operation?->fail();
            return SQL::from(NULL, $new_sql);
        }
        if (isset($observedFetch)) {
            // This exact call is the buffered execution part of this fetch, not a
            // nested application operation. Rendering (and Stringable calls) is over.
            $previousDelegate = $this->_telemetry_delegate;
            $this->_telemetry_delegate = '_qr';
            try {
                return $this->_qr($new_sql, $mode);
            } finally {
                $this->_telemetry_delegate = $previousDelegate;
            }
        }
        return $this->_qr($new_sql, $mode);
    }

    /**
     * close the database handle and write the transaction log if we have one
     * @return void 
     */
    public function close(): void
    {
        $this->_telemetry_guard?->guard();
        $this->reject_transaction_change();
        $streamFailure = null;
        if ($this->_active_stream !== null) {
            try {
                $this->_active_stream->close();
            } catch (ExecutionFailure $error) {
                $streamFailure = $error;
            }
        }
        if (!empty($this->_db)) {
            mysqli_close($this->_db);
            $this->_db = NULL;
        }
        if (SQL_ERROR_FILE) {
            $pending = array_filter($this->errors, function ($error, $key): bool {
                return !array_key_exists($key, $this->_logged_errors) || $this->_logged_errors[$key] !== $error;
            }, ARRAY_FILTER_USE_BOTH);
            $errors = array_filter($pending, function ($error): bool {
                // Generated query diagnostics carry errno; SQL text may itself say Duplicate.
                if (preg_match('/\A\[.*\] errno\((\d+)\) /s', $error, $match) === 1) {
                    return $match[1] !== '1062';
                }
                // Retain the legacy keyword policy for diagnostics without a query errno.
                return stripos($error, 'Duplicate') === false;
            });
            $complete = true;
            if ($errors !== []) {
                $output = print_r($errors, true);
                $complete = file_put_contents(SQL_ERROR_FILE, $output, FILE_APPEND) === strlen($output);
            }
            // Keep the public list intact; only completed appends advance its logged snapshot.
            if ($complete) {
                $this->_logged_errors = $this->errors;
            }
        }
        if ($this->_replay !== []) {
            $this->flush_replay();
        }
        if (!SQL_ERROR_FILE || $complete) {
            $this->_resource_close_pending = false;
        }
        if ($streamFailure !== null) {
            throw $streamFailure;
        }
    }

    /** Append one isolated session; recover a failed append while still holding the lock. */
    private function flush_replay(): void
    {
        $fp = @fopen($this->_replay_file, 'c+b');
        if ($fp === false) {
            throw new RuntimeException('Unable to open replay log: ' . $this->_replay_file);
        }
        $locked = false;
        try {
            $locked = flock($fp, LOCK_EX);
            if (!$locked) {
                throw new RuntimeException('Unable to lock replay log: ' . $this->_replay_file);
            }
            if (fseek($fp, 0, SEEK_END) !== 0 || ($checkpoint = ftell($fp)) === false) {
                throw new RuntimeException('Replay log must support seek and truncate');
            }
            // Put delimiters on their own lines so trailing SQL comments cannot swallow them.
            $chunk = $this->_replay_header . implode("\n;\n", $this->_replay)
                . "\n;\nROLLBACK;\nSET SESSION autocommit = 1;\n-- End ThreadFin replay session\n";
            try {
                if (stream_output_fn($chunk, $fp) < 0 || !fflush($fp)) {
                    throw new RuntimeException('Unable to write replay log: ' . $this->_replay_file);
                }
            } catch (\Throwable $error) {
                // No concurrent writer can observe/reuse our checkpoint until recovery finishes.
                if (!@ftruncate($fp, $checkpoint) || !@fflush($fp)) {
                    throw new RuntimeException('Replay recovery failed; repair journal before retrying', 0, $error);
                }
                throw $error;
            }
            $this->_replay = [];
        } finally {
            if ($locked) {
                flock($fp, LOCK_UN);
            }
            fclose($fp);
        }
    }
}



/**
 * database backup checkpoint offset
 * @package ThreadFinDB
 */
class Offset
{
    public $table;
    public $limit_sz = 0;
    public $offset = 0;
    const TABLE_COMPLETE = -1;

    public function __construct(string $table, int $limit_sz = 300)
    {
        $this->limit_sz = $limit_sz;
        $this->table = $table;
    }

    /**
     * update a table saved offset
     * @param string $table 
     * @param int $offset 
     * @param int $limit 
     * @return void 
     */
    public function set_check_point(int $offset)
    {
        $this->offset = $offset;
    }

    /**
     * @param string $table 
     * @return bool true if the table is completely dumped, false if not or incomplete
     */
    public function is_table_complete(): bool
    {
        return $this->offset == Offset::TABLE_COMPLETE;
    }
}

/**
 * function suitable for database dumping to gz compressed output file
 * this is equal to calling stream_output_fn($data, $stream, "gzwrite")
 * @param string $data 
 * @param mixed $stream 
 * @return int -1 on error, else total byte length written to stream across all writes
 */
function gz_output_fn(?string $data, $stream): int
{
    return stream_output_fn($data, $stream, "gzwrite");
}

/**
 * Write a complete dump chunk, retrying positive short writes.
 * @param ?string $data null/empty chunks query the current total without writing
 * @param mixed $stream an open stream resource
 * @param callable $fn writer returning an integer byte count or false
 * @return int -1 on failed/invalid progress, else cumulative bytes for this handle
 *             (successful prefixes remain counted if a later write fails)
 */
function stream_output_fn(?string $data, $stream, $fn = "fwrite"): int
{
    assert(is_resource($stream), "stream must be a resource");
    // Resource IDs separate handles without retaining resources and preventing closure.
    static $total_bytes = [];
    $id = get_resource_id($stream);
    $total_bytes[$id] ??= 0;
    $length = strlen($data ?? '');
    $offset = 0;
    while ($offset < $length) {
        $bytes = $fn($stream, substr($data, $offset));
        if (!is_int($bytes) || $bytes <= 0 || $bytes > $length - $offset) {
            return -1;
        }
        $offset += $bytes;
        $total_bytes[$id] += $bytes;
    }
    return $total_bytes[$id];
}


/** Opt-in frozen-source file/checkpoint export. See db_dump.php for v1 restrictions. */
function dump_resumable(DB $db, string $output_path, string $checkpoint_path, array $options = []): DumpReport
{
    return $db->dump_resumable($output_path, $checkpoint_path, $options);
}

/**
 * dump a single SQL table 300 rows at a time
 * @param DB $db
 * @param callable $write_fn writer returning a negative value on failure
 * @param array $row SHOW TABLES row
 * @return Offset checkpoint after the last fully written row batch
 */
function dump_table(DB $db, callable $write_fn, array $row): ?Offset
{
    $idx = 0;
    $limit = 300;
    $db_name = $db->database;
    $table = $row["Tables_in_$db_name"];
    $offset = new Offset($table);

    // find number of rows
    $num_rows = intval($db->fetch("SELECT count(*) as count FROM $table")->col("count")());
    // the create statement
    $create = $db->fetch("SHOW CREATE TABLE $table");
    // Stop immediately if a header/DDL chunk cannot be written.
    if (
        $write_fn("# Export of $table\n# $num_rows rows in $table\n") < 0
        || $write_fn("DROP TABLE IF EXISTS `$table`;\n") < 0
        || $write_fn($create->col("Create Table")() . ";\n\n") < 0
    ) {
        return $offset;
    }

    // insert $limit rows at a time
    while ($idx < $num_rows) {
        $limit = min($limit, $num_rows - $idx);
        $rows = $db->fetch("SELECT * FROM $table LIMIT $limit OFFSET $idx", NULL, MYSQLI_NUM);
        // create the output string
        $result = $rows->reduce(function (string $carry, array $row) {
            return $carry . "(" . implode(",", array_map('\ThreadFin\DB\quote', $row)) . "),\n";
        }, "INSERT IGNORE INTO $table VALUES");
        // write to the output stream
        $bytes_written = $write_fn(substr($result, 0, -2) . ";\n\n");
        if ($bytes_written < 0) {
            return $offset;
        }

        // increment the offset by the limit
        $idx += $limit;
        $offset->set_check_point($idx);

        // let the database rest a second
        usleep(100000);
    }
    $offset->set_check_point(Offset::TABLE_COMPLETE);

    return $offset;
}

/**
 * dump all database tables to the function $write_fn
 * @param Credentials $cred Access credentials to the database
 * @param string $db_name the name of the database (eg, wordpress)
 * @param callable $write_fn a function that takes a string and 
 *      writes it to the output stream (fwrite, gzwrite, etc)
 * @param int $max_bytes maximum uncompressed bytes, including headers (nonnegative)
 * @return array of Offset objects. one for each table in $db_name
 */
function dump_database(Credentials $cred, string $db_name, callable $write_fn, int $max_bytes = 1024 * 1024 * 50): array
{
    if ($max_bytes < 0) {
        throw new \InvalidArgumentException('dump byte budget must be nonnegative');
    }
    $header = "# Database export of ($db_name) began at UTC: "
        . date(DATE_RFC3339) . "\n# UTC tv: " . utc_time() . "\n\n";
    // Restore the same charset/mode required by PHP-side quoted dump values.
    $init_sql = "SET NAMES 'utf8mb4';\n" . DB_SQL_MODE_SETUP . ";\n";

    // Select the requested database without mutating the supplied credentials.
    $db = DB::connect($cred->host, $cred->username, $cred->password, $db_name);
    $tables = $db->fetch("SHOW TABLES");
    $remaining = $max_bytes;
    $stopped = false;
    $bounded_write = function (string $chunk) use ($write_fn, &$remaining, &$stopped): int {
        $bytes = strlen($chunk);
        // Keep chunks whole so a budget stop cannot emit truncated SQL.
        if ($stopped || $bytes > $remaining) {
            $stopped = true;
            return -1;
        }
        if ($write_fn($chunk) < 0) {
            $stopped = true;
            return -1;
        }
        // Writers may return cumulative totals; account for input bytes instead.
        $remaining -= $bytes;
        return $bytes;
    };
    $bounded_write($header . $init_sql);

    return $tables->map(function (array $row) use ($db, $bounded_write, &$remaining, &$stopped): Offset {
        if ($stopped || $remaining === 0) {
            // Preserve an incomplete checkpoint for every unvisited table.
            return new Offset($row["Tables_in_{$db->database}"]);
        }
        return dump_table($db, $bounded_write, $row);
    });
}
