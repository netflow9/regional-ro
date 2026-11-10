<?php

/*
    CLD: 2026-11-10T12:00:00+02:00: new class: the base of the RegionalRO steps (not registered itself), as the core's integration bases (models\node\NodeAppNamecheap):
	- the shape: one input, `success` (+ `error` when On error is "Continue on the `error` output"); in the picker's group Regional
	- the settings: the ANAF credential (for the operations of CREDENTIAL_OPS; none: the step has no credential), the operation (static::OPERATIONS; one: not shown), the operation's
	    own settings (operationFields(): each shown for its operations), At most (the lists: LISTS), timeout, result variable, on error
	- execute(): runOperation() (the subclass); the result in the variable, logged as "<label> <operation>: <summary> (<n> ms)"; a \RuntimeException (ANAF's or BNR's errors,
	    no connection, a value that cannot be used) follows On error; other errors fail the step
	- xmlOf(): an XML setting, as text or as a project file ({{ file }} of another step, or its id); saveFile(): a file saved in the project's Files
    CLD: 2026-11-10T16:00:00+02:00: the credential of $capability (default ANAF's: its client util\Anaf in $anaf; another's: $cred->client($timeout) in $client), named $credentialLabel; jsonOf(): a
	JSON setting (an object or a list, or its text); the Call the API operation of the services' steps (apiFields(), apiCall())
*/

namespace plugins\RegionalRO\models\node;

abstract class NodeAppRo extends \models\wf\WfNode {
    use \models\node\AgentToolTrait;

    public static $type = 'app';
    public static $icon = 'bi-flag';
    public static $color = 'var(--wf-color-app)';
    public static $category = 'regional';
    public static $inputs = 1;
    public static $outputs = 1;
    public static $outPorts = [ 'field' => 'onError', 'values' => [ 'output' => [ 'success', 'error' ] ] ];

    public static $defaultOperation = '';
    public static $defaultVariable = 'result';

    const OPERATIONS = [];
    const LISTS = [];
    //the operations that need the ANAF credential; [] : the step has none
    const CREDENTIAL_OPS = [];
    const MAX_ITEMS = 5000;
    const MAX_TIMEOUT = 600;
    const DEFAULT_TIMEOUT = 60;
    //the largest XML read from a setting or a file
    const MAX_XML = 20 * 1024 * 1024;

    //CLD: 2026-11-10T16:00:00+02:00: the capability of the step's credential, its label
    public static $capability = 'anaf';
    public static $credentialLabel = 'ANAF credential';

    //the run: the credential (null when the operation needs none), its client (ANAF's: $anaf; another's: $client), the start and the timeout
    protected $cred = null;
    protected $anaf = null;
    protected $client = null;
    protected $start = 0;
    protected $timeout = 60;

    //the operation: [ result, summary for the log ]; $text($key): a setting as trimmed text, $value($key): its value ({{ }} evaluated)
    abstract protected function runOperation($operation, \Closure $text, \Closure $value);

    protected static function operationFields() {
	return [];
    }

    // ------------------------------------------------------------------------------------
    //settings

    public static function credentialChoices() {
	$choices = [];
	if (\V::app()->config('app.mode') == 'web' && !\V::app()->user->isGuest && !empty(\V::app()->user->crtProject))
	    foreach(\models\Credential::choicesFor(static::$capability, \V::app()->config('rt.account')->_id ?? null, \V::app()->user->crtProject) as $c)
		$choices[$c['value']] = $c['label'];
	return $choices;
    }

    public static function configSchema() {
	$fields = [];
	if (static::CREDENTIAL_OPS) {
	    $all = count(static::CREDENTIAL_OPS) == count(static::OPERATIONS);
	    $fields[] = [
		'key' => 'credential',
		'type' => 'select',
		'label' => static::$credentialLabel,
		'choices' => static::credentialChoices(),
		'help' => 'A credential of the project',
		'default' => '',
	    ] + ($all ? [ 'required' => true ] : [ 'requiredIf' => [ 'if' => 'operation', 'in' => static::CREDENTIAL_OPS ],
		'ui' => [ 'showIf' => [ 'key' => 'operation', 'operator' => 'in', 'value' => static::CREDENTIAL_OPS ] ] ]);
	}
	if (count(static::OPERATIONS) > 1)
	    $fields[] = [
		'key' => 'operation',
		'type' => 'select',
		'label' => 'Operation',
		'required' => true,
		'default' => static::$defaultOperation,
		'choices' => static::OPERATIONS,
	    ];
	foreach(static::operationFields() as $f) {
	    $ops = $f['ops'] ?? null;
	    unset($f['ops']);
	    $f += [ 'default' => '' ];
	    if ($ops !== null) {
		$f['ui'] = ($f['ui'] ?? []) + [ 'showIf' => [ 'key' => 'operation', 'operator' => 'in', 'value' => $ops ] ];
		if (!empty($f['required'])) {
		    unset($f['required']);
		    $f['requiredIf'] = [ 'if' => 'operation', 'in' => $ops ];
		}
	    }
	    $fields[] = $f;
	}
	if (static::LISTS)
	    $fields[] = [
		'key' => 'maxItems',
		'type' => 'number',
		'label' => 'At most (items)',
		'required' => true,
		'default' => 1000,
		'help' => 'Of a list: more are left out (truncated)',
		'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => self::MAX_ITEMS, 'skipEmpty' => true, 'message' => 'from 1 to ' . self::MAX_ITEMS ] ],
		'ui' => [ 'limit' => true, 'step' => 1, 'showIf' => [ 'key' => 'operation', 'operator' => 'in', 'value' => static::LISTS ] ],
	    ];
	$fields[] = [
	    'key' => 'timeout',
	    'type' => 'number',
	    'label' => 'Timeout (s)',
	    'required' => true,
	    'default' => static::DEFAULT_TIMEOUT,
	    'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => self::MAX_TIMEOUT, 'skipEmpty' => true, 'message' => 'from 1 to ' . self::MAX_TIMEOUT ] ],
	    'ui' => [ 'step' => 1 ],
	];
	$fields[] = [
	    'key' => 'variable',
	    'type' => 'text',
	    'label' => 'Result variable',
	    'required' => true,
	    'default' => static::$defaultVariable,
	    'help' => static::LISTS ? 'A list: { items, count, total, truncated }; otherwise what the operation gives' : 'What the operation gives',
	    'validate' => [ [ 'identifier', 'skipEmpty' => true, 'message' => 'Use letters, digits or underscore, not starting with a digit' ] ],
	    'ui' => [ 'width' => 6, 'variableName' => 'write' ],
	];
	$fields[] = [
	    'key' => 'onError',
	    'type' => 'select',
	    'label' => 'On error',
	    'required' => true,
	    'default' => 'fail',
	    'choices' => self::ON_ERROR,
	    'help' => 'The service\'s errors, or no connection',
	    'ui' => [ 'width' => 6 ],
	];
	return $fields;
    }

    protected static function field($key, $label, array $ops, array $more = []) {
	return array_merge([ 'key' => $key, 'type' => 'text', 'label' => $label, 'ops' => $ops ], $more);
    }

    //the operation of $config (the only one, when there is one)
    protected static function operationOf($config) {
	$op = is_array($config) ? (string)($config['operation'] ?? static::$defaultOperation) : static::$defaultOperation;
	return isset(static::OPERATIONS[$op]) ? $op : static::$defaultOperation;
    }

    //the result variable of each operation: [ type, info, fields ] (none: { items, count, total, truncated } for LISTS, else any)
    protected static function resultOf($operation) {
	return null;
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? static::$defaultVariable) : static::$defaultVariable;
	if (!\util\Validator::isIdentifier($name))
	    return [];
	$op = static::operationOf($config);
	if ($def = static::resultOf($op))
	    return [ $name => $def ];
	if (in_array($op, static::LISTS, true))
	    return [ $name => [ 'type' => 'object', 'info' => '{ items, count, total, truncated }', 'fields' => [
		'items' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'info' => 'an item' ] ],
		'count' => [ 'type' => 'number', 'info' => 'the items read' ],
		'total' => [ 'type' => 'number', 'info' => 'all the matching items' ],
		'truncated' => [ 'type' => 'boolean', 'info' => 'more than At most' ],
	    ] ] ];
	return [ $name => [ 'type' => 'any', 'info' => 'the answer (null on error)' ] ];
    }

    public function validates($options = []) {
	$valid = parent::validates($options);
	$cred = (string)$this->cfg('credential', '');
	if (in_array(static::operationOf($this->config), static::CREDENTIAL_OPS, true) && $cred !== ''
	    && (!$this->workflow || empty(\models\Credential::findFor($this->workflow, $cred, static::$capability)))) {
	    $this->errors('config', static::$label . ': choose a credential of this project (' . static::$credentialLabel . ')');
	    $valid = false;
	}
	return $valid;
    }

    // ------------------------------------------------------------------------------------
    //running

    public function execute($edgeId = null) {
	$this->settingVars = $this->varsForSettings();
	$operation = static::operationOf($this->config);
	if (!isset(static::OPERATIONS[$operation]))
	    throw new \ErrorException(static::$label . ": unknown operation `{$operation}`");
	$this->start = microtime(true);
	$this->timeout = max(1, min(self::MAX_TIMEOUT, (int)$this->cfg('timeout', static::DEFAULT_TIMEOUT)));
	if (in_array($operation, static::CREDENTIAL_OPS, true)) {
	    $this->cred = \models\Credential::findFor($this->exec, (string)$this->cfg('credential', ''), static::$capability);
	    if (empty($this->cred))
		throw new \ErrorException(static::$label . ': the credential is no longer available');
	    if (static::$capability === 'anaf')
		$this->anaf = new \plugins\RegionalRO\util\Anaf($this->cred, $this->timeout);
	    else
		$this->client = $this->cred->client($this->timeout);
	}

	$name = (string)$this->cfg('variable', static::$defaultVariable);
	try {
	    [ $result, $summary ] = $this->runOperation($operation, $this->settingText(...), $this->settingOf(...));
	} catch (\RuntimeException $e) {
	    $this->setResult($name, null);
	    $this->exec->log($this->wfid, static::$label . " {$operation}: " . $e->getMessage());
	    if ($this->cfg('onError', 'fail') === 'output')
		return [ 1 ];
	    throw new \ErrorException(static::$label . ": {$operation}: " . $e->getMessage());
	}
	$this->setResult($name, $result);
	$this->exec->log($this->wfid, static::$label . " {$operation}: {$summary} (" . (int)round((microtime(true) - $this->start) * 1000) . ' ms)');
	return [ 0 ];
    }

    protected function maxItems() {
	return max(1, min(self::MAX_ITEMS, (int)$this->cfg('maxItems', 1000)));
    }

    //a list given whole, as At most cuts it: [ { items, count, total, truncated }, summary ]
    protected function listResult(array $all, $what) {
	$items = array_slice(array_values($all), 0, $this->maxItems());
	$truncated = count($all) > count($items);
	return [ [ 'items' => $items, 'count' => count($items), 'total' => count($all), 'truncated' => $truncated ], \util\Format::plural(count($items), $what) . ($truncated ? ' of ' . count($all) : '') ];
    }

    // ------------------------------------------------------------------------------------
    //values

    //the CIF of setting $key, else the credential's: its digits (util\Anaf::cui)
    protected function cifOf(\Closure $text, $key = 'cif') {
	$raw = $text($key);
	if ($raw === '' && $this->cred)
	    $raw = trim((string)$this->cred->cif);
	if ($raw === '')
	    throw new \RuntimeException('CIF: none given, and the credential has none');
	$cif = \plugins\RegionalRO\util\Anaf::cui($raw);
	if ($cif === null)
	    throw new \RuntimeException('CIF: not a valid CUI / CIF: `' . mb_substr($raw, 0, 20) . '`');
	return $cif;
    }

    //a date setting (YYYY-MM-DD, or anything strtotime reads); empty: today (Romania's time)
    protected function dateOf(\Closure $text, $key = 'date', $label = 'Date') {
	$raw = $text($key);
	$tz = new \DateTimeZone('Europe/Bucharest');
	if ($raw === '')
	    return (new \DateTime('now', $tz))->format('Y-m-d');
	try {
	    return (new \DateTime($raw, $tz))->format('Y-m-d');
	} catch (\Exception $e) {
	    throw new \RuntimeException("{$label}: not a date: `" . mb_substr($raw, 0, 32) . '` (YYYY-MM-DD)');
	}
    }

    //an XML setting: the text, or a project file (an object { id, ... } of another step, or a file id): [ xml, name ]
    protected function xmlOf(\Closure $value, $key = 'xml', $label = 'XML') {
	$v = $value($key);
	$id = is_array($v) ? (string)($v['id'] ?? '') : trim(\util\Expression::toText($v));
	if ($id !== '' && \util\Validator::rule('oid', $id)) {
	    $file = \V::app()->om->findOne('File', [ '_id' => $id, 'metadata.aid' => $this->exec->aid, 'metadata.project' => $this->exec->project ]);
	    if (empty($file))
		throw new \RuntimeException("{$label}: no project file `{$id}`");
	    if ((int)$file->length > self::MAX_XML)
		throw new \RuntimeException("{$label}: `{$file->filename}` is larger than " . (self::MAX_XML >> 20) . ' MB');
	    $fh = fopen('php://temp', 'w+b');
	    \models\File::_bucket()->downloadToStream($file->_id, $fh);
	    rewind($fh);
	    $xml = (string)stream_get_contents($fh);
	    fclose($fh);
	    return [ $xml, (string)$file->filename ];
	}
	$xml = is_array($v) ? '' : trim(\util\Expression::toText($v));
	if ($xml === '')
	    throw new \RuntimeException("{$label}: none given");
	if (strlen($xml) > self::MAX_XML)
	    throw new \RuntimeException("{$label}: larger than " . (self::MAX_XML >> 20) . ' MB');
	if ($xml[0] !== '<')
	    throw new \RuntimeException("{$label}: not XML, nor a project file");
	return [ $xml, '' ];
    }

    //$content saved as a file of the project named $filename: [ id, filename, size, contentType ]
    protected function saveFile($filename, $content) {
	$filename = preg_replace('~[\\\\/:*?"<>|\x00-\x1f]+~', '_', (string)$filename);
	$id = \models\File::createFromString($filename, (string)$content, [ 'metadata' => [
	    'project' => $this->exec->project,
	    'aid' => $this->exec->aid,
	    'owner' => null,
	    'source' => 'regional-ro',
	    'wf' => $this->exec->wf,
	    'created_at' => new \MongoDB\BSON\UTCDateTime(),
	] ]);
	return [ 'id' => (string)$id, 'filename' => $filename, 'size' => strlen((string)$content),
	    'contentType' => \models\File::CONTENT_TYPES[strtolower(pathinfo($filename, PATHINFO_EXTENSION))] ?? 'application/octet-stream' ];
    }

    //CLD: 2026-11-10T16:00:00+02:00: a JSON setting: an object ($list false) or a list, given as one ({{ }} of another step, a tool's argument) or as its text; [] when empty
    protected function jsonOf(\Closure $value, $key, $label, $list = false) {
	$v = $value($key);
	if (is_string($v)) {
	    if (trim($v) === '')
		return [];
	    $d = json_decode($v, true);
	    if (!is_array($d))
		throw new \RuntimeException("{$label}: not JSON (" . json_last_error_msg() . ')');
	    $v = $d;
	}
	if ($v === null || $v === '')
	    return [];
	if (!is_array($v) || ($v && array_is_list($v) !== $list))
	    throw new \RuntimeException("{$label}: " . ($list ? 'a list' : 'an object'));
	return $v;
    }

    //CLD: 2026-11-10T16:00:00+02:00: the settings of Call the API (operation `api`): method, path, query, body
    protected static function apiFields($pathHelp, $bodyHelp = 'A JSON object (or list) sent as the body; empty: none') {
	return [
	    self::field('apiMethod', 'Method', [ 'api' ], [ 'type' => 'select', 'default' => 'GET', 'choices' => [ 'GET' => 'GET', 'POST' => 'POST', 'PUT' => 'PUT', 'DELETE' => 'DELETE' ],
		'ui' => [ 'width' => 3 ] ]),
	    self::field('apiPath', 'Path', [ 'api' ], [ 'required' => true, 'help' => $pathHelp, 'ui' => [ 'expression' => 'text', 'width' => 9 ] ]),
	    self::field('apiQuery', 'Query', [ 'api' ], [ 'type' => 'textarea', 'help' => 'A JSON object of the query\'s parameters; empty: none',
		'ui' => [ 'rows' => 2, 'expression' => true, 'code' => true, 'agent' => [ 'type' => 'object' ] ] ]),
	    self::field('apiBody', 'Body', [ 'api' ], [ 'type' => 'textarea', 'help' => $bodyHelp, 'ui' => [ 'rows' => 3, 'expression' => true, 'code' => true ] ]),
	];
    }

    //CLD: 2026-11-10T16:00:00+02:00: Call the API: [ method, path (no scheme, no ..), query, body (null: none) ]
    protected function apiCall(\Closure $text, \Closure $value) {
	$method = in_array((string)$this->cfg('apiMethod', 'GET'), [ 'GET', 'POST', 'PUT', 'DELETE' ], true) ? (string)$this->cfg('apiMethod', 'GET') : 'GET';
	$path = ltrim($text('apiPath'), '/');
	if ($path === '' || preg_match('~^[a-z]+:|\.\.|[\s\\]~i', $path))
	    throw new \RuntimeException('Path: a path of the API, such as ' . 'invoice/pdf (no address, no ..)');
	$body = $value('apiBody');
	if (is_string($body)) {
	    $body = trim($body) === '' ? null : json_decode($body, true);
	    if ($body === null && trim((string)$value('apiBody')) !== '')
		throw new \RuntimeException('Body: not JSON');
	}
	return [ $method, $path, $this->jsonOf($value, 'apiQuery', 'Query'), $body ];
    }

    //a whole number of setting $key, from $min to $max
    protected function intOf(\Closure $text, $key, $label, $min, $max, $default) {
	$raw = $text($key);
	if ($raw === '')
	    return $default;
	if (!preg_match('/^\d+$/', $raw) || (int)$raw < $min || (int)$raw > $max)
	    throw new \RuntimeException("{$label}: a whole number from {$min} to {$max}");
	return (int)$raw;
    }
}
