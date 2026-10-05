<?php declare(strict_types=1);
/**
 * Test-only mysqli boundary for isolated `php -n` processes.
 * Not a SQL engine: scenarios supply result rows/affected counts explicitly.
 * Never load this file in the application or the TinyTest host process.
 */
if (extension_loaded('mysqli')) {
    throw new RuntimeException('The mysqli stand-in requires an isolated php -n process');
}

const MYSQLI_ASSOC = 1;
const MYSQLI_NUM = 2;
const MYSQLI_BOTH = 3;
const MYSQLI_OPT_CONNECT_TIMEOUT = 0;

class mysqli_sql_exception extends RuntimeException {}

class mysqli {
    public int $affected_rows = 0;
    public int $insert_id = 0;
    public int $errno = 0;
    public string $error = '';
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

    public function free(): void { $this->freed = true; }

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
function mysqli_query(mysqli $db, string $sql): mysqli_result|bool {
    if ($db->closed) {
        throw new Error('mysqli object is already closed');
    }
    $db->queries[] = $sql;
    $db->calls[] = ['query', $sql];
    if (isset($GLOBALS['db_fixture_query_failures'][$sql])) {
        $db->errno = 1064;
        $db->error = 'Syntax error (fixture)';
        if ($GLOBALS['db_fixture_query_failures'][$sql] === 'exception') {
            throw new mysqli_sql_exception($db->error, $db->errno);
        }
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
function mysqli_error(mysqli $db): string { return $db->error; }
function mysqli_affected_rows(mysqli $db): int { return $db->affected_rows; }
function mysqli_insert_id(mysqli $db): int { return $db->insert_id; }
function mysqli_num_rows(mysqli_result $result): int { return $result->num_rows; }
function mysqli_fetch_all(mysqli_result $result, int $mode = MYSQLI_NUM): array { return $result->fetch_all($mode); }
function mysqli_close(mysqli $db): bool { $db->closed = true; return true; }
