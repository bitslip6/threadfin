<?php declare(strict_types=1);
/**
 * Test-only mysqli boundary for isolated `php -n` processes.
 * Not a SQL engine: scenarios supply result rows/affected counts explicitly.
 * Never load this file in the application or the TinyTest host process.
 */
if (extension_loaded('mysqli')) {
    throw new RuntimeException('The mysqli stand-in requires an isolated php -n process');
}

const MYSQLI_USE_RESULT = 1;
const MYSQLI_ASSOC = 1;
const MYSQLI_NUM = 2;
const MYSQLI_BOTH = 3;
const MYSQLI_OPT_CONNECT_TIMEOUT = 0;

if ($GLOBALS['db_fixture_legacy_sql_exception'] ?? false) {
    // PHP 8.1.0/8.1.1 expose the exception without getSqlState().
    class mysqli_sql_exception extends RuntimeException {}
} else {
    class mysqli_sql_exception extends RuntimeException {
        public function getSqlState(): string { return $this->getCode() === 1213 ? '40001' : '42000'; }
    }
}

class mysqli {
    public int $affected_rows = 0;
    public int $insert_id = 0;
    public int $errno = 0;
    public string $error = '';
    public string $sqlstate = '00000';
    public bool $closed = false;
    public string $database = '';
    public string $charset = 'latin1';
    public string $sql_mode = 'STRICT_TRANS_TABLES,NO_BACKSLASH_ESCAPES,ANSI_QUOTES,NO_ENGINE_SUBSTITUTION';
    public array $queries = [];
    public array $calls = [];
}

class mysqli_result {
    public int $num_rows;
    private int $cursor = 0;
    private bool $freed = false;

    public function __construct(private array $rows) {
        $this->num_rows = count($rows);
    }

    public function fetch_assoc(): ?array {
        $this->checkOpen();
        if ($GLOBALS['db_fixture_result_fetch_failure'] ?? false) { throw new mysqli_sql_exception('SECRET fetch failure', 1064); }
        return $this->rows[$this->cursor++] ?? null;
    }

    public function data_seek(int $offset): bool {
        $this->checkOpen();
        if ($offset < 0) {
            throw new ValueError('mysqli_result::data_seek(): offset must be greater than or equal to 0');
        }
        if ($offset >= $this->num_rows) {
            return false;
        }
        $this->cursor = $offset;
        return true;
    }

    public function fetch_all(int $mode = MYSQLI_NUM): array {
        $this->checkOpen();
        $rows = array_slice($this->rows, $this->cursor);
        $this->cursor = $this->num_rows;
        if ($mode === MYSQLI_NUM) {
            return array_map('array_values', $rows);
        }
        return $rows;
    }

    public int $free_calls = 0;
    public function fetch_fields(): array {
        return array_map(fn($name) => (object)['name' => $name], array_keys($this->rows[0] ?? []));
    }
    public function free(): void {
        $this->free_calls++;
        if ($GLOBALS['db_fixture_result_free_failure'] ?? false) { throw new RuntimeException('SECRET result cleanup failure'); }
        $this->freed = true;
    }

    private function checkOpen(): void {
        if ($this->freed) {
            throw new Error('mysqli_result object is already closed');
        }
    }
}

function mysqli_init(): mysqli {
    return $GLOBALS['db_fixture_last_connection'] = new mysqli();
}
function mysqli_options(mysqli $db, int $option, mixed $value): bool { return true; }
function mysqli_real_connect(mysqli $db, string $host, string $user, string $password, string $database): bool {
    $GLOBALS['db_fixture_connected_database'] = $database;
    if (($GLOBALS['db_fixture_connection_failure'] ?? false) === 'false') { return false; }
    if ($GLOBALS['db_fixture_connection_failure'] ?? false) {
        throw new mysqli_sql_exception('Connection refused (fixture)', 2002);
    }
    $db->database = $database;
    return true;
}
function mysqli_connect_error(): string { return 'Connection refused (fixture)'; }
function mysqli_set_charset(mysqli $db, string $charset): bool {
    $db->calls[] = ['charset', $charset];
    if (($GLOBALS['db_fixture_setup_failure'] ?? '') === 'charset_exception') {
        throw new mysqli_sql_exception('Charset setup rejected (fixture)');
    }
    if (($GLOBALS['db_fixture_setup_failure'] ?? '') === 'charset_false') {
        $db->error = 'Charset setup rejected (fixture)';
        return false;
    }
    $db->charset = $charset;
    return true;
}
function mysqli_query(mysqli $db, string $sql, ?int $mode = null): mysqli_result|bool {
    if ($mode !== null) { $db->calls[] = ['query_mode', $mode]; }
    if ($db->closed) {
        throw new Error('mysqli object is already closed');
    }
    $db->queries[] = $sql;
    $db->calls[] = ['query', $sql];
    if (isset($GLOBALS['db_fixture_query_failures'][$sql])) {
        $db->errno = $GLOBALS['db_fixture_query_errno'][$sql] ?? 1064;
        $db->sqlstate = $db->errno === 1213 ? '40001' : '42000';
        $db->error = 'Syntax error (fixture)';
        if ($GLOBALS['db_fixture_query_failures'][$sql] === 'exception') {
            throw new mysqli_sql_exception($db->error, $GLOBALS['db_fixture_exception_errno'][$sql] ?? $db->errno);
        }
        if ($GLOBALS['db_fixture_query_failures'][$sql] === 'error') { throw new Error('Driver boundary error (fixture)'); }
        return false;
    }
    if ($sql === 'SELECT @@SESSION.sql_mode AS sql_mode, @@SESSION.autocommit AS autocommit') {
        return new mysqli_result([['sql_mode' => $db->sql_mode,
            'autocommit' => $GLOBALS['db_fixture_replay_autocommit'] ?? 1]]);
    }
    if ($sql === \ThreadFin\DB\DB_SQL_MODE_SETUP) {
        if (($GLOBALS['db_fixture_setup_failure'] ?? '') === 'mode_exception') {
            throw new mysqli_sql_exception('SQL mode setup rejected (fixture)');
        }
        if (($GLOBALS['db_fixture_setup_failure'] ?? '') === 'mode_false') {
            $db->error = 'SQL mode setup rejected (fixture)';
            return false;
        }
        $db->sql_mode = implode(',', array_filter(explode(',', $db->sql_mode),
            fn($mode) => $mode !== 'NO_BACKSLASH_ESCAPES'));
    }
    $db->affected_rows = 0;
    $db->insert_id = 0;
    if (str_starts_with($sql, 'INSERT')) {
        $db->affected_rows = 1;
        $db->insert_id = 7;
    }
    if (isset($GLOBALS['db_fixture_query_rows'][$sql])) {
        return new mysqli_result($GLOBALS['db_fixture_query_rows'][$sql]);
    }
    if (str_starts_with($sql, 'SHOW TABLES')) {
        return new mysqli_result([]);
    }
    if (str_starts_with($sql, 'SELECT')) {
        return new mysqli_result([['name' => 'bob']]);
    }
    return true;
}
function mysqli_errno(mysqli $db): int { return $db->errno; }
function mysqli_sqlstate(mysqli $db): string { return $db->sqlstate; }
function mysqli_error(mysqli $db): string { return $db->error; }
function mysqli_affected_rows(mysqli $db): int { return $db->affected_rows; }
function mysqli_insert_id(mysqli $db): int { return $db->insert_id; }
function mysqli_num_rows(mysqli_result $result): int { return $result->num_rows; }
function mysqli_fetch_all(mysqli_result $result, int $mode = MYSQLI_NUM): array { return $result->fetch_all($mode); }
function mysqli_close(mysqli $db): bool {
    if ($GLOBALS['db_fixture_close_warning'] ?? false) {
        unset($GLOBALS['db_fixture_close_warning']);
        fopen(__DIR__ . '/CANARY_BOUND_SECRET_missing_fixture', 'r');
    }
    $db->closed = true; return true;
}

/** Canned statement boundary only; no placeholder parser or SQL execution engine. */
class mysqli_stmt {
    public int $param_count;
    public int $affected_rows;
    public int $insert_id;
    public int $errno = 0;
    public string $sqlstate = '00000';
    public bool $closed = false;
    public array $bound = [];
    public array $chunks = [];
    public string $types = '';
    public ?mysqli_result $metadata = null;
    private array $outputs = [];
    private int $cursor = 0;
    public function __construct(public mysqli $db, public array $scenario) {
        $this->param_count = $scenario['count'] ?? 0;
        $this->affected_rows = $scenario['affected'] ?? 0;
        $this->insert_id = $scenario['id'] ?? 0;
    }
    public function step(string $operation): bool {
        $this->db->calls[] = [$operation];
        $failure = $this->scenario['fail'][$operation] ?? null;
        if ($failure === null) { return true; }
        $this->errno = $this->scenario['errno'] ?? 1064;
        $this->sqlstate = $this->errno === 1213 ? '40001' : '42000';
        if ($failure === 'warning' || $failure === 'advisory') {
            // Emit a real E_WARNING, not E_USER_WARNING: no fake SQL semantics.
            fopen(__DIR__ . '/CANARY_BOUND_SECRET_missing_fixture', 'r');
            if ($failure === 'advisory') { $this->errno = 0; $this->sqlstate = '00000'; return true; }
        }
        if ($failure === 'exception') { throw new mysqli_sql_exception('SECRET native error', $this->errno); }
        if ($failure === 'error') { throw new Error('SECRET boundary error'); }
        return false;
    }
    public function bind_param(string $types, mixed &...$values): bool {
        $this->types = $types;
        $this->bound = &$values;
        return $this->step('bind_param');
    }
    public function send_long_data(int $index, string $bytes): bool {
        $this->chunks[] = [$index, $bytes];
        return $this->step('send_long_data');
    }
    public function execute(): bool { return $this->step('execute'); }
    public function store_result(): bool { return $this->step('store_result'); }
    public function result_metadata(): mysqli_result|false {
        if (!$this->step('result_metadata') || !isset($this->scenario['fields'])) { return false; }
        return $this->metadata = new mysqli_prepared_metadata($this);
    }
    public function bind_result(mixed &...$values): bool {
        $this->outputs = &$values;
        return $this->step('bind_result');
    }
    public function fetch(): ?bool {
        if (!$this->step('fetch')) { return false; }
        $row = $this->scenario['rows'][$this->cursor++] ?? null;
        if ($row === null) { return null; }
        foreach ($row as $index => $value) { $this->outputs[$index] = $value; }
        return true;
    }
    public function free_result(): void {
        if (!$this->step('free_result')) { throw new RuntimeException('SECRET free failure'); }
    }
    public function close(): bool {
        $this->closed = true;
        return $this->step('stmt_close');
    }
}
class mysqli_prepared_metadata extends mysqli_result {
    public function __construct(private mysqli_stmt $statement) {
        parent::__construct([array_fill_keys($statement->scenario['fields'], null)]);
    }
    public function fetch_fields(): array {
        if (!$this->statement->step('fetch_fields')) { throw new RuntimeException('SECRET metadata failure'); }
        return parent::fetch_fields();
    }
    public function free(): void {
        if (!$this->statement->step('metadata_free')) { throw new RuntimeException('SECRET metadata cleanup'); }
        parent::free();
    }
}
function mysqli_prepare(mysqli $db, string $sql): mysqli_stmt|false {
    $db->calls[] = ['prepare', $sql];
    $stmt = new mysqli_stmt($db, $GLOBALS['db_fixture_prepared'] ?? []);
    $GLOBALS['db_fixture_statements'][] = $stmt;
    try { $ok = $stmt->step('prepare_result'); }
    finally { $db->errno = $stmt->errno; $db->sqlstate = $stmt->sqlstate; }
    return $ok ? $stmt : false;
}
