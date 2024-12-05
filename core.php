<?php
namespace ThreadFin\File;

use ThreadFin\Core\TF_Error;

use function ThreadFin\Log\debug;
use function ThreadFin\Log\trace2;
use function ThreadFin\Util\dbg;

require __DIR__ . "/storage.php";

const FILE_RW = 0664;
const FILE_R = 0444;

/**
 * a file modification class to define a change to the file system
 */
class FileChange {
   public function __construct(
        public readonly string $filename,
        public readonly string $content,
        public readonly int $write_mode = 0,
        public readonly int $mod_time = 0,
        public readonly bool $append = false) {
            assert(!empty($filename), "File Change has empty filename");
            assert(in_array($write_mode, [0644, 0664, 0666, 0775, 0444, 0400, 0000]), "File Permissions must be 0644, 0664, 0666, 0775, 0444, or 0400");
        }
}


/**
 * write a file-change to the file system
 * @param FileChange $file 
 * @return null|TF_Error 
 */
function writer(FileChange $file) : ?TF_Error {
    assert(!empty($file->filename), "can't write to null file: " . json_encode($file));
    trace2("WFile");

    $len = strlen($file->content);
    debug("FS(w) [%s] (%d)bytes", $file->filename, $len);


    // create the path if we need to
    $dir = dirname($file->filename);
    if (!file_exists($dir)) {
        // echo "mkdir ($dir)\n";
        if (!mkdir($dir, 0755, true)) {
            return new TF_Error("unable to mkdir -r [$dir]", __FILE__, __LINE__);
        }
    }

    // ensure write-ability
    $perm = -1;
    if (file_exists($file->filename)) {
        if (!is_writeable($file->filename)) {
            $st = stat($file->filename);
            $perm = $st["mode"];
            if (!chmod($file->filename, FILE_RW)) {
                $name = basename($file->filename);
                return new TF_Error("unable to make file writeable [$name]", __FILE__, __LINE__);
            }
        }
    }

    // write the file content
    $mods = ($file->append) ? FILE_APPEND : LOCK_EX;
    $written = file_put_contents($file->filename, $file->content, $mods);
    if ($written != $len) {
        $e = error_get_last();
        return new TF_Error("failed to write file: $file->filename " . strlen($file->content) . " bytes. " . json_encode($e), __FILE__, __LINE__);
    }

    // update file permissions
    if ($file->write_mode > 0) {
        if (!chmod($file->filename, $file->write_mode)) {
            return new TF_Error("unable to chmod {$file->filename} perm: " . $file->write_mode, __FILE__, __LINE__);
        }
    }

    // restore file permissions if we changed them
    else if ($perm != -1) {
        if (!chmod($file->filename, $perm)) {
            return new TF_Error("unable to restore chmod: {$file->filename} perm: {$perm}", __FILE__, __LINE__);
        }
    }

    // update the file modification time
    if ($file->mod_time > 0) { 
        if (!touch($file->filename, $file->mod_time)) {
            return new TF_Error("unable to set {$file->filename} mod_time to: " . $file->mod_time, __FILE__, __LINE__);
        }
    }

    return NULL;
}

namespace ThreadFin\Log;

if (!defined("ThreadFin\Log\TRACE_LEVEL")) {
    define("ThreadFin\Log\TRACE_LEVEL", 1);
}
const RETURN_LOG = -99;
const CLEAN_LOG = -101;
const ACTION_RETURN = -9999999;
const ACTION_CLEAN = -9999998;

function trace1(string $marker, int $stacktrace_level = 0) : void {
    if (TRACE_LEVEL < 1) { return; }
    trace(1, $marker, $stacktrace_level);
}
function trace2(string $marker, int $stacktrace_level = 0) : void {
    if (TRACE_LEVEL < 2) { return; }
    trace(2, $marker, $stacktrace_level);
}
function trace3(string $marker, int $stacktrace_level = 0) : void {
    if (TRACE_LEVEL < 3) { return; }
    trace(3, $marker, $stacktrace_level);
}

/**
 * add a marker to the TRACE LOG. if level === TRACE_RETURN_LOG, the trace log will be returned
 *  be returned
 * @param int $level - the message log level.  
 * @param string $marker - a short marker for the trace log
 * @param int $stacktrace_level - display a file and line number from this many stack frames ago (+1 for this function)
 * @return void 
 */
function trace(int $level, string $marker = "", int $stacktrace_level = 0) : ?string {
    static $markers = "";
    // return the trace log if we're done
    if ($level === RETURN_LOG) {
        return $markers;
    }
    assert(strlen($marker) > 0 && strlen($marker) < 16, "trace markers must be between 1 and 16 characters long. [$marker]");

    if ($stacktrace_level > 0) {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $stacktrace_level + 1);
        $file = basename($trace[$stacktrace_level+1]['file']);
        $line = intval($trace[$stacktrace_level+1]['line']);
        $marker .= "($file:$line)";
    }
    $markers .= "$marker, ";
    return NULL;
}

/**
 * ensure that a format string has the correct number of arguments
 * called from assert
 * @param null|string $fmt 
 * @param int $args 
 * @return bool 
 */
function format_chk(?string $fmt, int $args) : bool {
    if ($fmt == null) { return true; }
    return(substr_count($fmt, "%") === $args);
}


/**
 * debug log can be disabled by un-defining LOG_DEBUG_ENABLE
 * add a line to the debug log, immediately output a header if LOG_DEBUG_HEADER is defined
 */
function debug(?string $fmt, ...$args) : ?array {
    assert(format_chk($fmt, count($args)), "programmer error, format string does not match number of arguments [$fmt]");

    static $idx = 0;
    static $len = 0;
    static $log = [];
    if (!defined('LOG_DEBUG_ENABLE')) { return null;}
    if (empty($fmt)) { return $log; } // retrieve log lines

    $fmt = str_replace(array("\r","\n"), array(" "," "), $fmt);
    foreach ($args as &$arg) { if (is_array($arg)) { $arg = json_encode($arg, JSON_PRETTY_PRINT); } }
    $line = sprintf($fmt, ...$args);
    $log[] = $line;
    // directly write to the header line
    if (defined('LOG_DEBUG_HEADER')) {
        if (!headers_sent() && $idx < 24) {
            $line = str_replace(array("\r","\n",":"), array(" "," ",";"), $line);
            $s = sprintf("x-log-%02d: %s", $idx, substr($line, 0, 1024));
            $len += strlen($s);
            if ($len < 2048-1) {
                header($s);
            }
            $idx++;
        }
    }

    file_put_contents(LOG_PATH, "$line\n", FILE_APPEND | LOCK_EX);
    return null;
}

/**
 * register the path to write the debug log to on shutdown
 * @param string $path 
 * @return void 
 */
function debug_log_file(string $path, bool $immediate = false) : void {
    if (!defined('LOG_DEBUG_ENABLE')) {
        define ("LOG_DEBUG_ENABLE", true);
    }
    define("LOG_IMMEDIATE", $immediate);
    define("LOG_PATH", $path);
    if (LOG_DEBUG_ENABLE) {
        if (!$immediate) {
        register_shutdown_function(function() use ($path) {
            $log = debug(null);
            if (empty($log)) { return; }
            $log = implode("\n", $log);
            file_put_contents($path, "$log\n", FILE_APPEND);
        });
        }
    }
}


namespace ThreadFin\Core;

use InvalidArgumentException;
use OutOfBoundsException;
use ReturnTypeWillChange;
use ThreadFin\File\FileChange;

use const ThreadFin\DAY;
use const ThreadFin\Log\RETURN_LOG;

use function ThreadFin\Assertions\fn_arg_x_has_type;
use function ThreadFin\Assertions\fn_arg_x_is_type;
use function ThreadFin\Assertions\fn_returns_type;
use function ThreadFin\Assertions\fn_takes_x_args;
use function ThreadFin\Assertions\fn_takes_x_or_more_args;
use function ThreadFin\Assertions\last_assert_err;
use function ThreadFin\Log\debug;
use function ThreadFin\Log\trace;
use function ThreadFin\Log\trace2;
use function ThreadFin\Log\trace3;
use function ThreadFin\Util\array_clone;
use function ThreadFin\Util\func_name;

/**
 * a root class all of our classes 
 * @package ThreadFin
 */
class Entity {
} 

interface Hash_Code {
    public function hash_code() : string;
}


/**
 * a <generic> list. this is the base class for all typed lists 
 * @package ThreadFin\Core
 */
abstract class Typed_List implements \ArrayAccess, \Iterator, \Countable, \SeekableIterator {

    protected string $_type = "mixed";
    protected $_position = 0;
    protected array $_list;

    private bool $_is_associated = false;
    private $_keys;

    
    public function __construct(array $list = []) {
        $this->_list = $list;
        $this->_type = $this->get_type();
    }

    /**
     * Example: echo $list[$index]
     * @param mixed $index index may be numeric or hash key
     * @return $this->_type cast this to your subclass type
     */
    #[\ReturnTypeWillChange]
    public abstract function offsetGet(mixed $index): mixed;

    /**
     * Example: foreach ($list as $key => $value)
     * @return mixed cast this to your subclass type at the current iterator index
     */
    #[\ReturnTypeWillChange]
    public abstract function current(): mixed;


    /**
     * @return string the name of the type list supports or mixed
     */
    public abstract function get_type() : string;


    /**
     * return a new instance of the subclass with the given list
     * @param array $list 
     * @return static 
     */
    public static function of(array $list) : static {
        return new static($list);
    }

    /**
     * clone the current list into a new object
     * @return static new instance of subclass
     */
    #[\ReturnTypeWillChange]
    public function clone() {
        $new = new static();
        $new->_list = array_clone($this->_list);
        return $new;
    }

    /**
     * Example count($list);
     * @return int<0, \max> - the number of elements in the list
     */
    public function count(): int {
        return count($this->_list);
    }

    /**
     * SeekableIterator implementation
     * @param mixed $position - seek to this position in the list
     * @throws OutOfBoundsException - if the element does not exist
     */
    public function seek($position) : void {
        if (!isset($this->_list[$position])) {
            throw new OutOfBoundsException("invalid seek position ($position)");
        }
  
        $this->_position = $position;
    }

    /**
     * SeekableIterator implementation. seek internal pointer to the first element
     * @param mixed $position - seek to this position in the list
     */
    public function rewind() : void {
        if ($this->_is_associated) {
            $this->_keys = array_keys($this->_list);
            $this->_position = array_shift($this->_keys);
        } else {
            $this->_position = 0;
        }
    }

    /**
     * SeekableIterator implementation. equivalent of calling current()
     * @return mixed - the pointer to the current element
     */
    public function key() : mixed {
        return $this->_position;
    }

    /**
     * SeekableIterator implementation. equivalent of calling next()
     */
    public function next(): void {
        if ($this->_is_associated) {
            $this->_position = array_shift($this->_keys);
        } else {
            ++$this->_position;
        }
    }

    /**
     * SeekableIterator implementation. check if the current position is valid
     */
    public function valid() : bool {
        if (isset($this->_list[$this->_position])) {
            if ($this->_type != "mixed") {
                return $this->_list[$this->_position] instanceof $this->_type;
            }
            return true;
        }
        return false;
    }

    /**
     * Example: $list[1] = "data";  $list[] = "data2";
     * ArrayAccess implementation. set the value at a specific index
     * @throws 
     */
    public function offsetSet($index, $value) : void {
        // type checking
        if ($this->_type != "mixed") {
            if (! $value instanceof $this->_type) {
                $msg = get_class($this) . " only accepts objects of type \"" . $this->_type . "\", \"" . gettype($value) . "\" passed";
                throw new InvalidArgumentException($msg, 1);
            }
        }
        if (empty($index)) {
            $this->_list[] = $value;
        } else {
            $this->_is_associated = true;
            if ($index instanceof Hash_Code) {
                $this->_list[$index->hash_code()] = $value;
            } else {
                $this->_list[$index] = $value;
            }
        }
    }

    /**
     * unset($list[$value]);
     * ArrayAccess implementation. unset the value at a specific index
     */
    public function offsetUnset($index) : void {
        unset($this->_list[$index]);
    }

    /**
     * ArrayAccess implementation. check if the value at a specific index exist
     */
    public function offsetExists($index) : bool {
        return isset($this->_list[$index]);
    }

    /**
     * example $data = array_map($fn, $list->raw());
     * @return array - the internal array structure
     */
    public function &raw() : array {
        return $this->_list;
    }


    /**
     * sort the list
     * @return static - the current instance sorted
     */
    public function ksort(int $flags = SORT_REGULAR): static {
        ksort($this->_list, $flags);
        return $this;
    }

    /**
     * @return bool - true if the list is empty
     */
    public function empty() : bool {
        return empty($this->_list);
    }
   


    /**
     * helper method to be used by offsetGet() and current(), does bounds and key type checking
     * @param mixed $key 
     * @throws OutOfBoundsException - if the key is out of bounds
     */
    protected function protected_get($key) {
        if ($this->_is_associated) {
            if (isset($this->_list[$key])) {
                return $this->_list[$key];
            }
        }
        else {
            if ($key <= count($this->_list)) {
                return $this->_list[$key];
            }
        }

        throw new OutOfBoundsException("invalid key [$key]");
    }


   /**
     * filter the list using the given function 
     * @param callable $fn 
     * @return static
     */
    public function filter(callable $fn, bool $clone = false) {
        assert(fn_takes_x_args($fn, 1), last_assert_err() . " in " . get_class($this) . "->map()"); 
        assert(fn_arg_x_is_type($fn, 0, $this->_type), last_assert_err() . " in " . get_class($this) . "->map()");
        if ($clone) {
            return new static(array_filter(array_clone($this->_list), $fn));
        }
        $this->_list = array_filter($this->_list, $fn);
        return $this;
    }

    /**
     * json encoded version of the list
     * @return string json encoded version of first 5 elements
     */
    public function __toString() : string {
        return json_encode(array_slice($this->_list, 0, 5));
    }
}


/**
 * generic error type.  can be used for anything
 * @package ThreadFin
 */
class TF_Error {
    public function __construct(
        public readonly string $message,
        public readonly string $file,
        public readonly int $line,
    ) { }
}

/**
 * a <generic> list of errors
 * @package 
 */
class TF_ErrorList extends Typed_List {

    public function get_type() : string { return "\ThreadFin\Core\TF_Error"; }

    // used for index access
    public function offsetGet($index): ?TF_Error {
        return $this->_list[$index] ?? null;
    }

    // used by foreach
    public function current(): ?TF_Error {
        return $this->_list[$this->_position] ?? null;
    }

    /**
     * add a new error to the list
     * @param null|TF_Error $error 
     */
    public function add(?TF_Error $error) : void {
        if (!empty($error)) {
            $this->_list[] = $error;
        }
    }
}


/**
 * define a single cache entry
 */
class CacheItem {
    /**
     * @param string $key - the cache key to store/update. 
     *  if user-data is in the key, be sure to filter/limit it before passing here
     * @param callable $generator_fn - function to update the value
     * @param callable $init_fn - function to initialize the value
     * @param int $ttl - the number of seconds to expire the cache item
     * @return void 
     */
    public function __construct(
        public readonly string $key,
        public readonly string $generator_fn,
        public readonly string $init_fn,
        public readonly int $ttl) {
            assert($ttl > 0, "Cache TTL must be > 0.  Clear entry by returning NULL from generator_fn");
            assert($ttl < 86400*30, "Cache TTL must be < 30 days");
            assert(strlen($key) > 4, "Cache key length must be > 4 characters");
            assert(strlen($key) < 96, "Cache key length must be < 96 characters");
            assert(function_exists((string)$generator_fn), "Cache generator_fn must be a valid function");
            assert(function_exists((string)$init_fn), "Cache init_fn must be a valid function");
    }
}


/**
 * result from a REST API call
 * @package ThreadFin
 */
class ApiResult {
    public array $errors;

    public function __construct(
        public bool $success,
        public readonly string $note,
        public readonly array $result = [],
        public readonly int $status_code = 0
        ) {
            assert(!empty($note), "ApiResult note cannot be empty");
    }
}

/**
 * an HTTP cookie
 * @package ThreadFin
 */
class Cookie {
    public function __construct(
        public readonly string $name,
        public readonly string $value,
        public readonly int $expires = 0,
        public readonly string $path = "/",
        public readonly string $domain = "",
        public readonly bool $secure = true,
        public readonly bool $http_only = true
    ) {
        assert(!empty($name), "cookie name may not be null");
    }
}

/**
 * The status of an effect. Each effect generator will have it's
 * own meaning for each status. Add more statuses as needed.
 * Lets keep the number of status < 2 dozen
 */
enum EffectStatus {
    case OK;
    case FAIL;
}


/**
 * abstract away external effects
 */
class Effect {
    protected static ?string $runner = null;

    const ENCODE_RAW = 0;
    const ENCODE_HTML = 1;
    const ENCODE_BASE64 = 2;
    const ENCODE_SPECIAL = 3;

    private EffectStatus $status;
    private ?ApiResult $api_result = null;
    private string $out = '';
    private int $response = 0;
    private bool $exit = false;
    public bool $hide_output = false;

    // we don't define internal arrays until they are used (to save memory)
    private array $headers;
    private array $cookies;
    private array $cache;
    private array $file_outs;
    private array $unlinks;

    protected function __construct() {
        $this->status = EffectStatus::OK;
    }

    // $fn is the effect runner to use.  must take an Effect and return a TF_ErrorList
    // THIS IS GLOBAL TO ALL INSTANCES OF EFFECT
    public static function set_runner(callable $fn) : void {
        assert(fn_returns_type($fn, "ThreadFin\Core\TF_ErrorList"), "Effect runner " . last_assert_err());
        assert(fn_arg_x_is_type($fn, 0, "ThreadFin\Core\Effect"), "Effect runner " . last_assert_err());
        assert(self::$runner === null, "Effect Runner can only be set 1x");

        self::$runner = $fn;
    }

    /**
     * create a new empty effect
     * @return Effect 
     */
    public static function new() : Effect {
        assert(func_num_args() == 0, "incorrect call of Effect::new()");
        return new Effect();
    }

    /**
     * run this effect with the GLOBAL effect runner
     * @return TF_ErrorList 
     */
    public function run() : TF_ErrorList {
        assert(!empty(self::$runner), "Effect runner not set");
        $fn = self::$runner;
        return $fn($this);
    }


    /**
     * add request output to the effect.
     * @param string $content - the content to add
     * @param int $encoding - optional encoding to apply to content
     * @param bool $replace - if true any previous content will be replaced
     * @return Effect 
     */
    public function out(string $content, int $encoding = Effect::ENCODE_RAW, bool $replace = false) : Effect { 
        trace3("OUT", 1);
        $tmp = match ($encoding) {
            Effect::ENCODE_SPECIAL => htmlspecialchars($content),
            Effect::ENCODE_HTML => htmlentities($content),
            Effect::ENCODE_BASE64 => base64_encode($content),
            default => $tmp = $content
        };

        if ($replace) { $this->out = $tmp; }
        else { $this->out .= $tmp; }
        return $this;
    }

    /**
     * add a header to the effect
     * @return Effect 
     */
    public function header(string $name, ?string $value) : Effect {
        trace3("HEADER", 1);
        if (!isset($this->headers)) {
            $this->headers = [];
        }
        $this->headers[$name] = $value;
        return $this;
    }

    /**
     * add a cookie to the effect
     * @return Effect 
     */
    public function cookie(Cookie $cookie) : Effect {
        trace3("COOKIE", 1);
        if (!isset($this->cookies)) {
            $this->cookies = [];
        }
        $this->cookies[$cookie->name] = $cookie;
        return $this;
    }

    /**
     * set the effect's HTTP response code
     * @param int $code 
     * @return Effect 
     */
    public function response_code(int $code) : Effect {
        trace2("HTTP_CODE", 0);
        $this->response = $code;
        return $this;
    }

    /**
     * set an internal effect status code that can be read by callers
     * @param EffectStatus $status a status code set by the generating function
     * @return Effect 
     */
    public function status(EffectStatus $status) : Effect {
        $this->status = $status;
        return $this;
    }

    /**
     * if set to true, the effect will cause the script to exit
     * @param bool $should_exit 
     * @return Effect 
     */
    public function exit(bool $should_exit = true) : Effect { 
        $this->exit = $should_exit; 
        return $this;
    }

    /**
     * add a file modification to the effect. existing FileChanges
     * for the same file are overwritten
     * @param FileChange $change 
     * @return Effect 
     */
    public function file(FileChange $change) : Effect {
        $this->file_outs[$change->filename] = $change;
        return $this;
    }

    /**
     * add a cache update/create item to the effect. duplicate cache keys
     * are overwritten
    }

    /**
     * @param ApiResult $result a REST api result to return to the client
     * @return Effect 
     */
    public function api(ApiResult $result) : Effect {
        $this->api_result = $result;
        return $this;
    }

    /**
     * add file to the list to be deleted. assert error if file does not exist
     * @param string $filename 
     * @return Effect 
     */
    public function unlink(string $filename) : Effect {
        assert(file_exists($filename), "unlinking non-existent file: $filename");
        if (!isset($this->unlinks)) {
            $this->unlinks = [];
        }
        $this->unlinks[] = $filename;
        return $this;
    }

    /**
     * if set to true, the effect runner will not display any output
     * @param bool $hide 
     * @return Effect 
     */
    public function hide_output(bool $hide = true) : Effect {
        $this->hide_output = $hide;
        return $this;
    }

    /**
     * chain an effect to this one.
     * Boolean values are set to the new effect
     * array values are merged, newer values overwriting 
     * @param Effect $effect 
     * @param bool $append_out if true, the output of the chained effect will be appended to the output of this effect
     * @return Effect 
     */
    public function chain(Effect $effect, bool $append_out = true) : Effect { 
        $this->out = ($append_out) ? $this->out . $effect->read_out() : $effect->read_out();
        $this->response = $this->set_if_default('response', $effect->read_response_code(), 0);
        $this->status = $this->set_if_default('status', $effect->read_status(), EffectStatus::OK);
        $this->exit = $this->set_if_default('exit', $effect->read_exit(), false);
        $this->set_if_default('headers', $effect->read_headers(), [], true);
        $this->set_if_default('cache', $effect->read_cache(), []);
        $this->set_if_default('file_outs', $effect->read_files(), []);
        $this->set_if_default('cookies', $effect->read_cookies(), []);
        $this->set_if_default('api', $effect->read_api(), [], true);
        $this->set_if_default('unlinks', $effect->read_unlinks(), []);
        return $this;
    }

    // helper function for effect chaining
    protected function set_if_default($pname, $value, $default, $hash = false) {
        if (is_array($this->$pname) && !empty($value)) {
            if (is_array($value)) {
                $this->$pname = array_merge($this->$pname, $value);
            } else {
                $this->$pname[] = $value;
            }
        }
        else if (!empty($value) && $this->$pname === $default) { return $value; }
        return $this->$pname;
    }

    // return true if the effect will exit 
    public function read_exit() : bool { return $this->exit; }
    // return the effect content, if clear is true, the output 
    // will be returned and cleared from the effect
    public function read_out(bool $clear = false) : string {
        $t = $this->out;
        if ($clear) {
            $this->out = "";
        }
        return $t;
    }
    // return the effect headers
    public function read_headers() : array { return $this->headers??[]; }
    // return the effect cookie (only 1 cookie supported)
    public function read_cookies() : array { return $this->cookies??[]; }
    // return the effect cache updates
    public function read_cache() : array { return $this->cache??[]; }
    // return the effect response code
    public function read_response_code() : int { return $this->response; }
    // return the effect function status code
    public function read_status() : EffectStatus { return $this->status; }
    // return the effect filesystem changes
    public function read_files() : array { return $this->file_outs??[]; }
    // return the API result output
    public function read_api() : ?ApiResult { return $this->api_result; }
    // return the list of files to unlink
    public function read_unlinks() : array { return $this->unlinks??[]; }
}

class Runner {
    static $fn = null;

    // $fn is the effect runner to use.  must take an Effect and return a TF_ErrorList
    public static function set(callable $fn) {
        assert(fn_returns_type($fn, "TF_ErrorList"), "Effect runner must return a TF_ErrorList");
        assert(fn_arg_x_is_type($fn, 1, "Effect"), "Effect runner must take an Effect");

        self::$fn = $fn;
    }

    // run an effect and return the errors (if any)
    public static function run(Effect $effect) : TF_ErrorList {
        $f = self::$fn;
        assert($f !== null, "Effect runner not set, please call Runner::set()");
        return $f($effect);
    }
}

/**
 * set a cookie.  Only works for PHP > 7.0.3
 * @param string $name cookie name
 * @param string $value cookie value
 * @param int $exp expiration time in seconds, if negative, cookie will be removed
 * @return ?TF_Error 
 */
function TF_cookie(string $name, string $value, int $exp = DAY * 365, string $path = "/", string $domain = "", bool $secure = false, bool $httponly = true) : ?TF_Error {
    if (headers_sent($file, $line)) {
        return new TF_Error("unable to set cookie, headers already sent", $file, $line);
    } 
    $success = setcookie($name, $value, [
        'expires' => time() + $exp,
        'path' => $path,
        'domain' => $domain,
        'secure' => $secure,
        'httponly' => $httponly,
        'samesite' => 'strict'
    ]);
    return ($success) ? NULL : new TF_Error("unable to set cookie", __FILE__, __LINE__-8);
}

/**
 * send an http header if the headers have not been sent
 * @param string $name 
 * @param null|string $value 
 * @return null|TF_Error 
 */
function TF_header(string $name, ?string $value = null) : ?TF_Error {
     if (headers_sent($file, $line)) {
        return new TF_Error("unable to set header, headers already sent", $file, $line);
    }
    if ($value == null) {
        header($name);
    } else {
        header("$name: $value");
    }
    return NULL;
}



// TODO: monitor runner for failures and log/report them
function effect_run(Effect $effect) : TF_ErrorList {
    $errors = new TF_ErrorList();

    // http response
    if ($effect->read_response_code() > 0) {
        http_response_code($effect->read_response_code());
    }

    // send all cookies
    $cookies = $effect->read_cookies();
    array_walk($cookies, fn(Cookie $x) => 
        $errors->add(TF_cookie($x->name, $x->value, $x->expires, $x->path, $x->domain, $x->secure, $x->http_only))
    );

    // send all headers, map value,key to key,value
    $headers = $effect->read_headers();
    array_walk($headers, fn(?string $value, string $key) => 
        $errors->add(TF_header($key, $value))
    );

    // update all cache entries
    /*
    TODO: add caching...
    $cache = $effect->read_cache();
    array_walk($cache, function(CacheItem $item) use (&$errors){
        $updated = Cache::update_data(
            $item->key, $item->generator_fn, $item->init_fn, $item->ttl);
        if ($updated === NULL) {
            $errors->add(new TF_Error("unable to update cache entry: $item->key", __FILE__, __LINE__));
        }
    });
    */

    // write the files to disk
    $files = $effect->read_files();
    array_walk($files, 'ThreadFin\File\writer');

    // delete all file unlinks, track any errors
    $unlinks = $effect->read_unlinks();
    array_walk($unlinks, function ($x) use (&$errors) {
        if (!unlink($x)) {
            $errors->add(new TF_Error("unable to delete file: $x", __FILE__, __LINE__));
        }
    });

    // output api and error data if we are not set to hide it
    if (!$effect->hide_output) {
        // API output, force JSON content-type
        $api = $effect->read_api();
        if (!empty($api)) {
            TF_header("content-type", "application/json");
            echo json_encode($effect->read_api(), JSON_PRETTY_PRINT);
        }
        // standard output
        else {
            echo $effect->read_out();
        }
    }

    // log any errors
    if (!$errors->empty()) {
        debug("ERROR effect: " . json_encode($errors, JSON_PRETTY_PRINT));
    } 

    // exit if we are set to exit
    if ($effect->read_exit()) {
        debug((string)trace(RETURN_LOG, ""));
        exit();
    }

    // we didn't exit, so return any errors
    return $errors;
}

/**
 * FileSystem monad abstraction
 * TODO: move to ThreadFin\File
 * @package ThreadFin
 */
class FileData {
    public string $filename;
    public int $num_lines;
    public array $lines = [];
    public bool $debug = false;
    public string $content = "";
    public bool $exists = false;
    public bool $readable = false;
    public bool $writeable = false;

    protected static $fs_data = array();
    protected array $errors = array();

    /**
     * mask file system with mocked $content at $filename
     * @param string $filename 
     * @param string $content 
     */
    public static function mask_file(string $filename, string $content) {
        FileData::$fs_data[$filename] = $content;
    }

    /**
     * @return array of any errors that may have occurred during operations
     */
    public function get_errors() : array {
        return $this->errors;
    }

    /**
     * @param bool $enable enable or disable debug mode
     * @return FileData 
     */
    public function debug_enable(bool $enable) : FileData {
        $this->debug = $enable;
        return $this;
    }

    /**
     * preferred method of creating a FileData object
     */
    public static function new(string $filename) : FileData {
        return new FileData($filename);
    }

    public function __construct(string $filename) {
        $this->filename = $filename;
        if (isset(FileData::$fs_data[$filename])) {
            $this->exists = $this->writeable = $this->readable = true;
        } else {
            $this->exists = file_exists($filename);
            $this->writeable = is_writable($filename);
            $this->readable = is_readable($filename);
        }
    }

    /**
     * TODO: This could be improved by marking content clean/dirty and joining only dirty content
     * @return string the raw file contents
     */
    public function raw() : string {
        if (empty($this->lines)) {
            if (isset(FileData::$fs_data[$this->filename])) {
                return FileData::$fs_data[$this->filename];
            } else {
                return file_exists($this->filename) ? file_get_contents($this->filename) : "";
            }
        }
        return join("", $this->lines);
    }

    /**
     * read the data from disk and store as array of lines
     * @return FileData 
     */
    public function read($with_newline = true) : FileData {
        // mock data, and raw reads
        if (isset(FileData::$fs_data[$this->filename])) {
            $this->lines = explode("\n", FileData::$fs_data[$this->filename]);
            $this->num_lines = count($this->lines);
        }
        else {
            $disabled = false;
            if ($this->exists) {
                $size = filesize($this->filename);
                if (!is_readable($this->filename)) {
                    $s = @stat($this->filename);
                    $disabled = $s['mode']??0222;
                    @chmod($this->filename, 0664);
                }

                // split raw reads by line, and read in files line by line if no content
                $mode = ($with_newline) ? 0 : FILE_IGNORE_NEW_LINES;
                $this->lines = file($this->filename, $mode);
                // count lines...
                $this->num_lines = count($this->lines);
                debug(basename($this->filename) . " read num lines: " . $this->num_lines);

                if ($this->debug) {
                    debug("FS(r) [%s] (%d)lines", $this->filename, $this->num_lines);
                }

                // make sure lines is a valid value
                if ($size > 0 && $this->num_lines < 1) { 
                    debug("reading %s, no lines", $this->filename);
                    $this->lines = [];
                }
                if ($disabled !== false) {
                    @chmod($this->filename, $disabled);
                }
            } else {
                debug("FS(r) [%s] does not exist", $this->filename);
                $this->errors[] = "unable to read, file does not exist";
            }
        }
        return $this;
    }

    /**
     * MUTATE $lines
     * @param callable $fn apply function to every line in file.
     * @return FileData 
     */
    public function apply_ln(callable $fn) : FileData {
        if ($this->num_lines > 0) {
            $this->lines = $fn($this->lines);
            $this->num_lines = count($this->lines);
        } else {
            $this->errors[] = "unable to apply fn[".func_name($fn)."] has no lines";
        }
        return $this;
    }

    /**
     * return the number of bytes in all lines (excluding newlines...)
     * @return int 
     */
    public function count_bytes() : int {
        $bytes = 0;
        foreach ($this->lines as $line) { $bytes += strlen($line); }
        return $bytes;
    }

    /**
     * MUTATE $lines
     * @return FileData with lines joined and json decoded
     */
    public function un_json() : FileData {
        // UGLY, refactor this
        if (count($this->lines) > 0) {
            $data = join("\n", $this->lines);
            $result = false;
            if (!empty($data) && is_string($data)) {
                $result = json_decode($data, true, 128);
            }
            if (is_array($result)) {
                $this->lines = $result;
                $this->num_lines = count($this->lines);
            }
            else {
                $this->lines = array();
                $this->errors[] = "json decode failed";
            }
        }
        return $this;
    }
    /**
     * MUTATE $lines
     * @param callable $fn apply function to $this, must return a FileData objected
     * @return FileData FileData mutated FileData with data from returned $fn($this)
     */
    public function apply(callable $fn) : FileData {
        if ($this->num_lines > 0) {
            $tmp = $fn($this);
            $this->lines = $tmp->lines;
            $this->num_lines = count($tmp->lines);
            $this->filename = $tmp->filename;
            $this->exists = $tmp->exists;
        }
        return $this;
    }
    /**
     * @param callable $fn array_filter on $this->lines with $fn
     * @return FileData 
     */
    public function filter(callable $fn) : FileData {
        $this->lines = array_filter($this->lines, $fn);
        $this->num_lines = count($this->lines);
        //if (!empty($this->content)) { $this->content = join("\n", $this->lines); }
        return $this;
    }

    /**
     * @param string $text test to append to FileData
     * @return FileData 
     */
    public function append(string $text) : FileData {
        $lines = explode("\n", $text);
        if (!in_array($lines[0], $this->lines)) {
            $this->lines = array_merge($this->lines, $lines);
        }
        return $this;
    }

    /**
     * MUTATES $lines
     * @param callable $fn array_map on $this->lines with $fn
     * @return FileData 
     */
    public function map(callable $fn) : FileData {
        if ($this->num_lines > 0) {
            $this->lines = array_map($fn, $this->lines);
            $this->num_lines = count($this->lines);
        } else {
            debug("unable to map empty file [%s]", $this->filename);
        }
        return $this;
    }

    /**
     * reduces all $lines to a single value
     * @param callable $fn ($carry, $item)
     * @return FileData 
     */
    public function reduce(callable $fn, $initial = NULL) : ?string {
        return array_reduce($this->lines, $fn, $initial);
    }

    public function __invoke() : array {
        return $this->lines;
    }

    // return a file modification effect for current FileData
    public function file_mod($mode = 0, $mtime = 0) : FileChange {
        return new FileChange($this->filename, $this->raw(), $mode, $mtime);
    }

    /**
     * @return int the file modification time, or 0 if the file does not exist
     */
    public function mtime() : int {
        if ($this->exists) {
            return filemtime($this->filename);
        }
        return 0;
    }
}

/**
 * yield all matching files in a directory recursively
 * TODO: move to ThreadFin\File
 */
function file_recurse(string $dirname, string $include_regex_filter = NULL, string $exclude_regex_filter = NULL, $max_files = 20000) : \Generator {
    if (!is_dir($dirname)) { return; }

    if ($dh = \opendir($dirname)) {
        while(($file = \readdir($dh)) !== false && $max_files-- > 0) {
            if (!$file || $file === '.' || $file === '..') {
                continue;
            }
            $path = $dirname . '/' . $file;
            if (is_file($path)) {
                // check if the path matches the regex filter
                if (($include_regex_filter != NULL && preg_match($include_regex_filter, $path)) || $include_regex_filter == NULL) {
                    // skip if it matches the exclude filter
                    if ($exclude_regex_filter != NULL && preg_match($exclude_regex_filter, $path)) {
                        continue;
                    }
                    yield $path;
                }
            }
            // recurse if it is a directory, don't follow symlinks ...
            if (is_dir($path) && !is_link($path)) {
                if (!preg_match("#\/uploads\/?$#", $path)) {
                    yield from file_recurse($path, $include_regex_filter, $exclude_regex_filter, $max_files);
                }
			}
        }
        \closedir($dh);
    }
}


interface Maybe {
    public function value() : mixed;
    public static function of($x) : Maybe;

    public function map(callable $fn) : Maybe;
    public function effect(callable $fn) : Maybe;
    public function keep_if(callable $fn) : Maybe;
    public function if_not(callable $fn) : Maybe;
    public function convert(callable $fn) : mixed;

    public function empty() : bool;
    public function errors() : ?array;
    public function size() : int;
}


class MaybeStr implements Maybe {
    protected $_value = null;

    protected function __construct($value) {
        $this->_value = $value;
    }

    // create a new MaybeString from a value
    public static function of($x = null): MaybeStr {
        return new MaybeStr($x);
    }

    // return the value of the maybe
    public function value(): ?string {
        return $this->_value;
    }

    // string value as an int
    public function int() : ?int {
        if (!empty($this->_value)) {
            return intval($this->_value);
        }
        return null;
    }

    // string value as a float
    public function float() : ?float {
        if (!empty($this->_value)) {
            return floatval($this->_value);
        }
        return null;
    }

    // if the maybe is empty, then set it to this value
    public function set_if_empty(string $value): MaybeStr {
        if (empty($this->_value)) {
            $this->_value = $value;
        }
        return $this;
    }

    // applying the function to the value if the value is not empty
    public function map(callable $fn): MaybeStr {
        assert(fn_returns_type($fn, 'string'), "map " . last_assert_err());

        if (!empty($this->_value)) {
            $this->_value = $fn($this->_value);
        }
        return $this;
    }

    // call the function with the value if the value is not empty 
    // the maybe value is not changed
    public function effect(callable $fn): MaybeStr {
        assert(fn_takes_x_or_more_args($fn, 1), "effect " . last_assert_err());

        if (!empty($this->_value)) {
            $fn($this->_value);
        }
        return $this;
    }

    // if the function returns false, value will be set to null
    public function keep_if(callable $fn): MaybeStr {
        assert(fn_returns_type($fn, 'string'), "keep_if " . last_assert_err());
        assert(fn_takes_x_or_more_args($fn, 1), "keep_if " . last_assert_err());

        if (!$fn($this->_value)) {
            $this->_value = null;
        }
        return $this;
    }

    // call function $fn if the value is empty.  $fn should not require any arguments
    // if $fn returns a value, then set the maybe value to that value
    public function if_not(callable $fn): MaybeStr {
        assert(fn_returns_type($fn, 'bool'), "keep_if " . last_assert_err());
        assert(fn_takes_x_or_more_args($fn, 1), "keep_if " . last_assert_err());

        if (empty($this->_value)) {
            $result = $fn($this->_value);
            if (!empty($result)) {
                $this->_value = $result;
            }
        }

        return $this;
    }

    public function empty(): bool {
        return empty($this->_value);
    }

    // return a list of methods that failed 
    public function errors(): ?array {
        return [];
    }

    // return the length of the string
    public function size(): int {
        return (!empty($this->_value)) ? strlen($this->_value) : 0;
    }

    public function convert(callable $fn) : mixed {
        return (!empty($this->_value)) ? $fn($this->_value) : NULL;
    }

    public function __invoke(string $type = null) { return $this->value($type); }
}


class MaybeA implements Maybe {
    protected ?array $_value;

    protected function __construct($value) {
        $this->_value = $value;
    }

    // create a new MaybeString from a value
    public static function of($x): MaybeA {
        assert(is_array($x) || is_null($x), "MaybeA only accepts type ?array");
        return new MaybeA($x);
    }

    // return the value of the maybe
    public function value(): ?array {
        return $this->_value;
    }

    // applying the function to the value if the value is not empty
    public function map(callable $fn): MaybeA {
        assert(fn_returns_type($fn, 'array'), "map " . last_assert_err());
        assert(fn_takes_x_or_more_args($fn, 1), "map " . last_assert_err());

        if (!empty($this->_value)) {
            $this->_value = $fn($this->_value);
        }
        return $this;
    }

    // call the function with the value if the value is not empty 
    // the maybe value is not changed
    public function effect(callable $fn): MaybeA {
        assert(fn_takes_x_or_more_args($fn, 1), "effect " . last_assert_err());

        if (!empty($this->_value)) {
            $fn($this->_value);
        }
        return $this;
    }

    // if the function returns false, value will be set to null
    public function keep_if(callable $fn): MaybeA {
        assert(fn_returns_type($fn, 'bool'), "keep_if " . last_assert_err());
        assert(fn_takes_x_or_more_args($fn, 1), "keep_if " . last_assert_err());

        if (!$fn($this->_value)) {
            $this->_value = null;
        }
        return $this;
    }

    // call function $fn if the value is empty.  $fn should not require any arguments
    // if $fn returns a value, then set the maybe value to that value
    public function if_not(callable $fn): MaybeA {
        assert(fn_returns_type($fn, 'bool'), "keep_if " . last_assert_err());
        assert(fn_takes_x_or_more_args($fn, 1), "keep_if " . last_assert_err());

        if (empty($this->_value)) {
            $result = $fn($this->_value);
            if (!empty($result)) {
                $this->_value = $result;
            }
        }

        return $this;
    }

    public function empty(): bool {
        return empty($this->_value);
    }

    // return a list of methods that failed 
    public function errors(): ?array {
        return [];
    }

    // return the length of the string
    public function size(): int {
        return (!empty($this->_value)) ? count($this->_value) : 0;
    }

    public function convert(callable $fn) : mixed {
        return (!empty($this->_value)) ? $fn($this->_value) : NULL;
    }

    public function splat(callable $fn) : Maybe {
        if (!empty($this->_value)) {
            $this->_value = $fn(...$this->_value);
        }

        return $this;
    }
}

class MaybeO implements Maybe {
    protected object $_value;

    protected function __construct(object $value) {
        $this->_value = $value;
    }

    // create a new MaybeString from a value
    public static function of($x): MaybeO {
        return new MaybeO($x);
    }

    // return the value of the maybe
    public function value(): ?object {
        return $this->_value;
    }

    // applying the function to the value if the value is not empty
    public function map(callable $fn): MaybeO {
        assert(fn_takes_x_or_more_args($fn, 1), "map " . last_assert_err());

        if (!empty($this->_value)) {
            $this->_value = $fn($this->_value);
        }
        return $this;
    }

    // call the function with the value if the value is not empty 
    // the maybe value is not changed
    public function effect(callable $fn): MaybeO {
        assert(fn_takes_x_or_more_args($fn, 1), "effect " . last_assert_err());

        if (!empty($this->_value)) {
            $fn($this->_value);
        }
        return $this;
    }

    // if the function returns false, value will be set to null
    public function keep_if(callable $fn): MaybeO {
        assert(fn_returns_type($fn, 'bool'), "keep_if " . last_assert_err());
        assert(fn_takes_x_or_more_args($fn, 1), "keep_if " . last_assert_err());

        if (!$fn($this->_value)) {
            $this->_value = null;
        }
        return $this;
    }

    // call function $fn if the value is empty.  $fn should not require any arguments
    // if $fn returns a value, then set the maybe value to that value
    public function if_not(callable $fn): MaybeO {
        assert(fn_returns_type($fn, 'bool'), "keep_if " . last_assert_err());
        assert(fn_takes_x_or_more_args($fn, 1), "keep_if " . last_assert_err());

        if (empty($this->_value)) {
            $result = $fn($this->_value);
            if (!empty($result)) {
                $this->_value = $result;
            }
        }

        return $this;
    }

    public function convert(callable $fn) : mixed {
        return (!empty($this->_value)) ? $fn($this->_value) : NULL;
    }


    public function empty(): bool {
        return empty($this->_value);
    }

    // return a list of methods that failed 
    public function errors(): ?array {
        return [];
    }

    // return the length of the string
    public function size(): int {
        return (!empty($this->_value)) ? count($this->_value) : 0;
    }

    public function __invoke() : ?Object {
        return $this->_value;
    }
}



/**
 * functional helper for partial application
 * lock in left parameter(s)
 * $log_it = partial("file_put_contents", "/tmp/log.txt"); // function log_to($file, $content)
 * assert_eq($log_it('the log line'), 12, "partial app log to /tmp/log.txt failed");
 */
function partial(callable $fn, ...$args) : callable {
    return function(...$x) use ($fn, $args) { return $fn(...array_merge($args, $x)); };
}

/**
 * same as partial, but reverse argument order
 * lock in right parameter(s)
 * $minus3 = partial_right("minus", 3);  //function minus ($subtrahend, $minuend)
 * assert_eq($minus3(9), 6, "partial app of -3 failed");
 */
function partial_right(callable $fn, ...$args) : callable {
    return function(...$x) use ($fn, $args) { return $fn(...array_merge($x, $args)); };
}



/**
 * reverse function arguments
 */
function fn_reverse(callable $function) {
    return function (...$args) use ($function) {
        return $function(...array_reverse($args));
    };
}

/**
 * pipeline a series of callable in reverse order
 */
function pipeline(callable $a, callable $b) {
    $list = func_get_args();

    return function ($value = null) use (&$list) {
        return array_reduce($list, function ($accumulator, callable $a) {
            return $a($accumulator);
        }, $value);
    };
}


function chain_fn(callable $fn1, ?callable $fn2 = NULL) : callable {
    return function (...$x) use ($fn1, $fn2) {
        $result = $fn1(...$x);
        if ($fn2 != NULL) {
            $result = $fn2($result);
        }
        return $result;
    };
}

/**
 * compose functions in forward order
 */
function compose(callable $a, ?callable $b) {
    // if only one function, return it
    if ($b === null) {
        return $a;
    }

    return fn_reverse('\ThreadFin\Core\pipeline')(...func_get_args());
}

// return the input
function ident($in) { return $in; }




namespace ThreadFin\Util;

use Exception;
use ReflectionFunction;
use ThreadFin\Core\MaybeStr;

use const ThreadFin\Log\ACTION_CLEAN;
use const ThreadFin\Log\ACTION_RETURN;
use const ThreadFin\Log\RETURN_LOG;

use function ThreadFin\Core\partial;
use function ThreadFin\Core\partial_right;
use function ThreadFin\Log\debug;
use function ThreadFin\Log\trace;

enum Escape_Type : string {
    case HTML = 'htmlspecialchars';
    case RAW = '\ThreadFin\Core\ident';
}

// PolyFills
if (PHP_VERSION_ID < 80000) { 
    function str_contains(string $haystack, string $needle) : bool {
        return strpos($haystack, $needle) !== false;
    }
}

// escape the variable $key in array source $source with escaping from Escape_Type
function e(array $source, string $key, Escape_Type $type) : MaybeStr {
    if (isset($source[$key])) {
        return MaybeStr::of($type($source[$key]));
    }
    return MaybeStr::of(null);
}

// die($panic_message) if $fn is true
function panic_if(bool $value, string $panic_message) : void {
    if ($value) {
        die("$panic_message\n");
    }
}

// negate the return type of $fn()
function not(bool $input) : bool {
    return !$input;
}

function eg(string $get_name, Escape_Type $type = Escape_Type::HTML) { return e($_GET, $get_name, $type); }
function ep(string $get_name, Escape_Type $type = Escape_Type::HTML) { return e($_POST, $get_name, $type); }
function er(string $get_name, Escape_Type $type = Escape_Type::HTML) { return e($_REQUEST, $get_name, $type); }


/**
 * generate random string of length $len
 * @param int $len 
 * @return string 
 */
function random_str(int $len) : string {
    return substr(strtr(base64_encode(random_bytes($len)), '+/=', '___'), 0, $len);
}

// check if $haystack contains any of the strings in $needles case-sensitive
function contains(string $haystack, array $needles) : bool {
    foreach ($needles as $n) {
        if (!empty($n) && strpos($haystack, $n) !== false) {
            return true;
        }
    } return false;
}

// check if $haystack contains any of the strings in $needles case-insensitive
function icontains(string $haystack, array $needles) : bool {
    foreach ($needles as $n) {
        if (!empty($n) && stripos($haystack, $n) !== false) {
            return true;
        }
    } return false;
}

// return the function name for a callable, or source line for closure
function func_name(callable $fn) : string {
    if (is_string($fn)) {
        return trim($fn);
    }
    if (is_array($fn)) {
        return (is_object($fn[0])) ? get_class($fn[0]) : trim($fn[0]) . "::" . trim($fn[1]);
    }
    if ($fn instanceof \Closure) {
        $x = new ReflectionFunction($fn);
        return "Closure@".$x->getFileName() . ":" . $x->getStartLine();
    }
    return 'unknown';
}


// check if $haystack contains $needle case-sensitive 
function str_icontains(string $haystack, string $needle) : bool {
    return stripos($haystack, $needle) !== false;
}

// exit the stript, printing the input and the current debug log along with $msg
function dbg($x, $msg="") {
    $m=htmlspecialchars($msg);
    $z=(php_sapi_name() == "cli") ? print_r($x, true) : htmlspecialchars(print_r($x, true));
    $log = debug(null);
    $debug = (!empty($log)) ? join("\n", $log) : "";
    $debug = (debug(null)===null) ? debug(null) : [];
    echo "<pre>\n[$m]\n$z\n$debug\nTRACE: " . trace(RETURN_LOG). "\n";
    die("\nFIN");
}

// return the current time in UTC
function utc_time() : int {
    return time() + intval(date('Z'));
}

/**
 * calls $carry $fn($key, $value, $carry) for each element in $map
 * allows passing optional initial $carry, defaults to empty string
 * PURE as $fn, returns $carry
 */
function map_reduce(array $map, callable $fn, $carry = "") {
    foreach($map as $key => $value) { $carry = $fn($key, $value, $carry); }
    return $carry;
}

/**
 * take any function $fn and return a function that will accumulate a string and return the result
 * maintain a single string state variable
 * 
 * passing ACTION_RETURN to the returned function will return the accumulated result
 * passing ACTION_CLEAN to the returned function will reset the accumulator
 * @param callable $fn - should return the string to append to the accumulator
 * @return callable the accumulator function
 */
function accrue_str(callable $fn) : callable {
    return function(...$args) use ($fn) : ?string {
        static $result = "";
        if (isset($args[0])) {
            if ($args[0] === ACTION_RETURN) {
                return $result;
            } else if ($args[0] === ACTION_CLEAN) {
                $result = "";
                return $result;
            }
        }
        $result .= $fn(...$args);
        return $result;
    };
}

/**
 * take any function $fn and return a function that will accumulate a string and return the result
 * maintain a single string state variable
 * 
 * passing ACTION_RETURN to the returned function will return the accumulated result
 * passing ACTION_CLEAN to the returned function will reset the accumulator
 * @param callable $fn - should return the string to append to the accumulator
 * @return callable the accumulator function
 */
function accrue_arr(callable $fn) : callable {
    return function(...$args) use ($fn) : ?array {
        static $result = [];
        if (isset($args[0])) {
            if ($args[0] === ACTION_RETURN) {
                return $result;
            } else if ($args[0] === ACTION_CLEAN) {
                $result = [];
                return NULL;
            }
        }
        $result[] = $fn(...$args);
        return NULL;
    };
}



/**
 * Encrypt string using openSSL module
 * @param string $text the message to encrypt
 * @param string $password the password to encrypt with
 * @return string message.iv
 */
function encrypt_ssl(string $password, string $text) : string {
    assert(strlen($password) >= 12, "password must be at least 12 characters long");

    $iv = random_str(16);
    $e = openssl_encrypt($text, 'AES-128-CBC', $password, 0, $iv) . "." . $iv;
    return $e;
}

/**
 * aes-128-cbc decryption of data, return raw value
 */ 
function raw_decrypt(string $cipher, string $iv, string $password) {
    $decrypt = openssl_decrypt($cipher, "AES-128-CBC", $password, 0, $iv);
    return $decrypt;
}

/**
 * Decrypt string using openSSL module
 * @param string $password the password to decrypt with
 * @param string $cipher the message encrypted with encrypt_ssl
 * @return MaybeI with the original string data 
 */
function decrypt_ssl(string $password, string $cipher) : MaybeStr {

    assert(strlen($password) > 8, "password must be at least 8 characters long");
    $decrypt = partial_right("ThreadFin\\util\\raw_decrypt", $password);

    $a = MaybeStr::of($cipher)
        ->map(partial("explode", "."))
        ->keep_if(function($x) { return is_array($x) && count($x) === 2; })
        ->map($decrypt, true);
    return $a;
}

/**
 * @param array|object $array the array or object to clone
 * @return array|object the cloned array or object
 */
function array_clone($array) {
    return array_map(function($element) {
        return ((is_array($element))
            ? array_clone($element)
            : ((is_object($element))
                ? clone $element
                : $element
            )
        );
    }, $array);
}


// return the n'th element of an array
function take_n(array $array, int $n) : array {
    return array_slice($array, 0, $n);
}


namespace ThreadFin\Assertions;

use Exception;
use ReflectionParameter;

function last_assert_err(?string $message = null) : string {
    static $last_assertion_error = "";
    if ($message !== null) {
        $last_assertion_error = $message;
    } else {
        return $last_assertion_error;
    }
    return "";
}

// return true if $fn returns a var of $type 
function fn_returns_type(callable $fn, string $type) : bool {
    try {
        $ref = new \ReflectionFunction($fn);
    } catch (Exception $e) {
        $ref = new \ReflectionMethod($fn);
    }
    $check_type = (string)$ref->getReturnType();

    if ($check_type !== $type) {
        last_assert_err("Function returns type [$check_type], not return type $type");
        return false;
    }
    return true;
}

// return true if $fn argument $index is exactly $type
function fn_arg_x_is_type(callable $fn, int $index, string $type) : bool {
    $a = get_fn_param($fn, $index);
    if ($a === null) {
        last_assert_err("Function parameter #$index does not exist");
        return false;
    }
    $t = $a->getType();
    $check_type = (string)$a->getType();

    if ($check_type !== $type) {
        last_assert_err("Function parameter #$index must be type $type found ($check_type)");
        return false;
    }

    return true;
}

/**
 * @param callable $fn the function to check
 * @param int $index the parameter index to check (starting at 1)
 * @param string $type the type is should be
 * @return bool true if function parameter $index is of type $type
 */
function fn_arg_x_has_type(callable $fn, int $index, string $type) : bool {
    $a = get_fn_param($fn, $index);
    if ($a === null) {
        last_assert_err("Function parameter #$index does not exist");
        return false;
    }
    $check_type = (string)$a->getType();

    if (str_contains($check_type, $type)) {
        last_assert_err("Function parameter #$index must be type $type found ($check_type)");
        return false;
    }

    return true;
}

// return true if $fn has has at least $num_args arguments
function fn_takes_x_or_more_args(callable $fn, int $num_args) {
    $ref = new \ReflectionFunction($fn);
    if ($ref->getNumberOfParameters() < $num_args) {
        last_assert_err("Function does not take $num_args or more arguments");
        return false;
    }
    return true;
}

// return true if $fn has has exactly $num_args arguments
function fn_takes_x_args(callable $fn, int $num_args) {
    $ref = new \ReflectionFunction($fn);
    $n1 = $ref->getNumberOfRequiredParameters();

    if ($n1 != $num_args) {
        last_assert_err("Function does not take $num_args arguments (takes $n1 arguments)");
        return false;
    }
    return true;
}


// helper function to get a function parameter info by index
function get_fn_param(callable $fn, int $index) : ?ReflectionParameter {
    $ref = new \ReflectionFunction($fn);
    if ($ref->isClosure()) {
        $c = $ref->getStaticVariables();
        if (isset($c['fn'])) {
            $ref = new \ReflectionFunction($c['fn']);
        }
    }
    $p = $ref->getParameters();
    if (!isset($p[$index])) { 
        last_assert_err("Function does not have #$index parameter");
        return null;
    }
    return $p[$index];
}

/*
register_shutdown_function(function () {
    $err = error_get_last();
    if (!empty($err)) {
        print_r($err);
        //echo "Assertion failed: " . last_assert_err();
    }
});
*/
