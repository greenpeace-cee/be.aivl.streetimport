<?php

/**
 * Class provide Number helper methods
 */
class CRM_Streetimport_GP_Utils_Number {

  /**
   * Parses German number format
   * Example:
   *  1.234,56(string) => 1234.56(float)
   *  234,56(string) => 234.56(float)
   *  234(string) => 234(float)
   *  1.000(string) => 1000(float)
   *
   * If is not German number format it returns not parsed number
   *
   * @param $stringNumber
   *
   * @return float
   */
  public static function parseGermanFormatNumber($stringNumber) {
    if (empty($stringNumber)) {
      return $stringNumber;
    }

    $stringNumber = str_replace(' ', '', $stringNumber);

    if (!CRM_Streetimport_GP_Utils_Number::isGermanFormatNumber($stringNumber)) {
      return floatval($stringNumber);
    }

    $cleanStringNumber = str_replace('.', '', $stringNumber);
    $cleanStringNumber = str_replace(',', '.', $cleanStringNumber);

    return floatval($cleanStringNumber);
  }

  /**
   * Check if that German number format
   *
   * @param $stringNumber
   *
   * @return bool
   */
  public static function isGermanFormatNumber($stringNumber) {
    $pattern ='/^-?\d{1,3}(?:\.\d{3})*(?:,\d+)?$/';

    return (bool) (preg_match($pattern, $stringNumber));
  }

}
