<?php
/**
 * This class can process records of type 'welcome call'
 *
 * @author Björn Endres (SYSTOPIA) <endres@systopia.de>
 * @license AGPL-3.0
 */
class CRM_Streetimport_WelcomeCallRecordHandler extends CRM_Streetimport_StreetimportRecordHandler {

  /** 
   * Check if the given handler implementation can process the record
   *
   * @param $record  an array of key=>value pairs
   * @return true or false
   */
  public function canProcessRecord($record) {
    return isset($record['Loading type']) && $record['Loading type'] == 2;
  }

  /** 
   * process the given record
   *
   * @param $record  an array of key=>value pairs
   * @return true
   * @throws exception if failed
   */
  public function processRecord($record) {
    $this->logger->logDebug("Processing 'WelcomeCall' record #{$record['__id']}...");

    $this->logger->logImport($record['__id'], true, 'WelcomeCall');
    error_log("processing welcome call");
    // TODO: implement
  }

}