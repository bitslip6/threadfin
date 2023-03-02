<?php
namespace ThreadFin\Cache;

use SysvSemaphore;

use function ThreadFin\Assertions\fn_takes_exactly_x_args;
use function ThreadFin\Assertions\fn_takes_x_args;
use function ThreadFin\Log\trace2;
use function ThreadFin\Log\trace3;

const NOT_FOUND = NULL;

enum CacheType: string {
    case SHMOP = "\ThreadFin\Cache\Cuckoo";
    case APCU = "ThreadFin\Cache\Apcu";
    case APC = "\ThreadFin\Cache\Apc";
    case OPCACHE = "\ThreadFin\Cache\Opcache";
}

enum Serializer: string {
    case IGBINARY = "igbinary_";
    case MSGPACK = "msgpack_";
    case PHP = "\\";
}

/**
 * trivial storage abstraction
 * @package ThreadFin\Cache
 */
interface Storage {
    public function load(string $key) : mixed;
    public function store(string $key, mixed $data, int $ttl = 3600) : bool;
}


class Opcache implements Storage {

    public function load(string $key_name) : mixed {
        $file = $this->key2name($key_name);
        if (file_exists($file)) {
            //die("load cache file [$file]\n");
            @include($this->key2name($key_name));


            /*
            // remove expired data
            if (!$success) {
                @unlink($file);
            }

            if ($success) {
                if (isset($value[0]) && $value[0] == $key_name) {
                    return $value[1];
                }
            }
            */
            if ($success) {
                return $value;
            }
        }

        return NOT_FOUND;
    }

    public function store(string $key_name, mixed $storage, int $seconds = 3600) : bool {
        $s = var_export($storage, true);
        $exp = time() + $seconds; 
        $data = "<?php \$value = $s; \$success = (time() < $exp);";
        return file_put_contents($this->key2name($key_name), $data, LOCK_EX) == strlen($data);
    }

    protected function key2name(string $key) : string {
        $dir = "cache/objects/";
        if (!file_exists($dir)) {
            mkdir($dir, 0775, true);
        }
        return $dir . $key;
    }
}


/**
 * trivial cache abstraction with support for apc, apcu and zend opcache 
 */
class Cache {
    public static CacheType $type;
    public static Serializer $serializer;
    protected static Storage $_instance;
    protected static $_serialize_fn = "serialize";
    protected static $_unserialize_fn = "unserialize";


    public static function init(
        ?CacheType $type = null,
        Serializer $serializer = Serializer::PHP) {

        // initialize
        self::$type = $type;
        self::$serializer = $serializer;
        self::$_serialize_fn = $serializer->value . "serialize";
        self::$_unserialize_fn = $serializer->value . "unserialize";

        // guards
        assert(function_exists(self::$_serialize_fn), 
            "serialize function " . self::$_serialize_fn . " could not be found");
        assert(function_exists(self::$_unserialize_fn), 
            "unserialize function " . self::$_unserialize_fn . " could not be found");

        switch ($type) {
            case null:
                if (function_exists("apcu_store")) {
                    self::$type = CacheType::APCU;
                }
                else if (function_exists("apc_store")) {
                    self::$type = CacheType::APC;
                }
                else if (function_exists("shmop_open")) {
                    self::$type = CacheType::SHMOP;
                }
                else {
                    self::$type = CacheType::OPCACHE;
                }
                break;
        }

        $class = self::$type->value;
        self::$_instance = new $class();
    }

    /**
     * save data to key_name for $ttl seconds
     */
    public static function store(string $key_name, $data, int $ttl = 3600) : bool {
        $fn = self::$_serialize_fn;
        // guards
        assert(strlen($key_name) > 4, "key_name must be at least 4 characters");
        assert(strlen($key_name) < 96, "key_name must be at less than 96 characters");
        assert(self::$_instance instanceof Storage, "must call Cache::init() first");
        assert(function_exists($fn), "serialize function {$fn} could not be found");

        trace2("CACHE_ST");
        $store_value = $fn($data);
        return self::$_instance->store($key_name, $store_value, $ttl);
    }

    /**
     * load data from $key_name
     * @param mixed $key_name 
     * @return mixed - null if key not found, or expired
     */
    public static function load($key_name) : mixed {
        $fn = self::$_unserialize_fn;
        // die("fn: $fn");
        // guards
        assert(strlen($key_name) > 4, "key_name must be at least 4 characters");
        assert(strlen($key_name) < 96, "key_name must be at less than 96 characters");
        assert(self::$_instance instanceof Storage, "must call Cache::init() first");
        assert(function_exists($fn), "serialize function {$fn} could not be found");

        trace2("CACHE_LD");
        return $fn(self::$_instance->load($key_name));
    }

    /**
     * update cache entry @key_name with result of $fn or $init if it is expired.
     * return the cached item, or if expired, init or $fn
     * @param string $key_name - the name of the cache entry
     * @param int $ttl - seconds to cache for
     * @param callable $fn called with the previous value, caches and returned $fn() value
     * @param callable $init if there is no value in the cache, $fn called with this value
     * @return mixed the result of the $fn call
     */
    public static function update_data(string $key_name, callable $fn, callable $init, int $ttl) : mixed {
        // make sure our callbacks are the right shape
        assert(fn_takes_x_args($init, 0), "init must take 0 arguments");
        assert(fn_takes_x_args($fn, 1), "fn must take 1 argument");

        // lock the cache key for updating
        $sem = cache::lock($key_name);
        // load the existing data
        $data = cache::load($key_name);
        // if it is null, init the data
        if ($data === NOT_FOUND) {
            trace3("CACHE_INIT");
            $data = $init();
        }
        // update the cache entry
        $updated = $fn($data);

        // store the new value and release the lock
        self::store($key_name, $fn($data), $ttl);
        self::unlock($sem);

        // return the updated value
        return $updated;
    }

    /**
     * Lock the cache GLOBALLY for this key until script exit or unlock is called
     * THIS CALL CAN BLOCK
     * @return null|SysvSemaphore , null if semaphore could not be acquired.  
     */
    public static function lock(string $key_name) : ?SysvSemaphore {
        $sem = null;

        if (function_exists('sem_acquire')) {
            $opt = (PHP_VERSION_ID >= 80000) ? true : 1;
            $sem = sem_get(crc32($key_name), 1, 0660, $opt);
            if (!sem_acquire($sem)) {
                return null;
            }
        }
        return $sem;
    }
    
    // unlock the semaphore if it is not null
    public static function unlock(?SysvSemaphore $sem) : bool {
        if ($sem != null && function_exists('sem_release')) {
            return sem_release(($sem));
        }
        return false;
    }
}
