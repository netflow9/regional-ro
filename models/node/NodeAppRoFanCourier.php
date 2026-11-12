<?php

/*
    FAN Courier - shipments with FAN Courier (API 2.0; util\FanCourier), for the credential's client (clientId). Operations (setting `operation`):
	awb / tariff   make an internal AWB (POST intern-awb: { awbNumber, tariff, vat, ..., estimatedDeliveryTime }) or only its price (GET reports/awb/internal-tariff: { weightCost,
	               fuelCost, costNoVAT, vat, total, ... }): the service (Standard, Cont Colector, ...), parcels, envelopes, weight, dimensions, who pays, cash on delivery, declared
	               value, options (letters: X ePOD, A, S, ...), notes, content, cost center, the recipient (name, contact person, phone, email, county, locality, street, number,
	               postal code; building, entrance, floor, apartment); More fields: a JSON object added to `info` (as FAN Courier names them)
	tracking       the history of one or more AWBs (reports/awb/tracking)
	label          the labels of one or more AWBs, as one PDF saved in the project's Files (A4, A5, A6)
	delete         delete an AWB
	services / counties / localities   lists (localities: of a county)
	api            Call the API: any call (method, path, query, JSON body)
*/

namespace plugins\RegionalRO\models\node;

class NodeAppRoFanCourier extends NodeAppRo {
    public static $name = 'ro-fancourier';
    public static $label = 'FAN Courier';
    public static $icon = 'bi-truck';
    public static $defaultOperation = 'awb';
    public static $defaultVariable = 'fan';
    public static $capability = 'fancourier';
    public static $credentialLabel = 'FAN Courier credential';

    const OPERATIONS = [
	'awb' => 'Create an AWB',
	'tariff' => 'Get an AWB\'s tariff',
	'tracking' => 'Track AWBs',
	'label' => 'Get AWB labels',
	'delete' => 'Delete an AWB',
	'services' => 'List services',
	'counties' => 'List counties',
	'localities' => 'List localities',
	'api' => 'Call the API',
    ];
    const WRITES = [ 'awb', 'delete', 'api' ];
    const LISTS = [ 'tracking', 'services', 'counties', 'localities' ];
    const CREDENTIAL_OPS = [ 'awb', 'tariff', 'tracking', 'label', 'delete', 'services', 'counties', 'localities', 'api' ];
    const PAYERS = [ 'sender' => 'Sender', 'recipient' => 'Recipient' ];
    const MAX_AWBS = 100;

    protected static function operationFields() {
	$ship = [ 'awb', 'tariff' ];
	$w = fn($n) => [ 'expression' => 'text', 'width' => $n ];
	return array_merge([
	    self::field('service', 'Service', $ship, [ 'required' => true, 'default' => 'Standard', 'help' => 'Its name (List services): Standard, Cont Colector, RedCode, ...', 'ui' => $w(4) ]),
	    self::field('parcels', 'Parcels', $ship, [ 'default' => '1', 'ui' => $w(4) ]),
	    self::field('envelopes', 'Envelopes', $ship, [ 'default' => '0', 'ui' => $w(4) ]),
	    self::field('weight', 'Weight (kg)', $ship, [ 'required' => true, 'default' => '1', 'ui' => $w(4) ]),
	    self::field('dimensions', 'Length x width x height', $ship, [ 'default' => '10x10x10', 'help' => 'cm', 'ui' => $w(4) ]),
	    self::field('payer', 'Paid by', $ship, [ 'type' => 'select', 'default' => 'sender', 'choices' => self::PAYERS, 'ui' => [ 'width' => 4 ] ]),
	    self::field('cod', 'Cash on delivery', $ship, [ 'default' => '0', 'ui' => $w(4) ]),
	    self::field('declared', 'Declared value', $ship, [ 'default' => '0', 'ui' => $w(4) ]),
	    self::field('options', 'Options', $ship, [ 'help' => 'Letters, separated by commas (e.g. X: ePOD)', 'ui' => $w(4) ]),
	    self::field('name', 'Recipient', $ship, [ 'required' => true, 'ui' => $w(4) ]),
	    self::field('contactPerson', 'Contact person', $ship, [ 'help' => 'Empty: the recipient', 'ui' => $w(4) ]),
	    self::field('phone', 'Phone', [ 'awb' ], [ 'required' => true, 'ui' => $w(4) ]),
	    self::field('email', 'Email', [ 'awb' ], [ 'ui' => $w(4) ]),
	    self::field('county', 'County', $ship, [ 'required' => true, 'ui' => $w(4) ]),
	    self::field('locality', 'Locality', $ship, [ 'required' => true, 'ui' => $w(4) ]),
	    self::field('street', 'Street', [ 'awb' ], [ 'required' => true, 'ui' => $w(8) ]),
	    self::field('streetNo', 'Number', [ 'awb' ], [ 'ui' => $w(4) ]),
	    self::field('zipCode', 'Postal code', [ 'awb' ], [ 'ui' => $w(4) ]),
	    self::field('building', 'Building, entrance, floor, apartment', [ 'awb' ], [ 'help' => 'Separated by commas', 'ui' => $w(8) ]),
	    self::field('observation', 'Notes', [ 'awb' ], [ 'ui' => $w(8) ]),
	    self::field('content', 'Content', [ 'awb' ], [ 'help' => 'e.g. the order\'s number', 'ui' => $w(4) ]),
	    self::field('costCenter', 'Cost center', [ 'awb' ], [ 'ui' => $w(4) ]),
	    self::field('more', 'More fields', $ship, [ 'type' => 'textarea', 'help' => 'A JSON object added to `info`, as FAN Courier names them (e.g. {"bank": "...", "bankAccount": "..."})',
		'ui' => [ 'rows' => 2, 'expression' => true, 'code' => true, 'advanced' => true, 'agent' => [ 'type' => 'object' ] ] ]),
	    self::field('awbs', 'AWBs', [ 'tracking', 'label', 'delete' ], [ 'required' => true, 'help' => 'Separated by commas, or a list (delete: one)', 'ui' => $w(8) + [ 'agent' => [ 'type' => 'string' ] ] ]),
	    self::field('format', 'Format', [ 'label' ], [ 'type' => 'select', 'default' => 'A6', 'choices' => [ 'A4' => 'A4', 'A5' => 'A5', 'A6' => 'A6' ], 'ui' => [ 'width' => 4 ] ]),
	    self::field('countyName', 'County', [ 'localities' ], [ 'help' => 'Empty: all', 'ui' => $w(4) ]),
	], self::apiFields('After https://api.fancourier.ro/, e.g. reports/awb (clientId: add it to the query)', 'A JSON object sent as the body; empty: none'));
    }

    protected static function resultOf($operation) {
	if ($operation === 'awb')
	    return [ 'type' => 'object', 'info' => '{ awbNumber, tariff, vat, ... }', 'fields' => [ 'awbNumber' => [ 'type' => 'string' ], 'tariff' => [ 'type' => 'number' ] ] ];
	if ($operation === 'tariff')
	    return [ 'type' => 'object', 'info' => '{ weightCost, fuelCost, costNoVAT, vat, total, ... }' ];
	if ($operation === 'label')
	    return [ 'type' => 'object', 'info' => '{ id, filename, size, contentType }' ];
	return null;
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$clientId = $this->client->clientId();
	switch($operation) {
	    case 'awb':
		[ $info, $recipient ] = $this->shipment($text, $value, true);
		$r = $this->client->call('POST', 'intern-awb', [], [ 'clientId' => (int)$clientId, 'shipments' => [ [ 'info' => $info, 'recipient' => $recipient ] ] ]);
		$a = (array)($r['response'][0] ?? []);
		if (!empty($a['errors']))
		    throw new \RuntimeException('FAN Courier refused the AWB: ' . \plugins\RegionalRO\util\FanCourier::errors([ 'errors' => (array)$a['errors'] ]));
		if (empty($a['awbNumber']))
		    throw new \RuntimeException('FAN Courier gave no AWB number');
		$a['awbNumber'] = (string)$a['awbNumber'];
		return [ $a, "AWB {$a['awbNumber']}" . (isset($a['tariff']) ? ", tariff {$a['tariff']}" : '') ];
	    case 'tariff':
		[ $info, $recipient ] = $this->shipment($text, $value, false);
		$r = $this->client->call('GET', 'reports/awb/internal-tariff', [ 'clientId' => $clientId, 'info' => $info,
		    'recipient' => [ 'county' => $recipient['address']['county'], 'locality' => $recipient['address']['locality'] ] ]);
		$d = (array)($r['data'] ?? []);
		return [ $d, 'total ' . ($d['total'] ?? '?') ];
	    case 'tracking':
		$awbs = $this->awbs($value);
		$r = $this->client->call('GET', 'reports/awb/tracking', [ 'clientId' => $clientId, 'awb' => $awbs, 'language' => 'ro' ]);
		return $this->listResult((array)($r['data'] ?? []), 'AWB');
	    case 'label':
		$awbs = $this->awbs($value);
		$format = in_array((string)$this->cfg('format', 'A6'), [ 'A4', 'A5', 'A6' ], true) ? (string)$this->cfg('format', 'A6') : 'A6';
		$pdf = $this->client->file('awb/label', [ 'clientId' => $clientId, 'awbs' => $awbs, 'pdf' => 1, 'format' => $format ]);
		$file = $this->saveFile('fan-' . (count($awbs) == 1 ? $awbs[0] : count($awbs) . '-awbs-' . date('Ymd-His')) . '.pdf', $pdf);
		return [ $file, "{$file['filename']} (" . \util\Format::bytes($file['size']) . ')' ];
	    case 'delete':
		$awbs = $this->awbs($value);
		if (count($awbs) > 1)
			throw new \RuntimeException('AWBs: delete one at a time');
		$r = $this->client->call('DELETE', 'awb', [ 'clientId' => $clientId, 'awb' => $awbs[0] ]);
		return [ $r, "AWB {$awbs[0]} deleted" ];
	    case 'services':
	    case 'counties':
	    case 'localities':
		$r = $this->client->call('GET', "reports/{$operation}", $operation === 'localities' ? [ 'county' => $text('countyName') ] : []);
		return $this->listResult((array)($r['data'] ?? []), [ 'services' => 'service', 'counties' => 'county', 'localities' => 'locality' ][$operation]);
	    case 'api':
		[ $method, $path, $query, $body ] = $this->apiCall($text, $value);
		return [ $this->client->call($method, $path, $query, $body), "{$method} {$path}" ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }

    //the AWB numbers of setting `awbs`
    protected function awbs(\Closure $value) {
	$v = $value('awbs');
	$list = is_array($v) ? $v : preg_split('/[\s,;]+/', \util\Expression::toText($v));
	$awbs = array_values(array_unique(array_filter(array_map(fn($a) => trim(is_scalar($a) ? (string)$a : (string)($a['awbNumber'] ?? '')), $list), 'strlen')));
	if (!$awbs)
	    throw new \RuntimeException('AWBs: none given');
	if (count($awbs) > self::MAX_AWBS)
	    throw new \RuntimeException('AWBs: more than ' . self::MAX_AWBS);
	foreach($awbs as $a)
	    if (!preg_match('/^\d{6,20}$/', $a))
		throw new \RuntimeException("AWBs: not an AWB number: `" . mb_substr($a, 0, 30) . '`');
	return $awbs;
    }

    //[ info, recipient ] of the AWB (or its tariff: $full false)
    protected function shipment(\Closure $text, \Closure $value, $full) {
	$num = function($key, $label, $default = 0) use ($text) {
	    $v = str_replace([ ' ', ',' ], [ '', '.' ], $text($key));
	    if ($v === '')
		return $default;
	    if (!is_numeric($v) || (float)$v < 0)
		throw new \RuntimeException("{$label}: not a number");
	    return $v + 0;
	};
	$dims = array_map('trim', preg_split('/[x*,;]+/i', $text('dimensions') ?: '10x10x10'));
	if (count($dims) != 3 || array_filter($dims, fn($d) => !is_numeric($d)))
	    throw new \RuntimeException('Length x width x height: three numbers, such as 30x20x10');
	$info = [
	    'service' => $text('service') ?: 'Standard',
	    'packages' => [ 'parcel' => (int)$num('parcels', 'Parcels', 1), 'envelope' => (int)$num('envelopes', 'Envelopes', 0) ],
	    'weight' => $num('weight', 'Weight (kg)', 1),
	    'cod' => $num('cod', 'Cash on delivery'),
	    'declaredValue' => $num('declared', 'Declared value'),
	    'payment' => isset(self::PAYERS[(string)$this->cfg('payer', 'sender')]) ? (string)$this->cfg('payer', 'sender') : 'sender',
	    'dimensions' => [ 'length' => $dims[0] + 0, 'width' => $dims[1] + 0, 'height' => $dims[2] + 0 ],
	    'options' => array_values(array_filter(array_map(fn($o) => strtoupper(trim($o)), explode(',', $text('options'))), 'strlen')),
	];
	if ($full)
	    $info += array_filter([ 'observation' => $text('observation'), 'content' => $text('content'), 'costCenter' => $text('costCenter') ], 'strlen');
	$info = array_replace_recursive($info, $this->jsonOf($value, 'more', 'More fields'));
	foreach([ 'county' => 'County', 'locality' => 'Locality' ] + ($full ? [ 'name' => 'Recipient', 'phone' => 'Phone', 'street' => 'Street' ] : []) as $k => $label)
	    if ($text($k) === '')
		throw new \RuntimeException("{$label}: needed");
	[ $building, $entrance, $floor, $apartment ] = array_pad(array_map('trim', explode(',', $text('building'))), 4, '');
	$recipient = array_filter([ 'name' => $text('name'), 'contactPerson' => $text('contactPerson') ?: $text('name'), 'phone' => $text('phone'), 'email' => $text('email') ], 'strlen')
	    + [ 'address' => array_filter([ 'county' => $text('county'), 'locality' => $text('locality'), 'street' => $text('street'), 'streetNo' => $text('streetNo'),
		'zipCode' => $text('zipCode'), 'building' => $building, 'entrance' => $entrance, 'floor' => $floor, 'apartment' => $apartment ], 'strlen') ];
	return [ $info, $recipient ];
    }
}
