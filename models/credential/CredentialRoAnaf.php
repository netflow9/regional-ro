<?php

/*
    CLD: 2026-11-10T12:00:00+02:00: new: ANAF's API through OAuth (SPV), for the e-Factura and e-Transport steps (util\Anaf). The OAuth app is registered in ANAF's portal by the
    holder of a qualified certificate (anaf.ro > Servicii online > Înregistrare utilizatori > Editare profil Oauth: the services E-Factura and/or E-Transport, the redirect URI below);
    Connect opens logincert.anaf.ro, which asks the browser for that certificate (the token: plugged in). The access token lasts 90 days, the refresh token 365: after a year, Connect again.
    The company (CIF) the steps act for by default; ANAF's test environment (api.anaf.ro/test) or production. Test: a token, and the e-Factura messages of the last day of the CIF.
    Provides `anaf`
*/

namespace plugins\RegionalRO\models\credential;

class CredentialRoAnaf extends \models\credential\CredentialOAuth2 {
    public static $label = 'ANAF (SPV)';
    public static $icon = 'bi-bank';
    public static $group = 'Romania';
    public static $provides = [ 'anaf' ];

    public function authEndpoint() {
	return \plugins\RegionalRO\util\Anaf::AUTHORIZE;
    }

    public function tokenEndpoint() {
	return \plugins\RegionalRO\util\Anaf::TOKEN;
    }

    //ANAF has no scopes: the services are those of the OAuth app
    public function scope() {
	return '';
    }

    //a JWT access token (ANAF's APIs ask for one)
    public function tokenParams() {
	return [ 'token_content_type' => 'jwt' ];
    }

    public function getSchema() {
	$fields = parent::getSchema();
	foreach($fields as &$f) {
	    if ($f['key'] === 'clientId')
		$f['help'] = 'Of the OAuth app registered in ANAF\'s portal (Editare profil Oauth), with the services E-Factura and / or E-Transport';
	    if ($f['key'] === 'email')
		$f['help'] = 'ANAF gives no account name: empty';
	}
	unset($f);
	return array_merge($fields, [
	    [
		'key' => 'cif',
		'type' => 'text',
		'label' => 'CIF',
		'default' => '',
		'transform' => 'trim',
		'help' => 'The company the steps act for when they name none (its CUI / CIF, without RO); the certificate must have rights on it in SPV',
		'validate' => [ [ 'regex', 'pattern' => '^(RO|ro)?\s*[1-9]\d{1,9}$', 'skipEmpty' => true, 'message' => 'A CUI / CIF, such as 14399840' ] ],
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'testEnv',
		'type' => 'switch',
		'label' => 'ANAF test environment',
		'default' => false,
		'help' => 'api.anaf.ro/test: invoices and declarations sent there are not real (the OAuth app and the certificate are the same)',
		'ui' => [ 'width' => 6 ],
	    ],
	]);
    }

    public function test() {
	try {
	    $this->accessToken();
	} catch (\Exception $e) {
	    throw new \Exception($e->getMessage());
	}
	$where = $this->testEnv ? 'ANAF (test)' : 'ANAF';
	$cif = \plugins\RegionalRO\util\Anaf::cui($this->cif);
	if ($cif === null)
	    return "access token obtained from {$where}";
	try {
	    $r = (new \plugins\RegionalRO\util\Anaf($this, 20))->call('efactura', 'GET', 'listaMesajeFactura', [ 'zile' => 1, 'cif' => $cif ]);
	} catch (\RuntimeException $e) {
	    throw new \Exception($e->getMessage());
	}
	$a = \plugins\RegionalRO\util\Anaf::answer($r['body']);
	if (isset($a['mesaje']))
	    return "connected to {$where}: CIF {$cif}, " . \util\Format::plural(count((array)$a['mesaje']), 'e-Factura message') . ' in the last day';
	$m = \plugins\RegionalRO\util\Anaf::message($a);
	//no messages is an answer too ("Nu exista mesaje ...")
	if (stripos($m, 'nu exista mesaje') !== false)
	    return "connected to {$where}: CIF {$cif}, no e-Factura message in the last day";
	throw new \Exception("CIF {$cif}: " . ($m ?: 'ANAF\'s answer is not a list of messages'));
    }
}
