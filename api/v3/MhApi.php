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
	$new_address_location = 1; // "Privat"
	$activity_type_name = "Adressprüfung";
	$contact_parameters = array('email', 'first_name', 'last_name', 'contact_type', 'prefix', 'gender');
	$contact_address_parameters = array('street_address', 'country', 'postal_code', 'city');
	$contact_phone_parameters = array('phone');
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
		$create_query['source'] = 'Website';
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
				$create_address_query['country_id'] = $country2id[$country_name];
			} else {
				error_log("org.muslimehelfen.mhapi: Unknown country '$country_name'.");
			}

			$create_address_result = civicrm_api('Address', 'create', $create_address_query);
			if ($create_address_result['is_error']) {
				error_log("org.muslimehelfen.mhapi: API Error while creating address for contact $contact_id: ".$create_address_result['error_message']);
			}
		}

		// create phone number
		if ($params['phone']) {
			$create_phone = civicrm_api('Phone', 'create', array("contact_id" => $contact_id, "phone" => $params['phone'], "version" => 3));
			if ($create_phone['is_error']) {
				error_log("org.muslimehelfen.mhapi: API Error while creating phone for contact $contact_id: ".$create_address_result['error_message']);
			}
		}

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
		foreach (array($contact_parameters, $contact_address_parameters, $contact_phone_parameters) as $parameter_list) {
			foreach ($parameter_list as $key) {
				if ($params[$key]) {
					if (strtolower($params[$key])!=strtolower($contact_data[$key])) {
						$differing_attributes[$key] = $params[$key];
					}
				}
			}
		}

		// FIXME: there is still a bug with the country...disable for the moment
		if (isset($differing_attributes['country'])) unset($differing_attributes['country']);

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
					if (CRM_Utils_System::version() >= '4.4.1') {
						CRM_Core_DAO::executeQuery("INSERT IGNORE INTO civicrm_activity_contact (`activity_id`, `contact_id`, `record_type_id`) VALUES ($activity_id, $contact_id, 3);");
					} else {
						// this doesn't work any more with 4.4.x
						CRM_Core_DAO::executeQuery("INSERT IGNORE INTO civicrm_activity_target (`activity_id`, `target_contact_id`) VALUES ($activity_id, $contact_id);");
					}
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
	$FINANCIAL_TYP_MAPPING = array(
		"Brunnen" 					=> "Brunnen/Wasser",
		"Fidjah/Kaffara"			=> "Fijah/Kaffara",
		"Iftarpaket"				=> "Spende ungebunden",
		"Meine Schwestern"			=> "Spende ungebunden",
		"Nothilfe"					=> "Not- und Katastrophenhilfe",
		"Ramadan-Paket"				=> "Spende ungebunden",
		"Ramadanhilfe"				=> "Spende ungebunden",
		"Sadaqa/Spende"				=> "Spende ungebunden",
		"Selbsthilfe"				=> "Spende ungebunden",
		"Winterhilfe"				=> "Spende ungebunden",
		"Wo es am nötigsten ist"	=> "Spende ungebunden",
		"Zakatu-l-Fitr"				=> "Zakau-l-fitr"
	);
	
	// look up financial type id
	if (isset($params['financial_type']) && isset($FINANCIAL_TYP_MAPPING[$params['financial_type']])) {
		$params['financial_type'] = $FINANCIAL_TYP_MAPPING[$params['financial_type']];
	}
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

	// check/set source
	if (!isset($params['source'])) {
		$params['source'] = "Online-Spende";		
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

	// SWITCH by payment type
	if (!isset($params['payment_instrument'])) {
		return civicrm_api3_create_error("payment_instrument not given.");
	} else if ($params['payment_instrument']=='SEPA DD One-off Transaction') {
		$contribution_result = _mh_create_sepa_contribution($params, 'OOFF');
	} else if ($params['payment_instrument']=='SEPA DD Recurring Transaction') {
		$contribution_result = _mh_create_sepa_contribution($params, 'RCUR');
	// not yet active: } else if ($params['payment_instrument']=='Paypal') {
	//	$contribution_result = _mh_create_paypal_contribution($params);
	} else {
		$contribution_result = _mh_create_contribution($params);
	}

	if ($contribution_result['is_error']) {
		return civicrm_api3_create_error("API Error: ".$contribution_result['error_message']);
	} else {
		// everything seems to be fine, add a note if requested
		if (isset($params['notes'])) {
			if ($params['notes']==' /   / ') $params['notes']='';
			if (strlen($params['notes'])>0) {
				// add note
				$create_note = array();
				$create_note['version'] = 3;
				$create_note['sequential'] = 1;
				$create_note['entity_table'] = 'civicrm_contribution';
				$create_note['entity_id'] = $contribution_result['id'];
				$create_note['note'] = $params['notes'];
				$create_note['privacy'] = 0;
				$create_note_result = civicrm_api('Note', 'create', $create_note);
				if ($create_note_result['is_error']) {
					return civicrm_api3_create_error("API Error: ".$create_note_result['error_message']);
				}
			}
		}
		return civicrm_api3_create_success($contribution_result['values'], array(), 'MhApi', 'addcontribution');
	}
}



/*********************************************************************************
 **								Contribution Creators    						**
 ********************************************************************************/

function _mh_create_contribution($params) {
	if (!isset($params['payment_instrument_id'])) {
		$params['payment_instrument_id'] = _mh_lookup_option_value('payment_instrument', $params['payment_instrument']);
		if ($params['payment_instrument_id']==NULL) {
			return civicrm_api3_create_error("Cannot resolve payment_instrument ".$params['payment_instrument']);
		}
		unset($params['payment_instrument']);
	}

	// set contribution status to 2 (Pending)
	$params['contribution_status_id'] = 2;
	$params['is_pay_later'] = 1;
	if (!isset($params['contribution_status'])) unset($params['contribution_status']);
	/* setting of status by parameter disabled
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
	}*/

	// then, create the contribution
	$params['version'] = 3;
	$params['sequential'] = 1;
	$params['receive_date'] = date('YmdHis');
	unset($params['check_permissions']);
	return civicrm_api('Contribution', 'create', $params);
}

function _mh_create_paypal_contribution($params) {
	if (!isset($params['payment_instrument_id'])) {
		$params['payment_instrument_id'] = _mh_lookup_option_value('payment_instrument', $params['payment_instrument']);
		if ($params['payment_instrument_id']==NULL) {
			return civicrm_api3_create_error("Cannot resolve payment_instrument ".$params['payment_instrument']);
		}
		unset($params['payment_instrument']);
	}

	// set contribution status to 1 (closed)
	if (!isset($params['contribution_status'])) unset($params['contribution_status']);
	unset($params['check_permissions']);

	$params['contribution_status_id'] = 1;
	$params['version'] = 3;
	$params['sequential'] = 1;
	$params['receive_date'] = date('YmdHis');
	return civicrm_api('Contribution', 'create', $params);
}

function _mh_create_sepa_contribution($params, $mode) {
	if (!isset($params['payment_instrument_id'])) {
		$params['payment_instrument_id'] = _mh_lookup_option_value('payment_instrument', $params['payment_instrument']);
		if ($params['payment_instrument_id']==NULL) {
			return civicrm_api3_create_error("Cannot resolve payment_instrument ".$params['payment_instrument']);
		}
		unset($params['payment_instrument']);
	}

	// some sanity checks
	if (!isset($params['iban']) || strlen($params['iban'])<12) {
		return array('is_error'=>1, 'error_message'=>"Invalid 'iban' parameter.");
	} else if (!isset($params['bic']) || strlen($params['bic'])<6) {
		return array('is_error'=>1, 'error_message'=>"Invalid 'bic' parameter.");
	} else if (!isset($params['datum']) || strlen($params['datum'])!=10) {
		return array('is_error'=>1, 'error_message'=>"Invalid 'datum' parameter.");
	}

	// lookup creditor
	$creditor = civicrm_api('SepaCreditor', 'getsingle', array('version'=>3, 'mandate_active'=>1));
	if ($creditor['is_error']) {
		return $creditor;
	}

	// create mandate and contribution
	if ($mode=='OOFF') {
	    // first create a contribution
	    $contribution_data = array(
	        'version'                   => 3,
	        'contact_id'                => $params['contact_id'],
	        'total_amount'              => $params['total_amount'],
	        'campaign_id'               => $params['campaign_id'],
	        'financial_type_id'         => $params['financial_type_id'],
	        'payment_instrument_id'     => $params['payment_instrument_id'],
	        'contribution_status_id'    => 2,
	        'receive_date'              => $params['datum'],
	        'source'                    => $params['source'],
	      );

	    $contribution = civicrm_api('Contribution', 'create', $contribution_data);
	    if ($contribution['is_error']) {
	    	return $contribution;
	    }

	    // next, create mandate
	    $mandate_data = array(
	        'version'                   => 3,
	        'debug'                     => 1,
	        'reference'                 => "WILL BE SET BY HOOK",
	        'contact_id'                => $params['contact_id'],
	        'source'                    => $params['source'],
	        'entity_table'              => 'civicrm_contribution',
	        'entity_id'                 => $contribution['id'],
	        'creation_date'             => date('YmdHis'),
	        'validation_date'           => date('YmdHis'),
	        'date'                      => $params['datum'],
	        'iban'                      => $params['iban'],
	        'bic'                       => $params['bic'],
	        'status'                    => 'OOFF',
	        'type'                      => 'OOFF',
	        'creditor_id'               => $creditor['id'],
	        'is_enabled'                => 1,
	      );
	    // call the hook for mandate generation
	    // TODO: Hook not working: CRM_Utils_SepaCustomisationHooks::create_mandate($mandate_data);
	    sepa_civicrm_create_mandate($mandate_data);

	    $mandate = civicrm_api('SepaMandate', 'create', $mandate_data);
	    if ($mandate['is_error']) {
	    	return $mandate;
	    } else {
	    	// everyhing o.k
		    return $contribution;
	    }

	} else {
		return civicrm_api3_create_error("SEPA recurring contributions not yet implemented");		
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
