<?php declare(strict_types=1);
namespace ThreadFin\DB;

/** Explicit opt-in, Linux/installed MariaDB backend; proc_open, POSIX, setsid and
 * shared mysqli.so in PHP's extension_dir required. No ordinary DB API inclusion.
 * Offline manifest only: never accepts a source credential or existing destination.
 * Trusted local regular input files/directories; do not replace paths during a run.
 * Hard wallclock supervises installer, server and replay worker in separate process
 * groups. Teardown has a separate three-second grace. Disk cap is sampled (50ms),
 * NOT an OS quota; initialization and writes can overshoot between samples. Server
 * buffers/connections/packets and worker memory/input/statement sizes are bounded,
 * not a kernel CPU/RSS isolation guarantee. No retry or exactly-once guarantee.
 * Manifest/file hashes are integrity checks, not authentication against a malicious
 * manifest author. Identical packets reject conservatively: legacy has no packet IDs.
 */
final class VerifyReport {
    public function __construct(
        public readonly int $version = 1,
        public readonly string $status = 'internal',
        public readonly bool $matched = false,
        public readonly bool $cleanup = true,
        public readonly ?int $position = null,
        public readonly array $tables = []
    ) {}
}
final class VerifyFailure extends \RuntimeException {
    public function __construct(public readonly string $status, public readonly ?int $position = null) {
        parent::__construct('Replay verification failed: ' . $status);
    }
}

/** Internal, conservative SQL lexer: backslash-enabled ordinary MySQL quoting only. */
final class VerifySql {
    public static function lex(string $input): array {
        $out=[]; $sql=''; $mask=''; $start=0; $hasText=false; $n=strlen($input);
        for ($i=0; $i<$n;) {
            $c=$input[$i];
            if ($c==="\0") { throw new VerifyFailure('format',$i); }
            if ($c==="'" || $c==='"' || $c==='`') {
                if (!$hasText) { $start=$i; $hasText=true; }
                $begin=$i++; $closed=false;
                while ($i<$n) {
                    if ($input[$i]==='\\') { $i+=2; continue; }
                    if ($input[$i]===$c) { if ($i+1<$n && $input[$i+1]===$c) { $i+=2; continue; } $i++; $closed=true; break; }
                    $i++;
                }
                if (!$closed || $i>$n) { throw new VerifyFailure('format',$begin); }
                $part=substr($input,$begin,$i-$begin); $sql.=$part; $mask.=str_repeat(' ',strlen($part)); continue;
            }
            $line=$c==='#' || ($c==='-' && substr($input,$i,2)==='--' && ($i+2===$n || ord($input[$i+2])<=32));
            if ($line) {
                $end=strpos($input,"\n",$i); if ($end===false) { $end=$n; }
                $comment=trim(substr($input,$i,$end-$i));
                if (!$hasText && in_array($comment,['-- ThreadFin replay session','-- End ThreadFin replay session'],true)) {
                    $out[]=['marker'=>$comment,'offset'=>$i,'end'=>$end];
                }
                $sql.=' '; $mask.=' '; $i=$end; continue;
            }
            if (substr($input,$i,2)==='/*') {
                if (preg_match('/^\/\*(?:!|M!|\+)/i',substr($input,$i,4))) { throw new VerifyFailure('unsupported',$i); }
                $end=strpos($input,'*/',$i+2); if ($end===false) { throw new VerifyFailure('format',$i); }
                $sql.=' '; $mask.=' '; $i=$end+2; continue;
            }
            if ($c===';') {
                if (strlen($sql)>1048576) { throw new VerifyFailure('limit',$start); }
                if ($hasText) { $out[]=['sql'=>trim($sql),'mask'=>trim($mask),'offset'=>$start,'end'=>$i+1]; }
                $sql=''; $mask=''; $hasText=false; $i++; continue;
            }
            if (!$hasText && !ctype_space($c)) { $start=$i; $hasText=true; }
            $sql.=$c; $mask.=$c; $i++;
            if (strlen($sql)>1048576 || count($out)>10000) { throw new VerifyFailure('limit',$i); }
        }
        if (trim($sql)!=='') { throw new VerifyFailure('incomplete',$start); }
        if (count($out)>10000) { throw new VerifyFailure('limit'); }
        return $out;
    }
    public static function mode(string $mode): void {
        $allowed=['STRICT_TRANS_TABLES','STRICT_ALL_TABLES','ERROR_FOR_DIVISION_BY_ZERO','NO_AUTO_CREATE_USER','NO_ENGINE_SUBSTITUTION','NO_ZERO_DATE','NO_ZERO_IN_DATE','ONLY_FULL_GROUP_BY','NO_AUTO_VALUE_ON_ZERO'];
        foreach (explode(',',strtoupper($mode)) as $m) { if ($m!=='' && !in_array($m,$allowed,true)) { throw new VerifyFailure('unsupported'); } }
    }
    private static function normalized(string $s): string { return strtoupper(preg_replace('/\s+/',' ',trim($s))); }
    public static function statement(array $s): void {
        $m=self::normalized($s['mask']);
        if (preg_match('/^(INSERT|REPLACE|UPDATE|DELETE|SELECT)\b/',$m)) {
            if (preg_match('/\b(INTO\s+(OUTFILE|DUMPFILE)|LOAD\s+DATA)\b/',$m) || str_contains($m,'\\')) { throw new VerifyFailure('unsupported',$s['offset']); }
            return;
        }
        if (preg_match('/^(BEGIN|SET TRANSACTION READ WRITE|START TRANSACTION|COMMIT(?: AND NO CHAIN NO RELEASE)?|ROLLBACK(?: AND NO CHAIN NO RELEASE)?|ROLLBACK TO SAVEPOINT [A-Z0-9_]+|SAVEPOINT [A-Z0-9_]+|RELEASE SAVEPOINT [A-Z0-9_]+)$/',$m)) { return; }
        if (preg_match('/^(CREATE TABLE|DROP TABLE|ALTER TABLE)\b/',$m)) {
            if (preg_match('/\b(DIRECTORY|TABLESPACE|CONNECTION|FEDERATED|PARTITION|SELECT|TRIGGER|FOREIGN|CHECK|TEMPORARY|WITH\s+SYSTEM|SEQUENCE)\b/',$m)) { throw new VerifyFailure('unsupported',$s['offset']); }
            $engineCount=preg_match_all('/\bENGINE\b/',$m);
            $assigned=preg_match_all('/\bENGINE\s*=\s*(INNODB)\b/',$m);
            if ($engineCount!==$assigned) { throw new VerifyFailure('unsupported',$s['offset']); }
            if (str_starts_with($m,'CREATE TABLE') && !preg_match('/\bENGINE\s*=\s*INNODB\b/',$m)) { throw new VerifyFailure('unsupported',$s['offset']); }
            return;
        }
        throw new VerifyFailure('unsupported',$s['offset']);
    }
    public static function journal(string $input): array {
        $tokens=self::lex($input); $out=[]; $seen=[]; $i=0;
        while ($i<count($tokens)) {
            $start=$tokens[$i];
            if (($start['marker']??'')!=='-- ThreadFin replay session') { throw new VerifyFailure('format',$start['offset']); }
            $i++; $packet=[];
            while ($i<count($tokens) && !isset($tokens[$i]['marker'])) { $packet[]=$tokens[$i++]; }
            if ($i>=count($tokens) || $tokens[$i]['marker']!=='-- End ThreadFin replay session' || count($packet)<7) { throw new VerifyFailure('incomplete',$start['offset']); }
            $end=$tokens[$i++]; $hash=hash('sha256',substr($input,$start['offset'],$end['end']-$start['offset']));
            if (isset($seen[$hash])) { throw new VerifyFailure('duplicate',$start['offset']); } $seen[$hash]=true;
            $last=count($packet)-1;
            if (self::normalized($packet[0]['sql'])!=='ROLLBACK' || self::normalized($packet[1]['sql'])!=='SET NAMES UTF8MB4'
                || !preg_match("/^SET SESSION sql_mode = '([A-Za-z0-9_,]*)'$/i",$packet[2]['sql'],$mode)
                || !preg_match('/^SET SESSION autocommit = [01]$/i',$packet[3]['sql'])
                || self::normalized($packet[$last-1]['sql'])!=='ROLLBACK' || self::normalized($packet[$last]['sql'])!=='SET SESSION AUTOCOMMIT = 1') { throw new VerifyFailure('format',$start['offset']); }
            self::mode($mode[1]);
            for ($j=4; $j<$last-1; $j++) { self::statement($packet[$j]); }
            array_push($out,...$packet);
        }
        if (!$out) { throw new VerifyFailure('format'); }
        return $out;
    }
    public static function baseline(string $input): array {
        $tokens=self::lex($input); $dump=str_starts_with($input,'-- ThreadFin resumable dump v1; frozen source required');
        $head=['SET @THREADFIN_DUMP_MODE = @@SESSION.SQL_MODE','SET @THREADFIN_DUMP_AUTOCOMMIT = @@SESSION.AUTOCOMMIT','SET SESSION AUTOCOMMIT = 1',"SET SESSION SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO'",'SET NAMES UTF8MB4'];
        $tail=['SET SESSION SQL_MODE = @THREADFIN_DUMP_MODE','SET SESSION AUTOCOMMIT = @THREADFIN_DUMP_AUTOCOMMIT'];
        if ($dump && count($tokens)<7) { throw new VerifyFailure('incomplete'); }
        foreach ($tokens as $i=>$s) {
            if (!isset($s['sql'])) { throw new VerifyFailure('format',$s['offset']); }
            if ($dump && ($i<5 || $i>=count($tokens)-2)) {
                $want=$i<5 ? $head[$i] : $tail[$i-(count($tokens)-2)];
                if (self::normalized($s['sql'])!==$want) { throw new VerifyFailure('format',$s['offset']); }
            } else { self::statement($s); }
        }
        return $tokens;
    }
}

final class VerifyManifest {
    public const POLICY = ['timezone'=>'+00:00','charset'=>'utf8mb4','collation'=>'utf8mb4_bin','types'=>'integer-pk-bytes-v1'];
    public static function keys(array $a,array $keys): void {
        $actual=array_keys($a); sort($actual); sort($keys); if ($actual!==$keys) { throw new VerifyFailure('manifest'); }
    }
    public static function validate(array $m): array {
        self::keys($m,['version','source_id','source_policy','policy','journal','baseline_sha256','expected']);
        if ($m['version']!==1 || !is_string($m['source_id']) || $m['source_id']==='' || strlen($m['source_id'])>128
            || $m['source_policy']!=='frozen-cut-no-hidden-schema-objects' || $m['policy']!=self::POLICY || !is_array($m['journal']) || !is_array($m['expected'])) { throw new VerifyFailure('manifest'); }
        self::keys($m['journal'],['sha256','cut']);
        if (!is_int($m['journal']['cut']) || $m['journal']['cut']<1 || $m['journal']['cut']>16777216 || count($m['expected'])>100) { throw new VerifyFailure('manifest'); }
        self::hash($m['journal']['sha256']); self::hash($m['baseline_sha256']);
        foreach ($m['expected'] as $name=>$t) {
            // PHP coerces canonical integer-string array keys, including JSON keys.
            $name=(string)$name;
            if ($name==='' || strlen($name)>256 || !is_array($t)) { throw new VerifyFailure('manifest'); }
            self::keys($t,['schema_sha256','rows_sha256','count']); self::hash($t['schema_sha256']); self::hash($t['rows_sha256']);
            if (!is_int($t['count']) || $t['count']<0) { throw new VerifyFailure('manifest'); }
        }
        return $m;
    }
    private static function hash(mixed $h): void { if (!is_string($h) || !preg_match('/^[a-f0-9]{64}$/D',$h)) { throw new VerifyFailure('manifest'); } }
}

/** Length framing includes type and NULL; bytes are never text-normalized. */
function verify_frame(array $values,array $types): string {
    $s='R'.count($values).':';
    foreach ($values as $i=>$value) { $type=$types[$i]; $s.=strlen($type).':'.$type.($value===null?'N':'V'.strlen((string)$value).':'.(string)$value); }
    return $s;
}
function verify_identifier(string $name): string { return '`'.str_replace('`','``',$name).'`'; }

/** Read-only table snapshot, NOT a complete schema/trigger/routine audit. SELECT-only
 * users can have hidden objects. Manifest source_policy separately attests their
 * absence and an idle frozen source at the exact journal cut. Caller establishes
 * UTC/utf8mb4/utf8mb4_bin before calling; this helper issues ONLY metadata/SELECT.
 * Supported InnoDB tables: one integer PK, integer/decimal/text/binary/date/time/
 * datetime/YEAR(4). No floats/BIT/JSON/enum/set/generated/TIMESTAMP/YEAR(2), FKs or
 * CHECKs. Exact SHOW CREATE bytes (except unquoted AUTO_INCREMENT counter) define
 * schema identity; cross-version formatting equivalence is NOT promised.
 */
function verify_snapshot(\mysqli $db): array {
    set_error_handler(static fn()=>true);
    try { return VerifySnapshot::read($db); }
    catch (VerifyFailure $e) { throw $e; }
    catch (\Throwable $e) { throw new VerifyFailure('snapshot'); }
    finally { restore_error_handler(); }
}
final class VerifySnapshot {
    private static function rows(\mysqli $db,string $sql): array {
        $r=$db->query($sql); if (!$r instanceof \mysqli_result) { throw new VerifyFailure('snapshot'); }
        try { if ($r->num_rows>4096) { throw new VerifyFailure('limit'); } return $r->fetch_all(MYSQLI_ASSOC); } finally { $r->free(); }
    }
    public static function read(\mysqli $db): array {
        $session=self::rows($db,'SELECT @@session.time_zone AS tz, @@character_set_connection AS cs, @@collation_connection AS co, @@session.sql_mode AS mode, @@session.sql_quote_show_create AS qc')[0];
        if ($session['tz']!=='+00:00' || $session['cs']!=='utf8mb4' || $session['co']!=='utf8mb4_bin' || (string)$session['qc']!=='1') { throw new VerifyFailure('policy'); }
        VerifySql::mode($session['mode']);
        $tables=self::rows($db,'SHOW FULL TABLES'); if (count($tables)>100) { throw new VerifyFailure('limit'); }
        $out=[];
        foreach ($tables as $table) {
            $v=array_values($table); if ($v[1]!=='BASE TABLE') { throw new VerifyFailure('unsupported'); }
            $name=(string)$v[0]; $q=verify_identifier($name);
            $ddl=array_values(self::rows($db,'SHOW CREATE TABLE '.$q)[0])[1];
            $token=VerifySql::lex($ddl.';')[0]; VerifySql::statement($token);
            // SHOW CREATE separates table options with ASCII spaces. Remove the
            // counter and exactly one separator, never normalize quoted bytes or
            // other whitespace. The mask excludes identifiers/defaults/comments.
            $mask=$token['mask'];
            if (preg_match_all('/ AUTO_INCREMENT=\d+\b/i',$mask,$matches,PREG_OFFSET_CAPTURE)) {
                foreach (array_reverse($matches[0]) as [$part,$offset]) {
                    if ($ddl[$offset]!==' ') { throw new VerifyFailure('snapshot'); }
                    $ddl=substr_replace($ddl,'',$offset,strlen($part));
                }
            }
            $columns=self::rows($db,'SHOW FULL COLUMNS FROM '.$q); $keys=self::rows($db,"SHOW KEYS FROM $q WHERE Key_name='PRIMARY'");
            if (count($keys)!==1 || (string)$keys[0]['Seq_in_index']!=='1' || $keys[0]['Sub_part']!==null) { throw new VerifyFailure('unsupported'); }
            $key=$keys[0]['Column_name']; $types=[]; $expr=[]; $keyOk=false;
            foreach ($columns as $column) {
                $type=strtolower($column['Type']);
                if (!preg_match('/^(?:(?:tinyint|smallint|mediumint|int|bigint)(?:\(\d+\))?(?: unsigned)?|decimal\(\d+,\d+\)(?: unsigned)?|(?:char|varchar|binary|varbinary)\(\d+\)|(?:tiny|medium|long)?(?:text|blob)|date|datetime(?:\([0-6]\))?|time(?:\([0-6]\))?|year(?:\(4\))?)$/D',$type)
                    || preg_match('/generated/i',$column['Extra'])) { throw new VerifyFailure('unsupported'); }
                if ($column['Field']===$key) { $keyOk=(bool)preg_match('/^(tinyint|smallint|mediumint|int|bigint)\b/',$type) && $column['Null']==='NO'; }
                $types[]=$type; $expr[]='CAST('.verify_identifier($column['Field']).' AS BINARY)';
            }
            if (!$keyOk) { throw new VerifyFailure('unsupported'); }
            $r=$db->query('SELECT '.implode(',',$expr).' FROM '.$q.' ORDER BY '.verify_identifier($key),MYSQLI_USE_RESULT);
            if (!$r instanceof \mysqli_result) { throw new VerifyFailure('snapshot'); }
            $hash=hash_init('sha256'); $count=0;
            try { while (($row=$r->fetch_row())!==null && $row!==false) { hash_update($hash,verify_frame($row,$types)); $count++; } if ($row===false) { throw new VerifyFailure('snapshot'); } }
            finally { $r->free(); }
            $out[$name]=['schema_sha256'=>hash('sha256',$ddl),'rows_sha256'=>hash_final($hash),'count'=>$count];
        }
        ksort($out,SORT_STRING); return $out;
    }
}

/** Internal process owner. Only directories created here may be accounted/removed. */
final class VerifySandbox {
    public readonly string $directory;
    private array $identity;
    private array $processes=[];
    private int $deadline;
    private int $diskLimit;
    private bool $cleaned=false;
    public function __construct(array $options) {
        if (!function_exists('posix_kill') || !is_executable('/usr/bin/setsid') || !is_executable('/usr/bin/mariadb-install-db') || !is_executable('/usr/bin/mariadbd')) { throw new VerifyFailure('dependency'); }
        $this->deadline=hrtime(true)+$options['timeout_seconds']*1000000000;
        $this->diskLimit=$options['max_disk_bytes'];
        $parent=realpath($options['temp_parent']);
        if ($parent===false || !is_dir($parent) || !is_writable($parent) || !preg_match('~^/[A-Za-z0-9_./-]+$~D',$parent)) { throw new VerifyFailure('configuration'); }
        // Unix sockets have a small platform path limit.
        $this->directory=$parent.'/tfv-'.bin2hex(random_bytes(8));
        if (strlen($this->directory)>80 || !mkdir($this->directory,0700)) { throw new VerifyFailure('configuration'); }
        $s=lstat($this->directory); $this->identity=[$s['dev'],$s['ino']];
    }
    public function start(): void {
        mkdir($this->directory.'/data',0700); mkdir($this->directory.'/files',0700);
        $exit=$this->run(['/usr/bin/mariadb-install-db','--no-defaults','--datadir='.$this->directory.'/data','--auth-root-authentication-method=normal','--skip-test-db','--skip-name-resolve','--innodb-buffer-pool-size=32M','--innodb-log-file-size=16M']);
        if ($exit!==0) { throw new VerifyFailure('provision'); }
        $this->spawn(['/usr/bin/mariadbd','--no-defaults','--datadir='.$this->directory.'/data','--socket='.$this->directory.'/server.sock','--pid-file='.$this->directory.'/server.pid','--log-error='.$this->directory.'/server.log','--skip-networking','--skip-name-resolve','--local-infile=0','--secure-file-priv='.$this->directory.'/files','--innodb-buffer-pool-size=32M','--innodb-log-file-size=16M','--max-connections=8','--max-allowed-packet=4M','--tmp-table-size=8M','--max-heap-table-size=8M','--tmpdir='.$this->directory,'--default-storage-engine=InnoDB','--character-set-server=utf8mb4','--collation-server=utf8mb4_bin','--max-statement-time=10','--skip-log-bin']);
        while (!file_exists($this->directory.'/server.sock')) {
            $this->check(); $p=$this->processes[array_key_last($this->processes)];
            if (!proc_get_status($p['handle'])['running']) { throw new VerifyFailure('provision'); }
            usleep(50000);
        }
    }
    public function spawn(array $command): int {
        $this->check();
        $p=proc_open(array_merge(['/usr/bin/setsid','--wait'], $command),[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes,$this->directory,
            ['PATH'=>'/usr/bin:/bin','HOME'=>$this->directory,'LANG'=>'C']);
        if (!is_resource($p)) { throw new VerifyFailure('process'); }
        $pid=proc_get_status($p)['pid']; $this->processes[]=['handle'=>$p,'pid'=>$pid]; return array_key_last($this->processes);
    }
    public function run(array $command): int {
        $id=$this->spawn($command);
        while (true) {
            $this->check(); $state=proc_get_status($this->processes[$id]['handle']);
            if (!$state['running']) {
                $exit=$state['exitcode']; proc_close($this->processes[$id]['handle']);
                unset($this->processes[$id]); return $exit;
            }
            usleep(50000);
        }
    }
    public function check(): void {
        if (hrtime(true)>$this->deadline) { throw new VerifyFailure('timeout'); }
        $this->owned(); $entries=0;
        if ($this->size($this->directory,$entries)>$this->diskLimit) { throw new VerifyFailure('disk_limit'); }
    }
    private function owned(): void {
        $s=@lstat($this->directory);
        if ($s===false || is_link($this->directory) || [$s['dev'],$s['ino']]!==$this->identity) { throw new VerifyFailure('cleanup'); }
    }
    private function size(string $dir,int &$entries): int {
        $size=0; $names=scandir($dir); if ($names===false) { throw new VerifyFailure('disk_limit'); }
        foreach ($names as $name) {
            if ($name==='.' || $name==='..') { continue; }
            if (++$entries>20000) { throw new VerifyFailure('disk_limit'); }
            $path=$dir.'/'.$name; $s=@lstat($path); if ($s===false) { continue; }
            // lstat mode, never traverse symlinks; concurrent server temp files may vanish.
            $size+=(($s['mode']&0170000)===0040000) ? $this->size($path,$entries) : $s['size'];
        }
        return $size;
    }
    private function remove(string $dir): bool {
        $ok=true; $names=scandir($dir); if ($names===false) { return false; }
        foreach ($names as $name) {
            if ($name==='.' || $name==='..') { continue; }
            $path=$dir.'/'.$name; $s=@lstat($path); if ($s===false) { continue; }
            $done=(($s['mode']&0170000)===0040000) ? $this->remove($path) : @unlink($path); $ok=$done && $ok;
        }
        return $ok && @rmdir($dir);
    }
    public function cleanup(): bool {
        if ($this->cleaned) { return true; }
        $end=hrtime(true)+3000000000; $ok=true;
        foreach ($this->processes as $p) { @posix_kill(-$p['pid'],15); }
        do {
            $alive=false;
            foreach ($this->processes as $p) { if (proc_get_status($p['handle'])['running']) { $alive=true; } }
            if (!$alive) { break; } usleep(20000);
        } while (hrtime(true)<$end-1000000000);
        foreach ($this->processes as $p) { @posix_kill(-$p['pid'],9); }
        foreach ($this->processes as $p) {
            while (proc_get_status($p['handle'])['running'] && hrtime(true)<$end) { usleep(10000); }
            if (proc_get_status($p['handle'])['running']) { $ok=false; } else { proc_close($p['handle']); }
        }
        $this->processes=[];
        if (!$ok) { return false; }
        try { $this->owned(); $ok=$this->remove($this->directory); } catch (\Throwable $e) { $ok=false; }
        $this->cleaned=$ok; return $ok;
    }
}

/** Internal child-only protocol: root executes constants, then is closed forever. */
final class VerifyWorker {
    public static function connect(string $dir): \mysqli {
        mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
        $root=self::connection($dir,'root','',null);
        $password=bin2hex(random_bytes(24));
        try {
            $root->query('CREATE DATABASE verify_data CHARACTER SET utf8mb4 COLLATE utf8mb4_bin');
            $root->query("CREATE USER 'replay'@'localhost' IDENTIFIED BY '$password'");
            $root->query("GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX ON verify_data.* TO 'replay'@'localhost'");
        } finally { $root->close(); }
        $db=self::connection($dir,'replay',$password,'verify_data');
        $db->query("SET SESSION time_zone='+00:00'"); $db->query("SET NAMES utf8mb4 COLLATE utf8mb4_bin");
        $db->query("SET SESSION sql_mode='NO_AUTO_VALUE_ON_ZERO'");
        return $db;
    }
    private static function connection(string $dir,string $user,string $pass,?string $database): \mysqli {
        $db=mysqli_init(); $db->options(MYSQLI_OPT_LOCAL_INFILE,false); $db->options(MYSQLI_OPT_CONNECT_TIMEOUT,2); $db->options(MYSQLI_OPT_READ_TIMEOUT,10);
        $db->real_connect('localhost',$user,$pass,$database,0,$dir.'/server.sock'); return $db;
    }
    private static function execute(\mysqli $db,array $statements,bool $journal,?int &$position,string $dir): void {
        foreach ($statements as $statement) {
            $position=$journal ? $statement['offset'] : null;
            if ($journal && (file_put_contents($dir.'/position-next',(string)$position)===false || !rename($dir.'/position-next',$dir.'/position'))) { throw new VerifyFailure('progress',$position); }
            $r=$db->query($statement['sql'],MYSQLI_USE_RESULT);
            if ($r===false) { throw new VerifyFailure($journal?'replay':'baseline',$position); }
            if ($r instanceof \mysqli_result) {
                try { $count=0; while (($row=$r->fetch_row())!==null && $row!==false) { if (++$count>100000) { throw new VerifyFailure('limit',$position); } } }
                finally { $r->free(); }
            }
        }
    }
    public static function main(string $dir): void {
        $report=new VerifyReport(); $db=null; $position=null; $phase='manifest';
        set_error_handler(static fn()=>true);
        try {
            $m=VerifyManifest::validate(json_decode(file_get_contents($dir.'/manifest'),true,64,JSON_THROW_ON_ERROR));
            $journal=file_get_contents($dir.'/journal'); $baseline=file_get_contents($dir.'/baseline');
            if (strlen($journal)<$m['journal']['cut']) { throw new VerifyFailure('integrity'); }
            $journal=substr($journal,0,$m['journal']['cut']);
            if (!hash_equals($m['journal']['sha256'],hash('sha256',$journal)) || !hash_equals($m['baseline_sha256'],hash('sha256',$baseline))) { throw new VerifyFailure('integrity'); }
            $phase='journal'; $j=VerifySql::journal($journal);
            $phase='baseline'; $b=VerifySql::baseline($baseline);
            $phase='provision'; $db=self::connect($dir);
            $phase='baseline'; self::execute($db,$b,false,$position,$dir);
            $phase='replay'; self::execute($db,$j,true,$position,$dir);
            unlink($dir.'/position'); $position=null;
            // Envelopes SET NAMES changes default connection collation: canonicalize
            // only this disposable comparison session, never the read-only source.
            $db->query("SET NAMES utf8mb4 COLLATE utf8mb4_bin"); $db->query("SET SESSION time_zone='+00:00'");
            $phase='snapshot'; $actual=verify_snapshot($db); $tables=[]; $matched=true;
            // Normalize identifier values, not array storage: "00123" must remain
            // distinct from PHP's integer-coerced key for "123" (and from "0").
            $names=array_unique(array_merge(array_map('strval',array_keys($m['expected'])),array_map('strval',array_keys($actual)))); sort($names,SORT_STRING);
            foreach ($names as $name) {
                $expected=$m['expected'][$name]??null; $found=$actual[$name]??null; $same=$expected==$found; $matched=$matched && $same;
                $tables[]=['table_id'=>hash('sha256',$name),'matched'=>$same,'expected'=>$expected,'actual'=>$found];
            }
            $report=new VerifyReport(1,$matched?'matched':'mismatch',$matched,true,null,$tables);
        } catch (VerifyFailure $e) { $report=new VerifyReport(1,$e->status,false,true,in_array($phase,['journal','replay'],true)?($e->position??$position):null); }
        catch (\Throwable $e) { $report=new VerifyReport(1,$phase,false,true,$position); }
        finally { if ($db!==null) { try { $db->close(); } catch (\Throwable $e) {} } restore_error_handler(); }
        file_put_contents($dir.'/result',json_encode($report,JSON_THROW_ON_ERROR));
    }
}

function verify_options(array $options): array {
    if (array_diff(array_keys($options),['enabled','timeout_seconds','max_disk_bytes','temp_parent']) || ($options['enabled']??false)!==true) { throw new VerifyFailure('configuration'); }
    $o=$options+['timeout_seconds'=>60,'max_disk_bytes'=>536870912,'temp_parent'=>sys_get_temp_dir()];
    if (!is_int($o['timeout_seconds']) || $o['timeout_seconds']<5 || $o['timeout_seconds']>300 || !is_int($o['max_disk_bytes']) || $o['max_disk_bytes']<134217728 || $o['max_disk_bytes']>2147483648 || !is_string($o['temp_parent'])) { throw new VerifyFailure('configuration'); }
    return $o;
}

/** Only explicit safe scalar report fields cross process/privacy boundaries. */
function verify_replay(string $journal_path,string $baseline_path,string $manifest_path,array $sandbox_options=[]): VerifyReport {
    $sandbox=null; $report=new VerifyReport(); $cleanup=true;
    set_error_handler(static fn()=>true);
    try {
        $options=verify_options($sandbox_options);
        if (!extension_loaded('mysqli') || !is_file(ini_get('extension_dir').'/mysqli.so')) { throw new VerifyFailure('dependency'); }
        $sandbox=new VerifySandbox($options);
        foreach (['journal'=>$journal_path,'baseline'=>$baseline_path,'manifest'=>$manifest_path] as $name=>$path) {
            if (str_contains($path,'://') || is_link($path) || !is_file($path)) { throw new VerifyFailure('input'); }
            $f=fopen($path,'rb'); if ($f===false) { throw new VerifyFailure('input'); }
            try {
                $st=fstat($f); if (($st['mode']&0170000)!==0100000 || $st['size']>16777216) { throw new VerifyFailure('limit'); }
                $data=stream_get_contents($f,16777217); if ($data===false || strlen($data)>16777216) { throw new VerifyFailure('limit'); }
            } finally { fclose($f); }
            if (file_put_contents($sandbox->directory.'/'.$name,$data)!==strlen($data)) { throw new VerifyFailure('input'); }
            chmod($sandbox->directory.'/'.$name,0600); $sandbox->check();
        }
        $sandbox->start();
        $code='require $argv[1]; \\ThreadFin\\DB\\VerifyWorker::main($argv[2]);';
        $exit=$sandbox->run([PHP_BINARY,'-n','-d','extension='.ini_get('extension_dir').'/mysqli.so','-d','memory_limit=128M','-d','display_errors=0','-d','log_errors=0','-d','zend.exception_ignore_args=1','-r',$code,__FILE__,$sandbox->directory]);
        if ($exit!==0 || !is_file($sandbox->directory.'/result') || filesize($sandbox->directory.'/result')>1048576) { throw new VerifyFailure('worker'); }
        $r=json_decode(file_get_contents($sandbox->directory.'/result'),true,64,JSON_THROW_ON_ERROR);
        $report=new VerifyReport(1,$r['status'],$r['matched'],true,$r['position'],$r['tables']);
    } catch (VerifyFailure $e) {
        $position=$e->position;
        if ($sandbox!==null && is_file($sandbox->directory.'/position')) {
            $p=file_get_contents($sandbox->directory.'/position',false,null,0,16);
            if (is_string($p) && preg_match('/^[0-9]{1,8}$/D',$p) && (int)$p<=16777216) { $position=(int)$p; }
        }
        $report=new VerifyReport(1,$e->status,false,true,$position);
    }
    catch (\Throwable $e) { $report=new VerifyReport(1,'internal'); }
    finally { if ($sandbox!==null) { $cleanup=$sandbox->cleanup(); } restore_error_handler(); }
    return new VerifyReport(1,$cleanup?$report->status:'cleanup',$cleanup && $report->matched,$cleanup,$report->position,$report->tables);
}

/** Atomic private report publication; never overwrite an input or follow a symlink.
 * Parent directories are trusted; caller must prevent concurrent path replacement.
 */
function verify_report_path(string $path,array $inputs): string {
    if (str_contains($path,'://') || is_link($path) || !is_dir(dirname($path))) { throw new VerifyFailure('report'); }
    $parent=realpath(dirname($path)); $target=$parent.'/'.basename($path);
    foreach ($inputs as $input) {
        $inputParent=realpath(dirname($input));
        if (($inputParent!==false && $inputParent.'/'.basename($input)===$target) || realpath($input)===$target || (file_exists($target) && file_exists($input) && stat($target)['ino']===stat($input)['ino'] && stat($target)['dev']===stat($input)['dev'])) { throw new VerifyFailure('report'); }
    }
    if (file_exists($target) && !is_file($target)) { throw new VerifyFailure('report'); }
    return $target;
}
function verify_write_report(VerifyReport $report,string $path,array $inputs): void {
    set_error_handler(static fn()=>true); $f=null; $temp=null;
    try {
        $target=verify_report_path($path,$inputs); $parent=dirname($target);
        $temp=$parent.'/.tfv-report-'.bin2hex(random_bytes(8)); $f=fopen($temp,'x+b');
        if ($f===false) { throw new VerifyFailure('report'); }
        if (!chmod($temp,0600)) { throw new VerifyFailure('report'); }
        $data=json_encode($report,JSON_THROW_ON_ERROR)."\n";
        if (fwrite($f,$data)!==strlen($data) || !fflush($f) || !rename($temp,$target)) { throw new VerifyFailure('report'); }
    } catch (\Throwable $e) { throw new VerifyFailure('report'); }
    finally {
        if (is_resource($f)) { fclose($f); }
        if ($temp!==null && file_exists($temp)) { unlink($temp); }
        restore_error_handler();
    }
}
