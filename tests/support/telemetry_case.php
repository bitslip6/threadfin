<?php declare(strict_types=1);
namespace ThreadFin\DB {
    function hrtime(bool $number = false): array|int|float { $GLOBALS['telemetry_clocks']++; return \hrtime($number); }
    function hash_hmac(string $algo, string $data, string $key, bool $binary = false): string { $GLOBALS['telemetry_hashes']++; return \hash_hmac($algo,$data,$key,$binary); }
    function random_int(int $min, int $max): int {
        $GLOBALS['telemetry_random']++;
        return !empty($GLOBALS['telemetry_samples']) ? array_shift($GLOBALS['telemetry_samples']) : \random_int($min,$max);
    }
}
namespace {
error_reporting(E_ALL & ~E_DEPRECATED);
define('SQL_ERROR_FILE', false);
require __DIR__ . '/mysqli.php';
require __DIR__ . '/../../core.php';
require __DIR__ . '/../../db.php';
use ThreadFin\DB\DB;
use ThreadFin\DB\ExecutionFailure;
function telemetry_failure(callable $fn): Throwable {
    try { $fn(); } catch (Throwable $e) { return $e; }
    throw new RuntimeException('Expected failure');
}
function telemetry_stringable(callable $convert): Stringable {
    return new class($convert) implements Stringable {
        public function __construct(private $convert) {}
        public function __toString(): string { return ($this->convert)(); }
    };
}
class TelemetrySubclass extends DB {
    public int $writes = 0;
    public int $reads = 0;
    public function __construct(mysqli $handle) { parent::__construct($handle); }
    protected function _qb(string $sql, int $return_type = \ThreadFin\DB\DB_FETCH_SUCCESS): int {
        $this->writes++; return parent::_qb($sql,$return_type);
    }
    public function fetch(string $sql, $data = null, $mode = MYSQLI_ASSOC): \ThreadFin\DB\SQL {
        $this->reads++; return parent::fetch($sql,$data,$mode);
    }
}
function telemetry_case(string $case): bool {
    $GLOBALS['telemetry_clocks'] = $GLOBALS['telemetry_hashes'] = $GLOBALS['telemetry_random'] = 0;
    $h = new mysqli(); $db = DB::from($h); $events = [];
    $collect = function($e) use (&$events): void { $events[] = $e; };
    $sql = 'SELECT a FROM t'; $secret = 'TELEMETRY_SECRET_CANARY_93';
    $GLOBALS['db_fixture_query_rows'][$sql] = [['a'=>1],['a'=>2]];
    $GLOBALS['db_fixture_query_rows']['SELECT @@SESSION.autocommit AS autocommit'] = [['autocommit'=>1]];
    $GLOBALS['db_fixture_prepared'] = ['fields'=>['a'], 'rows'=>[[1],[2]], 'affected'=>2];
    if (!in_array($case,['disabled','nested_stream_busy_disabled'],true)) { assert($db->observe($collect, ['hmac_key'=>str_repeat('k',32)]) === $db); }
    $GLOBALS['db_fixture_query_rows']["SELECT 'ok' AS result"]=[['result'=>'ok']];
    $GLOBALS['db_fixture_query_failures']["INVALID 'ok'"]='exception';
    switch ($case) {
        case 'mode_fetch_toggle': case 'mode_stream_toggle':
            $method=$case==='mode_fetch_toggle'?'fetch':'stream';
            foreach([false,true] as $initial) {
                $events=[]; $db->enable_simulation($initial); $before=count($h->queries);
                $value=telemetry_stringable(function() use ($db,$initial): string { $db->enable_simulation(!$initial); return 'ok'; });
                $result=$db->$method('SELECT {value} AS result',['value'=>$value]);
                $rows=$method==='fetch'?$result->as_array():iterator_to_array($result);
                assert($rows===($initial?[['result'=>'ok']]:[])); $queries=count($h->queries)-$before;
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
                        $value=telemetry_stringable(function() use ($db,$inner,$initial): string {
                            $nested=telemetry_stringable(function() use ($db,$initial): string {
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
            $db->enable_simulation(false); $before=count($h->queries);
            $value=telemetry_stringable(function() use ($db): string { $db->enable_simulation(true); return 'ok'; });
            $raw=$db->stream('SELECT {value} AS result',['value'=>$value]);
            $prepared=$db->stream_prepared('INVALID SIMULATED SQL');
            $db->enable_simulation(false);
            assert($db->fetch("SELECT 'ok' AS result")->as_array()===[['result'=>'ok']]);
            $db->enable_simulation(true); $db->enable_simulation(false);
            assert(iterator_to_array($raw)===[]); $prepared->close(); $prepared->close(); $raw->close(); $queries=count($h->queries)-$before;
            assert($queries===1 && array_column($events,'status')===['start','start','success','complete','cancelled']);
            assert(array_column($events,'simulated')===[true,true,false,true,true]);
            assert(array_column($events,'operation_id')===[1,2,3,1,2] && $events[3]->rows===0 && $events[4]->rows===0);
            break;
        case 'mode_validation':
            foreach(['fetch','stream'] as $method) {
                foreach([false,true] as $initial) {
                    foreach([false,true] as $throws) {
                        $events=[]; $db->enable_simulation($initial); $before=count($h->queries);
                        $original=new RuntimeException('Stringable validation failure');
                        $value=telemetry_stringable(function() use ($db,$initial,$throws,$original): string {
                            $db->enable_simulation(!$initial); if($throws) { throw $original; } return 'ok';
                        });
                        $error=telemetry_failure(fn()=>$db->$method($throws?'SELECT {value}':'SELECT {value}, {missing}',['value'=>$value]));
                        assert($throws?$error===$original:$error instanceof InvalidArgumentException); $queries=count($h->queries)-$before;
                        assert($queries===0 && count($events)===1 && $events[0]->status==='failure' && $events[0]->simulated===$initial);
                        assert($events[0]->startup_ns===null && $events[0]->errno===null);
                    }
                }
            }
            break;
        case 'mode_execution_failure':
            foreach(['fetch','stream'] as $method) {
                $events=[]; $db->enable_simulation(true); $before=count($h->queries);
                $value=telemetry_stringable(function() use ($db): string { $db->enable_simulation(false); return 'ok'; });
                if($method==='fetch') { assert(count($db->fetch('INVALID {value}',['value'=>$value]))===0); }
                else { assert(telemetry_failure(fn()=>$db->stream('INVALID {value}',['value'=>$value])) instanceof ExecutionFailure); }
                $queries=count($h->queries)-$before;
                assert($queries===1 && count($events)===1 && $events[0]->status==='failure' && !$events[0]->simulated && $events[0]->errno>0);
            }
            break;
        case 'nested_raw_success': case 'nested_raw_failure':
            $bad=$case==='nested_raw_failure';
            $GLOBALS['db_fixture_query_failures']['BAD NESTED']='exception';
            $GLOBALS['db_fixture_query_rows']["SELECT 'ok' AS result"]=[['result'=>'ok']];
            $value=telemetry_stringable(function() use ($db,$bad): string {
                assert($db->unsafe_raw($bad?'BAD NESTED':'DO 0')===($bad?-1:1)); return 'ok';
            });
            assert($db->fetch('SELECT {value} AS result',['value'=>$value])->as_array()===[['result'=>'ok']]);
            assert(count($events)===2 && array_column($events,'kind')===['raw','fetch']);
            assert(array_column($events,'status')===[$bad?'failure':'success','success']);
            assert(array_column($events,'operation_id')===[2,1] && $events[1]->rows===1 && $events[1]->errno===null && $events[1]->affected===null);
            assert($events[0]->template_id!==$events[1]->template_id && count($h->queries)===3);
            break;
        case 'nested_buffered':
            $GLOBALS['db_fixture_query_rows']["SELECT 'ok' AS result"]=[['result'=>'ok']];
            $value=telemetry_stringable(function() use ($db,$sql): string {
                assert(count($db->fetch($sql))===2);
                assert(count($db->fetch_prepared($sql))===2);
                assert($db->execute_prepared('UPDATE t SET a=1',[],\ThreadFin\DB\DB_FETCH_NUM_ROWS)===2);
                $GLOBALS['db_fixture_prepared']['fail']['execute']='exception';
                assert(telemetry_failure(fn()=>$db->fetch_prepared($sql)) instanceof ExecutionFailure);
                unset($GLOBALS['db_fixture_prepared']['fail']); return 'ok';
            });
            assert(count($db->fetch('SELECT {value} AS result',['value'=>$value]))===1);
            assert(array_column($events,'kind')===['fetch','prepared_fetch','prepared_execute','prepared_fetch','fetch']);
            assert(array_column($events,'status')===['success','success','success','failure','success']);
            assert(array_column($events,'operation_id')===[2,3,4,5,1]);
            assert(array_column($events,'rows')===[2,2,null,null,1] && $events[2]->affected===2 && $events[4]->affected===null);
            break;
        case 'nested_context_restore':
            $GLOBALS['db_fixture_query_rows']["SELECT 'ok' AS result"]=[['result'=>'ok']];
            $GLOBALS['db_fixture_query_failures']['BAD NESTED']='exception';
            $leaf=telemetry_stringable(function() use ($db): string { assert($db->unsafe_raw('BAD NESTED')===-1); return 'ok'; });
            $middle=telemetry_stringable(function() use ($db,$leaf): string {
                assert(count($db->fetch('SELECT {value} AS result',['value'=>$leaf]))===1); return 'ok';
            });
            assert(count($db->fetch('SELECT {value} AS result',['value'=>$middle]))===1);
            assert(array_column($events,'operation_id')===[3,2,1] && array_column($events,'status')===['failure','success','success']);
            $events=[]; $original=new RuntimeException('string conversion');
            $value=telemetry_stringable(function() use ($db,$original): string { $db->unsafe_raw('DO 0'); throw $original; });
            assert(telemetry_failure(fn()=>$db->fetch('SELECT {value}',['value'=>$value]))===$original);
            $db->fetch($sql);
            assert(array_column($events,'status')===['success','failure','success'] && array_column($events,'operation_id')===[5,4,6]);
            break;
        case 'nested_streams':
            foreach(['stream','stream_prepared'] as $method) {
                $events=[];
                $value=telemetry_stringable(function() use ($db,$sql,$method): string {
                    $s=$db->$method($sql); $s->rewind(); $s->close(); $s->close(); return 'ok';
                });
                $GLOBALS['db_fixture_query_rows']["SELECT 'ok' AS result"]=[['result'=>'ok']];
                $s=$db->stream('SELECT {value} AS result',['value'=>$value]);
                assert(iterator_to_array($s)===[['result'=>'ok']]);
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
                    $value=telemetry_stringable(function() use ($db,$sql,$method,&$inner): string { $inner=$db->$method($sql); return 'ok'; });
                    $error=telemetry_failure(fn()=>$db->$outer('SELECT {value} AS result',['value'=>$value]));
                    assert($error instanceof ExecutionFailure && $error->errno===0 && $error->getMessage()==='Connection is busy with an open stream');
                    $calls=$h->calls;
                    assert(telemetry_failure(fn()=>$db->fetch($sql)) instanceof ExecutionFailure && $calls===$h->calls);
                    assert(count(iterator_to_array($inner))===2); $inner->close();
                    // Only inner startup may reach the driver; outer rendered SQL must never do so.
                    assert(!in_array("SELECT 'ok' AS result",$h->queries,true));
                    if($case==='nested_stream_busy') {
                        assert(array_column($events,'status')===['start','failure','failure','complete']);
                        assert($events[0]->operation_id===$events[3]->operation_id && $events[0]->operation_id!==$events[1]->operation_id);
                    } else {
                        assert($events===[] && !class_exists('ThreadFin\\DB\\QueryEvent',false));
                        assert($GLOBALS['telemetry_clocks']===0 && $GLOBALS['telemetry_hashes']===0);
                    }
                    assert($db->unsafe_raw('DO 0')===1);
                }
            }
            break;
        case 'nested_simulation':
            $db->enable_simulation(true); $before=$h->calls;
            $value=telemetry_stringable(function() use ($db): string {
                assert($db->unsafe_raw('INVALID NESTED QUERY')===1);
                assert(count($db->fetch_prepared('INVALID ?',['secret']))===0);
                assert(iterator_to_array($db->stream_prepared('INVALID ?',['secret']))===[]); return 'ok';
            });
            assert(count($db->fetch('SELECT {value}',['value'=>$value]))===0 && $h->calls===$before);
            assert(array_column($events,'kind')===['raw','prepared_fetch','stream_prepared','stream_prepared','fetch']);
            assert(array_column($events,'status')===['success','success','start','complete','success'] && array_column($events,'operation_id')===[2,3,4,4,1]);
            foreach($events as $event) { assert($event->simulated && $event->errno===null); }
            break;
        case 'nested_busy_transaction':
            $inner=null;
            $error=telemetry_failure(function() use ($db,$sql,&$inner): void {
                $db->transaction(function($db) use ($sql,&$inner): void {
                    $value=telemetry_stringable(function() use ($db,$sql,&$inner): string { $inner=$db->stream_prepared($sql); return 'ok'; });
                    assert(telemetry_failure(fn()=>$db->stream('SELECT {value}',['value'=>$value])) instanceof ExecutionFailure);
                    $inner->close();
                });
            });
            assert($error instanceof ExecutionFailure && $error->getMessage()==='Connection is busy with an open stream');
            assert(in_array('ROLLBACK AND NO CHAIN NO RELEASE',$h->queries,true) && !in_array('COMMIT AND NO CHAIN NO RELEASE',$h->queries,true));
            $streams=array_values(array_filter($events,fn($e)=>in_array($e->kind,['stream','stream_prepared'],true)));
            assert(array_column($streams,'status')===['start','failure','cancelled']);
            assert($streams[0]->operation_id===$streams[2]->operation_id && $streams[0]->operation_id!==$streams[1]->operation_id);
            assert($db->transaction(fn($d)=>$d->unsafe_raw('UPDATE t SET a=1'))===1);
            break;
        case 'nested_sampling_decisions':
            $db->observe($collect,['sample_rate'=>0.5]);
            $GLOBALS['db_fixture_query_failures']['BAD NESTED']='exception';
            $GLOBALS['db_fixture_query_rows']["SELECT 'ok' AS result"]=[['result'=>'ok']];
            $value=telemetry_stringable(function() use ($db): string { assert($db->unsafe_raw('BAD NESTED')===-1); return 'ok'; });
            foreach([[0,PHP_INT_MAX-1],[PHP_INT_MAX-1,0]] as $selection) {
                $events=[]; $GLOBALS['telemetry_samples']=$selection;
                assert(count($db->fetch('SELECT {value} AS result',['value'=>$value]))===1 && count($events)===1);
                assert($events[0]->kind===($selection[0]===0?'fetch':'raw') && $events[0]->status===($selection[0]===0?'success':'failure'));
                assert($GLOBALS['telemetry_samples']===[]);
            }
            assert($GLOBALS['telemetry_random']===4 && $GLOBALS['telemetry_hashes']===2);
            break;
        case 'nested_sampling': case 'nested_disable':
            $GLOBALS['db_fixture_query_failures']['BAD NESTED']='exception';
            $GLOBALS['db_fixture_query_rows']["SELECT 'ok' AS result"]=[['result'=>'ok']];
            $value=telemetry_stringable(function() use ($db,$case,$collect): string {
                $case==='nested_disable' ? $db->observe(null) : $db->observe($collect,['sample_rate'=>0]);
                $clocks=$GLOBALS['telemetry_clocks']; $hashes=$GLOBALS['telemetry_hashes'];
                assert($db->unsafe_raw('BAD NESTED')===-1);
                assert($clocks===$GLOBALS['telemetry_clocks'] && $hashes===$GLOBALS['telemetry_hashes']); return 'ok';
            });
            assert(count($db->fetch('SELECT {value} AS result',['value'=>$value]))===1);
            assert(count($events)===1 && $events[0]->kind==='fetch' && $events[0]->status==='success' && $events[0]->errno===null && $events[0]->rows===1);
            break;
        case 'privacy':
            $db->host = $db->user = $db->database = $secret;
            $db->fetch("SELECT '$secret'"); $db->unsafe_raw("DO '$secret'");
            $GLOBALS['db_fixture_prepared']['count'] = 1;
            $db->fetch_prepared('SELECT ?', [$secret]); $db->execute_prepared('UPDATE t SET a=?',[$secret]);
            $GLOBALS['db_fixture_query_failures']["BAD '$secret'"] = 'exception';
            assert($db->unsafe_raw("BAD '$secret'") === -1);
            $GLOBALS['db_fixture_prepared']['fail']['execute'] = 'exception';
            telemetry_failure(fn() => $db->fetch_prepared('SELECT ?',[$secret]));
            $db->enable_simulation(true); $db->fetch("SELECT '$secret'"); $db->fetch_prepared('SELECT ?',[$secret]);
            assert(count($events) === 8 && !str_contains(json_encode($events),$secret));
            assert(!str_contains(json_encode($events),'SECRET') && !str_contains(json_encode($events),'SELECT'));
            break;
        case 'outcomes':
            assert($db->unsafe_raw('DO 0', \ThreadFin\DB\DB_FETCH_INSERT_ID) === -1);
            assert($events[0]->status === 'success');
            $GLOBALS['db_fixture_query_failures']['bad'] = 'false';
            $db->unsafe_raw('bad'); $db->fetch('bad'); $db->fetch($sql);
            assert(array_column($events,'status') === ['success','failure','failure','success']);
            assert($events[1]->errno !== null && $events[1]->sqlstate !== null && $events[3]->rows === 2);
            break;
        case 'templates':
            $db->fetch('SELECT {x}', ['x'=>$secret]); $db->fetch('SELECT {x}',['x'=>'other']);
            assert($events[0]->template_id === $events[1]->template_id);
            assert($events[0]->template_id === hash_hmac('sha256','SELECT {x}',str_repeat('k',32)));
            assert($events[0]->operation_id !== $events[1]->operation_id && $events[0]->correlation_id === $events[1]->correlation_id);
            $old = $events[0]->template_id; $db->observe($collect); $db->fetch('SELECT {x}',['x'=>$secret]);
            assert($events[2]->template_id !== $old); break;
        case 'simulation':
            $db->enable_simulation(true); $before=$h->calls;
            $db->unsafe_raw('BAD'); $db->fetch('BAD'); $db->execute_prepared('BAD'); $db->fetch_prepared('BAD');
            assert($h->calls === $before && count($events) === 4);
            foreach ($events as $e) { assert($e->simulated && $e->status === 'success' && $e->affected === null); }
            break;
        case 'stream_complete': case 'stream_cancel': case 'stream_failure': case 'stream_cleanup': case 'stream_simulation':
            foreach (['stream','stream_prepared'] as $method) {
                $events=[];
                if ($case === 'stream_simulation') { $db->enable_simulation(true); }
                $s=$db->$method($sql); assert(count($events)===1 && $events[0]->status==='start');
                usleep(1000);
                if ($case==='stream_complete') { assert(count(iterator_to_array($s))===2); }
                elseif ($case==='stream_cancel') { $s->rewind(); usleep(1000); $s->close(); }
                elseif ($case==='stream_simulation') { assert(iterator_to_array($s)===[]); }
                elseif ($case==='stream_cleanup') {
                    if ($method==='stream') { $GLOBALS['db_fixture_result_free_failure']=true; }
                    else { end($GLOBALS['db_fixture_statements'])->scenario['fail']['stmt_close']='exception'; }
                    assert(telemetry_failure(fn()=>$s->close()) instanceof ExecutionFailure);
                    unset($GLOBALS['db_fixture_result_free_failure']);
                } else {
                    if ($method==='stream') { $GLOBALS['db_fixture_result_fetch_failure']=true; }
                    else { end($GLOBALS['db_fixture_statements'])->scenario['fail']['fetch']='exception'; }
                    assert(telemetry_failure(fn()=>$s->rewind()) instanceof ExecutionFailure);
                    unset($GLOBALS['db_fixture_result_fetch_failure']);
                }
                $s->close(); assert(count($events)===2);
                $e=$events[1]; assert($e->operation_id===$events[0]->operation_id && $e->duration_ns >= 1000000);
                assert($e->startup_ns !== null && $e->duration_ns >= $e->startup_ns);
                assert($e->status === (in_array($case,['stream_complete','stream_simulation'])?'complete':($case==='stream_cancel'?'cancelled':'failure')));
                assert($e->rows === ($case==='stream_complete'?2:($case==='stream_cancel'?1:0)));
                assert(($e->first_row_ns !== null) === in_array($case,['stream_complete','stream_cancel']));
                if ($case==='stream_cleanup') { $db->close(); $h=new mysqli(); $db=DB::from($h)->observe($collect); }
            }
            break;
        case 'observer_failure':
            $db->observe(static function($e) { throw new RuntimeException('SECRET'); });
            assert($db->transaction(fn($d)=>$d->unsafe_raw('UPDATE t SET a=1'))===1);
            assert($db->errors===[] && $db->telemetry_diagnostics()['observer_failures']>=4);
            assert(!str_contains(json_encode($db->telemetry_diagnostics()),'SECRET')); break;
        case 'recursion':
            $db->observe(function($e) use ($db,$h): void {
                $before=$h->calls;
                foreach ([fn()=>$db->fetch('SELECT 1'),fn()=>$db->unsafe_raw('DO 0'),fn()=>$db->fetch_prepared('SELECT 1'),
                    fn()=>$db->stream('SELECT 1'),fn()=>$db->stream_prepared('SELECT 1'),fn()=>$db->transaction(fn()=>null),
                    fn()=>$db->enable_simulation(true),fn()=>$db->enable_replay('/bad'),fn()=>$db->observe(null),
                    fn()=>$db->enable_log(true),fn()=>$db->close()] as $op) {
                    assert(telemetry_failure($op) instanceof LogicException);
                }
                assert($before===$h->calls);
            });
            assert($db->transaction(fn($d)=>$d->unsafe_raw('UPDATE t SET a=1'))===1 && $db->errors===[]);
            assert($db->telemetry_diagnostics()['recursive_rejections']>=11 && $db->telemetry_diagnostics()['observer_failures']===0); break;
        case 'stream_recursion':
            $retained=null;
            $db->observe(function($e) use (&$retained,$db,$h): void {
                if ($retained===null) { return; }
                $before=$h->calls;
                assert(telemetry_failure(fn()=>$retained->next()) instanceof LogicException);
                assert(telemetry_failure(fn()=>$retained->close()) instanceof LogicException);
                assert($h->calls===$before);
            });
            $retained=$db->stream($sql); $retained->rewind(); $retained->close();
            assert($db->transaction(fn($d)=>$d->unsafe_raw('UPDATE t SET a=1'))===1);
            assert($db->telemetry_diagnostics()['observer_failures']===0 && $db->telemetry_diagnostics()['recursive_rejections']>0);
            break;
        case 'sampling':
            $db->observe($collect,['sample_rate'=>0]); $GLOBALS['telemetry_clocks']=$GLOBALS['telemetry_hashes']=0;
            $db->fetch($sql); $db->execute_prepared('DO 0'); iterator_to_array($db->stream($sql));
            assert($events===[] && $GLOBALS['telemetry_clocks']===0 && $GLOBALS['telemetry_hashes']===0);
            mt_srand(123); $expected=mt_rand(); mt_srand(123);
            $db->observe($collect,['sample_rate'=>0.5]);
            for($i=0;$i<200;$i++) { $db->unsafe_raw('DO 0'); }
            assert(count($events)>30 && count($events)<170 && mt_rand()===$expected);
            assert($GLOBALS['telemetry_random']===200);
            $events=[]; $db->observe($collect,['sample_rate'=>1]); $db->fetch($sql);
            assert(count($events)===1 && $GLOBALS['telemetry_random']===200);
            break;
        case 'observer_disable_stream': case 'observer_replace_stream':
            $db->enable_simulation(true); $retained=null; $other=null; $verified=0; $replacementVerified=0; $oldEvents=[];
            $db->observe(function($e) use ($db,$h,&$retained,&$other,&$verified,&$oldEvents): void {
                $oldEvents[]=$e;
                if($e->status==='start') { return; }
                $before=$h->calls;
                foreach([fn()=>$db->fetch('SELECT 1'),fn()=>$db->unsafe_raw('DO 0'),fn()=>$db->fetch_prepared('SELECT 1'),
                    fn()=>$db->observe(null),fn()=>$db->enable_simulation(true),fn()=>$db->close(),fn()=>$db->transaction(fn()=>null),
                    fn()=>$retained->next(),fn()=>$retained->close(),fn()=>$other->next(),fn()=>$other->close()] as $op) {
                    assert(telemetry_failure($op) instanceof LogicException);
                }
                assert($before===$h->calls); $verified++;
            });
            $retained=$db->stream($sql); $other=$db->stream_prepared($sql);
            if($case==='observer_disable_stream') { $db->observe(null); }
            else {
                $db->observe(function($e) use (&$retained,&$replacementVerified): void {
                    assert(telemetry_failure(fn()=>$retained->next()) instanceof LogicException);
                    assert(telemetry_failure(fn()=>$retained->close()) instanceof LogicException);
                    $replacementVerified++;
                });
            }
            $db->enable_simulation(false);
            assert($db->transaction(function($d) use ($retained) { $retained->rewind(); return $d->unsafe_raw('UPDATE t SET a=1'); })===1);
            $other->close(); $retained->close(); $other->close();
            assert($verified===2 && count($oldEvents)===4);
            assert(array_column($oldEvents,'status')===['start','start','complete','cancelled']);
            assert(in_array('COMMIT AND NO CHAIN NO RELEASE',$h->queries,true) && !in_array('ROLLBACK AND NO CHAIN NO RELEASE',$h->queries,true));
            assert($db->errors===[] && $db->telemetry_diagnostics()['observer_failures']===0);
            if($case==='observer_replace_stream') { assert($replacementVerified===5); }
            break;
        case 'subclass':
            $custom=new TelemetrySubclass(new mysqli()); $custom->observe($collect);
            $custom->unsafe_raw('DO 0'); $custom->fetch($sql);
            assert($custom->writes===1 && $custom->reads===1 && count($events)===2);
            $custom->close(); break;
        case 'startup_failure':
            $GLOBALS['db_fixture_query_failures'][$sql]='exception';
            assert(telemetry_failure(fn()=>$db->stream($sql)) instanceof ExecutionFailure);
            $GLOBALS['db_fixture_prepared']['fail']['prepare_result']='exception';
            assert(telemetry_failure(fn()=>$db->stream_prepared($sql)) instanceof ExecutionFailure);
            assert(count($events)===2);
            foreach($events as $e) { assert($e->status==='failure' && $e->startup_ns===null && $e->first_row_ns===null); }
            break;
        case 'cleanup_primary':
            $s=$db->stream_prepared($sql);
            $stmt=end($GLOBALS['db_fixture_statements']);
            $stmt->scenario['fail']['fetch']='exception'; $stmt->scenario['fail']['stmt_close']='exception';
            $error=telemetry_failure(fn()=>$s->rewind());
            assert($error instanceof ExecutionFailure && count($events)===2 && $events[1]->status==='failure');
            assert($events[1]->errno===$error->errno && $events[1]->sqlstate===$error->sqlstate);
            $s->close(); assert(count($events)===2); break;
        case 'diagnostics':
            $db->observe(function($e) use ($db) { $db->fetch('SELECT 1'); });
            $property=new ReflectionProperty(DB::class,'_telemetry'); $owner=$property->getValue($db);
            foreach(['failures','recursions'] as $name) { (new ReflectionProperty($owner,$name))->setValue($owner,PHP_INT_MAX); }
            $db->unsafe_raw('DO 0');
            assert($db->telemetry_diagnostics()===['observer_failures'=>PHP_INT_MAX,'recursive_rejections'=>PHP_INT_MAX]);
            $db->observe($collect); assert($db->telemetry_diagnostics()===['observer_failures'=>0,'recursive_rejections'=>0]);
            break;
        case 'threshold':
            $db->observe($collect,['slow_threshold_ms'=>86400000]); $db->fetch($sql); iterator_to_array($db->stream($sql)); assert($events===[]);
            $db->observe($collect,['slow_threshold_ms'=>1]); $s=$db->stream($sql); assert($events===[]); usleep(3000); $s->close();
            assert(count($events)===1 && $events[0]->status==='cancelled' && $events[0]->startup_ns!==null); break;
        case 'options':
            foreach ([['sample_rate'=>-1],['sample_rate'=>1.1],['sample_rate'=>NAN],['sample_rate'=>null],['sample_rate'=>'1'],['slow_threshold_ms'=>-1],['slow_threshold_ms'=>INF],['slow_threshold_ms'=>86400001],['hmac_key'=>'short'],['unknown'=>true]] as $options) {
                assert(telemetry_failure(fn()=>$db->observe($collect,$options)) instanceof InvalidArgumentException);
            }
            $db->unsafe_raw('DO 0'); assert(count($events)===1); break;
        case 'disabled':
            assert(!class_exists('ThreadFin\\DB\\QueryEvent',false)); $before=count($h->queries);
            $db->unsafe_raw('DO 0'); $db->fetch($sql);
            assert(count($h->queries)===$before+2 && $GLOBALS['telemetry_clocks']===0 && $GLOBALS['telemetry_hashes']===0);
            assert(!class_exists('ThreadFin\\DB\\QueryEvent',false));
            $db->observe($collect)->observe(null); $db->unsafe_raw('DO 0'); assert($events===[]); break;
        case 'transaction':
            $db->transaction(function($d) { $d->unsafe_raw('UPDATE t SET a=1'); $d->transaction(fn($d)=>$d->fetch('SELECT 1')); });
            assert(count(array_filter($events,fn($e)=>$e->kind==='transaction_control'))===6);
            assert(count($events)===8); break;
        case 'replay':
            $path=tempnam(sys_get_temp_dir(),'telemetry-');
            try { $db->enable_replay($path); $db->unsafe_raw("DO '$secret'"); $db->close(); assert(str_contains(file_get_contents($path),$secret)); assert(!str_contains(json_encode($events),$secret)); }
            finally { unlink($path); } break;
        case 'immutability':
            $db->fetch($sql); assert(telemetry_failure(function() use ($events) { $events[0]->status='other'; }) instanceof Error);
            assert(telemetry_failure(function() use ($events) { $events[0]->extra='mutable'; }) instanceof LogicException);
            assert(telemetry_failure(function() use ($events) { unset($events[0]->extra); }) instanceof LogicException);
            foreach (get_object_vars($events[0]) as $value) { assert(is_scalar($value)||$value===null); }
            assert($events[0]->version===1); break;
        case 'warning_restoration':
            $warnings=[]; set_error_handler(function($n,$m) use (&$warnings) { $warnings[]=$m; return true; });
            try {
                $db->observe(function($e) { trigger_error('observer marker',E_USER_WARNING); });
                $db->fetch_prepared($sql); iterator_to_array($db->stream_prepared($sql));
                assert($warnings===['observer marker','observer marker','observer marker']);
            } finally { restore_error_handler(); } break;
        default: throw new InvalidArgumentException('Unknown telemetry case');
    }
    $db->observe(null); $db->close(); return true;
}
ob_start(); $value=null; $error=null;
try { $value=telemetry_case($argv[1]); } catch(Throwable $e) { $error=get_class($e).': '.$e->getMessage().' at '.$e->getFile().':'.$e->getLine(); }
$output=ob_get_clean(); echo json_encode(compact('value','error','output'),JSON_THROW_ON_ERROR);
}
