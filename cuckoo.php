<?php
declare(strict_types=1);
namespace BitSlip6;
define("CUCKOO_SLOT_SIZE_BYTES", 15);
define("CUCKOO_EXP_SIZE_BYTES", 6);
define("CUCKOO_MAX_SIZE", 65534);


define("CUCKOO_READ_EXPIRED", false);
define("CUCKOO_PRIMARY", 1);
define("CUCKOO_ALT", 2);
define("CUCKOO_LOCK", 4);
define("CUCKOO_EMPTY", 8);
define("CUCKOO_FULL", 16);
define("CUCKOO_LOW", 32);
define("CUCKOO_HIGH", 64);
define("CUCKOO_PERM", 128);

define("CUCKOO_PERM_MASK", 0 | CUCKOO_PERM | CUCKOO_HIGH | CUCKOO_LOW);
define("CUCKOO_NOT_MASK", 0 | CUCKOO_PRIMARY | CUCKOO_ALT | CUCKOO_LOCK | CUCKOO_EMPTY | CUCKOO_FULL);
define("CUCKOO_POSITIONS", [CUCKOO_PRIMARY, CUCKOO_ALT]);

// ghetto FP reduce, we use as repeat
function reduce(int $num, callable $f, $x) {
    while ($num-- > 0) {
        $x = $f($x);
    }
    return $x;
}

// ghetto FP loop until max times or callable returns true
function until(int $max = 10, callable $f): bool {
    $r = false;
    while ($r === false && $max > 0) {
        $r = $f($max--);
    }
    return $r;
}

// ghetto FP search
function find(array $items, callable $f) {
    for($i=0,$m=count($items);$i<$m;$i++) {
        $r = $f($items[$i], $i);
        if ($r !== null) {
            return $r;
        }
    }
    return null;
}

function if_else($truth, callable $is_fn, callable $is_not_fn) {
    if ($truth) {
        return $is_fn();
    } else {
        return $is_not_fn();
    }
}

// naive power of 2
function power_of_2(int $n): bool { 
    if ($n === 0) { return false; }
    while ($n !== 1) { 
        if ($n % 2 !== 0) { return false; }
        $n = $n / 2; 
    } 
    return true; 
} 

/**
 * find a key's 2 locations 
 */
function cuckoo_key(string $key): array {
    return array(hexdec(hash('crc32', $key, false)), hexdec(hash('crc32b', $key, false)));
}

/**
 * todo this needs to read in chunks of 64 4 byte ints at the expired list location
 * and search for the first free memory chunk...
 * the memory should look for a random slot in the chunk of available memory
 */
function cuckoo_find_free_mem($ctx, $size, $priority = 0 | CUCKOO_LOW): array
{
    // can't attempt to store values beyond the max size
    if ($size > CUCKOO_MAX_SIZE) {
        return [null, null];
    }

    // calculate a reasonable block_size
    $block_size = intval(ceil($size / $ctx['chunk_size']) * $ctx['chunk_size']);

    // lock memory
    if (cuckoo_lock_for_write($ctx, $block_size)) {
        // find a location to allocate at the end of the stack, if full, defrag and return end of stack
        $ptr = ($ctx['free'] + $block_size >= $ctx['mem_end']) ? cuckoo_mem_defrag($ctx) : $ctx['free'];

        // if we have a location (not full and defrag successful), update pointer location
        if ($ptr !== null) {
            $mem = unpack("nitems/nsize/Lfree", shmop_read($ctx['rid'], ($ctx['mem_end']), 8));
            shmop_write($ctx['rid'], pack("nnL", $ctx['slots'], $ctx['chunk_size'], $ptr + $block_size), $ctx['mem_end']);
        }

        // unlock memory, really, this should never fail...
        $r = until(10, function() use ($ctx) { return set_lock($ctx, 0); });
        //var_dump($r);

        return [$ptr, $block_size];
    }

    return [null, $block_size];
}

function get_lock($ctx): int
{
    $data = unpack("Ltxid/Lexp", shmop_read($ctx['rid'], $ctx['mem_end'] + 8, 8));
    return ($data['exp'] < $ctx['now']) ? 0 : $data['txid'];
}

function set_lock($ctx, $value): bool
{
    shmop_write($ctx['rid'], pack("LL", $value, $ctx['now']), $ctx['mem_end'] + 8);
    return (get_lock($ctx) === $value);
}

function cuckoo_lock_for_write(array $ctx, $block_size): bool
{
    return until(5, function($idx) use ($ctx) {

        $lock = get_lock($ctx);
        // unlocked
        if ($lock === 0) {
            return set_lock($ctx, $ctx['txid']);
        }
        // we oen the lock
        if ($lock === $ctx['txid']) {
            return true;
        }
        // we don't own the lock, wait and try again
        usleep(mt_rand(50,700));
        return false;
    });
}

function cuckoo_mem_defrag($ctx): void
{
    $final = null;

    reduce(intval(ceil($ctx['items'] / 64)), function($x) use ($ctx, &$final) {
        $read_len = (CUCKOO_EXP_SIZE_BYTES * 64);
        $start_byte = $x * $read_len;

        $bytes = shmop_read($ctx['rid'], $start_byte, $read_len);
        for($i=0;$i<64;$i++) {
            $header = unpack("Loffset/Lhash/Lexpires/nlen/Cflags", $bytes, $i * CUCKOO_EXP_SIZE_BYTES);
            //print_r($header);
            if ($header['expires'] > $ctx['now']) {
                $final .= shmop_read($ctx['rid'], $start_byte, $read_len); 
            }
            $bytes = shmop_read($ctx['rid'], $start_byte, $read_len);
        }

        return $x+1;
    }, 0);
}


/**
 * write $item to $key and expires in ttl_sec
 * will overwrite existing keys with a lower priority
 */
function cuckoo_write(array &$ctx, string $key, int $ttl_sec, $item, int $priority = 0 | CUCKOO_LOW ): bool
{
    $header = cuckoo_find_header_for_write($ctx, $key, $priority);

    // we have a header we can write cache data to...
    if ($header !== null) {
        $data = serialize($item);
        $size = strlen($data);

        // we can't store this much data in the cache...
        if ($size > CUCKOO_MAX_SIZE) {
            return false;
        }

        $mem = ($header['len'] < $size) ? cuckoo_find_free_mem($ctx, $size, $priority) : [$header['offset'], $header['len']];

        // we have available memory that will fit
        if ($mem[0] !== null) {
            // clear permissions...
            $header['flags'] = set_flag_priority($header['flags'], $priority) | CUCKOO_FULL;
            $header['offset'] = $mem[0];
            $header['len'] = $size;
            $header['expires'] = $ctx['now'] + $ttl_sec;
            $ctx['free'] += intval($mem[1]);

            if (cuckoo_write_header($ctx, $header)) {
                return shmop_write($ctx['rid'], $data, $mem[0]) === $size;
            }
        }
    }

    return false;
}

/**
 * return an array with the in cache header
 * long1 - memory offset, long2 - hash_key, long3 - expires time
 * len - length of data, open - true/false if the slot is open
 */
function cuckoo_read_header(array $ctx, int $key_hash, callable $fn): ?array
{
    $slot_num = $key_hash % $ctx['slots'];
    $slot_loc = $slot_num * CUCKOO_SLOT_SIZE_BYTES;

    //$header = unpack("L3long/nlen/Cflags", shmop_read($ctx['rid'], 
    $header = unpack("Loffset/Lhash/Lexpires/nlen/Cflags", shmop_read($ctx['rid'], $slot_loc, CUCKOO_SLOT_SIZE_BYTES));
    $header['slot_num'] = $slot_num;

    // return the filtering function
    return $fn($header);
}

/**
 * return an array with the in cache header
 * long1 - memory offset, long2 - hash_key, long3 - expires time
 * len - length of data, open - true/false if the slot is open
 */
function cuckoo_write_header(array $ctx, array $header): bool
{
    return shmop_write($ctx['rid'], 
        pack("LLLnC", $header['offset'], $header['hash'], $header['expires'], $header['len'], $header['flags']),
        $header['slot_num'] * CUCKOO_SLOT_SIZE_BYTES) === CUCKOO_SLOT_SIZE_BYTES;
}


/**
 * find a header for reading
 */
function cuckoo_find_header_for_read(array $ctx, string $key): ?array
{
    $key_hashes = cuckoo_key($key);
 
    return find($key_hashes, function(int $hash, int $index) use ($ctx) {
        return cuckoo_read_header($ctx, $hash, function(array $header) use ($hash, $index, $ctx) {
            // return  empty headers, expired headers, or matching headers

            return ($header['expires'] > $ctx['now'] && $header['hash'] === $hash)
                ? $header : null;
        });
    });
}

// clear position flags and keep any other flags, then set the position
function set_flag_position(int $flag, int $flag_position): int
{
    $flag = $flag & CUCKOO_PERM_MASK;
    return $flag | $flag_position;
}

// clear position flags and keep any other flags, then set the position
function set_flag_priority(int $flag, int $flag_priority): int
{
    $flag = $flag & CUCKOO_NOT_MASK;
    return $flag | $flag_priority;
}


/**
 * find a header for reading
 */
function cuckoo_find_header_for_write(array $ctx, string $key, int $priority): ?array
{
    $key_hashes = cuckoo_key($key);

    return find($key_hashes, function(int $hash, int $index) use ($ctx, $priority) {
        return cuckoo_read_header($ctx, $hash, function($header) use ($hash, $priority, $index, $ctx) {
            // key matches, or is expired, or the priority is lower
            if ($header['hash'] === $hash || $header['expires'] < $ctx['now'] || 
                ($header['flags'] & CUCKOO_PERM_MASK) < $priority) {
                    // set the correct hash position (primary, alt) flag
                    $header['flags'] = 0 | CUCKOO_POSITIONS[$index];
                    $header['hash'] = $hash;
                    return $header;
                }
            return null;
        });
    });
}



/**
 * read a previously stored cache key
 */
function cuckoo_read_or_set(array $ctx, string $key, int $ttl, callable $fn)
{
    $header = cuckoo_find_header_for_read($ctx, $key);

    return if_else($header !== null, 
        function() use ($ctx, $header) {
            return unserialize(shmop_read($ctx['rid'], 
                $header['offset'], $header['len']), array("allowed_classes" => false)); },
        function() use ($ctx, $key, $ttl, $fn) {
            $data = $fn();
            cuckoo_write($ctx, $key, $ttl, $data);
            return $data;
        }
    );
}

function cuckoo_read(array $ctx, string $key)
{
    $header = cuckoo_find_header_for_read($ctx, $key);

    return ($header === null || $header['len'] < 1) ? null :
        unserialize(
            shmop_read($ctx['rid'], 
            $header['offset'],
            $header['len']), array("allowed_classes" => false));
}


/**
 * memory is laid out like:
 * [LRU_HASH_ENTRY],[LRU_HASH_ENTRY]..X.items,[MEM_EXP],[MEM_EXP]..X.items,[MEM],[MEM]..X.items
 * LRU_HASH_ENTRY - hash_key,expires_ts,size,full|empty
 * MEM_EXP - expires_ts
 * MEM - chunk X chunk size bytes
 */
function cuckoo_init_memory(array $ctx, int $items, int $chunk_size): void
{   
    // some rules about our cache
    assert($items <= 100000, "max 100K items in cache");
    assert($chunk_size <= 16384, "max base chunk_size 16K");
    assert(power_of_2($chunk_size));


    // initial expired memory block (5 bytes)
    $exp_full_block = pack("Ln", time() + 60, 0);
    $exp_empty_block = pack("Ln", 1, 0);
    $exp_block = pack("Ln", time() + 60, 0);
    // initial slot header (15 bytes)
    $header_block = pack("LLLnC", 0, 0, 0, 0, 0 | CUCKOO_EMPTY);

    reduce($items, function($x) use ($exp_empty_block, $exp_full_block, $header_block, $ctx) {
        shmop_write($ctx['rid'], $header_block, $x * CUCKOO_SLOT_SIZE_BYTES);
        $block = pack("Ln", (mt_rand(1,50) == 2) ? 1 : time() + 60, $x);
        shmop_write($ctx['rid'], $block,    $ctx['mem_start'] + ($x * CUCKOO_EXP_SIZE_BYTES));
        return $x+1;
    }, 0);

    // mark all of the memory as initialized
    shmop_write($ctx['rid'], pack("nnLLL", $items, $chunk_size, $ctx['mem_start'], 0, 0), $ctx['mem_end']);
}

/**
 * helper function to open shared memory
 */
function cuckoo_open_mem(int $size_in_bytes, string $key) {
    $token = ftok(__FILE__, $key);
    $id = @shmop_open($token, 'c', 0600, $size_in_bytes);

    // unable to attach created memory segment, recreate it...
    if ($id === false) {
        // connect failed, we probably have an old mem segment that is not large enough
        $id = shmop_open($token, 'w', 0, 0);
        if ($id) { shmop_delete($id); }
        $id = shmop_open($token, 'c', 0600, $size_in_bytes);
        if ($id === false) {
            die("unable to allocate $size_in_bytes shared memory\n");
        }
    }
    return $id;
}

/**
 * connect to the existing shared memory or initialize new shared memory
 */
function cuckoo_connect(int $items = 4096, int $chunk_size = 1024, int $mem = 1114112, bool $force_init = false, string $key = "a"):array
{
    $size = ($items * $chunk_size);
    $entry_end = $items * CUCKOO_SLOT_SIZE_BYTES;
    //$exp_end = ($entry_end + ($items * CUCKOO_EXP_SIZE_BYTES)); 
    //$mem_start = $entry_end;
    $mem_end = $entry_end + $mem;

    $rid = cuckoo_open_mem($mem_end + 16, $key);

    // TODO: make this a readonly class
    $ctx = Array(
        'rid' => $rid, 
        'txid' => mt_rand(1, 2147483647),
        "mem_start" => $entry_end,
        "mem_end" => $entry_end + $mem,
        "slots" => $items,
        "now" => time(),
        "chunk_size" => $chunk_size);


    // initialized memory writes num items and chunk size to last initialized byte
    $mem = unpack("nitems/nsize/Lfree/Llockid/Llockexp", shmop_read($ctx['rid'], ($ctx['mem_end']), 16));

    // memory needs initialization...
    if ($force_init || $mem['items'] !== $items || $mem['size'] !== $chunk_size) {
        cuckoo_init_memory($ctx, $items, $chunk_size);
        $ctx['free'] = $ctx['mem_start'];
    } else {
        $ctx['free'] = $mem['free'];
    }

    return $ctx;
}

class cuckoo {
    private static $ctx = null;
    public static $conf = [4096, 1024, 1114112, false, "a"];

    public static function read($key) {
        if (self::$ctx === null) { self::$ctx = cuckoo_connect(self::$conf[0], self::$conf[1], self::$conf[2], self::$conf[3], self::$conf[4]); }
        return cuckoo_read(self::$ctx, $key);
    }

    public static function read_or_set(string $key, int $ttl, callable $fn) {
        if (self::$ctx === null) { self::$ctx = cuckoo_connect(self::$conf[0], self::$conf[1], self::$conf[2], self::$conf[3], self::$conf[4]); }
        return cuckoo_read_or_set(self::$ctx, $key, $ttl, $fn);
    }

    public static function write(string $key, int $ttl, $item, int $priority = 0 | CUCKOO_LOW ) {
        if (self::$ctx === null) { self::$ctx = cuckoo_connect(self::$conf[0], self::$conf[1], self::$conf[2], self::$conf[3], self::$conf[4]); }
        return cuckoo_write(self::$ctx, $key, $ttl, $item, $priority);
    }
}
