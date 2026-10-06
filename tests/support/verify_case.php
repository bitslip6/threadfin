<?php declare(strict_types=1);
use ThreadFin\DB\VerifySql;
use ThreadFin\DB\VerifyManifest;
function verify_packet(string $body, string $mode = ''): string {
    return "\n-- ThreadFin replay session\nROLLBACK;\nSET NAMES utf8mb4;\nSET SESSION sql_mode = '$mode';\nSET SESSION autocommit = 1;\n" . $body . "\n;\nROLLBACK;\nSET SESSION autocommit = 1;\n-- End ThreadFin replay session\n";
}
function verify_reject(callable $f): void { try { $f(); } catch (Throwable $e) { assert($e instanceof ThreadFin\DB\VerifyFailure); return; } throw new RuntimeException('Expected safe rejection'); }
ob_start();
try {
    require __DIR__ . '/../../db_verify.php';
    $case = $argv[1];
    if ($case === 'quoted') { $q = "INSERT INTO `a;``b` VALUES (1,'x;''y',X'000AFF', '-- ThreadFin replay session')"; $s = VerifySql::journal(verify_packet($q)); assert(count($s) === 7 && $s[4]['sql'] === $q); }
    elseif ($case === 'shared') { assert(count(VerifySql::journal(verify_packet('INSERT INTO t VALUES (1)') . verify_packet('INSERT INTO t VALUES (2)'))) === 14); }
    elseif ($case === 'duplicate') { $p=verify_packet('INSERT INTO t VALUES (1)'); verify_reject(fn()=>VerifySql::journal($p.$p)); }
    elseif ($case === 'incomplete') { verify_reject(fn()=>VerifySql::journal(substr(verify_packet('DELETE FROM t'),0,-12))); }
    elseif ($case === 'marker_string') { assert(count(VerifySql::journal(verify_packet("INSERT INTO t VALUES ('a\n-- End ThreadFin replay session\nb')")))===7); }
    elseif ($case === 'modes') { foreach(['ANSI_QUOTES','NO_BACKSLASH_ESCAPES','ORACLE','PAD_CHAR_TO_FULL_LENGTH'] as $m) { verify_reject(fn()=>VerifySql::journal(verify_packet('DELETE FROM t',$m))); } }
    elseif ($case === 'comments') { assert(count(VerifySql::journal(verify_packet("INSERT /* ordinary ; */ INTO t VALUES (1) -- ordinary\n")))===7); foreach(['/*!50000 DELETE FROM t */','/*M! DELETE FROM t */'] as $s) { verify_reject(fn()=>VerifySql::journal(verify_packet($s))); } }
    elseif ($case === 'metacommands') { foreach(['\\! touch /tmp/no','SOURCE /etc/passwd','DELIMITER $$','system ls'] as $s) { verify_reject(fn()=>VerifySql::journal(verify_packet($s))); } }
    elseif ($case === 'unsafe_ddl') { foreach(["CREATE TABLE t (id INT) ENGINE=MyISAM", "CREATE TABLE t (id INT) ENGINE=InnoDB DATA DIRECTORY='/tmp'", "ALTER TABLE t ENGINE=FEDERATED", "CREATE FUNCTION x RETURNS INTEGER SONAME 'x.so'"] as $s) { verify_reject(fn()=>VerifySql::journal(verify_packet($s))); } }
    elseif ($case === 'session_changes') { foreach(["SET sql_mode='ANSI_QUOTES'",'USE mysql','SET GLOBAL local_infile=1','LOAD DATA LOCAL INFILE \'x\' INTO TABLE t'] as $s) { verify_reject(fn()=>VerifySql::journal(verify_packet($s))); } }
    elseif ($case === 'malformed') { foreach(['', 'SELECT 1;', verify_packet("INSERT INTO t VALUES ('bad)"), verify_packet('DELETE FROM t'). 'garbage'] as $s) { verify_reject(fn()=>VerifySql::journal($s)); } }
    elseif ($case === 'baseline') { $s="-- ThreadFin resumable dump v1; frozen source required\nSET @threadfin_dump_mode = @@SESSION.sql_mode;\nSET @threadfin_dump_autocommit = @@SESSION.autocommit;\nSET SESSION autocommit = 1;\nSET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO';\nSET NAMES utf8mb4;\nCREATE TABLE `t` (`id` int PRIMARY KEY) ENGINE=InnoDB;\nSET SESSION sql_mode = @threadfin_dump_mode;\nSET SESSION autocommit = @threadfin_dump_autocommit;\n"; assert(count(VerifySql::baseline($s))===8); }
    elseif ($case === 'manifest') { foreach([[], ['version'=>2], ['version'=>1,'password'=>'SECRET']] as $m) { verify_reject(fn()=>VerifyManifest::validate($m)); } }
    elseif ($case === 'opt_in') { $r=ThreadFin\DB\verify_replay('/does/not/exist','/does/not/exist','/does/not/exist',[]); assert($r->status==='configuration' && $r->cleanup); }
    elseif ($case === 'destination') { $r=ThreadFin\DB\verify_replay('x','y','z',['enabled'=>true,'socket'=>'/production']); assert($r->status==='configuration'); }
    elseif ($case === 'limits') {
        verify_reject(fn()=>VerifySql::journal(verify_packet("INSERT INTO t VALUES ('".str_repeat('x',1048576)."')")));
        verify_reject(fn()=>VerifySql::journal(verify_packet(str_repeat('DELETE FROM t;',10001))));
    }
    elseif ($case === 'backslashes') {
        $q="INSERT INTO t VALUES ('a".chr(92)."'b; c', 'a".chr(92).chr(92)."b')";
        $s=VerifySql::journal(verify_packet($q)); assert($s[4]['sql']===$q);
    }
    elseif ($case === 'backtick_escape') {
        $payloads = [
            <<<'SQL'
CREATE TABLE `x\` (id INT PRIMARY KEY) ENGINE=MyISAM COMMENT='` ENGINE=InnoDB \'abc'
SQL,
            <<<'SQL'
CREATE TABLE t (`id\` INT PRIMARY KEY) ENGINE=MyISAM COMMENT='` ENGINE=InnoDB \'abc'
SQL,
            'SELECT `a'.chr(92).'b` FROM t',
        ];
        foreach ($payloads as $sql) {
            verify_reject(fn()=>VerifySql::journal(verify_packet($sql)));
            verify_reject(fn()=>VerifySql::baseline($sql.';'));
        }
        assert(count(VerifySql::journal(verify_packet('SELECT `a``b` FROM `t``u`')))===7);
    }
    elseif ($case === 'controls') {
        $q='SET TRANSACTION READ WRITE; START TRANSACTION; SAVEPOINT threadfin_sp_1; ROLLBACK TO SAVEPOINT threadfin_sp_1; RELEASE SAVEPOINT threadfin_sp_1; COMMIT AND NO CHAIN NO RELEASE';
        assert(count(VerifySql::journal(verify_packet($q)))===12);
    }
    elseif ($case === 'quoted_engine') {
        foreach(["ALTER TABLE t ENGINE='MyISAM'",'ALTER TABLE t ENGINE=`FEDERATED`',"CREATE TABLE t(id INT) ENGINE=InnoDB ENGINE='MyISAM'"] as $s) { verify_reject(fn()=>VerifySql::journal(verify_packet($s))); }
    }
    elseif ($case === 'option_bounds') {
        foreach([['timeout_seconds'=>4],['timeout_seconds'=>301],['max_disk_bytes'=>1],['enabled'=>1],['password'=>'SECRET']] as $o) { verify_reject(fn()=>ThreadFin\DB\verify_options($o+['enabled'=>true])); }
    }
    elseif ($case === 'manifest_fields') {
        $m=['version'=>1,'source_id'=>'freeze','source_policy'=>'frozen-cut-no-hidden-schema-objects','policy'=>VerifyManifest::POLICY,'journal'=>['sha256'=>str_repeat('a',64),'cut'=>1],'baseline_sha256'=>str_repeat('b',64),'expected'=>['t'=>['schema_sha256'=>str_repeat('c',64),'rows_sha256'=>str_repeat('d',64),'count'=>0]]];
        assert(VerifyManifest::validate($m)===$m);
        foreach(['count'=>'0','rows_sha256'=>'SECRET','schema_sha256'=>'bad'] as $key=>$v) { $bad=$m; $bad['expected']['t'][$key]=$v; verify_reject(fn()=>VerifyManifest::validate($bad)); }
        $m['policy']['timezone']='SYSTEM'; verify_reject(fn()=>VerifyManifest::validate($m));
    }
    elseif ($case === 'numeric_manifest') {
        $table=['schema_sha256'=>str_repeat('c',64),'rows_sha256'=>str_repeat('d',64),'count'=>0];
        foreach ([['0'=>$table],['0'=>$table,'123'=>$table,'00123'=>$table]] as $expected) {
            $m=['version'=>1,'source_id'=>'freeze','source_policy'=>'frozen-cut-no-hidden-schema-objects','policy'=>VerifyManifest::POLICY,'journal'=>['sha256'=>str_repeat('a',64),'cut'=>1],'baseline_sha256'=>str_repeat('b',64),'expected'=>$expected];
            $roundtrip=json_decode(json_encode($m,JSON_THROW_ON_ERROR),true,512,JSON_THROW_ON_ERROR);
            assert(VerifyManifest::validate($roundtrip)===$m);
        }
        assert(array_keys($expected)===[0,123,'00123']);
    }
    elseif ($case === 'report_paths') {
        $d=sys_get_temp_dir().'/tfv-unit-'.bin2hex(random_bytes(5)); mkdir($d,0700); file_put_contents($d.'/input','PRIVATE');
        try {
            link($d.'/input',$d.'/hard'); symlink($d.'/input',$d.'/sym');
            foreach(['input','hard','sym'] as $p) { verify_reject(fn()=>ThreadFin\DB\verify_write_report(new ThreadFin\DB\VerifyReport(),$d.'/'.$p,[$d.'/input'])); }
            assert(file_get_contents($d.'/input')==='PRIVATE');
            ThreadFin\DB\verify_write_report(new ThreadFin\DB\VerifyReport(1,'matched',true),$d.'/report',[$d.'/input']);
            assert((fileperms($d.'/report')&0777)===0600);
        } finally { foreach(scandir($d) as $p) { if($p!=='.' && $p!=='..') { unlink($d.'/'.$p); } } rmdir($d); }
    }
    elseif ($case === 'framing') { assert(ThreadFin\DB\verify_frame(['ab','c'],['text','text'])!==ThreadFin\DB\verify_frame(['a','bc'],['text','text'])); assert(ThreadFin\DB\verify_frame([null],['text'])!==ThreadFin\DB\verify_frame([''],['text'])); assert(ThreadFin\DB\verify_frame(['1'],['int'])!==ThreadFin\DB\verify_frame(['1'],['text'])); }
    else { throw new RuntimeException('Unknown case'); }
    $r=['value'=>true,'error'=>null];
} catch(Throwable $e) { $r=['value'=>false,'error'=>get_class($e).': '.$e->getMessage().' line '.$e->getLine()]; }
$r['output']=ob_get_clean(); echo json_encode($r,JSON_THROW_ON_ERROR);
