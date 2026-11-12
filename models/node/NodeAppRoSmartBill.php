<?php

/*
    SmartBill - invoices, proformas (estimates) and payments in SmartBill Cloud (API V1; util\SmartBill), for the credential's company.
    Operations (setting `operation`):
	invoice / estimate / payment   issue a document: the body as SmartBill names it (Document: client, products, ...; companyVatCode and seriesName added from the credential and
	                               Series when it has none): { series, number, url, ... }
	reverse                        reverse (storno) an invoice: the new invoice
	cancel / restore / delete      an invoice or a proforma (Document type)
	pdf                            an invoice's or a proforma's PDF, saved in the project's Files: { id, filename, size, contentType }
	paymentStatus                  an invoice's: { invoiceTotalAmount, paidAmount, unpaidAmount }
	estimateInvoices               the invoices made from a proforma
	send                           send an invoice or a proforma by email (to, cc, bcc, subject, text; empty: the client's address, the account's template)
	series / taxes / stocks        lists: the document series, the VAT rates, the stock (of a warehouse, a product)
	api                            Call the API: any V1 call (method, path, query, body)
*/

namespace plugins\RegionalRO\models\node;

class NodeAppRoSmartBill extends NodeAppRo {
    public static $name = 'ro-smartbill';
    public static $label = 'SmartBill';
    public static $icon = 'bi-receipt-cutoff';
    public static $defaultOperation = 'invoice';
    public static $defaultVariable = 'smartbill';
    public static $capability = 'smartbill';
    public static $credentialLabel = 'SmartBill credential';

    const OPERATIONS = [
	'invoice' => 'Issue an invoice',
	'estimate' => 'Issue a proforma',
	'payment' => 'Issue a payment (receipt)',
	'reverse' => 'Reverse an invoice',
	'cancel' => 'Cancel a document',
	'restore' => 'Restore a document',
	'delete' => 'Delete a document',
	'pdf' => 'Get a document\'s PDF',
	'paymentStatus' => 'Get an invoice\'s payment status',
	'estimateInvoices' => 'List a proforma\'s invoices',
	'send' => 'Send a document by email',
	'series' => 'List document series',
	'taxes' => 'List VAT rates',
	'stocks' => 'List stocks',
	'api' => 'Call the API',
    ];
    const WRITES = [ 'invoice', 'estimate', 'payment', 'reverse', 'cancel', 'restore', 'delete', 'send', 'api' ];
    const LISTS = [ 'estimateInvoices', 'series', 'taxes', 'stocks' ];
    const CREDENTIAL_OPS = [ 'invoice', 'estimate', 'payment', 'reverse', 'cancel', 'restore', 'delete', 'pdf', 'paymentStatus', 'estimateInvoices', 'send', 'series', 'taxes', 'stocks', 'api' ];
    const ISSUE = [ 'invoice' => 'invoice/v2', 'estimate' => 'estimate/v2', 'payment' => 'payment' ];
    const DOC_TYPES = [ 'invoice' => 'Invoice', 'estimate' => 'Proforma' ];
    const SERIES_TYPES = [ '' => 'All', 'f' => 'Invoices', 'p' => 'Proformas', 'c' => 'Receipts' ];

    protected static function operationFields() {
	$doc = [ 'cancel', 'restore', 'delete', 'pdf', 'send' ];
	$one = [ 'reverse', 'cancel', 'restore', 'delete', 'pdf', 'paymentStatus', 'estimateInvoices', 'send' ];
	return array_merge([
	    self::field('document', 'Document', [ 'invoice', 'estimate', 'payment' ], [ 'type' => 'textarea', 'required' => true,
		'help' => 'A JSON object as SmartBill names it, e.g. {"client": {"name": "...", "vatCode": "...", "country": "Romania"}, "products": [{"name": "...", "quantity": 1, '
		    . '"price": 100, "measuringUnitName": "buc", "currency": "RON", "taxName": "Normala", "taxPercentage": 21}]}; companyVatCode and seriesName: from the credential and Series',
		'ui' => [ 'rows' => 6, 'expression' => true, 'code' => true, 'agent' => [ 'type' => 'object' ] ] ]),
	    self::field('docType', 'Document type', $doc, [ 'type' => 'select', 'default' => 'invoice', 'choices' => self::DOC_TYPES, 'ui' => [ 'width' => 4 ] ]),
	    self::field('series', 'Series', array_merge([ 'invoice', 'estimate', 'payment' ], $one), [ 'help' => 'As in SmartBill; issuing: empty takes the document\'s seriesName',
		'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('number', 'Number', $one, [ 'required' => true, 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('issueDate', 'Issue date', [ 'reverse' ], [ 'help' => 'YYYY-MM-DD; empty: today', 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('to', 'To', [ 'send' ], [ 'help' => 'Empty: the client\'s address on the document', 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('cc', 'Cc', [ 'send' ], [ 'help' => 'Separated by commas', 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('bcc', 'Bcc', [ 'send' ], [ 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('subject', 'Subject', [ 'send' ], [ 'help' => 'Empty: the account\'s template', 'ui' => [ 'expression' => 'text' ] ]),
	    self::field('body', 'Text', [ 'send' ], [ 'type' => 'textarea', 'help' => 'Empty: the account\'s template', 'ui' => [ 'rows' => 3, 'expression' => true ] ]),
	    self::field('seriesType', 'Series of', [ 'series' ], [ 'type' => 'select', 'default' => '', 'choices' => self::SERIES_TYPES, 'ui' => [ 'width' => 4 ] ]),
	    self::field('date', 'On date', [ 'stocks' ], [ 'help' => 'YYYY-MM-DD; empty: today', 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('warehouse', 'Warehouse', [ 'stocks' ], [ 'help' => 'Empty: all', 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('product', 'Product name', [ 'stocks' ], [ 'help' => 'Empty: all', 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('productCode', 'Product code', [ 'stocks' ], [ 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	], self::apiFields('After https://ws.smartbill.ro/SBORO/api/, e.g. invoice/paymentstatus (the query names cif itself)'));
    }

    protected static function resultOf($operation) {
	if (isset(self::ISSUE[$operation]) || $operation === 'reverse')
	    return [ 'type' => 'object', 'info' => '{ series, number, url, documentUrl, documentId, ... }', 'fields' => [
		'series' => [ 'type' => 'string' ], 'number' => [ 'type' => 'string' ], 'url' => [ 'type' => 'string', 'info' => 'the document in SmartBill' ] ] ];
	if ($operation === 'pdf')
	    return [ 'type' => 'object', 'info' => '{ id, filename, size, contentType }' ];
	return null;
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$cif = trim((string)$this->cred->cif);
	$type = isset(self::DOC_TYPES[(string)$this->cfg('docType', 'invoice')]) ? (string)$this->cfg('docType', 'invoice') : 'invoice';
	$series = fn() => $text('series') !== '' ? $text('series') : throw new \RuntimeException('Series: none given');
	$number = fn() => $text('number') !== '' ? $text('number') : throw new \RuntimeException('Number: none given');
	switch($operation) {
	    case 'invoice':
	    case 'estimate':
	    case 'payment':
		$doc = $this->jsonOf($value, 'document', 'Document');
		if (!$doc)
		    throw new \RuntimeException('Document: none given');
		$doc['companyVatCode'] ??= $cif;
		if ($text('series') !== '')
		    $doc['seriesName'] = $text('series');
		$r = $this->client->call('POST', self::ISSUE[$operation], [], $doc);
		return [ $r, self::OPERATIONS[$operation] . ': ' . trim(($r['series'] ?? '') . ' ' . ($r['number'] ?? '')) ];
	    case 'reverse':
		$r = $this->client->call('POST', 'invoice/reverse', [], [ 'companyVatCode' => $cif, 'seriesName' => $series(), 'number' => $number(),
		    'issueDate' => $this->dateOf($text, 'issueDate', 'Issue date') ]);
		return [ $r, 'reversed by ' . trim(($r['series'] ?? '') . ' ' . ($r['number'] ?? '')) ];
	    case 'cancel':
	    case 'restore':
	    case 'delete':
		$q = [ 'cif' => $cif, 'seriesname' => $series(), 'number' => $number() ];
		$r = $operation === 'delete' ? $this->client->call('DELETE', $type, $q) : $this->client->call('PUT', "{$type}/{$operation}", $q);
		return [ $r, "{$type} {$q['seriesname']} {$q['number']}: {$operation}" ];
	    case 'pdf':
		$q = [ 'cif' => $cif, 'seriesname' => $series(), 'number' => $number() ];
		$file = $this->saveFile("{$type}-{$q['seriesname']}-{$q['number']}.pdf", $this->client->file("{$type}/pdf", $q));
		return [ $file, "{$file['filename']} (" . \util\Format::bytes($file['size']) . ')' ];
	    case 'paymentStatus':
		$r = $this->client->call('GET', 'invoice/paymentstatus', [ 'cif' => $cif, 'seriesname' => $series(), 'number' => $number() ]);
		return [ $r, 'unpaid ' . ($r['unpaidAmount'] ?? '?') . ' of ' . ($r['invoiceTotalAmount'] ?? '?') ];
	    case 'estimateInvoices':
		$r = $this->client->call('GET', 'estimate/invoices', [ 'cif' => $cif, 'seriesname' => $series(), 'number' => $number() ]);
		return $this->listResult((array)($r['invoices'] ?? $r['list'] ?? []), 'invoice');
	    case 'send':
		$mail = [ 'companyVatCode' => $cif, 'seriesName' => $series(), 'number' => $number(), 'type' => $type === 'estimate' ? 'proforma' : 'factura' ];
		foreach([ 'to', 'cc', 'bcc' ] as $k)
		    if ($text($k) !== '')
			$mail[$k] = $text($k);
		if ($text('subject') !== '')
		    $mail['subject'] = base64_encode($text('subject'));
		if ($text('body') !== '')
		    $mail['bodyText'] = base64_encode($text('body'));
		$r = $this->client->call('POST', 'document/send', [], $mail);
		if ((string)($r['status']['code'] ?? $r['code'] ?? '0') !== '0')
		    throw new \RuntimeException('SmartBill did not send it: ' . (\plugins\RegionalRO\util\Rest::message($r['status'] ?? $r) ?: 'no reason given'));
		return [ $r, "{$mail['type']} {$mail['seriesName']} {$mail['number']} sent" . (isset($mail['to']) ? " to {$mail['to']}" : '') ];
	    case 'series':
		$st = (string)$this->cfg('seriesType', '');
		$r = $this->client->call('GET', 'series', [ 'cif' => $cif, 'type' => isset(self::SERIES_TYPES[$st]) && $st !== '' ? $st : null ]);
		return $this->listResult((array)($r['list'] ?? []), 'series');
	    case 'taxes':
		return $this->listResult((array)($this->client->call('GET', 'tax', [ 'cif' => $cif ])['taxes'] ?? []), 'VAT rate');
	    case 'stocks':
		$r = $this->client->call('GET', 'stocks', [ 'cif' => $cif, 'date' => $this->dateOf($text, 'date', 'On date'), 'warehouseName' => $text('warehouse'),
		    'productName' => $text('product'), 'productCode' => $text('productCode') ]);
		return $this->listResult((array)($r['list'] ?? []), 'warehouse');
	    case 'api':
		[ $method, $path, $query, $body ] = $this->apiCall($text, $value);
		$r = $this->client->call($method, $path, $query, $body);
		return [ $r, "{$method} {$path}" ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
