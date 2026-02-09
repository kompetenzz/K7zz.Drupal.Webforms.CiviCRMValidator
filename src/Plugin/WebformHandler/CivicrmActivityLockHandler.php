<?php

namespace Drupal\k7zz_webform_civicrm_activity_lock\Plugin\WebformHandler;

use Drupal\Core\Form\FormStateInterface;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\WebformSubmissionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\k7zz_webform_civicrm_activity_lock\Service\ActivityChecker;
use Drupal\civicrm\Civicrm;

/**
 * Locks webform if CiviCRM activity exists.
 *
 * @WebformHandler(
 *   id = "civicrm_activity_lock",
 *   label = @Translation("CiviCRM Activity Lock"),
 *   category = @Translation("CRM"),
 *   description = @Translation("Lock webform if CiviCRM activity with specific type and status exists for contact or employer"),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_SINGLE,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_IGNORED,
 *   submission = \Drupal\webform\Plugin\WebformHandlerInterface::SUBMISSION_OPTIONAL,
 * )
 */
class CivicrmActivityLockHandler extends WebformHandlerBase {

  /**
   * The CiviCRM service.
   *
   * @var \Drupal\civicrm\Civicrm
   */
  protected $civicrm;

  /**
   * The Activity Checker service.
   *
   * @var \Drupal\k7zz_webform_civicrm_activity_lock\Service\ActivityChecker
   */
  protected $activityChecker;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->civicrm = $container->get('civicrm');
    $instance->activityChecker = $container->get('k7zz_webform_civicrm_activity_lock.activity_checker');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'activity_types' => [],
      'activity_status' => [],
      'first_name_field' => '',
      'last_name_field' => '',
      'email_field' => '',
      'check_employer' => FALSE,
      'relationship_type_id' => 4,
      'lock_message' => $this->t('This form is currently locked because a matching activity already exists in our system.'),
      'lock_message_format' => 'basic_html',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    // Initialize CiviCRM to load options.
    $this->civicrm->initialize();

    $form['activity_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Activity Check Settings'),
    ];

    // Load activity types from CiviCRM.
    $activityTypes = $this->getActivityTypes();
    $form['activity_settings']['activity_types'] = [
      '#type' => 'select',
      '#title' => $this->t('Activity Types'),
      '#description' => $this->t('Select the activity types to check for. Multiple selections allowed.'),
      '#options' => $activityTypes,
      '#default_value' => $this->configuration['activity_types'],
      '#multiple' => TRUE,
      '#required' => TRUE,
    ];

    // Load activity statuses from CiviCRM.
    $activityStatuses = $this->getActivityStatuses();
    $form['activity_settings']['activity_status'] = [
      '#type' => 'select',
      '#title' => $this->t('Activity Statuses'),
      '#description' => $this->t('Select the activity statuses to check for. Leave empty to check all statuses. Multiple selections allowed.'),
      '#options' => $activityStatuses,
      '#default_value' => $this->configuration['activity_status'],
      '#multiple' => TRUE,
      '#required' => FALSE,
    ];

    $form['field_mapping'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Field Mapping'),
      '#description' => $this->t('Map the webform fields to CiviCRM contact fields.'),
    ];

    $form['field_mapping']['first_name_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('First Name Field'),
      '#description' => $this->t('Enter the machine name of the webform field for first name.'),
      '#default_value' => $this->configuration['first_name_field'],
      '#required' => TRUE,
    ];

    $form['field_mapping']['last_name_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Last Name Field'),
      '#description' => $this->t('Enter the machine name of the webform field for last name.'),
      '#default_value' => $this->configuration['last_name_field'],
      '#required' => TRUE,
    ];

    $form['field_mapping']['email_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Email Field'),
      '#description' => $this->t('Enter the machine name of the webform field for email.'),
      '#default_value' => $this->configuration['email_field'],
      '#required' => TRUE,
    ];

    $form['employer_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Employer Settings'),
    ];

    $form['employer_settings']['check_employer'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Check employer activities'),
      '#description' => $this->t('If enabled, activities of the contact\'s employer will also be checked.'),
      '#default_value' => $this->configuration['check_employer'],
    ];

    $form['employer_settings']['relationship_type_id'] = [
      '#type' => 'number',
      '#title' => $this->t('Employer Relationship Type ID'),
      '#description' => $this->t('The CiviCRM relationship type ID for employer relationships (default: 4).'),
      '#default_value' => $this->configuration['relationship_type_id'],
      '#min' => 1,
      '#states' => [
        'visible' => [
          ':input[name="settings[employer_settings][check_employer]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['lock_message'] = [
      '#type' => 'text_format',
      '#title' => $this->t('Lock Message'),
      '#description' => $this->t('The message to display when the form is locked. HTML is allowed.'),
      '#default_value' => $this->configuration['lock_message'],
      '#format' => $this->configuration['lock_message_format'] ?? 'basic_html',
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    $values = $form_state->getValues();

    // Debug logging.
    \Drupal::logger('k7zz_webform_civicrm_activity_lock')->debug(
      'submitConfigurationForm values: @values',
      ['@values' => print_r($values, TRUE)]
    );

    // Activity settings.
    if (isset($values['activity_settings']['activity_types'])) {
      $this->configuration['activity_types'] = $values['activity_settings']['activity_types'];
    }
    if (isset($values['activity_settings']['activity_status'])) {
      $this->configuration['activity_status'] = $values['activity_settings']['activity_status'];
    }

    // Field mapping.
    if (isset($values['field_mapping']['first_name_field'])) {
      $this->configuration['first_name_field'] = $values['field_mapping']['first_name_field'];
    }
    if (isset($values['field_mapping']['last_name_field'])) {
      $this->configuration['last_name_field'] = $values['field_mapping']['last_name_field'];
    }
    if (isset($values['field_mapping']['email_field'])) {
      $this->configuration['email_field'] = $values['field_mapping']['email_field'];
    }

    // Employer settings.
    if (isset($values['employer_settings']['check_employer'])) {
      $this->configuration['check_employer'] = $values['employer_settings']['check_employer'];
    }
    if (isset($values['employer_settings']['relationship_type_id'])) {
      $this->configuration['relationship_type_id'] = $values['employer_settings']['relationship_type_id'];
    }

    // Lock message (text_format returns array with 'value' and 'format').
    if (isset($values['lock_message'])) {
      if (is_array($values['lock_message'])) {
        $this->configuration['lock_message'] = $values['lock_message']['value'] ?? '';
        $this->configuration['lock_message_format'] = $values['lock_message']['format'] ?? 'basic_html';
      }
      else {
        $this->configuration['lock_message'] = $values['lock_message'];
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function alterForm(array &$form, FormStateInterface $form_state, WebformSubmissionInterface $webform_submission) {
    // Add AJAX wrapper for the entire form content.
    $form['#prefix'] = '<div id="webform-civicrm-activity-lock-wrapper">';
    $form['#suffix'] = '</div>';

    // Get field names.
    $firstNameField = $this->configuration['first_name_field'];
    $lastNameField = $this->configuration['last_name_field'];
    $emailField = $this->configuration['email_field'];

    // Attach JavaScript library and pass settings.
    $form['#attached']['library'][] = 'k7zz_webform_civicrm_activity_lock/activity-lock';
    $form['#attached']['drupalSettings']['k7zz_webform_civicrm_activity_lock'] = [
      'first_name_field' => $firstNameField,
      'last_name_field' => $lastNameField,
      'email_field' => $emailField,
      'webform_id' => $this->getWebform()->id(),
      'activity_types' => array_values($this->configuration['activity_types']),
      'status_ids' => array_values($this->configuration['activity_status']),
      'check_employer' => $this->configuration['check_employer'],
      'relationship_type_id' => $this->configuration['relationship_type_id'],
      'lock_message' => $this->configuration['lock_message'],
    ];

    // Get values from form_state or webform_submission.
    $values = $form_state->getValues();
    $submissionData = $webform_submission->getData();

    // Get field values.
    $firstName = $values[$firstNameField]
      ?? $submissionData[$firstNameField]
      ?? NULL;
    $lastName = $values[$lastNameField]
      ?? $submissionData[$lastNameField]
      ?? NULL;
    $email = $values[$emailField]
      ?? $submissionData[$emailField]
      ?? NULL;

    // If all values are available on page load, check activity and potentially lock form.
    if (!empty($firstName) && !empty($lastName) && !empty($email)) {
      $activityExists = $this->activityChecker->checkActivityExists([
        'first_name' => $firstName,
        'last_name' => $lastName,
        'email' => $email,
        'activity_type_ids' => array_map('intval', array_values($this->configuration['activity_types'])),
        'status_ids' => !empty($this->configuration['activity_status']) ? array_map('intval', array_values($this->configuration['activity_status'])) : [],
        'check_employer' => $this->configuration['check_employer'],
        'relationship_type_id' => (int) ($this->configuration['relationship_type_id'] ?? 4),
      ]);

      if ($activityExists) {
        $this->lockForm($form);
      }
    }
  }

  /**
   * Add AJAX callback to a specific field.
   *
   * @param array &$form
   *   The form array.
   * @param string $fieldName
   *   The field name.
   */
  protected function addAjaxCallbackToField(array &$form, string $fieldName) {
    // Try to find the field in different locations.
    $field = NULL;

    // Check if field is in elements array (common for webforms).
    if (isset($form['elements'][$fieldName])) {
      $field = &$form['elements'][$fieldName];
      \Drupal::logger('k7zz_webform_civicrm_activity_lock')->debug('Found field @field in elements array', ['@field' => $fieldName]);
    }
    // Check if field is at root level.
    elseif (isset($form[$fieldName])) {
      $field = &$form[$fieldName];
      \Drupal::logger('k7zz_webform_civicrm_activity_lock')->debug('Found field @field at root level', ['@field' => $fieldName]);
    }
    // Search recursively for the field.
    else {
      $field = &$this->findFormElement($form, $fieldName);
      if ($field !== NULL) {
        \Drupal::logger('k7zz_webform_civicrm_activity_lock')->debug('Found field @field recursively', ['@field' => $fieldName]);
      }
      else {
        \Drupal::logger('k7zz_webform_civicrm_activity_lock')->warning('Field @field not found in form', ['@field' => $fieldName]);
      }
    }

    if ($field !== NULL) {
      $field['#ajax'] = [
        'callback' => [$this, 'ajaxCallback'],
        'wrapper' => 'webform-civicrm-activity-lock-wrapper',
        'event' => 'change',
        'progress' => [
          'type' => 'throbber',
          'message' => $this->t('Checking activity...'),
        ],
      ];
      \Drupal::logger('k7zz_webform_civicrm_activity_lock')->debug('Added AJAX callback to field @field', ['@field' => $fieldName]);
    }
  }

  /**
   * Recursively find a form element by name.
   *
   * @param array &$elements
   *   The form elements array.
   * @param string $fieldName
   *   The field name to find.
   *
   * @return array|null
   *   The field element or NULL if not found.
   */
  protected function &findFormElement(array &$elements, string $fieldName) {
    foreach ($elements as $key => &$element) {
      // Skip special form keys.
      if (strpos($key, '#') === 0) {
        continue;
      }

      if ($key === $fieldName) {
        return $element;
      }

      // If element is an array, recurse.
      if (is_array($element)) {
        $found = &$this->findFormElement($element, $fieldName);
        if ($found !== NULL) {
          return $found;
        }
      }
    }

    $null = NULL;
    return $null;
  }

  /**
   * AJAX callback for activity check.
   *
   * @param array &$form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The form array to replace via AJAX.
   */
  public function ajaxCallback(array &$form, FormStateInterface $form_state) {
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state, WebformSubmissionInterface $webform_submission) {
    // Get values from form_state.
    $values = $form_state->getValues();

    $firstName = $values[$this->configuration['first_name_field']] ?? NULL;
    $lastName = $values[$this->configuration['last_name_field']] ?? NULL;
    $email = $values[$this->configuration['email_field']] ?? NULL;

    // If any field is empty, skip validation.
    if (empty($firstName) || empty($lastName) || empty($email)) {
      return;
    }

    // Check if activity exists.
    $activityExists = $this->activityChecker->checkActivityExists([
      'first_name' => $firstName,
      'last_name' => $lastName,
      'email' => $email,
      'activity_type_ids' => array_map('intval', array_values($this->configuration['activity_types'])),
      'status_ids' => !empty($this->configuration['activity_status']) ? array_map('intval', array_values($this->configuration['activity_status'])) : [],
      'check_employer' => $this->configuration['check_employer'],
      'relationship_type_id' => (int) ($this->configuration['relationship_type_id'] ?? 4),
    ]);

    if ($activityExists) {
      $rendered = check_markup($this->configuration['lock_message'], $this->configuration['lock_message_format'] ?? 'basic_html');
      $form_state->setErrorByName('', \Drupal\Core\Render\Markup::create(\Drupal::service('renderer')->renderPlain($rendered)));
    }
  }

  /**
   * Lock the form by disabling all elements and showing a message.
   *
   * @param array &$form
   *   The form array.
   */
  protected function lockForm(array &$form) {
    // Add lock message at the top of the form.
    $form['lock_message'] = [
      '#type' => 'processed_text',
      '#text' => $this->configuration['lock_message'],
      '#format' => $this->configuration['lock_message_format'] ?? 'basic_html',
      '#prefix' => '<div class="messages messages--warning" role="alert">',
      '#suffix' => '</div>',
      '#weight' => -1000,
    ];

    // Disable all form elements except our tracked fields.
    $this->disableFormElements($form, [
      $this->configuration['first_name_field'],
      $this->configuration['last_name_field'],
      $this->configuration['email_field'],
    ]);

    // Hide submit buttons.
    if (isset($form['actions'])) {
      $form['actions']['#access'] = FALSE;
    }
  }

  /**
   * Recursively disable all form elements.
   *
   * @param array &$elements
   *   The form elements array.
   * @param array $exceptFields
   *   Array of field names to keep enabled (for AJAX).
   */
  protected function disableFormElements(array &$elements, array $exceptFields = []) {
    foreach ($elements as $key => &$element) {
      // Skip special form keys.
      if (strpos($key, '#') === 0) {
        continue;
      }

      // Skip fields that should remain enabled.
      if (in_array($key, $exceptFields)) {
        continue;
      }

      // If element is an array, recurse.
      if (is_array($element)) {
        // Disable input elements.
        if (isset($element['#type']) && !in_array($element['#type'], ['markup', 'container', 'fieldset', 'details', 'hidden'])) {
          $element['#disabled'] = TRUE;
        }

        // Recurse into nested elements.
        $this->disableFormElements($element, $exceptFields);
      }
    }
  }

  /**
   * Get activity types from CiviCRM.
   *
   * @return array
   *   Array of activity types keyed by ID.
   */
  protected function getActivityTypes(): array {
    try {
      $utils = \Drupal::service('webform_civicrm.utils');
      $results = $utils->wf_crm_apivalues('OptionValue', 'get', [
        'option_group_id' => 'activity_type',
        'is_active' => 1,
        'options' => ['sort' => 'weight'],
      ]);

      $activityTypes = [];
      foreach ($results as $result) {
        // Use 'value' as key (the actual activity type ID) and 'label' as the display text
        $activityTypes[$result['value']] = $result['label'];
      }

      return $activityTypes;
    }
    catch (\Exception $e) {
      \Drupal::logger('k7zz_webform_civicrm_activity_lock')->error(
        'Error loading activity types: @message',
        ['@message' => $e->getMessage()]
      );
      return [];
    }
  }

  /**
   * Get activity statuses from CiviCRM.
   *
   * @return array
   *   Array of activity statuses keyed by ID.
   */
  protected function getActivityStatuses(): array {
    try {
      $utils = \Drupal::service('webform_civicrm.utils');
      $results = $utils->wf_crm_apivalues('OptionValue', 'get', [
        'option_group_id' => 'activity_status',
        'is_active' => 1,
        'options' => ['sort' => 'weight'],
      ]);

      $activityStatuses = [];
      foreach ($results as $result) {
        // Use 'value' as key (the actual status ID) and 'label' as the display text
        $activityStatuses[$result['value']] = $result['label'];
      }

      return $activityStatuses;
    }
    catch (\Exception $e) {
      \Drupal::logger('k7zz_webform_civicrm_activity_lock')->error(
        'Error loading activity statuses: @message',
        ['@message' => $e->getMessage()]
      );
      return [];
    }
  }

}
