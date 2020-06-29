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
abstract class CRM_Streetimport_GP_Handler_TMRecordHandler extends CRM_Streetimport_GP_Handler_GPRecordHandler {

  /** file name pattern as used by TM company */
  protected static $TM_PATTERN = '#(?P<org>[a-zA-Z\-]+)_(?P<project1>\w+)_(?P<tm_company>[a-z]+)_(?P<code>C?\d{4})_(?P<date>\d{8})_(?P<time>\d{6})_(?P<project2>.+)_(?P<file_type>[a-zA-Z]+)[.]csv$#';

  /** stores the parsed file name */
  protected $file_name_data = NULL;

  /**
   * Checks if this record uses IMB or CiviCRM IDs
   * aka legacy mode
   */
  protected function isCompatibilityMode($record) {
    return substr($this->file_name_data['code'], 0, 1) != 'C';
  }

  /**
   * Checks if this record uses IMB or CiviCRM IDs
   */
  protected function getCampaignID($record) {
    $campaign_identifier = $this->file_name_data['code'];
    if ($this->isCompatibilityMode($record)) {
      // these are IMB campaign IDs, look up the internal Id
      return $this->getCampaignIDbyExternalIdentifier('AKTION-' . $campaign_identifier);

    } else {
      // this should be an internal campaign id, with prefix 'C'
      return (int) substr($campaign_identifier, 1);
    }
  }

  /**
   * this will return the membership object representing the contract
   */
  protected function getContract($record, $contact_id) {
    $membership_id = $this->getContractID($contact_id, $record);
    if (empty($membership_id)) return NULL;

    $membership = civicrm_api3('Membership', 'get', array('id' => $membership_id));
    if ($membership['id']) {
      return reset($membership['values']);
    } else {
      return NULL;
    }
  }

  /**
   * Will resolve the referenced contact id
   */
  protected function getContactID($record) {
    if ($this->isCompatibilityMode($record)) {
      // these are IMB contact numbers
      $external_identifier = 'IMB-' . trim($record['id']);
      return $this->getContactIDbyExternalID($external_identifier);
    } else {
      return $this->getContactIDbyCiviCRMID($record['id']);
    }
  }

  /**
   * Get the activity ID referenced by this record
   *
   * @param array $record
   *
   * @return int|null
   */
  protected function getActivityId($record) {
    return empty($record['activity_id']) ? NULL : $record['activity_id'];
  }

  /**
   * Checks if a contact was anonymized.
   *
   * This does not handle compatibility mode IDs and does not use identity
   * tracker, so it's merely a best-effort lookup intended to reduce
   * import errors.
   *
   * @param $record
   *
   * @return bool
   * @throws \CiviCRM_API3_Exception
   */
  protected function isAnonymized($record) {
    if (!$this->isCompatibilityMode($record)) {
      try {
        $name = civicrm_api3(
          'Contact',
          'getvalue', [
            'id' => $record['id'],
            'is_deleted' => TRUE,
            'return' => 'display_name',
          ]
        );
        if ($name == 'Anonymous') {
          return TRUE;
        }
      } catch (CiviCRM_API3_Exception $e) {
        return FALSE;
      }
    }
    return FALSE;
  }

  /**
   * Get the relevant address Id for the contact
   */
  protected function getAddressId($contact_id, $record) {
    if ($this->isCompatibilityMode($record) || empty($record['address_id'])) {
      // in compatibility mode we don't have an ID, just get the primary address
      $addresses_search = array(
        'contact_id' => $contact_id,
        'is_primary' => 1);
    } else {
      // check if the address_id is (still) there
      $addresses_search = array('id' => $record['address_id']);
    }

    $address_query = civicrm_api3('Address', 'get', $addresses_search);
    if (isset($address_query['id']) && $address_query['id']) {
      // address found
      return $address_query['id'];
    } else {
      return NULL;
    }
  }

  /**
   * Will try to parse the given name and extract the parameters outlined in TM_PATTERN
   *
   * @return NULL if not matched, data else
   */
  protected function parseTmFile($sourceID) {
    if (preg_match(self::$TM_PATTERN, $sourceID, $matches)) {
      return $matches;
    } else {
      return NULL;
    }
  }

  /**
   * Extracts the specific activity date for this line
   */
  protected function getDate($record) {
    // don't use date from file at all (Marco, Skype, 2017-10-23, see GP-1160)
    // if (!empty($this->file_name_data)) {
    //   // take date from the file name
    //   $file_timestamp = strtotime("{$this->file_name_data['date']}{$this->file_name_data['time']}");
    //   if ($file_timestamp) {
    //     return date('YmdHis', $file_timestamp);
    //   } else {
    //     $this->logger->logError("Couldn't parse date '{$this->file_name_data['date']}{$this->file_name_data['time']}' (from filename). Using 'now' instead.", $record);
    //   }
    // }

    // fallback is 'now'
    return date('YmdHis');
  }

  /**
   * get the medium for created activities
   */
  public function getMediumID($record) {
    return 2; // Phone
  }

  /**
   * Create an activity with the given data and determine the parent activity
   *
   * @param $data
   * @param $record
   * @param null $assigned_contact_ids
   *
   * @return \activity|void
   */
  public function createActivity($data, $record, $assigned_contact_ids=NULL) {
    $config = CRM_Streetimport_Config::singleton();
    $parent_id_field = $config->getGPCustomFieldKey('parent_activity_id');
    $parent_id = $this->getActivityId($record) ?? $this->getParentActivityId(
      (int) $this->getContactID($record),
      $this->getCampaignID($record),
      [
        'activity_types' => ['Action'],
        'min_date' => date('Y-m-d', strtotime('-90 days', strtotime($this->getDate($record)))),
        'max_date' => date('Y-m-d', strtotime($this->getDate($record))) ,
      ]
    );
    if (empty($parent_id)) {
      $this->logger->logWarning("Could not find parent Action activity for contact " . $this->getContactID($record), $record);
    } else {
      $data[$parent_id_field] = $parent_id;
    }
    return parent::createActivity($data, $record, $assigned_contact_ids);
  }
}
