<?php

/*
    CLD: 2026-11-10T12:00:00+02:00: new step: ANAF e-Transport - the declarations of goods transported in Romania (RO e-Transport: the UIT code; ETRANSPORT/ws/v1; util\Anaf).
    Operations (setting `operation`):
	upload      send a declaration's XML (schema eTransport v2: a notification, a correction, a deletion, a confirmation, a vehicle change) for a CIF (upload/ETRANSP/<cif>/2):
	            { uploadId, uit (the code the transport travels with, of a new notification), date, warning, traceId }
	status      the state of an upload (stareMesaj/<id>): { state (ok | nok | in prelucrare | ...), ready, ok, errors }
	list        the declarations of a CIF in the last days, 1 to 60 (lista/<days>/<cif>): a list { items (as ANAF gives them), count, total, truncated }
    The XML: a text (an expression), or a project file ({{ file }} of another step, or its id). The CIF: the setting, else the credential's.
    Built from ANAF's specification; its answers are read as JSON or XML (Anaf::answer)
*/

namespace plugins\RegionalRO\models\node;

use plugins\RegionalRO\util\Anaf;

class NodeAppRoETransport extends NodeAppRo {
    public static $name = 'ro-etransport';
    public static $label = 'ANAF e-Transport';
    public static $icon = 'bi-truck';
    public static $defaultOperation = 'upload';
    public static $defaultVariable = 'etransport';

    const OPERATIONS = [
	'upload' => 'Upload a declaration',
	'status' => 'Get an upload\'s status',
	'list' => 'List declarations',
    ];
    const WRITES = [ 'upload' ];
    const LISTS = [ 'list' ];
    const CREDENTIAL_OPS = [ 'upload', 'status', 'list' ];
    const MAX_DAYS = 60;

    protected static function operationFields() {
	return [
	    self::field('xml', 'XML', [ 'upload' ], [ 'type' => 'textarea', 'required' => true,
		'help' => 'The declaration\'s XML (eTransport v2), or a project file: {{ file }} of another step, or its id', 'ui' => [ 'rows' => 4, 'expression' => true, 'code' => true ] ]),
	    self::field('cif', 'CIF', [ 'upload', 'list' ], [ 'help' => 'The declarant\'s; empty: the credential\'s', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('uploadId', 'Upload id', [ 'status' ], [ 'required' => true, 'help' => 'index_incarcare, from Upload', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('days', 'Days', [ 'list' ], [ 'default' => '7', 'help' => 'The last 1 to ' . self::MAX_DAYS . ' days', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	];
    }

    protected static function resultOf($operation) {
	if ($operation === 'upload')
	    return [ 'type' => 'object', 'info' => '{ uploadId, uit, date, warning, traceId }', 'fields' => [
		'uploadId' => [ 'type' => 'string', 'info' => 'for Status' ],
		'uit' => [ 'type' => 'string', 'info' => 'the UIT code (a new notification)' ],
		'warning' => [ 'type' => 'string', 'info' => 'ANAF\'s warning (atentie), if any' ],
	    ] ];
	if ($operation === 'status')
	    return [ 'type' => 'object', 'info' => '{ state, ready, ok, errors }', 'fields' => [
		'state' => [ 'type' => 'string', 'info' => 'ok | nok | in prelucrare | ...' ],
		'ready' => [ 'type' => 'boolean', 'info' => 'ANAF is done (ok or nok)' ],
		'ok' => [ 'type' => 'boolean' ],
		'errors' => [ 'type' => 'array' ],
	    ] ];
	return null;
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	switch($operation) {
	    case 'upload':
		[ $xml ] = $this->xmlOf($value);
		$cif = $this->cifOf($text);
		$a = Anaf::answer($this->anaf->call('etransport', 'POST', "upload/ETRANSP/{$cif}/2", [], $xml)['body']);
		if ((string)($a['ExecutionStatus'] ?? '') !== '0' || empty($a['index_incarcare']))
		    throw new \RuntimeException('ANAF refused the declaration: ' . (Anaf::message($a) ?: 'no upload id in its answer'));
		$result = [ 'uploadId' => (string)$a['index_incarcare'], 'uit' => (string)($a['UIT'] ?? ''), 'date' => Anaf::stamp($a['dateResponse'] ?? ''),
		    'warning' => (string)($a['atentie'] ?? ''), 'traceId' => (string)($a['trace_id'] ?? '') ];
		return [ $result, "CIF {$cif}: upload id {$result['uploadId']}" . ($result['uit'] !== '' ? ", UIT {$result['uit']}" : '') ];

	    case 'status':
		$id = $text('uploadId');
		if (!preg_match('/^\d{1,20}$/', $id))
		    throw new \RuntimeException('Upload id: a number (index_incarcare)');
		$a = Anaf::answer($this->anaf->call('etransport', 'GET', "stareMesaj/{$id}")['body']);
		$state = (string)($a['stare'] ?? '');
		if ($state === '')
		    throw new \RuntimeException("upload {$id}: " . (Anaf::message($a) ?: 'ANAF\'s answer has no state'));
		return [ [ 'state' => $state, 'ready' => in_array($state, [ 'ok', 'nok' ], true), 'ok' => $state === 'ok', 'errors' => self::errorsOf($a) ], "upload {$id}: {$state}" ];

	    case 'list':
		$cif = $this->cifOf($text);
		$days = $this->intOf($text, 'days', 'Days', 1, self::MAX_DAYS, 7);
		$a = Anaf::answer($this->anaf->call('etransport', 'GET', "lista/{$days}/{$cif}")['body']);
		if (!isset($a['mesaje'])) {
		    $m = Anaf::message($a);
		    if (stripos($m, 'nu exista') === false)
			throw new \RuntimeException("CIF {$cif}: " . ($m ?: 'ANAF\'s answer is not a list'));
		    $a['mesaje'] = [];
		}
		[ $result, $summary ] = $this->listResult((array)$a['mesaje'], 'declaration');
		return [ $result, "CIF {$cif}, {$days} days: {$summary}" ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }

    //the errors of an answer, as texts (JSON: [ { errorMessage } ]; XML: Anaf::answer's)
    protected static function errorsOf(array $a) {
	return array_values(array_filter(array_map(fn($e) => is_array($e) ? (string)($e['errorMessage'] ?? $e['message'] ?? '') : (string)$e, (array)($a['Errors'] ?? [])), 'strlen'));
    }
}
