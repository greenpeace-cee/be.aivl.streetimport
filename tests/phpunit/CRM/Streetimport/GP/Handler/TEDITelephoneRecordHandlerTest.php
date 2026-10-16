<?php

/**
 * Tests for TM phone feedback files (<org>_<project>_<tm_company>_<code>_<date>_<time>_<project>_Telefon.csv)
 *
 * @group headless
 */
class CRM_Streetimport_GP_Handler_TEDITelephoneRecordHandlerTest extends CRM_Streetimport_GP_Handler_HandlerTestBase {

  /**
   * Each phone status results in the corresponding phone update and activity
   */
  public function testPhoneStatuses() {
    foreach (['notcalled', 'called', 'changed', 'deleted', 'new'] as $alias) {
      $this->createContact($alias);
    }
    $notCalledPhone = $this->createPhone('notcalled', '+43 1 1111111');
    $this->createPhone('called', '+43 1 2222222');
    $changedPhone = $this->createPhone('changed', '+43 664 1234567');
    $this->createPhone('deleted', '+43 1 4444444');
    $callId = $this->createOutgoingCallActivity($this->contacts['called'], '2026-08-01 10:00:00');
    $this->placeholders['call:called'] = $callId;

    $result = $this->importFixture('GP/TM/TEDITelephone/GP_Test_tedi_{campaign_code}_20260901_120000_Statuses_Telefon.csv');

    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals('5 of 5 records imported.', $result['values']);
    $this->assertEmpty($this->getImportErrors());

    // 0: not called
    $notCalled = $this->contacts['notcalled'];
    $this->assertEmpty($this->getActivities($notCalled, 'contact_called'));
    $this->assertEmpty($this->getActivities($notCalled, 'contact_updated'));
    $this->assertEquals('+43 1 1111111', $this->getPhones($notCalled)[$notCalledPhone]['phone']);

    // 1: called, with parent activity referenced by activity_id
    $called = $this->contacts['called'];
    $activity = $this->assertSingleActivity($called, 'contact_called', 'Contact Called');
    $this->assertEquals('2026-09-01 14:30:00', $activity['activity_date_time']);
    $this->assertEquals('Completed', $activity['status_id:name']);
    $this->assertEquals('Called on number +43 1 2222222', $activity['details']);
    $this->assertEquals($callId, $activity[self::PARENT_ACTIVITY_FIELD]);
    $this->assertEmpty($this->getActivities($called, 'contact_updated'));

    // 2: number changed
    $changed = $this->contacts['changed'];
    $this->assertEquals([$changedPhone], array_keys($this->getPhones($changed)));
    $this->assertEquals('+43 664 3333333', $this->getPhones($changed)[$changedPhone]['phone']);
    $activity = $this->assertSingleActivity($changed, 'contact_updated', 'Contact Phone Updated');
    $this->assertEquals('phone', $activity['medium_id:name']);

    // 3: number deleted
    $deleted = $this->contacts['deleted'];
    $this->assertEmpty($this->getPhones($deleted));
    $activity = $this->assertSingleActivity($deleted, 'contact_updated', 'Contact Phone Deleted');
    $this->assertEquals('phone', $activity['medium_id:name']);

    // 4: new number
    $new = $this->contacts['new'];
    $phones = $this->getPhones($new);
    $this->assertCount(1, $phones);
    $phone = reset($phones);
    $this->assertEquals('+43 676 5555555', $phone['phone']);
    $this->assertEquals('Phone', $phone['phone_type_id:name']);
    $this->assertEquals('Home', $phone['location_type_id:name']);
    $activity = $this->assertSingleActivity($new, 'contact_updated', 'New Phone Number');
    $this->assertEquals('phone', $activity['medium_id:name']);
  }

  /**
   * Without activity_id, the most recent outgoing call of the campaign within
   * 90 days before the contact date is used as parent activity
   */
  public function testFallbackParentActivity() {
    $recent = $this->createContact('recent');
    $old = $this->createContact('old');
    $this->createPhone('recent', '+43 1 1234567');
    $this->createPhone('old', '+43 1 7654321');
    $this->createOutgoingCallActivity($recent, '2026-07-01 10:00:00');
    $recentCallId = $this->createOutgoingCallActivity($recent, '2026-08-01 10:00:00');
    $this->createOutgoingCallActivity($old, '2026-05-01 10:00:00');

    $result = $this->importFixture('GP/TM/TEDITelephone/GP_Test_tedi_{campaign_code}_20260901_120000_Parent_Telefon.csv');

    $this->assertEquals('2 of 2 records imported.', $result['values']);
    $activity = $this->assertSingleActivity($recent, 'contact_called', 'Contact Called');
    $this->assertEquals($recentCallId, $activity[self::PARENT_ACTIVITY_FIELD]);
    $activity = $this->assertSingleActivity($old, 'contact_called', 'Contact Called');
    $this->assertEmpty($activity[self::PARENT_ACTIVITY_FIELD]);
  }

  /**
   * Unknown contacts and statuses are reported as import errors, anonymized
   * contacts are skipped silently
   */
  public function testInvalidRecords() {
    $jane = $this->createContact('jane');
    $this->createPhone('jane', '+43 1 1234567');
    $anonymized = $this->createContact('anonymized', NULL, ['first_name' => '', 'last_name' => 'Anonymous']);
    civicrm_api3('Contact', 'delete', ['id' => $anonymized]);

    // telepower files are processed by the same handler
    $result = $this->importFixture('GP/TM/TEDITelephone/GP_Test_telepower_{campaign_code}_20260901_120000_Errors_Telefon.csv');

    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals('1 of 1 records imported.', $result['values']);
    $this->assertEquals([
      "Contact [999999999] couldn't be identified.",
      'Undefined status [9]. Row ignored.',
    ], $this->getImportErrors());
    $this->assertSingleActivity($jane, 'contact_called', 'Contact Called');
    $this->assertEmpty($this->getActivities($anonymized, 'contact_called'));
  }

  /**
   * Files with IMB campaign codes (no "C" prefix) reference contacts by
   * external identifier and phones by number
   */
  public function testCompatibilityMode() {
    $this->campaignId = civicrm_api3('Campaign', 'create', [
      'title'               => 'IMB Campaign',
      'external_identifier' => 'AKTION-0042',
    ])['id'];
    $jane = $this->createContact('jane', [], ['external_identifier' => 'IMB-1001']);
    $john = $this->createContact('john', [], ['external_identifier' => 'IMB-1002']);
    $joe = $this->createContact('joe', [], ['external_identifier' => 'IMB-1003']);
    $janeOtherPhone = $this->createPhone('jane', '+43 1 5555555');
    $this->createPhone('jane', '+43 1 4444444');
    $johnPhone = $this->createPhone('john', '+43 664 1234567');

    $result = $this->importFixture('GP/TM/TEDITelephone/GP_Test_tedi_0042_20260901_120000_Legacy_Telefon.csv');

    $this->assertEquals('2 of 2 records imported.', $result['values']);

    // the phone matching the number is deleted
    $this->assertEquals([$janeOtherPhone], array_keys($this->getPhones($jane)));
    $this->assertSingleActivity($jane, 'contact_updated', 'Contact Phone Deleted');

    // changed numbers can't be matched, a manual update is required instead
    $this->assertEquals('+43 664 1234567', $this->getPhones($john)[$johnPhone]['phone']);
    $activity = $this->assertSingleActivity($john, 'manual_update_required', 'Phone number has changed to: +43 664 7654321');
    $this->assertEquals('Scheduled', $activity['status_id:name']);
    $this->assertEmpty($this->getActivities($john, 'contact_updated'));
    $this->assertEquals(["Phone [98766] couldn't be identified."], $this->getImportErrors());

    $activity = $this->assertSingleActivity($joe, 'contact_called', 'Contact Called');
    $this->assertEquals('Called on number +43 1 2222222', $activity['details']);
  }

}
