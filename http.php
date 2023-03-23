<?php

use function ThreadFin\Log\debug;
use function ThreadFin\Log\trace;
use function ThreadFin\Util\contains;
use function ThreadFin\Util\map_reduce;

namespace ThreadFin\HTTP;


/**
 * take HTML text and convert to array of links [href => text]
 */
function take_links(string $input): array
{
    preg_match_all("/href=['\"]([^'\"]+)['\"].*?>([^<]+)/", $input, $matches);
    $result = [];
    for ($i = 0, $m = count($matches[1]); $i < $m; $i++) {
        $result[$matches[1][$i]] = $matches[2][$i];
    }
    // remove the .. and ../ links (directory links)
    return array_filter($result, function ($x) {
        return $x != '..' && $x != '../' && strpos($x, 'subversion') === false;
    }, ARRAY_FILTER_USE_KEY);
}


/**
 * http request via curl, return [$content, $response_headers]
 */
function http2(string $method, string $url, $data = "", array $optional_headers = NULL) : array {
    if (!isset($optional_headers['User-Agent'])) {
		$optional_headers['User-Agent'] = "ThreadFin HTTP client https://giituhb.com/bitslip6/threadfin";
    }
    // fall back to non curl...
    if (!function_exists('curl_init')) {
        $c = http($method, $url, $data, $optional_headers);
        $len = strlen($c);
        return ["content" => $c, "path" => $url, "headers" => ["http/1.1 200"], "length" => $len, "success" => ($len > 0)];
    }


    $ch = \curl_init();
    $t1 = microtime(true);
    if (!$ch) {
        $c = http($method, $url, $data, $optional_headers);
        $len = strlen($c);
        return ["content" => $c, "path" => $url, "headers" => ["http/1.1 200"], "length" => $len, "success" => ($len > 0)];
    }
    $t2 = microtime(true);

    debug("http2 %s (%dms)", $url, floor($t2 - $t1));

    $content = (is_array($data)) ? http_build_query($data) : $data;
    if ($method == "POST" || $method == "PUT") {
        \curl_setopt($ch, CURLOPT_POST, 1);
        \curl_setopt($ch, CURLOPT_POSTFIELDS, $content);
    } else {
        $prefix = contains($url, ['?']) ? "&" : "?";
        $url .= $prefix . $content;
    }

    \curl_setopt($ch, CURLOPT_URL, $url);

    if ($optional_headers != NULL) {
        $headers = map_reduce($optional_headers, function($key, $value, $carry) { $carry[] = "$key: $value"; return $carry; }, array());
        \curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    
    // Receive server response ...
    \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    \curl_setopt($ch, CURLINFO_HEADER_OUT, true);
    //curl_setopt($ch, CURLOPT_HEADER, true);

    $headers = [];
    // this function is called by curl for each header received
    \curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($ch, $header) use (&$headers) {
        $hdr = explode(':', $header, 2);
        $name = $hdr[0]??'empty';
        $value = $hdr[1]??'empty';
        $headers[strtolower(trim($name))][] = trim($value);
        return strlen($header);
    });
    
    $server_output = \curl_exec($ch);
    if (!empty($server_output)) {
        debug("curl [%s] returned: [%d] bytes", $url, strlen($server_output));
    } else {
        debug("curl [%s] failed", $url);
        return ["content" => "", "length" => 0, "success" => false];
    }

    $info = @\curl_getinfo($ch);
    \curl_close($ch);

    if (empty($info)) { $info = ["success" => false]; }
    else { $info["success"] = true; }
    $info["content"] = $server_output;//substr($server_output, $info["header_size"]);
    $info["headers"] = $headers;//substr($server_output, 0, $info["header_size"]);
    $info["length"] = strlen($server_output);

    return $info;
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
 * @return string the server response.
 */
function http(string $method, string $path, $data, ?array $optional_headers = []) : string {
    $m0 = microtime(true);
    $path1 = $path;
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

    
    if ($optional_headers && count($optional_headers) > 0) {
        $params['http']['header'] = map_reduce($optional_headers, function($key, $value, $carry) { return "$carry$key: $value\r\n"; }, "" );
    }

    $ctx = stream_context_create($params);
    $response = @file_get_contents($path, false, $ctx);
    // log failed requests, but not failed requests to wordpress source code

    $m1 = microtime(true);
    $ms = round(($m1 - $m0) * 1000, 2);
    debug("http %s (%dms)", $path1, $ms);
    if ($response === false) {
        debug("http_resp [%s] fail", $path);
        return "";
    }

    return $response;
}

/**
 * create HTTP context for HTTP request
 * PURE
 */
function http_ctx(string $method, int $timeout) : array {
    return array('http' => array(
        'method' => $method,
        'timeout' => $timeout,
        'max_redirects' => 5,
        'header' => ''
        ),
        'ssl' => array(
            'verify_peer' => true,
            'allow_self_signed' => false,
        )
    );
}
