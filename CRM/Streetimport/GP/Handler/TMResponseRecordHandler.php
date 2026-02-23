<?php

/**
 * GP TM Response Handler (for DD)
 *
 * @author Patrick Figel <pfigel@greenpeace.org>
 * @license AGPL-3.0
 */
class CRM_Streetimport_GP_Handler_TMResponseRecordHandler extends CRM_Streetimport_GP_Handler_TMRecordHandler {

  /**
   * Check if the given handler implementation can process the record
   *
   * @param $record  an array of key=>value pairs
   * @return true or false
   */
  public function canProcessRecord($record, $sourceURI) {
    $parsedFileName = $this->parseTmFile($sourceURI);
    return ($parsedFileName && strtolower($parsedFileName['file_type']) == 'responses');
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
    $config = CRM_Streetimport_Config::singleton();
    $this->file_name_data = $this->parseTmFile($sourceURI);

    // ############# CHECKS #############
    $required = ['contact_id', 'responsedatum', 'response'];
    foreach ($required as $field) {
      if (empty($record[$field])) {
        $this->logger->logImport($record, FALSE, $config->translate('TM Contact'));
        return $this->logger->logError("Missing value for field {$field}.", $record);
      }
    }
    $date = DateTime::createFromFormat('d.m.Y H:i', $record['responsedatum']);
    if (!$date || $date->format('d.m.Y H:i') != $record['responsedatum']) {
      $this->logger->logImport($record, FALSE, $config->translate('TM Contact'));
      return $this->logger->logError("Invalid date value {$record['responsedatum']} in field 'responsedatum'.", $record);
    }
    // set $record['id'] to the actual contact ID (without the check digit)
    $record['id'] = substr($record['contact_id'], 0, -1);
    $contact_id = $this->getContactID($record);
    if (empty($contact_id)) {
      $this->logger->logImport($record, FALSE, $config->translate('TM Contact'));
      if ($this->isAnonymized($record)) {
        return $this->logger->logWarning("Contact [{$record['id']}] was anonymized, skipping.", $record);
      } else {
        return $this->logger->logError("Contact [{$record['id']}] couldn't be identified.", $record);
      }
    }

    $project_type = strtolower(substr($this->file_name_data['project1'], 0, 3));
    if ($project_type != TM_PROJECT_TYPE_CONVERSION) {
      $this->logger->logImport($record, FALSE, $config->translate('TM Contact'));
      return $this->logger->logError("Only conversion projects are supported", $record);
    }

    // TODO: remove fallback
    $parent_id = $this->getActivityId($record);
    if (empty($parent_id)) {
      $this->logger->logImport($record, FALSE, $config->translate('TM Contact'));
      return $this->logger->logError('Could not find parent activity', $record);
    }

    $existingResponseCount = civicrm_api3('Activity', 'getcount', [
      $config->getGPCustomFieldKey('parent_activity_id')
                          => $parent_id,
      'activity_type_id'  => 'Response',
      'target_contact_id' => $contact_id
    ]);
    if ($existingResponseCount > 0) {
      $this->logger->logImport($record, FALSE, $config->translate('TM Contact'));
      return $this->logger->logError("Found existing response for parent activity {$parent_id}", $record);
    }

    $membership = NULL;
    if (!empty($record['formular_nr'])) {
      $memberships = civicrm_api3('Membership', 'get', [
        $config->getGPCustomFieldKey('membership_contract') => $record['formular_nr'],
      ]);
      if ($memberships['count'] != 1) {
        $this->logger->logImport($record, FALSE, $config->translate('TM Contact'));
        return $this->logger->logError("Couldn't find membership with contract number {$record['formular_nr']}", $record);
      }

      $membership = reset($memberships['values']);
      if ($membership['contact_id'] != $contact_id) {
        $this->logger->logImport($record, FALSE, $config->translate('TM Contact'));
        return $this->logger->logError("Expected contract number {$record['formular_nr']} to belong to {$contact_id}, found at contact {$membership['contact_id']}.", $record);
      }
    }

    preg_match('/^(?P<code>\d\d) (?P<text>.*)$/', $record['response'], $response);

    if (empty($response['code']) || empty($response['text'])) {
      $this->logger->logImport($record, FALSE, $config->translate('TM Contact'));
      return $this->logger->logError("Invalid response value '{$record['response']}'.", $record);
    }

    if ((int) $response['code'] == 1 && empty($membership)) {
      $this->logger->logImport($record, FALSE, $config->translate('TM Contact'));
      return $this->logger->logError("Expected to find membership in column formular_nr for response '{$record['response']}'.", $record);
    }

    // HACK: convert "27 Nicht kontaktieren" to "41 Anrufsperre Kontakt"
    if ((int) $response['code'] == TM_KONTAKT_RESPONSE_NICHT_KONTAKTIEREN) {
      $record['response'] = TM_KONTAKT_RESPONSE_KONTAKT_ANRUFSPERRE . ' Anrufsperre Kontakt';
      $response['code'] = TM_KONTAKT_RESPONSE_KONTAKT_ANRUFSPERRE;
      $response['text'] = 'Anrufsperre Kontakt';
    }

    // ############# PROCESS #############
    switch ((int) $response['code']) {
      case TM_KONTAKT_RESPONSE_ZUSAGE_FOERDER:
        $this->setContractActivityParent($membership['id'], $parent_id);
        break;

      case TM_KONTAKT_RESPONSE_KONTAKT_LOESCHEN:
        // contact wants to be erased from GP database
        $result = $this->disableContact($contact_id, 'erase', $record);
        break;

      case TM_KONTAKT_RESPONSE_NICHT_KONTAKTIEREN:
        $this->disableContact($contact_id, 'deactivate', $record);
        break;

      case TM_KONTAKT_RESPONSE_KONTAKT_ANRUFSPERRE:
        civicrm_api3('Contact', 'create', [
          'id'           => $contact_id,
          'do_not_phone' => 1
        ]);
        break;

      case TM_KONTAKT_RESPONSE_KONTAKT_VERSTORBEN:
        // contact should be disabled
        $result = $this->disableContact($contact_id, 'deceased', $record);
        break;

      case TM_KONTAKT_RESPONSE_KONTAKT_KEIN_ANSCHLUSS:
      case TM_KONTAKT_RESPONSE_KONTAKT_NICHT_ERREICHT:
      case TM_KONTAKT_RESPONSE_KONTAKT_NICHT_ANGEGRIFFEN:
      case TM_KONTAKT_RESPONSE_GELEGENTLICHER_SPENDER:
        // no-op
        break;

      default:
        $this->logger->logImport($record, FALSE, $config->translate('TM Contact'));
        return $this->logger->logError("Unknown response '{$record['response']}'.", $record);
    }

    // GENERATE RESPONSE ACTIVITY
    $this->createResponseActivity(
      $contact_id,
      $this->assembleResponseSubject($response['code'], $response['text']),
      $record
    );

    $this->logger->logImport($record, TRUE, $config->translate('TM Contact'));
  }

  /**
   * Extracts the specific activity date for this line
   */
  protected function getDate($record) {
    if (!empty($record['responsedatum'])) {
      return date('YmdHis', strtotime($record['responsedatum']));
    } else {
      return parent::getDate($record);
    }
  }

}
