<?php
/*-------------------------------------------------------------+
| Greenpeace Poland StreetImporter Record Handlers             |
| Copyright (C) 2018 Greenpeace CEE                            |
| Author: P. Figel (pfigel@greenpeace.org)                     |
+--------------------------------------------------------------*/

/**
 * Greenpeace Poland Bank Authorization Response Import
 *
 * @author Patrick Figel <pfigel@greenpeace.org>
 * @license AGPL-3.0
 */
class CRM_Streetimport_GPPL_Handler_BankAuthorizationHandler extends CRM_Streetimport_RecordHandler {

  const PATTERN = '#/AU(?P<date>\d{6})\.csv$#';

  private $_date;
  private $_fileMatches;
  private $_cancellationReasons = [];

  public function __construct($logger) {
    parent::__construct($logger);
    $this->loadCancellationReasons();
  }

  /**
   * @param array $record
   * @param $sourceURI
   *
   * @return bool|null|true
   */
  public function canProcessRecord($record, $sourceURI) {
    if (is_null($this->_fileMatches)) {
      $this->_fileMatches = (bool) preg_match(self::PATTERN, $sourceURI, $matches);
      if ($this->_fileMatches) {
        $this->_date = $matches['date'];
      }
    }
    return $this->_fileMatches;
  }

  /**
   * @param array $record
   * @param $sourceURI
   *
   * @return void
   * @throws \CiviCRM_API3_Exception
   */
  public function processRecord($record, $sourceURI) {
    $config = CRM_Streetimport_Config::singleton();
    $membership = civicrm_api3('Membership', 'get', [
      'membership_id' => $record['membership_id'],
    ]);
    if ($membership['count'] == 0) {
      $this->logger->logError($config->translate('Membership not found'), $record);
      $this->logger->logImport($record, FALSE, $config->translate('Bank Authorization'), $config->translate('Membership not found'));
      return;
    }
    $membership = reset($membership['values']);
    try {
      if ($record['response_code'] == '1') {
        $this->createAuthorizationActivity($record, $membership['id'], $membership['contact_id'], 'Contract_Authorization_Approved', $record['response_description']);
        // approved
        if ($membership['status_id'] == $config->getPausedMembershipStatus()) {
          $this->resumeContract($membership['id'], $membership['contact_id']);
          $this->resetMembershipStartDate($membership['id']);
          $this->logger->logImport($record, TRUE, $config->translate('Bank Authorization'), $config->translate('Restarted Membership'));
        }
        elseif ($membership['status_id'] == $config->getCancelledMembershipStatus()) {
          if ($this->shouldReviveContract($membership)) {
            // approved + contract is cancelled with authorization reason
            $this->reviveContract($membership['id']);
            $this->logger->logImport($record, TRUE, $config->translate('Bank Authorization'), $config->translate('Revived Membership'));
          }
          else {
            $this->logger->logImport($record, TRUE, $config->translate('Bank Authorization'), $config->translate('Ignoring, Membership is cancelled for unrelated reason'));
          }
        }
        elseif (in_array($membership['status_id'], $config->getActiveMembershipStatuses())) {
          $this->logger->logImport($record, TRUE, $config->translate('Bank Authorization'), $config->translate('Ignoring, Membership is already active'));
        }
        else {
          $this->logger->logError($config->translate('Membership is in an undefined state (' . $membership['status_id'] . ')'), $record);
          $this->logger->logImport($record, FALSE, $config->translate('Bank Authorization'), $config->translate('Membership is in an undefined state'));
        }
      }
      else {
        $this->createAuthorizationActivity($record, $membership['id'], $membership['contact_id'], 'Contract_Authorization_Refused', $record['response_code'] . ' - ' . $record['response_description'], ((int) $config->getFundraiserContactID()));
        // refused
        if ($membership['status_id'] == $config->getPausedMembershipStatus()) {
          // this is slightly non-obvious. We need to resume the contract before
          // we can actually cancel it.
          $this->resumeContract($membership['id'], $membership['contact_id']);
          $this->runScheduledModifications($membership['id']);
          $this->cancelContract($membership['id'], $record['response_code']);
          $this->logger->logImport($record, TRUE, $config->translate('Bank Authorization'), $config->translate('Cancelled Membership'));
        }
        elseif ($membership['status_id'] == $config->getCancelledMembershipStatus()) {
          $this->logger->logImport($record, TRUE, $config->translate('Bank Authorization'), $config->translate('Ignoring, Membership is already cancelled'));
        }
        elseif (in_array($membership['status_id'], $config->getActiveMembershipStatuses())) {
          $this->cancelContract($membership['id'], $record['response_code']);
          $this->logger->logImport($record, TRUE, $config->translate('Bank Authorization'), $config->translate('Cancelled Membership'));
        }
        else {
          $this->logger->logError($config->translate('Membership is in an undefined state (' . $membership['status_id'] . ')'), $record);
          $this->logger->logImport($record, FALSE, $config->translate('Bank Authorization'), $config->translate('Membership is in an undefined state (' . $membership['status_id'] . ')'));
        }
      }
    }
    catch (CRM_Streetimport_GPPL_Handler_BankAuthorizationHandlerException $e) {
      $this->logger->logError('Error: ' . $e->getMessage(), $record);
      $this->logger->logImport($record, FALSE, $config->translate('Bank Authorization'), 'Error: ' . $e->getMessage());
      return;
    }
  }

  /**
   * @param string $sourceURI
   *
   * @throws \CiviCRM_API3_Exception
   */
  public function finishProcessing($sourceURI) {
    $this->runScheduledModifications();
  }

  /**
   * Run all scheduled membership modifications. Optionally limited to one
   * membership via $membershipId
   *
   * @param null $membershipId
   *
   * @throws \CiviCRM_API3_Exception
   */
  private function runScheduledModifications($membershipId = NULL) {
    $data = [];
    if (!is_null($membershipId)) {
      $data['id'] = $membershipId;
    }
    civicrm_api3('Contract', 'process_scheduled_modifications', $data);
  }

  /**
   * Sets the start_date
   *
   * @param $membershipId
   * @param $contactId
   *
   * @throws \CRM_Streetimport_GPPL_Handler_BankAuthorizationHandlerException
   */
  private function resumeContract($membershipId, $contactId) {
    $config = CRM_Streetimport_Config::singleton();
    $activityParams = [
      'activity_type_id' => 'Contract_Resumed',
      'is_current_revision' => 1,
      'is_deleted' => 0,
      'source_record_id' => $membershipId,
      'target_contact_id' => $contactId,
      'status_id' => 'scheduled',
    ];

    try {
      $activities = civicrm_api3('Activity', 'Get', $activityParams);
      if ($activities['count'] > 1) {
        // TODO: check if we need to handle this somehow. I think it's theoretically possible to schedule multiple pauses?
        throw new CRM_Streetimport_GPPL_Handler_BankAuthorizationHandlerException(
          "Found multiple resume activities for membership {$membershipId}"
        );
      }

      if ($activities['count'] < 1) {
        throw new CRM_Streetimport_GPPL_Handler_BankAuthorizationHandlerException(
          "Found no resume activity for membership {$membershipId}"
        );
      }

      $activity = reset($activities['values']);

      $updateParams = [
        'id' => $activity['id'],
        'activity_date_time' => CRM_Contract_Utils::getDefaultContractChangeDate(),
        'status_id' => 'scheduled',
      ];
      civicrm_api3('Activity', 'Create', $updateParams);
    }
    catch (CiviCRM_API3_Exception $e) {
      throw new CRM_Streetimport_GPPL_Handler_BankAuthorizationHandlerException(
        'API error: ' . $e->getMessage()
      );
    }
  }

  private function resetMembershipStartDate($membershipId) {
    $updateParams = [
      'id' => $membershipId,
      'start_date' => date('Y-m-d'),
      'skip_handler' => TRUE, // tell de.systopia.contract to ignore this
    ];
    civicrm_api3('Membership', 'Create', $updateParams);
  }

  /**
   * Determines whether a membership should be revived if a positive
   * authorization is received. This is currently only true if the cancellation
   * reason was authorization-related.
   *
   * @param $membership
   *
   * @return bool
   */
  private function shouldReviveContract($membership) {
    $cancellationReasonFieldId = CRM_Contract_Utils::getCustomFieldId('membership_cancellation.membership_cancel_reason');

    if (!empty($membership[$cancellationReasonFieldId]) && strncasecmp('AUTH', $membership[$cancellationReasonFieldId], 4) === 0) {
      return TRUE;
    }

    return FALSE;
  }

  private function reviveContract($membershipId) {
    $config = CRM_Streetimport_Config::singleton();

    $next_debit_date = new DateTimeImmutable(
      civicrm_api3('Contract', 'start_date', [ 'payment_adapter' => 'sepa_mandate' ])['values'][0]
    );

    civicrm_api3('Contract', 'Modify', [
      'action'            => 'revive',
      'id'                => $membershipId,
      'cycle_day'         => $next_debit_date->format('j'),
      'medium_id'         => $this->getMediumID(),
      'source_contact_id' => (int) $config->getFundraiserContactID(),
    ]);
  }

  private function cancelContract($membershipId, $responseCode) {
    $config = CRM_Streetimport_Config::singleton();
    $reason = 'AUTH' . $responseCode;
    $this->ensureCancellationReasonExists($reason);
    $cancelModification = array(
      'action' => 'cancel',
      'id' => $membershipId,
      'membership_cancellation.membership_cancel_reason' => $reason,
      'medium_id' => $this->getMediumID(),
      'source_contact_id' => (int) $config->getFundraiserContactID(),
    );
    try {
      civicrm_api3('Contract', 'Modify', $cancelModification);
    }
    catch (CiviCRM_API3_Exception $e) {
      throw new CRM_Streetimport_GPPL_Handler_BankAuthorizationHandlerException(
        'API error: ' . $e->getMessage()
      );
    }
  }

  private function loadCancellationReasons() {
    $results = civicrm_api3('OptionValue', 'get', [
      'option_group_id' => 'contract_cancel_reason',
      'options' => ['limit' => 0],
    ]);
    foreach ($results['values'] as $result) {
      $this->_cancellationReasons[] = $result['value'];
    }
  }

  private function ensureCancellationReasonExists($reason) {
    if (in_array($reason, $this->_cancellationReasons)) {
      return;
    }
    civicrm_api3('OptionValue', 'Create', [
      'option_group_id' => 'contract_cancel_reason',
      'name' => $reason,
      'label' => $reason,
      'value' => $reason,
    ]);
    $this->_cancellationReasons[] = $reason;
  }

  private function getActivityType($name, $label = NULL) {
    $activityType = CRM_Core_PseudoConstant::getKey('CRM_Activity_BAO_Activity', 'activity_type_id', $name);
    if (empty($activityType)) {
      if (is_null($label)) {
        $label = $name;
      }
      civicrm_api3('OptionValue', 'create', [
        'option_group_id' => 'activity_type',
        'name' => $name,
        'label' => $label,
        'is_active' => 1,
      ]);
      $activityType = CRM_Core_PseudoConstant::getKey('CRM_Activity_BAO_Activity', 'activity_type_id', $name);
    }

    return $activityType;
  }

  private function createAuthorizationActivity($record, $membershipId, $contactId, $activityType, $responseDescription, $assignTo = NULL) {
    $config = CRM_Streetimport_Config::singleton();
    $activityParams = [
      'activity_type_id' => $this->getActivityType($activityType),
      'subject' => $config->translate('id' . $membershipId . ': ' . $responseDescription),
      'status_id' => $config->getActivityCompleteStatusId(),
      'activity_date_time' => date('YmdHis'),
      'target_contact_id' => $contactId,
      'source_record_id' => $membershipId,
      'source_contact_id' => (int) $config->getFundraiserContactID(),
      'medium_id' => $this->getMediumID(),
    ];
    if (!is_null($assignTo)) {
      $activityParams['assignee_contact_id'] = $assignTo;
    }
    $this->createActivity($activityParams, $record);
  }

  protected function getMediumID() {
    return CRM_Core_PseudoConstant::getKey('CRM_Activity_BAO_Activity', 'medium_id', 'back_office');
  }

}
