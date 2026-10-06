<?php declare(strict_types=1);
namespace ThreadFin\DB;

/** Per-invocation work plus cumulative committed progress; no raw rows or credentials. */
final class DumpReport {
    public function __construct(
        public readonly bool $complete,
        public readonly string $status,
        public readonly string $phase,
        public readonly int $rows,
        public readonly int $position,
        public readonly int $bytes_written,
        public readonly int $batches_written,
        public readonly int $required_bytes
    ) {}
}

/**
 * Opt-in v1 export, not a portable snapshot. source_frozen=true acknowledges that
 * ALL selected data/schema remain frozen from first call until completion; source_id
 * identifies that freeze (nonsecret). source_idle=true separately acknowledges an
 * exclusively owned idle source connection with no external transaction/snapshot
 * on EVERY invocation (fresh connection recommended). Autocommit=1 is checked,
 * but cannot prove absence of an external transaction; caller must ensure it.
 * No row-change detection or locks are promised.
 * Only InnoDB base tables, single integer PKs, integer/decimal, character/binary and
 * DATE/DATETIME/TIME/YEAR or YEAR(4) columns (not YEAR(2)/unknown YEAR variants).
 * No triggers, FKs, CHECKs (including MariaDB JSON
 * aliases), native JSON, generated columns, BIT, floats, spatial, ENUM/SET/TIMESTAMP.
 * Requires direct TRIGGER privilege on each selected table (global/schema/table
 * grants); SELECT alone hides triggers and is insufficient. Role-only visibility
 * and account names outside the conservative ASCII account grammar reject.
 * ZEROFILL columns and non-default SQL modes outside the explicit allowlist reject.
 * Export into a fresh target schema using a fresh idle restore session and the
 * same compatible server semantics. Restore is not atomic: DDL/autocommit may
 * commit prior work. SQL mode/autocommit are restored; utf8mb4 remains established.
 * Tables default to all; explicit lists export ONLY those tables. No views/routines/
 * events/grants or database creation. Never use this as a whole-server backup.
 *
 * Paths are distinct local regular files in trusted directories, no symlinks or
 * wrappers. Caller must not replace paths or change permissions during execution.
 * Cooperating writers lock output and a stable checkpoint .lock sidecar. Do not
 * delete that sidecar while writers may be active. Atomic same-directory rename
 * publishes flushed batches. Hash once on resume, incrementally thereafter.
 * Detected failure/process restart only: no fsync/directory-fsync/power-loss claim.
 * An absent checkpoint with nonempty output is an orphan and ALWAYS rejects;
 * inspect/remove BOTH files explicitly to restart, never guess whether to append.
 * Checkpoints are integrity-checked, not authenticated against malicious editing.
 * max_bytes/max_batches are invocation budgets; headers/DDL/footer count as units.
 * An insufficient budget returns status=budget and required_bytes for the next
 * complete unit; callers must increase it, not spin retrying the same tiny budget.
 * Memory is bounded by one row batch, not by bytes (large cells remain resident).
 */
final class ResumableDump {
    // Instance paths remain local to preflight and are hashed, never exported.
    // Socket/datadir distinguish co-hosted socket-only servers sharing port zero.
    private const SOURCE_SQL = 'SELECT DATABASE() AS db, @@hostname AS host, @@port AS port, @@version AS version, @@socket AS socket, @@datadir AS datadir, @@server_id AS server_id, @@SESSION.sql_mode AS mode, @@SESSION.sql_quote_show_create AS quote_create, @@SESSION.autocommit AS autocommit';
    private const INTEGER_TYPES = ['tinyint','smallint','mediumint','int','bigint'];

    public static function run(callable $read, string $output, string $checkpoint, array $options): DumpReport {
        $o = self::options($options);
        $output = self::path($output); $checkpoint = self::path($checkpoint);
        $lockPath = self::path($checkpoint . '.lock');
        if ($output === $checkpoint || $output === $lockPath || self::sameFile($output, $checkpoint) || self::sameFile($output, $lockPath)) {
            throw new \InvalidArgumentException('Dump paths must be distinct');
        }
        // Validate every selected table before creating output or a checkpoint.
        [$source, $tables] = self::schema($read, $o);
        $fingerprints = ['source'=>self::digest([$source, $o['source_id']]), 'schema'=>self::digest($tables),
            'options'=>self::digest(['batch_rows'=>$o['batch_rows'], 'tables'=>array_column($tables,'name'), 'format'=>1, 'source_frozen'=>true, 'source_idle'=>true])];
        $lock = self::open($lockPath);
        $sink = null;
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) { throw new \RuntimeException('Checkpoint is busy'); }
            $sink = self::open($output);
            if (!flock($sink, LOCK_EX | LOCK_NB)) { throw new \RuntimeException('Dump output is busy'); }
            $stat = fstat($sink); $identity = ['dev'=>(string)$stat['dev'], 'ino'=>(string)$stat['ino']];
            $hash = hash_init('sha256');
            if (is_file($checkpoint)) {
                $state = self::load($checkpoint, $fingerprints, $identity, $tables);
                if ($stat['size'] < $state['position']) { throw new \RuntimeException('Dump output is shorter than checkpoint'); }
                $remaining = $state['position'];
                while ($remaining > 0) {
                    $part = fread($sink, min(65536, $remaining));
                    if ($part === false || $part === '') { throw new \RuntimeException('Cannot validate dump prefix'); }
                    hash_update($hash, $part); $remaining -= strlen($part);
                }
                if (!hash_equals($state['prefix'], hash_final(hash_copy($hash)))) { throw new \RuntimeException('Dump output hash mismatch'); }
                if (!ftruncate($sink, $state['position']) || fseek($sink, $state['position']) !== 0 || !fflush($sink)) {
                    throw new \RuntimeException('Cannot recover dump output');
                }
            } else {
                if ($stat['size'] !== 0) { throw new \RuntimeException('Nonempty dump output has no checkpoint; explicit repair required'); }
                $state = ['version'=>1, 'fingerprints'=>$fingerprints, 'output'=>$identity, 'position'=>0,
                    'prefix'=>hash_final(hash_copy($hash)), 'phase'=>'header', 'table'=>0, 'table_name'=>$tables[0]['name'] ?? null,
                    'key'=>$tables[0]['key'] ?? null, 'cursor'=>null, 'rows'=>0];
            }
            $bytes = 0; $units = 0;
            while ($state['phase'] !== 'done') {
                if ($units >= $o['max_batches']) { return self::report($state, $bytes, $units, 0); }
                [$chunk, $next] = self::unit($read, $source['db'], $tables, $state, $o['batch_rows']);
                $length = strlen($chunk);
                if ($length > $o['max_bytes'] - $bytes) { return self::report($state, $bytes, $units, $length); }
                self::writeAll($sink, $chunk);
                if (!fflush($sink)) { throw new \RuntimeException('Cannot flush dump batch'); }
                hash_update($hash, $chunk);
                $next['position'] += $length;
                $next['prefix'] = hash_final(hash_copy($hash));
                self::publish($checkpoint, $next);
                $state = $next; $bytes += $length; $units++;
            }
            return self::report($state, $bytes, $units, 0);
        } finally {
            if (is_resource($sink)) { fclose($sink); }
            fclose($lock);
        }
    }

    private static function options(array $options): array {
        if (array_diff(array_keys($options), ['source_frozen','source_idle','source_id','tables','batch_rows','max_bytes','max_batches'])) {
            throw new \InvalidArgumentException('Unknown dump option');
        }
        if (($options['source_frozen'] ?? null) !== true || ($options['source_idle'] ?? null) !== true || !is_string($options['source_id'] ?? null) || $options['source_id'] === '' || strlen($options['source_id']) > 1024) {
            throw new \InvalidArgumentException('Frozen/idle source acknowledgements and nonsecret source_id required');
        }
        $o = $options + ['tables'=>null, 'batch_rows'=>300, 'max_bytes'=>PHP_INT_MAX, 'max_batches'=>PHP_INT_MAX];
        foreach (['batch_rows','max_bytes','max_batches'] as $key) {
            if (!is_int($o[$key]) || $o[$key] < ($key === 'batch_rows' ? 1 : 0)) { throw new \InvalidArgumentException('Invalid dump budget or batch size'); }
        }
        if ($o['batch_rows'] > 10000) { throw new \InvalidArgumentException('Dump batch size exceeds limit'); }
        if ($o['tables'] !== null) {
            if (!is_array($o['tables']) || !array_is_list($o['tables']) || count(array_unique($o['tables'], SORT_REGULAR)) !== count($o['tables'])) {
                throw new \InvalidArgumentException('Dump tables must be a unique list');
            }
            foreach ($o['tables'] as $table) { if (!is_string($table) || $table === '' || str_contains($table,"\0")) { throw new \InvalidArgumentException('Invalid dump table'); } }
            sort($o['tables'], SORT_STRING);
        }
        return $o;
    }

    private static function rows(callable $read, string $sql, array $parameters = []): array {
        $result = $read($sql, $parameters);
        try { return $result->as_array(); } finally { $result->close(); }
    }

    private static function schema(callable $read, array $o): array {
        $identity = self::rows($read, self::SOURCE_SQL);
        $source = $identity[0] ?? [];
        if (!is_string($source['db'] ?? null) || $source['db'] === '' || (string)($source['quote_create'] ?? '') !== '1' || (string)($source['autocommit'] ?? '') !== '1') {
            throw new \RuntimeException('Dump requires autocommit=1, a selected database and quoted SHOW CREATE output');
        }
        // Other modes can suppress SHOW CREATE details or reinterpret stored DDL.
        $allowed = ['STRICT_TRANS_TABLES','STRICT_ALL_TABLES','ONLY_FULL_GROUP_BY','NO_ZERO_IN_DATE','NO_ZERO_DATE',
            'ERROR_FOR_DIVISION_BY_ZERO','NO_ENGINE_SUBSTITUTION','NO_AUTO_VALUE_ON_ZERO','NO_AUTO_CREATE_USER'];
        if (array_diff(array_filter(explode(',', (string)$source['mode'])), $allowed)) { throw new \RuntimeException('Unsupported dump source SQL mode'); }
        $available = self::rows($read, 'SELECT TABLE_NAME, TABLE_TYPE, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME', [$source['db']]);
        $selected = $o['tables'] ?? array_column($available, 'TABLE_NAME');
        if (array_diff($selected, array_column($available,'TABLE_NAME'))) { throw new \RuntimeException('Selected dump table does not exist'); }
        $visibility = $selected ? self::triggerVisibility($read, $source['db']) : [];
        $tables = [];
        foreach ($available as $table) {
            if (!in_array($table['TABLE_NAME'], $selected, true)) { continue; }
            if ($table['TABLE_TYPE'] !== 'BASE TABLE' || $table['ENGINE'] !== 'InnoDB') { throw new \RuntimeException('Unsupported dump table kind or engine'); }
            $name = $table['TABLE_NAME']; $params = [$source['db'], $name];
            if (!in_array(null, $visibility, true) && !in_array($name, $visibility, true)) { throw new \RuntimeException('Dump requires verified direct TRIGGER metadata visibility'); }
            $columns = self::rows($read, 'SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, CHARACTER_SET_NAME, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', $params);
            $keys = self::rows($read, 'SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX', [...$params, 'PRIMARY']);
            if (count($keys) !== 1 || !$columns) { throw new \RuntimeException('Dump requires a single integer primary key'); }
            $key = $keys[0]['COLUMN_NAME']; $keyIndex = null;
            foreach ($columns as $index => &$column) {
                $type = strtolower($column['DATA_TYPE']);
                if (stripos($column['COLUMN_TYPE'], 'zerofill') !== false) { throw new \RuntimeException('Dump does not support ZEROFILL columns'); }
                // YEAR(2) text loses the century: 1970 and 2070 both become "70".
                if ($type === 'year' && !in_array(strtolower($column['COLUMN_TYPE']), ['year','year(4)'], true)) {
                    throw new \RuntimeException('Unsupported dump YEAR display variant');
                }
                if (in_array($type, self::INTEGER_TYPES, true)) { $kind = 'integer'; }
                elseif ($type === 'decimal') { $kind = 'decimal'; }
                elseif (in_array($type, ['char','varchar','tinytext','text','mediumtext','longtext'], true)) { $kind = 'text'; }
                elseif (in_array($type, ['binary','varbinary','tinyblob','blob','mediumblob','longblob','date','datetime','time','year'], true)) { $kind = 'bytes'; }
                else { throw new \RuntimeException('Unsupported dump column type'); }
                if (!in_array(strtolower($column['EXTRA']), ['', 'auto_increment', 'default_generated', 'invisible', 'auto_increment invisible'], true)) {
                    throw new \RuntimeException('Unsupported dump generated or automatic column');
                }
                if ($kind === 'text' && !preg_match('/\A[a-zA-Z0-9_]+\z/D', (string)$column['CHARACTER_SET_NAME'])) { throw new \RuntimeException('Unsupported dump character set'); }
                $column['kind'] = $kind;
                if ($column['COLUMN_NAME'] === $key) {
                    if ($kind !== 'integer' || $column['IS_NULLABLE'] !== 'NO') { throw new \RuntimeException('Dump primary key must be a nonnull integer'); }
                    $keyIndex = $index;
                }
            }
            unset($column);
            if ($keyIndex === null) { throw new \RuntimeException('Missing dump primary key'); }
            $constraints = self::rows($read, 'SELECT CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', $params);
            foreach ($constraints as $constraint) {
                if (!in_array($constraint['CONSTRAINT_TYPE'], ['PRIMARY KEY','UNIQUE'], true)) { throw new \RuntimeException('Unsupported dump constraint'); }
            }
            if (self::rows($read, 'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? AND EVENT_OBJECT_TABLE = ?', $params)) { throw new \RuntimeException('Dump does not support triggers'); }
            $create = self::rows($read, 'SHOW CREATE TABLE '.self::identifier($source['db']).'.'.self::identifier($name));
            $ddl = $create[0]['Create Table'] ?? null;
            if (!is_string($ddl) || $ddl === '') { throw new \RuntimeException('Missing dump schema'); }
            $tables[] = ['name'=>$name, 'key'=>$key, 'key_index'=>$keyIndex, 'columns'=>$columns, 'ddl'=>$ddl];
        }
        return [$source, $tables];
    }

    private static function triggerVisibility(callable $read, string $database): array {
        $account = self::rows($read, 'SELECT CURRENT_USER() AS account')[0]['account'] ?? '';
        if (!is_string($account) || !preg_match('/\A([a-zA-Z0-9_.-]+)@([a-zA-Z0-9_.:%-]+)\z/D', $account, $parts)) {
            throw new \RuntimeException('Unsupported dump metadata account identity');
        }
        $grantee = "'".$parts[1]."'@'".$parts[2]."'";
        $grants = self::rows($read, "SELECT NULL AS scope FROM information_schema.USER_PRIVILEGES WHERE GRANTEE = ? AND PRIVILEGE_TYPE = 'TRIGGER' UNION ALL SELECT NULL AS scope FROM information_schema.SCHEMA_PRIVILEGES WHERE GRANTEE = ? AND TABLE_SCHEMA = ? AND PRIVILEGE_TYPE = 'TRIGGER' UNION ALL SELECT TABLE_NAME AS scope FROM information_schema.TABLE_PRIVILEGES WHERE GRANTEE = ? AND TABLE_SCHEMA = ? AND PRIVILEGE_TYPE = 'TRIGGER'", [$grantee,$grantee,$database,$grantee,$database]);
        return array_column($grants,'scope');
    }

    private static function unit(callable $read, string $database, array $tables, array $state, int $batch): array {
        $next = $state;
        switch ($state['phase']) {
            case 'header':
                $next['phase'] = $tables ? 'ddl' : 'footer';
                return ["-- ThreadFin resumable dump v1; frozen source required\nSET @threadfin_dump_mode = @@SESSION.sql_mode;\nSET @threadfin_dump_autocommit = @@SESSION.autocommit;\nSET SESSION autocommit = 1;\nSET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO';\nSET NAMES utf8mb4;\n", $next];
            case 'ddl':
                $next['phase'] = 'rows';
                return [$tables[$state['table']]['ddl'].";\n", $next];
            case 'rows':
                $table = $tables[$state['table']]; $expressions = []; $names = [];
                foreach ($table['columns'] as $i => $column) {
                    $name = self::identifier($column['COLUMN_NAME']); $names[] = $name;
                    $expressions[] = (in_array($column['kind'], ['integer','decimal'], true) ? 'CAST('.$name.' AS CHAR)' : 'HEX(CAST('.$name.' AS BINARY))').' AS `c'.$i.'`';
                }
                $key = self::identifier($table['key']);
                $query = 'SELECT '.implode(', ', $expressions).' FROM '.self::identifier($database).'.'.self::identifier($table['name']);
                $params = [];
                if ($state['cursor'] !== null) { $query .= ' WHERE '.$key.' > CAST(? AS DECIMAL(20,0))'; $params[] = $state['cursor']; }
                $rows = self::rows($read, $query.' ORDER BY '.$key.' LIMIT '.$batch, $params);
                $values = [];
                foreach ($rows as $row) {
                    $literals = [];
                    foreach ($table['columns'] as $i => $column) { $literals[] = self::literal($row['c'.$i], $column); }
                    $cursor = $row['c'.$table['key_index']];
                    if (!is_string($cursor) || !self::integer($cursor)) { throw new \RuntimeException('Invalid dump key representation'); }
                    $next['cursor'] = $cursor;
                    $values[] = '('.implode(', ', $literals).')';
                }
                $next['rows'] += count($rows);
                if (count($rows) < $batch) {
                    $next['table']++; $next['cursor'] = null;
                    $next['table_name'] = $tables[$next['table']]['name'] ?? null;
                    $next['key'] = $tables[$next['table']]['key'] ?? null;
                    $next['phase'] = isset($tables[$next['table']]) ? 'ddl' : 'footer';
                }
                return [$values ? 'INSERT INTO '.self::identifier($table['name']).' ('.implode(', ', $names).') VALUES '.implode(', ', $values).";\n" : '', $next];
            case 'footer':
                $next['phase'] = 'done';
                return ["SET SESSION sql_mode = @threadfin_dump_mode;\nSET SESSION autocommit = @threadfin_dump_autocommit;\n", $next];
            default: throw new \RuntimeException('Invalid dump phase');
        }
    }

    private static function integer(string $value): bool {
        if (!preg_match('/\A(?:0|-?[1-9][0-9]*)\z/D', $value)) { return false; }
        $negative = $value[0] === '-'; $digits = $negative ? substr($value,1) : $value;
        $max = $negative ? '9223372036854775808' : '18446744073709551615';
        return strlen($digits) < strlen($max) || (strlen($digits) === strlen($max) && strcmp($digits,$max) <= 0);
    }

    private static function literal(mixed $value, array $column): string {
        if ($value === null) { return 'NULL'; }
        if (!is_string($value)) { throw new \RuntimeException('Invalid dump column representation'); }
        if ($column['kind'] === 'integer') {
            if (!self::integer($value)) { throw new \RuntimeException('Invalid dump integer'); }
            return $value;
        }
        if ($column['kind'] === 'decimal') {
            if (!preg_match('/\A-?[0-9]+(?:\.[0-9]+)?\z/D', $value)) { throw new \RuntimeException('Invalid dump decimal'); }
            return $value;
        }
        if (!preg_match('/\A(?:[0-9A-F]{2})*\z/D', $value)) { throw new \RuntimeException('Invalid dump hex bytes'); }
        $literal = "X'".$value."'";
        return $column['kind'] === 'text' ? 'CONVERT('.$literal.' USING '.$column['CHARACTER_SET_NAME'].')' : $literal;
    }

    private static function identifier(string $name): string { return '`'.str_replace('`','``',$name).'`'; }
    private static function json(array $value): string { return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES); }
    private static function digest(array $value): string { return hash('sha256', self::json($value)); }
    private static function report(array $state, int $bytes, int $units, int $required): DumpReport {
        $done = $state['phase'] === 'done';
        return new DumpReport($done, $done ? 'complete' : 'budget', $state['phase'], $state['rows'], $state['position'], $bytes, $units, $required);
    }

    private static function load(string $path, array $fingerprints, array $identity, array $tables): array {
        // Bound accidental/corrupt checkpoint allocation; data rows never belong here.
        if (filesize($path) > 65536) { throw new \RuntimeException('Invalid dump checkpoint size'); }
        try { $record = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException $error) { throw new \RuntimeException('Invalid dump checkpoint JSON'); }
        if (!is_array($record) || !isset($record['state'], $record['checksum']) || !is_array($record['state']) || !is_string($record['checksum']) || !hash_equals(self::digest($record['state']),$record['checksum'])) {
            throw new \RuntimeException('Invalid dump checkpoint checksum');
        }
        $s = $record['state'];
        if (($s['version'] ?? null) !== 1 || ($s['fingerprints'] ?? null) !== $fingerprints || ($s['output'] ?? null) !== $identity ||
            !is_int($s['position'] ?? null) || $s['position'] < 0 || !is_int($s['rows'] ?? null) || $s['rows'] < 0 ||
            !is_string($s['prefix'] ?? null) || !preg_match('/\A[0-9a-f]{64}\z/D',$s['prefix']) ||
            !is_int($s['table'] ?? null) || $s['table'] < 0 || $s['table'] > count($tables) ||
            !in_array($s['phase'] ?? null,['ddl','rows','footer','done'],true) || !array_key_exists('cursor',$s) ||
            ($s['cursor'] !== null && (!is_string($s['cursor']) || !self::integer($s['cursor']))) ||
            ($s['table_name'] ?? null) !== ($tables[$s['table']]['name'] ?? null) || ($s['key'] ?? null) !== ($tables[$s['table']]['key'] ?? null)) {
            throw new \RuntimeException('Dump checkpoint identity/schema/options/state mismatch');
        }
        $atTable = in_array($s['phase'],['ddl','rows'],true);
        if ($atTable !== ($s['table'] < count($tables)) || ($s['phase'] !== 'rows' && $s['cursor'] !== null)) { throw new \RuntimeException('Invalid dump checkpoint phase'); }
        return $s;
    }

    private static function publish(string $path, array $state): void {
        $temp = $path.'.tmp.'.bin2hex(random_bytes(12)); $handle = null;
        try {
            $handle = fopen($temp, 'x+b');
            if ($handle === false) { throw new \RuntimeException('Cannot create checkpoint temporary file'); }
            chmod($temp,0600);
            self::writeAll($handle, self::json(['state'=>$state, 'checksum'=>self::digest($state)])."\n");
            if (!fflush($handle)) { throw new \RuntimeException('Cannot flush dump checkpoint'); }
            fclose($handle); $handle = null;
            if (!rename($temp,$path)) { throw new \RuntimeException('Cannot publish dump checkpoint'); }
        } finally {
            if (is_resource($handle)) { fclose($handle); }
            if (is_file($temp)) { unlink($temp); }
        }
    }

    private static function writeAll($handle, string $data): void {
        $length = strlen($data); $offset = 0;
        while ($offset < $length) {
            $written = fwrite($handle, substr($data,$offset));
            if ($written === false || $written <= 0 || $written > $length - $offset) { throw new \RuntimeException('Cannot write complete dump unit'); }
            $offset += $written;
        }
    }

    private static function path(string $path): string {
        if ($path === '' || str_contains($path,"\0") || str_contains($path, '://')) { throw new \InvalidArgumentException('Dump requires plain local file paths'); }
        $absolute = str_starts_with($path,'/') ? $path : getcwd().'/'.$path;
        $current = '';
        foreach (explode('/',$absolute) as $part) {
            if ($part === '' || $part === '.') { continue; }
            if ($part === '..') { $current = dirname($current); continue; }
            $current .= '/'.$part;
            clearstatcache(true,$current);
            if (is_link($current)) { throw new \InvalidArgumentException('Dump paths cannot contain symlinks'); }
        }
        if ($current === '') { throw new \InvalidArgumentException('Dump paths must name regular files'); }
        $parent = realpath(dirname($current));
        if ($parent === false || !is_dir($parent) || (file_exists($current) && !is_file($current))) { throw new \InvalidArgumentException('Dump paths require existing directories and regular files'); }
        return $parent.'/'.basename($current);
    }

    private static function sameFile(string $a, string $b): bool {
        if (!is_file($a) || !is_file($b)) { return false; }
        $x = stat($a); $y = stat($b);
        return $x['dev'] === $y['dev'] && $x['ino'] === $y['ino'];
    }

    private static function open(string $path) {
        $existed = file_exists($path);
        $handle = fopen($path, $existed ? 'r+b' : 'x+b');
        if ($handle === false) { throw new \RuntimeException('Cannot open dump file'); }
        if (!$existed) { chmod($path,0600); }
        $stat = fstat($handle); $named = lstat($path);
        if (($stat['mode'] & 0170000) !== 0100000 || ($named['mode'] & 0170000) !== 0100000 || $named['dev'] !== $stat['dev'] || $named['ino'] !== $stat['ino']) {
            fclose($handle); throw new \RuntimeException('Dump file identity changed');
        }
        return $handle;
    }
}
