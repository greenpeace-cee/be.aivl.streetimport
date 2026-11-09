<?php

require_once 'streetimport.civix.php';

/**
 * Implements hook_civicrm_config().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_config
 */
function streetimport_civicrm_config(&$config) {
  _streetimport_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_install
 */
function streetimport_civicrm_install() {
  /*
   * only install if CiviSepa and CiviBanking Extension are installed
   */
  $installedExtensionsResult = civicrm_api3('Extension', 'get', ['options' => ['limit' => 0]]);
  foreach($installedExtensionsResult['values'] as $value){
      if ($value['status'] == 'installed') {
          $installedExtensions[] = $value['key'];
      }
  }
  $requiredExtensions = array(
      'org.project60.sepa' => 'SEPA direct debit (org.project60.sepa)',
      'org.project60.banking' => 'CiviBanking (org.project60.banking)',
  );
  $missingExtensions = array_diff(array_keys($requiredExtensions), $installedExtensions);
  if (count($missingExtensions) == 1) {
    $missingExtensionsText = current($missingExtensions);
    throw new CRM_Core_Exception("The Street Recruitment Import extension requires the following extension: '$missingExtensionsText' but it is not currently installed. Please install it before continuing.");
  }
  elseif (count($missingExtensions) > 1) {
    $missingExtensionsText = implode("', '", $missingExtensions);
    throw new CRM_Core_Exception("The Street Recruitment Import extension requires the following extensions: '$missingExtensionsText' but they are not currently installed. Please install them before continuing.");
  }
  _streetimport_civix_civicrm_install();
  CRM_Streetimport_Config::singleton('install');
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_enable
 */
function streetimport_civicrm_enable() {
  // check if extension de.systopia.identitytracker is installed as it is required
  // TODO Merge this check with above.
  $identityTrackerActive = FALSE;
  try {
    $extensions = civicrm_api3('Extension', 'get', ['options' => ['limit' => 0]]);
    foreach ($extensions['values'] as $extension) {
      if ($extension['key'] == "de.systopia.identitytracker" && $extension['status'] == "installed") {
        $identityTrackerActive = TRUE;

      }
    }
  } catch (CiviCRM_API3_Exception $ex) {
    throw new Exception('Could not get the extensions for a dependency check in '.__METHOD__
      .', contact your system administrator. Error from API Extension get: '.$ex->getMessage());
  }
  if (!$identityTrackerActive) {
    throw new Exception('Could not find an active installation of the required extension de.systopia.indentitytracker, install first and then try to install be.aivl.streetimport again');
  }
  _streetimport_civix_civicrm_enable();
}
