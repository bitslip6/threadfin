<?php declare(strict_types=1);
namespace ThreadFin\DB {
    function rmdir(string $dir): bool { if ($GLOBALS['verify_cleanup_fault']??false) { return false; } return \rmdir($dir); }
}
namespace {
error_reporting(E_ALL & ~E_DEPRECATED);
require __DIR__.'/../../db_verify.php';
use ThreadFin\DB\VerifySandbox;
use ThreadFin\DB\VerifyWorker;
use ThreadFin\DB\VerifyReport;
use ThreadFin\DB\VerifyManifest;
use function ThreadFin\DB\verify_replay;
use function ThreadFin\DB\verify_snapshot;
use function ThreadFin\DB\verify_options;
use function ThreadFin\DB\verify_write_report;
function vpacket(string $body): string { return "\n-- ThreadFin replay session\nROLLBACK;\nSET NAMES utf8mb4;\nSET SESSION sql_mode = '';\nSET SESSION autocommit = 1;\n$body\n;\nROLLBACK;\nSET SESSION autocommit = 1;\n-- End ThreadFin replay session\n"; }
function vconnect(?string $user=null): mysqli {
    $db=new mysqli('localhost',$user??(getenv('THREADFIN_TEST_MYSQL_USER')?:'root'),'',getenv('THREADFIN_TEST_MYSQL_DATABASE'),0,getenv('THREADFIN_TEST_MYSQL_SOCKET'));
    $db->query("SET NAMES utf8mb4 COLLATE utf8mb4_bin"); $db->query("SET time_zone='+00:00'"); $db->query("SET sql_mode='NO_AUTO_VALUE_ON_ZERO'"); return $db;
}
function vremove(string $dir): void { foreach (scandir($dir) as $name) { if ($name==='.' || $name==='..') { continue; } $p=$dir.'/'.$name; if (is_dir($p) && !is_link($p)) { vremove($p); } else { unlink($p); } } rmdir($dir); }
function vmanifest(string $dir,string $baseline,string $journal,array $expected): array {
    file_put_contents($dir.'/baseline.sql',$baseline); file_put_contents($dir.'/journal.sql',$journal);
    $m=['version'=>1,'source_id'=>'nonsecret-freeze-1','source_policy'=>'frozen-cut-no-hidden-schema-objects','policy'=>VerifyManifest::POLICY,'journal'=>['sha256'=>hash('sha256',$journal),'cut'=>strlen($journal)],'baseline_sha256'=>hash('sha256',$baseline),'expected'=>$expected];
    file_put_contents($dir.'/manifest.json',json_encode($m,JSON_THROW_ON_ERROR)); return $m;
}
function vrun(string $dir,array $options=[]): VerifyReport { return verify_replay($dir.'/journal.sql',$dir.'/baseline.sql',$dir.'/manifest.json',$options+['enabled'=>true,'temp_parent'=>$dir]); }
function vprobe(string $case): bool {
    $s=new VerifySandbox(verify_options(['enabled'=>true]));
    try {
        $s->start();
        $code= <<<'PHP'
require $argv[1];
set_error_handler(static fn()=>true);
$db=ThreadFin\DB\VerifyWorker::connect($argv[2]);
$db->query('CREATE TABLE t (id INT PRIMARY KEY) ENGINE=InnoDB');
$denied=[];
foreach (["SELECT * FROM mysql.user", "SELECT 'SECRET' INTO OUTFILE '".$argv[2]."/files/escape'", "GRANT ALL ON *.* TO 'replay'@'localhost'", "CREATE FUNCTION tf_escape RETURNS INTEGER SONAME 'lib_mysqludf_sys.so'", "SET GLOBAL local_infile=1", "LOAD DATA LOCAL INFILE '/etc/passwd' INTO TABLE t"] as $sql) {
 try { $db->query($sql); $denied[]=0; } catch(Throwable $e) { $denied[]=$e->getCode(); }
}
$none=$db->query("SELECT LOAD_FILE('/etc/passwd'), @@local_infile")->fetch_row();
$db->query('INSERT INTO t VALUES (1)');
$ok=$denied===[1142,1227,1045,1044,1227,4166] && $none[0]===null && (int)$none[1]===0 && $db->query('SELECT * FROM t')->num_rows===1;
file_put_contents($argv[2].'/probe',json_encode($ok ? true : $denied)); $db->close();
PHP;
        $status=$s->run([PHP_BINARY,'-n','-d','extension='.(getenv('THREADFIN_TEST_MYSQLI_EXTENSION')?:'mysqli'),'-r',$code,realpath(__DIR__.'/../../db_verify.php'),$s->directory]);
        assert($status===0 && file_get_contents($s->directory.'/probe')==='true',file_get_contents($s->directory.'/probe')); return true;
    } finally { assert($s->cleanup()); }
}
function vcase(string $case,mysqli $admin,string $dir,array &$tables,?string &$user): bool {
    if ($case==='backtick_table' || $case==='backtick_column') {
        $name='tf_verify_'.bin2hex(random_bytes(4)).($case==='backtick_table'?chr(92):'');
        $q=ThreadFin\DB\verify_identifier($name);
        $column=$case==='backtick_column'?'`id'.chr(92).'`':'id';
        $comment= <<<'SQL'
COMMENT='` ENGINE=InnoDB \'abc'
SQL;
        $sql="CREATE TABLE $q ($column INT PRIMARY KEY) ENGINE=MyISAM $comment";
        // Prove this is real valid SQL with the engine hidden by the old lexer.
        $admin->query($sql); $tables[]=$name;
        $ddl=$admin->query('SHOW CREATE TABLE '.$q)->fetch_row()[1];
        assert(str_contains($ddl,'ENGINE=MyISAM'));
        $admin->query('DROP TABLE '.$q);
        // The column variant can erase its own unsupported table: the old
        // verifier then incorrectly reports a fully matching empty snapshot.
        $body=$sql.';'.($case==='backtick_column'?'DROP TABLE '.$q.';':'');
        foreach ([false,true] as $inJournal) {
            $journal=vpacket($inJournal?$body:'SELECT 1');
            vmanifest($dir,$inJournal?'':$body,$journal,[]);
            $report=vrun($dir);
            assert($report->status==='unsupported' && !$report->matched && $report->cleanup,json_encode($report));
            assert($report->position===($inJournal?strpos($journal,'CREATE TABLE'):null));
            assert(glob($dir.'/tfv-*')===[]);
        }
        return true;
    }
    if (in_array($case,['numeric_match','numeric_mismatch','numeric_zero'],true)) {
        $names=$case==='numeric_zero'?['0']:['0','123','00123']; $baseline='';
        foreach ($names as $name) {
            $ddl="CREATE TABLE `$name` (id INT PRIMARY KEY, v VARCHAR(30)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin";
            $admin->query($ddl); $tables[]=$name;
            $insert="INSERT INTO `$name` VALUES (1,'initial-$name')"; $admin->query($insert);
            $baseline.=$ddl.";\n".$insert.";\n";
        }
        $changed=$case==='numeric_zero'?'0':'123';
        $body="UPDATE `$changed` SET v='journal-change' WHERE id=1"; $admin->query($body);
        if ($case==='numeric_mismatch') { $admin->query("UPDATE `00123` SET v='expected-only-change' WHERE id=1"); }
        $expected=verify_snapshot($admin);
        assert(json_decode(json_encode($expected,JSON_THROW_ON_ERROR),true,512,JSON_THROW_ON_ERROR)===$expected);
        $manifest=vmanifest($dir,$baseline,vpacket($body),$expected);
        $decoded=json_decode(file_get_contents($dir.'/manifest.json'),true,512,JSON_THROW_ON_ERROR);
        assert($decoded===$manifest);
        $report=vrun($dir); assert($report->cleanup && glob($dir.'/tfv-*')===[]);
        assert($report->status===($case==='numeric_mismatch'?'mismatch':'matched'),json_encode($report));
        $reported=array_column($report->tables,null,'table_id'); assert(count($reported)===count($names));
        foreach ($names as $name) {
            $item=$reported[hash('sha256',$name)];
            assert($item['expected']===$expected[$name]);
            assert($item['matched']!==($case==='numeric_mismatch' && $name==='00123'));
        }
        assert(hash('sha256','00123')!==hash('sha256','123')); return true;
    }
    $name='tf_verify_'.bin2hex(random_bytes(4));
    if ($case==='counter' || $case==='counter_quoted') {
        if ($case==='counter_quoted') { $name.=' AUTO_INCREMENT=7'; }
        $q='`'.$name.'`';
        $column=$case==='counter_quoted'?", `AUTO_INCREMENT=8` VARCHAR(80) DEFAULT 'A  AUTO_INCREMENT=11  B' COMMENT 'X  AUTO_INCREMENT=22  Y'":'';
        $ddl="CREATE TABLE $q (id INT AUTO_INCREMENT PRIMARY KEY$column) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin";
        $admin->query($ddl); $tables[]=$name;
        $raw=$admin->query('SHOW CREATE TABLE '.$q)->fetch_row()[1];
        $absent=verify_snapshot($admin);
        assert($absent[$name]['schema_sha256']===hash('sha256',$raw));
        foreach ([42,99] as $counter) {
            $admin->query("ALTER TABLE $q AUTO_INCREMENT=$counter");
            assert(verify_snapshot($admin)===$absent,'Absent/present/different counters must have equal fingerprints');
        }
        vmanifest($dir,$ddl.';',vpacket("ALTER TABLE $q AUTO_INCREMENT=99"),$absent);
        $report=vrun($dir); assert($report->matched && $report->cleanup,json_encode($report));
        if ($case==='counter_quoted') {
            $admin->query("ALTER TABLE $q ALTER COLUMN `AUTO_INCREMENT=8` SET DEFAULT 'A AUTO_INCREMENT=11  B'");
            assert(verify_snapshot($admin)[$name]['schema_sha256']!==$absent[$name]['schema_sha256'],'Quoted whitespace remains significant');
        }
        return true;
    }
    if ($case==='adversarial') { $name.='` ; --'; }
    $q='`'.str_replace('`','``',$name).'`';
    $ddl="CREATE TABLE $q (id BIGINT PRIMARY KEY, v VARBINARY(100), s VARCHAR(100), d DECIMAL(12,2), y YEAR(4)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin";
    if ($case==='privileges') { return vprobe($case); }
    if ($case==='disk') {
        $s=new VerifySandbox(verify_options(['enabled'=>true,'max_disk_bytes'=>134217728])); $path=$s->directory;
        try {
            $s->start(); $caught=false;
            try { $s->run([PHP_BINARY,'-n','-r','$f=fopen($argv[1]."/big","wb"); ftruncate($f,200000000); sleep(5);',$path]); }
            catch(ThreadFin\DB\VerifyFailure $e) { assert($e->status==='disk_limit'); $caught=true; }
            assert($caught);
        } finally { assert($s->cleanup()); }
        assert(!file_exists($path)); return true;
    }
    if ($case==='cleanup_identity') {
        $s=new VerifySandbox(verify_options(['enabled'=>true])); $p=$s->directory; rename($p,$p.'-owned'); mkdir($p,0700); file_put_contents($p.'/canary','secret');
        assert(!$s->cleanup() && file_get_contents($p.'/canary')==='secret'); vremove($p); rename($p.'-owned',$p); assert($s->cleanup()); return true;
    }
    $admin->query($ddl); $tables[]=$name;
    $insert="INSERT INTO $q VALUES (0,X'000AFF','00123',1.20,1970),(1,NULL,'',-2.00,2070)";
    $admin->query($insert); $baseline=$ddl.";\n".$insert.";\n";
    if ($case==='actual_dump') {
        define('SQL_ERROR_FILE',false); require_once __DIR__.'/../../core.php'; require_once __DIR__.'/../../db.php';
        $db=ThreadFin\DB\DB::from(vconnect());
        try { $dump=$db->dump_resumable($dir.'/dump.sql',$dir.'/checkpoint.json',['source_id'=>'verify-baseline','source_frozen'=>true,'source_idle'=>true]); assert($dump->complete); }
        finally { $db->close(); }
        $baseline=file_get_contents($dir.'/dump.sql');
    }
    if ($case==='unsupported_type' || $case==='policy') {
        if ($case==='unsupported_type') { $admin->query("ALTER TABLE $q ADD bad YEAR(2)"); } else { $admin->query("SET time_zone='+01:00'"); }
        $caught=false; try { verify_snapshot($admin); } catch(ThreadFin\DB\VerifyFailure $e) { assert($e->status===($case==='policy'?'policy':'unsupported')); $caught=true; }
        assert($caught); return true;
    }
    $body="INSERT INTO $q VALUES (2,X'3B275C', 'SECRET_CANARY',3.14,2000)";
    if ($case==='adversarial') {
        $value="utf8 😀; \\ '\n-- End ThreadFin replay session\n";
        $body="INSERT INTO $q VALUES (2,X'3B275C', '".$admin->real_escape_string($value)."',3.14,2000)"; $admin->query($body);
    }
    elseif ($case==='rollback') { $body="START TRANSACTION; $body; ROLLBACK; START TRANSACTION; UPDATE $q SET s='committed' WHERE id=0; COMMIT AND NO CHAIN NO RELEASE"; $admin->query("UPDATE $q SET s='committed' WHERE id=0"); }
    elseif ($case==='timeout') { $body='SELECT SLEEP(20)'; }
    elseif ($case==='sql_error') { $body="INSERT INTO $q VALUES (1,X'00','SECRET_ERROR_VALUE',0,2001)"; }
    elseif ($case==='cross_schema') { $body='DELETE FROM mysql.user'; }
    elseif ($case==='file') { $body="SELECT 'SECRET_ERROR_VALUE' INTO OUTFILE '/tmp/escape'"; }
    elseif ($case==='local') { $body="LOAD DATA LOCAL INFILE '/etc/passwd' INTO TABLE $q"; }
    elseif ($case==='metacommand') { $body='\\! touch /tmp/escape'; }
    else { $admin->query($body); }
    $journal=vpacket($body);
    if ($case==='shared') { $admin->query("UPDATE $q SET s='shared' WHERE id=0"); $journal.=vpacket("UPDATE $q SET s='shared' WHERE id=0"); }
    if ($case==='actual_journal') {
        define('SQL_ERROR_FILE',false); require __DIR__.'/../../core.php'; require __DIR__.'/../../db.php';
        $db=ThreadFin\DB\DB::from(vconnect()); $db->enable_replay($dir.'/actual.sql');
        try {
            $db->transaction(function($db) use($q) {
                try { $db->transaction(function($db) use($q) { $db->unsafe_raw("UPDATE $q SET s='nested-rollback' WHERE id=1"); throw new RuntimeException('rollback'); }); } catch(RuntimeException $e) {}
                $db->unsafe_raw("UPDATE $q SET s='actual' WHERE id=0");
            });
        } finally { $db->close(); }
        $db=ThreadFin\DB\DB::from(vconnect()); $db->enable_replay($dir.'/actual.sql');
        try { $db->unsafe_raw('START TRANSACTION'); $db->unsafe_raw("UPDATE $q SET s='session-rollback' WHERE id=1"); $db->unsafe_raw('ROLLBACK'); } finally { $db->close(); }
        $journal=vpacket($body).file_get_contents($dir.'/actual.sql');
    }
    if ($case==='readonly') {
        $user='tfv_'.bin2hex(random_bytes(5)); $admin->query("CREATE USER '$user'@'localhost'");
        $schema='`'.str_replace('`','``',getenv('THREADFIN_TEST_MYSQL_DATABASE')).'`'; $admin->query("GRANT SELECT ON $schema.$q TO '$user'@'localhost'");
        $read=vconnect($user); try { $expected=verify_snapshot($read); assert($expected===verify_snapshot($admin)); } finally { $read->close(); }
    } else { $expected=verify_snapshot($admin); }
    if ($case==='schema_mismatch') { $expected[$name]['schema_sha256']=str_repeat('a',64); }
    if ($case==='count_mismatch') { $expected[$name]['count']++; }
    if ($case==='row_mismatch') { $expected[$name]['rows_sha256']=str_repeat('a',64); }
    if ($case==='baseline_mismatch') { $baseline=str_replace('00123','different',$baseline); }
    if ($case==='dump_baseline') {
        $baseline="-- ThreadFin resumable dump v1; frozen source required\nSET @threadfin_dump_mode = @@SESSION.sql_mode;\nSET @threadfin_dump_autocommit = @@SESSION.autocommit;\nSET SESSION autocommit = 1;\nSET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO';\nSET NAMES utf8mb4;\n".$baseline."SET SESSION sql_mode = @threadfin_dump_mode;\nSET SESSION autocommit = @threadfin_dump_autocommit;\n";
    }
    if ($case==='duplicate') { $journal.=$journal; }
    if ($case==='incomplete') { $journal=substr($journal,0,-15); }
    $manifest=vmanifest($dir,$baseline,$journal,$expected);
    if ($case==='cut') { file_put_contents($dir.'/journal.sql','INCOMPLETE TRAILING DATA',FILE_APPEND); }
    if ($case==='baseline_hash') { file_put_contents($dir.'/baseline.sql',"\n--changed",FILE_APPEND); }
    if ($case==='hash') { file_put_contents($dir.'/journal.sql','x',FILE_APPEND); $manifest['journal']['sha256']=str_repeat('f',64); file_put_contents($dir.'/manifest.json',json_encode($manifest)); }
    if ($case==='cleanup_failure') { $GLOBALS['verify_cleanup_fault']=true; }
    $start=microtime(true); $report=vrun($dir,$case==='timeout'?['timeout_seconds'=>5]:[]); $GLOBALS['verify_cleanup_fault']=false;
    if ($case==='timeout') { assert($report->status==='timeout' && $report->position!==null && microtime(true)-$start<8.5); }
    elseif ($case==='cleanup_failure') { assert($report->status==='cleanup' && !$report->matched && !$report->cleanup); foreach(glob($dir.'/tfv-*') as $d) { vremove($d); } }
    elseif (in_array($case,['schema_mismatch','count_mismatch','row_mismatch','baseline_mismatch'],true)) { assert($report->status==='mismatch' && !$report->matched); }
    elseif ($case==='sql_error' || $case==='cross_schema') { assert($report->status==='replay' && $report->position!==null); }
    elseif (in_array($case,['file','local','metacommand'],true)) { assert($report->status==='unsupported'); }
    elseif (in_array($case,['duplicate','incomplete'],true)) { assert($report->status===$case); }
    elseif ($case==='hash' || $case==='baseline_hash') { assert($report->status==='integrity'); }
    else { assert($report->matched, json_encode($report)); }
    if ($case!=='cleanup_failure') { assert($report->cleanup && glob($dir.'/tfv-*')===[]); }
    $json=json_encode($report); foreach(['SECRET_CANARY','SECRET_ERROR_VALUE','/tmp/escape','root','server.sock',$name] as $secret) { assert(!str_contains($json,$secret)); }
    if ($case==='fresh') { assert(vrun($dir)->matched); }
    if ($case==='cli') {
        $cmd=[PHP_BINARY,'-n','-d','extension='.(getenv('THREADFIN_TEST_MYSQLI_EXTENSION')?:'mysqli'),__DIR__.'/../../bin/db-verify.php','--enable-sandbox',$dir.'/journal.sql',$dir.'/baseline.sql',$dir.'/manifest.json',$dir.'/cli-report.json'];
        $p=proc_open($cmd,[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        $stdout=stream_get_contents($pipes[1]); $stderr=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        assert(proc_close($p)===0 && $stderr==='' && json_decode($stdout,true)['matched']);
        assert(file_get_contents($dir.'/cli-report.json')===$stdout);
    }
    if ($case==='report') {
        verify_write_report($report,$dir.'/report.json',[$dir.'/journal.sql',$dir.'/baseline.sql',$dir.'/manifest.json']);
        assert(json_decode(file_get_contents($dir.'/report.json'),true)['matched'] && (fileperms($dir.'/report.json')&0777)===0600);
        $before=file_get_contents($dir.'/journal.sql'); $caught=false;
        try { verify_write_report($report,$dir.'/journal.sql',[$dir.'/journal.sql']); } catch(ThreadFin\DB\VerifyFailure $e) { $caught=true; }
        assert($caught && file_get_contents($dir.'/journal.sql')===$before);
    }
    return true;
}
ob_start(); $admin=null; $tables=[]; $user=null; $dir=sys_get_temp_dir().'/tfvt-'.bin2hex(random_bytes(5)); mkdir($dir,0700);
try {
    if (!getenv('THREADFIN_TEST_MYSQL_SOCKET') || !getenv('THREADFIN_TEST_MYSQL_DATABASE') || getenv('THREADFIN_TEST_MYSQL_ALLOW_SCHEMA_CHANGES')!=='1') { throw new RuntimeException('Parent-owned disposable source required'); }
    mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT); $admin=vconnect(); assert($admin->query('SHOW TABLES')->num_rows===0);
    $r=['value'=>vcase($argv[1],$admin,$dir,$tables,$user),'error'=>null];
} catch(Throwable $e) { $r=['value'=>false,'error'=>get_class($e).': '.$e->getMessage().' line '.$e->getLine()]; }
finally { $GLOBALS['verify_cleanup_fault']=false; if ($admin!==null) { foreach($tables as $table) { $admin->query('DROP TABLE IF EXISTS `'.str_replace('`','``',$table).'`'); } if($user!==null) { $admin->query("DROP USER '$user'@'localhost'"); } $admin->close(); } vremove($dir); }
$r['output']=ob_get_clean(); echo json_encode($r,JSON_THROW_ON_ERROR);
}
