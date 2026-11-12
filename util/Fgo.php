<?php

/*
    the client of FGO's API v7 (https://api.fgo.ro/v1, test https://api-testuat.fgo.ro/v1), for the FGO step and credential. Every request is a
    JSON POST (form data is refused) with CodUnic (the company's CUI), Hash and PlatformaUrl (the address of the calling application); the hash is SHA-1, upper case, of:
	issuing an invoice (factura/emitere)    CodUnic + private key + the client's name (Client.Denumire)
	the other invoice operations            CodUnic + private key + the invoice's number (Numar, or NumarFactura for payments)
	articles, warehouses                    CodUnic + private key
    The answer: { Success, Message, ... }; Success false is a \RuntimeException with Message. FGO's limits: invoices and payments 1 request a second, articles 1 in 5 s (or 30 s).
	$f->call($path, $body)    the answer (the hash computed from $body as above)
	$f->nomenclature($type)   a list of FGO's values (GET nomenclator/<type>)
*/

namespace plugins\RegionalRO\util;

class Fgo {
    const PRODUCTION = 'https://api.fgo.ro/v1/';
    const TEST = 'https://api-testuat.fgo.ro/v1/';

    protected $cred;
    protected $timeout;
    protected $start;

    public function __construct($cred, $timeout = 60) {
	$this->cred = $cred;
	$this->timeout = max(1, (int)$timeout);
	$this->start = microtime(true);
    }

    protected function base() {
	return $this->cred->testEnv ? self::TEST : self::PRODUCTION;
    }

    public function cui() {
	return Anaf::cui($this->cred->cif) ?? preg_replace('/\D/', '', (string)$this->cred->cif);
    }

    public static function hash($cui, $key, $extra = '') {
	return strtoupper(sha1($cui . $key . $extra));
    }

    //the hash's third part for $body: the client's name (issuing), else the invoice's number, else none
    public static function hashPart(array $body) {
	if (isset($body['Client']['Denumire']))
	    return (string)$body['Client']['Denumire'];
	return (string)($body['Numar'] ?? $body['NumarFactura'] ?? '');
    }

    public function platformUrl() {
	return trim((string)$this->cred->platformUrl) ?: rtrim(\V::app()->createAbsoluteUrl('/'), '/');
    }

    public function call($path, array $body) {
	$cui = $this->cui();
	$body = [ 'CodUnic' => $cui, 'Hash' => self::hash($cui, (string)$this->cred->privateKey, self::hashPart($body)), 'PlatformaUrl' => $this->platformUrl() ]
	    + array_diff_key($body, [ 'CodUnic' => 1, 'Hash' => 1, 'PlatformaUrl' => 1 ]);
	$r = Rest::send('POST', $this->base() . ltrim($path, '/'), [ 'json' => $body ], $this->start, $this->timeout);
	return self::checked($r);
    }

    public function nomenclature($type) {
	return self::checked(Rest::send('GET', $this->base() . 'nomenclator/' . ltrim($type, '/'), [], $this->start, $this->timeout));
    }

    protected static function checked(array $r) {
	$d = $r['data'];
	if (!is_array($d))
	    throw new \RuntimeException("FGO's answer is not JSON (HTTP {$r['status']})");
	//FGO's message may carry its server's stack trace ("System.Exception: ...\n   at ..."): its first line, without the exception's type
	if (empty($d['Success']))
	    throw new \RuntimeException(preg_replace('/^System\.\w*Exception:\s*/', '', strtok(Rest::message($d, [ 'Message', 'message', 'errors' ]), "\r\n") ?: '')
		?: "FGO refused the request (HTTP {$r['status']})");
	return $d;
    }
}
