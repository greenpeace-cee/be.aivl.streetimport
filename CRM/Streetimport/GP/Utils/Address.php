<?php

use Civi\Api4;

/**
 * Class provide Address helper methods
 */
class CRM_Streetimport_GP_Utils_Address {

  /**
   * Austria iso code
   *
   * @var string
   */
  const AUSTRIA_ISO_CODE = 'AT';

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
      $queryResult = Api4\PostcodeAT::get(FALSE)
        ->selectRowCount()
        ->addWhere('plznr', '=', $postalCode)
        ->addClause('OR',
          ['gemnam38', '=', $city],
          ['ortnam', '=', $city],
          ['zustort', '=', $city]
        )
        ->addWhere('stroffi', '=', $street)
        ->execute();

      return $queryResult->rowCount > 0;
    } catch (CiviCRM_API3_Exception $e) {
      return FALSE;
    }
  }

}
