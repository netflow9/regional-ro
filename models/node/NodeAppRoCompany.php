<?php

/*
    ANAF Company Lookup - Romanian companies by CUI / CIF, from ANAF's public register (util\Anaf::lookup; no credential): name, address,
    trade register number, CAEN code, VAT payer (and its period), VAT on collection, split VAT, inactive, registered for e-Factura, the head office and the fiscal address, on a date
    (empty: today). One or more codes (separated by commas / new lines, or a list: of codes, or of objects with `cui` / `cif`), with or without RO; their control digit checked.
    The result: { items (util\Anaf::company: short names + `anaf`, the record as ANAF gives it), count, company (the first, or null), notFound, invalid }; codes ANAF does not know are
    not an error (notFound), nor are codes that are not valid (invalid); none valid is
*/

namespace plugins\RegionalRO\models\node;

use plugins\RegionalRO\util\Anaf;

class NodeAppRoCompany extends NodeAppRo {
    public static $name = 'ro-company';
    public static $label = 'ANAF Company Lookup';
    public static $icon = 'bi-building';
    public static $defaultOperation = 'lookup';
    public static $defaultVariable = 'companies';

    const OPERATIONS = [ 'lookup' => 'Look up companies' ];
    const WRITES = [];

    protected static function operationFields() {
	return [
	    self::field('cuis', 'CUI / CIF', [ 'lookup' ], [ 'required' => true, 'help' => 'One or more, separated by commas or new lines (or a list); with or without RO. At most '
		. Anaf::MAX_CODES . '; ANAF answers 100 a second', 'ui' => [ 'expression' => 'text', 'placeholder' => 'RO14399840' ] ]),
	    self::field('date', 'On date', [ 'lookup' ], [ 'help' => 'YYYY-MM-DD; empty: today', 'ui' => [ 'expression' => 'text', 'width' => 6, 'placeholder' => 'YYYY-MM-DD' ] ]),
	];
    }

    protected static function resultOf($operation) {
	return [ 'type' => 'object', 'info' => '{ items, count, company, notFound, invalid }', 'fields' => [
	    'items' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'info' => 'a company: cui, name, address, regCom, caen, vatPayer, vatOnCollection, splitVat, inactive, eFactura, headOffice, fiscalAddress, anaf, ...' ] ],
	    'count' => [ 'type' => 'number', 'info' => 'the companies found' ],
	    'company' => [ 'type' => 'object', 'info' => 'the first company found, or null' ],
	    'notFound' => [ 'type' => 'array', 'info' => 'the codes ANAF does not know' ],
	    'invalid' => [ 'type' => 'array', 'info' => 'the texts that are not valid codes' ],
	] ];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$v = $value('cuis');
	$parts = is_array($v) ? (array_is_list($v) ? $v : [ $v ]) : preg_split('/[\r\n,;]+/', \util\Expression::toText($v));
	$cuis = [];
	$invalid = [];
	foreach($parts as $p) {
	    if (is_array($p))
		$p = $p['cui'] ?? $p['cif'] ?? $p['CUI'] ?? $p['CIF'] ?? '';
	    $t = trim(is_scalar($p) ? (string)$p : '');
	    if ($t === '')
		continue;
	    $c = Anaf::cui($t);
	    if ($c === null)
		$invalid[] = mb_substr($t, 0, 40);
	    else
		$cuis[$c] = true;
	}
	$cuis = array_keys($cuis);
	if (!$cuis)
	    throw new \RuntimeException($invalid ? 'CUI / CIF: none valid (`' . implode('`, `', array_slice($invalid, 0, 5)) . '`)' : 'CUI / CIF: none given');
	if (count($cuis) > Anaf::MAX_CODES)
	    throw new \RuntimeException('CUI / CIF: more than ' . Anaf::MAX_CODES);
	$date = $this->dateOf($text, 'date', 'On date');
	[ $found, $notFound ] = Anaf::lookup($cuis, $date, $this->start, $this->timeout);
	$items = array_map(fn($f) => Anaf::company((array)$f), $found);
	$result = [ 'items' => $items, 'count' => count($items), 'company' => $items[0] ?? null, 'notFound' => $notFound, 'invalid' => $invalid ];
	//an AI step's tool: without ANAF's own record and the first company again (the short names say the same; the model's result is cut at a length)
	if ($this->toolCall) {
	    $result['items'] = array_map(fn($c) => array_diff_key($c, [ 'anaf' => true ]), $items);
	    unset($result['company']);
	}
	return [ $result, \util\Format::plural(count($items), 'company') . ' found' . ($notFound ? ', ' . count($notFound) . ' not found' : '')
	    . ($invalid ? ', ' . count($invalid) . ' not valid' : '') . " (on {$date})" ];
    }
}
