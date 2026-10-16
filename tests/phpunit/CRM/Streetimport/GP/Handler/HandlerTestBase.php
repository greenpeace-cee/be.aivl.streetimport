<?php

use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * Base class for GP handler tests
 *
 * Fixtures are read from tests/fixtures. Their content and file name may
 * contain the placeholders:
 *  - {campaign}:  the test campaign ID, zero-padded to 5 digits
 *  - {campaign_code}: the TM campaign code for the test campaign (C + 4 digits)
 *  - {contact:ALIAS}: the ID of the contact created via createContact('ALIAS'),
 *                     zero-padded to 9 digits
 *  - {ref:ALIAS}: a postal return reference (campaign + contact ID) for the
 *                 contact created via createContact('ALIAS')
 *  - {NAME}: any value registered in $this->placeholders['NAME'], e.g.
 *            {id:ALIAS} for contact IDs and {phone:ALIAS} for phone IDs
 *
 * @group headless
 */
abstract class CRM_Streetimport_GP_Handler_HandlerTestBase extends TestCase implements HeadlessInterface, TransactionalInterface {

  const RTS_COUNTER_FIELD = 'address_statistics.rts_counter';
  const PARENT_ACTIVITY_FIELD = 'activity_hierarchy.parent_activity_id';

  /**
   * @var int
   */
  protected $adminContactId;

  /**
   * @var int
   */
  protected $campaignId;

  /**
   * Contact IDs by alias
   *
   * @var array
   */
  protected $contacts = [];

  /**
   * Additional fixture placeholder values by name
   *
   * @var array
   */
  protected $placeholders = [];

  /**
   * Base directory containing the import folders for this test
   *
   * @var string
   */
  protected $importDir;

  public function setUpHeadless() {
    return \Civi\Test::headless()
      ->install([
        'de.systopia.identitytracker',
        'org.project60.banking',
        'org.project60.sepa',
        'de.systopia.contract',
        'de.systopia.contactinactive',
        'greenpeace_contribute',
      ])
      ->installMe(__DIR__)
      ->callback([self::class, 'createDataStructures'], 'streetimport-gp-data-structures-v6')
      ->apply();
  }

  /**
   * Create data structures the GP handlers expect to be provided by other
   * extensions or site configuration
   */
  public static function createDataStructures() {
    CRM_Core_BAO_ConfigSetting::enableComponent('CiviCampaign');
    CRM_Core_BAO_ConfigSetting::enableComponent('CiviCase');

    foreach (['Response', 'Action', 'Outgoing Call', 'Webshop Order', 'Ratgeber verschickt'] as $activityType) {
      \Civi\Api4\OptionValue::create(FALSE)
        ->addValue('option_group_id:name', 'activity_type')
        ->addValue('name', $activityType)
        ->addValue('label', $activityType)
        ->execute();
    }

    \Civi\Api4\OptionGroup::create(FALSE)
      ->addValue('name', 'response')
      ->addValue('title', 'TM Response')
      ->execute();

    self::createCustomGroup('address_statistics', 'Address', [
      ['name' => 'rts_counter', 'data_type' => 'Int', 'html_type' => 'Text'],
    ]);
    self::createCustomGroup('activity_hierarchy', 'Activity', [
      ['name' => 'parent_activity_id', 'data_type' => 'Int', 'html_type' => 'Text'],
    ]);
    self::createCustomGroup('additional_demographics', 'Contact', [
      ['name' => 'birth_year', 'data_type' => 'Int', 'html_type' => 'Text'],
      ['name' => 'dm_restrictions', 'data_type' => 'String', 'html_type' => 'Text'],
    ]);
    self::createCustomGroup('webshop_information', 'Activity', [
      ['name' => 'order_type', 'data_type' => 'String', 'html_type' => 'Text'],
      ['name' => 'order_count', 'data_type' => 'Int', 'html_type' => 'Text'],
      ['name' => 'shirt_type', 'data_type' => 'String', 'html_type' => 'Text'],
      ['name' => 'shirt_size', 'data_type' => 'String', 'html_type' => 'Text'],
      ['name' => 'linked_membership', 'data_type' => 'Int', 'html_type' => 'Text'],
      ['name' => 'free_order', 'data_type' => 'Boolean', 'html_type' => 'Radio'],
    ]);
    self::createCustomGroup('activity_tmresponses', 'Activity', [
      ['name' => 'response', 'data_type' => 'String', 'html_type' => 'Select', 'option_group_id:name' => 'response'],
      ['name' => 'response_date', 'data_type' => 'Date', 'html_type' => 'Select Date'],
      ['name' => 'response_counter', 'data_type' => 'Int', 'html_type' => 'Text'],
    ]);
    self::createCustomGroup('Communication_Channel', 'Activity', [
      ['name' => 'Channel', 'data_type' => 'Int', 'html_type' => 'Text'],
    ]);
    // the Dialoger contact sub type is provided by de.systopia.contract
    self::createCustomGroup('dialoger_data', 'Individual', [
      ['name' => 'dialoger_id', 'data_type' => 'String', 'html_type' => 'Text'],
    ], ['Dialoger']);

    foreach (['legacy_interested' => 'Interessiert', 'legacy_declined' => 'Abgelehnt'] as $name => $label) {
      \Civi\Api4\OptionValue::create(FALSE)
        ->addValue('option_group_id:name', 'case_status')
        ->addValue('name', $name)
        ->addValue('label', $label)
        ->addValue('grouping', 'Opened')
        ->execute();
    }
    \Civi\Api4\CaseType::create(FALSE)
      ->addValue('name', 'legat')
      ->addValue('title', 'Legat')
      ->addValue('definition', [
        'activityTypes' => [
          ['name' => 'Open Case', 'max_instances' => 1],
          ['name' => 'Change Case Status'],
        ],
        'activitySets' => [
          [
            'name' => 'standard_timeline',
            'label' => 'Standard Timeline',
            'timeline' => 1,
            'activityTypes' => [['name' => 'Open Case', 'status' => 'Completed']],
          ],
        ],
        'caseRoles' => [],
      ])
      ->execute();

    foreach (['Förderer', 'Guardian of the Ocean'] as $membershipType) {
      \Civi\Api4\MembershipType::create(FALSE)
        ->addValue('name', $membershipType)
        ->addValue('member_of_contact_id', 1)
        ->addValue('financial_type_id:name', 'Member Dues')
        ->addValue('duration_unit', 'lifetime')
        ->addValue('duration_interval', 1)
        ->addValue('period_type', 'rolling')
        ->execute();
    }

    foreach (['XX02', 'XX13', 'MS02'] as $cancelReason) {
      \Civi\Api4\OptionValue::create(FALSE)
        ->addValue('option_group_id:name', 'contract_cancel_reason')
        ->addValue('name', $cancelReason)
        ->addValue('label', $cancelReason)
        ->addValue('value', $cancelReason)
        ->execute();
    }

    // same creditor setup as org.project60.sepa's tests
    CRM_Sepa_BAO_SEPACreditor::addDefaultCreditorIfMissing();
    $creditorId = \Civi\Api4\SepaCreditor::get(FALSE)
      ->addSelect('id')
      ->execute()
      ->first()['id'];
    \Civi\Api4\SepaCreditor::update(FALSE)
      ->addValue('uses_bic', FALSE)
      // must not be a test creditor
      ->addValue('category', NULL)
      ->addWhere('id', '=', $creditorId)
      ->execute();
    CRM_Sepa_Logic_Settings::setSetting($creditorId, 'batching_default_creditor');
    // de.systopia.contract caches the creditor's bank account ID, so make sure
    // it's not created (and rolled back) within a test
    CRM_Contract_BankingLogic::getCreditorBankAccount();
  }

  /**
   * @param string $name
   * @param string $extends
   * @param array $fields field definitions, labels default to the name
   * @param array|null $extendsEntityColumnValue
   */
  private static function createCustomGroup($name, $extends, array $fields, ?array $extendsEntityColumnValue = NULL) {
    $customGroup = \Civi\Api4\CustomGroup::create(FALSE)
      ->addValue('name', $name)
      ->addValue('title', $name)
      ->addValue('extends', $extends)
      ->addValue('extends_entity_column_value', $extendsEntityColumnValue)
      ->execute()
      ->first();
    foreach ($fields as $field) {
      \Civi\Api4\CustomField::create(FALSE)
        ->setValues($field + [
          'custom_group_id' => $customGroup['id'],
          'label'           => $field['name'],
        ])
        ->execute();
    }
  }

  public function setUp(): void {
    parent::setUp();

    // the config singleton caches the domain, settings and custom field IDs
    $this->resetStaticProperty(CRM_Streetimport_Config::class, '_singleton');
    $this->resetStaticProperty(CRM_Streetimport_Config::class, '_domain');
    Civi::settings()->set('streetimporter_domain', 'GP');

    $this->adminContactId = civicrm_api3('Contact', 'create', [
      'contact_type' => 'Individual',
      'first_name'   => 'Import',
      'last_name'    => 'Admin',
    ])['id'];
    // imports run as a user, activities are created with them as source contact
    CRM_Core_Session::singleton()->set('userID', $this->adminContactId);
    Civi::settings()->set('streetimporter_settings', [
      'GP' => [
        'admin_id'            => $this->adminContactId,
        'fundraiser_id'       => $this->adminContactId,
        'location_type_id'    => CRM_Core_PseudoConstant::getKey('CRM_Core_BAO_Address', 'location_type_id', 'Home'),
        'phone_phone_type_id' => CRM_Core_PseudoConstant::getKey('CRM_Core_BAO_Phone', 'phone_type_id', 'Phone'),
      ],
    ]);

    $this->campaignId = civicrm_api3('Campaign', 'create', [
      'title' => 'Test Mailing',
      'name'  => 'test_mailing',
    ])['id'];

    $this->importDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('streetimport-test-', TRUE);
    foreach ($this->getImportFolders() as $folder) {
      mkdir($folder, 0777, TRUE);
    }
  }

  public function tearDown(): void {
    $this->contacts = [];
    $this->placeholders = [];
    if (!empty($this->importDir) && is_dir($this->importDir)) {
      $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->importDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
      );
      foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
      }
      rmdir($this->importDir);
    }
    parent::tearDown();
  }

  /**
   * @return array folder paths by Streetimport.importcsvfile parameter
   */
  protected function getImportFolders() {
    return [
      'source_folder'     => $this->importDir . DIRECTORY_SEPARATOR . 'source',
      'processing_folder' => $this->importDir . DIRECTORY_SEPARATOR . 'processing',
      'archive_folder'    => $this->importDir . DIRECTORY_SEPARATOR . 'processed',
      'failed_folder'     => $this->importDir . DIRECTORY_SEPARATOR . 'failed',
    ];
  }

  /**
   * Run a CSV fixture through Streetimport.importcsvfile
   *
   * @param string $fixture path relative to tests/fixtures. The file name is
   *   kept (with placeholders replaced), since handlers are selected based on it.
   * @param string $expectedFolder the folder the file should end up in,
   *   'archive_folder' or 'failed_folder'
   *
   * @return array API result
   */
  protected function importFixture($fixture, $expectedFolder = 'archive_folder') {
    $content = file_get_contents(__DIR__ . '/../../../../../fixtures/' . $fixture);
    $this->assertNotFalse($content, "Fixture {$fixture} not found");
    $fileName = $this->replacePlaceholders(basename($fixture));

    $folders = $this->getImportFolders();
    $file = $folders['source_folder'] . DIRECTORY_SEPARATOR . $fileName;
    file_put_contents($file, $this->replacePlaceholders($content));

    // the importer logs to error_log() in addition to the import log file,
    // silence it to keep test output readable
    $errorLog = ini_set('error_log', '/dev/null');
    try {
      $result = civicrm_api('Streetimport', 'importcsvfile', $folders + [
        'version'  => 3,
        'filepath' => $file,
      ]);
    }
    finally {
      ini_set('error_log', $errorLog);
    }
    $log = implode("\n", array_map('file_get_contents', glob($folders['failed_folder'] . DIRECTORY_SEPARATOR . '*.log')));
    $this->assertFileExists(
      $folders[$expectedFolder] . DIRECTORY_SEPARATOR . $fileName,
      "Import file should be moved to {$expectedFolder}. Import log:\n{$log}"
    );
    return $result;
  }

  /**
   * @param string $text
   *
   * @return string
   */
  protected function replacePlaceholders($text) {
    $text = preg_replace_callback('/\{ref:(\w+)\}/', function ($matches) {
      return $this->getReference($matches[1]);
    }, $text);
    $text = preg_replace_callback('/\{contact:(\w+)\}/', function ($matches) {
      $this->assertArrayHasKey($matches[1], $this->contacts, "Unknown contact alias '{$matches[1]}'");
      return sprintf('%09d', $this->contacts[$matches[1]]);
    }, $text);
    $placeholders = [
      '{campaign}'      => sprintf('%05d', $this->campaignId),
      '{campaign_code}' => sprintf('C%04d', $this->campaignId),
    ];
    foreach ($this->placeholders as $name => $value) {
      $placeholders['{' . $name . '}'] = $value;
    }
    $text = strtr($text, $placeholders);
    $this->assertDoesNotMatchRegularExpression('/\{[\w:]+\}/', $text, 'Unknown placeholder');
    return $text;
  }

  /**
   * Postal return reference for the contact with the given alias
   *
   * @param string $alias
   *
   * @return string
   */
  protected function getReference($alias) {
    $this->assertArrayHasKey($alias, $this->contacts, "Unknown contact alias '{$alias}'");
    return sprintf('1%05d%09d', $this->campaignId, $this->contacts[$alias]);
  }

  /**
   * Create an individual with a primary address
   *
   * @param string $alias
   * @param array|null $address address overrides, or NULL for no address
   * @param array $contact contact overrides
   *
   * @return int contact ID, also available as {id:ALIAS} placeholder
   */
  protected function createContact($alias, ?array $address = [], array $contact = []) {
    $contactId = civicrm_api3('Contact', 'create', $contact + [
      'contact_type' => 'Individual',
      'first_name'   => ucfirst($alias),
      'last_name'    => 'Doe',
    ])['id'];
    if (!is_null($address)) {
      civicrm_api3('Address', 'create', $address + [
        'contact_id'       => $contactId,
        'location_type_id' => 1,
        'is_primary'       => 1,
        'street_address'   => 'Hauptstraße 1',
        'postal_code'      => '1010',
        'city'             => 'Wien',
        'country_id'       => 'AT',
      ]);
    }
    $this->addContactAlias($alias, $contactId);
    return $contactId;
  }

  /**
   * Make an existing contact available via alias and placeholders
   *
   * @param string $alias
   * @param int $contactId
   */
  protected function addContactAlias($alias, $contactId) {
    $this->contacts[$alias] = $contactId;
    $this->placeholders["id:{$alias}"] = $contactId;
  }

  /**
   * Create a phone for the contact with the given alias
   *
   * @param string $alias
   * @param string $phone
   *
   * @return int phone ID, also available as {phone:ALIAS} placeholder
   */
  protected function createPhone($alias, $phone) {
    $phoneId = civicrm_api3('Phone', 'create', [
      'contact_id'       => $this->contacts[$alias],
      'phone'            => $phone,
      'location_type_id' => 1,
      'phone_type_id'    => 'Phone',
    ])['id'];
    $this->placeholders["phone:{$alias}"] = $phoneId;
    return $phoneId;
  }

  /**
   * Create a letter mail activity the RTS activity can be linked to
   *
   * @param int $contactId
   * @param string $date
   *
   * @return int activity ID
   */
  protected function createLetterActivity($contactId, $date = '2026-08-01 10:00:00') {
    return civicrm_api3('Activity', 'create', [
      'activity_type_id'   => 'Print PDF Letter',
      'source_contact_id'  => $this->adminContactId,
      'target_id'          => $contactId,
      'campaign_id'        => $this->campaignId,
      'medium_id'          => 'letter_mail',
      'status_id'          => 'Completed',
      'activity_date_time' => $date,
      'subject'            => 'Test Letter',
    ])['id'];
  }

  /**
   * Create an outgoing call (TM) activity
   *
   * @param int $contactId
   * @param string $date
   * @param string $activityType 'Outgoing Call' or 'Action'
   *
   * @return int activity ID
   */
  protected function createOutgoingCallActivity($contactId, $date, $activityType = 'Outgoing Call') {
    return civicrm_api3('Activity', 'create', [
      'activity_type_id'   => $activityType,
      'source_contact_id'  => $this->adminContactId,
      'target_id'          => $contactId,
      'campaign_id'        => $this->campaignId,
      'medium_id'          => 'phone',
      'status_id'          => 'Scheduled',
      'activity_date_time' => $date,
      'subject'            => 'Test Call',
    ])['id'];
  }

  /**
   * Create a previous RTS activity
   *
   * @param int $contactId
   * @param string $subject
   * @param string $date
   *
   * @return int activity ID
   */
  protected function createRTSActivity($contactId, $subject, $date) {
    return civicrm_api3('Activity', 'create', [
      'activity_type_id'   => 'Response',
      'source_contact_id'  => $this->adminContactId,
      'target_id'          => $contactId,
      'campaign_id'        => $this->campaignId,
      'medium_id'          => 'letter_mail',
      'status_id'          => 'Completed',
      'activity_date_time' => $date,
      'subject'            => $subject,
    ])['id'];
  }

  /**
   * Get all activities of the given type targeting the given contact
   *
   * @param int $contactId
   * @param string $activityType activity type name
   *
   * @return array
   */
  protected function getActivities($contactId, $activityType) {
    return \Civi\Api4\Activity::get(FALSE)
      ->addSelect('id', 'subject', 'details', 'activity_date_time', 'campaign_id', 'medium_id:name', 'status_id:name', self::PARENT_ACTIVITY_FIELD)
      ->addJoin('ActivityContact AS ac', 'INNER', ['ac.activity_id', '=', 'id'])
      ->addWhere('ac.contact_id', '=', $contactId)
      ->addWhere('ac.record_type_id:name', '=', 'Activity Targets')
      ->addWhere('activity_type_id:name', '=', $activityType)
      ->addOrderBy('activity_date_time')
      ->addOrderBy('id')
      ->execute()
      ->getArrayCopy();
  }

  /**
   * Get all RTS (Response) activities targeting the given contact
   *
   * @param int $contactId
   *
   * @return array
   */
  protected function getRTSActivities($contactId) {
    return $this->getActivities($contactId, 'Response');
  }

  /**
   * Assert the contact has exactly one activity of the given type and return it
   *
   * @param int $contactId
   * @param string $activityType activity type name
   * @param string $subject
   *
   * @return array
   */
  protected function assertSingleActivity($contactId, $activityType, $subject) {
    $activities = $this->getActivities($contactId, $activityType);
    $this->assertCount(1, $activities, "Contact {$contactId} should have exactly one '{$activityType}' activity");
    $activity = reset($activities);
    $this->assertEquals($subject, $activity['subject']);
    $this->assertEquals($this->campaignId, $activity['campaign_id']);
    return $activity;
  }

  /**
   * Get phone numbers of the given contact by phone ID
   *
   * @param int $contactId
   *
   * @return array
   */
  protected function getPhones($contactId) {
    return \Civi\Api4\Phone::get(FALSE)
      ->addSelect('phone', 'phone_type_id:name', 'location_type_id:name')
      ->addWhere('contact_id', '=', $contactId)
      ->execute()
      ->indexBy('id')
      ->getArrayCopy();
  }

  /**
   * Assert the contact has exactly one RTS activity created by the import
   * and return it
   *
   * @param int $contactId
   * @param string $subject
   * @param string|null $date expected activity date, or NULL to skip the check
   *
   * @return array
   */
  protected function assertSingleRTSActivity($contactId, $subject, $date = NULL) {
    $activity = $this->assertSingleActivity($contactId, 'Response', $subject);
    $this->assertEquals('letter_mail', $activity['medium_id:name']);
    $this->assertEquals('Completed', $activity['status_id:name']);
    if (!is_null($date)) {
      $this->assertEquals($date, $activity['activity_date_time']);
    }
    return $activity;
  }

  /**
   * @param int $contactId
   *
   * @return int
   */
  protected function getRTSCounter($contactId) {
    $address = \Civi\Api4\Address::get(FALSE)
      ->addSelect(self::RTS_COUNTER_FIELD)
      ->addWhere('contact_id', '=', $contactId)
      ->addWhere('is_primary', '=', TRUE)
      ->execute()
      ->single();
    return (int) $address[self::RTS_COUNTER_FIELD];
  }

  /**
   * @param int $contactId
   *
   * @return array
   */
  protected function getContact($contactId) {
    return \Civi\Api4\Contact::get(FALSE)
      ->addSelect('is_deceased', 'deceased_date', 'is_deleted')
      ->addWhere('id', '=', $contactId)
      ->execute()
      ->single();
  }

  /**
   * Get the subjects of all import error activities
   *
   * @return array
   */
  protected function getImportErrors() {
    return \Civi\Api4\Activity::get(FALSE)
      ->addSelect('subject')
      ->addWhere('activity_type_id:name', '=', 'streetimport_error')
      ->addOrderBy('id')
      ->execute()
      ->column('subject');
  }

  /**
   * Assert the import errors match the given messages
   *
   * Error activity subjects are truncated to 64 characters.
   *
   * @param array $messages
   */
  protected function assertImportErrors(array $messages) {
    $this->assertEquals(
      array_map(function ($message) {
        return substr($message, 0, 64);
      }, $messages),
      $this->getImportErrors()
    );
  }

  /**
   * Change a (GP domain) streetimport setting
   *
   * @param string $name
   * @param mixed $value
   */
  protected function setStreetimportSetting($name, $value) {
    $settings = Civi::settings()->get('streetimporter_settings');
    $settings['GP'][$name] = $value;
    Civi::settings()->set('streetimporter_settings', $settings);
    $this->resetStaticProperty(CRM_Streetimport_Config::class, '_singleton');
  }

  /**
   * @param string $class
   * @param string $property
   */
  private function resetStaticProperty($class, $property) {
    $reflection = new ReflectionProperty($class, $property);
    $reflection->setAccessible(TRUE);
    $reflection->setValue(NULL, NULL);
  }

}
