<?php declare(strict_types=1);
/** Only parent's explicitly configured disposable socket instance; no server lifecycle. */
error_reporting(E_ALL & ~E_DEPRECATED);
define('SQL_ERROR_FILE', false);
require __DIR__ . '/../../core.php';
require __DIR__ . '/../../db.php';
use ThreadFin\DB\DB;
use ThreadFin\DB\ExecutionFailure;
function telemetry_native_handle(): mysqli {
    return new mysqli('localhost',getenv('THREADFIN_TEST_MYSQL_USER') ?: 'root','',
        getenv('THREADFIN_TEST_MYSQL_DATABASE'),null,getenv('THREADFIN_TEST_MYSQL_SOCKET'));
}
function telemetry_native_failure(callable $fn): Throwable {
    try { $fn(); } catch(Throwable $e) { return $e; }
    throw new RuntimeException('Expected failure');
}
function telemetry_native_stringable(callable $convert): Stringable {
    return new class($convert) implements Stringable {
        public function __construct(private $convert) {}
        public function __toString(): string { return ($this->convert)(); }
    };
}
function telemetry_native_case(string $case, mysqli $admin, string $table): bool {
    $h=telemetry_native_handle(); $db=DB::from($h); $events=[]; $secret='TELEMETRY_SECRET_CANARY_9357';
    $collect=function($event) use (&$events): void { $events[]=$event; };
    if($case!=='nested_stream_busy_disabled') { $db->observe($collect,['hmac_key'=>str_repeat('k',32)]); }
    try {
        switch($case) {
            case 'mode_fetch_toggle': case 'mode_stream_toggle':
                $method=$case==='mode_fetch_toggle'?'fetch':'stream';
                foreach([false,true] as $initial) {
                    $events=[]; $db->enable_simulation($initial); $before=(int)$h->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch_assoc()['Value'];
                    $value=telemetry_native_stringable(function() use ($db,$initial): string { $db->enable_simulation(!$initial); return 'ok'; });
                    $result=$db->$method('SELECT {value} AS result',['value'=>$value]);
                    $rows=$method==='fetch'?$result->as_array():iterator_to_array($result);
                    assert($rows===($initial?[['result'=>'ok']]:[])); $queries=(int)$h->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch_assoc()['Value']-$before-1;
                    assert($queries===($initial?1:0));
                    assert(array_column($events,'status')===($method==='fetch'?['success']:['start','complete']));
                    foreach($events as $event) { assert($event->simulated===!$initial && $event->errno===null); }
                    if($method==='stream') { assert($events[0]->operation_id===$events[1]->operation_id && $events[1]->rows===count($rows)); }
                }
                break;
            case 'mode_nested':
                foreach(['fetch','stream'] as $outer) {
                    foreach(['fetch','stream'] as $inner) {
                        foreach([false,true] as $initial) {
                            $events=[]; $db->enable_simulation($initial);
                            $value=telemetry_native_stringable(function() use ($db,$inner,$initial): string {
                                $nested=telemetry_native_stringable(function() use ($db,$initial): string {
                                    $db->enable_simulation(!$initial);
                                    $db->fetch_prepared('SELECT 1'); return 'ok';
                                });
                                $result=$db->$inner('SELECT {value} AS result',['value'=>$nested]);
                                $rows=$inner==='fetch'?$result->as_array():iterator_to_array($result);
                                assert($rows===($initial?[['result'=>'ok']]:[]));
                                $db->enable_simulation($initial); return 'ok';
                            });
                            $result=$db->$outer('SELECT {value} AS result',['value'=>$value]);
                            $rows=$outer==='fetch'?$result->as_array():iterator_to_array($result);
                            assert($rows===($initial?[]:[['result'=>'ok']]));
                            assert(count($events)===1+($inner==='fetch'?1:2)+($outer==='fetch'?1:2));
                            $outerId=end($events)->operation_id;
                            foreach($events as $event) { assert($event->simulated===($event->operation_id===$outerId?$initial:!$initial)); }
                            assert($events[0]->kind==='prepared_fetch' && $events[0]->status==='success');
                            assert($events[0]->operation_id!==$events[1]->operation_id && $events[1]->operation_id!==$outerId);
                        }
                    }
                }
                break;
            case 'mode_retained':
                $db->enable_simulation(false); $before=(int)$h->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch_assoc()['Value'];
                $value=telemetry_native_stringable(function() use ($db): string { $db->enable_simulation(true); return 'ok'; });
                $raw=$db->stream('SELECT {value} AS result',['value'=>$value]);
                $prepared=$db->stream_prepared('INVALID SIMULATED SQL');
                $db->enable_simulation(false);
                assert($db->fetch("SELECT 'ok' AS result")->as_array()===[['result'=>'ok']]);
                $db->enable_simulation(true); $db->enable_simulation(false);
                assert(iterator_to_array($raw)===[]); $prepared->close(); $prepared->close(); $raw->close(); $queries=(int)$h->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch_assoc()['Value']-$before-1;
                assert($queries===1 && array_column($events,'status')===['start','start','success','complete','cancelled']);
                assert(array_column($events,'simulated')===[true,true,false,true,true]);
                assert(array_column($events,'operation_id')===[1,2,3,1,2] && $events[3]->rows===0 && $events[4]->rows===0);
                break;
            case 'mode_validation':
                foreach(['fetch','stream'] as $method) {
                    foreach([false,true] as $initial) {
                        foreach([false,true] as $throws) {
                            $events=[]; $db->enable_simulation($initial); $before=(int)$h->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch_assoc()['Value'];
                            $original=new RuntimeException('Stringable validation failure');
                            $value=telemetry_native_stringable(function() use ($db,$initial,$throws,$original): string {
                                $db->enable_simulation(!$initial); if($throws) { throw $original; } return 'ok';
                            });
                            $error=telemetry_native_failure(fn()=>$db->$method($throws?'SELECT {value}':'SELECT {value}, {missing}',['value'=>$value]));
                            assert($throws?$error===$original:$error instanceof InvalidArgumentException); $queries=(int)$h->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch_assoc()['Value']-$before-1;
                            assert($queries===0 && count($events)===1 && $events[0]->status==='failure' && $events[0]->simulated===$initial);
                            assert($events[0]->startup_ns===null && $events[0]->errno===null);
                        }
                    }
                }
                break;
            case 'mode_execution_failure':
                foreach(['fetch','stream'] as $method) {
                    $events=[]; $db->enable_simulation(true); $before=(int)$h->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch_assoc()['Value'];
                    $value=telemetry_native_stringable(function() use ($db): string { $db->enable_simulation(false); return 'ok'; });
                    if($method==='fetch') { assert(count($db->fetch('INVALID {value}',['value'=>$value]))===0); }
                    else { assert(telemetry_native_failure(fn()=>$db->stream('INVALID {value}',['value'=>$value])) instanceof ExecutionFailure); }
                    $queries=(int)$h->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch_assoc()['Value']-$before-1;
                    assert($queries===1 && count($events)===1 && $events[0]->status==='failure' && !$events[0]->simulated && $events[0]->errno>0);
                }
                break;
            case 'nested_raw_success': case 'nested_raw_failure':
                $bad=$case==='nested_raw_failure';
                $value=telemetry_native_stringable(function() use ($db,$bad): string { assert($db->unsafe_raw($bad?'INVALID NESTED QUERY':'DO 0')===($bad?-1:1)); return 'ok'; });
                assert($db->fetch('SELECT {value} AS result',['value'=>$value])->as_array()===[['result'=>'ok']]);
                assert(count($events)===2 && array_column($events,'kind')===['raw','fetch']);
                assert(array_column($events,'status')===[$bad?'failure':'success','success'] && array_column($events,'operation_id')===[2,1]);
                assert($events[1]->rows===1 && $events[1]->errno===null && $events[1]->affected===null);
                if($bad) { assert($events[0]->errno===1064 && $events[0]->sqlstate==='42000'); }
                break;
            case 'nested_buffered':
                $value=telemetry_native_stringable(function() use ($db,$table,$secret): string {
                    assert(count($db->fetch('SELECT 1 UNION ALL SELECT 2'))===2);
                    assert(count($db->fetch_prepared('SELECT ? UNION ALL SELECT ?',[1,2]))===2);
                    assert($db->execute_prepared("INSERT INTO `$table` VALUES (?,?)",[1,$secret],\ThreadFin\DB\DB_FETCH_NUM_ROWS)===1);
                    assert(telemetry_native_failure(fn()=>$db->execute_prepared("INSERT INTO `$table` VALUES (?,?)",[1,$secret])) instanceof ExecutionFailure);
                    return 'ok';
                });
                assert($db->fetch('SELECT {value} AS result',['value'=>$value])->as_array()===[['result'=>'ok']]);
                assert(array_column($events,'kind')===['fetch','prepared_fetch','prepared_execute','prepared_execute','fetch']);
                assert(array_column($events,'status')===['success','success','success','failure','success'] && array_column($events,'operation_id')===[2,3,4,5,1]);
                assert($events[0]->rows===2 && $events[1]->rows===2 && $events[2]->affected===1 && $events[3]->errno===1062 && $events[4]->rows===1 && $events[4]->errno===null && $events[4]->affected===null);
                assert($admin->query("SELECT COUNT(*) AS n FROM `$table`")->fetch_assoc()['n']==1);
                break;
            case 'nested_context_restore':
                $leaf=telemetry_native_stringable(function() use ($db): string { assert($db->unsafe_raw('INVALID NESTED QUERY')===-1); return 'ok'; });
                $middle=telemetry_native_stringable(function() use ($db,$leaf): string { assert(count($db->fetch('SELECT {value} AS result',['value'=>$leaf]))===1); return 'ok'; });
                assert(count($db->fetch('SELECT {value} AS result',['value'=>$middle]))===1);
                assert(array_column($events,'operation_id')===[3,2,1] && array_column($events,'status')===['failure','success','success']);
                $events=[]; $original=new RuntimeException('string conversion');
                $value=telemetry_native_stringable(function() use ($db,$original): string { $db->unsafe_raw('DO 0'); throw $original; });
                assert(telemetry_native_failure(fn()=>$db->fetch('SELECT {value}',['value'=>$value]))===$original);
                $db->fetch('SELECT 1'); assert(array_column($events,'status')===['success','failure','success'] && array_column($events,'operation_id')===[5,4,6]);
                break;
            case 'nested_streams':
                foreach(['stream','stream_prepared'] as $method) {
                    $events=[];
                    $value=telemetry_native_stringable(function() use ($db,$method): string {
                        $s=$db->$method('SELECT 1 UNION ALL SELECT 2'); $s->rewind(); $s->close(); $s->close(); return 'ok';
                    });
                    $s=$db->stream('SELECT {value} AS result',['value'=>$value]); assert(iterator_to_array($s)===[['result'=>'ok']]);
                    assert(array_column($events,'status')===['start','cancelled','start','complete']);
                    assert($events[0]->operation_id===$events[1]->operation_id && $events[2]->operation_id===$events[3]->operation_id && $events[0]->operation_id!==$events[2]->operation_id);
                    assert($events[1]->rows===1 && $events[3]->rows===1 && $events[1]->first_row_ns!==null && $events[3]->first_row_ns!==null);
                }
                break;
            case 'nested_stream_busy': case 'nested_stream_busy_disabled':
                if($case==='nested_stream_busy_disabled') { $db->observe(null); }
                foreach(['stream','stream_prepared'] as $method) {
                    foreach(['fetch','stream'] as $outer) {
                        $events=[]; $inner=null;
                        $value=telemetry_native_stringable(function() use ($db,$method,&$inner): string { $inner=$db->$method('SELECT 1 UNION ALL SELECT 2'); return 'ok'; });
                        $before=(int)$h->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch_assoc()['Value'];
                        $error=telemetry_native_failure(fn()=>$db->$outer('SELECT {value} AS result',['value'=>$value]));
                        assert($error instanceof ExecutionFailure && $error->errno===0 && $error->getMessage()==='Connection is busy with an open stream');
                        assert(telemetry_native_failure(fn()=>$db->fetch('SELECT 1')) instanceof ExecutionFailure);
                        assert(count(iterator_to_array($inner))===2); $inner->close();
                        $after=(int)$h->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch_assoc()['Value'];
                        assert($after-$before===1);
                        if($case==='nested_stream_busy') {
                            assert(array_column($events,'status')===['start','failure','failure','complete']);
                            assert($events[0]->operation_id===$events[3]->operation_id && $events[0]->operation_id!==$events[1]->operation_id);
                        } else { assert($events===[] && !class_exists('ThreadFin\\DB\\QueryEvent',false)); }
                        assert($db->unsafe_raw('DO 0')===1);
                    }
                }
                break;
            case 'nested_simulation':
                $db->enable_simulation(true); $before=(int)$h->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch_assoc()['Value'];
                $value=telemetry_native_stringable(function() use ($db,$secret): string {
                    assert($db->unsafe_raw('INVALID NESTED QUERY')===1);
                    assert(count($db->fetch_prepared('INVALID ?',[$secret]))===0);
                    assert(iterator_to_array($db->stream_prepared('INVALID ?',[$secret]))===[]); return 'ok';
                });
                assert(count($db->fetch('SELECT {value}',['value'=>$value]))===0);
                $after=(int)$h->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch_assoc()['Value'];
                assert($after-$before===1);
                assert(array_column($events,'kind')===['raw','prepared_fetch','stream_prepared','stream_prepared','fetch']);
                assert(array_column($events,'status')===['success','success','start','complete','success'] && array_column($events,'operation_id')===[2,3,4,4,1]);
                foreach($events as $event) { assert($event->simulated && $event->errno===null); }
                break;
            case 'nested_busy_transaction':
                $inner=null;
                $error=telemetry_native_failure(function() use ($db,$table,$secret,&$inner): void {
                    $db->transaction(function($db) use ($table,$secret,&$inner): void {
                        $db->execute_prepared("INSERT INTO `$table` VALUES (?,?)",[1,$secret]);
                        $value=telemetry_native_stringable(function() use ($db,&$inner): string { $inner=$db->stream_prepared('SELECT 1 UNION ALL SELECT 2'); return 'ok'; });
                        assert(telemetry_native_failure(fn()=>$db->stream('SELECT {value}',['value'=>$value])) instanceof ExecutionFailure);
                        $inner->close();
                    });
                });
                assert($error instanceof ExecutionFailure && $error->getMessage()==='Connection is busy with an open stream');
                assert($admin->query("SELECT COUNT(*) AS n FROM `$table`")->fetch_assoc()['n']==0);
                $streams=array_values(array_filter($events,fn($e)=>in_array($e->kind,['stream','stream_prepared'],true)));
                assert(array_column($streams,'status')===['start','failure','cancelled']);
                assert($streams[0]->operation_id===$streams[2]->operation_id && $streams[0]->operation_id!==$streams[1]->operation_id);
                assert($db->transaction(fn($d)=>$d->execute_prepared("INSERT INTO `$table` VALUES (?,?)",[1,$secret]))===1);
                assert($admin->query("SELECT COUNT(*) AS n FROM `$table`")->fetch_assoc()['n']==1);
                break;
            case 'nested_sampling': case 'nested_disable':
                $value=telemetry_native_stringable(function() use ($db,$case,$collect): string {
                    $case==='nested_disable' ? $db->observe(null) : $db->observe($collect,['sample_rate'=>0]);
                    assert($db->unsafe_raw('INVALID NESTED QUERY')===-1); return 'ok';
                });
                assert($db->fetch('SELECT {value} AS result',['value'=>$value])->as_array()===[['result'=>'ok']]);
                assert(count($events)===1 && $events[0]->kind==='fetch' && $events[0]->status==='success' && $events[0]->errno===null && $events[0]->rows===1);
                break;
            case 'privacy':
                $db->host=$db->user=$db->database=$secret;
                assert($db->unsafe_raw("INSERT INTO `$table` VALUES (1,'$secret')")===1);
                assert($db->execute_prepared("INSERT INTO `$table` VALUES (?,?)",[2,$secret.'2'])===1);
                assert(count($db->fetch("SELECT txt FROM `$table` WHERE txt={x}",['x'=>$secret]))===1);
                assert(count($db->fetch_prepared("SELECT txt FROM `$table` WHERE txt=?",[$secret]))===1);
                assert(count(iterator_to_array($db->stream("SELECT '$secret' AS txt")))===1);
                assert(count(iterator_to_array($db->stream_prepared('SELECT ? AS txt',[$secret])))===1);
                assert(count($events)===8);
                assert($events[0]->affected===1 && $events[1]->affected===1 && $events[2]->rows===1 && $events[3]->rows===1);
                break;
            case 'failures':
                $admin->query("INSERT INTO `$table` VALUES (1,'$secret')");
                assert($db->unsafe_raw("INSERT INTO `$table` VALUES (2,'$secret')")===-1);
                assert(count($db->fetch("SELECT `$secret` FROM `$table`"))===0);
                $warnings=[]; set_error_handler(function($n,$m) use (&$warnings) { $warnings[]=$m; return true; });
                try {
                    foreach([MYSQLI_REPORT_OFF,MYSQLI_REPORT_ERROR,MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT] as $mode) {
                        mysqli_report($mode);
                        $e=telemetry_native_failure(fn()=>$db->execute_prepared("INSERT INTO `$table` VALUES (?,?)",[2,$secret]));
                        assert($e instanceof ExecutionFailure && $e->errno===1062);
                        telemetry_native_failure(fn()=>$db->stream_prepared("SELECT `$secret`"));
                    }
                    assert($warnings===[]);
                } finally { restore_error_handler(); mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT); }
                telemetry_native_failure(fn()=>$db->stream("SELECT `$secret`"));
                assert(count($events)===9);
                foreach($events as $e) { assert($e->status==='failure' && $e->errno>0 && $e->sqlstate!==null); }
                break;
            case 'simulation':
                $db->enable_simulation(true); $before=(int)$h->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch_assoc()['Value'];
                $db->unsafe_raw("BAD '$secret'"); $db->fetch('BAD {x}',['x'=>$secret]);
                $db->execute_prepared('BAD ?',[$secret]); $db->fetch_prepared('BAD ?',[$secret]);
                iterator_to_array($db->stream("BAD '$secret'")); iterator_to_array($db->stream_prepared('BAD ?',[$secret]));
                $after=(int)$h->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch_assoc()['Value'];
                assert($after-$before===1 && count($events)===8);
                foreach($events as $e) { assert($e->simulated && $e->status!=='failure'); }
                break;
            case 'streams':
                foreach(['stream','stream_prepared'] as $method) {
                    $events=[]; $s=$db->$method("SELECT '$secret' AS txt UNION ALL SELECT 'second'");
                    assert(count($events)===1 && $events[0]->status==='start');
                    usleep(2000); $s->rewind(); usleep(2000); $s->close(); $s->close();
                    assert(count($events)===2 && $events[1]->status==='cancelled' && $events[1]->rows===1);
                    assert($events[1]->first_row_ns >= $events[0]->startup_ns+1000000 && $events[1]->duration_ns>$events[1]->first_row_ns+1000000);
                    assert($events[1]->operation_id===$events[0]->operation_id);
                    $s=$db->$method('SELECT 1 AS id UNION ALL SELECT 2'); assert(count(iterator_to_array($s))===2);
                    assert(count($events)===4 && $events[3]->status==='complete' && $events[3]->rows===2);
                    $s=$db->$method('SELECT 1 WHERE FALSE'); assert(iterator_to_array($s)===[]);
                    assert($events[5]->rows===0 && $events[5]->first_row_ns===null);
                    $s=$db->$method('SELECT 1'); $db->close(); assert($events[7]->status==='cancelled');
                    $db=DB::from(telemetry_native_handle())->observe($collect);
                }
                break;
            case 'observer_isolation':
                $db->observe(function($e) use ($db,$h): void {
                    foreach([fn()=>$db->unsafe_raw('DO 0'),fn()=>$db->fetch('SELECT 1'),fn()=>$db->execute_prepared('DO 0'),
                        fn()=>$db->stream('SELECT 1'),fn()=>$db->transaction(fn()=>null),fn()=>$db->observe(null),fn()=>$db->enable_simulation(true),
                        fn()=>$db->enable_replay('/nonexistent'),fn()=>$db->enable_log(true),fn()=>$db->close()] as $op) {
                        assert(telemetry_native_failure($op) instanceof LogicException);
                    }
                    throw new RuntimeException('SECRET observer throwable');
                });
                assert($db->transaction(fn($d)=>$d->execute_prepared("INSERT INTO `$table` VALUES (?,?)",[1,$secret]))===1);
                assert($admin->query("SELECT COUNT(*) AS n FROM `$table`")->fetch_assoc()['n']==1);
                assert($db->errors===[] && $db->telemetry_diagnostics()['recursive_rejections']===50 && $db->telemetry_diagnostics()['observer_failures']===5);
                break;
            case 'stream_recursion':
                $retained=null; $attempts=0;
                $db->observe(function($e) use ($db,&$retained,&$attempts): void {
                    if($retained===null) { return; }
                    assert(telemetry_native_failure(fn()=>$retained->next()) instanceof LogicException);
                    assert(telemetry_native_failure(fn()=>$retained->close()) instanceof LogicException);
                    assert(telemetry_native_failure(fn()=>$db->transaction(fn()=>null)) instanceof LogicException);
                    $attempts++;
                });
                foreach(['stream','stream_prepared'] as $method) {
                    $retained=$db->$method('SELECT 1 UNION ALL SELECT 2'); $retained->rewind(); $retained->close();
                    assert($db->transaction(fn($d)=>$d->execute_prepared("INSERT INTO `$table` VALUES (?,?)",[$method==='stream'?1:2,$method]))===1);
                }
                assert($db->telemetry_diagnostics()['observer_failures']===0);
                assert($attempts>=12 && $admin->query("SELECT COUNT(*) AS n FROM `$table`")->fetch_assoc()['n']==2);
                break;
            case 'observer_disable_stream': case 'observer_replace_stream':
                $db->enable_simulation(true); $retained=null; $other=null; $verified=0; $replacementVerified=0; $oldEvents=[];
                $db->observe(function($e) use ($db,$h,&$retained,&$other,&$verified,&$oldEvents): void {
                    $oldEvents[]=$e;
                    if($e->status==='start') { return; }
                    $before=(int)$h->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch_assoc()['Value'];
                    foreach([fn()=>$db->fetch('SELECT missing_telemetry_column'),fn()=>$db->unsafe_raw('DO 0'),fn()=>$db->execute_prepared('DO 0'),
                        fn()=>$db->observe(null),fn()=>$db->enable_simulation(true),fn()=>$db->close(),fn()=>$db->transaction(fn()=>null),
                        fn()=>$retained->next(),fn()=>$retained->close(),fn()=>$other->next(),fn()=>$other->close()] as $op) {
                        assert(telemetry_native_failure($op) instanceof LogicException);
                    }
                    $after=(int)$h->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch_assoc()['Value'];
                    assert($after-$before===1); $verified++;
                });
                $retained=$db->stream('INVALID SIMULATED SQL'); $other=$db->stream_prepared('INVALID ?');
                if($case==='observer_disable_stream') { $db->observe(null); }
                else {
                    $db->observe(function($e) use (&$retained,&$replacementVerified): void {
                        assert(telemetry_native_failure(fn()=>$retained->next()) instanceof LogicException);
                        assert(telemetry_native_failure(fn()=>$retained->close()) instanceof LogicException);
                        $replacementVerified++;
                    });
                }
                $db->enable_simulation(false);
                assert($db->transaction(function($d) use ($retained,$table,$secret) {
                    $retained->rewind(); return $d->execute_prepared("INSERT INTO `$table` VALUES (?,?)",[1,$secret]);
                })===1);
                $other->close(); $retained->close(); $other->close();
                assert($verified===2 && count($oldEvents)===4 && array_column($oldEvents,'status')===['start','start','complete','cancelled']);
                assert($admin->query("SELECT COUNT(*) AS n FROM `$table`")->fetch_assoc()['n']==1);
                assert($db->errors===[] && $db->telemetry_diagnostics()['observer_failures']===0);
                if($case==='observer_replace_stream') { assert($replacementVerified===5); }
                break;
            case 'threshold':
                $db->observe($collect,['slow_threshold_ms'=>5]); $s=$db->stream('SELECT 1'); assert($events===[]); usleep(10000); $s->close();
                assert(count($events)===1 && $events[0]->status==='cancelled' && $events[0]->duration_ns>=5000000);
                $db->fetch('SELECT SLEEP(0.01)'); assert(count($events)===2 && $events[1]->status==='success');
                $db->observe($collect,['sample_rate'=>0]); $db->fetch('SELECT SLEEP(0.01)'); assert(count($events)===2);
                break;
            case 'controls':
                $db->transaction(function($d) use ($table,$secret) {
                    $d->execute_prepared("INSERT INTO `$table` VALUES (?,?)",[1,$secret]);
                    try { $d->transaction(function($d) use ($table) { $d->unsafe_raw("INSERT INTO `$table` VALUES (2,'rolledback')"); throw new RuntimeException('callback'); }); }
                    catch(RuntimeException $expected) {}
                });
                assert(count(array_filter($events,fn($e)=>$e->kind==='transaction_control'))===7);
                assert(count($events)===9 && $admin->query("SELECT COUNT(*) AS n FROM `$table`")->fetch_assoc()['n']==1);
                break;
            case 'stream_raw_failure': case 'stream_prepared_failure':
                $admin->query("ALTER TABLE `$table` DROP INDEX txt");
                $admin->query("ALTER TABLE `$table` MODIFY txt MEDIUMBLOB");
                $admin->query("INSERT INTO `$table` VALUES (1,REPEAT('x',32768))");
                for($i=0;$i<9;$i++) { $admin->query("INSERT INTO `$table` (id,txt) SELECT id+(SELECT MAX(id) FROM `$table`),txt FROM `$table`"); }
                $method=$case==='stream_raw_failure'?'stream':'stream_prepared';
                $s=$db->$method("SELECT txt FROM `$table`"); $s->rewind();
                $admin->query('KILL CONNECTION '.(int)$h->thread_id);
                assert(telemetry_native_failure(function() use ($s) { while($s->valid()) { $s->next(); } }) instanceof ExecutionFailure);
                assert(count($events)===2 && $events[1]->status==='failure' && $events[1]->rows>=1 && $events[1]->errno>0);
                $s->close(); break;
            default: throw new InvalidArgumentException('Unknown native telemetry case');
        }
        assert(!str_contains(json_encode($events),$secret) && !str_contains(json_encode($events),'SELECT') && !str_contains(json_encode($events),'INSERT'));
        foreach($events as $event) { foreach(get_object_vars($event) as $value) { assert(is_scalar($value)||$value===null); } }
        return true;
    } finally { try { $db->close(); } finally { $db->observe(null); } }
}
ob_start(); $admin=null; $owned=false; $table='threadfin_telemetry_'.bin2hex(random_bytes(6));
try {
    if(!extension_loaded('mysqli') || getenv('THREADFIN_TEST_MYSQL_ALLOW_SCHEMA_CHANGES')!=='1') { throw new RuntimeException('Disposable server configuration required'); }
    mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT); $admin=telemetry_native_handle();
    if($admin->query('SHOW TABLES')->num_rows!==0) { throw new RuntimeException('Telemetry native gates require EMPTY disposable schema'); }
    $owned=true; $admin->query("CREATE TABLE `$table` (id INT PRIMARY KEY, txt VARCHAR(100) UNIQUE) ENGINE=InnoDB");
    $value=telemetry_native_case($argv[1],$admin,$table); $report=['value'=>$value,'error'=>null];
} catch(Throwable $e) { $report=['value'=>null,'error'=>get_class($e).': '.$e->getMessage().' at '.$e->getFile().':'.$e->getLine()]; }
finally {
    if($admin!==null) { if($owned) { $admin->query("DROP TABLE IF EXISTS `$table`"); } $admin->close(); }
    $report['output']=ob_get_clean();
}
echo json_encode($report,JSON_THROW_ON_ERROR);
