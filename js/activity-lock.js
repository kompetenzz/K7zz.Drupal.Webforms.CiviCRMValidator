/**
 * @file
 * Handles AJAX-based activity checking for webform.
 */

(function ($, Drupal, drupalSettings) {

    'use strict';

    /**
     * Activity lock behavior.
     */
    Drupal.behaviors.k7zzWebformActivityLock = {
        attach: function (context, settings) {
            if (!settings.k7zz_webform_civicrm_activity_lock) {
                return;
            }

            const config = settings.k7zz_webform_civicrm_activity_lock;
            const firstNameField = config.first_name_field;
            const lastNameField = config.last_name_field;
            const emailField = config.email_field;

            // Find the form
            const $form = $('[id^="webform-submission-"]', context).one('k7zz-activity-lock');
            if (!$form.length) {
                return;
            }

            // State management to prevent multiple simultaneous calls
            let isChecking = false;
            let lastCheckedValues = { firstName: '', lastName: '', email: '' };
            let checkTimeout = null;

            // Helper function to get field value by name
            function getFieldValue(fieldName) {
                // Try different selector patterns for webform fields
                const selectors = [
                    `[name="${fieldName}"]`,
                    `[name="elements[${fieldName}]"]`,
                    `[data-drupal-selector="edit-${fieldName.replace(/_/g, '-')}"]`,
                    `#edit-${fieldName.replace(/_/g, '-')}`
                ];

                for (let selector of selectors) {
                    const $field = $(selector);
                    if ($field.length) {
                        return $field.val() || '';
                    }
                }
                return '';
            }

            // Helper function to attach change listener
            function attachChangeListener(fieldName) {
                const selectors = [
                    `[name="${fieldName}"]`,
                    `[name="elements[${fieldName}]"]`,
                    `[data-drupal-selector="edit-${fieldName.replace(/_/g, '-')}"]`,
                    `#edit-${fieldName.replace(/_/g, '-')}`
                ];

                for (let selector of selectors) {
                    const $field = $(selector);
                    if ($field.length) {
                        $field.on('change blur', checkActivity);
                        return true;
                    }
                }
                return false;
            }

            // Function to check activity (with debouncing and blocking)
            function checkActivity() {
                // Clear any pending timeout
                if (checkTimeout) {
                    clearTimeout(checkTimeout);
                }

                // Debounce: wait 500ms after last input
                checkTimeout = setTimeout(function() {
                    const firstName = getFieldValue(firstNameField);
                    const lastName = getFieldValue(lastNameField);
                    const email = getFieldValue(emailField);

                    // Only check if all fields have values
                    if (!firstName || !lastName || !email) {
                        return;
                    }

                    // Check if values have changed since last check
                    if (firstName === lastCheckedValues.firstName &&
                        lastName === lastCheckedValues.lastName &&
                        email === lastCheckedValues.email) {
                        return;
                    }

                    // Block if already checking
                    if (isChecking) {
                        return;
                    }

                    // Set flag and remember values
                    isChecking = true;
                    lastCheckedValues = { firstName, lastName, email };

                    // Make AJAX request (silent, no visual feedback)
                    $.ajax({
                        url: Drupal.url('k7zz-webform-activity-lock/check'),
                        method: 'POST',
                        data: {
                            first_name: firstName,
                            last_name: lastName,
                            email: email,
                            webform_id: config.webform_id
                        },
                        success: function (response) {
                            if (response.activity_exists) {
                                lockForm(response.message);
                            } else {
                                unlockForm();
                            }
                        },
                        error: function (xhr, status, error) {
                            console.error('Activity check failed:', error);
                        },
                        complete: function() {
                            // Reset flag when done
                            isChecking = false;
                        }
                    });
                }, 500); // Wait 500ms after last input
            }

            // Function to lock the form
            function lockForm(message) {
                // Remove existing lock message
                $('.activity-lock-message').remove();

                // Find the email field wrapper
                const emailSelectors = [
                    `[name="${emailField}"]`,
                    `[name="elements[${emailField}]"]`,
                    `[data-drupal-selector="edit-${emailField.replace(/_/g, '-')}"]`,
                    `#edit-${emailField.replace(/_/g, '-')}`
                ];

                let $emailField = null;
                for (let selector of emailSelectors) {
                    $emailField = $(selector);
                    if ($emailField.length) {
                        break;
                    }
                }

                // Find the closest form item wrapper
                const $emailWrapper = $emailField ? $emailField.closest('.form-item, .webform-element, .js-form-item') : null;

                // Add lock message
                const $message = $('<div class="activity-lock-message messages messages--warning" role="alert" style="display: none; margin-top: 1em;">' + message + '</div>');

                // Insert after email field wrapper, or prepend to form if not found
                if ($emailWrapper && $emailWrapper.length) {
                    $emailWrapper.after($message);
                } else {
                    $form.prepend($message);
                }

                // Animate message appearance
                $message.slideDown(300, function() {
                    // Scroll to message smoothly
                    $message[0].scrollIntoView({
                        behavior: 'smooth',
                        block: 'center'
                    });

                    // Add highlight effect
                    $message.css('background-color', '#fff3cd');
                    setTimeout(function() {
                        $message.css('transition', 'background-color 1s ease');
                        $message.css('background-color', '');
                    }, 500);
                });

                // Disable all fields except the three trigger fields
                $form.find('input, select, textarea, button').not(
                    `[name="${firstNameField}"], [name="${lastNameField}"], [name="${emailField}"]`
                ).prop('disabled', true);

                // Hide submit buttons
                $form.find('.webform-button--submit').hide();
            }

            // Function to unlock the form
            function unlockForm() {
                $('.activity-lock-message').remove();
                $form.find('input, select, textarea, button').prop('disabled', false);
                $form.find('.webform-button--submit').show();
            }

            // Attach change listeners to all three fields
            attachChangeListener(firstNameField);
            attachChangeListener(lastNameField);
            attachChangeListener(emailField);
        }
    };

})(jQuery, Drupal, drupalSettings);
