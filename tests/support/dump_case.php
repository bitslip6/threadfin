<?php declare(strict_types=1);
namespace ThreadFin\DB {
    function rename(string $from, string $to): bool {
        $fault = $GLOBALS['dump_fault'] ?? '';
        if ($fault === 'checkpoint_before') { $GLOBALS['dump_fault']=''; throw new \RuntimeException('Injected before publication'); }
        $ok = \rename($from, $to);
        if ($fault === 'checkpoint_after') { $GLOBALS['dump_fault']=''; throw new \RuntimeException('Injected after publication'); }
        return $ok;
    }
    function fwrite($stream, string $data): int|false {
        if (($GLOBALS['dump_fault'] ?? '') === 'checkpoint_write' && str_contains(stream_get_meta_data($stream)['uri'],'.tmp.')) {
            $GLOBALS['dump_fault']=''; \fwrite($stream,substr($data,0,7)); throw new \RuntimeException('Injected checkpoint partial write');
        }
        if (($GLOBALS['dump_fault'] ?? '') === 'write_failure') {
            $GLOBALS['dump_fault']=''; \fwrite($stream, substr($data,0,7)); throw new \RuntimeException('Injected partial write');
        }
        return \fwrite($stream, $data);
    }
    function fflush($stream): bool {
        if (($GLOBALS['dump_fault'] ?? '') === 'checkpoint_flush' && str_contains(stream_get_meta_data($stream)['uri'],'.tmp.')) { $GLOBALS['dump_fault']=''; return false; }
        if (($GLOBALS['dump_fault'] ?? '') === 'flush_failure') { $GLOBALS['dump_fault']=''; return false; }
        return \fflush($stream);
    }
}
namespace {
error_reporting(E_ALL & ~E_DEPRECATED);
define('SQL_ERROR_FILE', false);
require __DIR__.'/mysqli.php';
require __DIR__.'/../../core.php';
require __DIR__.'/../../db.php';
use ThreadFin\DB\DB;
use ThreadFin\DB\SQL;
use function ThreadFin\DB\dump_resumable;
class DumpFixtureDB extends DB {
    public array $calls=[];
    public string $ddl='CREATE TABLE `items` (`id` bigint unsigned NOT NULL, `body` blob, PRIMARY KEY (`id`)) ENGINE=InnoDB';
    public string $hostIdentity='fixture-host';
    public array $instanceIdentity=['socket'=>'/fixture-private/socket', 'datadir'=>'/fixture-private/data/', 'server_id'=>'17'];
    public string $keyType='bigint unsigned';
    public string $bodyType='blob';
    public bool $changeLifecycle=false;
    public ?SQL $lastResult=null;
    public array $fixtureRows=[['0','00FF'],['9223372036854775808','3030313233'],['18446744073709551615',null]];
    public function __construct() { parent::__construct(new mysqli()); }
    public function fetch_prepared(string $sql, array $parameters=[]): SQL {
        $this->calls[]=[$sql,$parameters];
        switch ($sql) {
            case 'SELECT DATABASE() AS db, @@hostname AS host, @@port AS port, @@version AS version, @@socket AS socket, @@datadir AS datadir, @@server_id AS server_id, @@SESSION.sql_mode AS mode, @@SESSION.sql_quote_show_create AS quote_create, @@SESSION.autocommit AS autocommit':
                return SQL::from([['db'=>'fixture','host'=>$this->hostIdentity,'port'=>'0','version'=>'fixture-1','mode'=>'STRICT_TRANS_TABLES','quote_create'=>'1','autocommit'=>'1'] + $this->instanceIdentity]);
            case 'SELECT CURRENT_USER() AS account': return SQL::from([['account'=>'root@localhost']]);
            case "SELECT NULL AS scope FROM information_schema.USER_PRIVILEGES WHERE GRANTEE = ? AND PRIVILEGE_TYPE = 'TRIGGER' UNION ALL SELECT NULL AS scope FROM information_schema.SCHEMA_PRIVILEGES WHERE GRANTEE = ? AND TABLE_SCHEMA = ? AND PRIVILEGE_TYPE = 'TRIGGER' UNION ALL SELECT TABLE_NAME AS scope FROM information_schema.TABLE_PRIVILEGES WHERE GRANTEE = ? AND TABLE_SCHEMA = ? AND PRIVILEGE_TYPE = 'TRIGGER'": return SQL::from([['scope'=>null]]);
            case 'SELECT TABLE_NAME, TABLE_TYPE, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME':
                assert($parameters===['fixture']); return SQL::from([['TABLE_NAME'=>'items','TABLE_TYPE'=>'BASE TABLE','ENGINE'=>'InnoDB']]);
            case 'SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, CHARACTER_SET_NAME, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION':
                return SQL::from([
                    ['COLUMN_NAME'=>'id','DATA_TYPE'=>explode(' ',$this->keyType)[0],'COLUMN_TYPE'=>$this->keyType,'IS_NULLABLE'=>'NO','CHARACTER_SET_NAME'=>null,'EXTRA'=>''],
                    ['COLUMN_NAME'=>'body','DATA_TYPE'=>str_starts_with(strtolower($this->bodyType),'year')?'year':'blob','COLUMN_TYPE'=>$this->bodyType,'IS_NULLABLE'=>'YES','CHARACTER_SET_NAME'=>null,'EXTRA'=>'']]);
            case 'SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX':
                assert($parameters===['fixture','items','PRIMARY']); return SQL::from([['COLUMN_NAME'=>'id']]);
            case 'SELECT CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?':
                return SQL::from([['CONSTRAINT_TYPE'=>'PRIMARY KEY']]);
            case 'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? AND EVENT_OBJECT_TABLE = ?': return SQL::from([]);
            case 'SHOW CREATE TABLE `fixture`.`items`': return SQL::from([['Table'=>'items','Create Table'=>$this->ddl]]);
            case 'SELECT CAST(`id` AS CHAR) AS `c0`, HEX(CAST(`body` AS BINARY)) AS `c1` FROM `fixture`.`items` ORDER BY `id` LIMIT 1':
                if($this->changeLifecycle) { $this->enable_simulation(true); }
                return $this->lastResult=SQL::from([['c0'=>$this->fixtureRows[0][0],'c1'=>$this->fixtureRows[0][1]]]);
            case 'SELECT CAST(`id` AS CHAR) AS `c0`, HEX(CAST(`body` AS BINARY)) AS `c1` FROM `fixture`.`items` WHERE `id` > CAST(? AS DECIMAL(20,0)) ORDER BY `id` LIMIT 1':
                foreach($this->fixtureRows as $index=>$row) {
                    if($parameters===[$row[0]]) { $next=$this->fixtureRows[$index+1]??null; return SQL::from($next===null?[]:[['c0'=>$next[0],'c1'=>$next[1]]]); }
                }
        }
        throw new RuntimeException('Unexpected canned query: '.$sql);
    }
}
function dump_failure(callable $call): Throwable {
    try { $call(); } catch(Throwable $e) { return $e; }
    throw new RuntimeException('Expected failure');
}
function dump_unit_case(string $case): bool {
    if ($case !== 'disabled') { assert(function_exists('ThreadFin\\DB\\dump_resumable')); }
    $dir=sys_get_temp_dir().'/threadfin-dump-unit-'.bin2hex(random_bytes(6)); mkdir($dir,0700);
    $out=$dir.'/dump.sql'; $cp=$dir.'/checkpoint.json'; $db=new DumpFixtureDB();
    $options=['source_frozen'=>true,'source_idle'=>true,'source_id'=>'unit-fixture','batch_rows'=>1];
    $run=fn(array $extra=[])=>dump_resumable($db,$out,$cp,array_replace($options,$extra));
    try {
        switch($case) {
            case 'instance_identity':
                $r=$run(['max_batches'=>3]);
                file_put_contents($out,'uncommitted',FILE_APPEND);
                $before=file_get_contents($out); $checkpoint=file_get_contents($cp);
                foreach(['socket','datadir','server_id'] as $field) {
                    $old=$db->instanceIdentity[$field]; $db->instanceIdentity[$field].='different';
                    $e=dump_failure($run); assert($e->getMessage()==='Dump checkpoint identity/schema/options/state mismatch');
                    assert(file_get_contents($out)===$before && file_get_contents($cp)===$checkpoint);
                    $db->instanceIdentity[$field]=$old;
                }
                foreach(['socket','datadir'] as $field) { assert(!str_contains($checkpoint.json_encode($r,JSON_UNESCAPED_SLASHES),$db->instanceIdentity[$field])); }
                assert($run()->complete); break;
            case 'year_variants':
                foreach(['year(2)','year(3)','year(04)','year(4) unsigned','year unexpected'] as $type) {
                    $db->bodyType=$type; dump_failure($run); assert(!file_exists($out) && !file_exists($cp));
                }
                foreach(['year','year(4)'] as $type) {
                    $db->bodyType=$type; assert($run(['max_batches'=>1])->phase==='ddl'); unlink($out); unlink($cp);
                }
                break;
            case 'disabled':
                $ordinary=DB::from(new mysqli()); $ordinary->fetch('SELECT 1'); $ordinary->close();
                assert(!class_exists('ThreadFin\\DB\\DumpReport',false) && !class_exists('ThreadFin\\DB\\ResumableDump',false)); break;
            case 'lifecycle':
                $run(['max_batches'=>2]); $before=file_get_contents($cp); $output=file_get_contents($out);
                $db->changeLifecycle=true; dump_failure($run);
                assert(file_get_contents($cp)===$before && file_get_contents($out)===$output && count($db->lastResult)===0); break;
            case 'options_changed':
                $run(['max_batches'=>3]); $before=file_get_contents($out);
                dump_failure(fn()=>$run(['batch_rows'=>2])); dump_failure(fn()=>$run(['tables'=>[]])); assert(file_get_contents($out)===$before); break;
            case 'simulation':
                $db->enable_simulation(true); dump_failure($run); assert($db->calls===[] && !file_exists($out) && !file_exists($cp)); break;
            case 'options':
                foreach([['source_frozen'=>false],['source_idle'=>false],['source_id'=>''],['batch_rows'=>0],['batch_rows'=>10001],['max_bytes'=>-1],['max_batches'=>-1],['unknown'=>1]] as $bad) {
                    dump_failure(fn()=>$run($bad)); assert(!file_exists($out) && $db->calls===[]);
                }
                foreach(['source_frozen','source_idle','source_id'] as $key) {
                    $missing=$options; unset($missing[$key]); dump_failure(fn()=>dump_resumable($db,$out,$cp,$missing));
                    assert($db->calls===[] && !file_exists($out));
                }
                break;
            case 'paths':
                foreach(['php://memory',$dir.'/absent/x.sql'] as $bad) { dump_failure(fn()=>dump_resumable($db,$bad,$cp,$options)); }
                dump_failure(fn()=>dump_resumable($db,$out,$out,$options));
                file_put_contents($dir.'/target','untouched'); symlink($dir.'/target',$out);
                dump_failure($run); assert(file_get_contents($dir.'/target')==='untouched'); unlink($out);
                file_put_contents($out,'unknown orphan'); dump_failure($run); assert(file_get_contents($out)==='unknown orphan'); break;
            case 'budget':
                $r=$run(['max_bytes'=>1]); assert(!$r->complete && $r->status==='budget' && $r->bytes_written===0 && $r->required_bytes>1);
                assert(!file_exists($out) || filesize($out)===0);
                $r=$run(['max_batches'=>1]); assert(!$r->complete && $r->phase==='ddl' && $r->rows===0);
                $before=file_get_contents($out); $r=$run(['max_bytes'=>1]); assert(file_get_contents($out)===$before && $r->required_bytes>1); break;
            case 'resume': case 'cursor_fidelity':
                $iterations=0;
                do { $r=$run(['max_batches'=>1]); assert(++$iterations<15); } while(!$r->complete);
                assert($r->rows===3); $sql=file_get_contents($out); assert(substr_count($sql,'CREATE TABLE')===1 && substr_count($sql,'INSERT INTO')===3);
                assert(str_contains($sql,'18446744073709551615') && str_contains($sql,"X'00FF'") && str_contains($sql,'NULL'));
                $calls=count($db->calls); $r=$run(); assert($r->complete && $r->bytes_written===0);
                foreach($db->calls as [$q,$p]) { if(str_contains($q,' WHERE `id`')) { assert(is_string($p[0]) && str_contains($q,'> CAST(? AS DECIMAL(20,0))')); } }
                assert(count($db->calls)>$calls); break;
            case 'unsupported':
                $db->keyType='varchar'; dump_failure($run); assert(!file_exists($out) && !file_exists($cp)); break;
            case 'schema_drift': case 'source_drift':
                $run(['max_batches'=>2]); $before=file_get_contents($out);
                if($case==='schema_drift') { $db->ddl.=' COMMENT=\'changed\''; } else { $db->hostIdentity='another-host'; }
                dump_failure($run); assert(file_get_contents($out)===$before); break;
            case 'header_orphan':
                $GLOBALS['dump_fault']='checkpoint_before'; dump_failure($run);
                assert(filesize($out)>0 && !file_exists($cp)); $before=file_get_contents($out); dump_failure($run); assert(file_get_contents($out)===$before); break;
            case 'checkpoint_before': case 'checkpoint_after': case 'ddl_before': case 'write_failure': case 'flush_failure': case 'checkpoint_write': case 'checkpoint_flush':
                $run(['max_batches'=>$case==='ddl_before'?1:2]); $checkpoint=file_get_contents($cp);
                $GLOBALS['dump_fault']=$case==='ddl_before'?'checkpoint_before':$case; dump_failure(fn()=>$run(['max_batches'=>1]));
                assert(($case==='checkpoint_after') !== (file_get_contents($cp)===$checkpoint));
                $r=$run(); assert($r->complete && $r->rows===3);
                $sql=file_get_contents($out); assert(substr_count($sql,'CREATE TABLE')===1 && substr_count($sql,'INSERT INTO')===3); break;
            default:
                $run(['max_batches'=>3]); $before=file_get_contents($out);
                switch($case) {
                    case 'ahead': file_put_contents($out,'UNCOMMITTED',FILE_APPEND); assert($run()->complete); assert(!str_contains(file_get_contents($out),'UNCOMMITTED')); break;
                    case 'short': file_put_contents($out,substr($before,0,-1)); dump_failure($run); assert(file_get_contents($out)===substr($before,0,-1)); break;
                    case 'corrupt': file_put_contents($out,'!'.substr($before,1)); dump_failure($run); break;
                    case 'identity': rename($out,$dir.'/old.sql'); file_put_contents($out,$before); dump_failure($run); break;
                    case 'checkpoint_corrupt': file_put_contents($cp,'{"version":999}'); dump_failure($run); assert(file_get_contents($out)===$before); break;
                    case 'lock': $handle=fopen($out,'r+'); flock($handle,LOCK_EX); try { dump_failure($run); } finally { fclose($handle); } break;
                    default: throw new RuntimeException('Unknown case');
                }
        }
        return true;
    } finally { $db->close(); foreach(glob($dir.'/*') as $file) { unlink($file); } rmdir($dir); }
}
ob_start();
try { $report=['value'=>dump_unit_case($argv[1]??''),'error'=>null]; }
catch(Throwable $e) { $report=['value'=>null,'error'=>get_class($e).': '.$e->getMessage().' at '.$e->getFile().':'.$e->getLine()]; }
$report['output']=ob_get_clean(); echo json_encode($report,JSON_THROW_ON_ERROR);
}
