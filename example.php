<?php

$mh_civicrm_api_config = array();
$mh_civicrm_api_config['url'] = "<your_drupal_URL>/sites/all/modules/civicrm/extern/rest.php";
$mh_civicrm_api_config['site_key'] = "<civicrm site key>";
$mh_civicrm_api_config['api_key'] = "<civicrm API key>";


$contact_information = array();
$contact_information['email'] = 'someguy@somewhere.nil';
$contact_information['first_name'] = 'Björn';
$contact_information['last_name'] = 'Endres';
$contact_information['create_if_not_found'] = '1';
$contact_info = mh_civicrm_get_contact($contact_information);
print_r($contact_info);

$contribution_information = array();
$contribution_information['contact_id'] = $contact_info['id'];
$contribution_information['financial_type'] = 'Brunnen/Wasser';
$contribution_information['payment_instrument'] = 'Kreditkarte';
$contribution_information['contribution_campaign'] = '111111';
$contribution_information['total_amount'] = '100.00';								// needs to have two postfix digits
$contribution_information['currency'] = 'EUR';										// or e.g.: 'CHF'
$contribution_information['contribution_status'] = 'In Bearbeitung';				// or e.g.: 'Abgeschlossen'
$contribution_information['is_test'] = '1';											// set to '0' for real contributions


$result = mh_civicrm_create_contribution($contribution_information);
print_r($result);



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
    print_r($response);
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
