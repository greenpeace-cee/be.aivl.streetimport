<?php
/*-------------------------------------------------------------+
| Greenpeace Poland StreetImporter Record Handlers             |
| Copyright (C) 2018 Greenpeace CEE                            |
| Author: P. Figel (pfigel@greenpeace.org)                     |
|         M. McAndrew (michaelmcandrew@thirdsectordesign.org)  |
|         B. Endres (endres -at- systopia.de)                  |
| http://www.systopia.de/                                      |
+--------------------------------------------------------------*/

/**
 * Class following Singleton pattern for specific extension configuration
 */
class CRM_Streetimport_GPPL_Config extends CRM_Streetimport_Config {
  private $_activeMembershipStatuses;
  private $_cancelledMembershipStatus;
  private $_pausedMembershipStatus;

  public function __construct() {
    CRM_Streetimport_Config::__construct();
  }

  /**
   * get a list (id => name) of the relevant employees
   *
   * @return array
   * @throws \CiviCRM_API3_Exception
   */
  public function getEmployeeList() {
    // get user list
    $employees = parent::getEmployeeList();

    // currently, everybody with an external ID starting with 'USER-' is a user
    //  so we want to add those
    $result = civicrm_api3('Contact', 'get', array(
      'return'              => 'display_name,id',
      'external_identifier' => array('LIKE' => "USER-%"),
      'options'             => array('limit' => 0),
    ));
    foreach ($result['values'] as $contact_id => $contact) {
      $employees[$contact['id']] = $contact['display_name'];
    }

    return $employees;
  }

  /**
   * get the default set of handlers
   *
   * @return an array of handler instances
   */
  public function getHandlers($logger) {
    return array(
      new CRM_Streetimport_GPPL_Handler_BankAuthorizationHandler($logger),
      new CRM_Streetimport_GPPL_Handler_TMResponseHandler($logger),
    );
  }

  public function getActiveMembershipStatuses() {
    if (is_null($this->_activeMembershipStatuses)) {
      $result = civicrm_api3('MembershipStatus', 'get', array(
        'is_current_member' => 1,
        'return'            => 'id'));
      $this->_activeMembershipStatuses = array();
      foreach ($result['values'] as $status) {
        $this->_activeMembershipStatuses[] = $status['id'];
      }
    }
    return $this->_activeMembershipStatuses;
  }

  public function getCancelledMembershipStatus() {
    if (is_null($this->_cancelledMembershipStatus)) {
      $this->_cancelledMembershipStatus = civicrm_api3('MembershipStatus', 'getvalue', [
        'return' => 'id',
        'name' => 'Cancelled',
      ]);
    }
    return $this->_cancelledMembershipStatus;
  }

  public function getPausedMembershipStatus() {
    if (is_null($this->_pausedMembershipStatus)) {
      $this->_pausedMembershipStatus = civicrm_api3('MembershipStatus', 'getvalue', [
        'return' => 'id',
        'name' => 'Paused',
      ]);
    }
    return $this->_pausedMembershipStatus;
  }

  /**
   * get the SEPA creditor ID to be used for all mandates
   *
   * @return int
   */
  public function getCreditorID() {
    $default_creditor = CRM_Sepa_Logic_Settings::defaultCreditor();
    if (!empty($default_creditor->id)) {
      return $default_creditor->id;
    }
    else {
      return 1;
    }
  }

  /**
   * Should processing of the whole file stop if no handler was found for a
   * line?
   *
   * @return bool
   */
  public function stopProcessingIfNoHanderFound() {
    return TRUE;
  }

  /**
   * Look up custom fields and return full field data
   */
  public function getGPCustomField($field_name, $group_id = NULL) {
    if (!isset($this->gp_custom_fields[$field_name]) || !is_array($this->gp_custom_fields[$field_name])) {
      // load custom field data
      try {
        $params = [ 'name' => $field_name ];
        if (!is_null($group_id)) {
          $params['custom_group_id'] = $group_id;
        }
        $this->gp_custom_fields[$field_name] = civicrm_api3('CustomField', 'getsingle', $params);
      } catch (Exception $e) {
        $this->gp_custom_fields[$field_name] = array(
          'is_error' => 1,
          'error_msg' => $e->getMessage());
      }
    }

    return $this->gp_custom_fields[$field_name];
  }

  /**
   * Look up custom fields and return full field data
   */
  public function getGPCustomFieldKey($field_name, $group_id = NULL) {
    $custom_field = $this->getGPCustomField($field_name, $group_id);
    return "custom_{$custom_field['id']}";
  }

}
