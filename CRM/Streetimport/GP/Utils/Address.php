<?php

/**
 * Class provide Address helper methods
 */
class CRM_Streetimport_GP_Utils_Address {

  /**
   * Checks if address is real by 'de.systopia.postcodeat' extension functionality
   * If 'de.systopia.postcodeat' extension does not install that method returns false
   *
   * @param $city
   * @param $postalCode
   * @param $street
   *
   * @return bool
   */
  public static function isRealAddress($city, $postalCode, $street) {
    if (empty($city) || empty($postalCode) || empty($street)) {
      return FALSE;
    }

    try {
      $address = civicrm_api3('PostcodeAT', 'get', [
        'sequential' => 1,
        'plznr' => $postalCode,
        'ortnam' => $city,
        'stroffi' => $street,
        'return' => 'id',
      ]);

      $ortnamSearchResult = !empty($address['values']);
    } catch (CiviCRM_API3_Exception $e) {
      $ortnamSearchResult = FALSE;
    }

    try {
      $address = civicrm_api3('PostcodeAT', 'get', [
        'sequential' => 1,
        'plznr' => $postalCode,
        'gemnam38' => $city,
        'stroffi' => $street,
        'return' => 'id',
      ]);

      $gemnam38SearchResult = !empty($address['values']);
    } catch (CiviCRM_API3_Exception $e) {
      $gemnam38SearchResult = FALSE;
    }

    return $gemnam38SearchResult || $ortnamSearchResult;
  }

}
