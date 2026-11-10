<?php

/*
    CLD: 2026-11-10T16:00:00+02:00: new: the client of Sameday's API (https://api.sameday.ro, demo https://sameday-api.demo.zitec.com; as Sameday's own PHP SDK calls it), for the Sameday
    step and credential. Sign-in: POST /api/authenticate with the headers X-AUTH-USERNAME, X-AUTH-PASSWORD (remember_me): { token, expire_at }; then the header X-AUTH-TOKEN (the token
    kept in the process: Rest::token). The bodies are form fields (awbRecipient[name], parcels[0][weight], ...); the answers JSON, the lists paged ({ data, total, pages, currentPage }).
	$s->call($method, $path, $query, $form)   the answer's JSON (a 401 signs in again, once); errors as \RuntimeException
	$s->file($path)                            the bytes of a PDF
*/

namespace plugins\RegionalRO\util;

class Sameday {
    const PRODUCTION = 'https://api.sameday.ro/';
    const DEMO = 'https://sameday-api.demo.zitec.com/';

    protected $cred;
    protected $timeout;
    protected $start;

    public function __construct($cred, $timeout = 60) {
	$this->cred = $cred;
	$this->timeout = max(1, (int)$timeout);
	$this->start = microtime(true);
    }

    protected function base() {
	return $this->cred->demo ? self::DEMO : self::PRODUCTION;
    }

    protected function key() {
	return 'sameday:' . sha1($this->base() . '|' . $this->cred->username . '|' . $this->cred->password);
    }

    protected function token($fresh = false) {
	return Rest::token($this->key(), function() {
	    $r = Rest::send('POST', $this->base() . 'api/authenticate', [ 'form' => [ 'remember_me' => 'true' ],
		'headers' => [ 'X-AUTH-USERNAME' => trim((string)$this->cred->username), 'X-AUTH-PASSWORD' => (string)$this->cred->password ] ], $this->start, $this->timeout);
	    if ($r['status'] >= 400 || empty($r['data']['token']))
		throw new \RuntimeException('Sameday refused the sign-in' . (($m = Rest::message($r['data'])) !== '' ? ": {$m}" : " (HTTP {$r['status']})"));
	    $exp = strtotime((string)($r['data']['expire_at'] ?? '')) ?: time() + 3600;
	    return [ $r['data']['token'], $exp ];
	}, $fresh);
    }

    protected function request($method, $path, array $query, $form, $fresh = false) {
	$o = [ 'headers' => [ 'X-AUTH-TOKEN' => $this->token($fresh), 'Accept' => 'application/json' ] ];
	if ($form !== null)
	    $o['form'] = $form;
	$r = Rest::send($method, $this->base() . ltrim($path, '/') . Rest::query($query), $o, $this->start, $this->timeout);
	if ($r['status'] == 401 && !$fresh)
	    return $this->request($method, $path, $query, $form, true);
	return $r;
    }

    public function call($method, $path, array $query = [], $form = null) {
	$r = $this->request($method, $path, $query, $form);
	if ($r['status'] >= 400)
	    throw new \RuntimeException('Sameday: ' . (self::errors($r['data']) ?: "HTTP {$r['status']}"));
	return is_array($r['data']) ? $r['data'] : [ 'body' => (string)$r['body'] ];
    }

    public function file($path) {
	$r = $this->request('GET', $path, [], null);
	if ($r['status'] >= 400 || !str_starts_with((string)$r['body'], '%PDF'))
	    throw new \RuntimeException('Sameday gave no PDF: ' . (self::errors($r['data']) ?: "HTTP {$r['status']}"));
	return (string)$r['body'];
    }

    //Sameday's errors: { message, errors: { children: { field: { errors: [..] } } } } (Symfony forms) -> "field: ...; ..."
    public static function errors($d) {
	if (!is_array($d))
	    return Rest::message($d);
	$list = [];
	$walk = function($node, $path) use (&$walk, &$list) {
	    foreach((array)($node['errors'] ?? []) as $e)
		if (is_string($e))
		    $list[] = ($path !== '' ? "{$path}: " : '') . $e;
	    foreach((array)($node['children'] ?? []) as $k => $child)
		if (is_array($child))
		    $walk($child, $path !== '' ? "{$path}.{$k}" : (string)$k);
	};
	if (isset($d['errors']) && is_array($d['errors']))
	    $walk($d['errors'], '');
	$m = trim((string)($d['message'] ?? ''));
	return mb_substr(implode('; ', array_filter(array_merge([ $m ], array_slice($list, 0, 8)), 'strlen')), 0, 1000);
    }
}
