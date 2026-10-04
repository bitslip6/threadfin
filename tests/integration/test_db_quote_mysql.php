<?php declare(strict_types=1);
/**
 * Opt-in real-server validation, run explicitly with TinyTest -f.
 * Use a disposable database/server; all tables created here are TEMPORARY.
 * @covers ../../db.php
 */
require_once __DIR__ . '/../../db.php';

function db_quote_mysql_query(string $sql): string {
    $socket = getenv('THREADFIN_TEST_MYSQL_SOCKET');
    $database = getenv('THREADFIN_TEST_MYSQL_DATABASE');
    if (!$socket || !$database) {
        throw new RuntimeException('Set THREADFIN_TEST_MYSQL_SOCKET and THREADFIN_TEST_MYSQL_DATABASE to a disposable test server/database');
    }
    $command = [getenv('THREADFIN_TEST_MYSQL_CLIENT') ?: 'mariadb', '--no-defaults', '--protocol=socket',
        '--socket=' . $socket, '--database=' . $database,
        '--user=' . (getenv('THREADFIN_TEST_MYSQL_USER') ?: 'root'),
        '--batch', '--raw', '--skip-column-names'];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Cannot start the SQL test client'); }
    fwrite($pipes[0], $sql);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    assert_eq(proc_close($process), 0, 'Real server must accept the generated SQL: ' . $stderr);
    return rtrim($stdout, "\r\n");
}

/** @type integration */
function test_db_quote_mysql_round_trips_bytes_after_mode_setup(): void {
    $inputs = ['', 'plain text', "'", '\\', "\\' OR 1=1 -- ", "x'); DROP TABLE records; -- ",
        "nul\0 newline\n return\r tab\t eof\x1a quote\"", 'café 中文 😀'];
    $sql = "SET NAMES utf8mb4;\n";
    $expected = [];
    foreach (['', 'NO_BACKSLASH_ESCAPES', 'ANSI_QUOTES', 'NO_BACKSLASH_ESCAPES,ANSI_QUOTES'] as $mode) {
        $sql .= "SET SESSION sql_mode = '$mode';\n" . \ThreadFin\DB\DB_SQL_MODE_SETUP . ";\n";
        foreach ($inputs as $input) {
            $literal = \ThreadFin\DB\quote($input);
            $sql .= "SELECT HEX($literal), CHARSET($literal);\n";
            $expected[] = strtoupper(bin2hex($input)) . "\tutf8mb4";
        }
    }
    assert_eq(explode("\n", db_quote_mysql_query($sql)), $expected,
        'MySQL/MariaDB must preserve all PHP-escaped bytes after connection mode setup, including with ANSI_QUOTES');
}

/** @type integration */
function test_db_quote_mysql_blocks_injection_and_preserves_text_comparisons(): void {
    $sql = "SET NAMES utf8mb4;\nCREATE TEMPORARY TABLE threadfin_quote_security (name VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci);\n";
    $sql .= "INSERT INTO threadfin_quote_security VALUES ('Alice'), ('Bob');\n";
    $expected = [];
    foreach (['', 'NO_BACKSLASH_ESCAPES', 'ANSI_QUOTES', 'NO_BACKSLASH_ESCAPES,ANSI_QUOTES'] as $mode) {
        $sql .= "SET SESSION sql_mode = '$mode';\n" . \ThreadFin\DB\DB_SQL_MODE_SETUP . ";\n";
        foreach (["x' OR 1=1 -- ", "\\' OR 1=1 -- "] as $input) {
            $literal = \ThreadFin\DB\quote($input);
            $sql .= "SELECT COUNT(*) FROM threadfin_quote_security WHERE name = $literal;\n";
            $expected[] = '0';
        }
        $literal = \ThreadFin\DB\quote('alice');
        $sql .= "SELECT COUNT(*) FROM threadfin_quote_security WHERE name = $literal;\n";
        $expected[] = '1'; // Escaped text must retain the column's case-insensitive comparison.
    }
    assert_eq(explode("\n", db_quote_mysql_query($sql)), $expected,
        'Payloads must not widen predicates, and escaped strings must retain normal text collation behavior');
}

/** @type integration */
function test_db_quote_mysql_setup_preserves_other_modes_and_utf8_settings(): void {
    $initialModes = ['', 'NO_BACKSLASH_ESCAPES', 'STRICT_TRANS_TABLES,NO_BACKSLASH_ESCAPES',
        'NO_BACKSLASH_ESCAPES,ANSI_QUOTES,NO_ENGINE_SUBSTITUTION',
        'STRICT_TRANS_TABLES,NO_BACKSLASH_ESCAPES,ANSI_QUOTES', 'ANSI_QUOTES,STRICT_TRANS_TABLES'];
    $sql = "SET NAMES utf8mb4;\n";
    foreach ($initialModes as $mode) {
        $sql .= "SET SESSION sql_mode = '$mode';\n" . \ThreadFin\DB\DB_SQL_MODE_SETUP . ";\n";
        $sql .= "SELECT CONCAT('modes:', @@SESSION.sql_mode), @@character_set_client, @@character_set_connection, @@character_set_results;\n";
    }
    $observed = explode("\n", db_quote_mysql_query($sql));
    assert_count($observed, count($initialModes), 'Check mode removal with empty, sole, first, middle and last flag positions');
    foreach ($observed as $index => $row) {
        $fields = explode("\t", $row);
        assert_eq(array_slice($fields, 1), ['utf8mb4', 'utf8mb4', 'utf8mb4'], 'All connection charset settings must remain UTF-8');
        $actual = array_filter(explode(',', substr($fields[0], strlen('modes:'))));
        $expected = array_filter(explode(',', $initialModes[$index]),
            fn($flag) => $flag !== '' && $flag !== 'NO_BACKSLASH_ESCAPES');
        sort($actual);
        sort($expected);
        assert_eq($actual, $expected, 'SQL mode setup must remove only NO_BACKSLASH_ESCAPES');
    }
}
