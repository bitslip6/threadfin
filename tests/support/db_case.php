<?php declare(strict_types=1);
/** Isolated observations of real db.php behavior, consumed by TinyTest tests. */
error_reporting(E_ALL & ~E_DEPRECATED); // Existing core.php has PHP 8.4+ implicit-nullable declarations.
require __DIR__ . '/mysqli.php';

$fixtureFiles = [];
function db_fixture_file(): string {
    $path = tempnam(sys_get_temp_dir(), 'threadfin-db-test-');
    if ($path === false) {
        throw new RuntimeException('Cannot create regression fixture file');
    }
    $GLOBALS['fixtureFiles'][] = $path;
    return $path;
}
define('SQL_ERROR_FILE', db_fixture_file());
require __DIR__ . '/../../core.php';
require __DIR__ . '/../../db.php';

use ThreadFin\DB\DB;
use ThreadFin\DB\SQL;
use ThreadFin\DB\Credentials;
use function ThreadFin\DB\quote;
use function ThreadFin\DB\where_clause;
use function ThreadFin\DB\stream_output_fn;
use function ThreadFin\DB\dump_database;
use const ThreadFin\DB\DB_DUPLICATE_UPDATE;
use const ThreadFin\DB\DB_DUPLICATE_IGNORE;
use const ThreadFin\DB\DB_FETCH_SUCCESS;

/** Capture generated statements without overriding the SQL builder under test. */
class DbStatementProbe extends DB {
    public array $statements = [];
    public function __construct(?mysqli $db = null) { parent::__construct($db); }
    protected function _qb(string $sql, int $return_type = DB_FETCH_SUCCESS): int {
        $this->statements[] = $sql;
        return $return_type === \ThreadFin\DB\DB_FETCH_INSERT_ID ? 7 : 1;
    }
    public function insertStatement(array $data, int $mode, ?array $noUpdate = null, ?array $ifNull = null): string {
        return $this->insert_stmt('records', $data, $mode, $noUpdate, $ifNull);
    }
    public function template(string $sql, array $data): string {
        return $this->fetch_to_statement($sql, $data);
    }
}

/** Isolate attribute extraction from downstream SQL generation and execution. */
class DbAttributeProbe extends DbStatementProbe {
    public array $extracted = [];
    protected function insert_stmt(string $table, array $data, int $on_duplicate = DB_DUPLICATE_IGNORE,
                                 ?array $no_update = null, ?array $if_null = null): string {
        $this->extracted = ['data' => $data, 'no_update' => $no_update, 'if_null' => $if_null];
        return 'ATTRIBUTE_CAPTURE';
    }
}
class DbNoUpdateRecord {
    #[ThreadFin\DB\NoUpdate] public int $id = 7;
    public string $name = 'bob';
}
class DbIfNullRecord {
    #[ThreadFin\DB\IfNull] public string $name = 'bob';
}
class DbValueRecord {
    public function __construct(public string $text) {}
}
class DbNotNullRecord {
    #[ThreadFin\DB\NotNull] public int $zero = 0;
    #[ThreadFin\DB\NotNull] public bool $flag = false;
    #[ThreadFin\DB\NotNull] public string $blank = '';
    #[ThreadFin\DB\NotNull] public ?string $nil = null;
}
class DbPublicInstanceRecord {
    public string $name = 'bob';
    public string $uninitialized;
    public ?string $nil = null;
    public static string $metadata = 'not_a_column';
    private string $secret = 'not_a_column';
    protected string $internal = 'not_a_column';
}

function db_fixture_rows(): array {
    return [['id' => '1', 'name' => 'alice'], ['id' => '2', 'name' => 'bob'], ['id' => '3', 'name' => 'carol']];
}
function db_fixture_result(): SQL {
    return SQL::fetch(new mysqli_result(db_fixture_rows()), 'SELECT id, name FROM records');
}
function db_fixture_property(object $object, string $name): mixed {
    return (new ReflectionProperty($object, $name))->getValue($object);
}

/** Decode one quoted or UTF-8 hex literal in either backslash SQL mode.
 * This deliberately tiny lexer does not execute SQL or model an entire database.
 */
function db_fixture_literal(string $sql, bool $noBackslashEscapes = false): array {
    if (preg_match("/\\A_utf8mb4\\s+X'([0-9a-f]*)'/i", $sql, $match) === 1) {
        $value = hex2bin($match[1]);
        if ($value === false) { throw new RuntimeException('Invalid hex literal'); }
        return ['value' => $value, 'trailing' => substr($sql, strlen($match[0]))];
    }
    if (!str_starts_with($sql, "'")) {
        return ['value' => null, 'trailing' => $sql];
    }
    $value = '';
    for ($i = 1, $len = strlen($sql); $i < $len; $i++) {
        if (!$noBackslashEscapes && $sql[$i] === '\\' && $i + 1 < $len) {
            $escape = $sql[++$i];
            $value .= match ($escape) {
                '0' => "\0", 'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'Z' => "\x1a",
                default => $escape,
            };
        } elseif ($sql[$i] !== "'") {
            $value .= $sql[$i];
        } elseif ($i + 1 < $len && $sql[$i + 1] === "'") {
            $value .= "'";
            $i++;
        } else {
            return ['value' => $value, 'trailing' => substr($sql, $i + 1)];
        }
    }
    throw new RuntimeException('Unterminated SQL literal');
}

/** Observe header/connection behavior with no tables; all errors propagate. */
function db_fixture_empty_dump(string $database = 'configured', int $budget = 52428800): array {
    $output = '';
    dump_database(new Credentials('user', 'pass', 'host', 'configured'), $database,
        function(string $chunk) use (&$output): int { $output .= $chunk; return strlen($output); }, $budget);
    return ['output' => $output, 'database' => $GLOBALS['db_fixture_connected_database']];
}

/** Canned driver responses only; the real dumper builds and emits all SQL. */
function db_fixture_dump(int $budget = 52428800, string $database = 'configured',
                         bool $cumulative = false, ?int $failAt = null): array {
    $rows = [];
    for ($id = 1; $id <= 301; $id++) { $rows[] = ['id' => (string)$id, 'name' => 'row' . $id]; }
    $GLOBALS['db_fixture_query_rows'] = [
        'SHOW TABLES' => [["Tables_in_$database" => 'records'], ["Tables_in_$database" => 'extras']],
        'SELECT count(*) as count FROM records' => [['count' => '301']],
        'SHOW CREATE TABLE records' => [['Create Table' => 'CREATE TABLE records (id INT, name TEXT)']],
        'SELECT * FROM records LIMIT 300 OFFSET 0' => array_slice($rows, 0, 300),
        'SELECT * FROM records LIMIT 1 OFFSET 300' => array_slice($rows, 300),
        'SELECT count(*) as count FROM extras' => [['count' => '1']],
        'SHOW CREATE TABLE extras' => [['Create Table' => 'CREATE TABLE extras (id INT)']],
        'SELECT * FROM extras LIMIT 1 OFFSET 0' => [['id' => '7']],
    ];
    $output = '';
    $chunks = [];
    $calls = 0;
    $cred = new Credentials('user', 'pass', 'host', 'configured');
    $offsets = dump_database($cred, $database,
        function(string $chunk) use (&$output, &$chunks, &$calls, $cumulative, $failAt): int {
            $call = $calls++;
            if ($call === $failAt) { return -1; }
            $output .= $chunk;
            $chunks[] = $chunk;
            return $cumulative ? strlen($output) : strlen($chunk);
        }, $budget);
    return ['output' => $output, 'chunks' => $chunks, 'calls' => $calls,
        'offsets' => array_map(fn($offset) => [$offset->table, $offset->offset, $offset->is_table_complete()], $offsets),
        'queries' => $GLOBALS['db_fixture_last_connection']->queries,
        'database' => $GLOBALS['db_fixture_connected_database'], 'credential_database' => $cred->db_name];
}

function db_fixture_run(string $case): mixed {
    switch ($case) {
        case 'quote_configured_mode':
            $handle = new mysqli(); // Starts with NO_BACKSLASH_ESCAPES enabled.
            $db = DB::from($handle);
            $mode = in_array('NO_BACKSLASH_ESCAPES', explode(',', $handle->sql_mode), true);
            return db_fixture_literal(quote("x' OR 1=1 -- "), $mode);
        case 'quote_numeric_string':
            return db_fixture_literal(quote('00123'));
        case 'quote_mode_matrix':
            $inputs = ['', 'plain text', "'", '\\', "\\' OR 1=1 -- ", "x'); DROP TABLE records; -- ",
                "nul\0 newline\n return\r tab\t eof\x1a quote\"", 'café 中文 😀'];
            $rows = [];
            foreach ([false, true] as $initialMode) {
                $handle = new mysqli();
                $handle->sql_mode = $initialMode ? 'ANSI_QUOTES,NO_BACKSLASH_ESCAPES,STRICT_TRANS_TABLES' : 'ANSI_QUOTES,STRICT_TRANS_TABLES';
                $db = DB::from($handle);
                $mode = in_array('NO_BACKSLASH_ESCAPES', explode(',', $handle->sql_mode), true);
                foreach ($inputs as $input) {
                    $rows[] = ['input' => $input, 'no_backslash_escapes' => $mode, 'charset' => $handle->charset,
                        'decoded' => db_fixture_literal(quote($input), $mode)];
                }
            }
            return $rows;
        case 'quote_stringable':
            $object = new class {
                public function __toString(): string { return "\\' OR 1=1 -- "; }
            };
            return db_fixture_literal(quote($object));
        case 'quote_array':
            $inputs = ["x' OR 1=1 -- ", 'two,three', '中文 😀'];
            $remaining = quote($inputs);
            $decoded = [];
            foreach ($inputs as $index => $input) {
                $literal = db_fixture_literal($remaining);
                $decoded[] = $literal['value'];
                $remaining = $literal['trailing'];
                if ($index < count($inputs) - 1) {
                    if (!str_starts_with($remaining, ',')) { break; }
                    $remaining = substr($remaining, 1);
                }
            }
            return ['values' => $decoded, 'trailing' => $remaining];
        case 'quote_exact_escapes':
            return quote("\\'\"\0\n\r\t\x08\x1a");
        case 'quote_builder_values':
            $input = "x\\' OR 1=1 -- \0\n\r\x1a 中文 😀";
            $db = new DbStatementProbe(new mysqli());
            $statements = [
                \ThreadFin\DB\glue(['text' => $input]),
                where_clause(['text' => $input]),
                $db->template('SELECT {text}', ['text' => $input]),
                $db->insertStatement(['text' => $input], DB_DUPLICATE_IGNORE),
            ];
            ($db->insert_fn('records'))(['text' => $input]);
            ($db->upsert_fn('records'))(['text' => $input]);
            $bulk = $db->bulk_fn('records', ['text' => 'text']);
            $bulk(['text' => $input]);
            $bulk();
            $db->update('records', ['text' => $input], ['id' => 7]);
            $db->store('records', new DbValueRecord($input));
            return ['literal' => quote($input), 'statements' => array_merge($statements, $db->statements)];
        case 'connection_setup_new':
        case 'connection_setup_wrapped':
            if ($case === 'connection_setup_new') {
                $db = DB::connect('host', 'user', 'pass', 'configured');
                $handle = $GLOBALS['db_fixture_last_connection'];
            } else {
                $handle = new mysqli();
                $db = DB::from($handle);
            }
            $db->unsafe_raw('UPDATE records SET id = 7');
            return ['connected' => $db->connected(), 'charset' => $handle->charset,
                'modes' => explode(',', $handle->sql_mode), 'calls' => $handle->calls];
        case 'connection_setup_empty_mode':
            $handle = new mysqli();
            $handle->sql_mode = '';
            $db = DB::from($handle);
            return ['connected' => $db->connected(), 'mode' => $handle->sql_mode];
        case 'connection_setup_failures':
            $results = [];
            foreach (['charset_false', 'charset_exception', 'mode_false', 'mode_exception'] as $failure) {
                foreach (['new', 'wrapped'] as $factory) {
                    $GLOBALS['db_fixture_setup_failure'] = $failure;
                    if ($factory === 'new') {
                        $db = DB::connect('host', 'user', 'pass', 'configured');
                        $handle = $GLOBALS['db_fixture_last_connection'];
                    } else {
                        $handle = new mysqli();
                        $db = DB::from($handle);
                    }
                    $results[] = ['failure' => $failure, 'factory' => $factory,
                        'connected' => $db->connected(), 'closed' => $handle->closed,
                        'errors' => $db->errors, 'queries' => $handle->queries];
                }
            }
            return $results;
        case 'upsert_associative':
            // Supply [] explicitly so null default cannot mask the backwards guard.
            return (new DbStatementProbe())->insertStatement(['name' => 'bob'], DB_DUPLICATE_UPDATE, []);
        case 'upsert_defaults':
            return (new DbStatementProbe())->insert('records', ['name' => 'bob'], DB_DUPLICATE_UPDATE);
        case 'duplicate_reject_lists':
            $rejected = [];
            foreach ([['bob'], []] as $data) {
                try {
                    (new DbStatementProbe())->insertStatement($data, DB_DUPLICATE_UPDATE, []);
                    $rejected[] = false;
                } catch (RuntimeException $error) {
                    $rejected[] = true;
                }
            }
            return $rejected;
        case 'duplicate_no_update':
            return (new DbStatementProbe())->insertStatement(['id' => 7, 'name' => 'bob'],
                DB_DUPLICATE_UPDATE, ['id' => true]);
        case 'duplicate_if_null':
            return (new DbStatementProbe())->insertStatement(['name' => 'bob', 'other' => 'alice'],
                DB_DUPLICATE_UPDATE, null, ['name' => true]);
        case 'duplicate_quoted_and_falsey':
            $input = "x\\' OR 1=1 -- \0\n 中文 😀";
            $sql = (new DbStatementProbe())->insertStatement([
                'name' => $input, 'n' => 0, 'flag' => false, 'nil' => null, 'blank' => '', 'code' => '00123',
            ], DB_DUPLICATE_UPDATE);
            return ['sql' => $sql, 'literal' => quote($input)];
        case 'column':
            return db_fixture_result()->col('name')->value();
        case 'column_moves':
            $sql = db_fixture_result();
            $sql->next();
            $second = $sql->col('name')->value();
            $sql->rewind();
            return [$second, $sql->col('name')->value()];
        case 'column_falsey_values':
            $sql = SQL::fetch(new mysqli_result([['blank' => '', 'zero' => '0', 'nil' => null]]), 'SELECT');
            return [$sql->col('blank')->value(), $sql->col('zero')->value(), $sql->col('nil')->value()];
        case 'column_missing_empty':
            $sql = db_fixture_result();
            $empty = SQL::fetch(new mysqli_result([]), 'SELECT');
            return [$sql->col('missing')->value(), $empty->col('name')->value(), SQL::from(null)->col('name')->value()];
        case 'column_numeric_name':
            return SQL::fetch(new mysqli_result([['0' => 'alice']]), 'SELECT')->col('0')->value();
        case 'as_array':
            return db_fixture_result()->as_array();
        case 'as_array_single':
            return SQL::fetch(new mysqli_result([['name' => 'alice']]), 'SELECT')->as_array();
        case 'as_array_repeat':
            $sql = db_fixture_result();
            return [$sql->as_array(), $sql->as_array()];
        case 'as_array_after_next':
            $sql = db_fixture_result();
            $sql->next();
            $before = ['key' => $sql->key(), 'row' => $sql->current()];
            $rows = $sql->as_array();
            $after = ['key' => $sql->key(), 'row' => $sql->current()];
            $sql->next();
            return ['rows' => $rows, 'before' => $before, 'after' => $after,
                'next' => ['key' => $sql->key(), 'row' => $sql->current()]];
        case 'as_array_empty_error_closed':
            $empty = SQL::fetch(new mysqli_result([]), 'SELECT');
            $closed = db_fixture_result();
            $closed->close();
            return [$empty->as_array(), SQL::from(null)->as_array(), $closed->as_array()];
        case 'as_array_exhausted':
            $sql = db_fixture_result();
            foreach ($sql as $row) {} // Exhaust the cursor through the iterator API.
            return ['rows' => $sql->as_array(), 'key' => $sql->key(), 'valid' => $sql->valid()];
        case 'seek':
            $sql = db_fixture_result();
            $sql->seek(2);
            return ['key' => $sql->key(), 'current' => $sql->current()];
        case 'array_cursor':
            $sql = db_fixture_result();
            $sql->rewind();
            $unused = $sql[2];
            $sql->next();
            return ['key' => $sql->key(), 'current' => $sql->current()];
        case 'seek_moves':
            $sql = db_fixture_result();
            $sql->seek(1);
            $atSecond = [$sql->key(), $sql->col('name')->value()];
            $sql->next();
            $atThird = [$sql->key(), $sql->col('name')->value()];
            $sql->seek(0);
            $atFirst = [$sql->key(), $sql->col('name')->value()];
            $sql->next();
            return [$atSecond, $atThird, $atFirst, [$sql->key(), $sql->col('name')->value()]];
        case 'seek_after_exhaustion':
            $sql = db_fixture_result();
            foreach ($sql as $row) {}
            $sql->seek(1);
            $selected = [$sql->key(), $sql->col('name')->value(), $sql->valid()];
            $sql->next();
            return [$selected, [$sql->key(), $sql->col('name')->value(), $sql->valid()]];
        case 'invalid_seek_preserves_cursor':
            $sql = db_fixture_result();
            $sql->next();
            $rejected = [];
            foreach ([-1, 3] as $offset) {
                try {
                    $sql->seek($offset);
                    $rejected[] = false;
                } catch (OutOfBoundsException|ValueError $error) {
                    $rejected[] = true;
                }
            }
            $before = [$sql->key(), $sql->col('name')->value()];
            $sql->next();
            return [$rejected, $before, [$sql->key(), $sql->col('name')->value()]];
        case 'array_cursor_after_next':
            $sql = db_fixture_result();
            $sql->next();
            $read = [$sql[0], $sql[2], $sql[0]];
            $before = [$sql->key(), $sql->col('name')->value()];
            $sql->next();
            return [$read, $before, [$sql->key(), $sql->col('name')->value()]];
        case 'array_cursor_at_last':
            $sql = db_fixture_result();
            $sql->next();
            $sql->next();
            $read = $sql[0];
            $before = [$sql->key(), $sql->col('name')->value()];
            $sql->next();
            return [$read, $before, [$sql->key(), $sql->valid(), $sql->col('name')->value()]];
        case 'array_cursor_exhausted':
            $sql = db_fixture_result();
            foreach ($sql as $row) {}
            $read = $sql[1];
            $before = [$sql->key(), $sql->valid(), $sql->col('name')->value()];
            $sql->next();
            return [$read, $before, [$sql->key(), $sql->valid(), $sql->col('name')->value()]];
        case 'array_cursor_single':
            $sql = SQL::fetch(new mysqli_result([['name' => 'alice']]), 'SELECT');
            $read = [$sql[0], $sql[0]];
            $before = [$sql->key(), $sql->col('name')->value()];
            $sql->next();
            return [$read, $before, [$sql->key(), $sql->valid(), $sql->col('name')->value()]];
        case 'from_length':
            return SQL::from(db_fixture_rows())->valid();
        case 'from_count':
            return count(SQL::from(db_fixture_rows()));
        case 'from_access':
            return SQL::from(db_fixture_rows())[1];
        case 'from_iteration':
            return iterator_to_array(SQL::from(db_fixture_rows()));
        case 'from_current':
            $sql = SQL::from(db_fixture_rows());
            return [$sql->key(), $sql->current(), $sql->col('name')->value(), $sql->valid(), $sql->empty()];
        case 'from_moves_and_repeat':
            $sql = SQL::from(db_fixture_rows());
            $sql->next();
            $second = [$sql->key(), $sql->current()];
            $sql->rewind();
            $first = [$sql->key(), $sql->current()];
            $rows = iterator_to_array($sql);
            $end = [$sql->key(), $sql->valid(), $sql->col('name')->value(), count($sql)];
            return [$second, $first, $rows, $end, iterator_to_array($sql)];
        case 'from_random_reads':
            $sql = SQL::from(db_fixture_rows());
            $sql->next();
            $read = [$sql[2], $sql[0], $sql[2]];
            $before = [$sql->key(), $sql->current()];
            $rejected = [];
            foreach ([-1, 3] as $offset) {
                try {
                    $unused = $sql[$offset];
                    $rejected[] = false;
                } catch (OutOfBoundsException|ValueError $error) {
                    $rejected[] = true;
                }
            }
            $sql->next();
            return [$read, $before, $rejected, [$sql->key(), $sql->current()]];
        case 'from_seek':
            $sql = SQL::from(db_fixture_rows());
            foreach ($sql as $row) {}
            $sql->seek(1);
            $second = [$sql->key(), $sql->current(), $sql->valid()];
            $rejected = [];
            foreach ([-1, 3] as $offset) {
                try {
                    $sql->seek($offset);
                    $rejected[] = false;
                } catch (OutOfBoundsException|ValueError $error) {
                    $rejected[] = true;
                }
            }
            $sql->next();
            $third = [$sql->key(), $sql->current()];
            $sql->seek(0);
            return [$second, $rejected, $third, [$sql->key(), $sql->current()]];
        case 'from_empty':
            $observations = [];
            foreach ([[], null] as $rows) {
                $sql = SQL::from($rows);
                $observations[] = [count($sql), $sql->empty(), $sql->valid(), $sql->as_array(),
                    iterator_to_array($sql), $sql->col('name')->value()];
            }
            return $observations;
        case 'from_falsey_row':
            $row = ['blank' => '', 'zero' => 0, 'flag' => false, 'nil' => null];
            $sql = SQL::from([$row]);
            $initial = [$sql->current(), $sql[0], $sql->col('blank')->value(), $sql->col('nil')->value()];
            $rows = iterator_to_array($sql);
            $end = [$sql->key(), $sql->valid(), $sql->col('blank')->value()];
            $sql->rewind();
            return [$initial, $rows, $end, $sql->current()];
        case 'from_as_array':
            $sql = SQL::from(db_fixture_rows());
            $sql->next();
            $arrays = [$sql->as_array(), $sql->as_array()];
            $second = [$sql->key(), $sql->current()];
            $sql->next();
            $third = [$sql->key(), $sql->current()];
            $sql->next();
            $exhausted = $sql->as_array();
            return [$arrays, $second, $third, $exhausted,
                [$sql->key(), $sql->valid(), $sql->col('name')->value()]];
        case 'from_nonsequential_keys':
            $rows = db_fixture_rows();
            $sql = SQL::from([4 => $rows[0], 'other' => $rows[1], 9 => $rows[2]]);
            return [count($sql), $sql->current(), $sql[1], $sql->as_array(), iterator_to_array($sql)];
        case 'negative_exists':
            return isset(db_fixture_result()[-1]);
        case 'past_end_exists':
            return isset(db_fixture_result()[3]);
        case 'exists_bounds':
            $observations = [];
            foreach ([db_fixture_result(), SQL::from(db_fixture_rows())] as $sql) {
                $checks = [];
                foreach ([-2, -1, 0, 1, 2, 3, 4, PHP_INT_MAX, '-1', '0', '2', '3'] as $offset) {
                    $checks[] = [$sql->offsetExists($offset), isset($sql[$offset])];
                }
                $observations[] = $checks;
            }
            return $observations;
        case 'exists_empty_and_closed':
            $closed = db_fixture_result();
            $closed->close();
            $observations = [];
            foreach ([SQL::fetch(new mysqli_result([]), 'SELECT'), SQL::from([]), SQL::from(null),
                      new SQL(), $closed] as $sql) {
                $checks = [];
                foreach ([-1, 0, 1] as $offset) {
                    $checks[] = [$sql->offsetExists($offset), isset($sql[$offset])];
                }
                $observations[] = $checks;
            }
            return $observations;
        case 'exists_preserves_cursor':
            $observations = [];
            foreach ([db_fixture_result(), SQL::from(db_fixture_rows())] as $sql) {
                $sql->next();
                $checks = [isset($sql[-1]), isset($sql[0]), isset($sql[2]), isset($sql[3])];
                $current = [$sql->key(), $sql->current()];
                $sql->next();
                $observations[] = [$checks, $current, [$sql->key(), $sql->current()]];
            }
            return $observations;
        case 'exists_after_exhaustion':
            $observations = [];
            foreach ([db_fixture_result(), SQL::from(db_fixture_rows())] as $sql) {
                foreach ($sql as $row) {}
                $checks = [isset($sql[-1]), isset($sql[0]), isset($sql[2]), isset($sql[3])];
                $state = [$sql->key(), $sql->valid(), $sql->col('name')->value()];
                $sql->next();
                $observations[] = [$checks, $state, [$sql->key(), $sql->valid(), $sql->col('name')->value()]];
            }
            return $observations;
        case 'exists_single_falsey_row':
            $rows = [['nil' => null, 'zero' => 0, 'blank' => '', 'flag' => false]];
            $observations = [];
            foreach ([SQL::fetch(new mysqli_result($rows), 'SELECT'), SQL::from($rows)] as $sql) {
                $observations[] = [isset($sql[-1]), isset($sql[0]), isset($sql[1]), $sql[0]];
            }
            return $observations;
        case 'invalid_read':
            try {
                $unused = db_fixture_result()[3];
                return false;
            } catch (OutOfBoundsException|ValueError $error) {
                return true;
            }
        case 'map_available':
            return is_callable([db_fixture_result(), 'map']);
        case 'reduce_available':
            return is_callable([db_fixture_result(), 'reduce']);
        case 'map_rows':
            $observations = [];
            foreach ([db_fixture_result(), SQL::from(db_fixture_rows())] as $sql) {
                $observations[] = [$sql->map(fn(array $row) => $row['name']),
                    $sql->map(fn(array $row) => match ($row['id']) { '1' => 0, '2' => false, '3' => null })];
            }
            return $observations;
        case 'reduce_order_and_seed':
            $observations = [];
            foreach ([db_fixture_result(), SQL::from(db_fixture_rows())] as $sql) {
                $fn = fn(string $carry, array $row) => $carry . $row['name'] . ',';
                $observations[] = [$sql->reduce($fn), $sql->reduce($fn, 'prefix:')];
            }
            return $observations;
        case 'reduce_mixed_values':
            $observations = [];
            foreach ([db_fixture_result(), SQL::from(db_fixture_rows())] as $sql) {
                $observations[] = [
                    $sql->reduce(fn(int $carry, array $row) => $carry + (int)$row['id'], 0),
                    $sql->reduce(fn(array $carry, array $row) => [...$carry, $row['id']], ['seed']),
                    $sql->reduce(fn($carry, array $row) => 0, 99),
                    $sql->reduce(fn($carry, array $row) => false, true),
                    $sql->reduce(fn($carry, array $row) => null, 'seed'),
                ];
            }
            return $observations;
        case 'map_reduce_advanced':
            $observations = [];
            foreach ([db_fixture_result(), SQL::from(db_fixture_rows())] as $sql) {
                $sql->next();
                $mapped = $sql->map(fn(array $row) => $row['name']);
                $reduced = $sql->reduce(fn(int $carry, array $row) => $carry + (int)$row['id'], 0);
                $repeated = $sql->map(fn(array $row) => $row['name']);
                $state = [$sql->key(), $sql->current()];
                $sql->next();
                $observations[] = [$mapped, $reduced, $repeated, $state, [$sql->key(), $sql->current()]];
            }
            return $observations;
        case 'map_reduce_exhausted':
            $observations = [];
            foreach ([db_fixture_result(), SQL::from(db_fixture_rows())] as $sql) {
                foreach ($sql as $row) {}
                $mapped = $sql->map(fn(array $row) => $row['name']);
                $reduced = $sql->reduce(fn(int $carry, array $row) => $carry + (int)$row['id'], 0);
                $state = [$sql->key(), $sql->valid(), $sql->col('name')->value()];
                $sql->next();
                $observations[] = [$mapped, $reduced, $state, [$sql->key(), $sql->valid(), $sql->col('name')->value()]];
            }
            return $observations;
        case 'map_reduce_at_eof':
            $observations = [];
            foreach ([db_fixture_rows(), [['id' => '1', 'name' => 'alice']]] as $rows) {
                foreach ([SQL::fetch(new mysqli_result($rows), 'SELECT'), SQL::from($rows)] as $sql) {
                    $sql->seek(count($rows) - 1);
                    $mapped = $sql->map(fn(array $row) => $row['name']);
                    $reduced = $sql->reduce(fn(int $carry, array $row) => $carry + (int)$row['id'], 0);
                    $state = [$sql->key(), $sql->col('name')->value()];
                    $sql->next();
                    $observations[] = [$mapped, $reduced, $state,
                        [$sql->key(), $sql->valid(), $sql->col('name')->value()]];
                }
            }
            return $observations;
        case 'map_reduce_empty':
            $closed = db_fixture_result();
            $closed->close();
            $observations = [];
            foreach ([SQL::fetch(new mysqli_result([]), 'SELECT'), SQL::from([]), SQL::from(null),
                      new SQL(), $closed] as $sql) {
                $calls = 0;
                $fn = function(...$args) use (&$calls) { $calls++; return 'unexpected'; };
                $observations[] = [$sql->map($fn), $sql->reduce($fn),
                    $sql->reduce($fn, ['seed']), $sql->reduce($fn, null), $calls];
            }
            return $observations;
        case 'map_reduce_callback_exception':
            $observations = [];
            foreach (['map', 'reduce'] as $method) {
                foreach ([db_fixture_result(), SQL::from(db_fixture_rows())] as $sql) {
                    $sql->next();
                    $calls = 0;
                    $fn = function(array $row) use (&$calls) {
                        $calls++;
                        if ($row['id'] === '2') { throw new RuntimeException('callback failed'); }
                        return $row['name'];
                    };
                    try {
                        if ($method === 'map') {
                            $sql->map($fn);
                        } else {
                            $sql->reduce(fn($carry, array $row) => $fn($row), '');
                        }
                        $message = 'no exception';
                    } catch (RuntimeException $error) {
                        $message = $error->getMessage();
                    }
                    $state = [$sql->key(), $sql->current()];
                    $sql->next();
                    $observations[] = [$message, $calls, $state, [$sql->key(), $sql->current()]];
                }
            }
            return $observations;
        case 'dump_empty_methods':
            $output = '';
            $offsets = dump_database(new Credentials('user', 'pass', 'host', 'configured'), 'configured',
                function(string $chunk) use (&$output): int { $output .= $chunk; return strlen($output); });
            return [$offsets, str_contains($output, "SET NAMES 'utf8mb4';\n"), strlen($output) > 0];
        case 'dump_nonempty_methods':
            $GLOBALS['db_fixture_query_rows'] = [
                'SHOW TABLES' => [['Tables_in_configured' => 'records']],
                'SELECT count(*) as count FROM records' => [['count' => '3']],
                'SHOW CREATE TABLE records' => [['Table' => 'records', 'Create Table' => 'CREATE TABLE records (id INT, name TEXT)']],
                'SELECT * FROM records LIMIT 3 OFFSET 0' => db_fixture_rows(),
            ];
            $output = '';
            $offsets = dump_database(new Credentials('user', 'pass', 'host', 'configured'), 'configured',
                function(string $chunk) use (&$output): int { $output .= $chunk; return strlen($output); });
            return ['output' => $output, 'offsets' => array_map(fn($offset) =>
                [$offset->table, $offset->is_table_complete()], $offsets),
                'queries' => $GLOBALS['db_fixture_last_connection']->queries];
        case 'dump_header':
            return db_fixture_empty_dump()['output'];
        case 'dump_budget':
            return strlen(db_fixture_empty_dump('configured', 10)['output']);
        case 'dump_database_name':
            return db_fixture_empty_dump('requested')['database'];
        case 'dump_small_budgets':
            $full = db_fixture_dump();
            $observations = [];
            foreach ([0, 1, 10, strlen($full['chunks'][0]) - 1] as $budget) {
                $dump = db_fixture_dump($budget);
                $observations[] = [$dump['output'], $dump['calls'], $dump['offsets'],
                    in_array('SELECT count(*) as count FROM records', $dump['queries'], true)];
            }
            return $observations;
        case 'dump_exact_header_budget':
            $full = db_fixture_dump();
            $budget = strlen($full['chunks'][0]);
            $dump = db_fixture_dump($budget);
            return [strlen($dump['output']) === $budget, $dump['calls'], $dump['offsets'],
                in_array('SELECT count(*) as count FROM records', $dump['queries'], true)];
        case 'dump_ddl_budget':
            $full = db_fixture_dump();
            $budget = strlen(implode('', array_slice($full['chunks'], 0, 3)));
            $dump = db_fixture_dump($budget);
            return [strlen($dump['output']) <= $budget, $dump['calls'], $dump['offsets'],
                str_ends_with($dump['output'], "DROP TABLE IF EXISTS `records`;\n"),
                in_array('SELECT * FROM records LIMIT 300 OFFSET 0', $dump['queries'], true),
                in_array('SELECT count(*) as count FROM extras', $dump['queries'], true)];
        case 'dump_batch_budget':
            $full = db_fixture_dump();
            $budget = strlen(implode('', array_slice($full['chunks'], 0, 5)));
            $dump = db_fixture_dump($budget);
            return [strlen($dump['output']) <= $budget, $dump['calls'], $dump['offsets'],
                str_ends_with($dump['output'], "('300','row300');\n\n"),
                str_contains($dump['output'], "('301','row301')"),
                in_array('SELECT count(*) as count FROM extras', $dump['queries'], true)];
        case 'dump_sufficient_budget':
            $observations = [];
            foreach ([false, true] as $cumulative) {
                $dump = db_fixture_dump(52428800, 'configured', $cumulative);
                $observations[] = [$dump['offsets'], $dump['calls'],
                    str_contains($dump['output'], "('301','row301');\n\n"),
                    str_contains($dump['output'], "INSERT IGNORE INTO extras VALUES('7');\n\n")];
            }
            return $observations;
        case 'dump_header_writer_failure':
            $dump = db_fixture_dump(52428800, 'configured', false, 0);
            return [$dump['output'], $dump['calls'], $dump['offsets'],
                in_array('SELECT count(*) as count FROM records', $dump['queries'], true)];
        case 'dump_ddl_writer_failure':
            $observations = [];
            foreach ([1, 2, 3] as $failAt) {
                $dump = db_fixture_dump(52428800, 'configured', false, $failAt);
                $observations[] = [$dump['calls'], $dump['offsets'],
                    in_array('SELECT * FROM records LIMIT 300 OFFSET 0', $dump['queries'], true),
                    in_array('SELECT count(*) as count FROM extras', $dump['queries'], true)];
            }
            return $observations;
        case 'dump_batch_writer_failure':
            $dump = db_fixture_dump(52428800, 'configured', false, 5);
            return [$dump['calls'], $dump['offsets'],
                str_contains($dump['output'], "('301','row301')"),
                in_array('SELECT count(*) as count FROM extras', $dump['queries'], true)];
        case 'dump_negative_budget':
            try {
                db_fixture_dump(-1);
                return false;
            } catch (InvalidArgumentException $error) { return true; }
        case 'dump_requested_nonempty_database':
            $dump = db_fixture_dump(52428800, 'requested');
            return [$dump['database'], $dump['credential_database'], $dump['offsets'],
                str_contains($dump['output'], 'Database export of (requested)')];
        case 'store_connection':
            return (new DbStatementProbe(new mysqli()))->store('records', (object)['name' => 'bob']);
        case 'attribute_no_update':
            $db = new DbAttributeProbe(new mysqli());
            $db->store('records', new DbNoUpdateRecord());
            return $db->extracted['no_update'];
        case 'attribute_if_null':
            $db = new DbAttributeProbe(new mysqli());
            $db->store('records', new DbIfNullRecord());
            return $db->extracted['if_null'];
        case 'store_no_update_sql':
        case 'store_if_null_sql':
        case 'store_not_null_sql':
        case 'store_dynamic_sql':
        case 'store_public_instance_sql':
            $record = match ($case) {
                'store_no_update_sql' => new DbNoUpdateRecord(),
                'store_if_null_sql' => new DbIfNullRecord(),
                'store_not_null_sql' => new DbNotNullRecord(),
                'store_dynamic_sql' => (object)['name' => 'bob', 'code' => '00123'],
                'store_public_instance_sql' => new DbPublicInstanceRecord(),
            };
            $mode = in_array($case, ['store_dynamic_sql', 'store_public_instance_sql'], true)
                ? DB_DUPLICATE_IGNORE : DB_DUPLICATE_UPDATE;
            $db = new DbStatementProbe(new mysqli());
            $id = $db->store('records', $record, $mode);
            return ['id' => $id, 'sql' => $db->statements[0]];
        case 'store_disconnected':
            try {
                (new DbStatementProbe())->store('records', new DbValueRecord('bob'));
                return false;
            } catch (AssertionError $error) {
                return true;
            }
        case 'simulate_write':
            $handle = new mysqli();
            $db = DB::from($handle)->enable_simulation(true);
            $handle->queries = []; // Observe simulation IO, not required connection setup.
            $status = $db->unsafe_raw('INSERT INTO records VALUES (1)');
            return ['status' => $status, 'queries' => $handle->queries, 'logs' => $db->logs, 'errors' => $db->errors];
        case 'simulate_read':
            $handle = new mysqli();
            $db = DB::from($handle)->enable_simulation(true);
            $handle->queries = []; // Observe simulation IO, not required connection setup.
            $db->fetch('SELECT name FROM records');
            return ['queries' => $handle->queries, 'logs' => $db->logs, 'errors' => $db->errors];
        case 'simulate_return_modes':
            $handle = new mysqli();
            $db = DB::from($handle)->enable_simulation(true);
            $handle->queries = [];
            $handle->affected_rows = 42;
            $handle->insert_id = 999;
            $handle->errno = 1064;
            $handle->error = 'stale driver error';
            $db->errors[] = 'existing diagnostic';
            $statuses = [];
            foreach ([DB_FETCH_SUCCESS, \ThreadFin\DB\DB_FETCH_NUM_ROWS, \ThreadFin\DB\DB_FETCH_INSERT_ID] as $mode) {
                $statuses[] = $db->unsafe_raw('INSERT INTO records VALUES (1)', $mode);
            }
            return ['statuses' => $statuses, 'queries' => $handle->queries, 'logs' => $db->logs,
                'errors' => $db->errors, 'last_stmt' => $db->last_stmt];
        case 'simulate_empty_read':
            $handle = new mysqli();
            $db = DB::from($handle)->enable_simulation(true);
            $handle->queries = [];
            $handle->errno = 1064;
            $handle->error = 'stale driver error';
            $sql = $db->fetch('SELECT name FROM records WHERE name = {name}', ['name' => "O'Reilly"]);
            return ['queries' => $handle->queries, 'logs' => $db->logs, 'errors' => $db->errors,
                'result' => [count($sql), $sql->empty(), $sql->valid(), $sql->as_array(),
                    iterator_to_array($sql), $sql->col('name')->value()],
                'sql' => db_fixture_property($sql, '_sql')];
        case 'simulate_value_builders':
            $handle = new mysqli();
            $db = DB::from($handle)->enable_simulation(true);
            $handle->queries = [];
            $statuses = [
                $db->insert('records', ['name' => 'alice']),
                $db->store('records', (object)['name' => 'bob']),
                ($db->insert_fn('records'))(['name' => 'carol']),
                ($db->upsert_fn('records'))(['name' => 'dan']),
                $db->update('records', ['name' => 'eve'], ['id' => 7]),
                $db->delete('records', ['id' => 8]),
            ];
            // Explicit flush keeps this independent of the separate buffering bug.
            $bulk = $db->bulk_fn('records', ['name' => 'name']);
            $bulk(['name' => 'frank']);
            $bulk();
            return ['statuses' => $statuses, 'queries' => $handle->queries, 'logs' => $db->logs, 'errors' => $db->errors];
        case 'simulate_replay_is_empty':
            $path = db_fixture_file();
            $handle = new mysqli();
            $db = DB::from($handle)->enable_replay($path)->enable_simulation(true);
            $handle->queries = [];
            $db->unsafe_raw('CREATE TABLE records (id INT)');
            $db->unsafe_raw('INSERT INTO records VALUES (1)');
            $db->fetch('SELECT name FROM records');
            $replay = db_fixture_property($db, '_replay');
            $db->close();
            return ['queries' => $handle->queries, 'logs' => $db->logs, 'errors' => $db->errors,
                'replay' => $replay, 'file' => file_get_contents($path)];
        case 'simulate_toggle_execution':
            $handle = new mysqli();
            $db = DB::from($handle)->enable_simulation(true);
            $handle->queries = [];
            $first = $db->unsafe_raw('INSERT INTO records VALUES (1)');
            $db->enable_simulation(false);
            $id = $db->unsafe_raw('INSERT INTO records VALUES (2)', \ThreadFin\DB\DB_FETCH_INSERT_ID);
            $real = $db->fetch('SELECT name FROM records')->col('name')->value();
            $db->enable_simulation(true);
            $simulated = $db->fetch('SELECT name FROM records')->as_array();
            return ['values' => [$first, $id, $real, $simulated], 'queries' => $handle->queries,
                'logs' => $db->logs, 'errors' => $db->errors];
        case 'simulate_logging_disabled':
            $handle = new mysqli();
            $db = DB::from($handle)->enable_simulation(true)->enable_log(false);
            $handle->queries = [];
            $status = $db->unsafe_raw('INSERT INTO records VALUES (1)');
            $rows = $db->fetch('SELECT name FROM records')->as_array();
            return [$status, $rows, $handle->queries, $db->logs, $db->errors];
        case 'simulate_disconnected_write':
            $db = DB::from(null)->enable_simulation(true);
            try {
                $db->unsafe_raw('INSERT INTO records VALUES (1)');
                return false;
            } catch (AssertionError $error) { return true; }
        case 'simulate_disabled_real_failures':
            $observations = [];
            foreach (['false', 'exception'] as $failure) {
                $handle = new mysqli();
                $db = DB::from($handle)->enable_simulation(true)->enable_simulation(false);
                $handle->queries = [];
                $GLOBALS['db_fixture_query_failures'] = ['BAD WRITE' => $failure, 'SELECT bad' => $failure];
                $status = $db->unsafe_raw('BAD WRITE');
                $rows = $db->fetch('SELECT bad')->as_array();
                $observations[] = [$status, $rows, $handle->queries, $db->errors, $db->logs];
                $db->errors = []; // Avoid the unrelated close/error-log regression in this observation.
            }
            return $observations;
        case 'null_placeholder':
            return (new DbStatementProbe())->template('SELECT {x}', ['x' => null]);
        case 'missing_placeholder':
            try {
                (new DbStatementProbe())->template('SELECT {x}', ['other' => 1]);
                return false;
            } catch (InvalidArgumentException|OutOfBoundsException $error) {
                return true;
            }
        case 'null_where':
            return where_clause(['name' => null]);
        case 'upsert_zero':
        case 'upsert_false':
            $db = new DbStatementProbe();
            ($db->upsert_fn('records'))(['flag' => $case === 'upsert_zero' ? 0 : false]);
            return $db->statements[0];
        case 'bulk_batch':
            $db = new DbStatementProbe();
            $insert = $db->bulk_fn('records', ['name' => 'name']);
            $insert(['name' => 'alice']);
            $insert(['name' => 'bob']);
            return $db->statements;
        case 'bulk_list_columns':
            $db = new DbStatementProbe();
            $insert = $db->bulk_fn('records', ['name', 'email']);
            $insert(['name' => 'bob', 'email' => 'b@example.test']);
            $insert();
            return $db->statements[0];
        case 'error_logging':
            $db = DB::from(new mysqli());
            $db->errors[] = '[BAD SQL] errno(1064) Syntax error near BAD';
            $db->close();
            return file_get_contents(SQL_ERROR_FILE);
        case 'replay_ddl':
            $path = db_fixture_file();
            $db = DB::from(new mysqli())->enable_replay($path);
            $db->unsafe_raw('CREATE TABLE records (id INT)');
            $db->close();
            return file_get_contents($path);
        case 'replay_rollback':
            $path = db_fixture_file();
            $db = DB::from(new mysqli())->enable_replay($path);
            $db->unsafe_raw('BEGIN');
            $db->unsafe_raw('INSERT INTO records VALUES (1)');
            $db->unsafe_raw('ROLLBACK');
            $db->close();
            $replay = file_get_contents($path);
            // Either preserve transaction boundaries or omit rolled-back writes.
            return !str_contains($replay, 'INSERT INTO records VALUES (1)') ||
                preg_match('/\bBEGIN\s*;.*INSERT INTO records VALUES \(1\)\s*;.*\bROLLBACK\s*;/s', $replay) === 1;
        case 'replay_close_twice':
            $path = db_fixture_file();
            $db = DB::from(new mysqli())->enable_replay($path);
            $db->unsafe_raw('INSERT INTO records VALUES (1)');
            $db->close();
            $db->close();
            return substr_count(file_get_contents($path), 'INSERT INTO records VALUES (1)');
        case 'stream_zero':
            $stream = fopen('php://memory', 'w+');
            try {
                stream_output_fn('0', $stream);
                rewind($stream);
                return stream_get_contents($stream);
            } finally { fclose($stream); }
        case 'stream_short_write':
            $stream = fopen('php://memory', 'w+');
            try {
                stream_output_fn('abcdef', $stream, fn($s, $data) => fwrite($s, substr($data, 0, 2)));
                rewind($stream);
                return stream_get_contents($stream);
            } finally { fclose($stream); }
        case 'stream_independent_totals':
            $first = fopen('php://memory', 'w+');
            $second = fopen('php://memory', 'w+');
            try {
                stream_output_fn('abc', $first);
                return stream_output_fn('x', $second);
            } finally { fclose($first); fclose($second); }
        case 'connection_failure_false':
            $GLOBALS['db_fixture_connection_failure'] = 'false';
            $db = DB::connect('host', 'user', 'pass', 'configured');
            return ['connected' => $db->connected(), 'closed' => $GLOBALS['db_fixture_last_connection']->closed,
                'errors' => $db->errors];
        case 'connection_failure':
            $GLOBALS['db_fixture_connection_failure'] = true;
            return DB::connect('host', 'user', 'pass', 'configured')->connected();
        case 'stored_sql':
            $stored = db_fixture_property(db_fixture_result(), '_sql');
            return is_string($stored) ? $stored : get_debug_type($stored);
        default:
            throw new InvalidArgumentException("Unknown regression case: $case");
    }
}

// Keep production echo/log output separate from the observation transport.
ob_start();
set_error_handler(function(int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) { return false; }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
try {
    $value = db_fixture_run($argv[1] ?? '');
    $report = ['value' => $value, 'error' => null];
} catch (Throwable $error) {
    $report = ['value' => null, 'error' => ['class' => get_class($error), 'message' => $error->getMessage()]];
} finally {
    restore_error_handler();
    $report['output'] = ob_get_clean();
    foreach ($fixtureFiles as $path) { unlink($path); }
}
echo json_encode($report, JSON_THROW_ON_ERROR);
