<?php

declare(strict_types=1);
/**
 * functional MySQL database abstraction
 */

namespace ThreadFin\DB;

use Attribute;
use Exception;
use mysqli;
use mysqli_result;
use OutOfBoundsException;
use RuntimeException;
use ThreadFin\Core\MaybeStr;

use function ThreadFin\Core\partial_right as bind_r;
use function ThreadFin\Log\debug;
use function ThreadFin\Util\utc_time;

const DB_FETCH_SUCCESS = 1;
const DB_FETCH_NUM_ROWS = 2;
const DB_FETCH_INSERT_ID = 4;
const DB_DUPLICATE_IGNORE = 8;
const DB_DUPLICATE_ERROR = 16;
const DB_DUPLICATE_UPDATE = 32;
const DB_MAX_BULK_INSERT = 64;

// Preserve strict/ANSI/other modes while enabling backslash string escapes.
const DB_SQL_MODE_SETUP = "SET SESSION sql_mode = TRIM(BOTH ',' FROM REPLACE(CONCAT(',', @@SESSION.sql_mode, ','), ',NO_BACKSLASH_ESCAPES,', ','))";


/**
 * The property is a primary key and will not update on duplicate
 */
#[Attribute(Attribute::TARGET_CLASS_CONSTANT | Attribute::TARGET_PROPERTY)]
class NoUpdate
{
    public function __construct() {}
}
/** 
 * the attribute will not update on duplicate if the update would null it
 */
#[Attribute(Attribute::TARGET_CLASS_CONSTANT | Attribute::TARGET_PROPERTY)]
class NotNull
{
    public function __construct() {}
}
/**
 * The property should only be updated if the value is not null and null in the DB
 */
#[Attribute(Attribute::TARGET_CLASS_CONSTANT | Attribute::TARGET_PROPERTY)]
class IfNull
{
    public function __construct() {}
}

/**
 * Interface from mapping SQL results to objects
 */
interface FromSQL
{
    #[\ReturnTypeWillChange]
    public static function from_sql(array $sql): mixed;
}


// set the error log file if running in cli mode
if (!defined("SQL_ERROR_FILE")) {
    define("SQL_ERROR_FILE", "/tmp/php_sql_errors.log");
}

/**
 * store mysql login credentials
 * @package ThreadFin\DB
 */
class Credentials
{
    public string $username;
    public string $password;
    public string $prefix;
    public string $db_name;
    public string $host;

    /**
     * create database credentials
     * @return Credentials
     */
    public function __construct(string $user, string $pass, string $host, string $db_name, string $pre = "")
    {
        $this->username = $user;
        $this->password = $pass;
        $this->prefix = $pre;
        $this->host = $host;
        $this->db_name = $db_name;
    }
}

/** 
 * used to glue key values pairs together for SQL queries
 * if data key begins with ! then the value is not quoted
 * EG: UPDATE $table set " . glue(" = ", $data, ", ") .  where_clause($where);
 */
function glue(array $data, string $join = " = ", string $append_str = ", "): string
{
    $result = "";
    foreach ($data as $key => $value) {
        if ($result != '') {
            $result .= $append_str;
        }
        if ($key[0] === '!') {
            $key = substr($key, 1);
            $result .= "`{$key}` $join $value";
        } else {
            $result .= "`{$key}`" . $join . quote($value);
        }
    }
    return $result;
}

/**
 * Quote UTF-8 text entirely in PHP for the session configured by DB::from/connect:
 * utf8mb4 with NO_BACKSLASH_ESCAPES disabled. Do not change that session mode or
 * charset after setup. Raw SQL/identifiers supplied through ! remain trusted APIs.
 */
function quote($input): string
{
    if (is_null($input)) {
        return 'null';
    }
    // Strings (including numeric-looking ones) must retain their string type.
    if (is_string($input)) {
        return quote_utf8($input);
    }
    if (is_numeric($input)) {
        return strval($input);
    }
    if (is_bool($input)) {
        return $input ? '1' : '0';
    }
    if (is_array($input)) {
        return implode(',', array_map('\ThreadFin\DB\quote', $input));
    }
    $x = (string)$input;
    debug("implicit quote cast to string: [%s]", $x);
    return quote_utf8($x);
}

/** Escape one UTF-8 string for a backslash-enabled MySQL session, without IO. */
function quote_utf8(string $input): string
{
    // strtr's array form replaces original bytes once, never re-escaping output.
    return "'" . strtr($input, [
        "\\" => "\\\\",
        "'" => "\\'",
        '"' => '\\"',
        "\0" => '\\0',
        "\n" => '\\n',
        "\r" => '\\r',
        "\t" => '\\t',
        "\x08" => '\\b',
        "\x1a" => '\\Z',
    ]) . "'";
}

/**
 * create a where clause from an array of key value pairs; PHP null uses IS NULL
 * @param array $data 
 * @return string - the generated SQL where clause
 */
function where_clause(array $data): string
{
    assert(count(array_filter(array_keys($data), 'is_string')) > 0, "where_clause requires an associative array");

    $result = " WHERE ";
    foreach ($data as $key => $value) {
        if (strlen($result) > 7) {
            $result .= " AND ";
        }
        if (is_string($key) && $key[0] === '!') {
            $t = substr($key, 1);
            $result .= ($value === null) ? " `{$t}` IS NULL " : " `{$t}` = {$value} ";
        } else {
            $result .= " `{$key}`" . (($value === null) ? " IS NULL" : " = " . quote($value));
        }
    }
    $x = trim($result, ",");
    return $x;
}


/**
 * Safe message/fields for opted-in execution failures, never raw driver messages.
 * PHP-managed traces are NOT redacted by this class. Applications logging full
 * exceptions must configure zend.exception_ignore_args=1 before execution; dumping
 * exception objects (including cleanup diagnostics) is not a safe diagnostic export.
 */
class ExecutionFailure extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $errno = 0,
        public readonly string $sqlstate = 'HY000',
        public readonly bool $transactionInvalidated = false
    ) {
        parent::__construct($message, $errno);
    }
}

/** Explicit arbitrary bytes for native binding; ordinary strings are UTF-8 text. */
final class BinaryParameter
{
    public function __construct(public readonly string $bytes) {}
}

class DB
{
    public $errors = [];
    public $logs = [];
    public $host;
    public $user;
    public $database;
    public $last_stmt = "";

    protected $_db;
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

    protected function __construct(?\mysqli $db)
    {
        $this->_db = $db;
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

    public function connected(): bool
    {
        return (!empty($this->_db));
    }

    /**
     * Take ownership of an existing connection and establish the quoting contract.
     * Charset/mode setup failure closes the handle and returns a disconnected DB.
     * @param null|mysqli $mysqli
     * @return DB a new DB object, from existing connection
     */
    public static function from(?\mysqli $mysqli): DB
    {
        $db = new DB($mysqli);
        if ($mysqli === null) {
            return $db;
        }
        try {
            if (!mysqli_set_charset($mysqli, 'utf8mb4')) {
                throw new RuntimeException('Unable to set database charset: ' . mysqli_error($mysqli));
            }
            if (mysqli_query($mysqli, DB_SQL_MODE_SETUP) !== true) {
                throw new RuntimeException('Unable to set database SQL mode: ' . mysqli_error($mysqli));
            }
        } catch (Exception $ex) {
            // Never expose a connection on which PHP-side quoting is unsafe.
            $db->errors[] = $ex->getMessage();
            mysqli_close($mysqli);
            $db->_db = null;
        }
        return $db;
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
     * @param null|Credentials $cred create a new DB connection
     * @return DB 
     */
    public static function cred_connect(?Credentials $cred): DB
    {
        if ($cred == NULL) {
            return DB::from(NULL);
        }
        return DB::connect($cred->host, $cred->username, $cred->password, $cred->db_name);
    }

    /**
     * Connection failures return a disconnected wrapper; callers decide whether to retry.
     * 
     * @param string $host 
     * @param string $user 
     * @param string $passwd 
     * @param string $db_name 
     * @return DB 
     */
    public static function connect(string $host, string $user, string $passwd, string $db_name): DB
    {
        $connection = mysqli_init();
        if ($connection === false) {
            $db = DB::from(null);
            $db->errors[] = 'Unable to initialize database connection';
            return $db;
        }
        try {
            if (!mysqli_options($connection, MYSQLI_OPT_CONNECT_TIMEOUT, 3)) {
                throw new RuntimeException('Unable to set database connection timeout');
            }
            if (!mysqli_real_connect($connection, $host, $user, $passwd, $db_name)) {
                throw new RuntimeException('Unable to connect to database: ' . mysqli_connect_error());
            }
        } catch (Exception $ex) {
            mysqli_close($connection);
            $db = DB::from(null);
            $db->errors[] = $ex->getMessage();
            return $db;
        }
        // from() configures new and externally supplied connections identically.
        $db = DB::from($connection);
        $db->host = $host;
        $db->user = $user;
        $db->database = $db_name;
        return $db;
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

    private function reject_transaction_change(): void
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
     * calls _qb internally to run a insert/update/delete statement.
     * not for fetching data.  see @fetch instead
     * 
     * @param string $sql raw SQL to run.  be careful, this must be pre-escaped!
     * @param int $mode, one of DB_FETCH_SUCCESS, DB_FETCH_NUM_ROWS, DB_FETCH_INSERT_ID
     * @return DB_FETCH_SUCCESS -1 on error, 1 on success
     *  DB_FETCH_NUM_ROWS -1 on error, 0 if no rows affected, >0 if rows affected
     *  DB_FETCH_INSERT_ID -1 on error, >0 if insert id
     *  In simulation: 1 for DB_FETCH_SUCCESS, 0 for counts/IDs (no execution).
     */
    public function unsafe_raw(string $sql, int $mode = DB_FETCH_SUCCESS): int
    {
        assert(in_array($mode, [DB_FETCH_SUCCESS, DB_FETCH_NUM_ROWS, DB_FETCH_INSERT_ID], true), "invalid mode: $mode");

        return intval($this->_qb($sql, $mode));
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

    private function reject_busy_stream(): never
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
     * 
     * @param string $sql 
     * @param mixed $data 
     * @param int $mode 
     * @return string 
     */
    protected function fetch_to_statement(string $sql, $data = NULL, $mode = MYSQLI_ASSOC): string
    {
        if ($data !== null && !is_array($data) && !is_object($data)) {
            throw new \InvalidArgumentException('SQL template parameters must be an array, object, or null');
        }
        // get_object_vars omits inaccessible and uninitialized fields, but retains nulls.
        $parameters = is_object($data) ? get_object_vars($data) : ($data ?? []);
        return preg_replace_callback("/{!?\w+}/", function ($match) use ($parameters) {
            $param = substr($match[0], 1, -1);
            $raw = $param[0] === '!';
            if ($raw) {
                $param = substr($param, 1);
            }
            if (!array_key_exists($param, $parameters)) {
                throw new \InvalidArgumentException("Missing SQL template parameter [$param]");
            }
            $value = $parameters[$param];
            // ! expressions remain trusted raw SQL; explicit null is always SQL NULL.
            return ($raw && $value !== null) ? (string)$value : quote($value);
        }, $sql);
    }


    /**
     * delete entries from $table where $data matches
     * @param string $table table name
     * @param array $where key value pairs of column names and values
     * @return int -1 on error, 0 if no rows were deleted, > 0 number rows that were deleted
     */
    public function delete(string $table, array $where): int
    {
        $sql = "DELETE FROM $table " . where_clause($where);
        return intval($this->_qb($sql, DB_FETCH_NUM_ROWS));
    }

    /**
     * return a (key name) values (...) SQL string
     * with correct escaping and handling for ! column names
     * @param array $kvp column name value pairs
     * @return string the resulting SQL 
     */
    private function insert_sql(array $kvp): string
    {
        $key_names = "";
        $values = "";
        foreach ($kvp as $column => $value) {

            if ($column[0] === '!') {
                $column = substr($column, 1);
            } else {
                $value = quote($value);
            }
            $key_names .= $column . ', ';
            $values .= $value . ', ';
        }
        $values = trim($values, ' ,');
        $key_names = trim($key_names, ' ,');
        return "($key_names) VALUES ($values)";
    }


    /**
     * helper function to create an insert statement
     * @param string $table table name to insert
     * @param array $data key value pairs column -> data
     * @param int $on_duplicate, must be one of DB_DUPLICATE_IGNORE, DB_DUPLICATE_UPDATE
     * @param null|array $no_update kvp of column names to not update on duplicate, key is column name value is true
     * @param null|array $if_null kvp of column names to only update if there are null, key is column name value is true
     * @return string - the resulting SQL
     */
    protected function insert_stmt(string $table, array $data, int $on_duplicate = DB_DUPLICATE_IGNORE, ?array $no_update = null, ?array $if_null = null): string
    {

        $ignore = "";
        // ignore duplicates
        if ($on_duplicate === DB_DUPLICATE_IGNORE) {
            $ignore = "IGNORE";
        }

        if (! array_is_list($data)) {
            $value_sql = $this->insert_sql($data);
            $sql = "INSERT $ignore INTO `$table` $value_sql ";
        } else {
            $sql = "INSERT $ignore INTO `$table` VALUES (" . join(",", array_map('\ThreadFin\DB\quote', $data)) . ")";
        }

        // update on duplicate, exclude any PKS
        if ($on_duplicate === DB_DUPLICATE_UPDATE) {
            if (array_is_list($data)) {
                throw new RuntimeException('Duplicate updates require an associative array of column values');
            }
            $update_data = array_diff_key($data, $no_update ?? []);
            $suffix = "";
            foreach ($update_data as $key => $value) {
                $q_value = quote($value);
                if (isset($if_null[$key])) {
                    $suffix .= "`$key` = IF(`$key` = '' OR `$key` IS NULL, $q_value, `$key`), ";
                } else {
                    $suffix .= "`$key` = $q_value, ";
                }
            }
            if (!empty($suffix)) {
                $sql .= " ON DUPLICATE KEY UPDATE " . substr($suffix, 0, -2);
            }
        }

        return $sql;
    }

    /**
     * insert $data into $table 
     * @param string $table 
     * @param array $kvp 
     * @param int $on_duplicate - DB_DUPLICATE_IGNORE, DB_DUPLICATE_UPDATE. 
     *   IMPORTANT! for update be sure auto incrementing PK is not in $data
     * @return int 
     */
    public function insert(string $table, array $kvp, int $on_duplicate = DB_DUPLICATE_IGNORE): int
    {
        $sql = $this->insert_stmt($table, $kvp, $on_duplicate);

        return intval($this->_qb($sql));
    }

    /**
     * return a function that will insert key value pairs into $table.  
     * keys are column names, values are data to insert.
     * @param string $table the table name
     * @param ?array $keys list of allowed key names from the passed $data
     * @return callable(array $data) insert $data into $table - return newly created db id
     */
    public function insert_fn(string $table, ?array $keys = null, bool $ignore_duplicate = true): callable
    {
        $t = $this;
        $ignore = ($ignore_duplicate) ? "IGNORE" : "";
        $prefix = "INSERT $ignore INTO $table ";
        return function (array $data) use ($prefix, &$t, $keys): int {
            // set flag to ignore dupliactes
            if (array_is_list($data)) {
                $sql = "$prefix VALUES (" . join(",", array_map('\ThreadFin\DB\quote', $data)) . ")";
            } else {
                // filter out unwanted key/values
                if (!empty($keys)) {
                    $data = array_filter($data, bind_r('in_array', $keys), ARRAY_FILTER_USE_KEY);
                }
                $sql = "$prefix (" . join(",", array_keys($data)) .
                    ") VALUES (" . join(",", array_map('\ThreadFin\DB\quote', array_values($data))) . ")";
            }

            $id = $t->_qb($sql, DB_FETCH_INSERT_ID);
            return $id;
        };
    }

    /**
     * return a function that will upsert key value pairs into $table. will always return the PK id for the row effected 
     * keys are column names, values are data to insert.
     * @param string $table the table name
     * @param ?array $keys list of allowed key names from the passed $data
     * @return callable(array $data) insert $data into $table - return newly created db id
     */
    public function upsert_fn(string $table, ?array $keys = null, string $pk = "id"): callable
    {
        $t = $this;
        $prefix = "INSERT INTO $table ";
        return function (array $data) use ($prefix, &$t, $keys, $pk): int {
            // set flag to ignore dupliactes
            $update = "";
            if (array_is_list($data)) {
                $sql = "$prefix VALUES (" . join(',', array_map('\ThreadFin\DB\quote', $data)) . ')';
            } else {
                // filter out unwanted key/values
                if (!empty($keys)) {
                    $data = array_filter($data, bind_r('in_array', $keys), ARRAY_FILTER_USE_KEY);
                }
                $key_names = "";
                $values = "";
                foreach ($data as $column => $value) {

                    if ($column[0] === '!') {
                        $column = substr($column, 1);
                    } else {
                        $value = quote($value);
                    }
                    $key_names .= $column . ', ';
                    $values .= $value . ', ';
                    // Every supplied non-PK value is an update, including SQL zero.
                    if ($column != $pk) {
                        $update .= "$column = $value, ";
                    }
                }
                $values = trim($values, ' ,');
                $key_names = trim($key_names, ' ,');
                $update = trim($update, ' ,');
                $sql = "$prefix ($key_names) VALUES ($values)";
            }

            $sql .= " ON DUPLICATE KEY UPDATE $pk = LAST_INSERT_ID($pk)";
            if (!empty($update)) {
                $sql .= ", $update";
            }

            $id = $t->_qb($sql, DB_FETCH_INSERT_ID);
            return $id;
        };
    }


    /**
     * Buffer inserts until explicit flush (null/no argument) or DB_MAX_BULK_INSERT rows.
     * Does not support {} replacement; identifiers remain trusted input.
     * @param string $table the table name
     * @param array $columns list of column names, or column => source-key mapping
     * @return callable(?array $data): int pending row count, 1 on successful flush,
     *         0 on empty flush, or -1 on failed flush (the batch is retained)
     * @throws \InvalidArgumentException for empty columns or missing row fields
     */
    public function bulk_fn(string $table, array $columns, bool $ignore_duplicate = true): callable
    {
        if (empty($columns)) {
            throw new \InvalidArgumentException('Bulk inserts require at least one column');
        }
        if (array_is_list($columns)) {
            $columns = array_combine($columns, $columns);
        }
        $t = $this;
        $ignore = ($ignore_duplicate) ? "IGNORE" : "";
        $prefix = "INSERT $ignore INTO $table (" . join(",", array_keys($columns)) . ") VALUES ";
        $ctr = 0;
        $sql = "";
        $flush = function () use ($t, $prefix, &$ctr, &$sql): int {
            if ($ctr === 0) {
                return 0;
            }
            $status = $t->_qb($prefix . substr($sql, 0, -2));
            if ($status >= 0) {
                $ctr = 0;
                $sql = "";
            }
            return $status;
        };
        return function (?array $data = null) use ($columns, $flush, &$ctr, &$sql): int {
            if ($data === null) {
                return $flush();
            }
            // Build the entire row before touching the buffer, preserving explicit nulls.
            $values = [];
            foreach ($columns as $key_name) {
                if (!array_key_exists($key_name, $data)) {
                    throw new \InvalidArgumentException("Missing bulk row field [$key_name]");
                }
                $values[] = quote($data[$key_name]);
            }
            // A failed full batch must be retried before accepting another row.
            if ($ctr >= DB_MAX_BULK_INSERT && $flush() < 0) {
                return -1;
            }
            $sql .= "(" . implode(",", $values) . "),\n";
            $ctr++;
            return ($ctr >= DB_MAX_BULK_INSERT) ? $flush() : $ctr;
        };
    }



    /**
     * update $table and set $data where $where
     * @return int num updated rows (determined by $return_type parameter)
     */
    public function update(string $table, array $data, array $where, int $return_type = DB_FETCH_NUM_ROWS): int
    {
        // unset all where keys in data. this makes no sense when where is a PK
        array_walk($where, function ($value, $key) use (&$data) {
            unset($data[$key]);
        });

        // glue does the escaping for us here...
        $sql = "UPDATE `$table` set " . glue($data) .  where_clause($where);
        return $this->_qb($sql, $return_type);
    }

    /**
     * store object data into table.  data must have public members and have the 
     * same names as the table
     * @return int insert ID, -1 when the write/ID fetch fails, or 0 in simulation
     */
    public function store(string $table, Object $data, int $on_duplicate = DB_DUPLICATE_IGNORE): int
    {
        assert($this->_db instanceof mysqli, "database not connected");

        // ReflectionObject includes public dynamic fields as well as declared ones.
        $r = new \ReflectionObject($data);
        $props = $r->getProperties(\ReflectionProperty::IS_PUBLIC);
        $no_updates = [];
        $if_null = [];
        // turn instance data into an array and build duplicate-update policies
        $kvp = array_reduce($props, function ($kvp, $item) use ($data, &$no_updates, &$if_null) {
            if ($item->isStatic()) {
                return $kvp;
            }
            $name = $item->name;
            $attrs = $item->getAttributes();

            foreach ($attrs as $attr) {
                $attribute = $attr->getName();
                switch ($attribute) {
                    case NoUpdate::class:
                        $no_updates[$name] = true;
                        break;
                    case NotNull::class:
                        if (!isset($data->$name)) {
                            return $kvp;
                        }
                        break;
                    case IfNull::class:
                        $if_null[$name] = true;
                }
            }

            if (isset($data->$name)) {
                $kvp[$name] = $data->$name;
            }
            return $kvp;
        }, []);

        $sql = $this->insert_stmt($table, $kvp, $on_duplicate, $no_updates, $if_null);
        return $this->_qb($sql, DB_FETCH_INSERT_ID);
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
 * SQL result abstraction
 */
class SQL implements \ArrayAccess, \Iterator, \SeekableIterator, \Countable
{
    protected ?array $_x = null;
    protected int $_position = 0;
    protected string $_sql = '';
    protected int $_len = 0;
    protected ?mysqli_result $_mysqli_result = null;
    // Array-backed datasets are separate from the iterator's current row (_x).
    protected ?array $_rows = null;

    public function count(): int
    {
        if ($this->_rows !== null) {
            return count($this->_rows);
        }
        if (empty($this->_mysqli_result)) {
            return 0;
        }
        return intval(mysqli_num_rows($this->_mysqli_result));
    }

    public function empty(): bool
    {
        return ($this->count() == 0);
    }

    /** Return all buffered rows without disturbing the iterator's current row. */
    public function as_array(): array
    {
        if ($this->_rows !== null) {
            return $this->_rows;
        }
        if (!$this->_mysqli_result || $this->_mysqli_result->num_rows === 0) {
            return [];
        }
        // The current row was already fetched, so next() must resume after it.
        $resume = $this->_position + 1;
        $this->_mysqli_result->data_seek(0);
        try {
            return mysqli_fetch_all($this->_mysqli_result, MYSQLI_ASSOC);
        } finally {
            if ($resume < $this->_mysqli_result->num_rows) {
                $this->_mysqli_result->data_seek($resume);
            }
            // Otherwise fetch_all left the cursor at EOF, which is already correct.
        }
    }

    /** Integer strings are supported; other scalar types must not coerce into row positions. */
    private static function row_offset(mixed $offset): ?int
    {
        if (is_int($offset)) {
            return $offset;
        }
        if (!is_string($offset) || preg_match('/\A[+-]?[0-9]+\z/', $offset) !== 1) {
            return null;
        }
        $negative = $offset[0] === '-';
        $digits = ltrim(ltrim($offset, '+-'), '0');
        if ($digits === '') {
            return 0;
        }
        $limit = $negative ? substr((string)PHP_INT_MIN, 1) : (string)PHP_INT_MAX;
        if (strlen($digits) > strlen($limit) || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)) {
            return null;
        }
        return (int)($negative ? '-' . $digits : $digits);
    }

    public function offsetExists(mixed $offset): bool
    {
        $offset = self::row_offset($offset);
        return $offset !== null && $offset >= 0 && $offset < $this->_len
            && ($this->_rows !== null || $this->_mysqli_result !== null);
    }

    public function offsetGet(mixed $offset): array
    {
        $offset = self::row_offset($offset);
        if ($offset === null) {
            throw new OutOfBoundsException('Row offset must be an integer or integer string');
        }
        if ($offset < 0 || $offset >= $this->_len) {
            throw new OutOfBoundsException("row offset [$offset] is out of bounds");
        }
        if ($this->_rows !== null) {
            return $this->_rows[$offset];
        }
        if (!$this->_mysqli_result || !$this->_mysqli_result->data_seek($offset)) {
            throw new OutOfBoundsException("row offset [$offset] is out of bounds");
        }
        try {
            return $this->_mysqli_result->fetch_assoc();
        } finally {
            // Random reads must not change the iterator's next-fetch position.
            $resume = $this->_position + 1;
            if ($resume < $this->_len) {
                $this->_mysqli_result->data_seek($resume);
            } else {
                // mysqli cannot seek directly to EOF; consume the last row instead.
                $this->_mysqli_result->data_seek($this->_len - 1);
                $this->_mysqli_result->fetch_assoc();
            }
        }
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new OutOfBoundsException('SQL results are read-only');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new OutOfBoundsException('SQL results are read-only');
    }

    /**
     * create a new SQL result abstraction from an array of associative rows
     * @param null|array $x rows in iteration order, normalized to zero-based offsets
     * @param string $in_sql the sql that generated the result
     * @param bool $fetch_all retained for named-argument compatibility; results are always buffered
     * @return SQL 
     */
    public static function from(?array $x, string $in_sql = "", bool $fetch_all = true): SQL
    {
        $sql = new SQL();
        $sql->_rows = array_values($x ?? []);
        $sql->_len = count($sql->_rows);
        $sql->_x = $sql->_rows[0] ?? null;
        $sql->_sql = $in_sql;
        return $sql;
    }

    public static function fetch(mysqli_result $result, string $sql): SQL
    {
        $wrapper = new SQL();
        $wrapper->_mysqli_result = $result;
        $wrapper->_sql = $sql;
        $wrapper->_x = $result->fetch_assoc();
        $wrapper->_len = $result->num_rows;
        return $wrapper;
    }


    /**
     * set internal dataset to row  at current row index 
     */
    public function seek(int $offset = 0): void
    {
        if ($offset < 0 || $offset >= $this->_len) {
            throw new OutOfBoundsException("row offset [$offset] is out of bounds");
        }
        if ($this->_rows !== null) {
            $this->_x = $this->_rows[$offset];
        } else {
            if (!$this->_mysqli_result || !$this->_mysqli_result->data_seek($offset)) {
                throw new OutOfBoundsException("row offset [$offset] is out of bounds");
            }
            $this->_x = $this->_mysqli_result->fetch_assoc();
        }
        $this->_position = $offset;
    }

    public function current(): ?array
    {
        return $this->_x;
    }

    public function key(): int
    {
        return $this->_position;
    }

    public function next(): void
    {
        $this->_position++;
        if ($this->_rows !== null) {
            $this->_x = $this->_rows[$this->_position] ?? null;
        } else if ($this->_mysqli_result) {
            $this->_x = $this->_mysqli_result->fetch_assoc();
        } else {
            $this->_x = null;
        }
    }

    public function rewind(): void
    {
        $this->_position = 0;
        if ($this->_rows !== null) {
            $this->_x = $this->_rows[0] ?? null;
        } else if ($this->_mysqli_result && $this->_len > 0) {
            $this->_mysqli_result->data_seek(0);
            $this->_x = $this->_mysqli_result->fetch_assoc();
        } else {
            $this->_x = null;
        }
    }

    public function valid(): bool
    {
        return $this->_position < $this->_len;
    }

    /**
     * @return MaybeStr of column $name at current row index
     */
    public function col(string $name): MaybeStr
    {
        return MaybeStr::of($this->_x[$name] ?? null);
    }

    /** Map every buffered row without changing the iterator's position. */
    public function map(callable $fn): array
    {
        return array_map($fn, $this->as_array());
    }

    /** Fold every buffered row; empty results return the initial value unchanged. */
    public function reduce(callable $fn, $initial = ""): mixed
    {
        return array_reduce($this->as_array(), $fn, $initial);
    }

    public function close(): void
    {
        try {
            if ($this->_mysqli_result) {
                $this->_mysqli_result->free();
            }
        } finally {
            $this->_mysqli_result = null;
            $this->_rows = null;
            $this->_x = null;
            $this->_len = 0;
            $this->_position = 0;
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
