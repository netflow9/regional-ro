<?php

/*
    CLD: 2026-11-10T16:00:00+02:00: new step: FGO - invoices in FGO (API v7; util\Fgo), for the credential's company. Operations (setting `operation`):
	invoice       issue an invoice (factura/emitere): the body as FGO names it (Document: Client, Continut, ...; Serie from Series, Valuta RON and TipFactura Factura when it has
	              none): { Numar, Serie, Link (the PDF), LinkPlata }
	print         an invoice's PDF (factura/print): its link, and the PDF saved in the project's Files (Save the PDF)
	status        an invoice's value and what was paid (factura/getstatus)
	cancel / delete / reverse   an invoice (anulare, stergere, stornare: the credit note's series, number, date optional)
	awb           attach a courier's AWB to an invoice (factura/awb)
	payment       record a payment of an invoice (factura/incasare; FGO: PREMIUM and ENTERPRISE): amount, type (nomenclature tipincasare), date
	nomenclature  one of FGO's lists (tara, judet, tva, banca, tipincasare, tipfactura, tipclient, localitati of a county)
	articles / warehouses       the articles (ENTERPRISE), the warehouses
	api           Call the API: any POST (the path, the body; CodUnic, Hash and PlatformaUrl added: util\Fgo::call)
*/

namespace plugins\RegionalRO\models\node;

class NodeAppRoFgo extends NodeAppRo {
    public static $name = 'ro-fgo';
    public static $label = 'FGO';
    public static $icon = 'bi-receipt-cutoff';
    public static $defaultOperation = 'invoice';
    public static $defaultVariable = 'fgo';
    public static $capability = 'fgo';
    public static $credentialLabel = 'FGO credential';

    const OPERATIONS = [
	'invoice' => 'Issue an invoice',
	'print' => 'Get an invoice\'s PDF',
	'status' => 'Get an invoice\'s status',
	'cancel' => 'Cancel an invoice',
	'delete' => 'Delete an invoice',
	'reverse' => 'Reverse an invoice',
	'awb' => 'Attach an AWB',
	'payment' => 'Record a payment',
	'nomenclature' => 'List a nomenclature',
	'articles' => 'List articles',
	'warehouses' => 'List warehouses',
	'api' => 'Call the API',
    ];
    const WRITES = [ 'invoice', 'cancel', 'delete', 'reverse', 'awb', 'payment', 'api' ];
    const LISTS = [ 'nomenclature', 'articles', 'warehouses' ];
    const CREDENTIAL_OPS = [ 'invoice', 'print', 'status', 'cancel', 'delete', 'reverse', 'awb', 'payment', 'nomenclature', 'articles', 'warehouses', 'api' ];
    const PATHS = [ 'status' => 'factura/getstatus', 'cancel' => 'factura/anulare', 'delete' => 'factura/stergere' ];
    const NOMENCLATURES = [ 'tara' => 'Countries', 'judet' => 'Counties', 'localitati' => 'Localities of a county', 'tva' => 'VAT rates', 'banca' => 'Banks',
	'tipincasare' => 'Payment types', 'tipfactura' => 'Invoice types', 'tipclient' => 'Client types' ];
    //FGO's articles: a page (its largest)
    const PAGE = 200;

    protected static function operationFields() {
	$one = [ 'print', 'status', 'cancel', 'delete', 'reverse', 'awb', 'payment' ];
	return array_merge([
	    self::field('document', 'Document', [ 'invoice' ], [ 'type' => 'textarea', 'required' => true,
		'help' => 'A JSON object as FGO names it, e.g. {"Client": {"Denumire": "...", "Tip": "PJ", "CodUnic": "...", "Tara": "RO", "Judet": "Bucuresti"}, "Continut": '
		    . '[{"Denumire": "...", "NrProduse": 1, "UM": "BUC", "CotaTVA": 21, "PretUnitar": 100}]}; Serie: from Series; Valuta RON, TipFactura Factura when not given',
		'ui' => [ 'rows' => 6, 'expression' => true, 'code' => true, 'agent' => [ 'type' => 'object' ] ] ]),
	    self::field('series', 'Series', array_merge([ 'invoice' ], $one), [ 'help' => 'As in FGO (Setari > Serii documente); issuing: empty takes the document\'s Serie',
		'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('number', 'Number', $one, [ 'required' => true, 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('savePdf', 'Save the PDF', [ 'print' ], [ 'type' => 'switch', 'default' => true, 'help' => 'In the project\'s Files; off: only its link', 'ui' => [ 'width' => 4 ] ]),
	    self::field('stornoSeries', 'Credit note series', [ 'reverse' ], [ 'help' => 'Empty: FGO\'s', 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('stornoNumber', 'Credit note number', [ 'reverse' ], [ 'help' => 'Empty: the next', 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('date', 'Date', [ 'reverse', 'payment' ], [ 'help' => 'YYYY-MM-DD; empty: today', 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('awbNumber', 'AWB', [ 'awb' ], [ 'required' => true, 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('amount', 'Amount', [ 'payment' ], [ 'required' => true, 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('paymentType', 'Payment type', [ 'payment' ], [ 'required' => true, 'default' => 'Ordin de plata', 'help' => 'From the nomenclature tipincasare',
		'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('account', 'Collection account', [ 'payment' ], [ 'help' => 'Empty: FGO\'s', 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('nomenclature', 'Nomenclature', [ 'nomenclature' ], [ 'type' => 'select', 'default' => 'tva', 'choices' => self::NOMENCLATURES, 'ui' => [ 'width' => 4 ] ]),
	    self::field('county', 'County code', [ 'nomenclature' ], [ 'help' => 'Of Localities (e.g. B, CJ)', 'ui' => [ 'expression' => 'text', 'width' => 4,
		'showIf' => [ 'key' => 'nomenclature', 'operator' => 'eq', 'value' => 'localitati' ] ] ]),
	], self::apiFields('After https://api.fgo.ro/v1/, e.g. factura/listfacturiasociate (CodUnic, Hash, PlatformaUrl are added)', 'A JSON object sent as the body'));
    }

    protected static function resultOf($operation) {
	if ($operation === 'invoice')
	    return [ 'type' => 'object', 'info' => '{ Numar, Serie, Link, LinkPlata }', 'fields' => [ 'Numar' => [ 'type' => 'string' ], 'Serie' => [ 'type' => 'string' ],
		'Link' => [ 'type' => 'string', 'info' => 'the PDF' ] ] ];
	if ($operation === 'print')
	    return [ 'type' => 'object', 'info' => '{ link, file }', 'fields' => [ 'link' => [ 'type' => 'string' ], 'file' => [ 'type' => 'object', 'info' => '{ id, filename, size, contentType }' ] ] ];
	return null;
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$invoice = function() use ($text) {
	    if ($text('series') === '' || $text('number') === '')
		throw new \RuntimeException('Series and Number: both needed');
	    return [ 'Serie' => $text('series'), 'Numar' => $text('number') ];
	};
	switch($operation) {
	    case 'invoice':
		$doc = $this->jsonOf($value, 'document', 'Document');
		if (trim((string)($doc['Client']['Denumire'] ?? '')) === '')
		    throw new \RuntimeException('Document: Client.Denumire (the client\'s name) is needed');
		if ($text('series') !== '')
		    $doc['Serie'] = $text('series');
		$doc += [ 'Valuta' => 'RON', 'TipFactura' => 'Factura' ];
		$f = (array)($this->client->call('factura/emitere', $doc)['Factura'] ?? []);
		return [ $f, 'invoice ' . trim(($f['Serie'] ?? '') . ' ' . ($f['Numar'] ?? '')) ];
	    case 'print':
		$inv = $invoice();
		$link = (string)($this->client->call('factura/print', $inv)['Factura']['Link'] ?? '');
		if ($link === '')
		    throw new \RuntimeException('FGO gave no link to the PDF');
		$file = null;
		if ($this->cfg('savePdf', true)) {
		    $r = \plugins\RegionalRO\util\Rest::send('GET', $link, [ 'redirects' => 3 ], $this->start, $this->timeout);
		    if ($r['status'] >= 400 || !str_starts_with((string)$r['body'], '%PDF'))
			throw new \RuntimeException("the PDF could not be read (HTTP {$r['status']})");
		    $file = $this->saveFile("factura-{$inv['Serie']}-{$inv['Numar']}.pdf", $r['body']);
		}
		return [ [ 'link' => $link, 'file' => $file ], "invoice {$inv['Serie']} {$inv['Numar']}" . ($file ? ": {$file['filename']}" : ": {$link}") ];
	    case 'status':
	    case 'cancel':
	    case 'delete':
		$inv = $invoice();
		$r = $this->client->call(self::PATHS[$operation], $inv);
		return [ $operation === 'status' ? (array)($r['Factura'] ?? $r) : $r, "invoice {$inv['Serie']} {$inv['Numar']}: " . ($operation === 'status'
		    ? 'paid ' . ($r['Factura']['ValoareAchitata'] ?? '?') . ' of ' . ($r['Factura']['Valoare'] ?? '?') : (($r['Message'] ?? '') ?: $operation)) ];
	    case 'reverse':
		$inv = $invoice();
		$r = $this->client->call('factura/stornare', $inv + array_filter([ 'SerieStorno' => $text('stornoSeries'), 'NumarStorno' => $text('stornoNumber'),
		    'DataEmitere' => $text('date') !== '' ? $this->dateOf($text) : null ], fn($v) => $v !== null && $v !== ''));
		$f = (array)($r['Factura'] ?? $r);
		return [ $f, "invoice {$inv['Serie']} {$inv['Numar']} reversed" . (isset($f['Numar']) ? " by {$f['Serie']} {$f['Numar']}" : '') ];
	    case 'awb':
		$inv = $invoice();
		$r = $this->client->call('factura/awb', $inv + [ 'AWB' => $text('awbNumber') ]);
		return [ $r, "invoice {$inv['Serie']} {$inv['Numar']}: AWB " . $text('awbNumber') ];
	    case 'payment':
		$inv = $invoice();
		$amount = str_replace([ ' ', ',' ], [ '', '.' ], $text('amount'));
		if (!is_numeric($amount))
		    throw new \RuntimeException('Amount: not a number');
		$r = $this->client->call('factura/incasare', array_filter([ 'NumarFactura' => $inv['Numar'], 'SerieFactura' => $inv['Serie'], 'TipIncasare' => $text('paymentType'),
		    'SumaIncasata' => round((float)$amount, 2), 'DataIncasare' => $this->dateOf($text) . ' ' . date('H:i:s'), 'ContIncasare' => $text('account') ], fn($v) => $v !== ''));
		return [ $r, "invoice {$inv['Serie']} {$inv['Numar']}: {$amount} paid" ];
	    case 'nomenclature':
		$type = isset(self::NOMENCLATURES[(string)$this->cfg('nomenclature', 'tva')]) ? (string)$this->cfg('nomenclature', 'tva') : 'tva';
		if ($type === 'localitati') {
		    if ($text('county') === '')
			throw new \RuntimeException('County code: needed for the localities');
		    $type .= '?judet=' . rawurlencode($text('county'));
		}
		return $this->listResult((array)($this->client->nomenclature($type)['List'] ?? []), 'value');
	    case 'articles':
		$all = [];
		for ($page = 1; count($all) < $this->maxItems(); $page++) {
		    $res = (array)($this->client->call('articol/list', [ 'NrPagina' => $page, 'NrArticole' => self::PAGE ])['Result'] ?? []);
		    $list = (array)($res['List'] ?? []);
		    array_push($all, ...$list);
		    if (count($list) < self::PAGE || count($all) >= (int)($res['Total'] ?? 0))
			break;
		    //FGO: 1 request in 5 seconds for the articles
		    sleep(5);
		}
		return $this->listResult($all, 'article');
	    case 'warehouses':
		return $this->listResult((array)($this->client->call('articol/gestiune', [])['Result']['List'] ?? []), 'warehouse');
	    case 'api':
		[ , $path, , $body ] = $this->apiCall($text, $value);
		if ($body !== null && (!is_array($body) || array_is_list($body)))
		    throw new \RuntimeException('Body: a JSON object');
		return [ $this->client->call($path, (array)$body), "POST {$path}" ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
