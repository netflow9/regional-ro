<?php

/*
    CLD: 2026-11-10T16:00:00+02:00: new: the client of Cargus's API (UrgentCargus, StandardUrgentOnlineAPI: https://urgentcargus.azure-api.net/api; documentation API V3), for the
    Cargus step and credential. Every request has the header Ocp-Apim-Subscription-Key (the Primary Key of the subscription in Cargus's API portal); sign-in: POST LoginUser
    { UserName, Password } -> the token (a JSON text, 24 hours; kept in the process: Rest::token), then Authorization: Bearer. JSON both ways; errors are an HTTP status with a text.
	$c->call($method, $path, $query, $json)   the answer's JSON (a 401 signs in again, once); errors as \RuntimeException
*/

namespace plugins\RegionalRO\util;

class Cargus {
    const API = 'https://urgentcargus.azure-api.net/api/';

    protected $cred;
    protected $timeout;
    protected $start;

    public function __construct($cred, $timeout = 60) {
	$this->cred = $cred;
	$this->timeout = max(1, (int)$timeout);
	$this->start = microtime(true);
    }

    protected function base() {
	$url = trim((string)$this->cred->apiUrl);
	return $url !== '' ? rtrim($url, '/') . '/' : self::API;
    }

    protected function key() {
	return 'cargus:' . sha1($this->base() . '|' . $this->cred->username . '|' . $this->cred->password . '|' . $this->cred->subscriptionKey);
    }

    protected function headers() {
	return [ 'Ocp-Apim-Subscription-Key' => (string)$this->cred->subscriptionKey, 'Accept' => 'application/json' ];
    }

    protected function token($fresh = false) {
	return Rest::token($this->key(), function() {
	    $r = Rest::send('POST', $this->base() . 'LoginUser', [ 'headers' => $this->headers(), 'json' => [ 'UserName' => trim((string)$this->cred->username),
		'Password' => (string)$this->cred->password ] ], $this->start, $this->timeout);
	    $token = is_string($r['data']) ? $r['data'] : null;
	    if ($r['status'] >= 400 || empty($token))
		throw new \RuntimeException('Cargus refused the sign-in' . ($r['status'] == 401 ? ' (HTTP 401: the subscription key?)' : (($m = Rest::message($r['data'] ?? $r['body'])) !== '' ? ": {$m}" : " (HTTP {$r['status']})")));
	    return [ $token, time() + 23 * 3600 ];
	}, $fresh);
    }

    public function call($method, $path, array $query = [], $json = null, $fresh = false) {
	$o = [ 'headers' => $this->headers() + [ 'Authorization' => 'Bearer ' . $this->token($fresh) ] ];
	if ($json !== null)
	    $o['json'] = $json;
	$r = Rest::send($method, $this->base() . ltrim($path, '/') . Rest::query($query), $o, $this->start, $this->timeout);
	if ($r['status'] == 401 && !$fresh)
	    return $this->call($method, $path, $query, $json, true);
	if ($r['status'] >= 400)
	    throw new \RuntimeException('Cargus: ' . (Rest::message($r['data'] ?? $r['body'], [ 'message', 'Message', 'error' ]) ?: "HTTP {$r['status']}"));
	return $r['data'] ?? (trim((string)$r['body']) === '' ? null : (string)$r['body']);
    }
}
