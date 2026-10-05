<?php declare(strict_types=1);
/** @covers ../db.php */
require_once __DIR__ . '/support/regression.php';

function test_db_stream_writes_zero_and_binary_text_and_empty_chunks_return_current_total(): void {
    assert_eq(db_regression_observe('stream_binary_and_empty'), [[0, 0, 1, 5, 5, 5], "0é\0Z"],
        'Only null/empty text is a no-op; counts are bytes, not characters or truthiness');
}

function test_db_stream_short_writes_retry_only_the_remaining_suffix(): void {
    assert_eq(db_regression_observe('stream_short_write_suffixes'), [6, ['abcdef', 'cdef', 'ef'], 'abcdef'],
        'Positive short writes must complete the chunk without duplicating previously written bytes');
}

function test_db_stream_interleaved_handles_have_independent_cumulative_totals(): void {
    assert_eq(db_regression_observe('stream_interleaved_totals'), [2, 1, 3, 1, 3],
        'Each handle must retain only its own cumulative byte count across interleaved and empty calls');
}

function test_db_stream_false_zero_and_negative_writes_stop_without_losing_partial_progress(): void {
    assert_eq(db_regression_observe('stream_partial_failures'), array_fill(0, 3, [-1, 4, 6, 2, 'abcdef']),
        'Failed/no-progress writes must return -1 promptly; successfully written prefixes remain counted');
}

function test_db_stream_rejects_non_integer_and_oversized_writer_counts(): void {
    assert_eq(db_regression_observe('stream_invalid_writer_counts'), array_fill(0, 4, [-1, 0]),
        'Invalid writer reports must not manufacture progress or cumulative bytes');
}

function test_db_stream_writer_exceptions_propagate_with_successful_prefix_counted(): void {
    assert_eq(db_regression_observe('stream_writer_exception'), [true, 2, 'ab'],
        'Callback exceptions must propagate while preserving real progress from earlier short writes');
}

function test_db_gzip_output_round_trips_binary_text_and_counts_uncompressed_bytes_per_handle(): void {
    assert_eq(db_regression_observe('stream_gzip_round_trip'), [[1, 5, 5], "0é\0Z"],
        'The gzip adapter must share complete-writing semantics without inheriting totals from ordinary streams');
}
