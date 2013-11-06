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
	$new_contact_tag_name = "Neuer Kontakt";
	$new_address_location = "Privat";
	$activity_type_name = "Adressprüfung";

	$contact_parameters = ['email', 'first_name', 'last_name', 'contact_type', 'prefix', 'gender'];
	$contact_address_parameters = ['street_address', 'country', 'postal_code', 'city'];
	$contact_phone_parameters = ['phone'];
	$id2country = CRM_Core_PseudoConstant::country();
	$country2id = array_flip($id2country);
	
	if (isset($params['create_if_not_found'])) {
		$create_if_not_found = $params['create_if_not_found'];
	}
	if (isset($params['fuzzy_match_threshold'])) {
		$fuzzy_match_threshold = $params['fuzzy_match_threshold'];
	}


	// post-process input values
	if ($params['prefix']=='Herr') {
		$params['gender'] = "Männlich";
	} elseif ($params['prefix']=='Frau') {
		$params['gender'] = "Weiblich";
	}
	if ($params['phone_main']) {
		$params['phone'] = $params['phone_main'];	
	}

	// match contact
	$contact_id = 0;
	$contact_data = NULL;
	$email_query = civicrm_api('Email', 'get', array('version' => 3, 'sequential' => 1, 'email' => strtolower($params['email'])));
	if ($email_query['is_error']) {
		error_log("org.muslimehelfen.mhapi: API Error: ".$email_query['error_message']);
		return civicrm_api3_create_error("API Error: ".$email_query['error_message']);
	}

	// iterate through email list and see if we have a match
	foreach ($email_query['values'] as $email_data) {
		$candidate_query = civicrm_api('Contact', 'get', array('version' => 3, 'sequential' => 1, 'id' => $email_data['contact_id']));
		if ($candidate_query['is_error']) {
			error_log("org.muslimehelfen.mhapi: API Error: ".$candidate_query['error_message']);
			return civicrm_api3_create_error("API Error: ".$candidate_query['error_message']);
		} 

		// check if the contact is a match:
		$candidate_data = $candidate_query['values'][0];
		$first_name_similarity = _mh_stringSimiliarity($params['first_name'], $candidate_data['first_name']);
		$last_name_similarity = _mh_stringSimiliarity($params['last_name'], $candidate_data['last_name']);
		$similarity = $first_name_similarity * $last_name_similarity;

		if ($similarity >= $fuzzy_match_threshold) {
			// found a match!
			$contact_id = $candidate_data['id'];
			$contact_data = $candidate_data;
			break;
		}
	}


	// if no match found, create new contact
	if (!$contact_id && $create_if_not_found) {
		// create contact
		$create_query = array_section($params, $contact_parameters);
		$create_query['version'] = 3;
		$create_query['sequential'] = 1;
		$create_result = civicrm_api('Contact', 'create', $create_query);
		if ($create_result['is_error']) {
			error_log("org.muslimehelfen.mhapi: API Error: ".$create_result['error_message']);
			return civicrm_api3_create_error("API Error: ".$create_result['error_message']);
		} else {
			$contact_data = $create_result['values'][0];
			$contact_id = $contact_data['id'];
		}

		// create address
		$create_address_query = array_section($params, $contact_address_parameters);
		if ($create_address_query) {
			$country_name = $create_address_query['country'];
			unset($create_address_query['country']);

			$create_address_query['version'] = 3;
			$create_address_query['sequential'] = 1;
			$create_address_query['contact_id'] = $contact_id;
			$create_address_query['location_type_id'] = $new_address_location;
			if (isset($country2id[$country_name])) {
				$create_address_query['country_id'] = $countries[$country_name];
			} else {
				error_log("org.muslimehelfen.mhapi: Unknown country '$country_name'.");
			}

			$create_address_result = civicrm_api('Address', 'create', $create_address_query);
			if ($create_address_result['is_error']) {
				error_log("org.muslimehelfen.mhapi: API Error while creating address for contact $contact_id: ".$create_address_result['error_message']);
			}
		}

		// TODO: create phone number


		// tag new contact for review
		$tag_search = civicrm_api('Tag', 'getsingle', array("name" => $new_contact_tag_name, "version" => 3));
		if ($tag_search['is_error']) {
			// create tag if not found
			$tag_create = civicrm_api('Tag', 'create', array("name" => $new_contact_tag_name, "description" => "Kontakte, die von z.B. der Spendenseite angelegt wurden.", "used_for" => "civicrm_contact", "version" => 3));
			if ($tag_create['is_error']) {
				error_log("org.muslimehelfen.mhapi: Cannot create tag '$new_contact_tag_name': ".$tag_create['error_message']);
			} else {
				$tag_id = $tag_create['id'];
			}
		} else {
			$tag_id = $tag_search['id'];
		}

		if ($tag_id) {
			$tag_set = civicrm_api('EntityTag', 'create', array("contact_id" => $contact_id, "tag_id" => $tag_id, "version" => 3));
			if ($tag_set['is_error']) {
				error_log("org.muslimehelfen.mhapi: Cannot set tag $tag_id for contact $contact_id: ".$tag_set['error_message']);
			}
		}

		
	} elseif ($contact_data) {
		// generate a list of differing values
		$contact_data['country'] = $id2country[$contact_data['country_id']];
		$differing_attributes = array();
		foreach ([$contact_parameters, $contact_address_parameters, $contact_phone_parameters] as $parameter_list) {
			foreach ($parameter_list as $key) {
				if ($params[$key]) {
					if (strtolower($params[$key])!=strtolower($contact_data[$key])) {
						$differing_attributes[$key] = $params[$key];
					}
				}
			}
		}

		// create activity only of differing attributes were detected!
		if (count($differing_attributes) > 0) {
			// retrieve activity ID 

			$activity_type_id = _mh_lookup_option_value('activity_type', $activity_type_name);
			if (!$activity_type_id) {
				// not there? create it!
				$create_activity_type_query = array();
				$create_activity_type_query['version'] = 3;
				$create_activity_type_query['label'] = $activity_type_name;
				$create_activity_type_query['weight'] = 100; // ?
				$create_activity_type_query['is_active'] = 1;
				$create_activity_type_result = civicrm_api('ActivityType', 'create', $create_activity_type_query);
				if ($create_activity_type_result['is_error']) {
					error_log("org.muslimehelfen.mhapi: Can not find or create activity type '$activity_type_name': ".$create_activity_type_result['error_message']);
				} else {
					$activity_type_id = $create_activity_type_result['values'][0]['value'];
				}
			}

			if ($activity_type_id) {
				error_log($activity_type_id);
				// Compile activity message
				$text = "<h3>Abweichende Angaben zu Kontakt ${contact_data['sort_name']}.</h3>\n";
				$text .= "<table><thead><th>Attribut</th><th>aktueller Wert</th><th>übertragener Wert</th></thead><tbody>\n";
				foreach ($differing_attributes as $key => $new_value) {
					$text .= "<tr><td>".ts($key)."</td><td>".$contact_data[$key]."</td><td>$new_value</td></tr>\n";
				}
				$text .= "</tbody></table>";

				// create the activity
				$create_activity_query = array();
				$create_activity_query['version'] = 3;
				$create_activity_query['source_contact_id'] = $contact_id;
				$create_activity_query['activity_type_id'] = $activity_type_id;
				$create_activity_query['subject'] = "Adressänderung für Kontakt aus Spendenformular";
				$create_activity_query['details'] = $text;
				$create_activity_query['status_id'] = _mh_lookup_option_value('activity_status', 'Geplant');
				$create_activity_result = civicrm_api('Activity', 'create', $create_activity_query);
				if ($create_activity_result['is_error']) {
					error_log("org.muslimehelfen.mhapi: Can not create activity: ".$create_activity_result['error_message']);
				} else {
					// set the activity as a target
					$activity_id = $create_activity_result['id'];
					CRM_Core_DAO::executeQuery("INSERT IGNORE INTO civicrm_activity_target (`activity_id`, `target_contact_id`) VALUES ($activity_id, $contact_id);");
				}
			}
		}
	}

	// reply 
	$reply = array();
	$reply['contact_id'] = $contact_data['id'];
	$reply['external_identifier'] = $contact_data['external_identifier'];

	return civicrm_api3_create_success(array($contact_id => $reply), array(), 'MhApi', 'getcontact');
}






/**
 * Will create a new contribution, looking up the state and type ids
 * 
 */
function civicrm_api3_mh_api_addcontribution($params) {
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
	$params['receive_date'] = date('YmdHis');
	$params['source'] = "Online-Spende";
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
		error_log("org.muslimehelfen.mhapi: API Error: ".$group_query['error_message']);
		return NULL;
	}
	if ($group_query['count']==0) {
		error_log("org.muslimehelfen.mhapi: Couldn't find option group: ".$group_name);
		return NULL;
	}
	// query the option value
	$value_query = civicrm_api('OptionValue', 'get', array('version' => 3, 'sequential' => 1, 'option_group_id' => $group_query['id'], 'label' => $value_name));
	//error_log(var_export($value_query, true));
	if ($value_query['is_error']) {
		error_log("org.muslimehelfen.mhapi: API Error: ".$value_query['error_message']);
		return NULL;
	}
	if ($value_query['count']==0) {
		error_log("org.muslimehelfen.mhapi: Couldn't find option value: ".$value_name);
		return NULL;
	}
	return $value_query['values'][0]['value'];
}


/**
 * creates a new array with only the $keys copied from the source
 */
function array_section($source, $keys) {
	$target = array();
	foreach ($keys as $key) {
		if (isset($source[$key])) {
			$target[$key] = $source[$key];
		}
	}
	return $target;
}
