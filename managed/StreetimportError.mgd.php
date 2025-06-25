<?php

use CRM_Streetimport_ExtensionUtil as E;

return [
  [
    'name' => 'OptionValue_streetimport_error',
    'entity' => 'OptionValue',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'activity_type',
        'label' => E::ts('Import Error'),
        'name' => 'streetimport_error',
      ],
      'match' => [
        'option_group_id',
        'name',
      ],
    ],
  ],
];
