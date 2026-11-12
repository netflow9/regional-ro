<?php

/*
    RegionalRO: the steps and credentials of Romania (its public institutions and services), for the installations that serve Romanian
    accounts. One plugin per country or region (Regional<code>: RegionalRO, later e.g. RegionalBG, RegionalEU), each installed on its own: kept in its own repository, cloned
    into app/plugins/, turned on in plugins.json (plugins.json.example: this plugin's entry as shipped).
    Its steps and credentials are registered here (\V::app()->om->registerObjects: `wfNode` for a step, `variant-of` Credential for a credential), as the core's are in
    data\SysObjects; they are in the designer's palette of every account of the installation (no tier feature), in the group Regional.
      models/node/          the steps (NodeAppRo their base)
	  NodeAppRoCompany      ANAF Company Lookup: companies by CUI / CIF (public)
	  NodeAppRoBnr          BNR Exchange Rates: the National Bank's reference rates, a conversion (public)
	  NodeAppRoEFactura     ANAF e-Factura: upload, status, messages, download; validate and PDF (public)
	  NodeAppRoETransport   ANAF e-Transport: upload (the UIT), status, list
      models/credential/    CredentialRoAnaf: ANAF (SPV), OAuth with the holder's qualified certificate; provides `anaf`
      util/                 Anaf (ANAF's services), Bnr (BNR's rates)
    the invoicing and courier services, each a step, a credential (group Romania) and a client (util/; Rest: their requests):
	  NodeAppRoSmartBill    SmartBill: invoices, proformas, payments, PDF, email, series, VAT rates, stocks (CredentialRoSmartBill, `smartbill`)
	  NodeAppRoFgo          FGO: invoices (issue, PDF, status, cancel, delete, reverse, AWB, payment), nomenclatures, articles (CredentialRoFgo, `fgo`)
	  NodeAppRoSameday      Sameday: AWB, cost, status, label, delete, pickup points, services, easyboxes, cities (CredentialRoSameday, `sameday`)
	  NodeAppRoFanCourier   FAN Courier: AWB, tariff, tracking, labels, delete, services, counties, localities (CredentialRoFanCourier, `fancourier`)
	  NodeAppRoCargus       Cargus: AWB, price, details, trace, documents, delete, pickup locations, counties, localities (CredentialRoCargus, `cargus`)
	each with Call the API (any call of the service, through the credential)
    the steps as the tools of AI steps (the core's util\AgentTools, hook agent:families): ANAF Company Lookup and BNR Exchange Rates (public: no credential;
    tools ro_company_lookup, ro_bnr_rates, ro_bnr_convert), ANAF e-Factura / e-Transport (an ANAF credential; anaf_efactura_<operation>, anaf_etransport_<operation>)
*/

namespace plugins\RegionalRO;

class Plugin {
    public function __construct() {
	\V::app()->om->registerObjects([
	    'CredentialRoAnaf' => [
		'class' => __NAMESPACE__ . '\\models\\credential\\CredentialRoAnaf',
		'variant-of' => 'Credential',
	    ],
	    //the invoicing and courier services' credentials
	    'CredentialRoSmartBill' => [
		'class' => __NAMESPACE__ . '\\models\\credential\\CredentialRoSmartBill',
		'variant-of' => 'Credential',
	    ],
	    'CredentialRoFgo' => [
		'class' => __NAMESPACE__ . '\\models\\credential\\CredentialRoFgo',
		'variant-of' => 'Credential',
	    ],
	    'CredentialRoSameday' => [
		'class' => __NAMESPACE__ . '\\models\\credential\\CredentialRoSameday',
		'variant-of' => 'Credential',
	    ],
	    'CredentialRoFanCourier' => [
		'class' => __NAMESPACE__ . '\\models\\credential\\CredentialRoFanCourier',
		'variant-of' => 'Credential',
	    ],
	    'CredentialRoCargus' => [
		'class' => __NAMESPACE__ . '\\models\\credential\\CredentialRoCargus',
		'variant-of' => 'Credential',
	    ],
	    'NodeAppRoCompany' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppRoCompany',
		'wfNode' => true,
	    ],
	    'NodeAppRoBnr' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppRoBnr',
		'wfNode' => true,
	    ],
	    'NodeAppRoEFactura' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppRoEFactura',
		'wfNode' => true,
	    ],
	    'NodeAppRoETransport' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppRoETransport',
		'wfNode' => true,
	    ],
	    //the invoicing and courier services' steps
	    'NodeAppRoSmartBill' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppRoSmartBill',
		'wfNode' => true,
	    ],
	    'NodeAppRoFgo' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppRoFgo',
		'wfNode' => true,
	    ],
	    'NodeAppRoSameday' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppRoSameday',
		'wfNode' => true,
	    ],
	    'NodeAppRoFanCourier' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppRoFanCourier',
		'wfNode' => true,
	    ],
	    'NodeAppRoCargus' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppRoCargus',
		'wfNode' => true,
	    ],
	]);

	//the families of the AI steps' tools
	\V::app()->hooks->addHook('agent:families', function(\ArrayObject $families) {
	    $families['roCompany'] = [ 'label' => 'ANAF Company Lookup', 'prefix' => 'ro_company', 'credential' => false, 'steps' => [ models\node\NodeAppRoCompany::class ] ];
	    $families['roBnr'] = [ 'label' => 'BNR Exchange Rates', 'prefix' => 'ro_bnr', 'credential' => false, 'steps' => [ models\node\NodeAppRoBnr::class ] ];
	    $families['roAnaf'] = [ 'label' => 'ANAF e-Factura / e-Transport', 'prefix' => 'anaf', 'capability' => 'anaf',
		'steps' => [ models\node\NodeAppRoEFactura::class, models\node\NodeAppRoETransport::class ] ];
	    //the invoicing and courier services (tools <prefix>_<operation>)
	    $families['roSmartBill'] = [ 'label' => 'SmartBill', 'prefix' => 'smartbill', 'capability' => 'smartbill', 'steps' => [ models\node\NodeAppRoSmartBill::class ] ];
	    $families['roFgo'] = [ 'label' => 'FGO', 'prefix' => 'fgo', 'capability' => 'fgo', 'steps' => [ models\node\NodeAppRoFgo::class ] ];
	    $families['roSameday'] = [ 'label' => 'Sameday', 'prefix' => 'sameday', 'capability' => 'sameday', 'steps' => [ models\node\NodeAppRoSameday::class ] ];
	    $families['roFanCourier'] = [ 'label' => 'FAN Courier', 'prefix' => 'fan', 'capability' => 'fancourier', 'steps' => [ models\node\NodeAppRoFanCourier::class ] ];
	    $families['roCargus'] = [ 'label' => 'Cargus', 'prefix' => 'cargus', 'capability' => 'cargus', 'steps' => [ models\node\NodeAppRoCargus::class ] ];
	});
    }
}
