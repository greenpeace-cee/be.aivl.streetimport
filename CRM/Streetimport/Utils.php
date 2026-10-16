<?php

use Civi\Api4;

/**
 * Class with extension specific util functions
 *
 * @author Erik Hommel (CiviCooP) <erik.hommel@civicoop.org>
 * @date 30 April 2015
 * @license AGPL-3.0
 */

class CRM_Streetimport_Utils {

  /**
   * Function to get activity type with name
   *
   * @param string $activityTypeName
   * @return array|bool
   * @access public
   * @static
   */
  public static function getActivityTypeWithName($activityTypeName) {
    $optionGroup = self::getOptionGroupWithName('activity_type');
    $activityTypeOptionGroupId = $optionGroup['id'];
    $params = array(
      'option_group_id' => $activityTypeOptionGroupId,
      'name' => $activityTypeName);
    try {
      $activityType = civicrm_api3('OptionValue', 'Getsingle', $params);
      return $activityType;
    } catch (CiviCRM_API3_Exception $ex) {
      return FALSE;
    }
  }

  /**
   * Function to get activity status id with name
   *
   * @param string $activityStatusName
   * @return int|bool
   * @access public
   * @static
   */
  public static function getActivityStatusIdWithName($activityStatusName) {
    $optionGroup = self::getOptionGroupWithName('activity_status');
    $activityStatusOptionGroupId = $optionGroup['id'];
    $params = array(
      'option_group_id' => $activityStatusOptionGroupId,
      'name' => $activityStatusName);
    try {
      $activityStatus = civicrm_api3('OptionValue', 'Getsingle', $params);
      return $activityStatus['value'];
    } catch (CiviCRM_API3_Exception $ex) {
      return FALSE;
    }
  }

  /**
   * Function to get the group with a name
   *
   * @param string $groupName
   * @return array|bool
   * @access public
   * @static
   */
  public static function getGroupWithName($groupName) {
    try {
      $group = civicrm_api3('Group', 'Getsingle', array('name' => $groupName));
      return $group;
    } catch (CiviCRM_API3_Exception $ex) {
      return FALSE;
    }
  }

  /**
   * Function to get the option group id
   *
   * @param string $optionGroupName
   * @return int $optionGroupId
   * @access public
   * @static
   */
  public static function getOptionGroupWithName($optionGroupName) {
    $params = array(
      'name' => $optionGroupName,
      'is_active' => 1);
    try {
      $optionGroup = civicrm_api3('OptionGroup', 'Getsingle', $params);
      return $optionGroup;
    } catch (CiviCRM_API3_Exception $ex) {
      return array();
    }
  }

  /**
   * Function to get the ID of a financial type by name
   *
   * @param string $financialTypeName
   * @return int $financialTypeID
   * @access public
   * @static
   */
  public static function getFinancialTypeID($financialTypeName) {
    return (int) Api4\FinancialType::get(FALSE)
      ->addWhere('name', '=', $financialTypeName)
      ->addSelect('id')
      ->setLimit(1)
      ->execute()
      ->first()['id'];
  }

  /**
   * Function get country data with an iso code
   *
   * @param $isoCode
   * @return array
   */
  public static function getCountryByIso($isoCode) {
    $country = array();
    if (empty($isoCode)) {
      return $country;
    }
    $query = 'SELECT * FROM civicrm_country WHERE iso_code = %1';
    $params = array(1 => array($isoCode, 'String'));
    $dao = CRM_Core_DAO::executeQuery($query, $params);
    if ($dao->fetch()) {
      $country['country_id'] = $dao->id;
      $country['name'] = $dao->name;
      $country['iso_code'] = $dao->iso_code;
    }
    return $country;
  }

  /**
   * Method to get list of active option values for select lists
   *
   * @param string $optionGroupName
   * @return array
   * @throws Exception when no option group found
   * @access public
   * @static
   */
  public static function getOptionGroupList($optionGroupName) {
    $valueList = array();
    $optionGroupParams = array(
      'name' => $optionGroupName,
      'return' => 'id');
    try {
      $optionGroupId = civicrm_api3('OptionGroup', 'Getvalue', $optionGroupParams);
      $optionValueParams = array(
        'option_group_id' => $optionGroupId,
        'is_active' => 1,
        'options' => array('limit' => 99999));
      $optionValues = civicrm_api3('OptionValue', 'Get', $optionValueParams);
      foreach ($optionValues['values'] as $optionValue) {
        $valueList[$optionValue['value']] = $optionValue['label'];
      }
      $valueList[0] = ts('- select -');
      asort($valueList);
    } catch (CiviCRM_API3_Exception $ex) {
      $config = CRM_Streetimport_Config::singleton();
      throw new Exception($config->translate('Could not find an option group with name').' '.$optionGroupName
        .' ,'.$config->translate('contact your system administrator').' .'
        .$config->translate('Error from API OptionGroup Getvalue').': '.$ex->getMessage());
    }
    return $valueList;
  }

  /**
   * Method to set list of date formats for import files
   *
   * @access public
   * @static
   * @return array
   */
  public static function getDateFormatList() {
    return array('dd-mm-jjjj', 'dd/mm/jjjj', 'dd-mm-jj', 'dd/mm/jj', 'jjjj-mm-dd', 'jjjj/mm/dd', 'jj-mm-dd','jj/mm/dd',
      'mm-dd-jjjj', 'mm/dd/jjjj', 'mm-dd-jj', 'mm/dd/jj');
  }

  /**
   * Method to format the CSV import date if new DateTime has thrown error
   *
   * @param $inDate
   * @return string
   */
  public static function formatCsvDate($inDate) {
    if (empty($inDate)) {
      return $inDate;
    }
    $config = CRM_Streetimport_Config::singleton();
    $inDay = null;
    $inMonth = null;
    $inYear = null;
    switch ($config->getCsvDateFormat()) {
      case 0:
        $dateParts = explode("-", $inDate);
        $inDay = $dateParts[0];
        $inMonth = $dateParts[1];
        $inYear = $dateParts[2];
        break;
      case 1:
        $dateParts = explode("/", $inDate);
        if (isset($dateParts[1]) && isset($dateParts[2])) {
          $inDay = $dateParts[0];
          $inMonth = $dateParts[1];
          $inYear = $dateParts[2];
        }
        break;
      case 2:
        $dateParts = explode("-", $inDate);
        $inDay = $dateParts[0];
        $inMonth = $dateParts[1];
        $inYear = $dateParts[2];
        break;
      case 3:
        $dateParts = explode("/", $inDate);
        $inDay = $dateParts[0];
        $inMonth = $dateParts[1];
        $inYear = $dateParts[2];
        break;
      case 4:
        $dateParts = explode("-", $inDate);
        $inDay = $dateParts[2];
        $inMonth = $dateParts[1];
        $inYear = $dateParts[0];
        break;
      case 5:
        $dateParts = explode("/", $inDate);
        $inDay = $dateParts[2];
        $inMonth = $dateParts[1];
        $inYear = $dateParts[0];
        break;
      case 6:
        $dateParts = explode("-", $inDate);
        $inDay = $dateParts[2];
        $inMonth = $dateParts[1];
        $inYear = $dateParts[0];
        break;
      case 7:
        $dateParts = explode("/", $inDate);
        $inDay = $dateParts[2];
        $inMonth = $dateParts[1];
        $inYear = $dateParts[0];
        break;
      case 8:
        $dateParts = explode("-", $inDate);
        $inDay = $dateParts[1];
        $inMonth = $dateParts[0];
        $inYear = $dateParts[2];
        break;
      case 9:
        $dateParts = explode("/", $inDate);
        $inDay = $dateParts[1];
        $inMonth = $dateParts[0];
        $inYear = $dateParts[2];
        break;
      case 10:
        $dateParts = explode("-", $inDate);
        $inDay = $dateParts[1];
        $inMonth = $dateParts[0];
        $inYear = $dateParts[2];
        break;
      case 11:
        $dateParts = explode("/", $inDate);
        $inDay = $dateParts[1];
        $inMonth = $dateParts[0];
        $inYear = $dateParts[2];
        break;
      default:
        return $inDate;
      break;
    }
    return $inDay.'-'.$inMonth.'-'.$inYear;
  }

  /**
   * render a given template with the given variables
   */
  public static function renderTemplate($template_path, $vars) {
    $config = CRM_Streetimport_Config::singleton();
    $smarty = CRM_Core_Smarty::singleton();

    // first backup original variables, since smarty instance is a singleton
    $oldVars = $smarty->getTemplateVars();
    $backupFrame = array();
    foreach ($vars as $key => $value) {
      $key = str_replace(' ', '_', $key);
      $backupFrame[$key] = isset($oldVars[$key]) ? $oldVars[$key] : NULL;
    }

    // then assign new variables
    foreach ($vars as $key => $value) {
      $key = str_replace(' ', '_', $key);
      $smarty->assign($key, $value);
    }

    // adjust template path
    $result =  $smarty->fetch($config->getDomain() . '/' . $template_path);

    // reset smarty variables
    foreach ($backupFrame as $key => $value) {
      $key = str_replace(' ', '_', $key);
      $smarty->assign($key, $value);
    }

    return $result;
  }


  /**
   * Create an activity with the given data
   *
   * @return activity BAO object
   */
  public static function createActivity($data, $record, $assigned_contact_ids=NULL) {
    $activity = CRM_Activity_BAO_Activity::create($data);
    if (empty($activity->id)) {
      return NULL;
    }

    // create assignments
    if (!empty($assigned_contact_ids) && is_array($assigned_contact_ids)) {
      foreach ($assigned_contact_ids as $contact_id) {
        $assignment_parameters = array(
          'activity_id'    => $activity->id,
          'contact_id'     => $contact_id,
          'record_type_id' => 1  // ASSIGNEE
        );
        CRM_Activity_BAO_ActivityContact::create($assignment_parameters);
      }
    }

    // set custom fields (if any)
    $custom_data = array();
    foreach ($data as $key => $value) {
      if (substr($key, 0, 7) == 'custom_') {
        $custom_data[$key] = $value;
      }
    }
    if ($activity->id && !empty($custom_data)) {
      $custom_data['id'] = $activity->id;
      civicrm_api3('Activity', 'create', $custom_data);
    }

    return $activity;
  }

}
