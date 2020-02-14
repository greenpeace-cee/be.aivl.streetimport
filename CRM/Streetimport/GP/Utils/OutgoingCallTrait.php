<?php

use Civi\Api4\Contact;
use Civi\Api4\Group;
use Civi\Api4\OptionValue;

trait CRM_Streetimport_GP_Utils_OutgoingCallTrait {

  protected function generateOutgoingCallDetails($description, array $data, $contactId, $contractId) {
    // mapping and order of JSON properties to human-readable labels or callback
    // slightly hacky - maybe replace with a thing where handlers are auto-discovered
    $defaultProperties = [
      'first_name' => function($data) {
        $name = '';
        foreach (['prefix_id', 'formal_title', 'first_name', 'last_name'] as $key) {
          if (!empty($data[$key])) {
            $name .= trim($data[$key]) . ' ';
          }
        }
        return $this->generateTableRow('Name', trim($name));
      },
      'phone' => 'Phone',
      'call_date' => 'Date of Call',
      'response_code' => function($data) {
        if (!empty($data['response_code']) && !empty($data['response'])) {
          return $this->generateTableRow('Response', "{$data['response_code']} {$data['response']}");
        }
      },
      'remark' => 'Remark',
      'email' => 'Email',
      'birth_date' => function($data) {
        if (!empty($data['birth_date'])) {
          return $this->generateTableRow('Date of Birth', $data['birth_date']);
        }
        else if (!empty($data['birth_year'])) {
          return $this->generateTableRow('Year of Birth', $data['birth_year']);
        }
      },
      'street_address' => 'Street Address',
      'postal_code' => 'ZIP Code',
      'city' => 'City',
      'country_id' => 'Country',
      'reset_rts' => 'Address verified?',
      'groups' => function($data) {
        $groups = [];
        foreach ($data['groups'] as $groupName) {
          if (empty($groupName)) {
            continue;
          }
          $group = Group::get()
            ->setSelect([
              'title',
            ])
            ->addWhere('name', '=', $groupName)
            ->execute()
            ->first();
          if (empty($group)) {
            $this->logger->logError("Unknown group '{$groupName}' in JSON.", []);
            continue;
          }
          $groups[] = $group['title'];
        }
        if (!empty($groups)) {
          return $this->generateTableRow('Groups', implode(', ', $groups));
        }
      },
      'contract_id' => 'Contract ID',
      'start_date' => 'Start Date',
      'frequency' => function($data) {
        if (!empty($data['frequency'])) {
          $frequency = OptionValue::get()
            ->setSelect([
              'label',
            ])
            ->addWhere('option_group.name', '=', 'payment_frequency')
            ->addWhere('value', '=', $data['frequency'])
            ->execute()
            ->first();
          if (empty($frequency)) {
            $this->logger->logError("Unknown frequency '{$data['frequency']}' in JSON.", []);
          }
          else {
            return $this->generateTableRow('Frequency', $frequency['label']);
          }
        }
      },
      'annual_amount' => function($data) {
        if (!empty($data['annual_amount'])) {
          return $this->generateTableRow('Annual Amount', CRM_Utils_Money::format($data['annual_amount']));
        }
      },
      'amount' => function($data) {
        if (!empty($data['amount'])) {
          return $this->generateTableRow('Installment Amount', CRM_Utils_Money::format($data['amount']));
        }
      },
      'iban' => 'IBAN',
      'continue_debit' => 'Continue Debit?',
      'additional_actions' => function($data) {
        if (!empty($data['additional_actions']) && is_array($data['additional_actions'])) {
          $additionalActions = [];
          foreach ($data['additional_actions'] as $additionalAction) {
            if (empty($additionalAction)) {
              continue;
            }
            $additionalActions[] = $additionalAction;
          }
          return $this->generateTableRow('Additional Actions', implode('<br>', $additionalActions));
        }
      },
    ];

    $details = "
        <p>{$description}</p>
        <table><thead><tr><th>Field</th><th>Value</th></tr></thead><tbody>";
    foreach ($defaultProperties as $property => $labelOrCallback) {
      if (is_callable($labelOrCallback)) {
        $result = $labelOrCallback($data);
        if (!empty($result)) {
          $details .= $result;
        }
      }
      else {
        if (empty($data[$property])) {
          continue;
        }
        $details .= $this->generateTableRow($labelOrCallback, $data[$property]);
      }
    }
    $details .= '</tbody></table>';
    return $details;
  }

  private function formatValue($value, $raw = FALSE) {
    if (is_bool(($value))) {
      return $value ? 'Yes' : 'No';
    }
    if (is_array($value)) {
      $value = implode('<br>', $value);
    }
    return $raw ? $value : htmlspecialchars($value);
  }

  private function generateTableRow($label, $value, $raw = FALSE) {
    if (!$raw) {
      $label = htmlspecialchars($label);
    }
    return "<tr>
              <td>{$label}</td>
              <td>{$this->formatValue($value, $raw)}</td>
            </tr>";
  }

}
