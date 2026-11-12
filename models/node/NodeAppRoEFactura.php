<?php

/*
    ANAF e-Factura - RO e-Factura, the national system of electronic invoices (FCTEL/rest; util\Anaf). Operations (setting `operation`):
	upload      send an invoice's XML (UBL 2.1 / CIUS-RO; a credit note, CII, or a buyer's message) for a CIF (upload?standard=&cif=[&extern=DA][&autofactura=DA]):
	            { uploadId, date }; ANAF checks it afterwards (status)
	status      the state of an upload (stareMesaj?id_incarcare=): { state (ok | nok | in prelucrare | XML cu erori nepreluat de sistem), ready (ANAF is done), ok, downloadId
	            (id_descarcare: the response to download), errors }
	messages    the messages of a CIF in the last days, 1 to 60 (listaMesajePaginatieFactura: invoices sent, received, errors, buyers' messages): a list { items: { id (to download),
	            uploadId, cif, created, type, details }, count, total, truncated }
	download    a message's ZIP (descarcare?id=): its files (the invoice's or the errors' XML, ANAF's signature) saved in the project's Files: { files, invoice, signature, xml (the
	            first XML that is not the signature, as text, up to 1 MB) }
	validate    check an invoice's XML against e-Factura's rules, without sending it (public: validare/FACT1 | FCN): { valid, state, messages, traceId }; not valid is a result,
	            not an error
	pdf         the PDF of an invoice's XML (public: transformare/FACT1 | FCN, checked first or not): saved in the project's Files: { id, filename, size, contentType }
    The XML: a text (an expression), or a project file ({{ file }} of another step, or its id). The CIF: the setting, else the credential's. Only upload, status, messages and
    download need the ANAF credential
*/

namespace plugins\RegionalRO\models\node;

use plugins\RegionalRO\util\Anaf;

class NodeAppRoEFactura extends NodeAppRo {
    public static $name = 'ro-efactura';
    public static $label = 'ANAF e-Factura';
    public static $icon = 'bi-receipt';
    public static $defaultOperation = 'upload';
    public static $defaultVariable = 'efactura';

    const OPERATIONS = [
	'upload' => 'Upload an invoice',
	'status' => 'Get an upload\'s status',
	'messages' => 'List messages',
	'download' => 'Download a message',
	'validate' => 'Validate an invoice (without sending)',
	'pdf' => 'Make an invoice\'s PDF',
    ];
    const WRITES = [ 'upload' ];
    const LISTS = [ 'messages' ];
    const CREDENTIAL_OPS = [ 'upload', 'status', 'messages', 'download' ];
    //the document: upload's standard, validate's and pdf's type (null: they cannot)
    const DOCUMENTS = [
	'invoice' => [ 'Invoice (UBL)', 'UBL', 'FACT1' ],
	'credit' => [ 'Credit note (UBL)', 'CN', 'FCN' ],
	'cii' => [ 'Invoice (CII)', 'CII', null ],
	'message' => [ 'Buyer\'s message (RASP)', 'RASP', null ],
    ];
    const FILTERS = [ '' => 'All', 'T' => 'Invoices sent', 'P' => 'Invoices received', 'E' => 'Errors', 'R' => 'Buyers\' messages' ];
    const MAX_DAYS = 60;
    const MAX_XML_TEXT = 1024 * 1024;
    const MAX_PAGES = 100;

    protected static function operationFields() {
	$docs = array_map(fn($d) => $d[0], self::DOCUMENTS);
	return [
	    self::field('xml', 'XML', [ 'upload', 'validate', 'pdf' ], [ 'type' => 'textarea', 'required' => true,
		'help' => 'The invoice\'s XML (UBL 2.1, CIUS-RO), or a project file: {{ file }} of another step, or its id', 'ui' => [ 'rows' => 4, 'expression' => true, 'code' => true ] ]),
	    self::field('document', 'Document', [ 'upload', 'validate', 'pdf' ], [ 'type' => 'select', 'default' => 'invoice', 'choices' => $docs,
		'help' => 'Validate and PDF: invoices and credit notes in UBL only', 'ui' => [ 'width' => 6 ] ]),
	    self::field('cif', 'CIF', [ 'upload', 'messages' ], [ 'help' => 'The seller\'s (upload) or the company\'s (messages); empty: the credential\'s',
		'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('foreignBuyer', 'Buyer outside Romania', [ 'upload' ], [ 'type' => 'switch', 'default' => false, 'help' => 'extern=DA', 'ui' => [ 'width' => 6 ] ]),
	    self::field('selfInvoice', 'Self-invoice', [ 'upload' ], [ 'type' => 'switch', 'default' => false, 'help' => 'Issued by the buyer for the seller (autofactura=DA)',
		'ui' => [ 'width' => 6 ] ]),
	    self::field('uploadId', 'Upload id', [ 'status' ], [ 'required' => true, 'help' => 'index_incarcare, from Upload', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('days', 'Days', [ 'messages' ], [ 'default' => '7', 'help' => 'The last 1 to ' . self::MAX_DAYS . ' days', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('filter', 'Messages', [ 'messages' ], [ 'type' => 'select', 'default' => '', 'choices' => self::FILTERS, 'ui' => [ 'width' => 6 ] ]),
	    self::field('downloadId', 'Download id', [ 'download' ], [ 'required' => true, 'help' => 'id_descarcare (Status), or a message\'s id (List messages)',
		'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('checkFirst', 'Validate first', [ 'pdf' ], [ 'type' => 'switch', 'default' => true, 'help' => 'Off: the PDF of an XML that may not be valid',
		'ui' => [ 'width' => 6 ] ]),
	    self::field('filename', 'File name', [ 'download', 'pdf' ], [ 'help' => 'Empty: from ANAF\'s names (download), invoice-<date>.pdf (pdf)',
		'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	];
    }

    protected static function resultOf($operation) {
	$file = [ 'type' => 'object', 'info' => '{ id, filename, size, contentType }' ];
	switch($operation) {
	    case 'upload':
		return [ 'type' => 'object', 'info' => '{ uploadId, date }', 'fields' => [ 'uploadId' => [ 'type' => 'string', 'info' => 'for Status' ], 'date' => [ 'type' => 'string' ] ] ];
	    case 'status':
		return [ 'type' => 'object', 'info' => '{ state, ready, ok, downloadId, errors }', 'fields' => [
		    'state' => [ 'type' => 'string', 'info' => 'ok | nok | in prelucrare | XML cu erori nepreluat de sistem' ],
		    'ready' => [ 'type' => 'boolean', 'info' => 'ANAF is done (ok or nok)' ],
		    'ok' => [ 'type' => 'boolean', 'info' => 'the invoice was accepted' ],
		    'downloadId' => [ 'type' => 'string', 'info' => 'for Download: the signed invoice, or its errors' ],
		    'errors' => [ 'type' => 'array' ],
		] ];
	    case 'download':
		return [ 'type' => 'object', 'info' => '{ files, invoice, signature, xml }', 'fields' => [
		    'files' => [ 'type' => 'array', 'items' => $file ], 'invoice' => $file, 'signature' => $file,
		    'xml' => [ 'type' => 'string', 'info' => 'the invoice\'s (or the errors\') XML, up to 1 MB' ],
		] ];
	    case 'validate':
		return [ 'type' => 'object', 'info' => '{ valid, state, messages, traceId }', 'fields' => [
		    'valid' => [ 'type' => 'boolean' ], 'state' => [ 'type' => 'string', 'info' => 'ok | nok' ], 'messages' => [ 'type' => 'array', 'info' => 'what is wrong' ],
		] ];
	    case 'pdf':
		return $file;
	}
	return null;
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$doc = self::DOCUMENTS[(string)$this->cfg('document', 'invoice')] ?? self::DOCUMENTS['invoice'];
	switch($operation) {
	    case 'upload':
		[ $xml ] = $this->xmlOf($value);
		$cif = $this->cifOf($text);
		$r = $this->anaf->call('efactura', 'POST', 'upload', [ 'standard' => $doc[1], 'cif' => $cif,
		    'extern' => $this->cfg('foreignBuyer') ? 'DA' : null, 'autofactura' => $this->cfg('selfInvoice') ? 'DA' : null ], $xml);
		$a = Anaf::answer($r['body']);
		if ((string)($a['ExecutionStatus'] ?? '') !== '0' || empty($a['index_incarcare']))
		    throw new \RuntimeException('ANAF refused the upload: ' . (Anaf::message($a) ?: 'no upload id in its answer'));
		return [ [ 'uploadId' => (string)$a['index_incarcare'], 'date' => Anaf::stamp($a['dateResponse'] ?? '') ], "CIF {$cif}: upload id {$a['index_incarcare']}" ];

	    case 'status':
		$id = $text('uploadId');
		if (!preg_match('/^\d{1,20}$/', $id))
		    throw new \RuntimeException('Upload id: a number (index_incarcare)');
		$a = Anaf::answer($this->anaf->call('efactura', 'GET', 'stareMesaj', [ 'id_incarcare' => $id ])['body']);
		$state = (string)($a['stare'] ?? '');
		if ($state === '')
		    throw new \RuntimeException('upload ' . $id . ': ' . (Anaf::message($a) ?: 'ANAF\'s answer has no state'));
		$result = [ 'state' => $state, 'ready' => in_array($state, [ 'ok', 'nok' ], true), 'ok' => $state === 'ok',
		    'downloadId' => (string)($a['id_descarcare'] ?? ''), 'errors' => array_values((array)($a['Errors'] ?? [])) ];
		return [ $result, "upload {$id}: {$state}" ];

	    case 'messages':
		return $this->messages($text);

	    case 'download':
		return $this->download($text);

	    case 'validate':
		if ($doc[2] === null)
		    throw new \RuntimeException('Validate: only invoices and credit notes in UBL');
		[ $xml ] = $this->xmlOf($value);
		$a = Anaf::answer(Anaf::publicEfactura('validare/' . $doc[2], $xml, $this->start, $this->timeout)['body']);
		$state = (string)($a['stare'] ?? '');
		if ($state === '')
		    throw new \RuntimeException(Anaf::message($a) ?: 'ANAF\'s answer has no state');
		$messages = array_values(array_filter(array_map(fn($m) => is_array($m) ? (string)($m['message'] ?? '') : (string)$m, (array)($a['Messages'] ?? [])), 'strlen'));
		return [ [ 'valid' => $state === 'ok', 'state' => $state, 'messages' => $messages, 'traceId' => (string)($a['trace_id'] ?? '') ],
		    $state === 'ok' ? 'valid' : 'not valid: ' . \util\Format::plural(count($messages), 'message') ];

	    case 'pdf':
		if ($doc[2] === null)
		    throw new \RuntimeException('PDF: only invoices and credit notes in UBL');
		[ $xml, $from ] = $this->xmlOf($value);
		$r = Anaf::publicEfactura('transformare/' . $doc[2] . ($this->cfg('checkFirst', true) ? '' : '/DA'), $xml, $this->start, $this->timeout);
		if (!str_starts_with((string)$r['body'], '%PDF'))
		    throw new \RuntimeException('no PDF: ' . (Anaf::message(Anaf::answer($r['body'])) ?: 'ANAF\'s answer is not a PDF'));
		$name = $text('filename') ?: ($from !== '' ? pathinfo($from, PATHINFO_FILENAME) : 'invoice-' . date('Ymd-His')) . '.pdf';
		if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'pdf')
		    $name .= '.pdf';
		$file = $this->saveFile($name, $r['body']);
		return [ $file, "{$file['filename']} (" . \util\Format::bytes($file['size']) . ')' ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }

    //the messages of the last days, page by page (ANAF: up to 500 a page)
    protected function messages(\Closure $text) {
	$cif = $this->cifOf($text);
	$days = $this->intOf($text, 'days', 'Days', 1, self::MAX_DAYS, 7);
	$filter = isset(self::FILTERS[(string)$this->cfg('filter', '')]) ? (string)$this->cfg('filter', '') : '';
	$end = (int)(microtime(true) * 1000);
	$startTime = $end - $days * 86400000;
	$max = $this->maxItems();
	$items = [];
	$total = 0;
	for ($page = 1; $page <= self::MAX_PAGES; $page++) {
	    $a = Anaf::answer($this->anaf->call('efactura', 'GET', 'listaMesajePaginatieFactura', [ 'startTime' => $startTime, 'endTime' => $end, 'cif' => $cif,
		'pagina' => $page, 'filtru' => $filter ?: null ])['body']);
	    if (!isset($a['mesaje'])) {
		$m = Anaf::message($a);
		//none in the interval is an empty list
		if ($page == 1 && stripos($m, 'nu exista mesaje') !== false)
		    break;
		throw new \RuntimeException("CIF {$cif}: " . ($m ?: 'ANAF\'s answer is not a list of messages'));
	    }
	    foreach((array)$a['mesaje'] as $msg) {
		if (count($items) < $max)
		    $items[] = [ 'id' => (string)($msg['id'] ?? ''), 'uploadId' => (string)($msg['id_solicitare'] ?? ''), 'cif' => (string)($msg['cif'] ?? ''),
			'created' => Anaf::stamp($msg['data_creare'] ?? ''), 'type' => (string)($msg['tip'] ?? ''), 'details' => (string)($msg['detalii'] ?? '') ];
	    }
	    $total = (int)($a['numar_total_inregistrari'] ?? count($items));
	    $pages = (int)($a['numar_total_pagini'] ?? 1);
	    if ($page >= $pages || count($items) >= $max)
		break;
	}
	$total = max($total, count($items));
	$truncated = $total > count($items);
	return [ [ 'items' => $items, 'count' => count($items), 'total' => $total, 'truncated' => $truncated ],
	    "CIF {$cif}, {$days} days: " . \util\Format::plural(count($items), 'message') . ($truncated ? " of {$total}" : '') ];
    }

    //a message's ZIP: its XML files saved
    protected function download(\Closure $text) {
	$id = $text('downloadId');
	if (!preg_match('/^\d{1,20}$/', $id))
	    throw new \RuntimeException('Download id: a number (id_descarcare, or a message\'s id)');
	$r = $this->anaf->call('efactura', 'GET', 'descarcare', [ 'id' => $id ]);
	if (!str_starts_with((string)$r['body'], "PK"))
	    throw new \RuntimeException("message {$id}: " . (Anaf::message(Anaf::answer($r['body'])) ?: 'ANAF\'s answer is not a ZIP'));
	$tmp = tempnam(sys_get_temp_dir(), 'efact');
	file_put_contents($tmp, $r['body']);
	$zip = new \ZipArchive();
	$opened = false;
	try {
	    if (!($opened = $zip->open($tmp) === true))
		throw new \RuntimeException("message {$id}: the ZIP cannot be read");
	    $base = $text('filename');
	    $files = [];
	    $invoice = $signature = null;
	    $xml = null;
	    for ($i = 0; $i < $zip->numFiles && $i < 20; $i++) {
		$entry = basename((string)$zip->getNameIndex($i));
		if ($entry === '' || strtolower(pathinfo($entry, PATHINFO_EXTENSION)) !== 'xml')
		    continue;
		$content = (string)$zip->getFromIndex($i);
		$isSignature = str_starts_with(strtolower($entry), 'semnatura');
		$name = $base !== '' ? pathinfo($base, PATHINFO_FILENAME) . ($isSignature ? '-semnatura' : '') . '.xml' : $entry;
		$file = $this->saveFile($name, $content);
		$files[] = $file;
		if ($isSignature)
		    $signature ??= $file;
		else {
		    $invoice ??= $file;
		    if ($xml === null && strlen($content) <= self::MAX_XML_TEXT)
			$xml = $content;
		}
	    }
	} finally {
	    if ($opened)
		$zip->close();
	    @unlink($tmp);
	}
	if (!$files)
	    throw new \RuntimeException("message {$id}: the ZIP has no XML");
	return [ [ 'files' => $files, 'invoice' => $invoice, 'signature' => $signature, 'xml' => $xml ], "message {$id}: " . \util\Format::plural(count($files), 'file') . ' saved' ];
    }
}
