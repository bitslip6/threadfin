<?php declare(strict_types=1);
namespace ThreadFin\DB;

/**
 * Single-pass, closeable associative rows. Use try/finally { $rows->close(); }:
 * breaking foreach does not release a retained iterator or its busy connection.
 * A second rewind throws; no seek/count/buffering helpers are provided. Collecting
 * rows yourself can still consume unbounded memory. Memory scales with the largest
 * row and supplied parameters, not row count. Raw values follow fetch_assoc;
 * prepared values follow native bind_result (including native numeric types).
 * _sql contains rendered legacy SQL or the prepared template, never bound values.
 * Cancellation can drain pending server data; bounded memory is not bounded latency.
 * @internal Construction callbacks belong to DB, not application observers.
 */
final class StreamingRows implements \Iterator {
    private bool $started = false;
    private bool $closed = false;
    private ?array $row = null;
    private int $position = -1;
    private ?TelemetryObserver $observer = null;
    private ?TelemetryOperation $operation = null;

    /** @internal DB attaches only after startup and driver warning-handler restoration. */
    public function attach_observer(TelemetryObserver $observer, ?TelemetryOperation $operation): void {
        $this->observer?->guard();
        $this->observer = $observer;
        $this->operation = $operation;
    }

    public function __construct(public readonly string $_sql, private ?\Closure $fetch,
        private ?\Closure $cleanup) {}

    private function __clone() {}

    public function rewind(): void {
        $this->observer?->guard();
        if ($this->started) { throw new \LogicException('Streaming rows cannot be rewound'); }
        $this->started = true;
        $this->next();
    }
    public function next(): void {
        $this->observer?->guard();
        if ($this->closed) { return; }
        $this->started = true;
        try {
            $this->row = $this->fetch !== null ? ($this->fetch)() : null;
            if ($this->row === null) { $this->finish('complete'); }
            else { $this->position++; $this->operation?->row(); }
        } catch (\Throwable $error) {
            // DB records cleanup failures separately; the fetch failure stays primary.
            try { $this->finish('failure', $error); } catch (\Throwable $cleanup) {}
            throw $error;
        }
    }
    public function current(): ?array { return $this->row; }
    public function key(): int { return $this->position; }
    public function valid(): bool { return !$this->closed && $this->row !== null; }
    public function close(): void {
        $this->observer?->guard();
        $this->finish('cancelled');
    }
    private function finish(string $status, ?\Throwable $primary = null): void {
        if ($this->closed) { return; }
        $this->closed = true;
        $this->row = null;
        $cleanup = $this->cleanup;
        $this->fetch = $this->cleanup = null;
        $operation = $this->operation;
        $this->operation = null;
        try { if ($cleanup !== null) { $cleanup(); } }
        catch (\Throwable $error) { $operation?->finish('failure', $primary ?? $error); throw $error; }
        $operation?->finish($status, $primary);
    }
    public function __destruct() {
        try { $this->close(); } catch (\Throwable $error) { /* DB retains safe cleanup diagnostics. */ }
    }
}
