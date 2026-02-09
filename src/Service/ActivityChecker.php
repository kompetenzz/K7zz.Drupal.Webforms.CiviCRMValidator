<?php

namespace Drupal\k7zz_webform_civicrm_activity_lock\Service;

use Drupal\civicrm\Civicrm;
use Drupal\webform_civicrm\UtilsInterface;

/**
 * Service for checking CiviCRM activities.
 */
class ActivityChecker {

  /**
   * The CiviCRM service.
   *
   * @var \Drupal\civicrm\Civicrm
   */
  protected $civicrm;

  /**
   * The Webform CiviCRM Utils service.
   *
   * @var \Drupal\webform_civicrm\UtilsInterface
   */
  protected $utils;

  /**
   * Constructs an ActivityChecker object.
   *
   * @param \Drupal\civicrm\Civicrm $civicrm
   *   The CiviCRM service.
   * @param \Drupal\webform_civicrm\UtilsInterface $utils
   *   The Webform CiviCRM Utils service.
   */
  public function __construct(Civicrm $civicrm, UtilsInterface $utils) {
    $this->civicrm = $civicrm;
    $this->utils = $utils;
  }

  /**
   * Check if an activity exists for a contact.
   *
   * @param array $params
   *   Parameters containing:
   *   - first_name: First name of the contact.
   *   - last_name: Last name of the contact.
   *   - email: Email of the contact.
   *   - activity_type_ids: Array of activity type IDs to check.
   *   - status_ids: Array of activity status IDs to check.
   *   - check_employer: Whether to check employer's activities.
   *   - relationship_type_id: Employer relationship type ID (default: 4).
   *
   * @return bool
   *   TRUE if activity exists, FALSE otherwise.
   */
  public function checkActivityExists(array $params): bool {
    // Initialize CiviCRM.
    $this->civicrm->initialize();

    // Validate required parameters.
    if (empty($params['first_name']) || empty($params['last_name']) || empty($params['email'])) {
      return FALSE;
    }

    if (empty($params['activity_type_ids'])) {
      return FALSE;
    }

    // Note: status_ids is now optional - if empty, all statuses will be checked

    // Find contact.
    $contactId = $this->findContact(
      $params['first_name'],
      $params['last_name'],
      $params['email']
    );

    if (!$contactId) {
      // Contact not found - allow form submission.
      return FALSE;
    }

    // Check if activity exists for this contact.
    if ($this->hasActivity($contactId, $params['activity_type_ids'], $params['status_ids'])) {
      return TRUE;
    }

    // Check employer if configured.
    if (!empty($params['check_employer'])) {
      $relationshipTypeId = $params['relationship_type_id'] ?? 4;
      $employerId = $this->getEmployerId($contactId, $relationshipTypeId);

      if ($employerId && $this->hasActivity($employerId, $params['activity_type_ids'], $params['status_ids'])) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Find a contact by first name, last name, and email.
   *
   * @param string $firstName
   *   First name.
   * @param string $lastName
   *   Last name.
   * @param string $email
   *   Email address.
   *
   * @return int|null
   *   Contact ID or NULL if not found.
   */
  protected function findContact(string $firstName, string $lastName, string $email): ?int {
    try {
      // Try to find contact with exact match.
      $contacts = $this->utils->wf_crm_apivalues('Contact', 'get', [
        'first_name' => $firstName,
        'last_name' => $lastName,
        'email' => $email,
      ]);

      if (!empty($contacts)) {
        // Return the first matching contact ID.
        $firstContact = reset($contacts);
        return $firstContact['id'] ?? NULL;
      }

      // Alternative: Try with Email entity join.
      $contacts = $this->utils->wf_crm_apivalues('Contact', 'get', [
        'first_name' => $firstName,
        'last_name' => $lastName,
        'api.Email.get' => [
          'contact_id' => '$value.id',
          'email' => $email,
        ],
      ]);

      foreach ($contacts as $contact) {
        if (!empty($contact['api.Email.get']['count']) && $contact['api.Email.get']['count'] > 0) {
          return $contact['id'] ?? NULL;
        }
      }

      return NULL;
    }
    catch (\Exception $e) {
      \Drupal::logger('k7zz_webform_civicrm_activity_lock')->error(
        'Error finding contact: @message',
        ['@message' => $e->getMessage()]
      );
      return NULL;
    }
  }

  /**
   * Get the employer ID for a contact.
   *
   * @param int $contactId
   *   Contact ID.
   * @param int $relationshipTypeId
   *   Relationship type ID for employer relationship.
   *
   * @return int|null
   *   Employer contact ID or NULL if not found.
   */
  protected function getEmployerId(int $contactId, int $relationshipTypeId = 4): ?int {
    try {
      $relationships = $this->utils->wf_crm_apivalues('Relationship', 'get', [
        'contact_id_a' => $contactId,
        'relationship_type_id' => $relationshipTypeId,
        'is_active' => 1,
      ]);

      if (!empty($relationships)) {
        $firstRelationship = reset($relationships);
        return $firstRelationship['contact_id_b'] ?? NULL;
      }

      return NULL;
    }
    catch (\Exception $e) {
      \Drupal::logger('k7zz_webform_civicrm_activity_lock')->error(
        'Error finding employer: @message',
        ['@message' => $e->getMessage()]
      );
      return NULL;
    }
  }

  /**
   * Check if a contact has an activity with specific type and status.
   *
   * @param int $contactId
   *   Contact ID.
   * @param array $activityTypeIds
   *   Array of activity type IDs.
   * @param array $statusIds
   *   Array of activity status IDs.
   *
   * @return bool
   *   TRUE if activity exists, FALSE otherwise.
   */
  protected function hasActivity(int $contactId, array $activityTypeIds, array $statusIds): bool {
    try {
      // Build query params - status filter is optional
      $baseParams = ['activity_type_id' => ['IN' => $activityTypeIds]];
      if (!empty($statusIds)) {
        $baseParams['status_id'] = ['IN' => $statusIds];
      }

      // Check activities where contact is a target.
      $activities = $this->utils->wf_crm_apivalues('Activity', 'get', array_merge([
        'target_contact_id' => $contactId,
      ], $baseParams));

      if (!empty($activities)) {
        return TRUE;
      }

      // Also check activities where contact is an assignee.
      $activities = $this->utils->wf_crm_apivalues('Activity', 'get', array_merge([
        'assignee_contact_id' => $contactId,
      ], $baseParams));

      if (!empty($activities)) {
        return TRUE;
      }

      // Also check activities where contact is a source.
      $activities = $this->utils->wf_crm_apivalues('Activity', 'get', array_merge([
        'source_contact_id' => $contactId,
      ], $baseParams));

      return !empty($activities);
    }
    catch (\Exception $e) {
      \Drupal::logger('k7zz_webform_civicrm_activity_lock')->error(
        'Error checking activities: @message',
        ['@message' => $e->getMessage()]
      );
      return FALSE;
    }
  }

}
