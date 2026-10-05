<?php declare(strict_types=1);
use ThreadFin\DB\SQL;
use ThreadFin\DB\DB;

function db_hardening_case(string $case): mixed {
    switch ($case) {
        case 'closed_result_lifecycle':
            $observations = [];
            foreach ([db_fixture_result(), SQL::from(db_fixture_rows()), new SQL()] as $sql) {
                $sql->close();
                $sql->close();
                $before = [$sql->valid(), count($sql), isset($sql[0]), $sql->col('name')->value()];
                $rows = iterator_to_array($sql);
                $sql->next();
                $sql->rewind();
                $rejected = [];
                foreach ([fn() => $sql[0], fn() => $sql->seek(0)] as $read) {
                    try { $read(); $rejected[] = false; }
                    catch (OutOfBoundsException $error) { $rejected[] = true; }
                }
                $observations[] = [$before, $rows, $sql->current(), $sql->valid(), $sql->as_array(), $rejected];
            }
            return $observations;
        case 'offset_types_consistent':
            $observations = [];
            foreach ([db_fixture_result(), SQL::from(db_fixture_rows())] as $sql) {
                $sql->seek(1);
                $accepted = [];
                foreach ([0, '0', '00', '+0', '-0', 2, '02', '+2'] as $offset) {
                    $accepted[] = [$sql->offsetExists($offset), $sql[$offset]['id']];
                }
                $rejected = [];
                foreach ([0.0, 0.5, false, true, null, '', ' 0', '0 ', '0.0', '1e0', [], new stdClass(),
                          (string)PHP_INT_MAX . '0', '-1', PHP_INT_MIN] as $offset) {
                    try { $sql[$offset]; $throws = false; }
                    catch (OutOfBoundsException $error) { $throws = true; }
                    $rejected[] = [$sql->offsetExists($offset), $throws];
                }
                $observations[] = [$accepted, $rejected, [$sql->key(), $sql->current()['id']]];
            }
            return $observations;
        case 'empty_current_and_read_only':
            $observations = [];
            foreach ([new SQL(), SQL::from(null), SQL::fetch(new mysqli_result([]), 'SELECT')] as $sql) {
                $sql->rewind();
                $sql->next();
                $readonly = [];
                foreach ([fn() => $sql->offsetSet(0, []), fn() => $sql->offsetUnset(0)] as $write) {
                    try { $write(); $readonly[] = false; }
                    catch (OutOfBoundsException $error) { $readonly[] = true; }
                }
                $observations[] = [$sql->current(), $sql->valid(), count($sql), $readonly];
            }
            return $observations;
        case 'disconnected_fetch_metadata':
            $result = DB::from(null)->fetch('SELECT {zero}', ['zero' => 0]);
            return [db_fixture_property($result, '_sql'), count($result), $result->current(), $result->valid()];
        case 'obsolete_fields_removed':
            $removed = [];
            foreach (['_data', '_errors', '_fetch_all'] as $field) { $removed[] = !property_exists(SQL::class, $field); }
            $removed[] = !property_exists(DB::class, '_err_filter_fn');
            // Keep the legacy named parameter accepted, without storing dead state.
            $result = SQL::from([['id' => '1']], fetch_all: false);
            return [$removed, $result->as_array()];
        case 'replay_partial_recovery':
        case 'replay_flush_recovery':
            stream_wrapper_register('dbreplaytest', DbReplayWriteFixture::class);
            try {
                DbReplayWriteFixture::$contents = "# existing\n";
                DbReplayWriteFixture::$blocked = false;
                DbReplayWriteFixture::$failAfter = $case === 'replay_partial_recovery' ? 7 : null;
                DbReplayWriteFixture::$failFlushOnce = $case === 'replay_flush_recovery';
                $db = DB::from(new mysqli())->enable_replay('dbreplaytest://journal');
                $db->unsafe_raw('BEGIN');
                $db->unsafe_raw('INSERT INTO records VALUES (1)');
                try { $db->close(); $rejected = false; }
                catch (RuntimeException $error) { $rejected = true; }
                $afterFailure = DbReplayWriteFixture::$contents;
                $pending = db_fixture_property($db, '_replay');
                DbReplayWriteFixture::$failAfter = null;
                $db->close();
                $db->close();
                return [$rejected, $afterFailure, $pending, DbReplayWriteFixture::$contents,
                    db_fixture_property($db, '_replay')];
            } finally { stream_wrapper_unregister('dbreplaytest'); }
        case 'replay_destination_guard':
            $path = db_fixture_file();
            $other = db_fixture_file();
            $db = DB::from(new mysqli())->enable_replay($path);
            $db->unsafe_raw('INSERT INTO records VALUES (1)');
            $same = $db->enable_replay($path) === $db;
            try { $db->enable_replay($other); $rejected = false; }
            catch (LogicException $error) { $rejected = true; }
            $db->close();
            return [$same, $rejected, file_get_contents($path), file_get_contents($other)];
        case 'replay_initial_autocommit':
            $path = db_fixture_file();
            $handle = new mysqli();
            $GLOBALS['db_fixture_replay_autocommit'] = 0;
            $db = DB::from($handle)->enable_replay($path);
            $db->unsafe_raw('INSERT INTO records VALUES (1)');
            $db->close();
            return file_get_contents($path);
        default:
            throw new InvalidArgumentException("Unknown regression case: $case");
    }
}
