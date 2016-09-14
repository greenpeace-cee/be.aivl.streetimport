<?php

require_once 'CRM/Core/Form.php';

/**
 * Form controller class.
 *
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC43/QuickForm+Reference
 */
class CRM_Streetimport_Update_Form_Result extends CRM_Streetimport_Update_Form_Base
{

    public function buildQuickForm()
    {
        $this->assign('contacts', $this->get('contacts'));
        $this->addButtons(array(
        array(
          'type' => 'submit',
          'name' => ts('OK'),
          'isDefault' => true,
        ),
    ));

    // export form elements

    parent::buildQuickForm();
    }

    public function postProcess()
    {
      CRM_Utils_System::redirect('civicrm/streetimport/update');
    }

    public function getColorOptions()
    {
        $options = array(
      '' => ts('- select -'),
      '#f00' => ts('Red'),
      '#0f0' => ts('Green'),
      '#00f' => ts('Blue'),
      '#f0f' => ts('Purple'),
    );
        foreach (array('1', '2', '3', '4', '5', '6', '7', '8', '9', 'a', 'b', 'c', 'd', 'e') as $f) {
            $options["#{$f}{$f}{$f}"] = ts('Grey (%1)', array(1 => $f));
        }

        return $options;
    }

  /**
   * Get the fields/elements defined in this form.
   *
   * @return array (string)
   */
  public function getRenderableElementNames()
  {
      // The _elements list includes some items which should not be
    // auto-rendered in the loop -- such as "qfKey" and "buttons".  These
    // items don't have labels.  We'll identify renderable by filtering on
    // the 'label'.
    $elementNames = array();
      foreach ($this->_elements as $element) {
          /* @var HTML_QuickForm_Element $element */
      $label = $element->getLabel();
          if (!empty($label)) {
              $elementNames[] = $element->getName();
          }
      }

      return $elementNames;
  }
}
