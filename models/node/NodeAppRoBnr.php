<?php

/*
    BNR Exchange Rates - the reference rates of the National Bank of Romania (util\Bnr; public, no credential). Operations (setting `operation`):
	rates       the rates of a day (empty: the latest), of some currencies (empty: all): { date (the banking day used: the last on or before the one asked), requested, base RON,
	            rates { EUR: 4.97, ... }: RON for 1 unit }
	convert     an amount from a currency to another (RON or one of BNR's) at a day's rates: { amount, from, to, rate (of 1 `from` in `to`), result (rounded to Decimals), date, requested }
*/

namespace plugins\RegionalRO\models\node;

use plugins\RegionalRO\util\Bnr;

class NodeAppRoBnr extends NodeAppRo {
    public static $name = 'ro-bnr-rates';
    public static $label = 'BNR Exchange Rates';
    public static $icon = 'bi-currency-exchange';
    public static $defaultOperation = 'rates';
    public static $defaultVariable = 'rates';
    const DEFAULT_TIMEOUT = 30;

    const OPERATIONS = [
	'rates' => 'Get exchange rates',
	'convert' => 'Convert an amount',
    ];
    const WRITES = [];

    protected static function operationFields() {
	return [
	    self::field('date', 'On date', [ 'rates', 'convert' ], [ 'help' => 'YYYY-MM-DD; empty: the latest rates. A day without rates (a weekend, a holiday): the banking day before',
		'ui' => [ 'expression' => 'text', 'width' => 6, 'placeholder' => 'YYYY-MM-DD' ] ]),
	    self::field('currencies', 'Currencies', [ 'rates' ], [ 'help' => 'e.g. EUR, USD; empty: all', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('amount', 'Amount', [ 'convert' ], [ 'required' => true, 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('decimals', 'Decimals', [ 'convert' ], [ 'default' => '2', 'help' => 'Of the result, 0 to 8', 'ui' => [ 'width' => 6 ] ]),
	    self::field('from', 'From currency', [ 'convert' ], [ 'required' => true, 'default' => 'EUR', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('to', 'To currency', [ 'convert' ], [ 'required' => true, 'default' => 'RON', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	];
    }

    protected static function resultOf($operation) {
	if ($operation === 'convert')
	    return [ 'type' => 'object', 'info' => '{ amount, from, to, rate, result, date, requested }', 'fields' => [
		'result' => [ 'type' => 'number', 'info' => 'the amount in `to`' ],
		'rate' => [ 'type' => 'number', 'info' => '1 `from` in `to`' ],
		'date' => [ 'type' => 'string', 'info' => 'the banking day of the rates' ],
	    ] ];
	return [ 'type' => 'object', 'info' => '{ date, requested, base, rates }', 'fields' => [
	    'date' => [ 'type' => 'string', 'info' => 'the banking day of the rates' ],
	    'requested' => [ 'type' => 'string', 'info' => 'the day asked (empty: the latest)' ],
	    'base' => [ 'type' => 'string', 'info' => 'RON' ],
	    'rates' => [ 'type' => 'object', 'info' => 'currency => RON for 1 unit' ],
	] ];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$requested = $text('date') === '' ? null : $this->dateOf($text, 'date', 'On date');
	if ($requested !== null && $requested > (new \DateTime('tomorrow', new \DateTimeZone('Europe/Bucharest')))->format('Y-m-d'))
	    throw new \RuntimeException("On date: {$requested} is in the future");
	[ $date, $rates ] = Bnr::on($requested, $this->start, $this->timeout);
	$cur = function($key, $label) use ($text, $rates, $date) {
	    $c = strtoupper($text($key));
	    if (!preg_match('/^[A-Z]{3}$/', $c))
		throw new \RuntimeException("{$label}: not a currency code: `" . mb_substr($c, 0, 10) . '`');
	    if (!isset($rates[$c]))
		throw new \RuntimeException("{$label}: BNR has no rate of {$c} on {$date}");
	    return $c;
	};
	if ($operation === 'convert') {
	    $from = $cur('from', 'From currency');
	    $to = $cur('to', 'To currency');
	    $raw = str_replace([ ' ', ',' ], [ '', '.' ], $text('amount'));
	    if (!is_numeric($raw))
		throw new \RuntimeException('Amount: not a number: `' . mb_substr($raw, 0, 32) . '`');
	    $decimals = $this->intOf($text, 'decimals', 'Decimals', 0, 8, 2);
	    $rate = $rates[$from] / $rates[$to];
	    $result = round((float)$raw * $rate, $decimals);
	    return [ [ 'amount' => (float)$raw, 'from' => $from, 'to' => $to, 'rate' => round($rate, 8), 'result' => $result, 'date' => $date, 'requested' => (string)$requested ],
		"{$raw} {$from} = {$result} {$to} (BNR {$date})" ];
	}
	$want = array_values(array_filter(array_map(fn($c) => strtoupper(trim($c)), preg_split('/[\s,;]+/', $text('currencies'))), 'strlen'));
	//RON itself only when asked
	$out = array_diff_key($rates, [ 'RON' => true ]);
	if ($want) {
	    $missing = array_diff($want, array_keys($rates));
	    if ($missing)
		throw new \RuntimeException("Currencies: BNR has no rate of " . implode(', ', $missing) . " on {$date}");
	    $out = array_intersect_key($rates, array_flip($want));
	}
	return [ [ 'date' => $date, 'requested' => (string)$requested, 'base' => 'RON', 'rates' => $out ],
	    \util\Format::plural(count($out), 'rate') . " of {$date}" . (isset($out['EUR']) ? " (EUR {$out['EUR']})" : '') ];
    }
}
