<?php

/*
    the reference exchange rates of the National Bank of Romania (BNR), for the BNR Exchange Rates step. Public XML files on curs.bnr.ro (www.bnr.ro's
    addresses redirect to its home page):
	nbrfxrates.xml                    the latest day's (published each banking day about 13:00, Romania's time)
	files/xml/years/nbrfxrates<Y>.xml every banking day of year Y (from 2005)
    <DataSet><Body><OrigCurrency>RON</OrigCurrency><Cube date="YYYY-MM-DD"><Rate currency="EUR">4.9706</Rate><Rate currency="HUF" multiplier="100">1.4722</Rate>...</Cube>...
    A rate is in RON for `multiplier` units (default 1); here always for 1 unit.
	Bnr::on($date, $start, $timeout)  [ date, rates ] of the last banking day on or before $date (YYYY-MM-DD; null: the latest): rates [ currency => RON for 1 unit ]
    The files read are kept in the process (the daemon runs many steps): the latest one LATEST_TTL, the current year's YEAR_TTL, the past years' PAST_TTL
*/

namespace plugins\RegionalRO\util;

class Bnr {
    const BASE = 'https://curs.bnr.ro/';
    const FIRST_YEAR = 2005;
    const LATEST_TTL = 600;
    const YEAR_TTL = 1800;
    const PAST_TTL = 86400;
    const MAX_ANSWER = 5 * 1024 * 1024;

    //url => [ read at, [ date => [ currency => rate ] ] (dates in order) ]
    private static $files = [];

    public static function on($date, $start, $timeout) {
	if ($date === null) {
	    $days = self::file('nbrfxrates.xml', self::LATEST_TTL, $start, $timeout);
	    $last = array_key_last($days);
	    return [ $last, $days[$last] ];
	}
	$year = (int)substr($date, 0, 4);
	if ($year < self::FIRST_YEAR)
	    throw new \RuntimeException('BNR\'s files start in ' . self::FIRST_YEAR);
	//the first days of January: the last day of the year before
	for ($y = $year; $y >= max(self::FIRST_YEAR, $year - 1); $y--) {
	    $days = self::file("files/xml/years/nbrfxrates{$y}.xml", $y == (int)date('Y') ? self::YEAR_TTL : self::PAST_TTL, $start, $timeout);
	    $best = null;
	    foreach($days as $d => $rates) {
		if ($d > $date)
		    break;
		$best = $d;
	    }
	    if ($best !== null)
		return [ $best, $days[$best] ];
	}
	throw new \RuntimeException("BNR has no rates on or before {$date}");
    }

    private static function file($path, $ttl, $start, $timeout) {
	$url = self::BASE . $path;
	if (isset(self::$files[$url]) && time() - self::$files[$url][0] < $ttl)
	    return self::$files[$url][1];
	$r = \util\Http::request('GET', $url, [ 'timeout' => Anaf::left($start, $timeout), 'maxBytes' => self::MAX_ANSWER, 'https' => true, 'public' => true, 'compressed' => true ]);
	if ($r['error'] !== null)
	    throw new \RuntimeException('no answer from BNR: ' . $r['error']);
	if ($r['status'] == 404)
	    throw new \RuntimeException('BNR has no file ' . $path);
	if ($r['status'] != 200)
	    throw new \RuntimeException("BNR answered HTTP {$r['status']} for {$path}");
	$days = self::parse($r['body']);
	if (!$days)
	    throw new \RuntimeException("BNR's {$path} has no rates (not its XML?)");
	self::$files[$url] = [ time(), $days ];
	return $days;
    }

    public static function parse($xml) {
	$xml = (string)$xml;
	if (trim($xml) === '' || preg_match('~<!(DOCTYPE|ENTITY)~i', $xml))
	    return [];
	//the default namespace (http or https://www.bnr.ro/xsd) dropped, so the elements are found by name
	$prev = libxml_use_internal_errors(true);
	$root = simplexml_load_string(preg_replace('~\sxmlns="[^"]*"~', '', $xml, 1), \SimpleXMLElement::class, LIBXML_NONET);
	libxml_clear_errors();
	libxml_use_internal_errors($prev);
	if ($root === false || !isset($root->Body))
	    return [];
	$days = [];
	foreach($root->Body->Cube ?? [] as $cube) {
	    $d = (string)$cube['date'];
	    if (!preg_match('/^\d{4}-\d\d-\d\d$/', $d))
		continue;
	    $rates = [ 'RON' => 1.0 ];
	    foreach($cube->Rate ?? [] as $rate) {
		$cur = strtoupper((string)$rate['currency']);
		$value = (float)(string)$rate;
		$mult = max(1, (int)(string)($rate['multiplier'] ?? 1));
		if (preg_match('/^[A-Z]{3}$/', $cur) && $value > 0)
		    $rates[$cur] = round($value / $mult, 8);
	    }
	    $days[$d] = $rates;
	}
	ksort($days);
	return $days;
    }
}
