<?php
/*-------------------------------------------------------------+
| GP StreetImporter Record Handlers                            |
| Copyright (C) 2017 SYSTOPIA                                  |
| Author: B. Endres (endres -at- systopia.de)                  |
| http://www.systopia.de/                                      |
+--------------------------------------------------------------*/

/**
 * Processes legacy postal return barcode lists (GP-331)
 *
 * @author Björn Endres (SYSTOPIA) <endres@systopia.de>
 * @license AGPL-3.0
 */
class CRM_Streetimport_GP_Handler_PostalReturn_LegacyRecordHandler extends CRM_Streetimport_GP_Handler_PostalReturn_Base {

  /** file name / reference patterns as defined in GP-331 */
  protected static $FILENAME_PATTERN       = '#^RTS_(?P<category>[a-zA-Z\-]+)(_[0-9]*)?[.][a-zA-Z]+$#';

  /** stores the parsed file name */
  protected $file_name_data = 'not parsed';

  /**
   * Check if the given handler implementation can process the record
   *
   * @param $record  an array of key=>value pairs
   * @param $sourceURI
   *
   * @return true or false
   */
  public function canProcessRecord($record, $sourceURI) {
    if ($this->file_name_data === 'not parsed') {
      $this->file_name_data = $this->parseRetourFile($sourceURI);
    }
    return $this->file_name_data != NULL;
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
    $reference = $this->getReference($record);
    if (empty($reference)) {
      return $this->logger->logImport($record, FALSE, 'RTS', "Empty reference '{$reference}'");
    }
    if (empty($this->getCampaignID($record))) {
      return $this->logger->logImport($record, FALSE, 'RTS', "Couldn't identify campaign for reference '{$reference}'");
    }
    if (empty($this->getContactID($record))) {
      return $this->logger->logImport($record, FALSE, 'RTS', "Couldn't identify contact for reference '{$reference}'");
    }

    $this->processReturn($record);

    $this->logger->logImport($record, TRUE, 'RTS');
  }

  /**
   * Get category
   *
   * @param $record
   *
   * @return mixed
   */
  protected function getCategory($record) {
    return $this->file_name_data['category'];
  }

  /**
   * Will try to parse the given name and extract the parameters outlined in TM_PATTERN
   *
   * @param $sourceID
   *
   * @return NULL if not matched, data else
   */
  protected function parseRetourFile($sourceID) {
    if (preg_match(self::$FILENAME_PATTERN, basename($sourceID), $matches)) {
      return $matches;
    } else {
      return NULL;
    }
  }

  /**
   * Get the reference
   *
   * @param $record
   *
   * @return mixed
   */
  protected function getReference($record) {
    return trim(CRM_Utils_Array::value('scanned_code', $record));
  }

}
