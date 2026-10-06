<?php declare(strict_types=1);
namespace ThreadFin\DB;

/** Immutable privacy-safe scalar export. template_id is keyed exact-template identity,
 * NOT a normalized SQL shape. No SQL, values, driver messages or exception objects.
 * simulated denotes execution-boundary mode (entry mode for earlier validation
 * failures). Stream events retain startup mode through consumption/cancellation.
 */
final class QueryEvent {
    public function __construct(
        public readonly int $version,
        public readonly string $correlation_id,
        public readonly int $operation_id,
        public readonly string $kind,
        public readonly bool $simulated,
        public readonly string $status,
        public readonly int|float $duration_ns,
        public readonly int|float|null $startup_ns,
        public readonly int|float|null $first_row_ns,
        public readonly ?int $rows,
        public readonly ?int $affected,
        public readonly ?int $errno,
        public readonly ?string $sqlstate,
        public readonly string $template_id
    ) {}
    public function __set(string $name, mixed $value): void { throw new \LogicException('Query events are immutable'); }
    public function __unset(string $name): void { throw new \LogicException('Query events are immutable'); }
}

/** @internal Stable per-DB dispatch latch, retained across disable/replacement.
 * Contains no callback, SQL, values, observer or DB reference.
 */
final class TelemetryGuard {
    private bool $active = false;
    private int $rejections = 0;
    public function guard(): void {
        if ($this->active) {
            if ($this->rejections < PHP_INT_MAX) { $this->rejections++; }
            // Deliberately not ExecutionFailure: must never latch a transaction.
            throw new \LogicException('Recursive database observer operation rejected');
        }
    }
    public function enter(): void { $this->guard(); $this->active=true; $this->rejections=0; }
    public function leave(): int {
        $this->active=false;
        $rejections=$this->rejections; $this->rejections=0;
        return $rejections;
    }
}

/** @internal One observer installation; bounded counters, no event history. */
final class TelemetryObserver {
    private \Closure $observer;
    private string $key;
    private string $correlation;
    private float $rate;
    public readonly float $threshold_ns;
    private int $sequence = 0;
    private int $failures = 0;
    private int $recursions = 0;

    public function __construct(callable $observer, array $options, private TelemetryGuard $guard) {
        if (array_diff(array_keys($options), ['sample_rate','slow_threshold_ms','hmac_key']) !== []) {
            throw new \InvalidArgumentException('Unknown telemetry option');
        }
        $rate = array_key_exists('sample_rate',$options) ? $options['sample_rate'] : 1;
        $slow = array_key_exists('slow_threshold_ms',$options) ? $options['slow_threshold_ms'] : 0;
        foreach ([[$rate,1],[$slow,86400000]] as [$value,$max]) {
            if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value) || $value < 0 || $value > $max) {
                throw new \InvalidArgumentException('Invalid telemetry sampling or threshold');
            }
        }
        if (array_key_exists('hmac_key',$options) && (!is_string($options['hmac_key']) || strlen($options['hmac_key']) < 16)) {
            throw new \InvalidArgumentException('Telemetry HMAC key requires at least 16 bytes');
        }
        $this->observer = \Closure::fromCallable($observer);
        $this->rate = (float)$rate;
        $this->threshold_ns = (float)$slow * 1000000;
        $this->key = $options['hmac_key'] ?? random_bytes(32);
        $this->correlation = bin2hex(random_bytes(16));
    }
    public function guard(): void { $this->guard->guard(); }
    public function diagnostics(): array {
        return ['observer_failures'=>$this->failures, 'recursive_rejections'=>$this->recursions];
    }
    public function begin(string $kind, string $template, bool $simulated): ?TelemetryOperation {
        if ($this->rate === 0.0 || ($this->rate < 1.0 && random_int(0,PHP_INT_MAX-1) / PHP_INT_MAX >= $this->rate)) { return null; }
        // Sampling uses the OS RNG, never the application's mt_rand sequence.
        if ($this->sequence === PHP_INT_MAX) { $this->correlation=bin2hex(random_bytes(16)); $this->sequence=0; }
        return new TelemetryOperation($this, $this->correlation, ++$this->sequence, $kind, $simulated,
            hash_hmac('sha256',$template,$this->key));
    }
    public function emit(QueryEvent $event): void {
        $this->guard->enter();
        try { ($this->observer)($event); }
        catch (\Throwable $error) { if ($this->failures < PHP_INT_MAX) { $this->failures++; } }
        finally {
            $rejected=$this->guard->leave();
            $this->recursions=$rejected > PHP_INT_MAX-$this->recursions ? PHP_INT_MAX : $this->recursions+$rejected;
        }
    }
}

/** @internal Selected operation state contains only safe data; no SQL or parameters. */
final class TelemetryOperation {
    private int|float $started;
    private int|float|null $startup = null;
    private int|float|null $first = null;
    private ?int $rows = null;
    private ?int $affected = null;
    private ?int $errno = null;
    private ?string $state = null;
    private bool $failed = false;
    private bool $finished = false;
    public function __construct(private TelemetryObserver $owner, private string $correlation,
        private int $id, private string $kind, private bool $simulated, private string $template) {
        $this->started = hrtime(true);
    }
    /** Capture after application-capable rendering, at the simulation/native choice.
     * Called only at execution startup, never when a retained stream is consumed.
     */
    public function execution_mode(bool $simulated): void { $this->simulated=$simulated; }
    public function counts(?int $rows, ?int $affected): void { $this->rows=$rows; $this->affected=$affected; }
    public function fail(?int $errno = null, ?string $state = null): void {
        $this->failed=true; $this->errno=$errno;
        // SQLSTATE is a driver code, never an arbitrary diagnostic string.
        $this->state=$state !== null && preg_match('/\A[0-9A-Z]{5}\z/D',$state) === 1 ? $state : null;
    }
    public function stream_start(): void {
        $this->startup=hrtime(true)-$this->started; $this->rows=0;
        // Positive thresholds deliberately use terminal-only events, not orphan starts.
        if ($this->owner->threshold_ns === 0.0) { $this->publish('start',$this->startup); }
    }
    public function row(): void {
        if ($this->first === null) { $this->first=hrtime(true)-$this->started; }
        if ($this->rows < PHP_INT_MAX) { $this->rows++; }
    }
    public function finish(string $status = 'success', ?\Throwable $error = null): void {
        if ($this->finished) { return; }
        $this->finished=true;
        if ($error !== null) { $this->fail($error instanceof ExecutionFailure ? $error->errno : null,
            $error instanceof ExecutionFailure ? $error->sqlstate : null); }
        $duration=hrtime(true)-$this->started;
        if ($duration >= $this->owner->threshold_ns) { $this->publish($this->failed ? 'failure' : $status,$duration); }
    }
    private function publish(string $status, int|float $duration): void {
        $this->owner->emit(new QueryEvent(1,$this->correlation,$this->id,$this->kind,$this->simulated,$status,
            $duration,$this->startup,$this->first,$this->rows,$this->affected,$this->errno,$this->state,$this->template));
    }
}
