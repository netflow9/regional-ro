<?php

/*
    a SmartBill Cloud account's API access, for the SmartBill step (util\SmartBill): the account's email, its API token (encrypted; SmartBill:
    Integrari > API), the company's CIF the documents are issued for (companyVatCode). Test: the company's VAT rates. Provides `smartbill`
*/

namespace plugins\RegionalRO\models\credential;

class CredentialRoSmartBill extends \models\Credential {
    public static $label = 'SmartBill';
    public static $icon = 'bi-receipt-cutoff';
    public static $group = 'Romania';
    public static $provides = [ 'smartbill' ];
    public static $testable = true;

    public function getSchema() {
	return array_merge(parent::getSchema(), [
	    [
		'key' => 'email',
		'type' => 'text',
		'label' => 'Email',
		'required' => true,
		'default' => '',
		'transform' => 'trim',
		'help' => 'The SmartBill Cloud account\'s email',
		'validate' => [ [ 'email', 'skipEmpty' => true, 'message' => 'An email address' ] ],
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'token',
		'type' => 'password',
		'label' => 'API token',
		'required' => true,
		'encrypt' => true,
		'help' => 'SmartBill: Integrari > API',
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'cif',
		'type' => 'text',
		'label' => 'Company CIF',
		'required' => true,
		'default' => '',
		'transform' => 'trim',
		'help' => 'The company the documents are issued for, as in SmartBill (e.g. RO12345678)',
		'ui' => [ 'width' => 6 ],
	    ],
	]);
    }

    public function client($timeout = 60) {
	return new \plugins\RegionalRO\util\SmartBill($this, $timeout);
    }

    public function test() {
	try {
	    $taxes = (array)($this->client(20)->call('GET', 'tax', [ 'cif' => trim((string)$this->cif) ])['taxes'] ?? []);
	} catch (\RuntimeException $e) {
	    throw new \Exception($e->getMessage());
	}
	return 'connected to SmartBill as ' . trim((string)$this->email) . ': company ' . trim((string)$this->cif) . ', ' . \util\Format::plural(count($taxes), 'VAT rate');
    }
}
