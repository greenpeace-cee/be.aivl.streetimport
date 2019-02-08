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
    $tx = new CRM_Core_Transaction();
    try {
      switch ($record['Campaign ID']) {
        case 'email_ok_hungary':
          // this relates to newsletter opt-in status
          $this->processEmail($record);
          break;

        default:
          $this->logger->logImport($record, TRUE, 'Engaging Networks', "Ignored Campaign ID {$record['Campaign ID']}");
          break;
      }
    }
    catch (Exception $e) {
      $tx->rollback();
      throw $e;
    }
  }

  /**
   * Process email opt-in and opt-out requests
   *
   * @param $record
   *
   * @throws \CiviCRM_API3_Exception
   */
  private function processEmail($record) {
    $config = CRM_Streetimport_Config::singleton();
    if ($record['Campaign Status'] == 'N') {
      // this is an opt-out. we explicitly want to cover possible duplicates,
      // so match via email and remove newsletter group for all contacts.
      try {
        $contacts = civicrm_api3('Contact', 'get', [
          'return' => 'id',
          'email' => CRM_Utils_Array::value('email', $record),
        ]);
      }
      catch (Exception $e) {
        if ($e->getMessage() == 'invalid string') {
          // this is fine.
          // (Civi does not allow "select" to be used in .get API parameters.)
          // (Yes, really.)
          $this->logger->logImport($record, FALSE, 'Engaging Networks', 'Skipping line with illegal SELECT value');
        } else {
          throw $e;
        }
      }

      foreach ($contacts['values'] as $contact) {
        $this->removeContactFromGroup($contact['id'], $config->getNewsletterGroupID(), $record);
        $this->logger->logDebug("Opting out Contact ID {$contact['id']}", $record);
      }
      $this->logger->logImport($record, TRUE, 'Engaging Networks', 'Processed Opt-out');
    }
    elseif ($record['Campaign Status'] == 'Y') {
      $contact = $contact = $this->getOrCreateContact($record);
      $this->addContactToGroup($contact['id'], $config->getNewsletterGroupID(), $record);
      if (!empty($record['Suppressed'])) {
        // if Suppressed == 'Y', set email to "On Hold"
        $email = reset(civicrm_api3('Email', 'get', [
          'contact_id' => $contact['id'],
          'email'      => CRM_Utils_Array::value('email', $record),
          'return'     => 'id,on_hold',
        ])['values']);
        $on_hold = $record['Suppressed'] == 'Y' ? TRUE : FALSE;
        if ($on_hold != $email['on_hold']) {
          $this->logger->logMessage("Changing on_hold to {$record['Suppressed']} for Contact ID {$contact['id']}", $record);
          civicrm_api3('Email', 'create', [
            'id'         => $email['id'],
            'contact_id' => $contact['id'],
            'email'      => CRM_Utils_Array::value('email', $record),
            'on_hold'    => $on_hold,
          ]);
        }
      }
      $this->logger->logImport($record, TRUE, 'Engaging Networks', "Processed Opt-in for Contact ID {$contact['id']}");
    }
    else {
      $this->logger->logImport($record, FALSE, 'Engaging Networks', "Invalid value for Campaign Status: {$record['Campaign Status']}");
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
  private function getOrCreateContact($record) {
    if (empty($record['civi_id']) && !empty($record['supporter_id'])) {
      // we have no civi_id, but supporter_id (friends ID) is given
      // use that to determine the civi_id, or fall back to XCM otherwise
      try {
        $record['civi_id'] = civicrm_api3('Contact', 'getvalue', [
          'external_identifier' => $record['supporter_id'],
          'return'              => 'id',
        ]);
      } catch (CiviCRM_API3_Exception $e) {
        $this->logger->logWarning("Couldn't find contact with supporter_id='{$record['supporter_id']}'.", $record);
      }
    }
    $phone = CRM_Utils_Array::value('phone_number', $record);
    if (strlen($phone) > 20) {
      $this->logger->logWarning("Ignoring invalid phone number '{$phone}'.", $record);
      $phone = '';
    }
    // phone numbers in civi are mostly prefixed with zeros
    if ($phone[0] == '6') {
      $phone = "0{$phone}";
    }
    $params = [
      'xcm_profile'  => 'engaging_networks',
      'id'           => CRM_Utils_Array::value('civi_id', $record),
      'first_name'   => CRM_Utils_Array::value('first_name', $record),
      'last_name'    => CRM_Utils_Array::value('last_name', $record),
      'email'        => CRM_Utils_Array::value('email', $record),
      'do_not_email' => 0,
      'is_opt_out'   => 0,
    ];
    // passing empty phone number to XCM creates diff if contact has one already
    if (!empty($phone)) {
      $params['phone'] = $phone;
    }
    return civicrm_api3('Contact', 'getorcreate', $params);
  }
}
