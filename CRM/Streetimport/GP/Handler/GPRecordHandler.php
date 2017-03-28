<?php
/*-------------------------------------------------------------+
| GP StreetImporter Record Handlers                            |
| Copyright (C) 2017 SYSTOPIA                                  |
| Author: M. McAndrew (michaelmcandrew@thirdsectordesign.org)  |
|         B. Endres (endres -at- systopia.de)                  |
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

  /**
   * look up contact id with CiviCRM ID
   */
  protected function getContactIDbyCiviCRMID($contact_id) {
    // TODO: use identity tracker!
    return $contact_id;
  }

  /**
   * look up contact id with external ID
   */
  protected function getContactIDbyExternalID($external_identifier) {
    if (empty($external_identifier)) return NULL;

    // look up contact via external_identifier
    // TODO: use identity tracker!
    $contacts = civicrm_api3('Contact', 'get', array(
      'external_identifier' => $external_identifier,
      'return'              => 'id'));
    if ($contacts['count'] == 1) {
      return $contacts['id'];
    } elseif ($contacts['count'] > 1) {
      // not unique? this shouldn't happen
      return NULL;
    } else {
      // NOT found
      return NULL;
    }
  }

  /**
   * disable a contact with everything that entails
   * @param $mode  on of 'erase', 'disabled', 'deceased'
   */
  public function disableContact($contact_id, $mode, $record) {
    switch ($mode) {
      case 'erase':
        // erase means anonymise and delete
        civicrm_api3('Contact', 'create', array(
          'id'         => $contact_id,
          'is_deleted' => 1));
        // FIXME: anonymisation not yet available
        $this->tagContact($contact_id, 'anonymise', $record);
        $this->cancelAllContracts($contact_id, 'XX02', $record);
        break;

      case 'disable':
        // disabled (stillgelegt) means deleted + tagged
        civicrm_api3('Contact', 'create', array(
          'id'         => $contact_id,
          'is_deleted' => 1));
        $this->tagContact($contact_id, 'inaktiv', $record);
        $this->cancelAllContracts($contact_id, 'XX02', $record);
        break;

      case 'deceased':
        // disabled (verstorben) means deceased + tagged
        civicrm_api3('Contact', 'create', array(
          'id'            => $contact_id,
          // 'is_deleted'  => 1, // Marco said (27.03.2017): don't delete right away
          'deceased_date' => $this->getDate($record),
          'is_deceased'   => 1));
        $this->tagContact($contact_id, 'inaktiv', $record);
        $this->cancelAllContracts($contact_id, 'XX13', $record);
        break;

      default:
        $this->logger->logFatal("DisableContact mode '{$mode}' not implemented!", $record);
        break;
    }
  }

  /**
   * create a new contract
   *
   * FIELDS: Vertragsnummer  Bankleitzahl  Kontonummer Bic Iban  Kontoinhaber  Bankinstitut  Einzugsstart  JahresBetrag  BuchungsBetrag  Einzugsintervall  EinzugsEndeDatum
   */
  public function createContract($contact_id, $record) {
    $config = CRM_Streetimport_Config::singleton();

    // validate parameters
    if (    empty($record['IBAN'])
         || empty($record['BIC'])
         || empty($record['JahresBetrag'])
         || empty($record['Einzugsintervall'])) {
      return $this->logger->logError("Couldn't create mandate, information incomplete.", $record);
    }

    // get start date
    $now = date('YmdHis');
    $mandate_start_date = date('YmdHis', strtotime($record['Einzugsstart']));
    if (empty($mandate_start_date) || $mandate_start_date < $now) {
      $mandate_start_date = $now;
    }

    // FIRST: compile and create SEPA mandate
    $annual_amount = $record['JahresBetrag'];
    $frequency = $record['Einzugsintervall'];
    $amount = number_format($annual_amount / $frequency, 2);
    $mandate_params = array(
      'type'                => 'RCUR',
      'iban'                => $record['IBAN'],
      'bic'                 => $record['BIC'],
      'amount'              => $amount,
      'contact_id'          => $contact_id,
      'currency'            => 'EUR',
      'frequency_unit'      => 'month',
      'cycle_day'           => $config->getNextCycleDay($mandate_start_date),
      'frequency_interval'  => (int) (12.0 / $frequency),
      'start_date'          => $mandate_start_date,
      'campaign_id'         => $this->getCampaignID($record),
      'financial_type_id'   => 3, // Membership Dues
      );
    if (!empty($record['EinzugsEndeDatum'])) {
      $mandate_params['end_date'] = date('YmdHis', strtotime($record['EinzugsEndeDatum']));
    }

    // create and reload mandate
    $mandate = civicrm_api3('SepaMandate', 'createfull', $mandate_params);
    $mandate = civicrm_api3('SepaMandate', 'getsingle', array('id' => $mandate['id']));

    // NEXT: create membership
    $membership_annual        = $config->getGPCustomFieldKey('membership_annual');
    $membership_frequency     = $config->getGPCustomFieldKey('membership_frequency');
    $membership_rcontribution = $config->getGPCustomFieldKey('membership_recurring_contribution');

    $membership_params = array(
      'contact_id'              => $contact_id,
      'membership_type_id'      => $this->getMembershipTypeID($record),
      'member_since'            => $this->getDate($record),
      'start_date'              => $mandate_start_date,
      'campaign_id'             => $this->getCampaignID($record),
      $membership_annual        => number_format($annual_amount, 2),
      $membership_frequency     => $frequency,
      $membership_rcontribution => $mandate['entity_id']
      );
    $membership = civicrm_api3('Membership', 'create', $membership_params);
  }

  /**
   * Create a OOFF mandate
   */
  public function createOOFFMandate($contact_id, $record) {
    $config = CRM_Streetimport_Config::singleton();

    // validate parameters
    if (    empty($record['IBAN'])
         || empty($record['BIC'])
         || empty($record['BuchungsBetrag'])) {
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
      'bic'                 => $record['BIC'],
      'amount'              => number_format($record['BuchungsBetrag'], 2),
      'contact_id'          => $contact_id,
      'currency'            => 'EUR',
      'receive_date'        => $mandate_start_date,
      'campaign_id'         => $this->getCampaignID($record),
      'financial_type_id'   => 1, // Donation
      );

    // create mandate
    $mandate = civicrm_api3('SepaMandate', 'createfull', $mandate_params);
  }

  /**
   * update an existing contract:
   * If contractId is set, then all changes in column U-AE are related to this contractID.
   * In conversion projects you will find no contractid here, which means you have to create a new one,
   * if the response in field AM/AN is positive and there is data in columns U-AE.
   *
   * FIELDS: Vertragsnummer  Bankleitzahl  Kontonummer Bic Iban  Kontoinhaber  Bankinstitut  Einzugsstart  JahresBetrag  BuchungsBetrag  Einzugsintervall  EinzugsEndeDatum
   */
  public function updateContract($contract_id, $contact_id, $record) {
    $config = CRM_Streetimport_Config::singleton();
    $now = date('YmdHis');
    if (empty($record['Einzugsstart'])) {
      $new_start_date = $now;
    } else {
      $new_start_date = date('YmdHis', strtotime($record['Einzugsstart']));
      if ($new_start_date < $now) {
        $new_start_date = $now;
      }
    }

    if ($record['weiterbuchen']) {
      $old_end_date = $new_start_date;
    } else {
      $old_end_date = $now;
    }

    // end old mandate
    $old_recurring_contribution_id = civicrm_api3('Membership', 'getvalue', array(
      'id'     => $contract_id,
      'return' => $config->getGPCustomFieldKey('membership_recurring_contribution')));
    $old_mandate_id = civicrm_api3('SepaMandate', 'get', array(
      'entity_id'    => $old_recurring_contribution_id,
      'entity_table' => 'civicrm_contribution_recur',
      'return'       => 'id'));
    if (!empty($old_mandate_id['id'])) {
      CRM_Sepa_BAO_SEPAMandate::terminateMandate($old_mandate_id['id'], $old_end_date, 'CHNG');
      CRM_Sepa_Logic_Batching::closeEnded();
    } else {
      $this->logger->logError("No mandate attached to membership [{$contract_id}], couldn't stop!", $record);
    }

    // create new mandate
    // validate parameters
    if (    empty($record['IBAN'])
         || empty($record['BIC'])
         || empty($record['JahresBetrag'])
         || empty($record['Einzugsintervall'])) {
      return $this->logger->logError("Couldn't create mandate, information incomplete.", $record);
    }

    // FIRST: compile and create SEPA mandate
    $annual_amount = $record['JahresBetrag'];
    $frequency = $record['Einzugsintervall'];
    $amount = number_format($annual_amount / $frequency, 2);
    $mandate_params = array(
      'type'                => 'RCUR',
      'iban'                => $record['IBAN'],
      'bic'                 => $record['BIC'],
      'amount'              => $amount,
      'contact_id'          => $contact_id,
      'currency'            => 'EUR',
      'frequency_unit'      => 'month',
      'cycle_day'           => $config->getNextCycleDay($mandate_start_date),
      'frequency_interval'  => (int) (12.0 / $frequency),
      'start_date'          => $new_start_date,
      'campaign_id'         => $this->getCampaignID($record),
      'financial_type_id'   => 3, // Membership Dues
      );
    if (!empty($record['EinzugsEndeDatum'])) {
      $mandate_params['end_date'] = date('YmdHis', strtotime($record['EinzugsEndeDatum']));
    }

    // create and reload mandate
    $mandate = civicrm_api3('SepaMandate', 'createfull', $mandate_params);
    $mandate = civicrm_api3('SepaMandate', 'getsingle', array('id' => $mandate['id']));

    // update membership
    $membership_rcontribution = $config->getGPCustomFieldKey('membership_recurring_contribution');
    civicrm_api3('Membership', 'create', array(
      'id'                      => $contract_id,
      $membership_rcontribution => $mandate['entity_id']));
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
    foreach ($memberships['values'] as $membership) {
      $this->cancelContract($membership, $record, array('cancel_reason' => $cancel_reason));
    }
  }

  /**
   * end contract, i.e. membership _and_ mandate
   */
  public function cancelContract($membership, $record, $params = array()) {
    $config = CRM_Streetimport_Config::singleton();
    $end_date = date('YmdHis', strtotime('yesterday')); // end_date has to be now, not $this->getDate()

    // first load the membership
    if (empty($membership)) {
      return $this->logger->logError("Contract (membership) [{$membership['id']}] NOT FOUND.", $record);
    }

    // now check if it's still active
    if (!$this->isContractActive($membership)) {
      $this->logger->logError("Contract (membership) [{$membership['id']}] is not active.", $record);
    }

    // finally set to cancelled
    $membership_cancellation = array(
      'id'        => $membership['id'],
      'status_id' => $config->getMembershipCancelledStatus(),
      'end_date'  => $end_date);

    // add extra parameters
    foreach ($params as $key => $value) {
      $membership_cancellation[$key] = $value;
    }

    // add cancel data
    $cancel_reason = CRM_Utils_Array::value('cancel_reason', $params, 'MS02');
    $membership_cancellation[$config->getGPCustomFieldKey('membership_cancel_reason')] = $cancel_reason;
    $membership_cancellation[$config->getGPCustomFieldKey('membership_cancel_date')] = $this->getDate($record);

    // finally: end membership
    civicrm_api3('Membership', 'create', $membership_cancellation);
    $this->logger->logDebug("Contract (membership) [{$membership['id']}] ended.", $record);

    // NOW: end the attached recurring contribution
    $contribution_recur_id = $membership[$config->getGPCustomFieldKey('membership_recurring_contribution')];
    if ($contribution_recur_id) {
      // check if this is a SepaMandate
      $sepa_mandate = civicrm_api3('SepaMandate', 'get', array(
        'entity_id'    => $contribution_recur_id,
        'entity_table' => 'civicrm_contribution_recur'));
      if ($sepa_mandate['count']) {
        // this is a SEPA Mandate
        if ($sepa_mandate['id']) {
          $mandate = reset($sepa_mandate['values']);
          if ($this->isMandateActive($mandate)) {
            // TODO: use API (when available)
            CRM_Sepa_BAO_SEPAMandate::terminateMandate($sepa_mandate['id'], $end_date, $cancel_reason);
            CRM_Sepa_Logic_Batching::closeEnded();
            $this->logger->logDebug("Mandate '{$mandate['reference']}' ended.");
          } else {
            $this->logger->logDebug("Mandate  '{$mandate['reference']}' has already been cancelled.");
          }

        } else {
          $this->logger->logError("Multiple mandates found! This shouldn't happen, please investigate", $record);
        }

      } else {
        // this is a non-sepa recurring contribution
        $contribution_recur_search = civicrm_api3('ContributionRecur', 'get', array('id' => $contribution_recur_id));
        if ($contribution_recur_search['id']) {
          $contribution_recur = reset($contribution_recur_search['values']);
          if ($this->isContributionRecurActive($contribution_recur)) {
            $cancel_reason = CRM_Utils_Array::value('cancel_reason', $params);
            civicrm_api3('ContributionRecur', 'create', array(
              'id'                     => $contribution_recur['id'],
              'cancel_reason'          => $cancel_reason,
              'end_date'               => $end_date,
              'contribution_status_id' => 3, // Cancelled
              ));
            $this->logger->logDebug("RecurringContribution [{$contribution_recur['id']}] ended.");
          } else {
            $this->logger->logDebug("RecurringContribution [{$contribution_recur['id']}] has already been cancelled.");
          }
        }
      }
    }
    $this->logger->logDebug("No payment scheme attached to contract (membership) [{$membership['id']}].", $record);
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




  /*****************************************************
   *               ACTIVITY CREATION                   *
   ****************************************************/


  /**
   * Create a "Manual Update" activity
   */
  public function createManualUpdateActivity($contact_id, $message, $record) {
    $config = CRM_Streetimport_Config::singleton();

    // first get contact called activity type
    if ($this->_manual_update_required_id == NULL) {
      $this->_manual_update_required_id = CRM_Core_OptionGroup::getValue('activity_type', 'manual_update_required', 'name');
      if (empty($this->_manual_update_required_id)) {
        // couldn't be found => create
        $activity = civicrm_api3('OptionValue', 'create', array(
          'option_group_id' => 'activity_type',
          'name'            => 'manual_update_required',
          'label'           => $config->translate('Manual Update Required'),
          'is_active'       => 1
          ));
        $this->_manual_update_required_id = CRM_Core_OptionGroup::getValue('activity_type', 'manual_update_required', 'name');
      }
    }

    // NOW create the activity
    $activityParams = array(
      'activity_type_id'    => $this->_manual_update_required_id,
      'subject'             => $config->translate('Manual Update Required'),
      'details'             => $message,
      'status_id'           => $config->getActivityCompleteStatusId(),
      'campaign_id'         => $this->getCampaignID($record),
      'activity_date_time'  => $this->getDate($record),
      'target_contact_id'   => (int) $contact_id,
      'source_contact_id'   => (int) $config->getCurrentUserID(),
      'assignee_contact_id' => (int) $config->getFundraiserContactID(),
    );

    // add segment ("Zielgruppe")
    $segment = $this->getSegment($record);
    if ($segment) {
      $activityParams[$config->getGPCustomFieldKey('segment')] = $segment;
    }

    $this->createActivity($activityParams, $record, array($config->getFundraiserContactID()));
  }


  /**
   * Create a RESPONSE activity
   */
  public function createResponseActivity($contact_id, $title, $record) {
    $config = CRM_Streetimport_Config::singleton();

    // first get Response activity type
    if ($this->_response_activity_id == NULL) {
      $this->_response_activity_id = CRM_Core_OptionGroup::getValue('activity_type', 'Response', 'name');
      if (empty($this->_response_activity_id)) {
        // couldn't be found => create
        $activity = civicrm_api3('OptionValue', 'create', array(
          'option_group_id' => 'activity_type',
          'name'            => 'Response',
          'label'           => $config->translate('Manual Update Required'),
          'is_active'       => 1
          ));
        $this->_response_activity_id = CRM_Core_OptionGroup::getValue('activity_type', 'Response', 'name');
      }
    }

    // determine the subject
    $campaign = $this->loadEntity('Campaign', $this->getCampaignID($record));
    $subject = $campaign['title'] . ' - ' . $title;

    // NOW create the activity
    $activityParams = array(
      'activity_type_id'    => $this->_response_activity_id,
      'subject'             => $subject,
      'status_id'           => $config->getActivityCompleteStatusId(),
      'campaign_id'         => $this->getCampaignID($record),
      'activity_date_time'  => $this->getDate($record),
      'source_contact_id'   => (int) $config->getCurrentUserID(),
      'target_contact_id'   => (int) $contact_id,
      // 'assignee_contact_id' => (int) $config->getFundraiserContactID(),
    );

    // add segment ("Zielgruppe")
    $segment = $this->getSegment($record);
    if ($segment) {
      $activityParams[$config->getGPCustomFieldKey('segment')] = $segment;
    }

    $activity = $this->createActivity($activityParams, $record);
  }


  /**
   * Create a "Contact Updated" activity
   */
  public function createContactUpdatedActivity($contact_id, $subject, $details, $record) {
    $config = CRM_Streetimport_Config::singleton();

    // first get contact called activity type
    if ($this->_update_activity_id == NULL) {
      $this->_update_activity_id = CRM_Core_OptionGroup::getValue('activity_type', 'contact_updated', 'name');
      if (empty($this->_update_activity_id)) {
        // couldn't be found => create
        $activity = civicrm_api3('OptionValue', 'create', array(
          'option_group_id' => 'activity_type',
          'name'            => 'contact_updated',
          'label'           => $config->translate('Contact Updated'),
          'is_active'       => 1
          ));
        $this->_update_activity_id = CRM_Core_OptionGroup::getValue('activity_type', 'contact_updated', 'name');
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
      // 'assignee_contact_id' => (int) $config->getFundraiserContactID(),
    );

    // add segment ("Zielgruppe")
    $segment = $this->getSegment($record);
    if ($segment) {
      $activityParams[$config->getGPCustomFieldKey('segment')] = $segment;
    }

    $this->createActivity($activityParams, $record);
  }

}
