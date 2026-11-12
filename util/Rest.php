<?php

/*
    one request of the RegionalRO services' clients (SmartBill, FGO, Sameday, FAN Courier, Cargus), through util\Http: https, the public-address
    guard, the step's time left, the largest answer. Rest::send($method, $url, $o, $start, $timeout): Http's answer + `data` (the body as JSON, null when it is not); no answer
    (a network error, too large) is a \RuntimeException. Rest::query($params): a query string, lists as name[]=.. (PHP's style, as these APIs read them)
    The tokens of the services that sign in (Sameday, FAN Courier, Cargus) are kept in the process (tokens()), not saved: the daemon reuses them until they expire
*/

namespace plugins\RegionalRO\util;

class Rest {
    const MAX_ANSWER = 50 * 1024 * 1024;

    //key => [ token, expires (unix time) ]
    private static $tokens = [];

    public static function send($method, $url, array $o, $start, $timeout) {
	$r = \util\Http::request($method, $url, $o + [ 'timeout' => Anaf::left($start, $timeout), 'maxBytes' => self::MAX_ANSWER, 'https' => true, 'public' => true ]);
	if ($r['error'] !== null)
	    throw new \RuntimeException($r['tooLarge'] ? 'the answer is larger than ' . (self::MAX_ANSWER >> 20) . ' MB' : 'no answer: ' . $r['error']);
	$r['data'] = json_decode((string)$r['body'], true);
	return $r;
    }

    public static function query(array $params) {
	$params = array_filter($params, fn($v) => $v !== null && $v !== '');
	return $params ? '?' . preg_replace('/%5B\d+%5D=/', '%5B%5D=', http_build_query($params, '', '&', PHP_QUERY_RFC3986)) : '';
    }

    //a token of $key that has a minute left, else $login() => [ token, expires ]
    public static function token($key, \Closure $login, $fresh = false) {
	if (!$fresh && isset(self::$tokens[$key]) && self::$tokens[$key][1] > time() + 60)
	    return self::$tokens[$key][0];
	[ $token, $expires ] = $login();
	self::$tokens[$key] = [ (string)$token, (int)$expires ];
	return (string)$token;
    }

    public static function forget($key) {
	unset(self::$tokens[$key]);
    }

    //the first text of an answer's error fields (as each API names them)
    public static function message($data, array $keys = [ 'errorText', 'Message', 'message', 'error', 'errors', 'detail', 'title' ]) {
	if (!is_array($data))
	    return is_string($data) ? mb_substr(trim(strip_tags($data)), 0, 300) : '';
	foreach($keys as $k) {
	    $v = $data[$k] ?? null;
	    if (is_array($v))
		$v = implode('; ', array_map(fn($x) => is_array($x) ? (string)($x['message'] ?? $x['errorMessage'] ?? json_encode($x, JSON_UNESCAPED_UNICODE)) : (string)$x, array_slice(array_values($v), 0, 5)));
	    if (is_scalar($v) && trim((string)$v) !== '')
		return mb_substr(trim((string)$v), 0, 1000);
	}
	return '';
    }
}
