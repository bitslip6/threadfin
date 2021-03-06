<?php declare(strict_types=1);
namespace TF;

if (defined("BitFire_Util")) { return; }
define("BitFire_Util", true);

/**
 * debug output
 */
function dbg($x) {echo "<pre>";print_r($x);die("\nFIN"); }
function do_for_all(array $data, callable $fn) { foreach ($data as $item) { $fn($item); } }
function do_for_all_key_names(array $data, array $keynames, callable $fn) { foreach ($keynames as $item) { $fn($data[$item], $item); } }
function do_for_all_key(array $data, callable $fn) { foreach ($data as $key => $item) { $fn($key); } }
function do_for_all_key_value(array $data, callable $fn) { foreach ($data as $key => $item) { $fn($key, $item); } }
function do_for_all_key_value_recursive(array $data, callable $fn) { foreach ($data as $key => $items) { foreach ($items as $item) { $fn($key, $item); } } }
function keep_if_key(array $data, callable $fn) { $result = $data; foreach ($data as $key => $item) { if (!$fn($key)) { unset($result[$key]); } return $result; }}
function if_then_do(callable $test_fn, callable $action, $optionals = null) : callable { return function($argument) use ($test_fn, $action, $optionals) { if ($argument && $test_fn($argument, $optionals)) { $action($argument); }}; }
function is_equal_reduced($value) : callable { return function($initial, $argument) use ($value) { return ($initial || $argument === $value); }; }
function is_contain($value) : callable { return function($argument) use ($value) { return (strstr($argument, $value) !== false); }; }
function is_not_contain($value) : callable { return function($argument) use ($value) { return (strstr($argument, $value) === false); }; }
function startsWith(string $haystack, string $needle) { return (substr($haystack, 0, strlen($needle)) === $needle); } 
function endsWith(string $haystack, string $needle) { return (substr($haystack, -strlen($needle)) === $needle); } 
function say($color = '\033[39m', $prefix = "") : callable { return function($line) use ($color, $prefix) : string { return (strlen($line) > 0) ? "{$color}{$prefix}{$line}".NORML."\n" : ""; }; } 
function last_element(array $items, $default = "") { return (count($items) > 0) ? array_slice($items, -1, 1)[0] : $default; }
function first_element(array $items, $default = "") { return (count($items) > 0) ? array_slice($items, 0, 1)[0] : $default; }
function random_str(int $len) { return substr(base64_encode(openssl_random_pseudo_bytes($len)), 0, $len); }
function un_json(string $data) { return json_decode($data, true, 6); }
function en_json($data) : string { return json_encode($data); }
function in_array_ending(array $data, string $key) { foreach ($data as $item) { if (strlen($key) >= strlen($item)) { if (endsWith($key, $item)) { return true; } } } return false; }

function memoize(callable $fn, string $key, int $ttl) { return function(...$args) use ($fn, $key, $ttl) { 
    $cache = CacheStorage::get_instance();
    $result = $cache->load_data($key);
    if ($result === null) {
        $result = $fn(...$args);
        $cache->save_data($key, $result, $ttl);
    }
    return $result;
}; }

/**
 * functional helper for partial application
 * $times3 = partial("times", 3);
 * assert_eq($times3(9), 27, "partial app of *3 failed");
 */
function partial(callable $fn, ...$args) : callable {
    return function(...$x) use ($fn, $args) {
        return $fn(...array_merge($args, $x));
    };
}
/**
 * same as partial, bur reverse argument order
 */
function partial_right(callable $fn, ...$args) : callable {
    return function(...$x) use ($fn, $args) {
        return $fn(...array_merge($x, $args));
    };
}

/**
 * functional helper for chaining function output *YAY MONOIDS!*
 * $fn = pipe("fn1", "fn2", "fn3");
 * $fn($data);
 */
function pipe(...$fns) {
    return function($x) use ($fns) {
        return array_reduce($fns, function($acc, $fn) {
            return $fn($acc);
        }, $x);
    };
}

/**
 * functional helper for calling methods on an input and returning all values ORed together
 * $fn = or_pipe("test1", "test2");
 * $any_true = $fn($data);
 */
function or_pipe(callable ...$fns) {
    return function($x, bool $initial = false) use ($fns) {
        foreach ($fns as $fn) {
            $initial |= $fn($x);
        }
        return $initial;
    };
}

/**
 * functional helper for calling methods on an input and returning all values ORed together
 * $fn = and_pipe("test1", "test2");
 * $all_true = $fn($data, false);
 */
function and_pipe(callable ...$fns) {
    return function($x, bool $initial = true) use ($fns) {
        foreach ($fns as $fn) {
            $initial &= $fn($x);
        }
        return $initial;
    };
}



/**
 * hold a box of things and call functions on it
 */
class Box {
    protected $_x;
    protected function __construct($x) { $this->_x = $x; }
    public static function of($x) { return new static($x); }
    public function __invoke() { return $this->_x; }
    public function map(callable $fn) { return static::of($fn($this->_x)); }
}
class Reader {
    protected $_fn;
    protected $_names;
    protected function __construct(callable $fn) { $this->_fn = $fn; }
    public static function of(callable $fn) { 
        return new static($fn);
    }
    // binds all parameters IN ORDER at the end of the function
    // eg: bind('p1', 'p2') = call(x,x,p1,p2);
    public function bind(...$param_names) {
        $this->_names = $param_names;
        return $this;
    }
    // binds all parameters IN REVERSEORDER at the end of the function
    // eg: bind('p1', 'p2') = call(x,x,p2,p1);
    public function bind_l(...$param_names) {
        $this->_names = array_reverse($param_names);
        return $this;
    }
    // runs the function with arguments IN ORDER at the BEGINNING of the function
    // eg: bind('p1','p2')->run(a1, a2) = call(a1,a2,p1,p2);
    public function run(array $ctx, ...$args) {
        $fn = $this->_fn;
        return $fn(...array_merge($args, $this->bind_args($ctx)));
    }
    // runs the function with arguments IN ORDER at the END of the function
    // eg: bind('p1','p2')->run(a1, a2) = call(p1,p2,a1,a2);
    public function run_l(array $ctx, ...$args) {
        $fn = $this->_fn;
        return $fn(...array_merge($this->bind_args($ctx), $args));
    }
    protected function bind_args(array $ctx) : array {
        $bind_args = array();
        for($i=0,$m=count($this->_names);$i<$m;$i++) {
            $bind_args[] = $ctx[$this->_names[$i]];
        }
        return $bind_args;
    }
    // helper method to invoke ->run, eg:
    // ->bind(foo)->run(arg1) = ->bind(foo)(arg1);
    public function __invoke(array $ctx, ...$args) {
        return $this->run($ctx, ...$args);
    }
}
class Maybe {
    protected $_x;
    protected function __construct($x) { $this->_x = $x; }
    public static function of($x) { 
        if ($x instanceof Maybe) {
            $x->_x = $x;
            return $x;
        }
        return new static($x);
    }
    public function then(callable $fn) {
        if (!empty($this->_x)) {
            $x = $fn($this->_x);
            $this->_x = ($x instanceOf Maybe) ? $x->value() : $x;
        } return $this;
    }
    public function thenSpread(callable $fn) {
        if (!empty($this->_x)) {
            $x = $fn(...$this->_x);
            $this->_x = ($x instanceOf Maybe) ? $x->value() : $x;
        } return $this;
    }
    public function map(callable $fn) { 
        $this->_x = (is_array($this->_x)) ?
            (array_map($fn, $this->_x)) :
            $fn($this->_x);
        return $this;
    }
    public function filter(callable $fn) { return $this->_x = array_filter($this->x, $fn); }
    public function reduce(callable $fn, $seed) { return $this->_x = array_reduce($this->x, $fn, $seed); }
    public function if($fn) { if ($fn($this->_x) === false) { $this->_x = false; } return $this; }
    public function ifnot($fn) { if ($fn($this->_x) !== false) { $this->_x = false; } return $this; }
    public function empty() { return empty($this->_x); }
    public function value() { return $this->_x; }
    public function extract(string $key) { if (is_array($this->_x)) { return $this->_x[$key] ?? false; } return false; }
    public function truefalse() { return empty($this->_x) !== true; }
    public function __invoke() { return $this->_x; }
}
class ArrayBox {
    private $_x;
    private function __construct($x) { $this->_x = $x; }
    public static function of($x) { return static::from([$x]); }
    public static function from(array $x) { return new static($x); }
    public function __invoke() { return $this->_x; }
    public function map(callable $fn) { return static::from(array_map($fn, $this->x)); }
    public function filter(callable $fn) { return static::from(array_filter($this->x, $fn)); }
    public function reduce(callable $fn, $seed) { return static::from(array_reduce($this->x, $fn, $seed)); }
}



/**
 * define getallheaders if it does not exist (think phpfpm)
 */
if (!function_exists('getallheaders')) {
    function getallheaders() {
        $headers = array();
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) === 'HTTP_' && $name !== "HTTP_COOKIE") {
                $headers[str_replace(' ', '-', strtoupper(str_replace('_', ' ', substr($name, 5))))] = $value;
            }
        }
        return $headers;
    }
}


/**
 * load raw files
 */
function recache(array $lines) {
    $z = lookahead(trim($lines[0]), '');
    $a = array();
    $block="";
    for ($i=1,$m=count($lines);$i<$m;$i++) {
        $id = hexdec(substr($lines[$i], 0, 4));
        if (between($id, 10000, 90000)) {
            $a[$id]=trim($block);
            $block="";
        } else {
            $block .= lookbehind($lines[$i], $z);
        }
    }
    return $a;
}

/**
 * call the bitwaf api (get params)
 */
function apidata($method, $params) {

    $url = array_reduce(array_keys($params), function($c, $key) use ($params) {
        return $c . "&$key=" . $params[$key];
    }, "http://dev.bitslip6.com:9090/waf/$method?apikey=__KEY__");

    $data = @\file_get_contents($url, false, stream_context_create(array('http'=> array('timeout' => 3))));
    return ($data !== false) ? json_decode($data, true) : array("status" => 0);
}


/**
 * Encrypt string using openSSL module
 * @param string $text the message to encrypt
 * @param string $password the password to encrypt with
 * @return string message.iv
 */
function encrypt_ssl($text, $password) : string {
    assert(between(strlen($password), 20, 32), "cipher password length is out of bounds: [$password]");
    $iv = substr(base64_encode(openssl_random_pseudo_bytes(16)), 0, 16);
    return openssl_encrypt($text, 'AES-128-CBC', $password, 0, $iv) . "." . $iv;
}

// aes-128-cbc decryption of data, return raw value
function raw_decrypt(string $cipher, string $iv, string $password) {
    return openssl_decrypt($cipher, "AES-128-CBC", $password, 0, $iv);
}

/**
 * Decrypt string using openSSL module
 * @param string $cipher the message encrypted with encrypt_ssl
 * @param string $password the password to decrypt with
 * @return Maybe with the original string data 
 */
function decrypt_ssl(string $cipher, string $password) : Maybe {

    $exploder = partial("explode", ".");
    $decryptor = partial_right("BitSlip\raw_decrypt", $password);

    return Maybe::of($cipher)
        ->then($exploder)
        ->if(function($x) { return count($x) === 2; })
        ->thenSpread($decryptor);
}

/**
 * @return $data is >= $min and <= $max
 */
function between($data, $min, $max) {
    return $data >= $min && $data <= $max;
}


/**
 * call $fn for every $values
 */
function loopkey(array $values, callable $fn) {
    foreach($values as $value) {
        \call_user_func($fn, $value);
    }
}

/**
 * get the path to the system lock file
 */
function throttle_lockfile() {
    $dir = \sys_get_temp_dir(); 
    assert(\file_exists($dir), TDNE . ": [$dir]");
    return "$dir/bitwaf-error.lock";
}

/**
 * recursively perform a function over directory traversal.
 */
function recurse(string $dirname, callable $fn) :void {
    echo "recursing.. $dirname\n";
    $maxfiles = 1000;
    if ($dh = \opendir($dirname)) {
        while(($file = \readdir($dh)) !== false && $maxfiles-- > 0) {
            $path = $dirname . '/' . $file;
            if (!$file || $file === '.' || $file === '..' || is_link($file)) {
                continue;
            } if (is_dir($path)) {
                recurse($path, $fn);
            } else {
                \call_user_func($fn, $path);
            }
        }
        \closedir($dh);
    }
}

/**
 * get a list from the remote api server, cache it in shmop cache
 */
function get_remote_list(string $type, Storage $cache) {
    return $cache->load_or_cache("remote-{$type}", 86400 * 7, function($type) {
        return apidata("getlist", ["type" => $type]);
    }, array($type));
}


/**
 * calls $carry $fn($key, $value, $carry) for each element in $map
 * allows passing optional initial $carry, defaults to empty string
 */
function map_reduce(array $map = null, callable $fn, $carry = "") {
    if ($map === null) { return $carry; }
    foreach($map as $key => $value) {
        $carry = $fn($key, $value, $carry);
    }
    return $carry;
}

/**
 * glues a key and value together in url format (urlencodes $value also!)
 */
function param_glue(string $key, string $value, string $carry = "") : string {
    $carry = ($carry === "") ? "" : "$carry&";
    return "$carry$key=".urlencode($value);
}

// return true if an string is an ipv6 address
function is_ipv6(string $addr) : bool {
    return substr_count($addr, ':') === 5;
}

// reduce a string to a value by iterating over each character
function str_reduce(string $string, callable $fn, string $prefix = "", string $suffix = "") {
    for ($i=0,$m=strlen($string); $i<$m; $i++) {
        $prefix .= $fn($string[$i]);
    }
    return $prefix . $suffix;
}

/**
 * reverse ip lookup, takes ipv4 and ipv6 addresses
 */
function reverse_ip_lookup(string $ip) : Maybe {
    $lookup_addr = ""; 
    if (is_ipv6($ip)) {
        // remove : and reverse the address
        $ip = strrev(str_replace(":", "", $ip));
        // insert a "." after each reversed char and suffix with ip6.arpa
        $lookup_addr = str_reduce($ip, function($chr) { return $chr . "."; }, "", "ip6.arpa");
    } else {
        $parts = explode('.', $ip);
        assert((count($parts) === 4), "invalid ipv4 address [$ip]");
        $lookup_addr = "{$parts[3]}.{$parts[2]}.{$parts[1]}.{$parts[0]}.in-addr.arpa";
    }

    return fast_ip_lookup($lookup_addr, 'PTR');
}

/**
 * queries quad 1 for dns data, no SSL
 * @returns array("name", "data")
 */
function ip_lookup(string $ip, string $type = "A") : Maybe {
    $dns = null;
    assert(in_array($type, array("A", "AAAA", "CNAME", "MX", "NS", "PTR", "SRV", "TXT", "SOA")), "invalid dns query type [$type]");
    debug("doing dns request for [$ip] [$type]");

    try {
        $raw = bit_http_request("GET", "http://1.1.1.1/dns-query?name=$ip&type=$type&ct=application/dns-json", '', 3);
        if ($raw != false) {
            $formatted = json_decode($raw, true);
            if (isset($formatted['Authority'])) {
                $dns = end($formatted['Authority'])['data'] ?? '';
            } else if (isset($formatted['Answer'])) {
                $dns = end($formatted['Answer'])['data'] ?? '';
            }
        }
    } catch (\Exception $e) {
        // silently swallow http errors.
    }

    //assert($dns !== null, "unable to query quad 1, $ip, $type");
    return Maybe::of($dns);
}

// memoized version of ip_lookup
function fast_ip_lookup(string $ip, string $type = "A") : Maybe {
    $cache = CacheStorage::get_instance();
    $lookup = memoize('BitSlip\ip_lookup', "_bf_dns_{$type}_{$ip}", 3600);
    return $lookup($ip, $type);
}




/**
 * post data to a web page and return the result
 * @param string $method the HTTP verb
 * @param string $url the url to post to
 * @param array $data the data to post, key value pairs in the content head
 *   parameter of the HTTP request
 * @param string $optional_headers optional stuff to stick in the header, not
 *   required
 * @param integer $timeout the HTTP read timeout in seconds, default is 5 seconds
 * @throws \RuntimeException if a connection could not be established OR if data
 *  could not be read.
 * @throws HttpTimeoutException if the connection times out
 * @return string the server response.
 */
function bit_http_request(string $method, string $url, $data = '', int $timeout = 5, array $optional_headers = null) {
    $parsed = parse_url($url);
    // inject an ? into the query
    $parsed['query'] = (isset($parsed['query'])) ? "?" . $parsed['query'] : "";

    // build the post content paramater
    $glue = "BitSlip\param_glue";
    $content = (is_array($data)) ? map_reduce($data, $glue) : $data;
    
    $optional_headers['Content-Length'] = strlen($content);
    if (!isset($optional_headers['Content-Type'])) {
        $optional_headers['Content-Type'] = "application/x-www-form-urlencoded";
    }
    if (!isset($optional_headers['User-Agent'])) {
        $optional_headers['User-Agent'] = "BitFire WAF https://bitslip6.com/bitfire";
    }

    $params = array('http' => array(
        'method' => $method,
        'content' => $content,
        'timeout' => $timeout,
        'max_redirects' => 4,
        'header' => ''
    ),
        'ssl' => array(
            'verify_peer' => false,
            'allow_self_signed' => true,
    ) );
            //'ciphers' => 'HIGH:!SSLv2:!SSLv3',

    $params['http']['header'] = map_reduce($optional_headers, function($key, $value, $carry) { return "$carry$key: $value\r\n"; }, "" );
	//$foo = (print_r($params, true));
	//file_put_contents("/tmp/debug.log", $foo);
    $ctx = stream_context_create($params);
    $foo = @file_get_contents($url, false, $ctx);
	if ($foo === false) {
		system("curl -X$method --header 'content-Type: '{$optional_headers['Content-Type']}' $url -d '".escapeshellarg($content)."'");
	}

	return $foo;
}


class txt {
    // crappy state variables...
    protected static $_data = array();
    protected static $_section = "";
    protected static $_lang = "";


    // required to be set at least 1x
    public static function set_section(string $section) {
        if (txt::$_lang === "") {
            txt::$_lang = txt::lang_from_http($_SERVER['HTTP_ACCEPT_LANG'] ?? "*");
        }
        txt::$_section = $section;
    }

    // process accept lang into a path
    protected static function lang_from_http(string $accept) {

        $default_lang = "en";
        // split language on , iterate over each, code is last match wins, we reverse because higher priority are first
        return array_reduce(array_reverse(explode(",", $accept)), function($current, $lang) use ($default_lang) {
            // accept languages look like fr-CH, fr;q=0.9, en;q=0.8, de;q=0.7, *;q=0.5
            $lang = preg_split("/[-;]/", $lang);
            // if language accepts anything, use default
            $lang = $lang == "*" ? $default_lang : $lang;

            return (is_dir(WAF_DIR . "lang" . DS . $lang[0])) ? $lang[0] : $current;
        }, $default_lang);

    }

    protected static function section_loaded(string $section) {
        return isset(txt::$_data[$section]);
    }

    protected static function find_pot_file(string $section) {
         $file = WAF_DIR . "lang" . DS . txt::$_lang . DS . $section . ".po";
         assert(file_exists($file), "no language PO file for [".txt::$_lang."] [$section]");
         return $file;
    }

    protected static function load_lines(string $section) {
        return file(txt::find_pot_file($section));
    }

    protected static function msg_type(string $type) {
        switch($type) {
            case "msgid":
                return "msgid";
            case "msgid_plural":
                return "msgid_plural";
            case "msgstr":
                return "msgstr";
            case "msgstr[0]":
                return "msgstr";
            case "msgstr[1]":
                return "msgstr_plural";
            default:
                return "comment";
        }
    }

    /**
     * load a pot file section if not already loaded
     */
    protected static function load_section(string $section) {
        // only do this 1x
        if (isset(txt::$_data[$section])) { return; }

        txt::$_data[$section] = array();
        $id = "";
        do_for_all(txt::load_lines($section), function ($line) use ($section, &$id) {
            $parts = explode(" ", $line, 2);
            if (count($parts) !== 2) { return; }

            $type = txt::msg_type($parts[0]);
            $msg_value = txt::msg_value($parts[1]);
            if ($type === "msgid" || $type === "msgid_plural") {
               $id = trim($msg_value); 
            } else if ($type === "msgstr") {
                txt::$_data[$section][$id] = trim($msg_value);
            } else if ($type === "msgstr_plural") {
                txt::$_data[$section]["{$id}_plural"] = trim($msg_value);
            }
            
        }); 
    }

    protected static function msg_value(string $value) : string {
        return ($value[0] === '"') ?
            htmlentities(str_replace('\\"', '"', substr($value, 1, -2))) :
            $value;
    }

    /**
     * get translated singular text from POT file named $section with $msgid
     */
    public static function _s(string $msgid, string $mods = "") {
        assert(txt::$_section != "", "must set a text section first");
        txt::load_section(txt::$_section);
        $r = txt::mod(txt::$_data[txt::$_section][$msgid] ?? "ID:$msgid", $mods);
        return $r;
    }

    /**
     * get translated plural text from POT file named $section with $msgid
     */
    public static function _p(string $msgid, string $mods = "") {
        assert(txt::$_section != "", "must set a text section first");
        txt::load_section(txt::$_section);
        $r = txt::mod(txt::$_data[txt::$_section]["{$msgid}_plural"] ?? $msgid, $mods);
        return $r;
    }

    /**
     * | separated list of modifiers to apply
     **/  
    public static function mod(string $input, string $mods) {
        if ($mods === "") { return $input; }
        return array_reduce(explode("|", $mods), function($carry, $mod) use($input) {
            switch($mod) {
                case "ucf":
                    return ucfirst($carry);
                case "upper":
                    return strtoupper($carry);
                case "lower":
                    return strtolower($carry);
                case "ucw":
                case "cap":
                    return ucwords($carry);
                default:
                    return $carry;
            }
        }, $input);
    }
}

// create the JS to send an xml http request
function xml_request_to_url(string $url, array $data, string $callback = 'console.log') {
    return 'c=new XMLHttpRequest();c.open("POST","'.$url.'",true);c.setRequestHeader("Content-type","application/x-www-form-urlencoded");'.
    'c.onreadystatechange=function(){if(c.readyState==4&&c.status==200){'.$callback.'(c.responseText);}};c.send("'. 
    BitFire::get_instance()->param_to_str($data).'");';
}

// test if the web user can write to a file (checks ownership and permission 6xx or 7xx)
function really_writeable(string $filename) : bool {
    $st = stat($filename);
    $mode = intval(substr(decoct($st['mode']), -3, 1));
    return ($st['uid'] === intval(config_value('web_uid'))) &&
        (($mode === 6) || ($mode === 7));
}
