<?php declare(strict_types=1);
/** @covers ../db.php ../db-extended.php */

function db_import_probe(string $entry): array {
    $file = realpath(__DIR__ . '/../' . $entry);
    $code = 'require ' . var_export($file, true) . '; echo json_encode(['
        . 'class_exists("ThreadFin\\\\DB\\\\DBExtended", false),'
        . 'method_exists("ThreadFin\\\\DB\\\\DB", "observe"),'
        . 'method_exists("ThreadFin\\\\DB\\\\DB", "dump_resumable"),'
        . 'function_exists("ThreadFin\\\\DB\\\\dump_database"),'
        . 'class_exists("ThreadFin\\\\DB\\\\DB", false),'
        . 'is_subclass_of("ThreadFin\\\\DB\\\\DBExtended", "ThreadFin\\\\DB\\\\DB")]);';
    $process = proc_open([PHP_BINARY, '-n', '-r', $code], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    assert_true(is_resource($process), 'Start isolated import probe');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    assert_eq(proc_close($process), 0, $errors);
    return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
}

function test_db_basic_import_has_no_extended_api(): void {
    assert_eq(db_import_probe('db.php'), [false, false, false, false, true, false], 'Basic import is standalone');
}

function test_db_extended_import_includes_basic_and_extended_api(): void {
    assert_eq(db_import_probe('db-extended.php'), [true, false, false, true, true, true], 'Extended import includes base');
}

function test_db_factories_return_selected_class(): void {
    require_once __DIR__ . '/../db-extended.php';
    assert_eq(get_class(\ThreadFin\DB\DB::from(null)), \ThreadFin\DB\DB::class, 'Basic factory');
    assert_eq(get_class(\ThreadFin\DB\DBExtended::from(null)), \ThreadFin\DB\DBExtended::class, 'Extended factory');
}

function test_db_basic_import_executes_without_extended_file(): void {
    $fixture = realpath(__DIR__ . '/support/mysqli.php');
    $core = realpath(__DIR__ . '/../core.php');
    $base = realpath(__DIR__ . '/../db.php');
    $code = 'require ' . var_export($fixture, true) . '; require ' . var_export($core, true)
        . '; require ' . var_export($base, true)
        . '; $handle = new mysqli(); $db = \\ThreadFin\\DB\\DB::from($handle); '
        . '$ok = $db->insert("records", ["name" => "bob"]); '
        . '$rows = $db->fetch("SELECT 1"); '
        . 'echo json_encode([$ok, $rows->col("name")(), count($handle->queries)]);';
    $process = proc_open([PHP_BINARY, '-n', '-d', 'error_reporting=0', '-r', $code],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    assert_true(is_resource($process), 'Start basic query probe');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    assert_eq(proc_close($process), 0, $errors);
    assert_eq(json_decode($output, true, 512, JSON_THROW_ON_ERROR), [1, 'bob', 3], 'Basic insert/fetch without extended import');
}
