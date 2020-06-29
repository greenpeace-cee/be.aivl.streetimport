<?php
/*-------------------------------------------------------------+
| GP StreetImporter Record Handlers                            |
| Copyright (C) 2017 SYSTOPIA                                  |
| Author: B. Endres (endres -at- systopia.de)                  |
| http://www.systopia.de/                                      |
+--------------------------------------------------------------*/

define('TM_KONTAKT_RESPONSE_OFFF_SPENDE',            3);

define('TM_KONTAKT_RESPONSE_ZUSAGE_FOERDER',         1);
define('TM_KONTAKT_RESPONSE_ZUSAGE_GUARDIAN',       51);
define('TM_KONTAKT_RESPONSE_ZUSAGE_FLOTTE',         53);
define('TM_KONTAKT_RESPONSE_ZUSAGE_ARKTIS',         54);
define('TM_KONTAKT_RESPONSE_ZUSAGE_DETOX',          55);
define('TM_KONTAKT_RESPONSE_ZUSAGE_WAELDER',        57);
define('TM_KONTAKT_RESPONSE_ZUSAGE_GP4ME',          58);
define('TM_KONTAKT_RESPONSE_ZUSAGE_ATOM',           59);

define('TM_KONTAKT_RESPONSE_KONTAKT_STORNO_ZS',     30);
define('TM_KONTAKT_RESPONSE_KONTAKT_STORNO_ZSO',    31);
define('TM_KONTAKT_RESPONSE_KONTAKT_STORNO_SMS',    32);
define('TM_KONTAKT_RESPONSE_KONTAKT_STORNO_DONE',   33);

define('TM_KONTAKT_RESPONSE_KONTAKT_RESCUE',        24);
define('TM_KONTAKT_RESPONSE_KONTAKT_LOESCHEN',      25);
define('TM_KONTAKT_RESPONSE_KONTAKT_STILLEGEN',     26);
define('TM_KONTAKT_RESPONSE_NICHT_KONTAKTIEREN',    27);
define('TM_KONTAKT_RESPONSE_KONTAKT_VERSTORBEN',    40);
define('TM_KONTAKT_RESPONSE_KONTAKT_ANRUFSPERRE',   41);

define('TM_KONTAKT_RESPONSE_KONTAKT_KEIN_ANSCHLUSS',    90);
define('TM_KONTAKT_RESPONSE_KONTAKT_NICHT_ERREICHT',    91);
define('TM_KONTAKT_RESPONSE_KONTAKT_KEIN_KONTAKT',      92);
define('TM_KONTAKT_RESPONSE_KONTAKT_NICHT_ANGEGRIFFEN', 93);
define('TM_KONTAKT_RESPONSE_POTENTIAL_IDENTITY_CHANGE', 94);



define('TM_PROJECT_TYPE_CONVERSION',   'umw'); // Umwandlung
define('TM_PROJECT_TYPE_UPGRADE',      'upg'); // Upgrade
define('TM_PROJECT_TYPE_REACTIVATION', 'rea'); // Reaktivierung
define('TM_PROJECT_TYPE_RESEARCH',     'rec'); // Recherche
define('TM_PROJECT_TYPE_SURVEY',       'umf'); // Umfrage
define('TM_PROJECT_TYPE_LEGACY',       'leg'); // Legacy
define('TM_PROJECT_TYPE_MIDDLE_DONOR', 'mdu'); // Middle-Donor - two subtypes:
define('TM_PROJECT_TYPE_MD_UPGRADE',   'mdup');//     subtype 1: upgrade
define('TM_PROJECT_TYPE_MD_CONVERSION','mdum');//     subtype 2: conversion (Umwandlung)


/**
 * GP TEDI Handler
 *
 * @author Björn Endres (SYSTOPIA) <endres@systopia.de>
 * @license AGPL-3.0
 */
class CRM_Streetimport_GP_Handler_TEDIContactRecordHandler extends CRM_Streetimport_GP_Handler_TMRecordHandler {
  use CRM_Streetimport_GP_Utils_OutgoingCallTrait;

  /**
   * Check if the given handler implementation can process the record
   *
   * @param $record  an array of key=>value pairs
   * @return true or false
   */
  public function canProcessRecord($record, $sourceURI) {
    $parsedFileName = $this->parseTmFile($sourceURI);
    return ($parsedFileName && $parsedFileName['file_type'] == 'Kontakte' && $parsedFileName['tm_company'] == 'tedi');
  }

  /**
   * Process the given record
   *
   * @param $record  an array of key=>value pairs
   * @param $sourceURI
   *
   * @return true
   * @throws \CiviCRM_API3_Exception
   */
  public function processRecord($record, $sourceURI) {
    $config = CRM_Streetimport_Config::singleton();
    $this->file_name_data = $this->parseTmFile($sourceURI);

    $contact_id = $this->getContactID($record);
    if (empty($contact_id)) {
      $this->logger->logImport($record, FALSE, $config->translate('TM Contact'));
      if ($this->isAnonymized($record)) {
        return $this->logger->logWarning("Contact [{$record['id']}] was anonymized, skipping.", $record);
      } else {
        return $this->logger->logError("Contact [{$record['id']}] couldn't be identified.", $record);
      }
    }

    // TODO: remove this workaround once TEDI stops sending files with full country names
    if (!empty($record['Land']) && strlen($record['Land']) != 2) {
      $record['Land'] = '';
    }

    // only perform these actions if the contact was actually reached
    if ($this->isContactReachedResponse($record['Ergebnisnummer'])) {
      // apply contact base data updates if provided
      // FIELDS: nachname  vorname firma TitelAkademisch TitelAdel TitelAmt  Anrede  geburtsdatum  geburtsjahr strasse hausnummer  hausnummernzusatz Land PLZ Ort email
      $this->performContactBaseUpdates($contact_id, $record);
      // Sign up for newsletter
      // FIELDS: emailNewsletter
      if ($this->isTrue($record, 'emailNewsletter')) {
        $newsletter_group_id = $config->getNewsletterGroupID();
        $this->addContactToGroup($contact_id, $newsletter_group_id, $record);
      }
      // If "X" then set  "rts_counter" in table "civicrm_value_address_statistics"  to "0"
      // FIELDS: AdresseGeprueft
      if ($this->isTrue($record, 'AdresseGeprueft')) {
        $this->addressValidated($contact_id, $record);
      }
    }

    /************************************
     *           VERIFICATION           *
     ***********************************/
    $project_type_full = strtolower($this->file_name_data['project1']);
    $project_type = strtolower(substr($this->file_name_data['project1'], 0, 3));
    $modify_command = 'update';
    $contract_id = NULL;
    $contract_id_required = FALSE;
    switch ($project_type) {
      case TM_PROJECT_TYPE_CONVERSION:
        if (!empty($this->getContractID($contact_id, $record))) {
          $this->logger->logImport($record, FALSE, $config->translate('TM Contact'));
          return $this->logger->logError("Conversion projects shouldn't provide a contract ID", $record);
        }
        break;

      case TM_PROJECT_TYPE_UPGRADE:
        $modify_command = 'update';
        $contract_id_required = TRUE;
        break;

      case TM_PROJECT_TYPE_REACTIVATION:
      case TM_PROJECT_TYPE_RESEARCH:
        $modify_command = 'revive';
        $contract_id_required = TRUE;
        break;

      case TM_PROJECT_TYPE_SURVEY:
        // Nothing to do here?
        break;

      case TM_PROJECT_TYPE_LEGACY:
        $case_count = civicrm_api3('Case', 'getcount', [
          'case_type_id' => 'legat',
          'is_deleted'   => 0,
          'contact_id'   => $contact_id,
        ]);
        if ($case_count != 1) {
          $this->logger->logImport($record, FALSE, $config->translate('TM Contact'));
          return $this->logger->logError("Legacy contact should have exactly one legacy case, found $case_count", $record);
        }
        break;

      case TM_PROJECT_TYPE_MIDDLE_DONOR:
        if ($project_type_full == TM_PROJECT_TYPE_MD_UPGRADE) {
          $modify_command = 'update';
          $contract_id_required = FALSE;
          break;

        } elseif ($project_type_full == TM_PROJECT_TYPE_MD_CONVERSION) {
          // copied from TM_PROJECT_TYPE_CONVERSION
          if (!empty($this->getContractID($contact_id, $record))) {
            $this->logger->logImport($record, FALSE, $config->translate('TM Contact'));
            return $this->logger->logError("Conversion projects shouldn't provide a contract ID", $record);
          }
          break;
        }

      default:
        $this->logger->abort("Unknown project type {$project_type}. Processing stopped.", $record);
        break;
    }

    if (!empty($record['Land']) && is_null($this->_getCountryByISOCode($record['Land']))) {
      $this->logger->logImport($record, FALSE, $config->translate('TM Contact'));
      return $this->logger->logError("Invalid Country for Contact [{$record['id']}]: '{$record['Land']}'", $record);
    }

    $parent_id = $this->getActivityId($record) ?? $this->getParentActivityId(
      $contact_id,
      $this->getCampaignID($record),
      [
        'activity_types' => ['Action'],
        'min_date' => date('Y-m-d', strtotime('-90 days', strtotime($this->getDate($record)))),
        'max_date' => date('Y-m-d', strtotime($this->getDate($record))) ,
      ]
    );
    if (empty($parent_id)) {
      $this->logger->logWarning('Could not find parent action activity', $record);
    }

    $createResponse = TRUE;

    /************************************
     *         MAIN PROCESSING          *
     ***********************************/
    switch ($record['Ergebnisnummer']) {
      case TM_KONTAKT_RESPONSE_ZUSAGE_FOERDER:
      case TM_KONTAKT_RESPONSE_ZUSAGE_FLOTTE:
      case TM_KONTAKT_RESPONSE_ZUSAGE_ARKTIS:
      case TM_KONTAKT_RESPONSE_ZUSAGE_DETOX:
      case TM_KONTAKT_RESPONSE_ZUSAGE_WAELDER:
      case TM_KONTAKT_RESPONSE_ZUSAGE_GP4ME:
      case TM_KONTAKT_RESPONSE_ZUSAGE_ATOM:
      case TM_KONTAKT_RESPONSE_KONTAKT_RESCUE:
      case TM_KONTAKT_RESPONSE_ZUSAGE_GUARDIAN:
        // this is a conversion/upgrade
        $contract_id = $this->getContractID($contact_id, $record);
        if (empty($contract_id)) {
          // make sure this is no mistake (see GP-1123)
          if ($contract_id_required) {
            // this whole line should not be imported (see GP-1123)
            return $this->logger->abort("Format violation, the record type requires a contract_id.", $record);
          } else {
            $contract_id = $this->createContract($contact_id, $record);
            if (!empty($parent_id)) {
              $this->setContractActivityParent($contract_id, $parent_id);
            }
          }
        } else {
          // load the contract
          $contract  = $this->getContract($record, $contact_id);
          if (empty($contract)) {
            $this->logger->logError("Couldn't find contract to update (ID: {$contract_id})", $record);
          } else {
            // check if the 'active' state is right
            $is_active = $this->isContractActive($contract);
            if ($modify_command == 'update' && !$is_active) {
              $this->logger->logError("Update projects should refer to active contracts", $record);
            } elseif ($modify_command == 'revive' && $is_active) {
              $this->logger->logError("This project should only refer to inactive contracts", $record);
            } else {
              // submit membership type if there's a change
              if (   $record['Ergebnisnummer'] == TM_KONTAKT_RESPONSE_ZUSAGE_FOERDER
                  || $record['Ergebnisnummer'] == TM_KONTAKT_RESPONSE_KONTAKT_RESCUE) {
                // this simply means 'carry on' (see GP-1000/GP-1328)
                $membership_type_id = NULL;
              } else {
                $membership_type_id = $this->getMembershipTypeID($record);
              }

              // ALL GOOD: do the upgrade!
              $this->updateContract($contract_id, $contact_id, $record, $membership_type_id, $modify_command);
              if (!empty($parent_id)) {
                $this->setContractActivityParent($contract_id, $parent_id);
              }
            }
          }
        }
        break;

      case TM_KONTAKT_RESPONSE_OFFF_SPENDE:
        // create a simple OOFF mandate
        $this->createOOFFMandate($contact_id, $record);
        break;

      case TM_KONTAKT_RESPONSE_KONTAKT_STORNO_ZS:
      case TM_KONTAKT_RESPONSE_KONTAKT_STORNO_ZSO:
      case TM_KONTAKT_RESPONSE_KONTAKT_STORNO_SMS:
      case TM_KONTAKT_RESPONSE_KONTAKT_STORNO_DONE:
        // contact wants to cancel his/her contract
        $membership = $this->getContract($record, $contact_id);
        if ($membership) {
          $this->cancelContract($membership, $record);
          if (!empty($parent_id)) {
            $this->setContractActivityParent($membership['id'], $parent_id);
          }
        } else {
          $this->logger->logWarning("NO contract (membership) found.", $record);
        }
        break;

      case TM_KONTAKT_RESPONSE_KONTAKT_LOESCHEN:
        // contact wants to be erased from GP database
        $result = $this->disableContact($contact_id, 'erase', $record);
        if (!empty($parent_id)) {
          foreach ($result['cancelled_contracts'] as $membership_id) {
            $this->setContractActivityParent($membership_id, $parent_id);
          }
        }
        break;

      case TM_KONTAKT_RESPONSE_KONTAKT_STILLEGEN:
        // contact should be disabled
        $result = $this->disableContact($contact_id, 'disable', $record);
        if (!empty($parent_id)) {
          foreach ($result['cancelled_contracts'] as $membership_id) {
            $this->setContractActivityParent($membership_id, $parent_id);
          }
        }
        break;

      case TM_KONTAKT_RESPONSE_NICHT_KONTAKTIEREN:
        $this->disableContact($contact_id, 'deactivate', $record);
        break;

      case TM_KONTAKT_RESPONSE_KONTAKT_VERSTORBEN:
        // contact should be disabled
        $result = $this->disableContact($contact_id, 'deceased', $record);
        if (!empty($parent_id)) {
          foreach ($result['cancelled_contracts'] as $membership_id) {
            $this->setContractActivityParent($membership_id, $parent_id);
          }
        }
        break;

      case TM_KONTAKT_RESPONSE_KONTAKT_ANRUFSPERRE:
        // contact doesn't want to be called
        civicrm_api3('Contact', 'create', array(
          'id'           => $contact_id,
          'do_not_phone' => 1));
        break;

      case TM_KONTAKT_RESPONSE_POTENTIAL_IDENTITY_CHANGE:
        $createResponse = FALSE;
        break;

      default:
        // in all other cases nothing needs to happen except the
        //  to create the reponse activity, see below.
    }

    if ($createResponse) {
      // GENERATE RESPONSE ACTIVITY
      $this->createResponseActivity(
        $contact_id,
        $this->assembleResponseSubject($record['Ergebnisnummer'], $record['ErgebnisText']),
        $record
      );
    }


    /************************************
     *      SECONDARY PROCESSING        *
     ***********************************/

    // Add a note if requested
    // FIELDS: BemerkungFreitext
    if (!empty($record['BemerkungFreitext'])) {
      $this->createManualUpdateActivity($contact_id, $record['BemerkungFreitext'], $record);
    }

    // process additional fields
    // FIELDS: Bemerkung1  Bemerkung2  Bemerkung3  Bemerkung4  Bemerkung5 ...
    for ($i=1; $i <= 10; $i++) {
      if (!empty($record["Bemerkung{$i}"])) {
        $this->processAdditionalFeature($record["Bemerkung{$i}"], $contact_id, $contract_id, $record);
      }
    }

    // process JSON fields. (yes, JSON in a CSV file. don't judge.)
    for ($i=1; $i <= 10; $i++) {
      if (!empty($record["JSON{$i}"])) {
        $this->processJSON($record["JSON{$i}"], $contact_id, $contract_id, $record);
      }
    }

    $this->logger->logImport($record, true, $config->translate('TM Contact'));
  }

  /**
   * Extracts the specific activity date for this line
   */
  protected function getDate($record) {
    if (!empty($record['TagDerTelefonie'])) {
      return date('YmdHis', strtotime($record['TagDerTelefonie']));
    } else {
      return parent::getDate($record);
    }
  }

  /**
   * Apply contact base date updates (if present in the data)
   * FIELDS: nachname, vorname, firma, TitelAkademisch, TitelAdel, TitelAmt,  Anrede,
   * geburtsdatum, geburtsjahr, strasse, hausnummer,  hausnummernzusatz, PLZ, Ort, email
   *
   * @param $contact_id
   * @param $record
   *
   * @throws \CiviCRM_API3_Exception
   */
  public function performContactBaseUpdates($contact_id, $record) {
    //Contact Entity
    $contact_params = $this->getPreparedContactParams($record);
    $this->updateContact($contact_params, $contact_id, $record);

    //Address Entity
    $address_params = $this->getPreparedAddressParams($record);
    $this->createOrUpdateAddress($contact_id, $address_params, $record);

    //Email Entity
    if (!empty($record['email'])) {
      $this->addDetail($record, $contact_id, 'Email', ['email' => $record['email']], TRUE, ['is_primary' => TRUE]);
    }
  }

  /**
   * Update Contact
   *
   * @param $contact_params
   * @param $contact_id
   * @param $record
   *
   * @throws \CiviCRM_API3_Exception
   */
  private function updateContact($contact_params, $contact_id, $record) {
    if (empty($contact_params)) {
      return;
    }

    $contact_params['id'] = $contact_id;
    $this->resolveFields($contact_params, $record);

    if ($this->isContactParamAndContactIsIdentical($contact_id, $contact_params)) {
      return;
    }

    // check if contact is really individual
    $contact = civicrm_api3('Contact', 'getsingle', [
      'id' => $contact_id,
      'return' => 'contact_type,first_name,last_name,birth_date,prefix_id',
    ]);

    if ($contact['contact_type'] != 'Individual') {
      // this is NOT and Individual: create an activity (see GP-1229)
      unset($contact_params['id']);
      $this->createManualUpdateActivity(
        $contact_id,
        "Convert to 'Individual'",
        $record,
        'activities/ManualConversion.tpl',
        ['contact' => $contact, 'update' => $contact_params]);
      // $this->logger->logError("Contact [{$contact_id}] is not an Individual and cannot be updated. A manual update activity has been created.", $record);
    } else {

      // make sure we're not changing first_name,last_name,birth_date
      //  so we cannot accidentally change the IDENTITY of the contact
      //  Filling the attributes is ok, though
      $potential_identify_change = FALSE;
      $identity_parameters = ['contact_type', 'first_name', 'last_name', 'birth_date'];
      $diff_params = [];
      foreach ($identity_parameters as $identity_parameter) {
        $current_value = CRM_Utils_Array::value($identity_parameter, $contact);
        $future_value = CRM_Utils_Array::value($identity_parameter, $contact_params);
        if (!empty($current_value) && !empty($future_value)) {
          $current_value = $this->normalizeParameter($identity_parameter, $current_value);
          $future_value = $this->normalizeParameter($identity_parameter, $future_value);
          if ($current_value != $future_value) {
            // a change of the identity related parameters was requested
            $potential_identify_change = TRUE;
            $diff_params[] = $identity_parameter;
          }
        }
      }

      if ($potential_identify_change) {
        unset($contact_params['id']);
        if (CRM_Utils_Array::value('prefix_id', $contact) != CRM_Utils_Array::value('prefix_id', $contact_params)) {
          $diff_params[] = 'prefix_id';
        }
        $this->createManualUpdateActivity(
          $contact_id,
          "Potential Identity Change",
          $record,
          'activities/IdentityChange.tpl',
          ['contact' => $contact, 'update' => $contact_params, 'diff' => $diff_params]);
        $this->logger->logDebug("Detected potential identity change for contact [{$contact_id}]...flagged.", $record);

      } else {
        civicrm_api3('Contact', 'create', $contact_params);
        $config = CRM_Streetimport_Config::singleton();
        $this->createContactUpdatedActivity($contact_id, $config->translate('Contact Base Data Updated'), NULL, $record);
        $this->logger->logDebug("Contact [{$contact_id}] base data updated: " . json_encode($contact_params), $record);
      }
    }
  }

  /**
   * Check if contact's filed from param
   * and contact's filed from database are the same
   *
   * @param $contact_id
   * @param $contact_params
   *
   * @return bool
   */
  private function isContactParamAndContactIsIdentical($contact_id, $contact_params) {
    $params = array_keys($contact_params);
    $return_params = implode(',', array_keys($contact_params));

    try {
      $contact = civicrm_api3('Contact', 'getsingle', [
        'id' => $contact_id,
        'return' => $return_params,
      ]);
    } catch (CiviCRM_API3_Exception $e) {
      return false;
    }
    foreach ($params as $param) {
      $current_value = $this->normalizeParameter($param, $contact[$param]);
      $future_value = $this->normalizeParameter($param, $contact_params[$param]);
      if ($current_value != $future_value) {
        return false;
      }
    }

    return true;
  }

  /**
   * Normalize contact parameter value for comparison
   *
   * @param $name parameter name
   * @param $value parameter value
   *
   * @return string
   */
  private function normalizeParameter($name, $value) {
    if (!empty($value) && $name == 'birth_date') {
      return date('Y-m-d', strtotime($value));
    }
    return trim(strtolower($value));
  }

  /**
   * Gets prepared Address params
   *
   * @param $record
   *
   * @return array
   */
  private function getPreparedAddressParams($record) {
    $address_base_attributes = [
      'PLZ' => 'postal_code',
      'Ort' => 'city',
      'strasse' => 'street_address_1',
      'hausnummer' => 'street_address_2',
      'hausnummernzusatz' => 'street_address_3',
    ];

    // extract attributes
    $address_update = [];
    foreach ($address_base_attributes as $record_key => $civi_key) {
      if (!empty($record[$record_key])) {
        $address_update[$civi_key] = trim($record[$record_key]);
      }
    }

    // compile street address
    $street_address = '';
    for ($i=1; $i <= 3; $i++) {
      if (isset($address_update["street_address_{$i}"])) {
        $street_address = trim($street_address . ' ' . $address_update["street_address_{$i}"]);
        unset($address_update["street_address_{$i}"]);
      }
    }
    if (!empty($street_address)) {
      $address_update['street_address'] = $street_address;
    }

    if (!empty($record['Land'])) {
      $address_update['country_id'] = $record['Land'];
    }

    return $address_update;
  }

  /**
   * Gets prepared Contact params
   *
   * @param $record
   *
   * @return array
   */
  private function getPreparedContactParams($record) {
    $config = CRM_Streetimport_Config::singleton();

    //Firma won't be processed any more (GP-1414)
    //example: 'firma' => 'current_employer',
    $contact_base_attributes = [
      'nachname' => 'last_name',
      'vorname' => 'first_name',
      'Anrede' => 'prefix_id',
      'geburtsdatum' => 'birth_date',
      'geburtsjahr' => $config->getGPCustomFieldKey('birth_year'),
      'TitelAkademisch' => 'formal_title_1',
      'TitelAdel' => 'formal_title_2',
      'TitelAmt' => 'formal_title_3',
    ];

    // extract attributes
    $contact_base_update = [];
    foreach ($contact_base_attributes as $record_key => $civi_key) {
      if (!empty($record[$record_key])) {
        $contact_base_update[$civi_key] = trim($record[$record_key]);
      }
    }

    // compile formal title
    $formal_title = '';
    for ($i=1; $i <= 3; $i++) {
      if (isset($contact_base_update["formal_title_{$i}"])) {
        $formal_title = trim($formal_title . ' ' . $contact_base_update["formal_title_{$i}"]);
        unset($contact_base_update["formal_title_{$i}"]);
      }
    }

    if (!empty($formal_title)) {
      $contact_base_update['formal_title'] = $formal_title;
    }

    return $contact_base_update;
  }

  /**
   * Mark the given address as valid by resetting the RTS counter
   *
   * @param $contact_id
   * @param $record
   */
  public function addressValidated($contact_id, $record) {
    $config = CRM_Streetimport_Config::singleton();

    $address_id = $this->getAddressId($contact_id, $record);
    if ($address_id) {
      civicrm_api3('Address', 'create', array(
        'id' => $address_id,
        $config->getGPCustomFieldKey('rts_counter') => 0));
      $this->logger->logDebug("RTS counter for address [{$address_id}] (contact [{$contact_id}]) was reset.", $record);
    } else {
      $this->logger->logDebug("RTS counter couldn't be reset, (primary) address for contact [{$contact_id}] couldn't be identified.", $record);
    }
  }

  /**
   * Process additional feature from the semi-formal "Bemerkung" note fields
   * Those can trigger certain actions within Civi as mentioned in doc
   * "20131107_Responses_Bemerkungen_1-5"
   *
   * @param $note
   * @param $contact_id
   * @param $contract_id
   * @param $record
   */
  public function processAdditionalFeature($note, $contact_id, $contract_id, $record) {
    $config = CRM_Streetimport_Config::singleton();
    $this->logger->logDebug("Contact [{$contact_id}] wants '{$note}'", $record);
    switch ($note) {
       case 'erhält keine Post':
         // Marco: "Posthäkchen setzen, Adresse zurücksetzen, Kürzel 15 + 18 + ZO löschen"
         // i.e.: 1) allow mailing
         civicrm_api3('Contact', 'create', array(
          'id' => $contact_id,
          'do_not_mail' => 0));

         // i.e.: 2) mark address as validated
         $this->addressValidated($contact_id, $record);

         // i.e.: 3) remove from groups
         $this->removeContactFromGroup($contact_id, $config->getGPGroupID('kein ACT'), $record);
         $this->removeContactFromGroup($contact_id, $config->getGPGroupID('ACT nur online'), $record);
         $this->removeContactFromGroup($contact_id, $config->getGPGroupID('Zusendungen nur online'), $record);
         break;

       case 'kein Telefonkontakt erwünscht':
         // Marco: "Telefonkanal schließen"
         civicrm_api3('Contact', 'create', array(
          'id' => $contact_id,
          'do_not_phone' => 1));
         $this->logger->logDebug("Setting 'do_not_phone' for contact [{$contact_id}].", $record);
         break;

       case 'keine Kalender senden':
         // Marco: 'Negativleistung "Kalender"'
         $this->addContactToGroup($contact_id, $config->getGPGroupID('kein Kalender'), $record);
         $this->logger->logDebug("Added contact [{$contact_id}] to group 'kein Kalender'.", $record);
         break;

       case 'nur Vereinsmagazin, sonst keine Post':
       case 'nur Vereinsmagazin mit Spendenquittung':
         // Marco: Positivleistung "Nur ACT"
         $this->addContactToGroup($contact_id, $config->getGPGroupID('Nur ACT'), $record);
         $this->logger->logDebug("Added contact [{$contact_id}] to group 'Nur ACT'.", $record);
         break;

       case 'nur Vereinsmagazin mit 1 Mailing':
         // Marco: Positivleistung "Nur ACT"
         $this->addContactToGroup($contact_id, $config->getGPGroupID('Nur ACT'), $record);
         $this->logger->logDebug("Added contact [{$contact_id}] to group 'Nur ACT'.", $record);

         //  + alle Monate bis auf Oktober deaktivieren
         $dm_restrictions = $config->getGPCustomFieldKey('dm_restrictions');
         civicrm_api3('Contact', 'create', array(
            'id'             => $contact_id,
            $dm_restrictions => '1')); // one mailing only
         $this->logger->logDebug("Contact [{$contact_id}]: DM restrictions set to '1'.", $record);
         break;

       case 'möchte keine Incentives':
         // Marco: Negativleistung " Geschenke"
         $this->addContactToGroup($contact_id, $config->getGPGroupID('keine Geschenke'), $record);
         $this->logger->logDebug("Added contact [{$contact_id}] to group 'keine Geschenke'.", $record);
         break;

       case 'möchte keine Postsendungen':
         // Marco: Postkanal schließen
         civicrm_api3('Contact', 'create', array(
          'id' => $contact_id,
          'do_not_mail' => 1));
         $this->logger->logDebug("Setting 'do_not_mail' for contact [{$contact_id}].", $record);
         break;

       case 'möchte max 4 Postsendungen':
         // Marco: Leistung Januar, Februar, März, Mai, Juli, August, September, November deaktivieren
         $dm_restrictions = $config->getGPCustomFieldKey('dm_restrictions');
         civicrm_api3('Contact', 'create', array(
            'id'             => $contact_id,
            $dm_restrictions => '4')); // 4 mailings
         $this->logger->logDebug("Contact [{$contact_id}]: DM restrictions set to '4'.", $record);
         break;

       case 'Postsendung nur bei Notfällen':
         // Marco: Im Leistungstool alle Monate rot einfärben
         $dm_restrictions = $config->getGPCustomFieldKey('dm_restrictions');
         civicrm_api3('Contact', 'create', array(
            'id'             => $contact_id,
            $dm_restrictions => '0')); // only emergency mailings
         $this->logger->logDebug("Contact [{$contact_id}]: DM restrictions set to '0'.", $record);
         break;

       case 'hat kein Konto':
       case 'möchte nur Jahresbericht':
         // do nothing according to '20131107_Responses_Bemerkungen_1-5.xlsx'
         $this->logger->logDebug("Nothing to be done for feature '{$note}'", $record);
         break;

       case 'Bankdaten gehören nicht dem Spender':
       case 'Spende wurde übernommen, Daten geändert':
       case 'erhält Post doppelt':
         // for these cases a manual update is required
         $this->createManualUpdateActivity($contact_id, $note, $record);
         $this->logger->logDebug("Manual update ticket created for contact [{$contact_id}]", $record);
         break;

       case 'Ratgeber verschickt':
         $channel_field = $config->getGPCustomFieldKey('Channel', 'Communication_Channel');
         $activityParams = [
           'activity_type_id'    => $config->getRatgeberVerschicktActivityType(),
           'status_id'           => 1, // Scheduled
           'campaign_id'         => $this->getCampaignID($record),
           'activity_date_time'  => $this->getDate($record),
           'source_contact_id'   => (int) $config->getCurrentUserID(),
           'target_contact_id'   => (int) $contact_id,
           'case_id'             => $this->getCaseIdByType($contact_id, 'Legat'),
           'medium_id'           => $this->getMediumID($record),
           $channel_field        => $this->getLegacyChannel(),
         ];
         $this->createActivity($activityParams, $record);
         $this->logger->logDebug("Created 'Ratgeber verschickt' activity for contact [{$contact_id}]", $record);
         break;

       case 'Legate Opt-in':
         $this->addContactToGroup($contact_id, $config->getGPGroupID('Legacy Opt-in'), $record);
         $this->logger->logDebug("Added contact [{$contact_id}] to group 'Legacy Opt-in'.", $record);
         break;

       case 'Legate Info ja':
         $this->removeContactFromGroup($contact_id, $config->getGPGroupID('keine Legacy Kommunikation'), $record);
         $this->logger->logDebug("Removed contact [{$contact_id}] from group 'keine Legacy Kommunikation'.", $record);
         break;

       case 'Legate Info nein':
         $this->addContactToGroup($contact_id, $config->getGPGroupID('keine Legacy Kommunikation'), $record);
         $this->logger->logDebug("Added contact [{$contact_id}] to group 'keine Legacy Kommunikation'.", $record);
         break;

       default:
         // maybe it's a T-Shirt?
         if (preg_match('#^(?P<shirt_type>M|W)/(?P<shirt_size>[A-Z]{1,2})/(?P<shirt_name>.+)?$#', $note, $match)) {
           // create a webshop activity (Activity type: ID 75)  with the status "scheduled"
           $this->createWebshopActivity($contact_id, $record, [
             'subject' => "order type {$match['shirt_name']} {$match['shirt_type']}/{$match['shirt_size']} AND number of items 1",
             $config->getGPCustomFieldKey('order_type')        => $match['shirt_name'],
             $config->getGPCustomFieldKey('order_count')       => 1,  // 1 x T-Shirt
             $config->getGPCustomFieldKey('shirt_type')        => $match['shirt_type'],
             $config->getGPCustomFieldKey('shirt_size')        => $match['shirt_size'],
             $config->getGPCustomFieldKey('linked_membership') => $contract_id,
           ]);
           break;
         }

         // maybe it's a legacy T-Shirt?
         if (preg_match('#^(?P<shirt_type>M|W)/(?P<shirt_size>[A-Z]{1,2})$#', $note, $match)) {
           // create a webshop activity (Activity type: ID 75)  with the status "scheduled"
           //  and in the field "order_type" option value 11 "T-Shirt"
           $this->createWebshopActivity($contact_id, $record, array(
             'subject' => "order type T-Shirt {$match['shirt_type']}/{$match['shirt_size']} AND number of items 1",
             $config->getGPCustomFieldKey('order_type')        => 11, // T-Shirt
             $config->getGPCustomFieldKey('order_count')       => 1,  // 1 x T-Shirt
             $config->getGPCustomFieldKey('shirt_type')        => $match['shirt_type'],
             $config->getGPCustomFieldKey('shirt_size')        => $match['shirt_size'],
             $config->getGPCustomFieldKey('linked_membership') => $contract_id,
             ));
           break;
         }

         // maybe it's a legacy status change?
         if (preg_match('#^Statusänderung:(?P<case_status>.*)$#', $note, $match)) {
           $case_id = $this->getCaseIdByType($contact_id, 'Legat');
           $this->updateCaseStatus($contact_id, $case_id, $match['case_status'], $record);
           $this->logger->logDebug("Changed case status of case [{$case_id}] to [{$match['case_status']}].", $record);
           break;
         }

         $this->logger->logError("Unknown feature '{$note}' ignored.", $record);
         return;
     }
  }

  /**
   * Extract the contract id from the record
   *
   * @param $contact_id
   * @param $record
   *
   * @return int|null
   */
  protected function getContractID($contact_id, $record) {
    if (empty($record['Vertragsnummer'])) {
      return NULL;
    }

    if ($this->isCompatibilityMode($record)) {
      // legacy files: look up via membership_imbid
      $config = CRM_Streetimport_Config::singleton();
      $membership_imbid =$config->getGPCustomFieldKey('membership_imbid');
      $membership = civicrm_api3('Membership', 'get', array(
        'contact_id'      => $contact_id,
        $membership_imbid => $record['Vertragsnummer']));
      return $membership['id'];
    } else {
      return (int) $record['Vertragsnummer'];
    }
  }

  /**
   * Get the requested membership type ID from the data record
   *
   * @param $record
   *
   * @return null|int
   */
  protected function getMembershipTypeID($record) {
    switch ($record['Ergebnisnummer']) {
      case TM_KONTAKT_RESPONSE_ZUSAGE_FOERDER:
        $name = 'Förderer';
        break;

      case TM_KONTAKT_RESPONSE_ZUSAGE_FLOTTE:
        $name = 'Flottenpatenschaft';
        break;

      case TM_KONTAKT_RESPONSE_ZUSAGE_ARKTIS:
        $name = 'arctic defender';
        break;

      case TM_KONTAKT_RESPONSE_ZUSAGE_DETOX:
        // TODO: is this correct?
        $name = 'Landwirtschaft';
        break;

      case TM_KONTAKT_RESPONSE_ZUSAGE_WAELDER:
        $name = 'Könige der Wälder';
        break;

      case TM_KONTAKT_RESPONSE_ZUSAGE_GP4ME:
        $name = 'Greenpeace for me';
        break;

      case TM_KONTAKT_RESPONSE_ZUSAGE_ATOM:
        $name = 'Atom-Eingreiftrupp';
        break;

      case TM_KONTAKT_RESPONSE_ZUSAGE_GUARDIAN:
        $name = 'Guardian of the Ocean';
        break;


      default:
        $this->logger->logError("No membership type can be derived from result code (Ergebnisnummer) '{$record['Ergebnisnummer']}'.", $record);
        return NULL;
    }

    // find a membership type with that name
    $membership_types = $this->getMembershipTypes();
    foreach ($membership_types as $membership_type) {
      if ($membership_type['name'] == $name) {
        return $membership_type['id'];
      }
    }

    $this->logger->logError("Membership type '{$name}' not found.", $record);
    return NULL;
  }

  /**
   * Take address data and see what to do with it:
   * - if it's not enough data -> create ticket (activity) for manual processing
   * - else: if no address is present -> create a new one
   * - else: if new data wouldn't replace ALL the data of the old address ->
   *   create ticket (activity) for manual processing
   * - else: update address
   *
   * @param $contact_id
   * @param $address_data
   * @param $record
   *
   * @throws \CiviCRM_API3_Exception
   */
  public function createOrUpdateAddress($contact_id, $address_data, $record) {
    if (empty($address_data)) return;

    $config = CRM_Streetimport_Config::singleton();
    $all_fields = $config->getAllAddressAttributes();
    if (!empty($address_data['country_id'])) {
      // check if fields other than country_id are set
      $fields_set = FALSE;
      $fields_without_country = array_diff($all_fields, ['country_id']);
      foreach ($fields_without_country as $field) {
        if (!empty($address_data[$field])) {
          $fields_set = TRUE;
        }
      }
      // if only country is set, skip address update
      if (!$fields_set) {
        $this->logger->logDebug("Ignoring address update with only country_id for contact [{$contact_id}]", $record);
        return;
      }
    }

    // check if address is complete
    $address_complete = TRUE;
    $required_attributes = $config->getRequiredAddressAttributes();
    foreach ($required_attributes as $required_attribute) {
      if (empty($address_data[$required_attribute])) {
        $address_complete = FALSE;
      }
    }

    if (!$address_complete) {
      $this->logger->logDebug("Manual address update required for [{$contact_id}] due to incomplete address.", $record);
      return $this->createManualUpdateActivity(
        $contact_id, 'Manual Address Update', $record, 'activities/ManualAddressUpdate.tpl',
        array('title'   => 'Please update contact\'s address (new address may be incomplete!)',
          'fields'  => $config->getAllAddressAttributes(),
          'address' => $address_data));
    }

    // find the old address
    $old_address_id = $this->getAddressId($contact_id, $record);
    if (!$old_address_id) {
      // CREATION (there is no address)
      $address_data['location_type_id'] = $config->getLocationTypeId();
      $address_data['contact_id'] = $contact_id;
      $this->resolveFields($address_data, $record);
      $this->setProvince($address_data);
      $this->logger->logDebug("Creating address for contact [{$contact_id}]: " . json_encode($address_data), $record);
      civicrm_api3('Address', 'create', $address_data);
      $template_data = [
        'fields'  => $config->getAllAddressAttributes(),
        'address' => $address_data,
      ];
      return $this->createContactUpdatedActivity(
        $contact_id,
        $config->translate('Contact Address Created'),
        $this->renderTemplate('activities/ManualAddressUpdate.tpl', $template_data),
        $record
      );
    }

    // load old address
    $old_address = civicrm_api3('Address', 'getsingle', array('id' => $old_address_id));

    // if old and new address is identical do nothing
    if ($this->isAddressIdentical($address_data, $old_address)) {
      $this->logger->logDebug("Contact Address not Updated, old and new address is identical [{$contact_id}]: " . json_encode($address_data), $record);
      return;
    }

    // check if we'd overwrite EVERY one the relevant fields
    // to avoid inconsistent addresses
    $full_overwrite = TRUE;
    foreach ($all_fields as $field) {
      if (empty($address_data[$field]) && !empty($old_address[$field])) {
        $full_overwrite = FALSE;
        break;
      }
    }

    $isCurrentCountryAustria = !empty($record['Land']) && trim($record['Land']) == CRM_Streetimport_GP_Utils_Address::AUSTRIA_ISO_CODE;
    $isRealAustriaAddress = FALSE;
    if ($isCurrentCountryAustria && !empty($record['strasse']) && !empty($record['PLZ']) && !empty($record['Ort'])) {
      $isRealAustriaAddress = CRM_Streetimport_GP_Utils_Address::isRealAddress(
        trim($record['Ort']),
        trim($record['PLZ']),
        trim($record['strasse'])
      );
    }

    if ($full_overwrite && (!$isCurrentCountryAustria || ($isCurrentCountryAustria && $isRealAustriaAddress))) {
      // this is a proper address update
      $address_data['id'] = $old_address_id;
      $this->setProvince($address_data);
      $this->logger->logDebug("Updating address for contact [{$contact_id}]: " . json_encode($address_data), $record);
      civicrm_api3('Address', 'create', $address_data);
      $this->addressValidated($contact_id, $record);
      $template_data = [
        'fields'  => $config->getAllAddressAttributes(),
        'address' => $address_data,
        'old_address' => $old_address
      ];
      return $this->createContactUpdatedActivity(
        $contact_id,
        $config->translate('Contact Address Updated'),
        $this->renderTemplate('activities/ManualAddressUpdate.tpl', $template_data),
        $record
      );

    } else {
      // this would create inconsistent/invalid addresses -> manual interaction required
      $this->logger->logDebug("Manual address update required for [{$contact_id}] due to invalid address.", $record);
      return $this->createManualUpdateActivity(
        $contact_id, 'Manual Address Update', $record, 'activities/ManualAddressUpdate.tpl',
        array('title'       => 'Please update contact\'s address (new address may be invalid!)',
          'fields'      => $config->getAllAddressAttributes(),
          'address'     => $address_data,
          'old_address' => $old_address));
    }
  }

  /**
   * Checks if old and new address is identical
   * (address fields which are compared are got from config)
   *
   * @param $newAddress
   * @param $oldAddress
   *
   * @return bool
   */
  private function isAddressIdentical($newAddress, $oldAddress) {
    $addressFields = CRM_Streetimport_Config::singleton()->getAllAddressAttributes();

    // fix difference in country field
    if (isset($newAddress['country_id']) && in_array('country_id', $addressFields)) {
      $country = CRM_Streetimport_Utils::getCountryByIso($newAddress['country_id']);
      if (!empty($country)) {
        $newAddress['country_id'] = $country['country_id'];
      }
    }

    foreach ($addressFields as $field) {
      if ((!empty($newAddress[$field]) && !empty($oldAddress[$field]) && $newAddress[$field] != $oldAddress[$field])
        || (empty($newAddress[$field]) && !empty($oldAddress[$field]))
        || (!empty($newAddress[$field]) && empty($oldAddress[$field]))) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /*
   * Get the Case ID of a case given contact id and case type
   *
   * @param $contact_id
   * @param $case_type
   *
   * @return array
   * @throws \CiviCRM_API3_Exception
   */
  private function getCaseIdByType($contact_id, $case_type) {
    return civicrm_api3('Case', 'getvalue', [
      'return'       => 'id',
      'case_type_id' => $case_type,
      'is_deleted'   => 0,
      'contact_id'   => $contact_id,
    ]);
  }

  /**
   * Get the value for legacy (as in "dead people", not "legacy code") channel field
   *
   * @return int
   */
  private function getLegacyChannel() {
    // the option group for this field has the awesome name "channel_20180528131747", might as well just hardcode
    return 7;
  }


  /**
   * Update the status of a case and create a status change activity
   *
   * @param $contact_id
   * @param $case_id
   * @param $new_status_name
   * @param $record
   *
   * @throws \CiviCRM_API3_Exception
   */
  private function updateCaseStatus($contact_id, $case_id, $new_status_name, $record) {
    $config = CRM_Streetimport_Config::singleton();
    // since someone thought it would be a good idea to handle status change
    // activities in the form layer, we need to re-implement that code here.
    // we're dealing with a mix of status IDs, names, and labels.
    // for activity subjects, we need labels, so resolve those
    $current_status_id = civicrm_api3('Case', 'getvalue', [
      'return' => 'status_id',
      'id'     => $case_id,
    ]);
    $current_status_label = civicrm_api3('OptionValue', 'getvalue', [
      'return'          => 'label',
      'option_group_id' => 'case_status',
      'value'           => $current_status_id,
    ]);
    $new_status_label = civicrm_api3('OptionValue', 'getvalue', [
      'return'          => 'label',
      'option_group_id' => 'case_status',
      'name'           => $new_status_name,
    ]);
    if ($new_status_label != $current_status_label) {
      $channel_field = $config->getGPCustomFieldKey('Channel', 'Communication_Channel');
      $activityParams = [
        'subject' => ts('Case status changed from %1 to %2', [
            1 => $current_status_label,
            2 => $new_status_label,
          ]
        ),
        'activity_type_id'    => $config->getChangeCaseStatusActivityType(),
        'status_id'           => 2, // Completed
        'campaign_id'         => $this->getCampaignID($record),
        'activity_date_time'  => $this->getDate($record),
        'source_contact_id'   => (int) $config->getCurrentUserID(),
        'target_contact_id'   => (int) $contact_id,
        'case_id'             => $case_id,
        'medium_id'           => $this->getMediumID($record),
        $channel_field        => $this->getLegacyChannel(),
      ];
      $this->createActivity($activityParams, $record);
    }
    civicrm_api3('Case', 'create', [
      'id'        => $case_id,
      'status_id' => $new_status_name,
    ]);
  }

  private function processJSON($json, $contact_id, $contract_id, array $record) {
    $data = json_decode(trim($json), TRUE);
    if (is_null($data)) {
      $this->logger->logError('Invalid JSON. Error was: ' . json_last_error_msg() . " for JSON '$json'", $record);
      return;
    }
    $config = CRM_Streetimport_Config::singleton();
    switch ($data['action']) {
      case 'outgoing_call':
        $data['phone'] = $this->_normalizePhoneNumber($data['phone']);
        switch ($data['subject']) {
          case 'bmf_umschreibung':
            $subject = "BMF move to {$data['last_name']}, {$data['first_name']} requested";
            $this->createResponseActivity(
              $contact_id,
              $this->assembleResponseSubject($data['response_code'], $data['response']),
              $record
            );
            break;

          default:
            $subject = $data['subject'];
            break;
        }
        $this->createActivity(
          [
            'subject'             => $subject,
            'activity_type_id'    => $config->getOutgoingCallActivityType(),
            'status_id'           => 1, // Scheduled
            'campaign_id'         => $this->getCampaignID($record),
            'activity_date_time'  => $this->getDate($record),
            'source_contact_id'   => (int) $config->getCurrentUserID(),
            'target_contact_id'   => (int) $contact_id,
            'medium_id'           => $this->getMediumID($record),
            'details'             => $this->generateOutgoingCallDetails(
              'The contact has requested a BMF move. Please verify the details with the contact and perform the move.',
              $data,
              $contact_id,
              $contract_id
            )
          ],
          $record
        );
        break;
    }
  }

  protected function assembleResponseSubject($responseCode, $responseText) {
    $responseCode = str_pad($responseCode, 2, '0', STR_PAD_LEFT);
    return trim("{$responseCode} {$responseText}");
  }

  /**
   * Does $responseCode indicate that the contact was reached?
   *
   * @param $responseCode
   *
   * @return bool
   */
  protected function isContactReachedResponse($responseCode) {
    $noContactResponses = [
      TM_KONTAKT_RESPONSE_KONTAKT_KEIN_ANSCHLUSS,
      TM_KONTAKT_RESPONSE_KONTAKT_NICHT_ERREICHT,
      TM_KONTAKT_RESPONSE_KONTAKT_KEIN_KONTAKT,
      TM_KONTAKT_RESPONSE_KONTAKT_NICHT_ANGEGRIFFEN,
    ];
    return !in_array($responseCode, $noContactResponses);
  }

}
