<?php declare(strict_types=1);
function db_verify_observe(string $case, bool $native = false): mixed {
    if ($native && (!getenv('THREADFIN_TEST_MYSQL_SOCKET') || getenv('THREADFIN_TEST_MYSQL_ALLOW_SCHEMA_CHANGES') !== '1')) {
        throw new RuntimeException('Parent disposable server required');
    }
    $cmd = [PHP_BINARY, '-n', '-d', 'zend.assertions=1', '-d', 'assert.exception=1'];
    if ($native) { array_push($cmd, '-d', 'extension=' . (getenv('THREADFIN_TEST_MYSQLI_EXTENSION') ?: 'mysqli')); }
    array_push($cmd, __DIR__ . ($native ? '/verify_mysql.php' : '/verify_case.php'), $case);
    $p = proc_open($cmd, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    fclose($pipes[0]); $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    assert_eq(proc_close($p), 0, $err . $out); assert_eq($err, '', 'No stderr');
    $r = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
    assert_eq($r['error'], null, $r['error'] ?? 'case completes'); assert_eq($r['output'], '', 'No unexpected output');
    return $r['value'];
}
