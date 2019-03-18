<?php
/*-------------------------------------------------------------+
| Greenpeace Hungary StreetImporter Record Handlers             |
| Copyright (C) 2018 Greenpeace CEE                            |
| Author: P. Figel (pfigel@greenpeace.org)                     |
+--------------------------------------------------------------*/

/**
 * Greenpeace Hungary Engaging Networks Import
 *
 * @author Patrick Figel <pfigel@greenpeace.org>
 * @license AGPL-3.0
 */
class CRM_Streetimport_GPHU_Handler_EngagingNetworksHandler extends CRM_Streetimport_RecordHandler {

  const PATTERN = '#/eaexport\-(?P<date>\d{8})\.csv$#';

  private $_date;
  private $_fileMatches;

  public function __construct($logger) {
    parent::__construct($logger);
  }

  /**
   * Determine whether this handler should process the given file/record
   *
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
   * Process a record
   *
   * @param array $record
   * @param $sourceURI
   *
   * @return void
   * @throws \Exception
   */
  public function processRecord($record, $sourceURI) {
    if ($record['owned_hungary'] != 'HU') {
      return $this->logger->logImport($record, FALSE, 'Engaging Networks', "Ignored owned_hungary != HU");
    }
    if (!in_array($record['Campaign Type'], ['QMR', 'QCB', 'MSU', 'HSU'])) {
      return $this->logger->logImport($record, FALSE, 'Engaging Networks', "Ignored unhandled Campaign Type");
    }
    $tx = new CRM_Core_Transaction();
    try {
      $change_opt_in = FALSE;
      if (in_array($record['Campaign Type'], ['QMR', 'QCB'])) {
        if ($record['Campaign ID'] != 'email_ok_hungary') {
          return $this->logger->logImport($record, FALSE, 'Engaging Networks', "Ignored Campaign ID {$record['Campaign ID']}");
        }
        $change_opt_in = TRUE;
      }
      $this->processEmail($record, $change_opt_in);
      return $this->logger->logImport($record, TRUE, 'Engaging Networks');
    }
    catch (Exception $e) {
      $tx->rollback();
      throw $e;
    }
  }

  /**
   * Process email opt-in and opt-out requests
   *
   * @param $record array
   * @param $changeOptIn bool
   *
   * @return void
   * @throws \CiviCRM_API3_Exception
   */
  private function processEmail($record, $changeOptIn) {
    $config = CRM_Streetimport_Config::singleton();
    if ($record['Campaign Status'] == 'N' && $changeOptIn) {
      // this is an opt-out. we explicitly want to cover possible duplicates,
      // so match via email and remove newsletter group for all contacts.
      $contacts = civicrm_api3('Email', 'get', [
        'email'      => $record['email'],
        'is_primary' => 1,
        'return'     => 'contact_id',
      ]);

      foreach ($contacts['values'] as $contact) {
        $this->removeContactFromGroup($contact['contact_id'], $config->getNewsletterGroupID(), $record);
        $this->logger->logMessage("Opting out Contact ID {$contact['contact_id']}", $record);
      }
      $this->logger->logMessage('Processed Opt-out', $record);
    }
    else {
      $contact = $contact = $this->getOrCreateContact($record, $changeOptIn);
      if ($changeOptIn && $record['Campaign Status'] == 'Y') {
        $this->logger->logMessage("Opting in Contact ID {$contact['id']}", $record);
        $this->addContactToGroup($contact['id'], $config->getNewsletterGroupID(), $record);
      }
      if (!empty($record['Suppressed'])) {
        // if Suppressed == 'Y', set email to "On Hold"
        $email = reset(civicrm_api3('Email', 'get', [
          'contact_id' => $contact['id'],
          'email'      => $record['email'],
          'return'     => 'id,on_hold',
        ])['values']);
        $on_hold = $record['Suppressed'] == 'Y' ? TRUE : FALSE;
        if ($on_hold != $email['on_hold']) {
          $this->logger->logMessage("Changing on_hold to {$record['Suppressed']} for Contact ID {$contact['id']}", $record);
          civicrm_api3('Email', 'create', [
            'id'         => $email['id'],
            'contact_id' => $contact['id'],
            'email'      => $record['email'],
            'on_hold'    => $on_hold,
          ]);
        }
      }
      $this->logger->logMessage('Contact data updated', $record);
    }
  }

  /**
   * Get or create the contact
   *
   * @param $record
   *
   * @return array
   * @throws \CiviCRM_API3_Exception
   */
  private function getOrCreateContact($record, $changeOptIn) {
    if (empty($record['civi_id']) && !empty($record['supporter_id'])) {
      // we have no civi_id, but supporter_id (friends ID) is given
      // use that to determine the civi_id, or fall back to XCM otherwise
      try {
        $record['civi_id'] = civicrm_api3('Contact', 'getvalue', [
          'external_identifier' => $record['supporter_id'],
          'return'              => 'id',
        ]);
      }
      catch (CiviCRM_API3_Exception $e) {
        $this->logger->logWarning("Couldn't find contact with supporter_id='{$record['supporter_id']}'.", $record);
      }
    }
    $phone = preg_replace('/[^0-9]/', '', CRM_Utils_Array::value('phone_number', $record));
    if (strlen($phone) > 20) {
      $this->logger->logWarning("Ignored invalid phone number '{$phone}'.", $record);
      $phone = '';
    }
    // phone numbers in civi are mostly prefixed with zeros
    if ($phone[0] == '6') {
      $phone = "0{$phone}";
    }
    $params = [
      'xcm_profile'  => 'engaging_networks',
      'id'           => $record['civi_id'],
      'first_name'   => $record['first_name'],
      'last_name'    => $record['last_name'],
      'email'        => $record['email'],
      'phone'        => $phone,
    ];
    if ($changeOptIn) {
      $params['do_not_email'] = 0;
      $params['is_opt_out'] = 0;
    }
    $this->unsetEmpty($params);
    return civicrm_api3('Contact', 'getorcreate', $params);
  }

  public function unsetEmpty(&$params) {
    $unsetListKeys = [
      'first_name', 'last_name', 'email', 'phone'
    ];
    foreach ($unsetListKeys as $key) {
      if (empty($params[$key])) {
        unset($params[$key]);
      }
    }
  }

}
