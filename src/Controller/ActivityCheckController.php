<?php

namespace Drupal\k7zz_webform_civicrm_activity_lock\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\k7zz_webform_civicrm_activity_lock\Service\ActivityChecker;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\webform\Entity\Webform;

/**
 * Controller for AJAX activity checking.
 */
class ActivityCheckController extends ControllerBase {

  /**
   * The Activity Checker service.
   *
   * @var \Drupal\k7zz_webform_civicrm_activity_lock\Service\ActivityChecker
   */
  protected $activityChecker;

  /**
   * Constructs an ActivityCheckController object.
   *
   * @param \Drupal\k7zz_webform_civicrm_activity_lock\Service\ActivityChecker $activityChecker
   *   The Activity Checker service.
   */
  public function __construct(ActivityChecker $activityChecker) {
    $this->activityChecker = $activityChecker;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('k7zz_webform_civicrm_activity_lock.activity_checker')
    );
  }

  /**
   * Check if activity exists.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with activity status.
   */
  public function check(Request $request) {
    // Get POST data.
    $firstName = $request->request->get('first_name');
    $lastName = $request->request->get('last_name');
    $email = $request->request->get('email');
    $webformId = $request->request->get('webform_id');

    // Validate input.
    if (empty($firstName) || empty($lastName) || empty($email) || empty($webformId)) {
      return new JsonResponse([
        'activity_exists' => FALSE,
        'error' => 'Missing required fields',
      ], 400);
    }

    // Load webform and get handler configuration.
    $webform = Webform::load($webformId);
    if (!$webform) {
      return new JsonResponse([
        'activity_exists' => FALSE,
        'error' => 'Webform not found',
      ], 404);
    }

    // Get handler configuration.
    $handler = NULL;
    foreach ($webform->getHandlers() as $handlerInstance) {
      if ($handlerInstance->getPluginId() === 'civicrm_activity_lock') {
        $handler = $handlerInstance;
        break;
      }
    }

    if (!$handler) {
      return new JsonResponse([
        'activity_exists' => FALSE,
        'error' => 'Handler not found',
      ], 404);
    }

    $configuration = $handler->getConfiguration();

    // Configuration is nested under 'settings' key.
    $settings = $configuration['settings'] ?? [];

    // Validate configuration - only activity_types is required.
    if (empty($settings['activity_types'])) {
      return new JsonResponse([
        'activity_exists' => FALSE,
        'error' => 'Handler not properly configured - activity types required',
      ], 400);
    }

    // Check if activity exists.
    // Note: activity_status is optional - empty array means check all statuses.
    $activityExists = $this->activityChecker->checkActivityExists([
      'first_name' => $firstName,
      'last_name' => $lastName,
      'email' => $email,
      'activity_type_ids' => array_map('intval', array_values($settings['activity_types'])),
      'status_ids' => !empty($settings['activity_status']) ? array_map('intval', array_values($settings['activity_status'])) : [],
      'check_employer' => $settings['check_employer'] ?? FALSE,
      'relationship_type_id' => (int) ($settings['relationship_type_id'] ?? 4),
    ]);

    $message = '';
    if ($activityExists) {
      $format = $settings['lock_message_format'] ?? 'basic_html';
      $message = (string) check_markup($settings['lock_message'] ?? 'Form is locked', $format);
    }

    return new JsonResponse([
      'activity_exists' => $activityExists,
      'message' => $message,
    ]);
  }

}
