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
define('TM_KONTAKT_RESPONSE_ZUSAGE_BIODIV',         52);
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

define('TM_KONTAKT_RESPONSE_GELEGENTLICHER_SPENDER', 22);
define('TM_KONTAKT_RESPONSE_KONTAKT_RESCUE',         24);
define('TM_KONTAKT_RESPONSE_KONTAKT_LOESCHEN',       25);
define('TM_KONTAKT_RESPONSE_KONTAKT_STILLEGEN',      26);
define('TM_KONTAKT_RESPONSE_NICHT_KONTAKTIEREN',     27);
define('TM_KONTAKT_RESPONSE_KONTAKT_VERSTORBEN',     40);
define('TM_KONTAKT_RESPONSE_KONTAKT_ANRUFSPERRE',    41);

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
define('TM_PROJECT_TYPE_POSTALRETURN', 'rts'); // Recherche
define('TM_PROJECT_TYPE_WELCOME', 'wel'); // Welcome
define('TM_PROJECT_TYPE_HAPPY', 'hap'); // Happy

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
   * Get the activity ID referenced by this record, or as determined by fallback
   * parent logic
   *
   * @param array $record
   *
   * @return int|null
   */
  protected function getActivityId($record) {
    $activityId = $this->getStrictActivityId($record);
    if (empty($activityId)) {
      $this->logger->logWarning('Could not determine strict parent activity', $record);
      $activityId = $this->getFallbackActivityId($record);
    }
    if (empty($activityId)) {
      $this->logger->logWarning('Could not determine fallback parent activity', $record);
    }
    return $activityId;
  }

  /**
   * Get activity_id referenced in $record
   *
   * @param $record
   *
   * @return int|null
   * @throws \CiviCRM_API3_Exception
   */
  protected function getStrictActivityId($record) {
    if (empty($record['activity_id'])) {
      return NULL;
    }
    $activityCount = civicrm_api3('Activity', 'getcount', [
      'id'               => $record['activity_id'],
      'activity_type_id' => ['IN' => ['Action', 'Outgoing Call']],
    ]);
    if (empty($activityCount)) {
      return NULL;
    }
    return $record['activity_id'];
  }

  /**
   * Get activity_id related to $record based on relevant activity type,
   * matching campaign and temporal relation
   *
   * @param $record
   *
   * @return int|null
   */
  protected function getFallbackActivityId($record) {
    return $this->getParentActivityId(
      $this->getContactID($record),
      $this->getCampaignID($record),
      [
        'activity_types' => ['Action', 'Outgoing Call'],
        'min_date' => date('Y-m-d', strtotime('-90 days', strtotime($this->getDate($record)))),
        'max_date' => date('Y-m-d', strtotime($this->getDate($record))),
      ]
    );
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
    $parent_id = $this->getActivityId($record);
    if (!empty($parent_id)) {
      $data[$parent_id_field] = $parent_id;
    }
    return parent::createActivity($data, $record, $assigned_contact_ids);
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
