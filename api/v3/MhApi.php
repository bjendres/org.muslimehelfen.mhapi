<?php

/**
 * Tries to find a contact with the given parameters, and returns the contact_id.
 * Identification currently by email + fuzzy match first and last name
 * 
 * parameters:
 *  - any Contact field
 *  - "create_if_not_found"  	to make it create a contact if not found. Default is FALSE
 *  - "fuzzy_match_threshold"	threshold value for the name matching. Default is 0.7
 *
 */
function civicrm_api3_mh_api_getcontact($params) {
	// parameters
	$create_if_not_found = FALSE;
	$fuzzy_match_threshold = 0.7;
	if (isset($params['create_if_not_found'])) {
		$create_if_not_found = $params['create_if_not_found'];
	}
	if (isset($params['fuzzy_match_threshold'])) {
		$fuzzy_match_threshold = $params['fuzzy_match_threshold'];
	}

	// match contact
	$contact_id = 0;
	$contact_data = NULL;
	$email_query = civicrm_api('Email', 'get', array('version' => 3, 'sequential' => 1, 'email' => strtolower($params['email'])));
	if ($email_query['is_error']) {
		error_log("API Error: ".$email_query['error_message']);
		return civicrm_api3_create_error("API Error: ".$email_query['error_message']);
	}

	// iterate through email list and see if we have a match
	foreach ($email_query['values'] as $email_data) {
		$candidate_query = civicrm_api('Contact', 'get', array('version' => 3, 'sequential' => 1, 'id' => $email_data['contact_id']));
		if ($candidate_query['is_error']) {
			error_log("API Error: ".$candidate_query['error_message']);
			return civicrm_api3_create_error("API Error: ".$candidate_query['error_message']);
		} 

		// check if the contact is a match:
		$candidate_data = $candidate_query['values'][0];
		$first_name_similarity = _mh_stringSimiliarity($params['first_name'], $candidate_data['first_name']);
		$last_name_similarity = _mh_stringSimiliarity($params['last_name'], $candidate_data['last_name']);
		$similarity = $first_name_similarity * $last_name_similarity;

		if ($similarity >= $fuzzy_match_threshold) {
			// found a match!
			$contact_id = $candidate_data['contact_id'];
			$contact_data = $candidate_data;
			break;
		}
	}

	// if no match found, create (?)
	if (!$contact_id && $create_if_not_found) {
		$create_query = $params;			// copy(!) array
		$create_query['version'] = 3;
		$create_query['sequential'] = 1;
		unset($create_query['create_if_not_found']);
		unset($create_query['fuzzy_match_threshold']);
		$create_result = civicrm_api('Contact', 'create', $create_query);
		if ($create_result['is_error']) {
			error_log("API Error: ".$create_result['error_message']);
			return civicrm_api3_create_error("API Error: ".$create_result['error_message']);
		} else {
			$contact_data = $create_result['values'][0];
			$contact_id = $contact_data['contact_id'];
		}
	}

	// create activity, if values differ
	$differing_attributes = array();
	foreach ($params as $attribute => $value) {
		if (count($value)>0) {
			// check if this attribute is different
			if (strtolower($value)!=strtolower($contact_data[$attribute])) {
				// this is different, mark!
				$differing_attributes[$key] = $value;
			}
		}
	}

	// create activity only of differing attributes were detected!
	if (count($differing_attributes)>0) {
		// TODO: implement
		error_log("TODO: create activity for differing attributes: ".implode(';', $differing_attributes));
	}

	// reply 
	$reply = array();
	$reply['contact_id'] = $contact_data['contact_id'];
	$reply['external_identifier'] = $contact_data['external_identifier'];

	return civicrm_api3_create_success(array($contact_id => $reply), array(), 'MhApi', 'getcontact');
}


/**
 * Will create a new contribution, looking up the state and type ids
 * 
 */
function civicrm_api3_mh_api_addcontribution($params) {
	// REMOVE: create only test contributions
	$params['is_test'] = '1';

	// look up payment type
	if (!isset($params['payment_instrument_id'])) {
		if (!isset($params['payment_instrument'])) {
			return civicrm_api3_create_error("Neither payment_instrument nor payment_instrument_id given.");
		} else {
			$params['payment_instrument_id'] = _mh_lookup_option_value('payment_instrument', $params['payment_instrument']);
			if ($params['payment_instrument_id']==NULL) {
				return civicrm_api3_create_error("Cannot resolve payment_instrument ".$params['payment_instrument']);
			}
			unset($params['payment_instrument']);
		}
	}

	// look up contribution status
	if (!isset($params['contribution_status_id'])) {
		if (!isset($params['contribution_status'])) {
			return civicrm_api3_create_error("Neither contribution_status nor contribution_status_id given.");
		} else {
			$params['contribution_status_id'] = _mh_lookup_option_value('contribution_status', $params['contribution_status']);
			if ($params['contribution_status_id']==NULL) {
				return civicrm_api3_create_error("Cannot resolve contribution_status ".$params['contribution_status']);
			}
			unset($params['contribution_status']);
		}
	}	

	// look up financial type id
	if (!isset($params['financial_type_id'])) {
		if (!isset($params['financial_type'])) {
			return civicrm_api3_create_error("Neither financial_type nor financial_type_id given.");
		} else {
			$query = "SELECT id FROM civicrm_financial_type WHERE name = %1";
			$query_parameters = array(1 => array($params['financial_type'],'String'));
			$params['financial_type_id'] = CRM_Core_DAO::singleValueQuery($query, $query_parameters);
			if ($params['financial_type_id']==NULL) {
				return civicrm_api3_create_error("Cannot resolve financial_type ".$params['financial_type']);
			}
			unset($params['financial_type']);
		}
	}	

	// look up campaign
	if (!isset($params['contribution_campaign_id'])) {
		if (!isset($params['contribution_campaign'])) {
			return civicrm_api3_create_error("Neither contribution_campaign nor contribution_campaign_id given.");
		} else {
			$campaign_query = civicrm_api('Campaign', 'get', array('version' => 3, 'sequential' => 1, 'external_identifier' => $params['contribution_campaign']));
			if ($campaign_query['is_error']) {
				return civicrm_api3_create_error("API Error: ".$campaign_query['error_message']);
			} elseif ($campaign_query['count']==0) {
				return civicrm_api3_create_error("Cannot find campaign ".$params['contribution_campaign']);
			} else {
				unset($params['contribution_campaign']);
				$params['contribution_campaign_id'] = $campaign_query['values'][0]['id'];
			}
		}
	}

	// finally, create contribution
	$params['version'] = 3;
	$params['sequential'] = 1;
	unset($params['check_permissions']);
	$create_contribution = civicrm_api('Contribution', 'create', $params);
	if ($create_contribution['is_error']) {
		return civicrm_api3_create_error("API Error: ".$create_contribution['error_message']);
	} else {
		return civicrm_api3_create_success($create_contribution['values'], array(), 'MhApi', 'addcontribution');
	}		
}






/*********************************************************************************
 **								Helper Functions 								**
 ********************************************************************************/

/***
 * defines a string similarity based on PHP's similar_text() function
 *
 * return value is a float [0...1]
 */
function _mh_stringSimiliarity($string1, $string2) {
	$string1 = strtolower($string1);
	$string2 = strtolower($string2);
	$similarity = floatval(similar_text(strtolower($string1), strtolower($string2)));
	$length = floatval(max(strlen($string1), strlen($string2)));
	return $similarity / $length;
}

/**
 * Look up an option group value
 *
 * Warning: the group is identified by 'name', whereas the value is identified by 'label' (translated!)
 *
 * TODO: this could be way more performant, but it'll do for now
 */
function _mh_lookup_option_value($group_name, $value_name) {
	// query the group ID
	$group_query = civicrm_api('OptionGroup', 'get', array('version' => 3, 'sequential' => 1, 'name' => $group_name));
	//error_log(var_export($group_query, true));
	if ($group_query['is_error']) {
		error_log("API Error: ".$group_query['error_message']);
		return NULL;
	}
	if ($group_query['count']==0) {
		error_log("Couldn't find option group: ".$group_name);
		return NULL;
	}
	// query the option value
	$value_query = civicrm_api('OptionValue', 'get', array('version' => 3, 'sequential' => 1, 'option_group_id' => $group_query['id'], 'label' => $value_name));
	//error_log(var_export($value_query, true));
	if ($value_query['is_error']) {
		error_log("API Error: ".$value_query['error_message']);
		return NULL;
	}
	if ($value_query['count']==0) {
		error_log("Couldn't find option value: ".$value_name);
		return NULL;
	}
	return $value_query['values'][0]['value'];
}
