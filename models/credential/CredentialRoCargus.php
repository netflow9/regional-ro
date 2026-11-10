<?php

/*
    CLD: 2026-11-10T16:00:00+02:00: new: a Cargus account's API access, for the Cargus step (util\Cargus): the subscription key (encrypted; Cargus's API portal, product
    StandardUrgentOnlineAPI: its Primary Key), the WebExpress user and its password (encrypted), the pickup location the AWBs leave from by default, the API's address (empty:
    https://urgentcargus.azure-api.net/api). Test: sign in, the pickup locations. Provides `cargus`
*/

namespace plugins\RegionalRO\models\credential;

class CredentialRoCargus extends \models\Credential {
    public static $label = 'Cargus';
    public static $icon = 'bi-truck';
    public static $group = 'Romania';
    public static $provides = [ 'cargus' ];
    public static $testable = true;

    public function getSchema() {
	return array_merge(parent::getSchema(), [
	    [
		'key' => 'subscriptionKey',
		'type' => 'password',
		'label' => 'Subscription key',
		'required' => true,
		'encrypt' => true,
		'help' => 'Cargus\'s API portal: Products > StandardUrgentOnlineAPI, the Primary Key',
	    ],
	    [
		'key' => 'username',
		'type' => 'text',
		'label' => 'User',
		'required' => true,
		'default' => '',
		'transform' => 'trim',
		'help' => 'The WebExpress user',
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
		'key' => 'locationId',
		'type' => 'text',
		'label' => 'Pickup location id',
		'default' => '',
		'transform' => 'trim',
		'help' => 'Where the AWBs leave from when a step names none (Cargus step: List pickup locations)',
		'validate' => [ [ 'regex', 'pattern' => '^\d{1,15}$', 'skipEmpty' => true, 'message' => 'A number' ] ],
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'apiUrl',
		'type' => 'text',
		'label' => 'API address',
		'default' => '',
		'transform' => 'trim',
		'help' => 'Empty: ' . \plugins\RegionalRO\util\Cargus::API,
		'validate' => [ [ 'regex', 'pattern' => '^https://\S+$', 'skipEmpty' => true, 'message' => 'An https:// address' ] ],
		'ui' => [ 'width' => 6 ],
	    ],
	]);
    }

    public function client($timeout = 60) {
	return new \plugins\RegionalRO\util\Cargus($this, $timeout);
    }

    public function test() {
	try {
	    $list = $this->client(20)->call('GET', 'PickupLocations/GetForClient');
	} catch (\RuntimeException $e) {
	    throw new \Exception($e->getMessage());
	}
	return 'connected to Cargus as ' . trim((string)$this->username) . ': ' . \util\Format::plural(is_array($list) ? count($list) : 0, 'pickup location');
    }
}
