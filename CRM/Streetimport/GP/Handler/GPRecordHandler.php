<?php
/*-------------------------------------------------------------+
| GP StreetImporter Record Handlers                            |
| Copyright (C) 2017 SYSTOPIA                                  |
| Author: B. Endres (endres -at- systopia.de)                  |
| http://www.systopia.de/                                      |
+--------------------------------------------------------------*/

/**
 * Abstract class bundle common GP importer functions
 *
 * @author Björn Endres (SYSTOPIA) <endres@systopia.de>
 * @license AGPL-3.0
 */
abstract class CRM_Streetimport_GP_Handler_GPRecordHandler extends CRM_Streetimport_RecordHandler {

  /** activity type cache */
  protected $_manual_update_required_id = NULL;
  protected $_response_activity_id = NULL;
  protected $_update_activity_id = NULL;
  protected $_webshop_order_activity_id = NULL;

  protected $_contract_changes_produced = FALSE;
  protected $_external_identifier_to_campaign_id = array();
  protected $_external_identifier_to_contact_id = array();
  protected $_internal_identifier_to_contact_id = array();
  protected $_iban_to_bic = array();
  protected $_country_iso_to_id = array();

  /**
   * This event is triggered AFTER the last record of a datasource has been processed
   *
   * @param $sourceURI string  source identifier, e.g. file name
   */
  public function finishProcessing($sourceURI) {
    if ($this->_contract_changes_produced) {
      // if contract changes have been produced, call the
      //  Contract processor to execute them
      civicrm_api3('Contract', 'process_scheduled_modifications', array());
    }
  }

  /**
   * look up contact id with CiviCRM ID
   * @todo use resolveContactID
   */
  protected function getContactIDbyCiviCRMID($contact_id) {
    if (!array_key_exists($contact_id, $this->_internal_identifier_to_contact_id)) {
      if (function_exists('identitytracker_civicrm_install')) {
        // identitytracker is enabled
        $contacts = civicrm_api3('Contact', 'findbyidentity', array(
          'identifier_type' => 'internal',
          'identifier'      => $contact_id));
        if ($contacts['count'] == 1) {
          $current_contact_id = $contacts['id'];
        } else {
          // NOT found or multiple
          $current_contact_id = NULL;
        }

      } else {
        // identitytracker is NOT enabled
        $current_contact_id = $contact_id;
      }
      $this->_internal_identifier_to_contact_id[$contact_id] = $current_contact_id;
    }
    return $this->_internal_identifier_to_contact_id[$contact_id];
  }

  /**
   * look up contact id with external ID
   * @todo use resolveContactID
   */
  protected function getContactIDbyExternalID($external_identifier) {
    if (empty($external_identifier)) return NULL;

    if (!array_key_exists($external_identifier, $this->_external_identifier_to_contact_id)) {
      if (function_exists('identitytracker_civicrm_install')) {
        // identitytracker is enabled
        $contacts = civicrm_api3('Contact', 'findbyidentity', array(
          'identifier_type' => 'external',
          'identifier'      => $external_identifier));
      } else {
        // identitytracker is NOT enabled
        $contacts = civicrm_api3('Contact', 'get', array(
          'external_identifier' => $external_identifier,
          'return'              => 'id'));
      }

      // evaluate results
      if ($contacts['count'] == 1) {
        $this->_external_identifier_to_contact_id[$external_identifier] = $contacts['id'];
      } elseif ($contacts['count'] > 1) {
        // not unique? this shouldn't happen
        $this->_external_identifier_to_contact_id[$external_identifier] = NULL;
      } else {
        // NOT found
        $this->_external_identifier_to_contact_id[$external_identifier] = NULL;
      }
    }
    return $this->_external_identifier_to_contact_id[$external_identifier];
  }

  /**
   * add a detail entity (Phone, Email, Website, ) to a contact
   *
   * @param $record          the data record (for logging)
   * @param $contact_id      the contact
   * @param $entity          the entity type to be created, i.e. 'Phone'
   * @param $data            the data, e.g. ['phone' => '23415425']
   * @param $create_activity should a 'contact changed' activity be created?
   * @param $create_data     data to be used if a new entity has to be created.
   *                           if no location is set, $config->getLocationTypeId() will be used
   * @return the id of the entity (either created or found)
   */
  protected function addDetail($record, $contact_id, $entity, $data, $create_activity=FALSE, $create_data=array()) {
    // make sure they're not empty...
    $print_value = implode('|', array_values($data));
    if (empty($print_value)) return;

    // first: try to find it
    $search = civicrm_api3($entity, 'get', $data + array(
      'contact_id' => $contact_id,
      'return'     => 'id'));
    if ($search['count'] > 0) {
      // this entity already exists, log it:
      $print_value = implode('|', array_values($data));
      $this->logger->logDebug("Contact [{$contact_id}] already has {$entity} '{$print_value}'", $record);

      // return it
      return reset($search['values'])['id'];

    } else {
      // not found: create it
      $config = CRM_Streetimport_Config::singleton();

      // prepare data
      $create_data = $data + $create_data;
      $create_data['contact_id'] = $contact_id;
      if (empty($create_data['location_type_id'])) {
        $create_data['location_type_id'] = $config->getLocationTypeId();
      }

      // create a new  entity
      $new_entity = civicrm_api3($entity, 'create', $create_data);

      // log it
      $print_value = implode('|', array_values($data));
      $this->logger->logDebug("Contact [{$contact_id}] new {$entity} added: {$print_value}", $record);

      // create activity if requested
      if ($create_activity) {
        $this->createContactUpdatedActivity($contact_id, "Contact {$entity} Added", NULL, $record);
      }

      // return
      return $new_entity['id'];
    }
  }

  /**
   * look up campaign id with external identifier (cached)
   */
  protected function getCampaignIDbyExternalIdentifier($external_identifier) {
    if (!array_key_exists($external_identifier, $this->_external_identifier_to_campaign_id)) {
      $campaign = civicrm_api3('Campaign', 'getsingle', array(
        'external_identifier' => $external_identifier,
        'return'              => 'id'));
      $this->_external_identifier_to_campaign_id[$external_identifier] = $campaign['id'];
    }

    return $this->_external_identifier_to_campaign_id[$external_identifier];
  }


  /**
   * disable a contact with everything that entails
   * @param $mode  on of 'erase', 'disabled', 'deceased'
   */
  public function disableContact($contact_id, $mode, $record) {
    $retval = ['cancelled_contracts' => []];
    switch ($mode) {
      case 'erase':
        // erase means anonymise and delete
        civicrm_api3('Contact', 'create', array(
          'id'         => $contact_id,
          'is_deleted' => 1));
        // FIXME: anonymisation not yet available
        $this->tagContact($contact_id, 'anonymise', $record);
        $retval['cancelled_contracts'] = $this->cancelAllContracts($contact_id, 'XX02', $record);
        break;

      case 'disable':
        // disabled (stillgelegt) means deleted + tagged
        civicrm_api3('Contact', 'create', array(
          'id'         => $contact_id,
          'is_deleted' => 1));
        $retval['cancelled_contracts'] = $this->cancelAllContracts($contact_id, 'XX02', $record);
        break;

      case 'deceased':
        // disabled (verstorben) means deceased + tagged
        civicrm_api3('Contact', 'create', array(
          'id'            => $contact_id,
          //'is_deleted'    => 1, // Marco said (27.03.2017): don't delete right away, but GP-1567 says: don't do it!
          'deceased_date' => $this->getDate($record),
          'is_deceased'   => 1));
        $retval['cancelled_contracts'] = $this->cancelAllContracts($contact_id, 'XX13', $record);
        break;

      case 'deactivate':
        civicrm_api3('Contact', 'setinactive', [
          'contact_id' => $contact_id
        ]);
        break;

      default:
        $this->logger->logFatal("DisableContact mode '{$mode}' not implemented!", $record);
        break;
    }
    return $retval;
  }

  /**
   * create a new contract
   *
   * FIELDS: Vertragsnummer  Bankleitzahl  Kontonummer Bic Iban  Kontoinhaber  Bankinstitut  Einzugsstart  JahresBetrag  BuchungsBetrag  Einzugsintervall  EinzugsEndeDatum
   */
  public function createContract($contact_id, $record) {

    // Validate parameters
    if (    empty($record['IBAN'])
         || empty($record['JahresBetrag'])
         || empty($record['Einzugsintervall'])) {
      return $this->logger->logError("Couldn't create mandate, information incomplete.", $record);
    }

    // Payment frequency & amount
    $frequency = (int) $record['Einzugsintervall'];
    $frequency_interval = 12 / $frequency;
    $annual_amount = CRM_Streetimport_GP_Utils_Number::parseGermanFormatNumber($record['JahresBetrag']);
    $amount = $annual_amount / $frequency;

    // Start date
    $start_date = new DateTimeImmutable();

    if (isset($record['Einzugsstart'])) {
      $contract_start = new DateTimeImmutable($record['Einzugsstart']);

      // GP-1416: Backdate by 3 days so the collection is not jeopardised
      $three_days = new DateInterval('P3D');
      $contract_start = $contract_start->sub($three_days);

      if ($contract_start->getTimestamp() > $start_date->getTimestamp()) {
        $start_date = $contract_start;
      }
    }

    // End date
    $end_date = NULL;

    if (!empty($record['EinzugsEndeDatum'])) {
      $end_date = date('YmdHis', strtotime($record['EinzugsEndeDatum']));
    }

    // Bank accounts
    if (empty($record['IBAN'])) {
      $this->logger->logError("Contract couldn't be created, IBAN is missing.", $record);
      return;
    }

    $from_ba = CRM_Contract_BankingLogic::getOrCreateBankAccount($contact_id, $record['IBAN']);
    $to_ba = CRM_Contract_BankingLogic::getCreditorBankAccount();

    // Create contract
    $contract_data = [
      'contact_id'                        => $contact_id,
      'membership_type_id'                => $this->getMembershipTypeID($record),
      'member_since'                      => $this->getDate($record),
      'start_date'                        => $start_date->format('YmdHis'),
      'join_date'                         => $this->getDate($record),
      'end_date'                          => $end_date,
      'campaign_id'                       => $this->getCampaignID($record),
      'membership_payment.from_ba'        => $from_ba,
      'membership_payment.to_ba'          => $to_ba,
      'payment_method.adapter'            => 'sepa_mandate',
      'payment_method.amount'             => $amount,
      'payment_method.campaign_id'        => $this->getCampaignID($record),
      'payment_method.contact_id'         => $contact_id,
      'payment_method.currency'           => 'EUR',
      'payment_method.end_date'           => $end_date,
      'payment_method.financial_type_id'  => CRM_Streetimport_Utils::getFinancialTypeID('Member Dues'),
      'payment_method.frequency_interval' => $frequency_interval,
      'payment_method.frequency_unit'     => 'month',
      'payment_method.iban'               => $record['IBAN'],
      'payment_method.type'               => 'RCUR',
    ];

    $this->logger->logDebug("Calling Contract.create: " . json_encode($contract_data), $record);
    $api_result = civicrm_api3('Contract', 'create', $contract_data);
    $this->_contract_changes_produced = TRUE;

    return $api_result['id'];
  }

  /**
   * Create a OOFF mandate
   */
  public function createOOFFMandate($contact_id, $record) {
    $config = CRM_Streetimport_Config::singleton();

    // validate parameters
    if (empty($record['IBAN']) || empty($record['BuchungsBetrag'])) {
      return $this->logger->logError("Couldn't create mandate, information incomplete.", $record);
    }

    // get start date
    $now = date('YmdHis');
    $mandate_start_date = date('YmdHis', strtotime($record['Einzugsstart']));
    if (empty($mandate_start_date) || $mandate_start_date < $now) {
      $mandate_start_date = $now;
    }

    // compile and create SEPA mandate
    $mandate_params = array(
      'type'                => 'OOFF',
      'iban'                => $record['IBAN'],
      'amount'              => CRM_Streetimport_GP_Utils_Number::parseGermanFormatNumber($record['BuchungsBetrag']),
      'contact_id'          => $contact_id,
      'currency'            => 'EUR',
      'receive_date'        => $mandate_start_date,
      'campaign_id'         => $this->getCampaignID($record),
      'financial_type_id'   => 1, // Donation
      );

    // add up bank account (see GP-1701)
    $bank_account = CRM_Contract_BankingLogic::getOrCreateBankAccount($contact_id, $record['IBAN'], NULL);
    $mandate_params['contribution_information.from_ba'] = $bank_account;
    CRM_Contract_CustomData::resolveCustomFields($mandate_params);

    // create mandate
    $mandate = civicrm_api3('SepaMandate', 'createfull', $mandate_params);
  }

  /**
   * update an existing contract:
   * If contractId is set, then all changes in column U-AE are related to this contractID.
   * if the response in field AM/AN is positive and there is data in columns U-AE.
   *
   * @param $contract_id  the contract/membership ID
   * @param $record       the record expected to contain the following data: Vertragsnummer  Bankleitzahl  Kontonummer Bic Iban  Kontoinhaber  Bankinstitut  Einzugsstart  JahresBetrag  BuchungsBetrag  Einzugsintervall  EinzugsEndeDatum
   * @param $new_type     can provide a new membership_type_id
   * @param $action       the Contract.modfify action: 'update' or 'revive'
   */
  public function updateContract($contract_id, $contact_id, $record, $new_type = NULL, $action = 'update') {
    if (empty($contract_id)) return; // this shoudln't happen

    // Validate parameters
    if (    empty($record['IBAN'])
         || empty($record['JahresBetrag'])
         || empty($record['Einzugsintervall'])) {
      return $this->logger->logError("Couldn't create mandate, information incomplete.", $record);
    }

    // Payment frequency & amount
    $frequency = (int) $record['Einzugsintervall'];
    $annual_amount = CRM_Streetimport_GP_Utils_Number::parseGermanFormatNumber($record['JahresBetrag']);

    // Start date
    $start_date = new DateTimeImmutable();
    $defer_payment_start = TRUE;

    if (isset($record['Einzugsstart'])) {
      $contract_start = new DateTimeImmutable($record['Einzugsstart']);
      $cycle_day = (int) $contract_start->format('d');

      // GP-1416: Backdate by 3 days so the collection is not jeopardised
      $three_days = new DateInterval('P3D');
      $contract_start = $contract_start->sub($three_days);

      if ($contract_start->getTimestamp() > $start_date->getTimestamp()) {
        $start_date = $contract_start;
      }

      // GP-1790: Force debit to start with $new_start_date
      $defer_payment_start = FALSE;
    }

    // Bank accounts
    if (empty($record['IBAN'])) {
      $this->logger->logError("Contract couldn't be created, IBAN is missing.", $record);
      return;
    }

    $from_ba = CRM_Contract_BankingLogic::getOrCreateBankAccount($contact_id, $record['IBAN']);
    $to_ba = CRM_Contract_BankingLogic::getCreditorBankAccount();

    $contract_data = [
      'action'                                  => $action,
      'campaign_id'                             => $this->getCampaignID($record),
      'date'                                    => $start_date->format('YmdHis'),
      'id'                                      => $contract_id,
      'medium_id'                               => $this->getMediumID($record),
      'membership_payment.cycle_day'            => $cycle_day,
      'membership_payment.defer_payment_start'  => $defer_payment_start,
      'membership_payment.from_ba'              => $from_ba,
      'membership_payment.membership_annual'    => $annual_amount,
      'membership_payment.membership_frequency' => $frequency,
      'membership_payment.to_ba'                => $to_ba,
    ];

    // Add membership type change (if requested)
    if ($new_type) {
      $contract_data['membership_type_id'] = $new_type;
    }

    $this->logger->logDebug("Calling Contract.modify: " . json_encode($contract_data), $record);
    civicrm_api3('Contract', 'modify', $contract_data);
    $this->_contract_changes_produced = TRUE;
    $this->logger->logDebug("Update for membership [{$contract_id}] scheduled.", $record);

    // Schedule end (if requested)
    if (!empty($record['EinzugsEndeDatum'])) {
      $contract_modification = array(
        'action'                                           => 'cancel',
        'id'                                               => $contract_id,
        'medium_id'                                        => $this->getMediumID($record),
        'campaign_id'                                      => $this->getCampaignID($record),
        'membership_cancellation.membership_cancel_reason' => 'XX02',
        'date'                                             => date('Y-m-d H:i:s', strtotime($record['EinzugsEndeDatum'])),
        );

      $this->logger->logDebug("Calling Contract.modify: " . json_encode($contract_modification), $record);
      civicrm_api3('Contract', 'modify', $contract_modification);
      $this->_contract_changes_produced = TRUE;
      $this->logger->logDebug("Contract (membership) [{$contract_id}] scheduled for termination.", $record);
    }
  }

  /**
   * Cancel all active contracts of a given contact
   */
  public function cancelAllContracts($contact_id, $cancel_reason, $record) {
    $config = CRM_Streetimport_Config::singleton();

    // find all active memberships (contracts)
    $memberships = civicrm_api3('Membership', 'get', array(
      'contact_id' => $contact_id,
      'status_id'  => array('IN' => $config->getActiveMembershipStatuses()),
      'return'     => 'id,status_id'  // TODO: more needed for cancellation?
      ));
    $ids = [];
    foreach ($memberships['values'] as $membership) {
      $this->cancelContract($membership, $record, array('cancel_reason' => $cancel_reason));
      $ids[] = $membership['id'];
    }
    return $ids;
  }

  /**
   * end contract, i.e. membership _and_ mandate
   */
  public function cancelContract($membership, $record, $params = array()) {
    try {
      $config = CRM_Streetimport_Config::singleton();
      $end_date = date('YmdHis', strtotime('yesterday')); // end_date has to be now, not $this->getDate()

      // first load the membership
      if (empty($membership)) {
        return $this->logger->logError("NO contract (membership) provided, cancellation not possible.", $record);
      }

      // now check if it's still active
      if (!$this->isContractActive($membership)) {
        return $this->logger->logWarning("Contract (membership) [{$membership['id']}] is not active.", $record);
      }

      // finally call contract extension
      $contract_modification = array(
        'action'                                           => 'cancel',
        'id'                                               => $membership['id'],
        'medium_id'                                        => $this->getMediumID($record),
        'campaign_id'                                      => $this->getCampaignID($record),
        'membership_cancellation.membership_cancel_reason' => CRM_Utils_Array::value('cancel_reason', $params, 'XX02'),
        );

      // add cancel date if in the future:
      $requested_cancel_date = strtotime($this->getDate($record));
      if ($requested_cancel_date > strtotime("now")) {
        $contract_modification['date'] = date('Y-m-d H:i:00', $requested_cancel_date);
      }

      $this->logger->logDebug("Calling Contract.modify: " . json_encode($contract_modification), $record);
      civicrm_api3('Contract', 'modify', $contract_modification);
      $this->_contract_changes_produced = TRUE;
      $this->logger->logDebug("Contract (membership) [{$membership['id']}] scheduled for termination.", $record);
    } catch (Exception $e) {
      $this->logger->logError("Contract (membership) [{$membership['id']}] received an exception when trying to terminate it: " . $e->getMessage(), $record);
    }
  }

  /**
   * This is just a fall-back, most handlers would want to
   * provide their own function. This one always returns 'now'.
   */
  protected function getDate($record) {
    return date('YmdHis');
  }

  /**
   * This is just a fall-back, most handlers would want to
   * provide their own function. This one always returns ''.
   */
  protected function getMediumID($record) {
    return '';
  }

  /**
   * Get activity assignee corresponding to $record
   *
   * @param $record
   *
   * @return null
   */
  protected function getAssignee($record) {
    return NULL;
  }

  /**
   * check if the given contract is still active
   */
  public function isContractActive($membership) {
    $config = CRM_Streetimport_Config::singleton();
    return in_array($membership['status_id'], $config->getActiveMembershipStatuses());
  }

  /**
   * check if the given mandate is active
   */
  public function isMandateActive($mandate) {
    return  $mandate['status'] == 'RCUR'
         || $mandate['status'] == 'FRST'
         || $mandate['status'] == 'INIT'
         || $mandate['status'] == 'SENT';
  }

  /**
   * check if the given recurring contribution is active
   */
  public function isContributionRecurActive($contribution_recur) {
    return  $contribution_recur['contribution_status_id'] == 2 // pending
         || $contribution_recur['contribution_status_id'] == 5; // in progress
  }

  /**
   * take address data and see what to do with it:
   * - if it's not enough data -> create ticket (activity) for manual processing
   * - else: if no address is present -> create a new one
   * - else: if new data wouldn't replace ALL the data of the old address -> create ticket (activity) for manual processing
   * - else: update address
   */
  public function createOrUpdateAddress($contact_id, $address_data, $record) {
    if (empty($address_data)) return;

    // check if address is complete
    $address_complete = TRUE;
    $config = CRM_Streetimport_Config::singleton();
    $required_attributes = $config->getRequiredAddressAttributes();
    foreach ($required_attributes as $required_attribute) {
      if (empty($address_data[$required_attribute])) {
        $address_complete = FALSE;
      }
    }

    if (!$address_complete) {
      $this->logger->logDebug("Manual address update required for [{$contact_id}].", $record);
      return $this->createManualUpdateActivity(
          $contact_id, 'Manual Address Update', $record, 'activities/ManualAddressUpdate.tpl',
          array('title'   => 'Please update contact\'s address',
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

    // check if we'd overwrite EVERY one the relevant fields
    //  to avoid inconsistent addresses
    $full_overwrite = TRUE;
    $all_fields = $config->getAllAddressAttributes();
    foreach ($all_fields as $field) {
      if (empty($address_data[$field]) && !empty($old_address[$field])) {
        $full_overwrite = FALSE;
        break;
      }
    }

    if ($full_overwrite) {
      // this is a proper address update
      $address_data['id'] = $old_address_id;
      $this->setProvince($address_data);
      $this->logger->logDebug("Updating address for contact [{$contact_id}]: " . json_encode($address_data), $record);
      civicrm_api3('Address', 'create', $address_data);
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
      $this->logger->logDebug("Manual address update required for [{$contact_id}].", $record);
      return $this->createManualUpdateActivity(
          $contact_id, 'Manual Address Update', $record, 'activities/ManualAddressUpdate.tpl',
          array('title'       => 'Please update contact\'s address',
                'fields'      => $config->getAllAddressAttributes(),
                'address'     => $address_data,
                'old_address' => $old_address));
    }
  }

  /**
   * Will resolve known fields (e.g. prefix_id, country_id, ...)
   * that require IDs rather than the value in the given data array
   *
   * @todo move to parent class
   */
  public function resolveFields(&$data, $record) {
    if (isset($data['prefix_id']) && !is_numeric($data['prefix_id'])) {
      // map label to name first
      $data['prefix_id'] = str_replace(
        ['Herr', 'Frau'],
        ['Mr.', 'Ms.'],
        $data['prefix_id']
      );
      $prefix_id = CRM_Core_PseudoConstant::getKey(
        'CRM_Contact_BAO_Contact',
        'prefix_id',
        $data['prefix_id']
      );
      if ($prefix_id) {
        $data['prefix_id'] = $prefix_id;
      } else {
        // not found!
        $this->logger->logWarning("Couldn't resolve prefix '{$data['prefix_id']}'.", $record);
        $data['prefix_id'] = '';
      }
    }
  }




  /*****************************************************
   *               ACTIVITY CREATION                   *
   ****************************************************/

  /**
   * Find parent activity based on campaign and other optional filters
   *
   * Always returns the most recent matching activity.
   *
   * Supported filters include:
   *  - activity_types: array of activity type names (SQL IN ())
   *  - exclude_activity_types: array of activity type names to exclude (SQL NOT IN ())
   *  - media: array of medium names (SQL IN ())
   *  - min_date: earliest possible activity date
   *  - max_date: latest possible activity_date
   *
   * @param $contact_id
   * @param $campaign_id
   * @param array $filters array of additional filters
   *
   * @return int|null
   */
  protected function getParentActivityId($contact_id, $campaign_id, array $filters = []) {
    $activity_types_resolved = [];
    if (array_key_exists('activity_types', $filters)) {
      foreach ($filters['activity_types'] as $activity_type) {
        $activity_types_resolved[] = CRM_Core_PseudoConstant::getKey('CRM_Activity_BAO_Activity', 'activity_type_id', $activity_type);
      }
    }
    $exclude_activity_types_resolved = [];
    if (array_key_exists('exclude_activity_types', $filters)) {
      foreach ($filters['exclude_activity_types'] as $activity_type) {
        $exclude_activity_types_resolved[] = CRM_Core_PseudoConstant::getKey('CRM_Activity_BAO_Activity', 'activity_type_id', $activity_type);
      }
    }
    $media_resolved = [];
    if (array_key_exists('media', $filters)) {
      foreach ($filters['media'] as $medium) {
        $media_resolved[] = CRM_Core_PseudoConstant::getKey('CRM_Activity_BAO_Activity', 'medium_id', $medium);
      }
    }
    $params = [
      1 => [$contact_id, 'Integer'],
      2 => [$campaign_id, 'Integer'],
    ];
    $optionalFilters = '';
    $param_count = 2;
    if (count($activity_types_resolved) > 0) {
      $param_count++;
      $optionalFilters .= " AND a.activity_type_id IN (%{$param_count})";
      $params[$param_count] = [implode(',', $activity_types_resolved), 'String'];
    }
    if (count($exclude_activity_types_resolved) > 0) {
      $param_count++;
      $optionalFilters .= " AND a.activity_type_id NOT IN (%{$param_count})";
      $params[$param_count] = [implode(',', $exclude_activity_types_resolved), 'String'];
    }
    if (count($media_resolved) > 0) {
      $param_count++;
      $optionalFilters .= " AND a.medium_id IN (%{$param_count})";
      $params[$param_count] = [implode(',', $media_resolved), 'String'];
    }
    if (array_key_exists('min_date', $filters) && !is_null($filters['min_date'])) {
      $param_count++;
      $optionalFilters .= " AND a.activity_date_time >= %{$param_count}";
      $params[$param_count] = [$filters['min_date'], 'String'];
    }
    if (array_key_exists('max_date', $filters) && !is_null($filters['max_date'])) {
      $param_count++;
      $optionalFilters .= " AND DATE(a.activity_date_time) <= %{$param_count}";
      $params[$param_count] = [$filters['max_date'], 'String'];
    }
    return CRM_Core_DAO::singleValueQuery("SELECT a.id
      FROM civicrm_activity a
      LEFT JOIN civicrm_activity_contact ac ON ac.activity_id = a.id  AND ac.record_type_id = 3
      WHERE ac.contact_id = %1
        AND a.campaign_id = %2
        {$optionalFilters}
      ORDER BY a.activity_date_time DESC
      LIMIT 1",
      $params
    );
  }

  /**
   * Get parent activity data
   *
   * @param $contact_id
   * @param $campaign_id
   * @param array $filters
   *
   * @see self::getParentActivityId()
   *
   * @return array|null
   * @throws \CiviCRM_API3_Exception
   */
  protected function getParentActivity($contact_id, $campaign_id, array $filters = []) {
    $parent_id = $this->getParentActivityId($contact_id, $campaign_id, $filters);
    if (!empty($parent_id)) {
      return civicrm_api3('Activity', 'getsingle', [
        'id' => $parent_id,
      ]);
    }
    return NULL;

  }

  /**
   * Create a "Manual Update" activity
   *
   * @param $contact_id        well....
   * @param $subject           subject for the activity
   * @param $record            the data record that's being processed
   * @param $messageOrTemplate either the full details body of the activity (if $data empty)
   *                            or a template path (if $data not empty), in which case $data will be assigned as template variables
   */
  public function createManualUpdateActivity($contact_id, $subject, $record, $messageOrTemplate=NULL, $data=NULL) {
    $config = CRM_Streetimport_Config::singleton();

    // first get contact called activity type
    if ($this->_manual_update_required_id == NULL) {
      $this->_manual_update_required_id = CRM_Core_PseudoConstant::getKey(
        'CRM_Activity_BAO_Activity',
        'activity_type_id',
        'manual_update_required'
      );
      if (empty($this->_manual_update_required_id)) {
        // couldn't be found => create
        $activity = civicrm_api3('OptionValue', 'create', array(
          'option_group_id' => 'activity_type',
          'name'            => 'manual_update_required',
          'label'           => $config->translate('Manual Update Required'),
          'is_active'       => 1
          ));
        $this->_manual_update_required_id = CRM_Core_PseudoConstant::getKey(
          'CRM_Activity_BAO_Activity',
          'activity_type_id',
          'manual_update_required'
        );
      }
    }

    $activityFields = CRM_Activity_DAO_Activity::fields();
    $subjectMaxlength = (!empty($activityFields['activity_subject']['maxlength'])) ? $activityFields['activity_subject']['maxlength'] : 255;
    $endSymbol = '...';
    $translatedSubject = $config->translate($subject);
    $details = '';

    if (strlen($translatedSubject) > $subjectMaxlength) {
      $handledSubject = substr($translatedSubject, 0, $subjectMaxlength - strlen($endSymbol));
      $handledSubject .= $endSymbol;
      $details = $translatedSubject;
    } else {
      $handledSubject = $translatedSubject;
    }

    // NOW create the activity
    $activityParams = array(
      'activity_type_id'    => $this->_manual_update_required_id,
      'subject'             => $handledSubject,
      'details'             => $details,
      'status_id'           => $config->getImportErrorActivityStatusId(),
      'campaign_id'         => $this->getCampaignID($record),
      'activity_date_time'  => $this->getDate($record),
      'target_contact_id'   => (int) $contact_id,
      'source_contact_id'   => (int) $config->getCurrentUserID(),
      'assignee_contact_id' => (int) $config->getFundraiserContactID(),
    );

    // calculate details
    if ($messageOrTemplate) {
      if ($data) {
        if (!empty($data['update'])) {
          $data['update'] = $this->resolveLabels($data['update']);
        }
        // this is should be a template -> render it!
        $activityParams['details'] = $this->renderTemplate($messageOrTemplate, $data);
      } else {
        $activityParams['details'] = $messageOrTemplate;
      }
    }

    $this->createActivity($activityParams, $record, array($config->getFundraiserContactID()));
  }

  protected function resolveLabels($data) {
    if (!empty($data['prefix_id']) && is_numeric($data['prefix_id'])) {
      $data['prefix_id'] = CRM_Core_PseudoConstant::getLabel(
        'CRM_Contact_BAO_Contact',
        'prefix_id',
        $data['prefix_id']
      );
    }
    return $data;
  }

  /**
   * Create a RESPONSE activity
   */
  public function createResponseActivity($contact_id, $title, $record) {
    $config = CRM_Streetimport_Config::singleton();

    // first get Response activity type
    if ($this->_response_activity_id == NULL) {
      $this->_response_activity_id = CRM_Core_PseudoConstant::getKey(
        'CRM_Activity_BAO_Activity',
        'activity_type_id',
        'Response'
      );
      if (empty($this->_response_activity_id)) {
        // couldn't be found => create
        $activity = civicrm_api3('OptionValue', 'create', array(
          'option_group_id' => 'activity_type',
          'name'            => 'Response',
          'label'           => $config->translate('Manual Update Required'),
          'is_active'       => 1
          ));
        $this->_response_activity_id = CRM_Core_PseudoConstant::getKey(
          'CRM_Activity_BAO_Activity',
          'activity_type_id',
          'Response'
        );
      }
    }

    // determine the subject
    // $campaign = $this->loadEntity('Campaign', $this->getCampaignID($record));
    // $subject = $campaign['title'] . ' - ' . $title;
    $subject = $title; // Marco said: drop the title

    // NOW create the activity
    $activityParams = array(
      'activity_type_id'    => $this->_response_activity_id,
      'subject'             => $subject,
      'status_id'           => $config->getActivityCompleteStatusId(),
      'campaign_id'         => $this->getCampaignID($record),
      'activity_date_time'  => $this->getDate($record),
      'source_contact_id'   => (int) $config->getCurrentUserID(),
      'target_contact_id'   => (int) $contact_id,
      'medium_id'           => $this->getMediumID($record),
      'assignee_contact_id' => $this->getAssignee($record),
    );

    $activity = $this->createActivity($activityParams, $record);
  }

  /**
   * Create a "Webshop Order" activity
   *
   * @param $contact_id        well....
   * @param $record            the data record that's being processed
   * @param $data array        additional data (e.g. custom fields) for the activity
   */
  public function createWebshopActivity($contact_id, $record, $data) {
    $config = CRM_Streetimport_Config::singleton();

    // first get contact called activity type
    if ($this->_webshop_order_activity_id == NULL) {
      $this->_webshop_order_activity_id = CRM_Core_PseudoConstant::getKey(
        'CRM_Activity_BAO_Activity',
        'activity_type_id',
        'Webshop Order'
      );
    }

    if (empty($this->_webshop_order_activity_id)) {
      $this->logger->logError("Activity type 'Webshop Order' unknown. No activity created.", $record);
      return;
    }

    // NOW create the activity
    $activityParams = array(
      'activity_type_id'    => $this->_webshop_order_activity_id,
      'subject'             => CRM_Utils_Array::value('subject', $data, 'Webshop Order'),
      'status_id'           => $data['status_id'] ?? $config->getActivityScheduledStatusId(),
      'campaign_id'         => $this->getCampaignID($record),
      'activity_date_time'  => $this->getDate($record),
      'source_contact_id'   => (int) $config->getCurrentUserID(),
      'target_contact_id'   => (int) $contact_id,
    );

    unset($data['status_id']);

    $this->createActivity($activityParams + $data, $record);
  }

  /**
   * Create a "Contact Updated" activity
   *
   * @param $contact_id
   * @param $subject
   * @param $details
   * @param $record
   */
  public function createContactUpdatedActivity($contact_id, $subject, $details, $record) {
    $config = CRM_Streetimport_Config::singleton();

    // first get contact called activity type
    if ($this->_update_activity_id == NULL) {
      $this->_update_activity_id = CRM_Core_PseudoConstant::getKey(
        'CRM_Activity_BAO_Activity',
        'activity_type_id',
        'contact_updated'
      );
      if (empty($this->_update_activity_id)) {
        // couldn't be found => create
        $activity = civicrm_api3('OptionValue', 'create', array(
          'option_group_id' => 'activity_type',
          'name'            => 'contact_updated',
          'label'           => $config->translate('Contact Updated'),
          'is_active'       => 1
          ));
        $this->_update_activity_id = CRM_Core_PseudoConstant::getKey(
          'CRM_Activity_BAO_Activity',
          'activity_type_id',
          'contact_updated'
        );
      }
    }

    // NOW create the activity
    $activityParams = array(
      'activity_type_id'    => $this->_update_activity_id,
      'subject'             => $subject,
      'details'             => $details,
      'status_id'           => $config->getActivityCompleteStatusId(),
      'campaign_id'         => $this->getCampaignID($record),
      'activity_date_time'  => date('YmdHis'), // has to be now
      'source_contact_id'   => (int) $config->getCurrentUserID(),
      'target_contact_id'   => (int) $contact_id,
      'medium_id'           => $this->getMediumID($record),
    );

    $this->createActivity($activityParams, $record);
  }

  /**
   * Returns the country_id for a country identified by $isoCode, or NULL if the
   * ISO code does not exist
   *
   * @param $isoCode
   *
   * @return integer|null
   */
  protected function _getCountryByISOCode($isoCode) {
    // avoid looking up empty iso codes
    if (empty($isoCode)) {
      return NULL;
    }
    if (array_key_exists($isoCode, $this->_country_iso_to_id)) {
      return $this->_country_iso_to_id[$isoCode];
    }
    try {
      $this->_country_iso_to_id[$isoCode] = civicrm_api3('Country', 'getvalue', [
        'return' => 'id',
        'iso_code' => $isoCode,
      ]);
    } catch (CiviCRM_API3_Exception $e) {
      $this->_country_iso_to_id[$isoCode] = NULL;
    }
    return $this->_country_iso_to_id[$isoCode];
  }

  /**
   * Normalises phone number
   *
   * @param $phone string phone number
   *
   * @return string normalised phone number
   */
  protected function _normalizePhoneNumber($phone) {
    if (method_exists('CRM_Utils_Normalize', 'normalize_phone')) {
      if (in_array(substr($phone, 0, 2), ['43', '49'])) {
        // For numbers starting with AT or DE country code and without a prefix
        // (i.e. 43680123456), add the prefix so normalize can handle the number
        $phone = '+' . $phone;
      }
      $normalized_phone = [
        'phone' => $phone,
        'phone_type_id' => 1
      ];
      $normalizer = new CRM_Utils_Normalize();
      $normalizer->normalize_phone($normalized_phone);
      return $normalized_phone['phone'];
    }
    return $phone;
  }

  /**
   * Set the parent Activity ID for the most recent contract activity
   *
   * @param $contractId
   * @param $parentActivityId
   *
   * @throws \CiviCRM_API3_Exception
   */
  protected function setContractActivityParent($contractId, $parentActivityId) {
    if (class_exists('CRM_Contract_Change')) {
      $activityTypes = CRM_Contract_Change::getActivityTypeIds();
    } else {
      $activityTypes = CRM_Contract_ModificationActivity::getModificationActivityTypeIds();
    }
    $activity_id = CRM_Core_DAO::singleValueQuery("SELECT a.id
      FROM civicrm_activity a
      WHERE a.source_record_id = %1
        AND a.activity_type_id IN (" . implode(',', $activityTypes) . ")
      ORDER BY a.activity_date_time DESC
      LIMIT 1",
      [1 => [$contractId, 'Integer']]
    );
    $config = CRM_Streetimport_Config::singleton();
    $parent_id_field = $config->getGPCustomFieldKey('parent_activity_id');
    civicrm_api3('Activity', 'create', [
      'id'             => $activity_id,
      $parent_id_field => $parentActivityId,
      'skip_handler'   => TRUE,
    ]);
  }

  /**
   * Set province based on other address fields
   *
   * @param $params
   */
  protected function setProvince(&$params) {
    if (function_exists('postcodeat_civicrm_config')) {
      if (!empty($params['country_id']) && !empty($params['postal_code'])) {
        try {
          $result = civicrm_api3('PostcodeAT', 'getstate', [
            'country_id'  => $params['country_id'],
            'postal_code' => $params['postal_code'],
          ]);
          if (!empty($result['id'])) {
            $params['state_province_id'] = $result['id'];
          }
        } catch (CiviCRM_API3_Exception $e) {
          // probably non-AT address. ignore
        }
      }
    }
  }

  /**
   * Check whether a given contact_id is in trash
   *
   * This is a best-effort attempt, it doesn't handle identitytracker IDs
   *
   * @param $contact_id
   *
   * @return bool
   * @throws \CiviCRM_API3_Exception
   */
  protected function isDeletedContact($contact_id) {
    return civicrm_api3('Contact', 'getcount', [
      'id'         => $contact_id,
      'is_deleted' => TRUE,
    ]) == 1;
  }

  /**
   * Check if there has been a change since a specified date
   *
   * @param $contact_id
   * @param $minimum_date
   * @param $record
   *
   * @return bool
   */
  public function addressChangeRecordedSince($contact_id, $minimum_date, $record) {
    $logging = new CRM_Logging_Schema();

    // Assert that logging is enabled
    if (!$logging->isEnabled()) {
      $this->logger->logDebug("Logging not enabled, cannot determine whether records have changed.", $record);
      return FALSE;
    }

    // Determine the name of the logging database
    $dsn_database = (
      defined('CIVICRM_LOGGING_DSN')
      ? DB::parseDSN(CIVICRM_LOGGING_DSN)
      : DB::parseDSN(CIVICRM_DSN)
    )['database'];

    // The following address attributes will be used for comparison
    $relevant_attributes = [
      'city',
      'country_id',
      'is_primary',
      'log_date',
      'postal_code',
      'street_address',
      'supplemental_address_1',
      'supplemental_address_2',
    ];

    $attribute_list = implode(', ', $relevant_attributes);

    // Determine the primary address of the contact at the time of $minimum_date
    $prev_addr_query = CRM_Core_DAO::executeQuery("
      SELECT $attribute_list
      FROM $dsn_database.log_civicrm_address
      WHERE
        contact_id = $contact_id
        AND is_primary = 1
        AND log_action != 'Delete'
        AND log_date < '$minimum_date'
      ORDER BY log_date DESC
      LIMIT 1
    ");

    if (!$prev_addr_query->fetch()) return TRUE;

    // Discard DB query metadata, keep only relevant attributes
    $previous_address = array_filter(
      (array) (clone $prev_addr_query),
      fn ($key) => in_array($key, $relevant_attributes),
      ARRAY_FILTER_USE_KEY
    );

    // Query all primary address changes since $minimum_date
    $changes_query = CRM_Core_DAO::executeQuery("
      SELECT $attribute_list
      FROM $dsn_database.log_civicrm_address
      WHERE
        contact_id = $contact_id
        AND is_primary = 1
        AND log_action != 'Delete'
        AND log_date >= '$minimum_date'
      ORDER BY log_date ASC
    ");

    while ($changes_query->fetch()) {
      // Discard DB query metadata, keep only relevant attributes
      $change = array_filter(
        (array) (clone $changes_query),
        fn ($key) => in_array($key, $relevant_attributes),
        ARRAY_FILTER_USE_KEY
      );

      foreach ($relevant_attributes as $attribute) {
        if ($attribute == 'log_date') continue; // Can be ignored
        if ($previous_address[$attribute] == $change[$attribute]) continue; // Hasn't changed

        // A relevant attribute has changed
        $log_date = $change['log_date'];
        $this->logger->logDebug("Address attribute '$attribute' changed (on $log_date)", $change);
        return TRUE;
      }
    }

    // No relevant changes have been detected
    return FALSE;
  }

}
