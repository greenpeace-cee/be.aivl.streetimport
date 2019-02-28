<?php
/*-------------------------------------------------------------+
| GP StreetImporter Record Handlers                            |
| Copyright (C) 2019 Greenpeace CEE                            |
| Author: P. Figel (pfigel -at- greenpeace.org)                |
| https://www.greenpeace.at                                    |
+--------------------------------------------------------------*/

/**
 * Process results from Austrian Post's "Adress.Check" service
 *
 * @author Patrick Figel <pfigel@greenpeace.org>
 * @license AGPL-3.0
 */
class CRM_Streetimport_GP_Handler_PostAddressCheckHandler extends CRM_Streetimport_GP_Handler_GPRecordHandler {

  // AC-Ergebnis-YYYYMMDD.txt
  protected static $FILENAME_PATTERN = '#^AC\-Ergebnis\-(\d{8}).txt$#';

  protected $canProcess = NULL;

  protected $campaignId = NULL;

  protected $contactToAddress = [];

  /**
   * Check if the given handler implementation can process the record
   *
   * @param array $record
   * @param $sourceURI
   *
   * @return bool
   */
  public function canProcessRecord($record, $sourceURI) {
    if (is_null($this->canProcess)) {
      $this->canProcess = FALSE;
      if (preg_match(self::$FILENAME_PATTERN, basename($sourceURI))) {
        $this->canProcess = TRUE;
      }
    }
    return $this->canProcess;
  }


  /**
   * Process the given record
   *
   * @param array $record
   * @param $sourceURI
   *
   * @return true
   * @throws \CiviCRM_API3_Exception
   */
  public function processRecord($record, $sourceURI) {
    $required = ['contact_id', 'address_id', 'export_date', 'PERSON_STATUS'];
    foreach ($required as $field) {
      if (empty($record[$field])) {
        return $this->logger->logError("Required field {$field} is empty.", $record);
      }
    }

    $contact_id = civicrm_api3('Contact', 'findbyidentity', [
      'identifier' => $record['contact_id'],
      'identifier_type' => 'internal',
    ]);
    if ($contact_id['count'] > 0) {
      $contact_id = $contact_id['id'];
    }
    else {
      return $this->logger->logImport($record, FALSE, 'AddressCheck', "Contact '{$record['contact_id']}' not found.");
    }
    $primary_address = $this->getPrimaryAddress($contact_id);
    if (is_null($primary_address)) {
      return $this->logger->logImport($record, FALSE, 'AddressCheck', "Primary address for contact '{$record['contact_id']}' not found.");
    }

    // process deceased regardless of conflicting address changes
    if (strtolower($record['PERSON_STATUS']) == 'verstorben') {
      $params = [
        'id'            => $contact_id,
        'is_deceased'   => 1
      ];
      if (!empty($record['PERSON_Umzg_Verst_GueltigAb'])) {
        $params['deceased_date'] = $record['PERSON_Umzg_Verst_GueltigAb'];
      }
      civicrm_api3('Contact', 'create', $params);
      $this->addResponse($contact_id, $record['PERSON_STATUS'], $record);
      return $this->logger->logImport($record, TRUE, 'AddressCheck');
    }

    if ($this->addressChanged($primary_address, $record)) {
      $this->addResponse($contact_id, $record['PERSON_STATUS'], $record, TRUE);
      return $this->logger->logImport($record, FALSE, 'AddressCheck', "Contact '{$contact_id}': address has changed since export, ignoring.");
    }

    $this->addResponse($contact_id, $record['PERSON_STATUS'], $record);

    if (strtolower($record['PERSON_STATUS']) == 'adresseok') {
      $this->setRTSCounter($primary_address, 1);
      $this->logger->logDebug("RTS counter set to 1 for [{$contact_id}].", $record);
    }

    switch (strtolower($record['PERSON_STATUS'])) {
      case 'personok':
      case 'adressekorr':
      case 'umzug':
        $this->setRTSCounter($primary_address, 0);
        $this->logger->logDebug("RTS counter reset for [{$contact_id}].", $record);
        // no break
      case 'adresseok':
        // build new address
        $address_data = [
          'postal_code'            => $record['PERSON_PLZ'],
          'city'                   => $record['PERSON_Bestimmungsort'],
          'country_id'             => $record['PERSON_Land'],
          'supplemental_address_2' => '',
          'street_address'         => $this->formatStreet(
            $record['PERSON_Strasse'],
            $record['PERSON_HNR'],
            $record['PERSON_TNR']
          ),
        ];
        if ($this->addressChanged($address_data, $record)) {
          // address changed, update it
          $this->setProvince($address_data);
          $this->createOrUpdateAddress($contact_id, $address_data, $record);
        }
        break;

      case 'adressenichtok':
      case 'mehrdeutigehnr':
      case 'mehrdeutigeadresse':
      case 'nichtueberpruefbar':
      case 'verstorben':
      case 'unzustellbar':
        // no-op, but don't trigger error
        break;

      default:
        $this->logger->abort("Unknown status '{$record['PERSON_STATUS']}'.", $record);
        break;
    }
    $this->logger->logImport($record, TRUE, 'AddressCheck');
  }

  /**
   * Get the primary address for $record
   *
   * @param $contact_id
   *
   * @return mixed|null
   * @throws \CiviCRM_API3_Exception
   */
  protected function getPrimaryAddress($contact_id) {
    $config      = CRM_Streetimport_Config::singleton();
    $rts_counter = $config->getGPCustomFieldKey('rts_counter');
    $addresses = civicrm_api3('Address', 'get', [
      'is_primary'   => 1,
      'contact_id'   => $contact_id,
      'return'       => "{$rts_counter},contact_id,id,street_address,city,supplemental_address_2,state_province_id,postal_code,country_id",
      'option.limit' => 1
    ]);
    if ($addresses['count'] == 1) {
      $address = reset($addresses['values']);
      $this->contactToAddress[$contact_id] = $address['id'];
      return $address;
    }
    else {
      return NULL;
    }
  }


  /**
   * Set the RTS counter for address $primary to zero
   *
   * @param $primary
   * @param $counter int
   *
   * @throws \CiviCRM_API3_Exception
   */
  protected function setRTSCounter($primary, $counter) {
    $config      = CRM_Streetimport_Config::singleton();
    $rts_counter = $config->getGPCustomFieldKey('rts_counter');
    civicrm_api3('Address', 'create', [
      'id'         => $primary['id'],
      $rts_counter => $counter
    ]);
  }

  /**
   * Was the address changed since it was exported?
   *
   * @param $current_address primary civi address
   * @param $exported_address exported address from CSV
   *
   * @return bool
   */
  protected function addressChanged($current_address, $exported_address) {
    $compare_map = [
      'street_address' => 'street_address',
      'postal_code' => 'zip',
      'city' => 'city',
      'supplemental_address_2' => 'pob',
    ];
    foreach ($compare_map as $civi_field => $export_field) {
      if ($current_address[$civi_field] != $exported_address[$export_field]) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Add Address Check result response
   *
   * @param $contact_id
   * @param $response
   * @param $record
   * @param bool $cancelled
   *
   * @throws \CiviCRM_API3_Exception
   */
  protected function addResponse($contact_id, $response, $record, $cancelled = FALSE) {
    $status_id = CRM_Core_PseudoConstant::getKey(
      'CRM_Activity_BAO_Activity',
      'status_id',
      $cancelled ? 'Cancelled' : 'Completed'
    );
    $config = CRM_Streetimport_Config::singleton();
    $activity_params = [
      'activity_type_id'    => CRM_Streetimport_GP_Config::getResponseActivityType(),
      'target_id'           => $contact_id,
      'subject'             => 'Adress.Check: ' . $response,
      'activity_date_time'  => date('YmdHis'),
      'campaign_id'         => $this->getCampaignID($record),
      'status_id'           => $status_id,
    ];
    $parent_id_field = $config->getGPCustomFieldKey('parent_activity_id');
    $parent_id = $this->getParentActivityId(
      (int) $contact_id,
      $this->getCampaignID($record)
    );
    if (empty($parent_id)) {
      $this->logger->logWarning("Could not find parent activity for contact " . $contact_id, $record);
    }
    else {
      $activity_params[$parent_id_field] = $parent_id;
    }
    civicrm_api3('Activity', 'create', $activity_params);
  }

  /**
   * Delete address $primary
   *
   * @param $primary
   *
   * @throws \CiviCRM_API3_Exception
   */
  protected function deleteAddress($primary) {
    civicrm_api3('Address', 'delete', [
      'id' => $primary['id'],
    ]);
  }

  /**
   * Format street name and number according to GP convention
   *
   * @param $street_name
   * @param $street_number
   * @param $door_number
   *
   * @return string
   */
  protected function formatStreet($street_name, $street_number, $door_number) {
    $street_number = trim(str_replace(' Stg. ', '/', $street_number), ' /');
    $full_number = trim($street_number . '/' . $door_number, ' /');
    return trim($street_name . ' ' . $full_number);
  }

  /**
   * Get Address Check campaign
   *
   * @return int
   * @throws \CiviCRM_API3_Exception
   */
  protected function getCampaignID() {
    if (is_null($this->campaignId)) {
      $this->campaignId = civicrm_api3('Campaign', 'getvalue', [
        'return' => 'id',
        'name'   => 'adresscheck',
      ]);
    }
    return $this->campaignId;
  }

  protected function getAddressId($contact_id, $record) {
    if (array_key_exists($contact_id, $this->contactToAddress)) {
      return $this->contactToAddress[$contact_id];
    }
    return NULL;
  }

}
