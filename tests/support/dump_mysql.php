<?php declare(strict_types=1);
namespace ThreadFin\DB {
    function rename(string $from, string $to): bool {
        if (($GLOBALS['dump_native_crash'] ?? '') === 'before') { exit(73); }
        $ok=\rename($from,$to);
        if (($GLOBALS['dump_native_crash'] ?? '') === 'after') { exit(73); }
        return $ok;
    }
    function fwrite($stream, string $data): int|false {
        if (($GLOBALS['dump_native_crash'] ?? '') === 'partial') { \fwrite($stream,substr($data,0,7)); \fflush($stream); exit(73); }
        return \fwrite($stream,$data);
    }
}
namespace {
error_reporting(E_ALL & ~E_DEPRECATED);
define('SQL_ERROR_FILE',false);
require __DIR__.'/../../core.php';
require __DIR__.'/../../db.php';
use ThreadFin\DB\DB;
use function ThreadFin\DB\dump_resumable;
function dump_native_handle(?string $user=null): mysqli {
    return new mysqli('localhost',$user ?? (getenv('THREADFIN_TEST_MYSQL_USER') ?: 'root'),'',''.getenv('THREADFIN_TEST_MYSQL_DATABASE'),null,getenv('THREADFIN_TEST_MYSQL_SOCKET'));
}
function dump_native_id(string $name): string { return '`'.str_replace('`','``',$name).'`'; }
function dump_native_failure(callable $run): Throwable {
    try { $run(); } catch(Throwable $e) { return $e; }
    throw new RuntimeException('Expected rejection');
}
function dump_native_script(mysqli $h, string $sql): void {
    $h->multi_query($sql);
    do { $r=$h->store_result(); if($r instanceof mysqli_result) { $r->free(); } if(!$h->more_results()) { break; } $h->next_result(); } while(true);
}
function dump_native_snapshot(mysqli $h, array $tables): array {
    $snapshot=[];
    foreach($tables as $t) {
        $q=dump_native_id($t); $columns=$h->query('SHOW FULL COLUMNS FROM '.$q)->fetch_all(MYSQLI_ASSOC);
        $key=$h->query('SHOW KEYS FROM '.$q." WHERE Key_name='PRIMARY'")->fetch_assoc()['Column_name'];
        $expressions=[];
        foreach($columns as $c) { $expressions[]='HEX(CAST('.dump_native_id($c['Field']).' AS BINARY)) AS '.dump_native_id($c['Field']); }
        $snapshot[$t]=[$h->query('SHOW CREATE TABLE '.$q)->fetch_assoc()['Create Table'], $h->query('SELECT '.implode(',',$expressions).' FROM '.$q.' ORDER BY '.dump_native_id($key))->fetch_all(MYSQLI_ASSOC)];
    }
    return $snapshot;
}
function dump_native_crash(string $out,string $cp,array $options,string $fault): void {
    $command=[PHP_BINARY,'-n','-d','extension='.(getenv('THREADFIN_TEST_MYSQLI_EXTENSION') ?: 'mysqli'),__FILE__,'worker',$out,$cp,json_encode($options),$fault];
    $p=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($p)) { throw new RuntimeException('Cannot start crash worker'); }
    fclose($pipes[0]); $stdout=stream_get_contents($pipes[1]); $stderr=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $exit=proc_close($p);
    assert($exit===73 && $stdout==='' && $stderr==='');
}
function dump_native_case(string $case,mysqli $admin,array &$owned,string $dir): bool {
    $prefix='tf_dump_'.bin2hex(random_bytes(5)); $a=$prefix.'_a` ; --'; $b=$prefix.'_b';
    $out=$dir.'/dump.sql'; $cp=$dir.'/checkpoint.json'; $h=dump_native_handle(); $db=DB::from($h);
    $options=['source_frozen'=>true,'source_idle'=>true,'source_id'=>'native-freeze','batch_rows'=>$case==='integer_keys'?1:2];
    $run=fn(array $extra=[])=>dump_resumable($db,$out,$cp,array_replace($options,$extra));
    try {
        $admin->query("SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'");
        $qa=dump_native_id($a); $qb=dump_native_id($b);
        if($case==='empty') { assert($run()->complete); $sql=file_get_contents($out); assert(!str_contains($sql,'CREATE TABLE')); return true; }
        if($case==='unsupported_sinks') {
            foreach(['php://memory','compress.zlib://'.$out] as $bad) { dump_native_failure(fn()=>dump_resumable($db,$bad,$cp,$options)); }
            file_put_contents($dir.'/target','intact'); symlink($dir.'/target',$out); dump_native_failure($run);
            assert(file_get_contents($dir.'/target')==='intact' && !file_exists($cp)); return true;
        }
        if($case==='source_instance') {
            $socket=getenv('THREADFIN_TEST_MYSQL_SECOND_SOCKET');
            if(!$socket || $socket===getenv('THREADFIN_TEST_MYSQL_SOCKET')) { throw new RuntimeException('Distinct parent-provisioned second socket required'); }
            $other=new mysqli('localhost',getenv('THREADFIN_TEST_MYSQL_USER') ?: 'root','',getenv('THREADFIN_TEST_MYSQL_DATABASE'),null,$socket);
            $otherDb=null; $otherOwned=false;
            try {
                if($other->query('SHOW TABLES')->num_rows!==0) { throw new RuntimeException('Second dump test database must be empty'); }
                $otherDb=DB::from($other);
                $oldIdentity='SELECT DATABASE(), @@hostname, @@port, @@version, @@SESSION.sql_mode';
                assert($h->query($oldIdentity)->fetch_row()===$other->query($oldIdentity)->fetch_row());
                $ddl='CREATE TABLE '.$qa.' (id INT PRIMARY KEY, v VARCHAR(20)) ENGINE=InnoDB';
                $admin->query($ddl); $owned[]=$a; $other->query($ddl); $otherOwned=true;
                $admin->query('INSERT INTO '.$qa." VALUES (1,'A-row1'),(2,'A-row2')");
                $other->query('INSERT INTO '.$qa." VALUES (1,'B-row1'),(2,'B-row2')");
                $opts=array_replace($options,['batch_rows'=>1]);
                $r=dump_resumable($db,$out,$cp,$opts+['max_batches'=>3]); assert($r->rows===1 && !$r->complete);
                file_put_contents($out,'UNCOMMITTED SUFFIX',FILE_APPEND);
                $beforeOutput=file_get_contents($out); $beforeCheckpoint=file_get_contents($cp);
                $failure=dump_native_failure(fn()=>dump_resumable($otherDb,$out,$cp,$opts));
                assert($failure->getMessage()==='Dump checkpoint identity/schema/options/state mismatch');
                assert(file_get_contents($out)===$beforeOutput && file_get_contents($cp)===$beforeCheckpoint);
                $r=dump_resumable($db,$out,$cp,$opts); assert($r->complete && $r->rows===2);
                foreach([$h,$other] as $connection) {
                    $paths=$connection->query('SELECT @@socket, @@datadir')->fetch_row();
                    $public=file_get_contents($cp).json_encode($r,JSON_UNESCAPED_SLASHES).$failure->getMessage();
                    foreach($paths as $identityPath) { assert($identityPath!=='' && !str_contains($public,$identityPath)); }
                }
                assert(!str_contains(file_get_contents($out),'UNCOMMITTED'));
            } finally {
                if($otherOwned) { $other->query('DROP TABLE '.$qa); }
                if($otherDb!==null) { $otherDb->close(); } else { $other->close(); }
            }
            return true;
        }
        if($case==='year2' || $case==='year4') {
            // Supported first table proves every selected schema is preflighted.
            $admin->query('CREATE TABLE '.$qa.' (id INT PRIMARY KEY) ENGINE=InnoDB'); $owned[]=$a;
            $admin->query('CREATE TABLE '.$qb.' (id INT PRIMARY KEY, y YEAR'.($case==='year2'?'(2)':', y4 YEAR(4)').') ENGINE=InnoDB'); $owned[]=$b;
            if($case==='year2') {
                $admin->query('INSERT INTO '.$qb.' VALUES (1,1970),(2,2070)');
                dump_native_failure($run);
                assert(!file_exists($out) && !file_exists($cp) && !file_exists($cp.'.lock'));
                // Numeric expansion, not the lossy two-digit textual representation.
                $admin->query('ALTER TABLE '.$qb.' MODIFY y YEAR(4)');
                assert($admin->query('SELECT y FROM '.$qb.' ORDER BY id')->fetch_all(MYSQLI_NUM)===[['1970'],['2070']]);
            } else {
                $admin->query('INSERT INTO '.$qb.' VALUES (1,0,0),(2,1901,1901),(3,1970,1970),(4,2000,2000),(5,2069,2069),(6,2070,2070),(7,2155,2155),(8,NULL,NULL)');
                $source=dump_native_snapshot($admin,[$a,$b]);
                do { $r=$run(['max_batches'=>1]); } while(!$r->complete);
                assert($r->rows===8);
                $admin->query('DROP TABLE '.$qa); $admin->query('DROP TABLE '.$qb);
                dump_native_script($admin,file_get_contents($out));
                assert(dump_native_snapshot($admin,[$a,$b])===$source);
                assert($admin->query('SELECT y, y4 FROM '.$qb.' WHERE id=6')->fetch_row()===['2070','2070']);
            }
            return true;
        }
        if($case==='unsupported_keys') {
            foreach(['(id INT, v INT)','(id INT, v INT, PRIMARY KEY(id,v))','(id VARCHAR(10) PRIMARY KEY)'] as $ddl) {
                $admin->query('CREATE TABLE '.$qa.$ddl.' ENGINE=InnoDB'); $owned[]=$a;
                dump_native_failure($run); assert(!file_exists($out) && !file_exists($cp)); $admin->query('DROP TABLE '.$qa);
            } return true;
        }
        if($case==='unsupported_types') {
            foreach(['FLOAT','DOUBLE','BIT(2)','JSON','GEOMETRY',"ENUM('a','b')","SET('a','b')",'TIMESTAMP','INT ZEROFILL','INT GENERATED ALWAYS AS (id+1) STORED'] as $type) {
                $admin->query('CREATE TABLE '.$qa.' (id INT PRIMARY KEY, v '.$type.') ENGINE=InnoDB'); $owned[]=$a;
                dump_native_failure($run); assert(!file_exists($out) && !file_exists($cp)); $admin->query('DROP TABLE '.$qa);
            } return true;
        }
        $admin->query('CREATE TABLE '.$qa.' (`key` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `b` BLOB, `t` VARCHAR(80) CHARACTER SET utf8mb4, `latin` VARCHAR(20) CHARACTER SET latin1, `n` DECIMAL(65,30), `d` DATETIME(6), `date` DATE, `time` TIME(6), `year` YEAR, `c` CHAR(5), `fixed` BINARY(4), `v` VARBINARY(8)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'); $owned[]=$a;
        $admin->query('CREATE TABLE '.$qb.' (`i``d` BIGINT PRIMARY KEY, `v` VARCHAR(20)) ENGINE=InnoDB'); $owned[]=$b;
        $admin->query('INSERT INTO '.$qa." VALUES (0,X'00FF275C0A',CONVERT(X'3030313233275CC3A9F09F9880' USING utf8mb4),CONVERT(X'E9FF' USING latin1),12345678901234567890123456789012345.123456789012345678901234567890,'2020-01-02 03:04:05.123456','0000-00-00','-838:59:59.123456',0,'x',X'00FF',X'00'),(9223372036854775808,X'', '', '',0,'0000-00-00 00:00:00','2024-02-29','00:00:00',2024,'',X'',X''),(18446744073709551615,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL)");
        $admin->query('INSERT INTO '.$qb." VALUES (-9223372036854775808,'minimum'),(-1,'negative'),(0,'zero'),(9223372036854775807,'maximum')");
        if($case==='unsupported_schema') {
            $admin->query('CREATE TRIGGER '.dump_native_id($prefix.'_tr').' BEFORE INSERT ON '.$qa.' FOR EACH ROW SET NEW.t = NEW.t');
            dump_native_failure($run); assert(!file_exists($out)); $admin->query('DROP TRIGGER '.dump_native_id($prefix.'_tr'));
            $admin->query('ALTER TABLE '.$qb.' ADD CONSTRAINT '.dump_native_id($prefix.'_check').' CHECK (`i``d` <> 123)');
            dump_native_failure($run); assert(!file_exists($out)); $admin->query('ALTER TABLE '.$qb.' DROP CONSTRAINT '.dump_native_id($prefix.'_check'));
            $admin->query('CREATE TABLE '.dump_native_id($prefix.'_fk').' (id BIGINT PRIMARY KEY, FOREIGN KEY (id) REFERENCES '.$qb.' (`i``d`)) ENGINE=InnoDB'); $owned[]=$prefix.'_fk';
            dump_native_failure($run); assert(!file_exists($out)); return true;
        }
        if($case==='unsupported_modes') {
            foreach(['ANSI_QUOTES','PAD_CHAR_TO_FULL_LENGTH','NO_TABLE_OPTIONS'] as $mode) {
                $h->query("SET SESSION sql_mode = '$mode'"); dump_native_failure($run); assert(!file_exists($out) && !file_exists($cp));
            } return true;
        }
        if($case==='metadata_visibility') {
            $user='tf_dump_user_'.bin2hex(random_bytes(5)); $account="'$user'@'localhost'"; $database=dump_native_id(getenv('THREADFIN_TEST_MYSQL_DATABASE'));
            $admin->query('CREATE USER '.$account);
            try {
                $admin->query('GRANT SELECT ON '.$database.'.* TO '.$account);
                $admin->query('CREATE TRIGGER '.dump_native_id($prefix.'_tr').' BEFORE INSERT ON '.$qa.' FOR EACH ROW SET NEW.t = NEW.t');
                $limited=DB::from(dump_native_handle($user));
                try {
                    assert(count($limited->fetch_prepared('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ?', [getenv('THREADFIN_TEST_MYSQL_DATABASE')]))===0);
                    dump_native_failure(fn()=>dump_resumable($limited,$out,$cp,$options)); assert(!file_exists($out) && !file_exists($cp));
                } finally { $limited->close(); }
                $admin->query('GRANT TRIGGER ON '.$database.'.* TO '.$account);
                $limited=DB::from(dump_native_handle($user));
                try { dump_native_failure(fn()=>dump_resumable($limited,$out,$cp,$options)); assert(!file_exists($out)); } finally { $limited->close(); }
                $admin->query('DROP TRIGGER '.dump_native_id($prefix.'_tr'));
                $limited=DB::from(dump_native_handle($user));
                try { assert(dump_resumable($limited,$out,$cp,$options)->complete); } finally { $limited->close(); }
                $admin->query('REVOKE TRIGGER ON '.$database.'.* FROM '.$account);
                foreach([$qa,$qb] as $table) { $admin->query('GRANT TRIGGER ON '.$database.'.'.$table.' TO '.$account); }
                $limited=DB::from(dump_native_handle($user));
                try { assert(dump_resumable($limited,$out,$cp,$options)->complete); } finally { $limited->close(); }
            } finally { $admin->query('DROP USER '.$account); }
            return true;
        }
        if($case==='freeze' || $case==='source_idle') {
            $before=(int)$h->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch_assoc()['Value'];
            dump_native_failure(fn()=>$run([$case==='freeze'?'source_frozen':'source_idle'=>false]));
            $after=(int)$h->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch_assoc()['Value'];
            assert($after-$before===1 && !file_exists($out)); return true;
        }
        if($case==='source_autocommit') { $h->autocommit(false); dump_native_failure($run); assert(!file_exists($out) && !file_exists($cp)); return true; }
        if($case==='simulation') {
            $db->enable_simulation(true); $before=(int)$h->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch_assoc()['Value'];
            dump_native_failure($run); $after=(int)$h->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch_assoc()['Value'];
            assert($after-$before===1 && !file_exists($out) && !file_exists($cp)); return true;
        }
        if(in_array($case,['schema_drift','source_drift','short','corrupt','identity','checkpoint_corrupt'],true)) {
            $run(['max_batches'=>3]); $before=file_get_contents($out);
            switch($case) {
                case 'schema_drift': $admin->query('ALTER TABLE '.$qb.' ADD COLUMN added INT'); break;
                case 'source_drift': $options['source_id']='new-freeze'; break;
                case 'short': file_put_contents($out,substr($before,0,-1)); break;
                case 'corrupt': file_put_contents($out,'!'.substr($before,1)); break;
                case 'identity': rename($out,$dir.'/old.sql'); file_put_contents($out,$before); break;
                case 'checkpoint_corrupt': file_put_contents($cp,'{}'); break;
            }
            dump_native_failure(fn()=>dump_resumable($db,$out,$cp,$options)); return true;
        }
        $tables=[$a,$b]; $source=dump_native_snapshot($admin,$tables);
        if(str_starts_with($case,'crash_')) {
            $fault=str_ends_with($case,'after')?'after':(str_ends_with($case,'partial')?'partial':'before');
            if($case==='crash_header') {
                dump_native_crash($out,$cp,$options,$fault); assert(!file_exists($cp) && filesize($out)>0);
                dump_native_failure($run); unlink($out); // Explicitly acknowledge/remove orphan, never automatic.
            } else {
                $target=str_contains($case,'ddl')?'ddl':(str_contains($case,'footer')?'footer':'rows');
                do { $report=$run(['max_batches'=>1]); } while($report->phase!==$target);
                $old=file_get_contents($cp);
                dump_native_crash($out,$cp,$options+['max_batches'=>1],$fault);
                assert(($fault==='after') !== (file_get_contents($cp)===$old));
            }
        } elseif($case==='ahead') { $run(['max_batches'=>3]); file_put_contents($out,'UNCOMMITTED SUFFIX',FILE_APPEND); }
        elseif($case==='tiny_budget') {
            $r=$run(['max_bytes'=>1]); assert(!$r->complete && $r->required_bytes>1 && $r->bytes_written===0);
            do {
                $r=$run(['max_bytes'=>1]);
                if(!$r->complete) { assert($r->required_bytes>1); $r=$run(['max_bytes'=>$r->required_bytes,'max_batches'=>1]); }
            } while(!$r->complete);
        } elseif(!in_array($case,['restore','integer_keys','autocommit'],true)) { throw new RuntimeException('Unknown native dump case'); }
        $iterations=0;
        do { $r=$run(['max_batches'=>1]); assert(++$iterations<30); } while(!$r->complete);
        assert($r->rows===7); $sql=file_get_contents($out); assert(substr_count($sql,'CREATE TABLE')===2 && !str_contains($sql,'UNCOMMITTED'));
        $checkpoint=file_get_contents($cp); assert(!str_contains($checkpoint,'00123') && !str_contains($checkpoint,'password'));
        $r=$run(); assert($r->complete && $r->bytes_written===0 && $r->batches_written===0);
        foreach($tables as $table) { $admin->query('DROP TABLE '.dump_native_id($table)); }
        $target=dump_native_handle();
        try {
            $target->query("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_BACKSLASH_ESCAPES,ANSI_QUOTES'");
            if($case==='autocommit') { $target->autocommit(false); }
            dump_native_script($target,$sql);
            assert(str_contains($target->query('SELECT @@sql_mode')->fetch_row()[0],'ANSI_QUOTES'));
            assert((int)$target->query('SELECT @@autocommit')->fetch_row()[0]===($case==='autocommit'?0:1));
        } finally { $target->close(); }
        // Independent connection after importer close proves final-table commitment.
        assert(dump_native_snapshot($admin,$tables)===$source);
        return true;
    } finally { $db->close(); }
}
if(($argv[1]??'')==='worker') {
    mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
    $GLOBALS['dump_native_crash']=$argv[5];
    dump_resumable(DB::from(dump_native_handle()),$argv[2],$argv[3],json_decode($argv[4],true,512,JSON_THROW_ON_ERROR));
    exit(74);
}
ob_start(); $admin=null; $owned=[]; $dir=sys_get_temp_dir().'/threadfin-dump-native-'.bin2hex(random_bytes(6)); mkdir($dir,0700);
try {
    if(!extension_loaded('mysqli') || getenv('THREADFIN_TEST_MYSQL_ALLOW_SCHEMA_CHANGES')!=='1') { throw new RuntimeException('Disposable mysqli schema permission required'); }
    mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT); $admin=dump_native_handle();
    if($admin->query('SHOW TABLES')->num_rows!==0) { throw new RuntimeException('Dump tests require empty disposable database'); }
    $report=['value'=>dump_native_case($argv[1]??'', $admin,$owned,$dir),'error'=>null];
} catch(Throwable $e) { $report=['value'=>null,'error'=>get_class($e).': '.$e->getMessage().' at '.$e->getFile().':'.$e->getLine()]; }
finally {
    if($admin!==null) { foreach(array_reverse(array_unique($owned)) as $table) { $admin->query('DROP TABLE IF EXISTS '.dump_native_id($table)); } $admin->close(); }
    foreach(glob($dir.'/*') as $file) { unlink($file); } rmdir($dir);
}
$report['output']=ob_get_clean(); echo json_encode($report,JSON_THROW_ON_ERROR);
}
