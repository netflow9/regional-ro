<?php

/*
    CLD: 2026-11-10T12:00:00+02:00: new: RegionalRO: the steps and credentials of Romania (its public institutions and services), for the installations that serve Romanian
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
    CLD: 2026-11-10T14:00:00+02:00: the steps as the tools of AI steps (the core's util\AgentTools, hook agent:families): ANAF Company Lookup and BNR Exchange Rates (public: no credential;
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
	]);

	//CLD: 2026-11-10T14:00:00+02:00: the families of the AI steps' tools
	\V::app()->hooks->addHook('agent:families', function(\ArrayObject $families) {
	    $families['roCompany'] = [ 'label' => 'ANAF Company Lookup', 'prefix' => 'ro_company', 'credential' => false, 'steps' => [ models\node\NodeAppRoCompany::class ] ];
	    $families['roBnr'] = [ 'label' => 'BNR Exchange Rates', 'prefix' => 'ro_bnr', 'credential' => false, 'steps' => [ models\node\NodeAppRoBnr::class ] ];
	    $families['roAnaf'] = [ 'label' => 'ANAF e-Factura / e-Transport', 'prefix' => 'anaf', 'capability' => 'anaf',
		'steps' => [ models\node\NodeAppRoEFactura::class, models\node\NodeAppRoETransport::class ] ];
	});
    }
}
