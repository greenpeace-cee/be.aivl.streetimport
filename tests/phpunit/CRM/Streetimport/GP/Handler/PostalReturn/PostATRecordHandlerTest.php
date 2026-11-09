<?php

/**
 * Tests for post.at managed return files (RET_<org>_<mailing>_<date>.csv)
 *
 * @group headless
 */
class CRM_Streetimport_GP_Handler_PostalReturn_PostATRecordHandlerTest extends CRM_Streetimport_GP_Handler_HandlerTestBase {

  /**
   * Each post.at return code ("Grund") maps to an RTS category, the activity
   * date is the return date ("EinspDatum")
   */
  public function testReturnCodes() {
    $expectedSubjects = [
      'unknown'      => 'unbekannt',
      'rejected'     => 'nicht angenommen',
      'moved'        => 'verzogen',
      'notretrieved' => 'nicht behoben',
      'incomplete'   => 'Anschrift ungenügend',
      'deceased'     => 'verstorben',
      'other'        => 'sonstiges',
      'badcode'      => 'falsche PLZ',
      'unused'       => 'Abgabestelle unbenutzt',
    ];
    foreach (array_keys($expectedSubjects) as $alias) {
      $this->createContact($alias);
    }
    $letterId = $this->createLetterActivity($this->contacts['moved']);

    $result = $this->importFixture('GP/PostalReturn/PostAT/RET_GP_Categories_20260901.csv');

    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals('9 of 9 records imported.', $result['values']);
    $this->assertEmpty($this->getImportErrors());
    foreach ($expectedSubjects as $alias => $subject) {
      $contactId = $this->contacts[$alias];
      $activity = $this->assertSingleRTSActivity($contactId, $subject, '2026-09-01 00:00:00');
      if ($alias == 'moved') {
        $this->assertEquals($letterId, $activity[self::PARENT_ACTIVITY_FIELD]);
      }
      else {
        $this->assertEmpty($activity[self::PARENT_ACTIVITY_FIELD]);
      }
      // "nicht behoben" doesn't indicate an invalid address (GP-5232)
      $this->assertEquals($alias == 'notretrieved' ? 0 : 1, $this->getRTSCounter($contactId), "RTS counter for '{$alias}'");
      // a single "deceased" return doesn't mark the contact as deceased
      $this->assertEquals(0, $this->getContact($contactId)['is_deceased']);
    }
  }

  /**
   * The RTS counter is only increased if the address in the return file
   * matches the current address (GP-10216)
   */
  public function testAddressComparison() {
    $same = $this->createContact('same');
    $umlaut = $this->createContact('umlaut', [
      'street_address' => 'Mühlgasse 3',
      'postal_code'    => '3380',
      'city'           => 'Pöchlarn',
    ]);
    $changed = $this->createContact('changed');
    $postcode = $this->createContact('postcode');

    $result = $this->importFixture('GP/PostalReturn/PostAT/RET_GP_Address_20260902.csv');

    $this->assertEquals('4 of 4 records imported.', $result['values']);
    foreach ([$same, $umlaut, $changed, $postcode] as $contactId) {
      $this->assertSingleRTSActivity($contactId, 'verzogen', '2026-09-02 00:00:00');
    }
    $this->assertEquals(1, $this->getRTSCounter($same), 'Address differing only in case/whitespace should count as unchanged');
    $this->assertEquals(1, $this->getRTSCounter($umlaut), 'Transliterated umlauts should count as unchanged');
    $this->assertEquals(0, $this->getRTSCounter($changed), 'Changed street should not increase RTS counter');
    $this->assertEquals(0, $this->getRTSCounter($postcode), 'Changed postal code should not increase RTS counter');
  }

  /**
   * Returns with address data for contacts without a primary address are
   * processed, the missing address is reported as an import error
   */
  public function testAddressComparisonWithoutPrimaryAddress() {
    $jane = $this->createContact('jane', NULL);

    $result = $this->importFixture('GP/PostalReturn/PostAT/RET_GP_NoAddress_20260905.csv');

    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals('1 of 1 records imported.', $result['values']);
    $this->assertSingleRTSActivity($jane, 'verzogen', '2026-09-05 00:00:00');
    $errors = $this->getImportErrors();
    $this->assertCount(1, $errors);
    $this->assertStringStartsWith("Primary address for contact [{$jane}] not found.", $errors[0]);
  }

  /**
   * Contacts are marked as deceased on the second "deceased" return within
   * two years of the return date
   */
  public function testDeceased() {
    $jane = $this->createContact('jane');
    $john = $this->createContact('john');
    $this->createRTSActivity($jane, 'verstorben', '2024-09-04 00:00:00');
    $this->createRTSActivity($john, 'verstorben', '2024-09-02 00:00:00');

    $result = $this->importFixture('GP/PostalReturn/PostAT/RET_GP_Deceased_20260903.csv');

    $this->assertEquals('2 of 2 records imported.', $result['values']);

    $janeContact = $this->getContact($jane);
    $this->assertEquals(1, $janeContact['is_deceased']);
    $this->assertEquals('2026-09-03', $janeContact['deceased_date']);
    $this->assertEquals(0, $janeContact['is_deleted']);
    $this->assertCount(2, $this->getRTSActivities($jane));

    $johnContact = $this->getContact($john);
    $this->assertEquals(0, $johnContact['is_deceased']);
    $this->assertEmpty($johnContact['deceased_date']);
    $this->assertCount(2, $this->getRTSActivities($john));
  }

  /**
   * Invalid records are skipped and reported as import errors, except for
   * returns for deleted contacts
   */
  public function testInvalidRecords() {
    $jane = $this->createContact('jane');
    $deleted = $this->createContact('deleted');
    civicrm_api3('Contact', 'delete', ['id' => $deleted]);

    $result = $this->importFixture('GP/PostalReturn/PostAT/RET_GP_Invalid_20260904.csv');

    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals('1 of 5 records imported.', $result['values']);

    $errors = $this->getImportErrors();
    $this->assertCount(3, $errors);
    $this->assertEquals("Unknown postal return category '99'", $errors[0]);
    $this->assertStringStartsWith('Invalid RTS campaign_id in reference', $errors[1]);
    $this->assertStringStartsWith('Invalid RTS contact_id in reference', $errors[2]);

    $this->assertSingleRTSActivity($jane, 'unbekannt', '2026-09-04 00:00:00');
    $this->assertEquals(1, $this->getRTSCounter($jane));
    $this->assertEmpty($this->getRTSActivities($deleted));
    $this->assertEquals(0, $this->getRTSCounter($deleted));
  }

}
