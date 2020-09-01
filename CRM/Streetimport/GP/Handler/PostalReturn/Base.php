<?php
/*-------------------------------------------------------------+
| GP StreetImporter Record Handlers                            |
| Copyright (C) 2017 SYSTOPIA                                  |
| Author: B. Endres (endres -at- systopia.de)                  |
| http://www.systopia.de/                                      |
+--------------------------------------------------------------*/

define('REPETITION_FRAME_DECEASED', "2 years");

/**
 * Base class for postal return files
 *
 * @author Björn Endres (SYSTOPIA) <endres@systopia.de>
 * @author Patrick Figel <pfigel@greenpeace.org>
 * @license AGPL-3.0
 */
abstract class CRM_Streetimport_GP_Handler_PostalReturn_Base extends CRM_Streetimport_GP_Handler_GPRecordHandler {

  /**
   * Get the contact's primary address ID
   *
   * @param $contact_id
   * @param $record
   *
   * @return mixed|null
   * @throws \CiviCRM_API3_Exception
   */
  protected function getPrimaryAddress($contact_id, $record) {
    $config      = CRM_Streetimport_Config::singleton();
    $rts_counter = $config->getGPCustomFieldKey('rts_counter');
    $addresses = civicrm_api3('Address', 'get', array(
      'is_primary'   => 1,
      'contact_id'   => $contact_id,
      'return'       => "{$rts_counter},contact_id,id",
      'option.limit' => 1));
    if ($addresses['count'] == 1) {
      return reset($addresses['values']);
    } else {
      $this->logger->logError("Primary address for contact [{$contact_id}] not found. Couldn't update RTS counter.", $record);
      return NULL;
    }
  }

  /**
   * Increase the RTS counter at the contact's primary address
   *
   * @param $primary
   * @param $record
   *
   * @throws \CiviCRM_API3_Exception
   */
  protected function increaseRTSCounter($primary, $record) {
    $config      = CRM_Streetimport_Config::singleton();
    $rts_counter = $config->getGPCustomFieldKey('rts_counter');
    if ($primary) {
      $new_count = CRM_Utils_Array::value($rts_counter, $primary, 0) + 1;
      civicrm_api3('Address', 'create', array(
        'id'         => $primary['id'],
        $rts_counter => $new_count));
      $this->logger->logDebug("Increased RTS counter for contact [{$primary['contact_id']}] to {$new_count}.", $record);
    }
  }

  /**
   * Add a new RTS activity
   *
   * @param $contact_id
   * @param $category
   * @param $record
   *
   * @throws \CiviCRM_API3_Exception
   */
  protected function addRTSActvity($contact_id, $category, $record) {
    $config = CRM_Streetimport_Config::singleton();
    $activity_params = [
      'activity_type_id'    => CRM_Streetimport_GP_Config::getResponseActivityType(),
      'target_id'           => $contact_id,
      'subject'             => $this->getRTSSubject($category),
      'activity_date_time'  => $this->getDate($record),
      'campaign_id'         => $this->getCampaignID($record),
      'status_id'           => 2, // completed
      'medium_id'           => $this->getMediumID($record),
    ];
    $parent_id_field = $config->getGPCustomFieldKey('parent_activity_id');
    $parent_id = $this->getParentActivityId(
      (int) $this->getContactID($record),
      $this->getCampaignID($record),
      [
        'media'                  => ['letter_mail'],
        'exclude_activity_types' => ['Response'],
      ]
    );
    if (empty($parent_id)) {
      $this->logger->logWarning("Could not find parent letter_mail activity for contact " . $contact_id, $record);
    } else {
      $activity_params[$parent_id_field] = $parent_id;
    }

    civicrm_api3('Activity', 'create', $activity_params);
  }

  /**
   * Find the last RTS activity
   *
   * @param $contact_id
   * @param $record
   * @param null $search_frame
   * @param null $category
   *
   * @return array last RTS activity of the given TYPE or NULL
   * @throws \CiviCRM_API3_Exception
   */
  protected function findLastRTS($contact_id, $record, $search_frame = NULL, $category = NULL) {
    $activity_type_id = CRM_Streetimport_GP_Config::getResponseActivityType();

    $SUBJECT_CLAUSE = 'AND (TRUE OR activity.subject = %2)'; // probably need to have the %2 token..
    $subject = '';
    if ($category) {
      $SUBJECT_CLAUSE = 'AND activity.subject = %2';
      $subject = $this->getRTSSubject($category);
    }

    $SEARCH_FRAME_CLAUSE = '';
    if ($search_frame) {
      $SEARCH_FRAME_CLAUSE = "AND activity.activity_date_time >= " . date("YmdHis", strtotime("{$this->getDate($record)} - {$search_frame}"));
    }

    $last_rts_id = CRM_Core_DAO::singleValueQuery("
    SELECT activity.id
    FROM civicrm_activity activity
    LEFT JOIN civicrm_activity_contact ac ON ac.activity_id = activity.id
    WHERE activity.activity_type_id = %1
      {$SUBJECT_CLAUSE}
      AND ac.contact_id = %3
      {$SEARCH_FRAME_CLAUSE}
    ORDER BY activity.activity_date_time DESC
    LIMIT 1;", array(
         1 => array($activity_type_id, 'Integer'),
         2 => array($subject,          'String'),
         3 => array($contact_id,       'Integer')));

    if ($last_rts_id) {
      $this->logger->logDebug("Found RTS ({$category}): [{$last_rts_id}]", $record);
      return civicrm_api3('Activity', 'getsingle', array('id' => $last_rts_id));
    } else {
      $this->logger->logDebug("No RTS ({$category}) found.", $record);
      return NULL;
    }
  }

  /**
   * Get category
   *
   * @param $record
   *
   * @return mixed
   */
  abstract protected function getCategory($record);

  /**
   * Check if there has been a change since
   *
   * @param $contact_id
   * @param $minimum_date
   * @param $record
   *
   * @return bool
   */
  protected function addressChangeRecordedSince($contact_id, $minimum_date, $record) {
    // check if logging is enabled
    $logging = new CRM_Logging_Schema();
    if (!$logging->isEnabled()) {
      $this->logger->logDebug("Logging not enabled, cannot determine whether records have changed.", $record);
      return FALSE;
    }

    // query the logging DB
    $dsn = defined('CIVICRM_LOGGING_DSN') ? DB::parseDSN(CIVICRM_LOGGING_DSN) : DB::parseDSN(CIVICRM_DSN);
    $relevant_attributes = array('id','is_primary','street_address','supplemental_address_1','supplemental_address_2','city','postal_code','country_id','log_date');
    $attribute_list = implode(',', $relevant_attributes);
    $current_status = array();
    $query = CRM_Core_DAO::executeQuery("SELECT {$attribute_list} FROM {$dsn['database']}.log_civicrm_address WHERE contact_id={$contact_id}");
    while ($query->fetch()) {
      // generate record
      $record = array();
      $record_id = $query->id;
      foreach ($relevant_attributes as $attribute) {
        $record[$attribute] = $query->$attribute;
      }

      // process record
      if (!isset($current_status[$record_id])) {
        // this is a new address
        $current_status[$record_id] = $record;

      } else {
        // compare with the old record
        $old_record = $current_status[$record_id];
        $changed = FALSE;
        foreach ($relevant_attributes as $attribute) {
          if ($attribute == 'log_date') continue; // that doesn't matter
          if ($old_record[$attribute] != $record[$attribute]) {
            $this->logger->logDebug("Address attribute '{$attribute}' changed (on {$record['log_date']})", $record);
            $changed = TRUE;
            break;
          }
        }

        // this is the new current
        $current_status[$record_id] = $record;

        if ($changed) {
          // there is a change, check if it's in the time frame we're looking for
          if (strtotime($record['log_date']) >= strtotime($minimum_date)) {
            $this->logger->logDebug("Address change relevant (date range)", $record);
            return TRUE;
          }
        }
      }
    }

    return FALSE;
  }

  /**
   * Get the correct subject for the activity
   *
   * @param $category
   *
   * @return string
   */
  protected function getRTSSubject($category) {
    switch (strtolower($category)) {
      case 'unused':
        return 'Abgabestelle unbenutzt';
      case 'incomplete':
        return 'Anschrift ungenügend';
      case 'badcode':
        return 'falsche PLZ';
      case 'rejected':
        return 'nicht angenommen';
      case 'notretrieved':
        return 'nicht behoben';
      case 'unknown':
        return 'unbekannt';
      case 'moved':
        return 'verzogen';
      case 'deceased':
        return 'verstorben';
      case 'streetrenamed':
        return 'Straße umbenannt';
      default:
        return 'sonstiges';
      }
  }

  /**
   * Get the reference
   *
   * @param $record
   *
   * @return mixed
   */
  abstract protected function getReference($record);

  /**
   * Extract the campaign ID from reference
   *
   * @param $record
   *
   * @return int|null
   */
  protected function getCampaignID($record) {
    $reference = $this->getReference($record);
    if (preg_match($this->getReferenceFormat(), $reference, $matches)) {
      $campaign_id = ltrim($matches['campaign_id'], '0');
      return (int) $campaign_id;
    }
    else {
      $this->logger->logWarning("Couldn't parse reference '{$reference}'.", $record);
      return NULL;
    }
  }

  /**
   * Extract the contact ID from reference
   *
   * @param $record
   * @param bool $returnRaw whether to return the raw ID
   *
   * @return null|string
   */
  protected function getContactID($record, $returnRaw = FALSE) {
    $reference = $this->getReference($record);
    if (preg_match($this->getReferenceFormat(), $reference, $matches)) {
      // use identity tracker
      $contact_id = ltrim($matches['contact_id'], '0');
      if ($returnRaw) {
        return $contact_id;
      }
      return $this->resolveContactID($contact_id, $record);
    }
    else {
      $this->logger->logWarning("Couldn't parse reference '{$reference}'.", $record);
      return NULL;
    }
  }

  /**
   * Get the medium for created activities
   *
   * @param $record
   *
   * @return int
   */
  public function getMediumID($record) {
    return 5; // Letter Mail
  }

  /**
   * Regex format of column containing campaign_id and contact_id
   *
   * @return string
   */
  protected function getReferenceFormat() {
    return '#^1(?P<campaign_id>[0-9]{5})(?P<contact_id>[0-9]{9})$#';
  }

  /**
   * Process a postal return
   *
   * @param $record
   *
   * @throws \CiviCRM_API3_Exception
   */
  protected function processReturn($record) {
    $contact_id = $this->getContactID($record);
    $category = $this->getCategory($record);
    $primary_address = $this->getPrimaryAddress($contact_id, $record);

    switch (strtolower($category)) {
      case 'unused':
      case 'incomplete':
      case 'badcode':
      case 'rejected':
      case 'other':
      case 'unknown':
      case 'moved':
      case 'streetrenamed':
        // find parent activity
        $parent_activity = $this->getParentActivity(
          (int) $this->getContactID($record),
          $this->getCampaignID($record),
          [
            'media'                  => ['letter_mail'],
            'exclude_activity_types' => ['Response'],
          ]
        );
        if (empty($parent_activity)) {
          // no parent activity found, continue with last RTS activity
          $parent_activity = $this->findLastRTS($contact_id, $record);
        }
        if (!empty($parent_activity)) {
          if (!$this->addressChangeRecordedSince($contact_id, $parent_activity['activity_date_time'], $record)) {
            // address hasn't changed since letter was sent
            $this->increaseRTSCounter($primary_address, $record);
          }
        } else {
          $this->increaseRTSCounter($primary_address, $record);
        }
        $this->addRTSActvity($contact_id, $category, $record);
        break;

      case 'notretrieved':
        $this->addRTSActvity($contact_id, $category, $record);
        break;

      case 'deceased':
        $lastDeceased = $this->findLastRTS($contact_id, $record, REPETITION_FRAME_DECEASED, 'deceased');
        if ($lastDeceased) {
          // there is another 'deceased' event in the last two years
          // set the deceased date
          civicrm_api3('Contact', 'create', array(
            'id'            => $contact_id,
            // 'is_deleted'  => 1, // Marco said (27.03.2017): don't delete right away
            'deceased_date' => $this->getDate($record),
            'is_deceased'   => 1));

        }
        $this->increaseRTSCounter($primary_address, $record);
        $this->addRTSActvity($contact_id, $category, $record);
        break;
    }
  }

}
