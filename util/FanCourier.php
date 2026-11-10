<?php

/*
    CLD: 2026-11-10T16:00:00+02:00: new: the client of FAN Courier's API 2.0 (https://api.fancourier.ro; the selfAWB user), for the FAN Courier step and credential. Sign-in: POST
    /login?username=&password= -> { status, data: { token, expiresAt } } (24 hours; kept in the process: Rest::token), then Authorization: Bearer. Most calls name the client (clientId:
    the sender's branch). The answers: { status: success | error, data, message }; the lists' parameters as name[]= (awb[], awbs[]).
	$f->call($method, $path, $query, $json)   the answer's JSON (a 401 signs in again, once); errors as \RuntimeException
	$f->file($path, $query)                    the bytes of a PDF
*/

namespace plugins\RegionalRO\util;

class FanCourier {
    const API = 'https://api.fancourier.ro/';

    protected $cred;
    protected $timeout;
    protected $start;

    public function __construct($cred, $timeout = 60) {
	$this->cred = $cred;
	$this->timeout = max(1, (int)$timeout);
	$this->start = microtime(true);
    }

    public function clientId() {
	return trim((string)$this->cred->clientId);
    }

    protected function key() {
	return 'fan:' . sha1($this->cred->username . '|' . $this->cred->password);
    }

    protected function token($fresh = false) {
	return Rest::token($this->key(), function() {
	    $r = Rest::send('POST', self::API . 'login' . Rest::query([ 'username' => trim((string)$this->cred->username), 'password' => (string)$this->cred->password ]),
		[ 'headers' => [ 'Accept' => 'application/json' ] ], $this->start, $this->timeout);
	    $token = $r['data']['data']['token'] ?? null;
	    if ($r['status'] >= 400 || empty($token))
		throw new \RuntimeException('FAN Courier refused the sign-in' . (($m = Rest::message($r['data'])) !== '' ? ": {$m}" : " (HTTP {$r['status']})"));
	    $exp = strtotime((string)($r['data']['data']['expiresAt'] ?? '')) ?: time() + 23 * 3600;
	    return [ $token, $exp ];
	}, $fresh);
    }

    protected function request($method, $path, array $query, $json, $fresh = false) {
	$o = [ 'headers' => [ 'Authorization' => 'Bearer ' . $this->token($fresh), 'Accept' => 'application/json' ] ];
	if ($json !== null)
	    $o['json'] = $json;
	$r = Rest::send($method, self::API . ltrim($path, '/') . Rest::query($query), $o, $this->start, $this->timeout);
	if ($r['status'] == 401 && !$fresh)
	    return $this->request($method, $path, $query, $json, true);
	return $r;
    }

    public function call($method, $path, array $query = [], $json = null) {
	$r = $this->request($method, $path, $query, $json);
	$d = $r['data'];
	if ($r['status'] >= 400 || (is_array($d) && ($d['status'] ?? '') === 'error'))
	    throw new \RuntimeException('FAN Courier: ' . (self::errors($d) ?: "HTTP {$r['status']}"));
	if (!is_array($d))
	    throw new \RuntimeException("FAN Courier's answer is not JSON (HTTP {$r['status']})");
	return $d;
    }

    public function file($path, array $query) {
	$r = $this->request('GET', $path, $query, null);
	if ($r['status'] >= 400 || !str_starts_with((string)$r['body'], '%PDF'))
	    throw new \RuntimeException('FAN Courier gave no PDF: ' . (self::errors($r['data']) ?: "HTTP {$r['status']}"));
	return (string)$r['body'];
    }

    //{ message, errors: { field: [ .. ] } } or [ .. ] -> one text
    public static function errors($d) {
	if (!is_array($d))
	    return Rest::message($d);
	$list = [];
	foreach((array)($d['errors'] ?? []) as $k => $e)
	    $list[] = (is_string($k) ? "{$k}: " : '') . (is_array($e) ? implode(', ', array_map(fn($x) => is_scalar($x) ? (string)$x : json_encode($x, JSON_UNESCAPED_UNICODE), $e)) : (string)$e);
	return mb_substr(implode('; ', array_filter(array_merge([ trim((string)($d['message'] ?? '')) ], array_slice($list, 0, 8)), 'strlen')), 0, 1000);
    }
}
