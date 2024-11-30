<?php

namespace ThreadFin\HTTP;

use ArrayAccess;

use function ThreadFin\Log\debug;
use function ThreadFin\Util\contains;
use function ThreadFin\Util\dbg;
use function ThreadFin\Util\icontains;

use const BitFire\BITFIRE_VER;

function map_reduce(array $map, callable $fn, $carry = "") {
    foreach($map as $key => $value) { $carry = $fn($key, $value, $carry); }
    return $carry;
}

class Basic_Array implements ArrayAccess {
    public function offsetExists($offset) : bool {
        return isset($this->$offset);
    }

    #[\ReturnTypeWillChange]
    public function offsetGet($offset) {
        return $this->$offset;
    }

    public function offsetSet($offset, $value): void {
        $this->$offset = $value;
    }

    public function offsetUnset($offset): void {
        unset($this->$offset);
    }
}


/**
 * portable * HTTP functions
 */
class HttpResponse extends Basic_Array {
    /** @var string $content */
    public $content;
    /** @var string $url */
    public $url;
    /** @var array $headers */
    public $headers;
    /** @var int $len */
    public $len;
    /** @var bool $success */
    public $success;
    /** @var int $http_code */
    public $http_code;
    public $info;

    public function __construct(string $content, string $url, array $headers, int $len, bool $success)
    {
        $this->content = $content;
        $this->url = $url;
        $this->headers = $headers;
        $this->len = $len;
        $this->success = $success;
    }
}

function http_response(string $content, string $url, array $headers, int $len, bool $success) : HttpResponse {
    return new HttpResponse($content, $url, $headers, $len, $success);
}

function new_headers(array $optional_headers = [], string $url = "") : array {
       // set user-agent
    if (!isset($optional_headers['User-Agent'])) {
        if (contains($url, ["bitfire.co"])) {
		    $optional_headers['User-Agent'] = "BitFire/1.1 RASP https://bitfire.co/user_agent/xxx";
        } else {
		    $optional_headers['User-Agent'] = "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0.0.0 Safari/537.36";
		    $optional_headers['User-Agent'] = "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36";
            $optional_headers['Upgrade-Insecure-Requests'] = "1";
            $optional_headers['Sec-Fetch-User'] = "?1";
            $optional_headers['Sec-Fetch-Site'] = "none";
            $optional_headers['Sec-Fetch-Mode'] = "navigate";
            $optional_headers['Sec-Fetch-Dest'] = "document";
            $optional_headers['Sec-Ch-Ua-Platform'] = "Linux";
            $optional_headers['Sec-Ch-Ua-Mobile'] = "?0";
            $optional_headers['Sec-Ch-Ua'] = 'Chromium";v="115", "Not/A)Brand";v="99"';
            $optional_headers['Pragma'] = 'no-cache';
            $optional_headers['Cache-Control'] = 'no-cache';
            $optional_headers['Accept-Language'] = 'en-US,en;q=0.9';
            $optional_headers['Accept'] = 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7';
        }
    }

    return $optional_headers;
}

/**
 * http request via curl, return [$content, $response_headers]
 * will return ssl info if optional_headers['ssl'] is true
 * optional_headers['proxy] = 'host:user:pass'
 * optional_headers['write_fn'] = function($ch, $data) { }
 */
function http2(string $method, string $url, $data = "", array $optional_headers = []) : HttpResponse {
    $m0 = microtime(true);

    $ssl = $optional_headers['ssl'] ?? false;
    $proxy = $optional_headers['proxy'] ?? false;
    $write_fn = $optional_headers['write_fn'] ?? false;
    unset($optional_headers['ssl']);
    unset($optional_headers['proxy']);
    unset($optional_headers['write_fn']);
    $url = str_replace('/+$', '', $url);


    $optional_headers = new_headers($optional_headers, $url);
    //echo "NEW HEADERS!\n";
    //print_r($optional_headers);

    //xdebug_break();
    // fall back to non curl...
    if (!function_exists('curl_init')) {
        $c = http($method, $url, $data, $optional_headers);
        $len = strlen($c->content);
        return http_response($c->content, $url, ["http/1.1 200"], $len, ($len > 0));
    }

    // startup curl library
    $ch = \curl_init();
    // fall back to non-curl if library fails
    if (!$ch) {
        $c = http($method, $url, $data, $optional_headers);
        $len = strlen($c->content);
        return http_response($c->content, $url, ["http/1.1 200"], $len, ($len > 0));
    }

    debug("http2 %s", $url);

    // build the post content
    $content = (is_array($data)) ? http_build_query($data) : $data;
    if ($method == "POST") {
        \curl_setopt($ch, CURLOPT_POST, 1);
        \curl_setopt($ch, CURLOPT_POSTFIELDS, $content);
    } else if (!empty($content)) {
        $prefix = contains($url, ['?']) ? "&" : "?";
        $url .= $prefix . $content;
    }
    if ($method == "HEAD") {
        \curl_setopt($ch, CURLOPT_NOBODY, 1);
    }
    $x = (curl_getinfo($ch, CURLINFO_SSL_ENGINES));
     
    //$v = curl_setopt($ch, CURLOPT_TLS13_CIPHERS, "TLS_AES_128_GCM_SHA256:TLS_AES_256_GCM_SHA384:TLS_CHACHA20_POLY1305_SHA256:TLS_ECDHE_ECDSA_WITH_AES_128_GCM_SHA256:TLS_ECDHE_RSA_WITH_AES_256_GCM_SHA384:TLS_ECDHE_ECDSA_WITH_AES_256_GCM_SHA384:TLS_ECDHE_RSA_WITH_AES_256_GCM_SHA384:TLS_ECDHE_RSA_WITH_AES_256_GCM_SHA384:TLS_ECDHE_ECDSA_WITH_CHACHA20_POLY1305_SHA256:TLS_ECDHE_RSA_WITH_CHACHA20_POLY1305_SHA256:TLS_ECDHE_RSA_WITH_AES_128_CBC_SHA:TLS_ECDHE_RSA_WITH_AES_256_CBC_SHA:TLS_RSA_WITH_AES_128_GCM_SHA256:TLS_RSA_WITH_AES_256_GCM_SHA384:TLS_RSA_WITH_AES_128_CBC_SHA:TLS_RSA_WITH_AES_256_CBC_SHA");
    //$v = curl_setopt($ch, CURLOPT_TLS13_CIPHERS, "TLS_AES_256_GCM_SHA384:TLS_CHACHA20_POLY1305_SHA256:TLS_AES_128_GCM_SHA256");
    /*
    $v = curl_setopt($ch, CURLOPT_TLS13_CIPHERS, "TLS_AES_256_GCM_SHA384");
    var_dump($v);
    //$v = curl_setopt($ch, CURLOPT_PROXY_TLS13_CIPHERS, "TLS_AES_128_GCM_SHA256:TLS_AES_256_GCM_SHA384:TLS_CHACHA20_POLY1305_SHA256:TLS_ECDHE_ECDSA_WITH_AES_128_GCM_SHA256:TLS_ECDHE_RSA_WITH_AES_256_GCM_SHA384:TLS_ECDHE_ECDSA_WITH_AES_256_GCM_SHA384:TLS_ECDHE_RSA_WITH_AES_256_GCM_SHA384:TLS_ECDHE_RSA_WITH_AES_256_GCM_SHA384:TLS_ECDHE_ECDSA_WITH_CHACHA20_POLY1305_SHA256:TLS_ECDHE_RSA_WITH_CHACHA20_POLY1305_SHA256:TLS_ECDHE_RSA_WITH_AES_128_CBC_SHA:TLS_ECDHE_RSA_WITH_AES_256_CBC_SHA:TLS_RSA_WITH_AES_128_GCM_SHA256:TLS_RSA_WITH_AES_256_GCM_SHA384:TLS_RSA_WITH_AES_128_CBC_SHA:TLS_RSA_WITH_AES_256_CBC_SHA");
    $v = curl_setopt($ch, CURLOPT_PROXY_TLS13_CIPHERS, "TLS_AES_128_GCM_SHA256");
    var_dump($v);
    //$v = curl_setopt($ch, CURLOPT_SSL_CIPHER_LIST, "TLS_AES_128_GCM_SHA256:TLS_AES_256_GCM_SHA384:TLS_CHACHA20_POLY1305_SHA256:TLS_ECDHE_ECDSA_WITH_AES_128_GCM_SHA256:TLS_ECDHE_RSA_WITH_AES_256_GCM_SHA384:TLS_ECDHE_ECDSA_WITH_AES_256_GCM_SHA384:TLS_ECDHE_RSA_WITH_AES_256_GCM_SHA384:TLS_ECDHE_RSA_WITH_AES_256_GCM_SHA384:TLS_ECDHE_ECDSA_WITH_CHACHA20_POLY1305_SHA256:TLS_ECDHE_RSA_WITH_CHACHA20_POLY1305_SHA256:TLS_ECDHE_RSA_WITH_AES_128_CBC_SHA:TLS_ECDHE_RSA_WITH_AES_256_CBC_SHA:TLS_RSA_WITH_AES_128_GCM_SHA256:TLS_RSA_WITH_AES_256_GCM_SHA384:TLS_RSA_WITH_AES_128_CBC_SHA:TLS_RSA_WITH_AES_256_CBC_SHA");
    $v = curl_setopt($ch, CURLOPT_SSL_CIPHER_LIST, "TLS_AES_128_GCM_SHA256");
    var_dump($v);
    //$v = curl_setopt($ch, CURLOPT_PROXY_SSL_CIPHER_LIST, "TLS_AES_128_GCM_SHA256:TLS_AES_256_GCM_SHA384:TLS_CHACHA20_POLY1305_SHA256:TLS_ECDHE_ECDSA_WITH_AES_128_GCM_SHA256:TLS_ECDHE_RSA_WITH_AES_256_GCM_SHA384:TLS_ECDHE_ECDSA_WITH_AES_256_GCM_SHA384:TLS_ECDHE_RSA_WITH_AES_256_GCM_SHA384:TLS_ECDHE_RSA_WITH_AES_256_GCM_SHA384:TLS_ECDHE_ECDSA_WITH_CHACHA20_POLY1305_SHA256:TLS_ECDHE_RSA_WITH_CHACHA20_POLY1305_SHA256:TLS_ECDHE_RSA_WITH_AES_128_CBC_SHA:TLS_ECDHE_RSA_WITH_AES_256_CBC_SHA:TLS_RSA_WITH_AES_128_GCM_SHA256:TLS_RSA_WITH_AES_256_GCM_SHA384:TLS_RSA_WITH_AES_128_CBC_SHA:TLS_RSA_WITH_AES_256_CBC_SHA");
    $v = curl_setopt($ch, CURLOPT_PROXY_SSL_CIPHER_LIST, "TLS_AES_128_GCM_SHA256");

    echo "TLS13_CIPHERS:";
    var_dump($v);
    echo "\n";
    curl_setopt($ch, CURLOPT_SSL_EC_CURVES, "p-256:P-384");
    echo "EC_CURVES:";
    var_dump($v);
    */
    //echo "\n";
    //die();

    // echo "CURL [$url]\n";
    // set the url
    \curl_setopt($ch, CURLOPT_URL, $url);

    // map the headers
    if ($optional_headers != NULL) {
        $headers = map_reduce($optional_headers, function($key, $value, $carry) { $carry[] = "$key: $value"; return $carry; }, []);
        \curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    
    // Receive server response ...
    \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    \curl_setopt($ch, CURLINFO_HEADER_OUT, true);
    \curl_setopt($ch, CURLOPT_TIMEOUT, $optional_headers['timeout']??15);
    \curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $optional_headers['timeout']??$optional_headers['connect_timeout']??2);
    \curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    \curl_setopt($ch, CURLOPT_SSL_VERIFYSTATUS, false);
    \curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    \curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    // handle special proxy case
    if ($proxy) {
        $parts = explode(":", $proxy);
        $proxy_host = "{$parts[0]}:{$parts[1]}";
        \curl_setopt($ch, CURLOPT_PROXY, "http://$proxy_host");
        \curl_setopt($ch, CURLOPT_PROXYUSERPWD, "{$parts[3]}:{$parts[4]}");
        \curl_setopt($ch, CURLOPT_PROXY_SSL_VERIFYHOST, false);
        \curl_setopt($ch, CURLOPT_PROXY_SSL_VERIFYPEER, false);
    }
    // handle returning ssl info
    if ($ssl) {
        \curl_setopt($ch, CURLOPT_VERBOSE, true);
        \curl_setopt($ch, CURLOPT_CERTINFO, true);
    }
    // handle write output
    if ($write_fn) {
        if (is_callable($write_fn)) {
            \curl_setopt($ch, CURLOPT_WRITEFUNCTION, $write_fn);
        } else {
            echo " Error: write_fn is not callable!\n";
        }
    }


    curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);
    // store response headers
    $resp_headers = [];
    // this function is called by curl for each header received
    \curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($ch, $header) use (&$resp_headers) {
        $hdr = explode(':', $header, 2);
        $name = $hdr[0]??'empty';
        $value = $hdr[1]??'empty';
        $resp_headers[strtolower(trim($name))] = trim($value);
        return strlen($header);
    });
    
    //sleep(1);
    $server_output = \curl_exec($ch);

    //echo "[$server_output]\n";
    $m1 = microtime(true);
    $ms = round(($m1 - $m0) * 1000, 2);
    if (!empty($server_output)) {
        if ($resp_headers['content-encoding']??"" == 'gzip') {
            $server_output = gzdecode($server_output);
        }
        debug("curl [%s] [%d] bytes (%fms)", $url, strlen($server_output), $ms);
    }

    // add any set cookies to headers on next request...
    if (isset($resp_headers['set-cookie']) && !icontains($resp_headers['set-cookie'], ['max-age=-'])) {
        $cookies = explode(";", $resp_headers['set-cookie']);
        $parts = explode("=", $cookies[0]);
        if (isset($parts[1])) {
            // echo "setting cookie {$cookies[0]}\n";
            if (!isset($optional_headers['cookie'])) {
                $optional_headers['cookie'] = "";
            }
            $optional_headers['cookie'] .= " " . $parts[0] . "=" . $parts[1] . ";";
        }
    }

    // follow redirects...
    if (isset($resp_headers['location'])) {
        // echo "$url -> redirect {$resp_headers['location']}\n";
        if (intval($optional_headers['redirect']??0) > 0) {
            $optional_headers['redirect'] = intval($optional_headers['redirect']??0) - 1;
            echo "location redirect => " . intval($optional_headers['redirect']) . "({$resp_headers['location']}) ";
            $optional_headers['ssl'] = $ssl;
            $optional_headers['proxy'] = $proxy;
            return http2($method, $resp_headers['location'], $data, $optional_headers);
        }
    }
    /*
    // follow sg captcha...
    else if (contains($server_output, ['sgcaptcha'])) {
        echo "sg found: [$server_output]\n";
        if(preg_match("~/.well-known/sgcaptcha/[^\"]+~", $server_output, $matches)) {
            print_r($matches);
            print_r($optional_headers);
            sleep(2);
            $url = urldecode($matches[0]);
            echo "!! site ground redirecting to $method $url\n";
            return http2($method, $url, $data, $optional_headers);
        } else {
            echo "no sg match [$server_output]\n";
        }
    } else {
        echo "no sg captcha!\n";
        print_r($server_output);
        print_r($resp_headers);
        sleep(2);
    }
    */

    $info = @\curl_getinfo($ch);
    $info['error_no'] = curl_errno($ch);
    $info['error_str'] = curl_error($ch);
    /*
    if ($ssl) {
        $info['x-cert'] = curl_getinfo($ch, CURLINFO_CERTINFO);
        fclose($fh);
        $info['x-log'] = file_get_contents($tmp);
        @unlink($tmp);
    }
    */
    \curl_close($ch);



    $response = http_response($server_output, $url, $resp_headers, strlen($server_output), (empty($info)) ? false : true);
    // if ($proxy) { print_r($response); }
    $response->http_code = $info["http_code"];
    $response->info = $info;
    return $response;
}


function httpg(string $path, $data, array $opts = [])  { return http("GET", $path, $data, $opts); }
function httpp(string $path, $data, array $opts = [])  { return http("POST", $path, $data, $opts); }


/**
 * post data to a web page and return the result
 * refactor to use http2
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
 * @return HttpResponse the server response.
 */
function http(string $method, string $path, $data, ?array $optional_headers = []) : HttpResponse {
    $m0 = microtime(true);
    $path1 = $path;

    $optional_headers = new_headers($optional_headers, $path);

    // build the post content parameter
    $content = (is_array($data)) ? http_build_query($data) : $data;
    $params = http_ctx($method, 5);
    if ($method === "POST") {
        $params['http']['content'] = $content;
        $optional_headers['Content-Length'] = strlen($content);
    } else { $path .= "?" . $content; }
    $path = trim($path, "?&");

    if (!$optional_headers) { $optional_headers = []; }

    if (!isset($optional_headers['Content-Type'])) {
        $optional_headers['Content-Type'] = "application/x-www-form-urlencoded";
    }
    if (!isset($optional_headers['User-Agent'])) {
		$optional_headers['User-Agent'] = "BitFire/1.1 RASP https://bitfire.co/user_agent/xxx";
    }

    
    if ($optional_headers && count($optional_headers) > 0) {
        $params['http']['header'] = map_reduce($optional_headers, function($key, $value, $carry) { return "$carry$key: $value\r\n"; }, "" );
    }

    $ctx = stream_context_create($params);
    $response = @file_get_contents($path, false, $ctx);
    // log failed requests, but not failed requests to wordpress source code

    $m1 = microtime(true);
    $ms = round(($m1 - $m0) * 1000, 2);
    debug("http $path1 ({$ms}ms)");
    if ($response === false && !contains($path, ["wordpress.org"])) {
        debug("http_resp [%s] fail", $path);
        return http_response($response, $path, ["http/1.1 200"], 0, false);
    }

    return http_response($response, $path, ["http/1.1 200"], strlen($response), true);
}

/**
 * create HTTP context for HTTP request
 * PURE
 */
function http_ctx(string $method, int $timeout) : array {
    echo "http ctx verify false\n";
    return array('http' => array(
        'method' => $method,
        'timeout' => $timeout,
        'max_redirects' => 5,
        'header' => ''
        ),
        'ssl' => array(
            'verify_peer' => false,
            'allow_self_signed' => true,
        )
    );
}

function http3(string $method, string $url, $data = "", array $optional_headers = []) {

    $optional_headers = new_headers($optional_headers, $url);

    $ch = \curl_init();
    if (!$ch) {
        $c = http($method, $url, $data, $optional_headers);
        $len = strlen($c->content);
        return ["content" => $c->content, "path" => $url, "headers" => ["http/1.1 200"], "length" => $len, "success" => ($len > 0)];
    }

    $content = (is_array($data)) ? http_build_query($data) : $data;
    if ($method == "POST") {
        \curl_setopt($ch, CURLOPT_POST, 1);
        \curl_setopt($ch, CURLOPT_POSTFIELDS, $content);
    } else {
        $prefix = contains($url, ['?']) ? "&" : "?";
        $url .= $prefix . $content;
    }

    \curl_setopt($ch, CURLOPT_URL, $url);
    \curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);

    if ($optional_headers != NULL) {
        $headers = map_reduce($optional_headers, function($key, $value, $carry) { $carry[] = "$key: $value"; return $carry; }, array());
        \curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    
    // Receive server response ...
    \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    \curl_setopt($ch, CURLINFO_HEADER_OUT, false);
    \curl_setopt($ch, CURLOPT_HEADER, false);

    return $ch;
}

function http4(string $method, string $url, $data, ?array $optional_headers = []) {
$m0 = microtime(true);

$optional_headers = new_headers($optional_headers, $url);

// startup curl library
$ch = \curl_init();

debug("http3 $url");

// build the post content
$content = (is_array($data)) ? http_build_query($data) : $data;
if ($method == "POST") {
    \curl_setopt($ch, CURLOPT_POST, 1);
    \curl_setopt($ch, CURLOPT_POSTFIELDS, $content);
} else {
    $prefix = contains($url, ['?']) ? "&" : "?";
    $url .= $prefix . $content;
    if ($method == "HEAD") {
        \curl_setopt($ch, CURLOPT_NOBODY, 1);
    }
}

// set the url
\curl_setopt($ch, CURLOPT_URL, $url);
\curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

// map the headers
if ($optional_headers != NULL) {
    $headers = map_reduce($optional_headers, function($key, $value, $carry) { $carry[] = "$key: $value"; return $carry; }, []);
    \curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
}

// Receive server response ...
\curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
\curl_setopt($ch, CURLINFO_HEADER_OUT, false);
\curl_setopt($ch, CURLOPT_HEADER, false);

return $ch;
}

function http_wait($mh) : array {

    if (!empty($mh)) {
        $active = null;
        debug("http wait...");
        //execute the handles
        do {
            $mrc = curl_multi_exec($mh, $active);
        }
        while ($mrc == CURLM_CALL_MULTI_PERFORM);

        while ($active && $mrc == CURLM_OK) {
            if (curl_multi_select($mh) != -1) {
                do {
                    $mrc = curl_multi_exec($mh, $active);
                    usleep(10000);
                } while ($mrc == CURLM_CALL_MULTI_PERFORM);
            }
        }
    }
}

