<?php

/*
    CLD: 2026-11-10T16:00:00+02:00: new step: Cargus - shipments with Cargus (UrgentCargus API; util\Cargus). Operations (setting `operation`):
	awb / price    make an AWB (POST Awbs: the AWB's number) or only its price (POST ShippingCalculation: { BaseCost, ..., Subtotal, Tax, GrandTotal }): the pickup location (empty:
	               the credential's), the recipient (name, county, locality, address, contact person, phone, email, postal code), parcels, envelopes, weight, the service (empty:
	               by weight: 34 up to 31 kg, 35 up to 50, 50 above), declared value, cash on delivery (cash / to the account), open package, Saturday / morning delivery, who pays,
	               notes, content, a reference (CustomString); More fields: a JSON object added to the AWB (as Cargus names them, e.g. ParcelCodes)
	get            an AWB's details (GET Awbs?barCode=)
	trace          the events of one or more AWBs (AwbTrace)
	pdf            the AWBs' documents as one PDF saved in the project's Files (A4 or the 10x14 label)
	delete         delete an AWB that has no checkpoint yet
	pickupLocations / counties / localities   lists (localities: of a county id)
	api            Call the API: any call (method, path, query, JSON body)
*/

namespace plugins\RegionalRO\models\node;

class NodeAppRoCargus extends NodeAppRo {
    public static $name = 'ro-cargus';
    public static $label = 'Cargus';
    public static $icon = 'bi-truck';
    public static $defaultOperation = 'awb';
    public static $defaultVariable = 'cargus';
    public static $capability = 'cargus';
    public static $credentialLabel = 'Cargus credential';

    const OPERATIONS = [
	'awb' => 'Create an AWB',
	'price' => 'Calculate a shipment\'s price',
	'get' => 'Get an AWB',
	'trace' => 'Trace AWBs',
	'pdf' => 'Get AWB documents',
	'delete' => 'Delete an AWB',
	'pickupLocations' => 'List pickup locations',
	'counties' => 'List counties',
	'localities' => 'List localities',
	'api' => 'Call the API',
    ];
    const WRITES = [ 'awb', 'delete', 'api' ];
    const LISTS = [ 'trace', 'pickupLocations', 'counties', 'localities' ];
    const CREDENTIAL_OPS = [ 'awb', 'price', 'get', 'trace', 'pdf', 'delete', 'pickupLocations', 'counties', 'localities', 'api' ];
    const PAYERS = [ '1' => 'Sender', '2' => 'Recipient' ];
    const MAX_AWBS = 100;

    protected static function operationFields() {
	$ship = [ 'awb', 'price' ];
	$w = fn($n) => [ 'expression' => 'text', 'width' => $n ];
	return array_merge([
	    self::field('locationId', 'Pickup location id', $ship, [ 'help' => 'Empty: the credential\'s (List pickup locations)', 'ui' => $w(4) ]),
	    self::field('name', 'Recipient', $ship, [ 'required' => true, 'ui' => $w(4) ]),
	    self::field('contactPerson', 'Contact person', [ 'awb' ], [ 'help' => 'Empty: the recipient', 'ui' => $w(4) ]),
	    self::field('county', 'County', $ship, [ 'required' => true, 'ui' => $w(4) ]),
	    self::field('locality', 'Locality', $ship, [ 'required' => true, 'ui' => $w(4) ]),
	    self::field('postalCode', 'Postal code', [ 'awb' ], [ 'ui' => $w(4) ]),
	    self::field('address', 'Address', [ 'awb' ], [ 'required' => true, 'help' => 'Street, number, building, ...', 'ui' => [ 'expression' => 'text' ] ]),
	    self::field('phone', 'Phone', [ 'awb' ], [ 'required' => true, 'ui' => $w(4) ]),
	    self::field('email', 'Email', [ 'awb' ], [ 'ui' => $w(4) ]),
	    self::field('parcels', 'Parcels', $ship, [ 'default' => '1', 'ui' => $w(4) ]),
	    self::field('envelopes', 'Envelopes', $ship, [ 'default' => '0', 'ui' => $w(4) ]),
	    self::field('weight', 'Weight (kg)', $ship, [ 'required' => true, 'default' => '1', 'ui' => $w(4) ]),
	    self::field('service', 'Service id', $ship, [ 'help' => 'Empty: by weight (34 up to 31 kg, 35 up to 50, 50 above)', 'ui' => $w(4) ]),
	    self::field('declared', 'Declared value', $ship, [ 'default' => '0', 'ui' => $w(4) ]),
	    self::field('cash', 'Cash on delivery (cash)', $ship, [ 'default' => '0', 'ui' => $w(4) ]),
	    self::field('bank', 'Cash on delivery (to the account)', $ship, [ 'default' => '0', 'ui' => $w(4) ]),
	    self::field('payer', 'Paid by', $ship, [ 'type' => 'select', 'default' => '1', 'choices' => self::PAYERS, 'ui' => [ 'width' => 4 ] ]),
	    self::field('openPackage', 'Open package', $ship, [ 'type' => 'switch', 'default' => false, 'ui' => [ 'width' => 4 ] ]),
	    self::field('saturday', 'Saturday delivery', [ 'awb' ], [ 'type' => 'switch', 'default' => false, 'ui' => [ 'width' => 4 ] ]),
	    self::field('morning', 'Morning delivery', [ 'awb' ], [ 'type' => 'switch', 'default' => false, 'ui' => [ 'width' => 4 ] ]),
	    self::field('observation', 'Notes', [ 'awb' ], [ 'ui' => $w(8) ]),
	    self::field('content', 'Content', [ 'awb' ], [ 'ui' => $w(4) ]),
	    self::field('reference', 'Reference', [ 'awb' ], [ 'help' => 'Yours (CustomString: e.g. the order\'s number)', 'ui' => $w(4) ]),
	    self::field('more', 'More fields', $ship, [ 'type' => 'textarea', 'help' => 'A JSON object added to the AWB, as Cargus names them (e.g. {"ParcelCodes": [..]})',
		'ui' => [ 'rows' => 2, 'expression' => true, 'code' => true, 'advanced' => true, 'agent' => [ 'type' => 'object' ] ] ]),
	    self::field('awbs', 'AWBs', [ 'get', 'trace', 'pdf', 'delete' ], [ 'required' => true, 'help' => 'Separated by commas, or a list (get, delete: one)', 'ui' => $w(8) + [ 'agent' => [ 'type' => 'string' ] ] ]),
	    self::field('format', 'Format', [ 'pdf' ], [ 'type' => 'select', 'default' => '1', 'choices' => [ '0' => 'A4', '1' => 'Label 10x14' ], 'ui' => [ 'width' => 4 ] ]),
	    self::field('countyId', 'County id', [ 'localities' ], [ 'required' => true, 'help' => 'List counties', 'ui' => $w(4) ]),
	], self::apiFields('After https://urgentcargus.azure-api.net/api/, e.g. Awbs/GetByDate', 'A JSON object (or list) sent as the body; empty: none'));
    }

    protected static function resultOf($operation) {
	if ($operation === 'awb')
	    return [ 'type' => 'object', 'info' => '{ awb }', 'fields' => [ 'awb' => [ 'type' => 'string', 'info' => 'the AWB\'s number' ] ] ];
	if ($operation === 'price')
	    return [ 'type' => 'object', 'info' => '{ BaseCost, ExtraKmCost, WeightCost, InsuranceCost, SpecialCost, RepaymentCost, Subtotal, Tax, GrandTotal }' ];
	if ($operation === 'pdf')
	    return [ 'type' => 'object', 'info' => '{ id, filename, size, contentType }' ];
	return null;
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	switch($operation) {
	    case 'awb':
		$r = $this->client->call('POST', 'Awbs', [], $this->shipment($text, $value));
		$awb = is_scalar($r) ? trim((string)$r, "\" \n") : (string)($r['BarCode'] ?? $r[0]['BarCode'] ?? '');
		if ($awb === '')
			throw new \RuntimeException('Cargus gave no AWB number');
		return [ [ 'awb' => $awb ], "AWB {$awb}" ];
	    case 'price':
		$s = $this->shipment($text, $value);
		$from = $this->pickupLocation($s['Sender']['LocationId']);
		$calc = [ 'FromLocalityId' => (int)($from['LocalityId'] ?? 0), 'FromCountyName' => (string)($from['CountyName'] ?? ''), 'FromLocalityName' => (string)($from['LocalityName'] ?? ''),
		    'ToCountyName' => $s['Recipient']['CountyName'], 'ToLocalityName' => $s['Recipient']['LocalityName'] ]
		    + array_intersect_key($s, array_flip([ 'Parcels', 'Envelopes', 'TotalWeight', 'ServiceId', 'DeclaredValue', 'CashRepayment', 'BankRepayment', 'OpenPackage', 'ShipmentPayer' ]));
		$r = (array)$this->client->call('POST', 'ShippingCalculation', [], $calc);
		return [ $r, 'total ' . ($r['GrandTotal'] ?? '?') ];
	    case 'get':
		$awb = $this->awbs($value, true)[0];
		$r = $this->client->call('GET', 'Awbs', [ 'barCode' => $awb ]);
		$a = is_array($r) && array_is_list($r) ? ($r[0] ?? null) : $r;
		if (!$a)
		    throw new \RuntimeException("Cargus has no AWB {$awb}");
		return [ $a, "AWB {$awb}: " . (string)($a['Status'] ?? 'read') ];
	    case 'trace':
		$r = $this->client->call('GET', 'AwbTrace', [ 'barCode' => json_encode($this->awbs($value)) ]);
		return $this->listResult(is_array($r) ? $r : [], 'AWB');
	    case 'pdf':
		$awbs = $this->awbs($value);
		$r = $this->client->call('GET', 'AwbDocuments', [ 'barCodes' => json_encode(array_map('intval', $awbs)), 'type' => 'PDF',
		    'format' => (string)$this->cfg('format', '1') === '0' ? 0 : 1, 'printMainOnce' => 1 ]);
		$pdf = base64_decode(is_string($r) ? trim($r, "\" \n") : '', true);
		if ($pdf === false || !str_starts_with($pdf, '%PDF'))
		    throw new \RuntimeException('Cargus gave no PDF');
		$file = $this->saveFile('cargus-' . (count($awbs) == 1 ? $awbs[0] : count($awbs) . '-awbs-' . date('Ymd-His')) . '.pdf', $pdf);
		return [ $file, "{$file['filename']} (" . \util\Format::bytes($file['size']) . ')' ];
	    case 'delete':
		$awb = $this->awbs($value, true)[0];
		$r = $this->client->call('DELETE', 'Awbs', [ 'barCode' => $awb ]);
		if ($r === false || $r === 'false')
		    throw new \RuntimeException("Cargus did not delete AWB {$awb} (it has a checkpoint, or does not exist)");
		return [ [ 'deleted' => true, 'awb' => $awb ], "AWB {$awb} deleted" ];
	    case 'pickupLocations':
		return $this->listResult((array)$this->client->call('GET', 'PickupLocations/GetForClient'), 'pickup location');
	    case 'counties':
		return $this->listResult((array)$this->client->call('GET', 'Counties', [ 'countryId' => 1 ]), 'county');
	    case 'localities':
		if (!ctype_digit($text('countyId')))
		    throw new \RuntimeException('County id: a number (List counties)');
		return $this->listResult((array)$this->client->call('GET', 'Localities', [ 'countryId' => 1, 'countyId' => $text('countyId') ]), 'locality');
	    case 'api':
		[ $method, $path, $query, $body ] = $this->apiCall($text, $value);
		return [ $this->client->call($method, $path, $query, $body), "{$method} {$path}" ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }

    //the pickup location $id of the client (for the price: its locality)
    protected function pickupLocation($id) {
	foreach((array)$this->client->call('GET', 'PickupLocations/GetForClient') as $l)
	    if (is_array($l) && (string)($l['LocationId'] ?? '') === (string)$id)
		return $l;
	throw new \RuntimeException("Pickup location id: {$id} is not one of the client's");
    }

    //the AWB numbers of setting `awbs` ($one: exactly one)
    protected function awbs(\Closure $value, $one = false) {
	$v = $value('awbs');
	$list = is_array($v) ? $v : preg_split('/[\s,;]+/', \util\Expression::toText($v));
	$awbs = array_values(array_unique(array_filter(array_map(fn($a) => trim(is_scalar($a) ? (string)$a : (string)($a['awb'] ?? $a['BarCode'] ?? '')), $list), 'strlen')));
	if (!$awbs)
	    throw new \RuntimeException('AWBs: none given');
	if ($one && count($awbs) > 1)
	    throw new \RuntimeException('AWBs: one at a time for this operation');
	if (count($awbs) > self::MAX_AWBS)
	    throw new \RuntimeException('AWBs: more than ' . self::MAX_AWBS);
	foreach($awbs as $a)
	    if (!preg_match('/^\d{6,20}$/', $a))
		throw new \RuntimeException("AWBs: not an AWB number: `" . mb_substr($a, 0, 30) . '`');
	return $awbs;
    }

    //the AWB as Cargus names it
    protected function shipment(\Closure $text, \Closure $value) {
	$num = function($key, $label, $default = 0) use ($text) {
	    $v = str_replace([ ' ', ',' ], [ '', '.' ], $text($key));
	    if ($v === '')
		return $default;
	    if (!is_numeric($v) || (float)$v < 0)
		throw new \RuntimeException("{$label}: not a number");
	    return $v + 0;
	};
	$location = $text('locationId') ?: trim((string)$this->cred->locationId);
	if (!ctype_digit($location))
	    throw new \RuntimeException('Pickup location id: none given, and the credential has none');
	foreach([ 'name' => 'Recipient', 'county' => 'County', 'locality' => 'Locality' ] as $k => $label)
	    if ($text($k) === '')
		throw new \RuntimeException("{$label}: needed");
	$weight = $num('weight', 'Weight (kg)', 1);
	$service = $text('service') !== '' ? (int)$text('service') : ($weight <= 31 ? 34 : ($weight <= 50 ? 35 : 50));
	$awb = [
	    'Sender' => [ 'LocationId' => (int)$location ],
	    'Recipient' => array_filter([ 'Name' => $text('name'), 'CountyName' => $text('county'), 'LocalityName' => $text('locality'), 'AddressText' => $text('address'),
		'ContactPerson' => $text('contactPerson') ?: $text('name'), 'PhoneNumber' => $text('phone'), 'Email' => $text('email'), 'CodPostal' => $text('postalCode') ], 'strlen'),
	    'Parcels' => (int)$num('parcels', 'Parcels', 1),
	    'Envelopes' => (int)$num('envelopes', 'Envelopes', 0),
	    'TotalWeight' => $weight,
	    'ServiceId' => $service,
	    'DeclaredValue' => $num('declared', 'Declared value'),
	    'CashRepayment' => $num('cash', 'Cash on delivery (cash)'),
	    'BankRepayment' => $num('bank', 'Cash on delivery (to the account)'),
	    'OtherRepayment' => '',
	    'OpenPackage' => (bool)$this->cfg('openPackage', false),
	    'SaturdayDelivery' => (bool)$this->cfg('saturday', false),
	    'MorningDelivery' => (bool)$this->cfg('morning', false),
	    'ShipmentPayer' => (string)$this->cfg('payer', '1') === '2' ? 2 : 1,
	    'Observations' => $text('observation'),
	    'PackageContent' => $text('content'),
	    'CustomString' => $text('reference'),
	];
	return array_replace_recursive($awb, $this->jsonOf($value, 'more', 'More fields'));
    }
}
