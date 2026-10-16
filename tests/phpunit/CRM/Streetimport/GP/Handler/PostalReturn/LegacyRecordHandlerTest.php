<?php

/**
 * Tests for legacy postal return barcode lists (RTS_<category>.csv)
 *
 * @group headless
 */
class CRM_Streetimport_GP_Handler_PostalReturn_LegacyRecordHandlerTest extends CRM_Streetimport_GP_Handler_HandlerTestBase {

  /**
   * The category is taken from the file name, the date is the import date
   */
  public function testCategoryFromFileName() {
    $jane = $this->createContact('jane');
    $john = $this->createContact('john');
    $letterId = $this->createLetterActivity($jane);

    $result = $this->importFixture('GP/PostalReturn/Legacy/RTS_moved.csv');

    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals('2 of 2 records imported.', $result['values']);
    $this->assertEmpty($this->getImportErrors());

    $janeActivity = $this->assertSingleRTSActivity($jane, 'verzogen');
    $this->assertEquals($letterId, $janeActivity[self::PARENT_ACTIVITY_FIELD]);
    $this->assertEqualsWithDelta(time(), strtotime($janeActivity['activity_date_time']), 300);
    $this->assertEquals(1, $this->getRTSCounter($jane));

    $johnActivity = $this->assertSingleRTSActivity($john, 'verzogen');
    $this->assertEmpty($johnActivity[self::PARENT_ACTIVITY_FIELD], 'No parent should be set without letter_mail activity');
    $this->assertEquals(1, $this->getRTSCounter($john));
  }

  /**
   * "nicht behoben" returns don't indicate an invalid address (GP-5232)
   */
  public function testNotRetrievedDoesNotIncreaseCounter() {
    $jane = $this->createContact('jane');

    $result = $this->importFixture('GP/PostalReturn/Legacy/RTS_notretrieved.csv');

    $this->assertEquals('1 of 1 records imported.', $result['values']);
    $this->assertSingleRTSActivity($jane, 'nicht behoben');
    $this->assertEquals(0, $this->getRTSCounter($jane));
  }

  /**
   * Contacts are marked as deceased on the second "deceased" return within
   * two years
   */
  public function testDeceased() {
    $jane = $this->createContact('jane');
    $john = $this->createContact('john');
    $this->createRTSActivity($jane, 'verstorben', date('Y-m-d H:i:s', strtotime('-1 year')));
    $this->createRTSActivity($john, 'verstorben', date('Y-m-d H:i:s', strtotime('-3 years')));

    $result = $this->importFixture('GP/PostalReturn/Legacy/RTS_deceased.csv');

    $this->assertEquals('2 of 2 records imported.', $result['values']);

    $janeContact = $this->getContact($jane);
    $this->assertEquals(1, $janeContact['is_deceased']);
    $this->assertEquals(date('Y-m-d'), $janeContact['deceased_date']);
    $this->assertEquals(0, $janeContact['is_deleted']);
    $this->assertCount(2, $this->getRTSActivities($jane));
    $this->assertEquals(1, $this->getRTSCounter($jane));

    $johnContact = $this->getContact($john);
    $this->assertEquals(0, $johnContact['is_deceased']);
    $this->assertEmpty($johnContact['deceased_date']);
    $this->assertCount(2, $this->getRTSActivities($john));
    $this->assertEquals(1, $this->getRTSCounter($john));
  }

  /**
   * Records with invalid references are skipped without error activities,
   * file names may have a numeric suffix
   */
  public function testInvalidReferences() {
    $jane = $this->createContact('jane');

    $result = $this->importFixture('GP/PostalReturn/Legacy/RTS_unknown_2.csv');

    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals('1 of 5 records imported.', $result['values']);
    $this->assertEmpty($this->getImportErrors());
    $this->assertSingleRTSActivity($jane, 'unbekannt');
    $this->assertEquals(1, $this->getRTSCounter($jane));
  }

}
