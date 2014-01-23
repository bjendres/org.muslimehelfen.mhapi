<?php

$mh_civicrm_api_config = array();
$contact_information = array();
$contact_information['email'] = 'someguy@somewhere.nil';
$contact_information['first_name'] = 'Björn';
$contact_information['last_name'] = 'Endres';
$contact_information['contact_type'] = 'Individual';								// or: "Organization"
$contact_information['prefix'] = 'Herr';											// or: "Frau"
$contact_information['street_address'] = 'Franzstr. 122';
$contact_information['country'] = 'Deutschland';									// or "Österreich", "Schweiz"
$contact_information['postal_code'] = '53111';
$contact_information['city'] = 'Bonn';
$contact_information['phone'] = '0228 96104990';
$contact_information['create_if_not_found'] = '1';

// overwrite with your credentials
require_once('example_credentials.php');

$contact_info = mh_civicrm_get_contact($contact_information);
print_r($contact_info);

if ($contact_info['id']) {
	$contribution_information = array();
	$contribution_information['contact_id'] = $contact_info['id'];
	$contribution_information['financial_type'] = 'Ramadanhilfe';
	$contribution_information['payment_instrument'] = 'SEPA DD Recurring Transaction';					
	$contribution_information['contribution_campaign'] = '111111';
	$contribution_information['total_amount'] = '100.00';								// needs to have two postfix digits
	$contribution_information['currency'] = 'EUR';										// or e.g.: 'CHF'
	$contribution_information['contribution_status'] = 'In Bearbeitung';				// or e.g.: 'Abgeschlossen'
	$contribution_information['is_test'] = '0';											// set to '0' for real contributions
	$contribution_information['iban'] = 'DE47200411440799723300';
	$contribution_information['bic'] = 'COBADEHD044';
	$contribution_information['dekade'] = 2;
	$contribution_information['turnus'] = 3;
 	$contribution_information['datum'] = date('Y-m-d', strtotime("+15 days"));


	$result = mh_civicrm_create_contribution($contribution_information);
	print_r($result);
} else {
	print_r("Fehler bei der Zuordnung des Kontakts.\n");
}


/*********************************************************
 **				Helper functions for REST calls      	**
 *********************************************************/

function mh_civicrm_get_contact($contact_information) {
	global $mh_civicrm_api_config;

	$query = $contact_information;		// array copy(!)
	$query['api_key'] = $mh_civicrm_api_config['api_key'];
	$query['key'] = $mh_civicrm_api_config['site_key'];
	$query['sequential'] = 1;
	$query['json'] = 1;
	$query['version'] = 3;
	$query['entity'] = 'MhApi';
	$query['action'] = 'getcontact';


	$curl = curl_init();
	curl_setopt($curl, CURLOPT_POST, 1);
	curl_setopt($curl, CURLOPT_POSTFIELDS, $query);
	curl_setopt($curl, CURLOPT_URL, $mh_civicrm_api_config['url']);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($curl, CURLOPT_SSLVERSION, 3);

    $response = curl_exec($curl);
    return json_decode($response, true);
}

function mh_civicrm_create_contribution($contact_information) {
	global $mh_civicrm_api_config;

	$query = $contact_information;		// array copy(!)
	$query['api_key'] = $mh_civicrm_api_config['api_key'];
	$query['key'] = $mh_civicrm_api_config['site_key'];
	$query['sequential'] = 1;
	$query['json'] = 1;
	$query['version'] = 3;
	$query['entity'] = 'MhApi';
	$query['action'] = 'addcontribution';
	print_r($query);
	$curl = curl_init();
	curl_setopt($curl, CURLOPT_POST, 1);
	curl_setopt($curl, CURLOPT_POSTFIELDS, $query);
	curl_setopt($curl, CURLOPT_URL, $mh_civicrm_api_config['url']);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($curl, CURLOPT_SSLVERSION, 3);

    $response = curl_exec($curl);
    return json_decode($response, true);
}
