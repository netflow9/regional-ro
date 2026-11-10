<?php

/*
    CLD: 2026-11-10T16:00:00+02:00: new: a Sameday (courier) account's API access, for the Sameday step (util\Sameday): the API user and its password (encrypted; from Sameday), the demo
    environment or production. Test: sign in, the pickup points. Provides `sameday`
*/

namespace plugins\RegionalRO\models\credential;

class CredentialRoSameday extends \models\Credential {
    public static $label = 'Sameday';
    public static $icon = 'bi-truck';
    public static $group = 'Romania';
    public static $provides = [ 'sameday' ];
    public static $testable = true;

    public function getSchema() {
	return array_merge(parent::getSchema(), [
	    [
		'key' => 'username',
		'type' => 'text',
		'label' => 'API user',
		'required' => true,
		'default' => '',
		'transform' => 'trim',
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'password',
		'type' => 'password',
		'label' => 'Password',
		'required' => true,
		'encrypt' => true,
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'demo',
		'type' => 'switch',
		'label' => 'Demo environment',
		'default' => false,
		'help' => 'sameday-api.demo.zitec.com, with a demo user: AWBs there are not real (its pickup points and services have other ids)',
	    ],
	]);
    }

    public function client($timeout = 60) {
	return new \plugins\RegionalRO\util\Sameday($this, $timeout);
    }

    public function test() {
	try {
	    $d = $this->client(20)->call('GET', 'api/client/pickup-points', [ 'countPerPage' => 100 ]);
	} catch (\RuntimeException $e) {
	    throw new \Exception($e->getMessage());
	}
	return 'connected to Sameday' . ($this->demo ? ' (demo)' : '') . ' as ' . trim((string)$this->username) . ': ' . \util\Format::plural(count((array)($d['data'] ?? [])), 'pickup point');
    }
}
