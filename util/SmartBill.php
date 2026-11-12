<?php

/*
    the client of SmartBill Cloud's API V1 (https://ws.smartbill.ro/SBORO/api; spec: api.smartbill.ro), for the SmartBill step and credential.
    Basic auth: the account's email and its API token (Integrari > API). JSON both ways; the answer's `errorText` says what went wrong (empty: it went well), with HTTP 400 too.
    SmartBill's limit: 30 calls in 10 s per token (over it: blocked for 10 minutes).
	$s->call($method, $path, $query, $body)   the answer's JSON; its errors as \RuntimeException
	$s->file($path, $query)                    the bytes of a PDF
*/

namespace plugins\RegionalRO\util;

class SmartBill {
    const API = 'https://ws.smartbill.ro/SBORO/api/';

    protected $cred;
    protected $timeout;
    protected $start;

    public function __construct($cred, $timeout = 60) {
	$this->cred = $cred;
	$this->timeout = max(1, (int)$timeout);
	$this->start = microtime(true);
    }

    protected function request($method, $path, array $query, $body, $accept) {
	$o = [ 'headers' => [ 'Accept' => $accept, 'Authorization' => 'Basic ' . base64_encode(trim((string)$this->cred->email) . ':' . (string)$this->cred->token) ] ];
	if ($body !== null)
	    $o['json'] = $body;
	$r = Rest::send($method, self::API . ltrim($path, '/') . Rest::query($query), $o, $this->start, $this->timeout);
	if ($r['status'] == 401)
	    throw new \RuntimeException('SmartBill refused the email and token (HTTP 401)');
	if ($r['status'] == 429)
	    throw new \RuntimeException('SmartBill\'s limit (30 calls in 10 s) was passed: blocked for 10 minutes (HTTP 429)');
	return $r;
    }

    public function call($method, $path, array $query = [], $body = null) {
	$r = $this->request($method, $path, $query, $body, 'application/json');
	//errorText is the error (empty: none); with HTTP 400 and more, any message
	$error = is_array($r['data']) ? trim((string)($r['data']['errorText'] ?? '')) : '';
	if ($error !== '' || $r['status'] >= 400)
	    throw new \RuntimeException($error ?: (Rest::message($r['data']) ?: "SmartBill answered HTTP {$r['status']}"));
	if (!is_array($r['data']))
	    throw new \RuntimeException("SmartBill's answer is not JSON (HTTP {$r['status']})");
	return $r['data'];
    }

    public function file($path, array $query) {
	$r = $this->request('GET', $path, $query, null, 'application/octet-stream');
	if ($r['status'] >= 400 || !str_starts_with((string)$r['body'], '%PDF'))
	    throw new \RuntimeException(Rest::message($r['data']) ?: "SmartBill gave no PDF (HTTP {$r['status']})");
	return (string)$r['body'];
    }
}
