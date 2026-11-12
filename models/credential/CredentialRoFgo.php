<?php

/*
    an FGO account's API access, for the FGO step (util\Fgo): the company's CUI (CodUnic), the API user's private key (encrypted; FGO: Setari >
    Utilizatori, a PRO or PREMIUM subscription), the address of the calling application FGO is told (PlatformaUrl; empty: this installation's), FGO's test environment (testuat).
    Test: FGO answers (its VAT rates; the key is checked by the invoices' operations only). Provides `fgo`
*/

namespace plugins\RegionalRO\models\credential;

class CredentialRoFgo extends \models\Credential {
    public static $label = 'FGO';
    public static $icon = 'bi-receipt-cutoff';
    public static $group = 'Romania';
    public static $provides = [ 'fgo' ];
    public static $testable = true;

    public function getSchema() {
	return array_merge(parent::getSchema(), [
	    [
		'key' => 'cif',
		'type' => 'text',
		'label' => 'Company CUI',
		'required' => true,
		'default' => '',
		'transform' => 'trim',
		'help' => 'CodUnic: the company\'s fiscal code, without RO',
		'validate' => [ [ 'regex', 'pattern' => '^(RO|ro)?\s*[1-9]\d{1,9}$', 'skipEmpty' => true, 'message' => 'A CUI, such as 14399840' ] ],
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'privateKey',
		'type' => 'password',
		'label' => 'Private key',
		'required' => true,
		'encrypt' => true,
		'help' => 'Of the API user (FGO: Setari > Utilizatori)',
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'platformUrl',
		'type' => 'text',
		'label' => 'Platform URL',
		'default' => '',
		'transform' => 'trim',
		'help' => 'PlatformaUrl, the calling application\'s address FGO is told; empty: ' . rtrim(\V::app()->createAbsoluteUrl('/'), '/'),
		'validate' => [ [ 'regex', 'pattern' => '^https?://\S+$', 'skipEmpty' => true, 'message' => 'An address, such as https://shop.example.ro' ] ],
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'testEnv',
		'type' => 'switch',
		'label' => 'FGO test environment',
		'default' => false,
		'help' => 'api-testuat.fgo.ro, with an account made at testuat.fgo.ro (not synchronised with production)',
		'ui' => [ 'width' => 6 ],
	    ],
	]);
    }

    public function client($timeout = 60) {
	return new \plugins\RegionalRO\util\Fgo($this, $timeout);
    }

    public function test() {
	try {
	    $list = (array)($this->client(20)->nomenclature('tva')['List'] ?? []);
	} catch (\RuntimeException $e) {
	    throw new \Exception($e->getMessage());
	}
	return 'FGO' . ($this->testEnv ? ' (test)' : '') . ' answers: ' . \util\Format::plural(count($list), 'VAT rate') . '; the key is checked by the first invoice operation';
    }
}
