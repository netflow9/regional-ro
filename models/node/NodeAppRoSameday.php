<?php

/*
    Sameday - shipments with Sameday (util\Sameday). Operations (setting `operation`):
	awb / estimate   make an AWB (POST api/awb: { awbNumber, awbCost, parcels, pdfLink }) or only its price (api/awb/estimate-cost: { amount, currency, time }): the pickup point
	                 (empty: the account's default one, and its first contact person), the service, the package type, the parcels (one of Weight, or a list of { weight, width,
	                 length, height }), who pays, cash on delivery, insured value, the recipient (name, phone, email, county, city, address, postal code; a company: name, CUI),
	                 an easybox (locker id), notes, a reference; More fields: a JSON object added to the form (as Sameday names them)
	status           an AWB's status and history (api/client/awb/<awb>/status)
	pdf              an AWB's label, saved in the project's Files (A4 or A6)
	delete           delete an AWB
	pickupPoints / services / lockers / cities   lists (cities: of a county, by name)
	api              Call the API: any call (method, path, query, form body)
*/

namespace plugins\RegionalRO\models\node;

class NodeAppRoSameday extends NodeAppRo {
    public static $name = 'ro-sameday';
    public static $label = 'Sameday';
    public static $icon = 'bi-truck';
    public static $defaultOperation = 'awb';
    public static $defaultVariable = 'sameday';
    public static $capability = 'sameday';
    public static $credentialLabel = 'Sameday credential';

    const OPERATIONS = [
	'awb' => 'Create an AWB',
	'estimate' => 'Estimate an AWB\'s cost',
	'status' => 'Get an AWB\'s status',
	'pdf' => 'Get an AWB\'s label',
	'delete' => 'Delete an AWB',
	'pickupPoints' => 'List pickup points',
	'services' => 'List services',
	'lockers' => 'List easyboxes',
	'cities' => 'List cities',
	'api' => 'Call the API',
    ];
    const WRITES = [ 'awb', 'delete', 'api' ];
    const LISTS = [ 'pickupPoints', 'services', 'lockers', 'cities' ];
    const CREDENTIAL_OPS = [ 'awb', 'estimate', 'status', 'pdf', 'delete', 'pickupPoints', 'services', 'lockers', 'cities', 'api' ];
    const PACKAGES = [ '0' => 'Parcel', '1' => 'Envelope', '2' => 'Large package' ];
    const PAYERS = [ '1' => 'Client (the sender)', '2' => 'Recipient', '3' => 'Third party' ];
    const LISTS_PATHS = [ 'pickupPoints' => 'api/client/pickup-points', 'services' => 'api/client/services', 'lockers' => 'api/client/lockers', 'cities' => 'api/geolocation/city' ];
    const PAGE = 500;

    protected static function operationFields() {
	$ship = [ 'awb', 'estimate' ];
	$w = fn($n) => [ 'expression' => 'text', 'width' => $n ];
	return array_merge([
	    self::field('pickupPoint', 'Pickup point id', $ship, [ 'help' => 'Empty: the account\'s default (List pickup points)', 'ui' => $w(4) ]),
	    self::field('contactPerson', 'Contact person id', $ship, [ 'help' => 'Of the pickup point; empty: its first', 'ui' => $w(4) ]),
	    self::field('service', 'Service id', $ship, [ 'required' => true, 'help' => 'List services (e.g. Nextday 24H)', 'ui' => $w(4) ]),
	    self::field('packageType', 'Package type', $ship, [ 'type' => 'select', 'default' => '0', 'choices' => self::PACKAGES, 'ui' => [ 'width' => 4 ] ]),
	    self::field('weight', 'Weight (kg)', $ship, [ 'default' => '1', 'help' => 'One parcel; or Parcels', 'ui' => $w(4) ]),
	    self::field('parcels', 'Parcels', $ship, [ 'help' => 'A JSON list of { weight, width, length, height } (cm); empty: one of Weight', 'ui' => $w(4) + [ 'agent' => [ 'type' => 'list' ] ] ]),
	    self::field('payer', 'Paid by', $ship, [ 'type' => 'select', 'default' => '1', 'choices' => self::PAYERS, 'ui' => [ 'width' => 4 ] ]),
	    self::field('cod', 'Cash on delivery', $ship, [ 'default' => '0', 'ui' => $w(4) ]),
	    self::field('insured', 'Insured value', $ship, [ 'default' => '0', 'ui' => $w(4) ]),
	    self::field('name', 'Recipient', $ship, [ 'required' => true, 'ui' => $w(4) ]),
	    self::field('phone', 'Phone', $ship, [ 'required' => true, 'ui' => $w(4) ]),
	    self::field('email', 'Email', $ship, [ 'ui' => $w(4) ]),
	    self::field('county', 'County', $ship, [ 'required' => true, 'help' => 'Its name (e.g. Cluj), or Sameday\'s id', 'ui' => $w(4) ]),
	    self::field('city', 'City', $ship, [ 'required' => true, 'help' => 'Its name, or Sameday\'s id', 'ui' => $w(4) ]),
	    self::field('postalCode', 'Postal code', $ship, [ 'ui' => $w(4) ]),
	    self::field('address', 'Address', $ship, [ 'required' => true, 'help' => 'Street, number, building, ...', 'ui' => [ 'expression' => 'text' ] ]),
	    self::field('company', 'Company', $ship, [ 'help' => 'A company recipient: its name', 'ui' => $w(4) ]),
	    self::field('companyCui', 'Company CUI', $ship, [ 'ui' => $w(4) ]),
	    self::field('locker', 'Easybox id', $ship, [ 'help' => 'Delivery to an easybox (List easyboxes)', 'ui' => $w(4) ]),
	    self::field('observation', 'Notes', $ship, [ 'ui' => $w(8) ]),
	    self::field('reference', 'Reference', $ship, [ 'help' => 'Yours (e.g. the order\'s number)', 'ui' => $w(4) ]),
	    self::field('more', 'More fields', $ship, [ 'type' => 'textarea', 'help' => 'A JSON object added to the AWB\'s fields, as Sameday names them (e.g. {"serviceTaxes": [..]})',
		'ui' => [ 'rows' => 2, 'expression' => true, 'code' => true, 'advanced' => true, 'agent' => [ 'type' => 'object' ] ] ]),
	    self::field('awbNumber', 'AWB', [ 'status', 'pdf', 'delete' ], [ 'required' => true, 'ui' => $w(4) ]),
	    self::field('format', 'Format', [ 'pdf' ], [ 'type' => 'select', 'default' => 'A6', 'choices' => [ 'A6' => 'A6 (label)', 'A4' => 'A4' ], 'ui' => [ 'width' => 4 ] ]),
	    self::field('countyId', 'County id', [ 'cities' ], [ 'help' => 'Sameday\'s; empty: all', 'ui' => $w(4) ]),
	    self::field('cityName', 'Name', [ 'cities' ], [ 'help' => 'Containing; empty: all', 'ui' => $w(4) ]),
	], self::apiFields('After https://api.sameday.ro/, e.g. api/client/awb/<awb>/status', 'A JSON object sent as form fields (as Sameday reads them); empty: none'));
    }

    protected static function resultOf($operation) {
	if ($operation === 'awb')
	    return [ 'type' => 'object', 'info' => '{ awbNumber, awbCost, parcels, pdfLink }', 'fields' => [ 'awbNumber' => [ 'type' => 'string' ], 'awbCost' => [ 'type' => 'number' ] ] ];
	if ($operation === 'estimate')
	    return [ 'type' => 'object', 'info' => '{ amount, currency, time }' ];
	if ($operation === 'pdf')
	    return [ 'type' => 'object', 'info' => '{ id, filename, size, contentType }' ];
	return null;
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	switch($operation) {
	    case 'awb':
	    case 'estimate':
		$r = $this->client->call('POST', $operation === 'awb' ? 'api/awb' : 'api/awb/estimate-cost', [], $this->shipment($text, $value));
		return [ $r, $operation === 'awb' ? 'AWB ' . ($r['awbNumber'] ?? '?') . (isset($r['awbCost']) ? ", cost {$r['awbCost']}" : '')
		    : 'cost ' . ($r['amount'] ?? '?') . ' ' . ($r['currency'] ?? '') ];
	    case 'status':
		$r = $this->client->call('GET', 'api/client/awb/' . rawurlencode($this->awb($text)) . '/status');
		return [ $r, 'AWB ' . $this->awb($text) . ': ' . (string)($r['expeditionStatus']['statusLabel'] ?? $r['expeditionStatus']['status'] ?? 'status read') ];
	    case 'pdf':
		$format = (string)$this->cfg('format', 'A6') === 'A4' ? 'A4' : 'A6';
		$file = $this->saveFile('sameday-' . $this->awb($text) . ".pdf", $this->client->file('api/awb/download/' . rawurlencode($this->awb($text)) . "/{$format}"));
		return [ $file, "{$file['filename']} (" . \util\Format::bytes($file['size']) . ')' ];
	    case 'delete':
		$r = $this->client->call('DELETE', 'api/awb/' . rawurlencode($this->awb($text)));
		return [ $r, 'AWB ' . $this->awb($text) . ' deleted' ];
	    case 'pickupPoints':
	    case 'services':
	    case 'lockers':
	    case 'cities':
		$query = $operation === 'cities' ? [ 'county' => $text('countyId'), 'name' => $text('cityName') ] : [];
		$all = [];
		for ($page = 1; ; $page++) {
		    $r = $this->client->call('GET', self::LISTS_PATHS[$operation], $query + [ 'page' => $page, 'countPerPage' => self::PAGE ]);
		    $list = array_is_list($r) ? $r : (array)($r['data'] ?? []);
		    array_push($all, ...$list);
		    if (array_is_list($r) || count($list) < self::PAGE || $page >= (int)($r['pages'] ?? 1) || count($all) >= $this->maxItems())
			break;
		}
		return $this->listResult($all, [ 'pickupPoints' => 'pickup point', 'services' => 'service', 'lockers' => 'easybox', 'cities' => 'city' ][$operation]);
	    case 'api':
		[ $method, $path, $query, $body ] = $this->apiCall($text, $value);
		return [ $this->client->call($method, $path, $query, $body), "{$method} {$path}" ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }

    protected function awb(\Closure $text) {
	$awb = $text('awbNumber');
	if (!preg_match('/^[A-Za-z0-9]{4,40}$/', $awb))
	    throw new \RuntimeException('AWB: not an AWB number');
	return $awb;
    }

    //the AWB's form fields
    protected function shipment(\Closure $text, \Closure $value) {
	$num = function($key, $label, $default = 0) use ($text) {
	    $v = str_replace([ ' ', ',' ], [ '', '.' ], $text($key));
	    if ($v === '')
		return $default;
	    if (!is_numeric($v) || (float)$v < 0)
		throw new \RuntimeException("{$label}: not a number");
	    return (float)$v;
	};
	$pickup = $text('pickupPoint');
	$contact = $text('contactPerson');
	if ($pickup === '') {
	    $points = (array)($this->client->call('GET', 'api/client/pickup-points', [ 'countPerPage' => 100 ])['data'] ?? []);
	    $point = current(array_filter($points, fn($p) => !empty($p['defaultPickupPoint']))) ?: ($points[0] ?? null);
	    if (!$point)
		throw new \RuntimeException('Pickup point: the account has none');
	    $pickup = (string)$point['id'];
	    if ($contact === '')
		$contact = (string)($point['contactPersons'][0]['id'] ?? '');
	}
	$parcels = $this->jsonOf($value, 'parcels', 'Parcels', true);
	if (!$parcels)
	    $parcels = [ [ 'weight' => $num('weight', 'Weight (kg)', 1) ] ];
	$weight = 0;
	foreach($parcels as $i => $p) {
	    if (!is_array($p) || !isset($p['weight']) || !is_numeric($p['weight']))
		throw new \RuntimeException('Parcels: each { weight, width, length, height }');
	    $weight += (float)$p['weight'];
	}
	$recipient = [ 'name' => $text('name'), 'phoneNumber' => $text('phone'), 'email' => $text('email'), 'address' => $text('address'), 'postalCode' => $text('postalCode'),
	    'personType' => $text('company') !== '' ? 1 : 0 ];
	foreach([ 'county' => 'County', 'city' => 'City' ] as $k => $label) {
	    if ($text($k) === '')
		throw new \RuntimeException("{$label}: needed");
	    $recipient[ctype_digit($text($k)) ? $k : "{$k}String"] = $text($k);
	}
	if ($text('company') !== '')
	    $recipient += [ 'companyName' => $text('company'), 'companyCui' => $text('companyCui') ];
	$form = array_filter([
	    'pickupPoint' => $pickup,
	    'contactPerson' => $contact,
	    'packageType' => isset(self::PACKAGES[(string)$this->cfg('packageType', '0')]) ? (string)$this->cfg('packageType', '0') : '0',
	    'packageNumber' => count($parcels),
	    'packageWeight' => $weight,
	    'service' => $text('service'),
	    'awbPayment' => isset(self::PAYERS[(string)$this->cfg('payer', '1')]) ? (string)$this->cfg('payer', '1') : '1',
	    'cashOnDelivery' => $num('cod', 'Cash on delivery'),
	    'insuredValue' => $num('insured', 'Insured value'),
	    'thirdPartyPickup' => 0,
	    'awbRecipient' => array_filter($recipient, fn($v) => $v !== ''),
	    'parcels' => array_values($parcels),
	    'observation' => $text('observation'),
	    'clientInternalReference' => $text('reference'),
	    'lockerLastMile' => $text('locker'),
	], fn($v) => $v !== '' && $v !== null);
	return array_replace_recursive($form, $this->jsonOf($value, 'more', 'More fields'));
    }
}
