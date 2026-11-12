<?php

/*
    a FAN Courier account's API access (selfAWB), for the FAN Courier step (util\FanCourier): the selfAWB user and its password (encrypted), the
    client id (the sender's branch, clientId) the AWBs are made for. Test: sign in, the services. Provides `fancourier`
*/

namespace plugins\RegionalRO\models\credential;

class CredentialRoFanCourier extends \models\Credential {
    public static $label = 'FAN Courier';
    public static $icon = 'bi-truck';
    public static $group = 'Romania';
    public static $provides = [ 'fancourier' ];
    public static $testable = true;

    public function getSchema() {
	return array_merge(parent::getSchema(), [
	    [
		'key' => 'username',
		'type' => 'text',
		'label' => 'selfAWB user',
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
		'key' => 'clientId',
		'type' => 'text',
		'label' => 'Client id',
		'required' => true,
		'default' => '',
		'transform' => 'trim',
		'help' => 'The sender branch\'s client id (clientId), from FAN Courier',
		'validate' => [ [ 'regex', 'pattern' => '^\d{1,12}$', 'skipEmpty' => true, 'message' => 'A number' ] ],
		'ui' => [ 'width' => 6 ],
	    ],
	]);
    }

    public function client($timeout = 60) {
	return new \plugins\RegionalRO\util\FanCourier($this, $timeout);
    }

    public function test() {
	try {
	    $d = $this->client(20)->call('GET', 'reports/services');
	} catch (\RuntimeException $e) {
	    throw new \Exception($e->getMessage());
	}
	return 'connected to FAN Courier as ' . trim((string)$this->username) . ' (client ' . trim((string)$this->clientId) . '): ' . \util\Format::plural(count((array)($d['data'] ?? [])), 'service');
    }
}
