<?php

/**
 * Tests for TM contact feedback files (<org>_<project>_<tm_company>_<code>_<date>_<time>_<project>_Kontakte.csv)
 *
 * The project type is derived from the first three characters of the project
 * name in the file name, e.g. "Umfrage" => survey.
 *
 * @group headless
 */
class CRM_Streetimport_GP_Handler_TEDIContactRecordHandlerTest extends CRM_Streetimport_GP_Handler_HandlerTestBase {

  /**
   * Responses are recorded as response activity, an outgoing call parent
   * activity also receives the response
   */
  public function testResponses() {
    foreach (['11 Info gewünscht', '93 nicht angegriffen'] as $response) {
      \Civi\Api4\OptionValue::create(FALSE)
        ->addValue('option_group_id:name', 'response')
        ->addValue('label', $response)
        ->addValue('value', $response)
        ->execute();
    }
    $strict = $this->createContact('strict');
    $strictCallId = $this->createOutgoingCallActivity($strict, '2026-08-15 10:00:00');
    $this->placeholders['call:strict'] = $strictCallId;
    $action = $this->createContact('action');
    $actionId = $this->createOutgoingCallActivity($action, '2026-08-15 10:00:00', 'Action');
    $this->placeholders['call:action'] = $actionId;
    $fallback = $this->createTMContact('fallback');
    $noParent = $this->createContact('noparent');
    $identity = $this->createTMContact('identity');

    $result = $this->importFixture('GP/TM/TEDIContact/GP_Umfrage_tedi_{campaign_code}_20260901_120000_Responses_Kontakte.csv');

    $this->assertEquals(0, $result['is_error']);
    $this->assertEquals('4 of 6 records imported.', $result['values']);
    $this->assertEquals([
      'Could not find parent activity',
      "Contact [999999999] couldn't be identified.",
    ], $this->getImportErrors());

    // referenced outgoing call: receives the response and the TM agent as assignee
    $dialoger = \Civi\Api4\Contact::get(FALSE)
      ->addSelect('id', 'display_name')
      ->addWhere('dialoger_data.dialoger_id', '=', 'TEDI-4711')
      ->execute()
      ->single();
    $this->assertEquals('TEDI Agent 4711', $dialoger['display_name']);
    $response = $this->assertSingleActivity($strict, 'Response', '11 Info gewünscht');
    $this->assertEquals('2026-09-01 14:30:00', $response['activity_date_time']);
    $this->assertEquals('phone', $response['medium_id:name']);
    $this->assertEquals('Completed', $response['status_id:name']);
    $this->assertEquals($strictCallId, $response[self::PARENT_ACTIVITY_FIELD]);
    $this->assertEquals([$dialoger['id']], $this->getAssignees($response['id']));
    $call = $this->getOutgoingCall($strictCallId);
    $this->assertEquals('11 Info gewünscht', $call['activity_tmresponses.response:label']);
    $this->assertEquals('2026-09-01', $call['activity_tmresponses.response_date']);
    $this->assertEquals(1, $call['activity_tmresponses.response_counter']);
    $this->assertEquals('Completed', $call['status_id:name']);
    $this->assertEquals([$dialoger['id']], $this->getAssignees($strictCallId));

    // referenced "Action" activity: only used as parent
    $response = $this->assertSingleActivity($action, 'Response', '11 Info gewünscht');
    $this->assertEquals($actionId, $response[self::PARENT_ACTIVITY_FIELD]);
    $this->assertEquals('Scheduled', $this->getOutgoingCall($actionId)['status_id:name']);

    // parent determined via fallback, "not attempted" doesn't count as response
    $response = $this->assertSingleActivity($fallback, 'Response', '93 nicht angegriffen');
    $call = $this->getOutgoingCall($response[self::PARENT_ACTIVITY_FIELD]);
    $this->assertEquals('93 nicht angegriffen', $call['activity_tmresponses.response:label']);
    $this->assertEquals(0, $call['activity_tmresponses.response_counter']);

    $this->assertEmpty($this->getActivities($noParent, 'Response'));

    // potential identity changes don't create a response
    $this->assertEmpty($this->getActivities($identity, 'Response'));
    $this->assertEquals('Scheduled', $this->getOutgoingCall($this->placeholders['call:identity'])['status_id:name']);
  }

  /**
   * Contact base data is updated for reached contacts, unless the update
   * would change the contact's identity
   */
  public function testContactBaseUpdates() {
    $updated = $this->createTMContact('updated');
    $identity = $this->createTMContact('identity');
    $organization = \Civi\Api4\Contact::create(FALSE)
      ->addValue('contact_type', 'Organization')
      ->addValue('organization_name', 'ACME')
      ->execute()
      ->first()['id'];
    $this->addContactAlias('organization', $organization);
    $this->createOutgoingCallActivity($organization, '2026-08-15 10:00:00');
    $email = $this->createTMContact('email');
    $unreached = $this->createTMContact('unreached');
    $talked = $this->createTMContact('talked');

    $result = $this->importFixture('GP/TM/TEDIContact/GP_Umfrage_tedi_{campaign_code}_20260901_120000_Contact_Kontakte.csv');

    $this->assertEquals('6 of 6 records imported.', $result['values']);
    $this->assertEmpty($this->getImportErrors());

    $contact = $this->getContactDetails($updated);
    $this->assertEquals('Ms.', $contact['prefix_id:name']);
    $this->assertEquals('1980-05-17', $contact['birth_date']);
    $this->assertEquals('Dr. von', $contact['formal_title']);
    $this->assertSingleActivity($updated, 'contact_updated', 'Contact Base Data Updated');

    $this->assertEquals('Doe', $this->getContactDetails($identity)['last_name']);
    $this->assertSingleActivity($identity, 'manual_update_required', 'Potential Identity Change');
    $this->assertEmpty($this->getActivities($identity, 'contact_updated'));

    $this->assertSingleActivity($organization, 'manual_update_required', "Convert to 'Individual'");

    $this->assertEquals(['new@example.org'], $this->getEmails($email));
    $this->assertSingleActivity($email, 'contact_updated', 'Contact Email Added');

    // updates are only applied if the contact was reached ...
    $this->assertEquals('Doe', $this->getContactDetails($unreached)['last_name']);
    $this->assertEmpty($this->getEmails($unreached));
    $this->assertEmpty($this->getActivities($unreached, 'contact_updated'));
    $this->assertEmpty($this->getActivities($unreached, 'manual_update_required'));
    // ... or a conversation took place
    $this->assertEquals(['talked@example.org'], $this->getEmails($talked));
  }

  /**
   * Addresses are created or replaced if complete, otherwise a manual update
   * is requested
   */
  public function testAddressUpdates() {
    $noAddress = $this->createTMContact('noaddress', NULL);
    $moved = $this->createTMContact('moved');
    $incomplete = $this->createTMContact('incomplete');
    $partial = $this->createTMContact('partial');
    $identical = $this->createTMContact('identical');
    $countryOnly = $this->createTMContact('countryonly');
    $verified = $this->createTMContact('verified');
    $invalidCountry = $this->createTMContact('invalidcountry');
    $this->setRTSCounter($moved, 2);
    $this->setRTSCounter($verified, 3);

    $result = $this->importFixture('GP/TM/TEDIContact/GP_Umfrage_tedi_{campaign_code}_20260901_120000_Address_Kontakte.csv');

    $this->assertEquals('7 of 8 records imported.', $result['values']);
    $this->assertEquals(["Invalid Country for Contact [{$invalidCountry}]: 'XX'"], $this->getImportErrors());

    $this->assertEquals([
      'street_address'        => 'Neubaugasse 12 /3',
      'postal_code'           => '1070',
      'city'                  => 'Wien',
      'country_id:abbr'       => 'AT',
      'location_type_id:name' => 'Home',
    ], $this->getPrimaryAddress($noAddress));
    $this->assertSingleActivity($noAddress, 'contact_updated', 'Contact Address Created');

    $this->assertEquals([
      'street_address'        => 'Unter den Linden 5',
      'postal_code'           => '10117',
      'city'                  => 'Berlin',
      'country_id:abbr'       => 'DE',
      'location_type_id:name' => 'Home',
    ], $this->getPrimaryAddress($moved));
    $this->assertEquals(0, $this->getRTSCounter($moved));
    $this->assertSingleActivity($moved, 'contact_updated', 'Contact Address Updated');

    foreach ([$incomplete, $partial] as $contactId) {
      $this->assertEquals('Hauptstraße 1', $this->getPrimaryAddress($contactId)['street_address']);
      $this->assertSingleActivity($contactId, 'manual_update_required', 'Manual Address Update');
    }

    foreach ([$identical, $countryOnly] as $contactId) {
      $this->assertEquals('Hauptstraße 1', $this->getPrimaryAddress($contactId)['street_address']);
      $this->assertEmpty($this->getActivities($contactId, 'contact_updated'));
      $this->assertEmpty($this->getActivities($contactId, 'manual_update_required'));
    }

    // address confirmed by contact
    $this->assertEquals(0, $this->getRTSCounter($verified));

    $this->assertEmpty($this->getActivities($invalidCountry, 'Response'));
  }

  /**
   * Responses requesting to be erased, disabled or not contacted
   */
  public function testDisableContact() {
    foreach (['erase', 'disable', 'inactive', 'deceased', 'donotphone'] as $alias) {
      $this->createTMContact($alias);
    }

    $result = $this->importFixture('GP/TM/TEDIContact/GP_Umfrage_tedi_{campaign_code}_20260901_120000_Disable_Kontakte.csv');

    $this->assertEquals('5 of 5 records imported.', $result['values']);
    $this->assertEmpty($this->getImportErrors());

    $erase = $this->getContactDetails($this->contacts['erase']);
    $this->assertTrue($erase['is_deleted']);
    $this->assertEquals(['anonymise'], $this->getTags($this->contacts['erase']));
    $this->assertSingleActivity($this->contacts['erase'], 'Response', '25 Kontakt löschen');

    $this->assertTrue($this->getContactDetails($this->contacts['disable'])['is_deleted']);
    $this->assertEmpty($this->getTags($this->contacts['disable']));

    $inactive = $this->getContactDetails($this->contacts['inactive']);
    foreach (['do_not_email', 'do_not_phone', 'do_not_mail', 'do_not_sms', 'do_not_trade', 'is_opt_out'] as $flag) {
      $this->assertTrue($inactive[$flag], "{$flag} should be set");
    }
    $this->assertFalse($inactive['is_deleted']);

    $deceased = $this->getContactDetails($this->contacts['deceased']);
    $this->assertTrue($deceased['is_deceased']);
    $this->assertEquals('2026-09-01', $deceased['deceased_date']);
    $this->assertFalse($deceased['is_deleted']);

    $doNotPhone = $this->getContactDetails($this->contacts['donotphone']);
    $this->assertTrue($doNotPhone['do_not_phone']);
    $this->assertFalse($doNotPhone['do_not_mail']);
    $this->assertSingleActivity($this->contacts['donotphone'], 'Response', '41 Anrufsperre');
  }

  /**
   * Semi-formal notes ("Bemerkung") trigger group changes, privacy settings,
   * mailing restrictions and tasks
   */
  public function testFeatures() {
    $groups = [];
    foreach (['kein ACT', 'ACT nur online', 'Zusendungen nur online', 'kein Kalender', 'Nur ACT', 'keine Geschenke', 'Legacy Opt-in', 'keine Legacy Kommunikation', 'Newsletter', 'Donation Info'] as $title) {
      $groups[$title] = $this->createGroup($title);
    }
    \Civi\Api4\Group::update(FALSE)
      ->addValue('name', 'Donation_Info_30')
      ->addWhere('id', '=', $groups['Donation Info'])
      ->execute();
    $this->setStreetimportSetting('newsletter_group_id', $groups['Newsletter']);
    foreach (['Task Category: TM Freie Bemerkung', 'Task Category: Legacy Freie Bemerkung'] as $tag) {
      \Civi\Api4\Tag::create(FALSE)
        ->addValue('name', $tag)
        ->addValue('used_for', ['civicrm_activity'])
        ->execute();
    }
    foreach (['nopost', 'nophone', 'nocalendar', 'onemailing', 'actonly', 'noincentives', 'nomail', 'newsletterin', 'newsletterout', 'legacyoptin', 'legacyinfo', 'task', 'nothing', 'unknown'] as $alias) {
      $this->createTMContact($alias);
    }
    $this->updateContact('nopost', ['do_not_mail' => TRUE]);
    $this->setRTSCounter($this->contacts['nopost'], 2);
    foreach (['kein ACT', 'ACT nur online', 'Zusendungen nur online'] as $title) {
      $this->addToGroup('nopost', $groups[$title]);
    }
    $this->updateContact('newsletterin', ['do_not_email' => TRUE, 'is_opt_out' => TRUE]);
    $this->addToGroup('newsletterout', $groups['Newsletter']);
    $this->addToGroup('newsletterout', $groups['Donation Info']);
    $this->addToGroup('legacyinfo', $groups['keine Legacy Kommunikation']);

    $result = $this->importFixture('GP/TM/TEDIContact/GP_Umfrage_tedi_{campaign_code}_20260901_120000_Features_Kontakte.csv');

    $this->assertEquals('14 of 14 records imported.', $result['values']);
    $this->assertEquals(["Unknown feature 'gibt es nicht' ignored."], $this->getImportErrors());

    // "erhält keine Post": mail allowed again, address verified, mail restriction groups removed
    $this->assertFalse($this->getContactDetails($this->contacts['nopost'])['do_not_mail']);
    $this->assertEquals(0, $this->getRTSCounter($this->contacts['nopost']));
    $this->assertEquals([
      $groups['kein ACT']               => 'Removed',
      $groups['ACT nur online']         => 'Removed',
      $groups['Zusendungen nur online'] => 'Removed',
    ], $this->getGroupStatuses('nopost'));

    $this->assertTrue($this->getContactDetails($this->contacts['nophone'])['do_not_phone']);
    $this->assertEquals([$groups['kein Kalender'] => 'Added'], $this->getGroupStatuses('nocalendar'));

    $this->assertEquals([$groups['Nur ACT'] => 'Added'], $this->getGroupStatuses('onemailing'));
    $this->assertEquals('1', $this->getContactDetails($this->contacts['onemailing'])['additional_demographics.dm_restrictions']);
    $this->assertEquals([$groups['Nur ACT'] => 'Added'], $this->getGroupStatuses('actonly'));
    $this->assertEmpty($this->getContactDetails($this->contacts['actonly'])['additional_demographics.dm_restrictions']);

    $this->assertEquals([$groups['keine Geschenke'] => 'Added'], $this->getGroupStatuses('noincentives'));
    $this->assertEquals('4', $this->getContactDetails($this->contacts['noincentives'])['additional_demographics.dm_restrictions']);

    $noMail = $this->getContactDetails($this->contacts['nomail']);
    $this->assertTrue($noMail['do_not_mail']);
    $this->assertEquals('0', $noMail['additional_demographics.dm_restrictions']);

    $this->assertEquals([$groups['Newsletter'] => 'Added'], $this->getGroupStatuses('newsletterin'));
    $newsletterIn = $this->getContactDetails($this->contacts['newsletterin']);
    $this->assertFalse($newsletterIn['do_not_email']);
    $this->assertFalse($newsletterIn['is_opt_out']);
    $this->assertEquals([
      $groups['Newsletter']    => 'Removed',
      $groups['Donation Info'] => 'Removed',
    ], $this->getGroupStatuses('newsletterout'));
    $this->assertTrue($this->getContactDetails($this->contacts['newsletterout'])['is_opt_out']);

    $this->assertEquals([
      $groups['Legacy Opt-in']               => 'Added',
      $groups['keine Legacy Kommunikation'] => 'Added',
    ], $this->getGroupStatuses('legacyoptin'));
    $this->assertEquals([$groups['keine Legacy Kommunikation'] => 'Removed'], $this->getGroupStatuses('legacyinfo'));

    // tasks for manual processing, for free text and notes requiring it
    $tasks = $this->getActivities($this->contacts['task'], 'manual_update_required');
    $this->assertEquals(['Bitte Adresse prüfen', 'erhält Post doppelt'], array_column($tasks, 'subject'));
    foreach ($tasks as $task) {
      $this->assertEquals('Scheduled', $task['status_id:name']);
      $this->assertEquals(['Task Category: TM Freie Bemerkung'], $this->getActivityTags($task['id']));
      $this->assertEquals([$this->adminContactId], $this->getAssignees($task['id']));
    }

    foreach (['nothing', 'unknown'] as $alias) {
      $this->assertEmpty($this->getGroupStatuses($alias));
      $this->assertEmpty($this->getActivities($this->contacts[$alias], 'manual_update_required'));
      $this->assertSingleActivity($this->contacts[$alias], 'Response', '11 Info gewünscht');
    }
  }

  /**
   * Webshop orders from T-shirt notes and JSON payloads
   */
  public function testOrders() {
    foreach (['shirt', 'legacyshirt', 'order', 'freeorder', 'scheduledorder'] as $alias) {
      $this->createTMContact($alias);
    }

    $result = $this->importFixture('GP/TM/TEDIContact/GP_Umfrage_tedi_{campaign_code}_20260901_120000_Orders_Kontakte.csv');

    $this->assertEquals('5 of 5 records imported.', $result['values']);
    $this->assertEmpty($this->getImportErrors());

    $order = $this->getWebshopOrder('shirt');
    $this->assertEquals('order type Klimaschutz M/XL AND number of items 1', $order['subject']);
    $this->assertEquals('Scheduled', $order['status_id:name']);
    $this->assertEquals('Klimaschutz', $order['webshop_information.order_type']);
    $this->assertEquals(1, $order['webshop_information.order_count']);
    $this->assertEquals('M', $order['webshop_information.shirt_type']);
    $this->assertEquals('XL', $order['webshop_information.shirt_size']);

    $order = $this->getWebshopOrder('legacyshirt');
    $this->assertEquals('order type T-Shirt W/S AND number of items 1', $order['subject']);
    $this->assertEquals('11', $order['webshop_information.order_type']);
    $this->assertEquals('W', $order['webshop_information.shirt_type']);
    $this->assertEquals('S', $order['webshop_information.shirt_size']);

    $order = $this->getWebshopOrder('order');
    $this->assertEquals('Webshop Order', $order['subject']);
    $this->assertEquals('Completed', $order['status_id:name']);
    $this->assertEquals('Kalender', $order['webshop_information.order_type']);
    $this->assertEquals(2, $order['webshop_information.order_count']);
    $this->assertFalse($order['webshop_information.free_order']);

    $order = $this->getWebshopOrder('freeorder');
    $this->assertEquals('Scheduled', $order['status_id:name']);
    $this->assertEquals(1, $order['webshop_information.order_count']);
    $this->assertTrue($order['webshop_information.free_order']);

    $this->assertEquals('Scheduled', $this->getWebshopOrder('scheduledorder')['status_id:name']);
  }

  /**
   * JSON payloads for follow-up calls and group changes
   */
  public function testJsonActions() {
    $groups = [];
    foreach (['test_add', 'test_remove', 'test_delete'] as $name) {
      $groups[$name] = $this->createGroup($name);
    }
    foreach (['bmf', 'callback', 'groups', 'invalid', 'unknownaction', 'unknowngroup'] as $alias) {
      $this->createTMContact($alias);
    }
    $this->addToGroup('groups', $groups['test_remove']);
    $this->addToGroup('groups', $groups['test_delete']);

    $result = $this->importFixture('GP/TM/TEDIContact/GP_Umfrage_tedi_{campaign_code}_20260901_120000_Json_Kontakte.csv');

    $this->assertEquals('6 of 6 records imported.', $result['values']);
    $this->assertEquals([
      "Invalid JSON. Error was: Syntax error for JSON '{broken'",
      'Unknown action "unknown" in JSON payload.',
      'Unknown group "does_not_exist" in modify_group JSON payload.',
    ], $this->getImportErrors());

    // BMF move: additional response and a follow-up call with the requested details
    $responses = $this->getActivities($this->contacts['bmf'], 'Response');
    $this->assertEquals(['11 Info gewünscht', '51 BMF Umschreibung'], array_column($responses, 'subject'));
    $calls = $this->getActivities($this->contacts['bmf'], 'Outgoing Call');
    $this->assertCount(2, $calls);
    $call = end($calls);
    $this->assertEquals('BMF move to Muster, Max requested', $call['subject']);
    $this->assertEquals('Scheduled', $call['status_id:name']);
    $this->assertEquals($this->campaignId, $call['campaign_id']);
    $this->assertStringContainsString('Max Muster', $call['details']);
    $this->assertStringContainsString('+43 1 2345678', $call['details']);

    $calls = $this->getActivities($this->contacts['callback'], 'Outgoing Call');
    $this->assertEquals('Rückruf', end($calls)['subject']);

    $this->assertEquals([
      $groups['test_add']    => 'Added',
      $groups['test_remove'] => 'Removed',
    ], $this->getGroupStatuses('groups'));
  }

  /**
   * Pledges in conversion projects create new contracts
   */
  public function testConversion() {
    foreach (['converted', 'guardian', 'incomplete', 'withcontract'] as $alias) {
      $this->createTMContact($alias);
    }
    $existingContract = $this->createExistingContract('withcontract');

    $result = $this->importFixture('GP/TM/TEDIContact/GP_Umwandlung_tedi_{campaign_code}_20260901_120000_Contract_Kontakte.csv');

    $this->assertEquals('3 of 4 records imported.', $result['values']);
    $this->assertEquals([
      "Couldn't create mandate, information incomplete.",
      "Conversion projects shouldn't provide a contract ID",
    ], $this->getImportErrors());

    $contract = $this->getSingleContract('converted');
    $this->assertEquals('Förderer', $contract['membership_type_id:name']);
    $this->assertEquals($this->campaignId, $contract['campaign_id']);
    $this->assertEquals(120, $contract['membership_payment.membership_annual']);
    $this->assertEquals(12, $contract['membership_payment.membership_frequency']);
    $this->assertEquals(
      $this->placeholders['call:converted'],
      $this->getContractActivity($contract['id'], 'Contract_Signed')[self::PARENT_ACTIVITY_FIELD]
    );
    $this->assertSingleActivity($this->contacts['converted'], 'Response', '01 Zusage Förderer');

    $contract = $this->getSingleContract('guardian');
    $this->assertEquals('Guardian of the Ocean', $contract['membership_type_id:name']);
    $this->assertEquals(1200, $contract['membership_payment.membership_annual']);
    $this->assertEquals(1, $contract['membership_payment.membership_frequency']);

    $this->assertEmpty($this->getContracts('incomplete'));
    $this->assertEquals([$existingContract], array_column($this->getContracts('withcontract'), 'id'));
    $this->assertEmpty($this->getActivities($this->contacts['withcontract'], 'Response'));
  }

  /**
   * Pledges in upgrade projects update the referenced active contract
   */
  public function testUpgrade() {
    foreach (['upgraded', 'carryon', 'nocontract', 'cancelled'] as $alias) {
      $this->createTMContact($alias);
    }
    $upgraded = $this->createExistingContract('upgraded');
    $carryOn = $this->createExistingContract('carryon');
    $cancelled = $this->createExistingContract('cancelled');
    $this->cancelExistingContract($cancelled);

    $result = $this->importFixture('GP/TM/TEDIContact/GP_Upgrade_tedi_{campaign_code}_20260901_120000_Contract_Kontakte.csv');

    $this->assertEquals('3 of 3 records imported.', $result['values']);
    $this->assertEquals([
      'Format violation, the record type requires a contract_id.',
      'Update projects should refer to active contracts',
    ], $this->getImportErrors());

    $contract = $this->getContract($upgraded);
    $this->assertEquals('Guardian of the Ocean', $contract['membership_type_id:name']);
    $this->assertEquals(240, $contract['membership_payment.membership_annual']);
    $this->assertEquals(12, $contract['membership_payment.membership_frequency']);
    $this->assertEquals(
      $this->placeholders['call:upgraded'],
      $this->getContractActivity($upgraded, 'Contract_Updated')[self::PARENT_ACTIVITY_FIELD]
    );

    // "Zusage Förderer" keeps the membership type
    $contract = $this->getContract($carryOn);
    $this->assertEquals('Förderer', $contract['membership_type_id:name']);
    $this->assertEquals(180, $contract['membership_payment.membership_annual']);
    $this->assertEquals(4, $contract['membership_payment.membership_frequency']);

    // incomplete records are skipped entirely
    $this->assertEmpty($this->getContracts('nocontract'));
    $this->assertEmpty($this->getActivities($this->contacts['nocontract'], 'Response'));

    $contract = $this->getContract($cancelled);
    $this->assertEquals('Cancelled', $contract['status_id:name']);
    $this->assertEquals(120, $contract['membership_payment.membership_annual']);
  }

  /**
   * Pledges in reactivation projects revive the referenced inactive contract
   */
  public function testReactivation() {
    $this->createTMContact('revived');
    $this->createTMContact('active');
    $revived = $this->createExistingContract('revived');
    $this->cancelExistingContract($revived);
    $active = $this->createExistingContract('active');

    $result = $this->importFixture('GP/TM/TEDIContact/GP_Reaktivierung_tedi_{campaign_code}_20260901_120000_Contract_Kontakte.csv');

    $this->assertEquals('2 of 2 records imported.', $result['values']);
    $this->assertEquals(['This project should only refer to inactive contracts'], $this->getImportErrors());
    $this->assertNotEquals('Cancelled', $this->getContract($revived)['status_id:name']);
    $this->assertEquals(
      $this->placeholders['call:revived'],
      $this->getContractActivity($revived, 'Contract_Revived')[self::PARENT_ACTIVITY_FIELD]
    );
    $this->assertEmpty($this->getContractActivity($active, 'Contract_Revived'));
  }

  /**
   * Cancellations, one-off donations and deceased contacts
   */
  public function testCancellationAndOneOffDonation() {
    foreach (['storno', 'stornodefault', 'stornonocontract', 'deceased', 'ooff', 'ooffincomplete'] as $alias) {
      $this->createTMContact($alias);
    }
    $storno = $this->createExistingContract('storno');
    $stornoDefault = $this->createExistingContract('stornodefault');

    $result = $this->importFixture('GP/TM/TEDIContact/GP_Umfrage_tedi_{campaign_code}_20260901_120000_Cancel_Kontakte.csv');

    $this->assertEquals('6 of 6 records imported.', $result['values']);
    $this->assertEquals(["Couldn't create mandate, information incomplete."], $this->getImportErrors());

    $contract = $this->getContract($storno);
    $this->assertEquals('Cancelled', $contract['status_id:name']);
    $this->assertEquals('MS02', $contract['membership_cancellation.membership_cancel_reason']);
    $this->assertEquals(
      $this->placeholders['call:storno'],
      $this->getContractActivity($storno, 'Contract_Cancelled')[self::PARENT_ACTIVITY_FIELD]
    );
    // without "Stornogrund", the default reason is used
    $contract = $this->getContract($stornoDefault);
    $this->assertEquals('Cancelled', $contract['status_id:name']);
    $this->assertEquals('XX02', $contract['membership_cancellation.membership_cancel_reason']);
    $this->assertSingleActivity($this->contacts['stornonocontract'], 'Response', '32 Storno SMS');

    $this->assertTrue($this->getContactDetails($this->contacts['deceased'])['is_deceased']);

    $mandate = \Civi\Api4\SepaMandate::get(FALSE)
      ->addSelect('type', 'iban', 'entity_table', 'entity_id')
      ->addWhere('contact_id', '=', $this->contacts['ooff'])
      ->execute()
      ->single();
    $this->assertEquals('OOFF', $mandate['type']);
    $this->assertEquals('DE89370400440532013000', $mandate['iban']);
    $this->assertEquals('civicrm_contribution', $mandate['entity_table']);
    $contribution = \Civi\Api4\Contribution::get(FALSE)
      ->addSelect('total_amount', 'campaign_id', 'financial_type_id:name')
      ->addWhere('id', '=', $mandate['entity_id'])
      ->execute()
      ->single();
    $this->assertEquals(50, $contribution['total_amount']);
    $this->assertEquals($this->campaignId, $contribution['campaign_id']);
    $this->assertEquals('Donation', $contribution['financial_type_id:name']);

    $this->assertEquals(0, \Civi\Api4\SepaMandate::get(FALSE)
      ->addWhere('contact_id', '=', $this->contacts['ooffincomplete'])
      ->execute()
      ->count());
  }

  /**
   * Middle donor projects: "MDUP" behaves like an upgrade without required
   * contract, "MDUM" like a conversion
   */
  public function testMiddleDonor() {
    $this->createTMContact('mdupgrade');
    $this->createTMContact('mdconversion');
    $this->createExistingContract('mdconversion');

    $result = $this->importFixture('GP/TM/TEDIContact/GP_MDUP_tedi_{campaign_code}_20260901_120000_MiddleDonor_Kontakte.csv');
    $this->assertEquals('1 of 1 records imported.', $result['values']);
    $this->assertEquals(600, $this->getSingleContract('mdupgrade')['membership_payment.membership_annual']);

    $result = $this->importFixture('GP/TM/TEDIContact/GP_MDUM_tedi_{campaign_code}_20260901_120000_MiddleDonor_Kontakte.csv');
    $this->assertEquals('0 of 1 records imported.', $result['values']);
    $this->assertEquals(["Conversion projects shouldn't provide a contract ID"], $this->getImportErrors());
    $this->assertEquals(120, $this->getSingleContract('mdconversion')['membership_payment.membership_annual']);
  }

  /**
   * Legacy acquisition projects create legacy cases with the status from the
   * response text
   */
  public function testLegacyAcquisition() {
    foreach (['acquired', 'invalidtext', 'hascase'] as $alias) {
      $this->createTMContact($alias);
    }
    $this->createLegacyCase('hascase');

    $result = $this->importFixture('GP/TM/TEDIContact/GP_LAC2026_tedi_{campaign_code}_20260901_120000_Legacy_Kontakte.csv');

    $this->assertEquals('1 of 2 records imported.', $result['values']);
    $this->assertImportErrors([
      "Invalid ErgebnisText format for Ergebnisnummer 102: expected 'teilgenommen Legacy <CaseStatusLabel>', got 'irgendwas'",
      'Legacy Acquisition import failed: Contact already has a legacy case (found 1)',
    ], $this->getImportErrors());

    $case = $this->getLegacyCase('acquired');
    $this->assertEquals('legacy_interested', $case['status_id:name']);
    $this->assertEquals('TM Akquise', $case['subject']);
    $this->assertEquals('2026-09-01', $case['start_date']);
    $openCase = $this->getCaseActivities($case['id'], 'Open Case');
    $this->assertCount(1, $openCase);
    // channel "TM_Akquise"
    $this->assertEquals(3, $openCase[0]['Communication_Channel.Channel']);
    $this->assertSingleActivity($this->contacts['acquired'], 'Response', '101 teilgenommen Legacy Interessiert');

    $this->assertEmpty($this->getLegacyCases('invalidtext'));
    $this->assertCount(1, $this->getLegacyCases('hascase'));
  }

  /**
   * Legacy qualification projects require a legacy case and can update it
   */
  public function testLegacyQualification() {
    \Civi\Api4\Tag::create(FALSE)
      ->addValue('name', 'Task Category: Legacy Freie Bemerkung')
      ->addValue('used_for', ['civicrm_activity'])
      ->execute();
    foreach (['statuschange', 'guide', 'nocase'] as $alias) {
      $this->createTMContact($alias);
    }
    $statusChangeCase = $this->createLegacyCase('statuschange');
    $guideCase = $this->createLegacyCase('guide');

    $result = $this->importFixture('GP/TM/TEDIContact/GP_LEG2026_tedi_{campaign_code}_20260901_120000_Legacy_Kontakte.csv');

    $this->assertEquals('2 of 3 records imported.', $result['values']);
    $this->assertEquals(['Legacy contact should have exactly one legacy case, found 0'], $this->getImportErrors());

    // "Statusänderung:<status>" changes the case status
    $this->assertEquals('legacy_declined', $this->getLegacyCase('statuschange')['status_id:name']);
    $statusChanges = $this->getCaseActivities($statusChangeCase, 'Change Case Status');
    $this->assertCount(1, $statusChanges);
    $this->assertEquals('Case status changed from Interessiert to Abgelehnt', $statusChanges[0]['subject']);
    // channel "TM_Qualifizierung"
    $this->assertEquals(7, $statusChanges[0]['Communication_Channel.Channel']);

    $guides = $this->getCaseActivities($guideCase, 'Ratgeber verschickt');
    $this->assertCount(1, $guides);
    $this->assertEquals('Scheduled', $guides[0]['status_id:name']);
    $this->assertEquals('2026-09-01 14:30:00', $guides[0]['activity_date_time']);
    $this->assertEquals(7, $guides[0]['Communication_Channel.Channel']);
    $task = $this->assertSingleActivity($this->contacts['guide'], 'manual_update_required', 'Bitte anrufen');
    $this->assertEquals(['Task Category: Legacy Freie Bemerkung'], $this->getActivityTags($task['id']));

    $this->assertEmpty($this->getActivities($this->contacts['nocase'], 'Response'));
  }

  /**
   * Files with unknown project types are rejected
   */
  public function testUnknownProjectType() {
    $this->createTMContact('unknownproject');

    $result = $this->importFixture('GP/TM/TEDIContact/GP_XYZ2026_tedi_{campaign_code}_20260901_120000_Unknown_Kontakte.csv', 'failed_folder');

    $this->assertEquals(1, $result['is_error']);
    $this->assertEquals(['Unknown project type xyz. Processing stopped.'], $this->getImportErrors());
    $this->assertEmpty($this->getActivities($this->contacts['unknownproject'], 'Response'));
  }

  /**
   * Create a contact with an outgoing call in the test campaign that serves as
   * fallback parent activity, available as {call:ALIAS} placeholder
   *
   * @param string $alias
   * @param array|null $address
   * @param array $contact
   *
   * @return int contact ID
   */
  private function createTMContact($alias, ?array $address = [], array $contact = []) {
    $contactId = $this->createContact($alias, $address, $contact);
    $this->placeholders["call:{$alias}"] = $this->createOutgoingCallActivity($contactId, '2026-08-15 10:00:00');
    return $contactId;
  }

  /**
   * @param int $activityId
   *
   * @return array
   */
  private function getOutgoingCall($activityId) {
    return \Civi\Api4\Activity::get(FALSE)
      ->addSelect('status_id:name', 'activity_tmresponses.response:label', 'activity_tmresponses.response_date', 'activity_tmresponses.response_counter')
      ->addWhere('id', '=', $activityId)
      ->execute()
      ->single();
  }

  /**
   * @param int $activityId
   *
   * @return array assignee contact IDs
   */
  private function getAssignees($activityId) {
    return \Civi\Api4\ActivityContact::get(FALSE)
      ->addWhere('activity_id', '=', $activityId)
      ->addWhere('record_type_id:name', '=', 'Activity Assignees')
      ->execute()
      ->column('contact_id');
  }

  /**
   * @param int $contactId
   *
   * @return array
   */
  private function getContactDetails($contactId) {
    return \Civi\Api4\Contact::get(FALSE)
      ->addSelect('*', 'prefix_id:name', 'additional_demographics.dm_restrictions')
      ->addWhere('id', '=', $contactId)
      ->execute()
      ->single();
  }

  /**
   * @param int $contactId
   *
   * @return array
   */
  private function getEmails($contactId) {
    return \Civi\Api4\Email::get(FALSE)
      ->addWhere('contact_id', '=', $contactId)
      ->addWhere('is_primary', '=', TRUE)
      ->execute()
      ->column('email');
  }

  /**
   * @param int $contactId
   *
   * @return array
   */
  private function getPrimaryAddress($contactId) {
    $address = \Civi\Api4\Address::get(FALSE)
      ->addSelect('street_address', 'postal_code', 'city', 'country_id:abbr', 'location_type_id:name')
      ->addWhere('contact_id', '=', $contactId)
      ->addWhere('is_primary', '=', TRUE)
      ->execute()
      ->single();
    unset($address['id']);
    return $address;
  }

  /**
   * @param int $contactId
   * @param int $count
   */
  private function setRTSCounter($contactId, $count) {
    \Civi\Api4\Address::update(FALSE)
      ->addValue(self::RTS_COUNTER_FIELD, $count)
      ->addWhere('contact_id', '=', $contactId)
      ->addWhere('is_primary', '=', TRUE)
      ->execute();
  }

  /**
   * Create an active SEPA contract for the contact with the given alias,
   * available as {contract:ALIAS} placeholder
   *
   * @param string $alias
   *
   * @return int contract (membership) ID
   */
  private function createExistingContract($alias) {
    $contactId = $this->contacts[$alias];
    $iban = 'AT611904300234573201';
    $contractId = civicrm_api3('Contract', 'create', [
      'contact_id'                        => $contactId,
      'membership_type_id'                => \Civi\Api4\MembershipType::get(FALSE)
        ->addWhere('name', '=', 'Förderer')
        ->execute()
        ->single()['id'],
      'join_date'                         => '2025-01-01',
      'start_date'                        => '2025-01-01',
      'campaign_id'                       => $this->campaignId,
      'membership_payment.from_ba'        => CRM_Contract_BankingLogic::getOrCreateBankAccount($contactId, $iban),
      'membership_payment.to_ba'          => CRM_Contract_BankingLogic::getCreditorBankAccount(),
      'payment_method.adapter'            => 'sepa_mandate',
      'payment_method.amount'             => 10,
      'payment_method.campaign_id'        => $this->campaignId,
      'payment_method.contact_id'         => $contactId,
      'payment_method.currency'           => 'EUR',
      'payment_method.financial_type_id'  => \Civi\Api4\FinancialType::get(FALSE)
        ->addWhere('name', '=', 'Member Dues')
        ->execute()
        ->single()['id'],
      'payment_method.frequency_interval' => 1,
      'payment_method.frequency_unit'     => 'month',
      'payment_method.iban'               => $iban,
      'payment_method.type'               => 'RCUR',
    ])['id'];
    $this->placeholders["contract:{$alias}"] = $contractId;
    return $contractId;
  }

  /**
   * @param int $contractId
   */
  private function cancelExistingContract($contractId) {
    civicrm_api3('Contract', 'modify', [
      'id'                                               => $contractId,
      'action'                                           => 'cancel',
      'medium_id'                                        => 'phone',
      'membership_cancellation.membership_cancel_reason' => 'XX02',
    ]);
    civicrm_api3('Contract', 'process_scheduled_modifications', []);
    $this->assertEquals('Cancelled', $this->getContract($contractId)['status_id:name']);
  }

  /**
   * @param string $alias
   *
   * @return array contracts of the contact
   */
  private function getContracts($alias) {
    return \Civi\Api4\Membership::get(FALSE)
      ->addSelect('id', 'campaign_id', 'membership_type_id:name', 'status_id:name', 'membership_payment.membership_annual', 'membership_payment.membership_frequency', 'membership_cancellation.membership_cancel_reason')
      ->addWhere('contact_id', '=', $this->contacts[$alias])
      ->execute()
      ->getArrayCopy();
  }

  /**
   * @param string $alias
   *
   * @return array the contact's only contract
   */
  private function getSingleContract($alias) {
    $contracts = $this->getContracts($alias);
    $this->assertCount(1, $contracts);
    return reset($contracts);
  }

  /**
   * @param int $contractId
   *
   * @return array
   */
  private function getContract($contractId) {
    return \Civi\Api4\Membership::get(FALSE)
      ->addSelect('id', 'membership_type_id:name', 'status_id:name', 'membership_payment.membership_annual', 'membership_payment.membership_frequency', 'membership_cancellation.membership_cancel_reason')
      ->addWhere('id', '=', $contractId)
      ->execute()
      ->single();
  }

  /**
   * @param int $contractId
   * @param string $activityType contract change activity type
   *
   * @return array|null the most recent contract change activity of the given type
   */
  private function getContractActivity($contractId, $activityType) {
    return \Civi\Api4\Activity::get(FALSE)
      ->addSelect('id', self::PARENT_ACTIVITY_FIELD)
      ->addWhere('source_record_id', '=', $contractId)
      ->addWhere('activity_type_id:name', '=', $activityType)
      ->addOrderBy('id', 'DESC')
      ->execute()
      ->first();
  }

  /**
   * @param string $alias
   *
   * @return int case ID
   */
  private function createLegacyCase($alias) {
    return \Civi\Api4\CiviCase::create(FALSE)
      ->addValue('contact_id', $this->contacts[$alias])
      ->addValue('creator_id', $this->adminContactId)
      ->addValue('case_type_id:name', 'legat')
      ->addValue('status_id:name', 'legacy_interested')
      ->addValue('subject', 'Legat')
      ->addValue('start_date', '2026-01-01')
      ->execute()
      ->first()['id'];
  }

  /**
   * @param string $alias
   *
   * @return array
   */
  private function getLegacyCases($alias) {
    return \Civi\Api4\CiviCase::get(FALSE)
      ->addSelect('id', 'subject', 'start_date', 'status_id:name')
      ->addJoin('CaseContact AS case_contact', 'INNER', ['id', '=', 'case_contact.case_id'])
      ->addWhere('case_contact.contact_id', '=', $this->contacts[$alias])
      ->addWhere('case_type_id:name', '=', 'legat')
      ->execute()
      ->getArrayCopy();
  }

  /**
   * @param string $alias
   *
   * @return array the contact's only legacy case
   */
  private function getLegacyCase($alias) {
    $cases = $this->getLegacyCases($alias);
    $this->assertCount(1, $cases);
    return reset($cases);
  }

  /**
   * @param int $caseId
   * @param string $activityType
   *
   * @return array
   */
  private function getCaseActivities($caseId, $activityType) {
    return \Civi\Api4\Activity::get(FALSE)
      ->addSelect('id', 'subject', 'status_id:name', 'activity_date_time', 'Communication_Channel.Channel')
      ->addJoin('CaseActivity AS case_activity', 'INNER', ['case_activity.activity_id', '=', 'id'])
      ->addWhere('case_activity.case_id', '=', $caseId)
      ->addWhere('activity_type_id:name', '=', $activityType)
      ->execute()
      ->getArrayCopy();
  }

  /**
   * @param string $title
   *
   * @return int group ID
   */
  private function createGroup($title) {
    return \Civi\Api4\Group::create(FALSE)
      ->addValue('title', $title)
      ->addValue('name', $title)
      ->execute()
      ->first()['id'];
  }

  /**
   * @param string $alias
   * @param int $groupId
   */
  private function addToGroup($alias, $groupId) {
    \Civi\Api4\GroupContact::create(FALSE)
      ->addValue('contact_id', $this->contacts[$alias])
      ->addValue('group_id', $groupId)
      ->addValue('status', 'Added')
      ->execute();
  }

  /**
   * @param string $alias
   *
   * @return array group membership status by group ID
   */
  private function getGroupStatuses($alias) {
    $statuses = \Civi\Api4\GroupContact::get(FALSE)
      ->addSelect('group_id', 'status')
      ->addWhere('contact_id', '=', $this->contacts[$alias])
      ->execute()
      ->column('status', 'group_id');
    ksort($statuses);
    return $statuses;
  }

  /**
   * @param string $alias
   * @param array $values
   */
  private function updateContact($alias, array $values) {
    \Civi\Api4\Contact::update(FALSE)
      ->setValues($values)
      ->addWhere('id', '=', $this->contacts[$alias])
      ->execute();
  }

  /**
   * @param string $alias
   *
   * @return array the contact's only webshop order
   */
  private function getWebshopOrder($alias) {
    return \Civi\Api4\Activity::get(FALSE)
      ->addSelect('subject', 'status_id:name', 'webshop_information.*')
      ->addJoin('ActivityContact AS ac', 'INNER', ['ac.activity_id', '=', 'id'])
      ->addWhere('ac.contact_id', '=', $this->contacts[$alias])
      ->addWhere('activity_type_id:name', '=', 'Webshop Order')
      ->execute()
      ->single();
  }

  /**
   * @param int $activityId
   *
   * @return array tag names
   */
  private function getActivityTags($activityId) {
    return \Civi\Api4\EntityTag::get(FALSE)
      ->addSelect('tag_id:name')
      ->addWhere('entity_table', '=', 'civicrm_activity')
      ->addWhere('entity_id', '=', $activityId)
      ->execute()
      ->column('tag_id:name');
  }

  /**
   * @param int $contactId
   *
   * @return array tag names
   */
  private function getTags($contactId) {
    return \Civi\Api4\EntityTag::get(FALSE)
      ->addSelect('tag_id:name')
      ->addWhere('entity_table', '=', 'civicrm_contact')
      ->addWhere('entity_id', '=', $contactId)
      ->execute()
      ->column('tag_id:name');
  }

}
