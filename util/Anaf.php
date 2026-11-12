<?php

/*
    the client of ANAF's services (Agenția Națională de Administrare Fiscală), for the RegionalRO steps and the ANAF credential.
    Public, no credential (webservicesp.anaf.ro):
	lookup($cuis, $date)        the companies of CUI / CIF codes on a date (PlatitorTvaRest v9): at most 100 codes a request, 1 request a second (ANAF's limits; longer lists
	                            are sent 100 at a time, a second apart): [ found (ANAF's records), notFound (the codes) ]
	publicEfactura($path, $xml) e-Factura's validation and PDF of an invoice's XML (prod/FCTEL/rest/validare/<FACT1 | FCN>, transformare/<FACT1 | FCN>[/DA])
    Through an ANAF credential (OAuth: CredentialRoAnaf; api.anaf.ro, prod or test):
	$a = new Anaf($cred, $timeout);
	$a->call($service, $method, $path, $query, $body)   one request of e-Factura (FCTEL/rest/...) or e-Transport (ETRANSPORT/ws/v1/...): Http's answer, its errors as \RuntimeException
    Anaf::answer($body)            ANAF's answer as an array: JSON as it is; XML: the root's attributes + `Errors`: the errorMessage of each <Errors>
    Anaf::cui($v)                  a CUI / CIF code, its control digit checked: the digits (no RO), or null
    Anaf::company($found)          one record of lookup() in short names (cui, name, vatPayer, eFactura, headOffice, ...) + `anaf`: the record as ANAF gives it
    Anaf::stamp($v)                ANAF's YYYYMMDDHHmm[ss] as YYYY-MM-DD HH:mm[:ss] (other texts as they are)
*/

namespace plugins\RegionalRO\util;

use util\Http;

class Anaf {
    const LOOKUP = 'https://webservicesp.anaf.ro/api/PlatitorTvaRest/v9/tva';
    const PUBLIC_EFACTURA = 'https://webservicesp.anaf.ro/prod/FCTEL/rest/';
    const API = 'https://api.anaf.ro/';
    const SERVICES = [ 'efactura' => 'FCTEL/rest/', 'etransport' => 'ETRANSPORT/ws/v1/' ];
    const AUTHORIZE = 'https://logincert.anaf.ro/anaf-oauth2/v1/authorize';
    const TOKEN = 'https://logincert.anaf.ro/anaf-oauth2/v1/token';
    //ANAF's limits of the company lookup: codes a request, seconds between requests
    const LOOKUP_BATCH = 100;
    const LOOKUP_PAUSE = 1.1;
    const MAX_CODES = 500;
    const MAX_ANSWER = 50 * 1024 * 1024;
    //the weights of a CUI's control digit
    const CUI_KEY = '753217532';

    protected $cred;
    protected $timeout;
    protected $start;

    public function __construct($cred, $timeout = 60) {
	$this->cred = $cred;
	$this->timeout = max(1, (int)$timeout);
	$this->start = microtime(true);
    }

    //the seconds left of the step's timeout
    public static function left($start, $timeout) {
	$left = $timeout - (int)(microtime(true) - $start);
	if ($left < 1)
	    throw new \RuntimeException("the step's timeout ({$timeout} s) passed");
	return $left;
    }

    // ------------------------------------------------------------------------------------
    //the API, through the credential

    //$service: efactura | etransport; $body: the XML sent (text/plain, as ANAF asks) or null; the answer of Http, its status checked
    public function call($service, $method, $path, array $query = [], $body = null) {
	try {
	    $token = $this->cred->accessToken();
	} catch (\Exception $e) {
	    throw new \RuntimeException('the ANAF credential: ' . $e->getMessage());
	}
	$url = self::API . ($this->cred->testEnv ? 'test/' : 'prod/') . self::SERVICES[$service] . ltrim($path, '/');
	$query = array_filter($query, fn($v) => $v !== null && $v !== '');
	if ($query)
	    $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
	$o = [ 'headers' => [ 'Authorization' => 'Bearer ' . $token ], 'timeout' => self::left($this->start, $this->timeout), 'maxBytes' => self::MAX_ANSWER, 'https' => true, 'public' => true ];
	if ($body !== null)
	    $o += [ 'body' => (string)$body, 'headers' => $o['headers'] + [ 'Content-Type' => 'text/plain' ] ];
	$r = Http::request($method, $url, $o);
	if ($r['error'] !== null)
	    throw new \RuntimeException($r['tooLarge'] ? 'the answer is larger than ' . (self::MAX_ANSWER >> 20) . ' MB' : 'no answer from ANAF: ' . $r['error']);
	if ($r['status'] == 401)
	    throw new \RuntimeException('ANAF refused the access token (HTTP 401): use Connect on the credential again');
	if ($r['status'] == 403)
	    throw new \RuntimeException('ANAF refused the request (HTTP 403): the certificate the credential was connected with has no right on this CIF in SPV');
	if ($r['status'] >= 400)
	    throw new \RuntimeException("ANAF answered HTTP {$r['status']}" . (($m = self::message(self::answer($r['body']))) !== '' ? ": {$m}" : ''));
	return $r;
    }

    // ------------------------------------------------------------------------------------
    //public services

    //[ found, notFound ] of the codes (Anaf::cui's), on $date (YYYY-MM-DD)
    public static function lookup(array $cuis, $date, $start, $timeout) {
	$found = [];
	$notFound = [];
	foreach(array_chunk(array_values($cuis), self::LOOKUP_BATCH) as $i => $chunk) {
	    if ($i > 0)
		usleep((int)(self::LOOKUP_PAUSE * 1000000));
	    $r = Http::request('POST', self::LOOKUP, [ 'json' => array_map(fn($c) => [ 'cui' => (int)$c, 'data' => $date ], $chunk),
		'timeout' => self::left($start, $timeout), 'maxBytes' => self::MAX_ANSWER, 'https' => true, 'public' => true ]);
	    if ($r['error'] !== null)
		throw new \RuntimeException('no answer from ANAF: ' . $r['error']);
	    $data = json_decode($r['body'], true);
	    if ($r['status'] >= 400 || !is_array($data) || !isset($data['found']))
		throw new \RuntimeException("ANAF answered HTTP {$r['status']}" . (is_array($data) && ($m = self::message($data)) !== '' ? ": {$m}" : ''));
	    array_push($found, ...array_values((array)$data['found']));
	    array_push($notFound, ...array_map('strval', array_values((array)($data['notFound'] ?? []))));
	}
	return [ $found, $notFound ];
    }

    //POST $xml to e-Factura's public $path (validare/..., transformare/...): Http's answer
    public static function publicEfactura($path, $xml, $start, $timeout) {
	$r = Http::request('POST', self::PUBLIC_EFACTURA . $path, [ 'body' => (string)$xml, 'headers' => [ 'Content-Type' => 'text/plain' ],
	    'timeout' => self::left($start, $timeout), 'maxBytes' => self::MAX_ANSWER, 'https' => true, 'public' => true ]);
	if ($r['error'] !== null)
	    throw new \RuntimeException('no answer from ANAF: ' . $r['error']);
	if ($r['status'] >= 400)
	    throw new \RuntimeException("ANAF answered HTTP {$r['status']}" . (($m = self::message(self::answer($r['body']))) !== '' ? ": {$m}" : ''));
	return $r;
    }

    // ------------------------------------------------------------------------------------
    //values

    public static function cui($v) {
	$v = strtoupper(preg_replace('/\s+/', '', is_scalar($v) ? (string)$v : ''));
	if (str_starts_with($v, 'RO'))
	    $v = substr($v, 2);
	if (!preg_match('/^[1-9]\d{1,9}$/', $v))
	    return null;
	$body = str_pad(substr($v, 0, -1), 9, '0', STR_PAD_LEFT);
	$sum = 0;
	for ($i = 0; $i < 9; $i++)
	    $sum += (int)$body[$i] * (int)self::CUI_KEY[$i];
	$check = ($sum * 10) % 11;
	return ($check == 10 ? 0 : $check) === (int)substr($v, -1) ? $v : null;
    }

    public static function answer($body) {
	$body = trim((string)$body);
	if ($body === '')
	    return [];
	if ($body[0] === '{' || $body[0] === '[') {
	    $data = json_decode($body, true);
	    return is_array($data) ? $data : [];
	}
	if ($body[0] !== '<' || preg_match('~<!(DOCTYPE|ENTITY)~i', $body))
	    return [ 'message' => mb_substr(strip_tags($body), 0, 300) ];
	$prev = libxml_use_internal_errors(true);
	$root = simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
	libxml_clear_errors();
	libxml_use_internal_errors($prev);
	if ($root === false)
	    return [];
	$out = [];
	foreach($root->attributes() as $k => $v)
	    $out[(string)$k] = (string)$v;
	//the children are in the root's namespace (mfp:anaf:dgti:...): found by name in any namespace
	$errors = [];
	foreach($root->xpath('//*[local-name()="Errors"]') ?: [] as $e)
	    $errors[] = trim((string)$e['errorMessage']);
	if ($errors = array_values(array_filter($errors, 'strlen')))
	    $out['Errors'] = $errors;
	return $out;
    }

    //ANAF's error message of an answer (answer()'s), '' when it has none
    public static function message(array $a) {
	$list = [];
	foreach((array)($a['Errors'] ?? []) as $e)
	    $list[] = is_array($e) ? (string)($e['errorMessage'] ?? $e['message'] ?? '') : (string)$e;
	foreach([ 'eroare', 'message', 'detail', 'error_description', 'error', 'title' ] as $k)
	    if (isset($a[$k]) && is_scalar($a[$k]) && trim((string)$a[$k]) !== '')
		$list[] = trim((string)$a[$k]);
	foreach((array)($a['Messages'] ?? []) as $m)
	    $list[] = is_array($m) ? (string)($m['message'] ?? '') : (string)$m;
	return mb_substr(implode('; ', array_slice(array_values(array_unique(array_filter($list, 'strlen'))), 0, 5)), 0, 1000);
    }

    public static function stamp($v) {
	$v = trim((string)$v);
	if (preg_match('/^(\d{4})(\d\d)(\d\d)(\d\d)(\d\d)(\d\d)?$/', $v, $m))
	    return "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}" . (isset($m[6]) && $m[6] !== '' ? ":{$m[6]}" : '');
	return $v;
    }

    public static function company(array $f) {
	$g = $f['date_generale'] ?? [];
	$vat = $f['inregistrare_scop_Tva'] ?? [];
	$period = ((array)($vat['perioade_TVA'] ?? []))[0] ?? [];
	$cash = $f['inregistrare_RTVAI'] ?? [];
	$inactive = $f['stare_inactiv'] ?? [];
	$split = $f['inregistrare_SplitTVA'] ?? [];
	$address = function($a, $p) {
	    $a = (array)$a;
	    return [ 'county' => (string)($a[$p . 'denumire_Judet'] ?? ''), 'countyCode' => (string)($a[$p . 'cod_JudetAuto'] ?? ''), 'locality' => (string)($a[$p . 'denumire_Localitate'] ?? ''),
		'street' => (string)($a[$p . 'denumire_Strada'] ?? ''), 'number' => (string)($a[$p . 'numar_Strada'] ?? ''), 'details' => (string)($a[$p . 'detalii_Adresa'] ?? ''),
		'postalCode' => (string)($a[$p . 'cod_Postal'] ?? ''), 'country' => (string)($a[$p . 'tara'] ?? '') ];
	};
	return [
	    'cui' => (string)($g['cui'] ?? ''),
	    'name' => (string)($g['denumire'] ?? ''),
	    'address' => (string)($g['adresa'] ?? ''),
	    'regCom' => (string)($g['nrRegCom'] ?? ''),
	    'phone' => (string)($g['telefon'] ?? ''),
	    'fax' => (string)($g['fax'] ?? ''),
	    'postalCode' => (string)($g['codPostal'] ?? ''),
	    'caen' => (string)($g['cod_CAEN'] ?? ''),
	    'iban' => (string)($g['iban'] ?? ''),
	    'registeredOn' => (string)($g['data_inregistrare'] ?? ''),
	    'registrationStatus' => (string)($g['stare_inregistrare'] ?? ''),
	    'taxOffice' => (string)($g['organFiscalCompetent'] ?? ''),
	    'legalForm' => (string)($g['forma_juridica'] ?? ''),
	    'organizationForm' => (string)($g['forma_organizare'] ?? ''),
	    'ownership' => (string)($g['forma_de_proprietate'] ?? ''),
	    'vatPayer' => !empty($vat['scpTVA']),
	    'vatFrom' => (string)($period['data_inceput_ScpTVA'] ?? ''),
	    'vatTo' => (string)($period['data_sfarsit_ScpTVA'] ?? ''),
	    'vatOnCollection' => !empty($cash['statusTvaIncasare']),
	    'splitVat' => !empty($split['statusSplitTVA']),
	    'inactive' => !empty($inactive['statusInactivi']),
	    'inactiveSince' => (string)($inactive['dataInactivare'] ?? ''),
	    'deregisteredOn' => (string)($inactive['dataRadiere'] ?? ''),
	    'eFactura' => !empty($g['statusRO_e_Factura']),
	    'eFacturaSince' => (string)($g['data_inreg_Reg_RO_e_Factura'] ?? ''),
	    'headOffice' => $address($f['adresa_sediu_social'] ?? [], 's'),
	    'fiscalAddress' => $address($f['adresa_domiciliu_fiscal'] ?? [], 'd'),
	    'date' => (string)($g['data'] ?? ''),
	    'anaf' => $f,
	];
    }
}
