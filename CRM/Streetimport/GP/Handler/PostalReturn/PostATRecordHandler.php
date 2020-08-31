<?php

/**
 * Processes postal returns from post.at's managed return service
 *
 * @author Patrick Figel <pfigel@greenpeace.org>
 * @license AGPL-3.0
 */
class CRM_Streetimport_GP_Handler_PostalReturn_PostATRecordHandler extends CRM_Streetimport_GP_Handler_PostalReturn_Base {

  /**
   * File name in the format RET_KUNDE_MAILING_JJJJMMTT.csv
   *
   * @var string
   */
  protected static $FILENAME_PATTERN = '#^RET_(?P<org>[a-zA-Z\-]+)_(?P<mailing>[a-zA-Z0-9\-]+)_(?P<date>\d{8})?[.]csv$#';

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
      $this->logger->logError("Empty RTS reference", $record);
    }
    if (empty($this->getCategory($record))) {
      $this->logger->logError("Unknown postal return category '{$record['Grund']}'", $record);
      return $this->logger->logImport($record, FALSE, 'RTS', "Couldn't identify RTS category");
    }
    if (empty($this->getCampaignID($record))) {
      $this->logger->logError("Invalid RTS campaign_id in reference '{$reference}'", $record);
      return $this->logger->logImport($record, FALSE, 'RTS', "Couldn't identify campaign for reference '{$reference}'");
    }
    if (empty($this->getContactID($record))) {
      // create an import error unless this is a deleted contact
      if (!$this->isDeletedContact($this->getContactID($record, TRUE))) {
        $this->logger->logError("Invalid RTS contact_id in reference '{$reference}'", $record);
      }
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
    $codeCategoryMap = [
      '01' => 'unknown',
      '02' => 'rejected',
      '03' => 'moved',
      '04' => 'notretrieved',
      '05' => 'incomplete',
      '06' => 'deceased',
      '07' => 'other',
      '08' => 'badcode',
      '09' => 'unused',
    ];
    if (!array_key_exists($record['Grund'], $codeCategoryMap)) {
      return NULL;
    }
    return $codeCategoryMap[$record['Grund']];
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
    return trim(CRM_Utils_Array::value('Adnr', $record));
  }

  /**
   * @param $record
   *
   * @return string
   */
  public function getDate($record) {
    return $record['EinspDatum'] . '000000' ?? parent::getDate($record);
  }

}
