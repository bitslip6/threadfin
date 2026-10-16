#!/usr/bin/env php
<?php declare(strict_types=1);
require __DIR__.'/../db_verify.php';
use ThreadFin\DB\VerifyReport;
use function ThreadFin\DB\verify_replay;
use function ThreadFin\DB\verify_write_report;
// Fixed argument protocol, no shell/SQL client and no credential arguments.
if ($argc!==6 || $argv[1]!=='--enable-sandbox') {
    fwrite(STDERR,"Usage: db-verify.php --enable-sandbox JOURNAL BASELINE MANIFEST REPORT\n"); exit(2);
}
set_error_handler(static fn()=>true);
try {
    ThreadFin\DB\verify_report_path($argv[5],array_slice($argv,2,3));
    $report=verify_replay($argv[2],$argv[3],$argv[4],['enabled'=>true]);
    verify_write_report($report,$argv[5],array_slice($argv,2,3));
    echo json_encode($report,JSON_THROW_ON_ERROR)."\n";
    exit($report->matched ? 0 : 1);
} catch(Throwable $e) {
    echo json_encode(new VerifyReport(1,'report'),JSON_THROW_ON_ERROR)."\n"; exit(2);
} finally { restore_error_handler(); }
