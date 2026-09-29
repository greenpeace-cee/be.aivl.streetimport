<?php

use Civi\Api4\Activity;
use Civi\Api4\Campaign;
use Civi\Api4\Contact;
use Civi\Api4\OptionValue;
use Civi\Api4\Phone;

class CRM_Streetimport_GPPL_Handler_TMResponseHandler extends CRM_Streetimport_GP_Handler_TMRecordHandler {

  const PROGRAM_TYPE_UPGRADING = 'upgr';
  const PROGRAM_TYPES = [
    self::PROGRAM_TYPE_UPGRADING
  ];

  const RESPONSE_REJECT = '55';
  const RESPONSE_SUCCESSFUL = '56';
  const RESPONSE_WRONG_PHONE = '90';
  const RESPONSE_NOT_REACHED = '91';
  const RESPONSE_DO_NOT_PHONE = '92';

  const CONTACT_CALLED_RESPONSES = [
    self::RESPONSE_SUCCESSFUL,
    self::RESPONSE_REJECT,
    self::RESPONSE_DO_NOT_PHONE
  ];

  protected static $TM_PATTERN = '#tfr_response_(?P<project1>\\w+)_(?P<code>c?\d{4})_(?P<date>\\d{8})(.*)[.]csv$#';

  protected $record = [];
  protected $config;
  protected ?int $contact_id;
  protected ?int $activity_id;
  protected ?array $membership;
  protected ?string $response_title;
  protected DateTime $response_date;
  protected DateTime $start_date;

  /**
   * Check if the given handler implementation can process the record
   *
   * @param $record  an array of key=>value pairs
   *
   * @return bool
   */
  public function canProcessRecord($record, $sourceURI) {
    $parsedFileName = $this->parseTmFile($sourceURI);
    return !empty($parsedFileName);
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
    // use per-record log_id
    CRM_Core_DAO::executeQuery('SET @uniqueID = %1', [1 => [uniqid() . CRM_Utils_String::createRandom(4, CRM_Utils_String::ALPHANUMERIC), 'String']]);
    $tx = new CRM_Core_Transaction();
    try {
      $this->config = CRM_Streetimport_Config::singleton();
      $this->record = $record;
      $this->file_name_data = $this->parseTmFile($sourceURI);
      $this->process();
    } catch (Exception $e) {
      $tx->rollback();
      $this->logger->logImport($record, FALSE, $this->config->translate('TM Response'));
      $this->logger->logError($e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine() . "\n" . $e->getTraceAsString(), $record, 'TM Response Import Error');
      return FALSE;
    }
    $this->logger->logImport($record, TRUE, $this->config->translate('TM Response'));
    return TRUE;
  }

  /**
   * @throws \CRM_Streetimport_ImportException
   */
  protected function process() {
    if (empty($this->record['payment_frequency']) && !empty($this->record['frequency_interval'])) {
      // compatibility fix: frequency_interval should be called payment_frequency, rewrite internally
      $this->record['payment_frequency'] = $this->record['frequency_interval'];
      unset($this->record['frequency_interval']);
    }
    if (!in_array($this->file_name_data['project1'], self::PROGRAM_TYPES)) {
      throw new CRM_Streetimport_ImportException("Unsupported program type '{$this->file_name_data['project1']}'");
    }

    $this->contact_id = $this->getContactID($this->record);

    $response = OptionValue::get(FALSE)
      ->addSelect('id', 'title')
      ->addWhere('option_group_id:name', '=', 'response')
      ->addWhere('value', '=', $this->record['response_code'])
      ->execute()
      ->first();
    if (empty($response['id'])) {
      throw new CRM_Streetimport_ImportException("Invalid response code '{$this->record['response_code']}'");
    }
    $this->response_title = $response['title'];

    $this->response_date = $this->parseDate($this->record['response_date']);

    if (empty($this->record['start_date'])) {
      $this->start_date = new DateTime();
    }
    else if (!empty($this->record['start_date'])) {
      $this->start_date = $this->parseDate($this->record['start_date']);
    }
    if ($this->start_date < new DateTime()) {
      $this->start_date = new DateTime();
    }

    $this->activity_id = $this->createOutgoingCall();
    $this->createResponse();
    $this->processResponse();
    if (in_array($this->record['response_code'], self::CONTACT_CALLED_RESPONSES)) {
      $this->createContactCalledActivity();
    }
  }

  protected function getContactID($record, bool $throwExceptionIfNotFound = TRUE) {
    $contact_id = $this->getContactIDbyCiviCRMID($record['contact_id']);
    if (empty($contact_id) && $throwExceptionIfNotFound) {
      throw new CRM_Streetimport_ImportException("Contact [{$record['contact_id']}] couldn't be identified.");
    }
    return $contact_id;
  }

  protected function parseDate(string $rawDate) {
    $formats = ['Y-m-d', 'Y-m-d H:i:s'];
    foreach ($formats as $format) {
      $date = DateTime::createFromFormat($format, $rawDate);
      if (!$date || $date->format($format) != $rawDate) {
        continue;
      }
      return $date;
    }
    throw new CRM_Streetimport_ImportException("Invalid date value '{$rawDate}', expected format: " . implode(' or ', $formats));
  }

  /**
   * Create an Outgoing Call activity
   *
   * @return int activity_id
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  protected function createOutgoingCall() {
    $campaign_title = Campaign::get(FALSE)
      ->addSelect('title')
      ->addWhere('id', '=', $this->getCampaignID($this->record))
      ->execute()
      ->first()['title'];

    return Activity::create(FALSE)
      ->addValue('activity_type_id:name', 'Outgoing Call')
      ->addValue('campaign_id', $this->getCampaignID($this->record))
      ->addValue('medium_id:name', 'phone')
      ->addValue('activity_date_time', $this->response_date->format('YmdHis'))
      ->addValue('subject', $campaign_title)
      ->addValue('status_id:name', 'Completed')
      ->addValue('activity_tmresponses.response', $this->record['response_code'])
      ->addValue('activity_tmresponses.response_date', $this->response_date->format('YmdHis'))
      ->addValue('target_contact_id', $this->contact_id)
      ->addValue('source_contact_id', $this->config->getCurrentUserID())
      ->execute()
      ->first()['id'];
  }

  /**
   * Create a Response activity
   *
   * @return int activity_di
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  protected function createResponse() {
    return Activity::create(FALSE)
      ->addValue('activity_type_id:name', 'Response')
      ->addValue('campaign_id', $this->getCampaignID($this->record))
      ->addValue('medium_id:name', 'phone')
      ->addValue('activity_date_time', $this->response_date->format('YmdHis'))
      ->addValue('subject', $this->response_title)
      ->addValue('status_id:name', 'Completed')
      ->addValue('target_contact_id', $this->contact_id)
      ->addValue('activity_hierarchy.parent_activity_id', $this->activity_id)
      ->addValue('source_contact_id', $this->config->getCurrentUserID())
      ->execute()
      ->first()['id'];
  }

  protected function processResponse() {
    switch ($this->record['response_code']) {
      case self::RESPONSE_WRONG_PHONE:
        $this->deleteAllPhoneNumbers();
        break;

      case self::RESPONSE_DO_NOT_PHONE:
        $this->setDoNotPhone();
        break;

      case self::RESPONSE_SUCCESSFUL:
        switch ($this->file_name_data['project1']) {
          case self::PROGRAM_TYPE_UPGRADING:
            $this->upgradeMembership();
        }
        break;
    }
  }

  protected function deleteAllPhoneNumbers() {
    $subject = $this->config->translate('Contact Phone Deleted');
    $phones = Phone::get(FALSE)
      ->addSelect('id', 'phone')
      ->addWhere('contact_id', '=', $this->contact_id)
      ->execute();
    foreach ($phones as $phone) {
      Phone::delete(FALSE)
        ->addWhere('id', '=', $phone['id'])
        ->execute();
      $details = sprintf($this->config->translate('Deleted phone number %s'), $phone['phone']);
      $this->createContactUpdatedActivity($this->contact_id, $subject, $details, $this->record);
    }

  }

  protected function setDoNotPhone() {
    Contact::update()
      ->addValue('do_not_phone', TRUE)
      ->addWhere('id', '=', $this->contact_id)
      ->execute();
    $this->createContactUpdatedActivity($this->contact_id, 'Contact set to Do Not Phone', NULL, $this->record);
  }

  protected function upgradeMembership() {
    $this->membership = \Civi\Api4\Membership::get(FALSE)
      ->addSelect('*', 'status_id:name', 'custom.*')
      ->addWhere('contact_id', '=', $this->contact_id)
      ->addWhere('id', '=', $this->record['membership_id'])
      ->execute()
      ->first();

    if (empty($this->membership)) {
      throw new CRM_Streetimport_ImportException("Membership {$this->record['membership_id']} not found for contact {$this->contact_id}");
    }
    if ($this->membership['status_id:name'] != 'Current') {
      $this->logger->logError("Membership must be active for Upgrading", $this->record);
    }

    if (empty($this->record['already_upgraded'] ?? '0')) {
      $contract_data = [
        'action'                                  => 'update',
        'campaign_id'                             => $this->getCampaignID($this->record),
        'date'                                    => $this->start_date->format('YmdHis'),
        'id'                                      => $this->membership['id'],
        'medium_id'                               => $this->getMediumID($this->record),
        'membership_payment.defer_payment_start'  => 1,
        'membership_payment.membership_annual'    => $this->record['annual_amount'] * $this->getFrequencyDebitsPerYear(),
        'membership_payment.membership_frequency' => $this->getFrequencyDebitsPerYear(),
      ];
      $result = civicrm_api3('Contract', 'modify', $contract_data);
      $update_activity = reset($result['values'])['change_activity_id'];
      if (empty($update_activity)) {
        throw new CRM_Streetimport_ImportException("Unable to find Update Contract activity");
      }
      Activity::update(FALSE)
        ->addValue('activity_hierarchy.parent_activity_id', $this->activity_id)
        ->addWhere('id', '=', $update_activity)
        ->execute();
      $this->_contract_changes_produced = TRUE;
    }
    else {
      // special handling for upgradings that were already entered. look for an
      // existing contract updated activity on or after the response date with
      // a matching campaign. if exactly one record is matched, update the
      // parent to the outgoing call activity id, otherwise error.
      $activities = Activity::get(FALSE)
        ->addSelect('id', 'activity_hierarchy.parent_activity_id')
        ->addWhere('campaign_id', '=', $this->getCampaignID($this->record))
        ->addWhere('source_record_id', '=', $this->membership['id'])
        ->addWhere('activity_type_id:name', '=', 'Contract_Updated')
        ->addWhere('activity_date_time', '>=', $this->response_date->format('YmdHis'))
        ->execute();
      if ($activities->countMatched() == 1) {
        Activity::update(FALSE)
          ->addValue('activity_hierarchy.parent_activity_id', $this->activity_id)
          ->addWhere('id', '=', $activities->first()['id'])
          ->execute();
      }
      else {
        $this->logger->logError("Unable to find exactly one existing Update Contract activity after response_date (found " . $activities->countMatched() . ")", $this->record);
      }
    }
  }

  /**
   * Retrieves the number of debits per year based on the payment frequency.
   *
   * @return int The number of debits per year corresponding to the payment frequency.
   * @throws CRM_Streetimport_ImportException If the payment frequency is invalid or not found.
   */
  protected function getFrequencyDebitsPerYear(): int {
    $paymentFrequency = OptionValue::get(FALSE)
      ->addSelect('value')
      ->addWhere('option_group_id:name', '=', 'payment_frequency')
      ->addWhere('name', '=', $this->record['payment_frequency'])
      ->execute()
      ->first();
    if (empty($paymentFrequency['value'])) {
      throw new CRM_Streetimport_ImportException("Invalid payment frequency '{$this->record['payment_frequency']}'");
    }
    return (int) $paymentFrequency['value'];
  }

  protected function createContactCalledActivity() {
    return Activity::create(FALSE)
      ->addValue('activity_type_id:name', 'Contact Called')
      ->addValue('campaign_id', $this->getCampaignID($this->record))
      ->addValue('medium_id:name', 'phone')
      ->addValue('activity_date_time', $this->response_date->format('YmdHis'))
      ->addValue('subject', $this->config->translate('Contact Called'))
      ->addValue('status_id:name', 'Completed')
      ->addValue('target_contact_id', $this->contact_id)
      ->addValue('activity_hierarchy.parent_activity_id', $this->activity_id)
      ->addValue('source_contact_id', $this->config->getCurrentUserID())
      ->execute()
      ->first()['id'];
  }

}
