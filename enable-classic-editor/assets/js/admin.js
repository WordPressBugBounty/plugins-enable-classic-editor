/**
 * Admin JavaScript for Enable Classic Editor Settings
 */
jQuery(document).ready(function($) {
    // Constants for timing delays (in milliseconds)
    const TOAST_SHOW_DELAY = 100;
    const TOAST_AUTO_HIDE_DELAY = 3000;
    const TOAST_FADE_OUT_DELAY = 300;
    const BUTTON_RESET_DELAY = 2000;
    const MODAL_FADE_DURATION = 200;
    const SETTINGS_SCROLL_DURATION = 300;
    const ADVANCED_SETTINGS_FADE_DURATION = 300;

    // Quick Edit functionality for editor choice column
    if (typeof inlineEditPost !== 'undefined') {
        // Save the original quick edit function
        var $wp_inline_edit = inlineEditPost.edit;

        // Override the quick edit function
        inlineEditPost.edit = function(id) {
            // Call the original function
            $wp_inline_edit.apply(this, arguments);

            // Get the post ID
            var post_id = 0;
            if (typeof(id) === 'object') {
                post_id = parseInt(this.getId(id));
            }

            if (post_id > 0) {
                // Get the row
                var $row = $('#post-' + post_id);

                // Get the editor choice
                var editor_choice = '';
                var $editor_icon = $row.find('td.editor_choice .dashicons');

                if ($editor_icon.hasClass('dashicons-editor-kitchensink')) {
                    editor_choice = 'classic';
                } else if ($editor_icon.hasClass('dashicons-block-default')) {
                    editor_choice = 'block';
                } else {
                    editor_choice = 'default';
                }

                // Set the value in the quick edit form
                var $edit_row = $('#edit-' + post_id);
                $edit_row.find('select[name="ecew_editor_choice"]').val(editor_choice);
            }
        };
    }

    // Toggle advanced settings visibility
    $('#ecew_show_settings').on('change', function() {
        if ($(this).is(':checked')) {
            $('.advanced-settings').fadeIn(ADVANCED_SETTINGS_FADE_DURATION);
        } else {
            $('.advanced-settings').fadeOut(ADVANCED_SETTINGS_FADE_DURATION);
        }
    });

    // Per-Post Toggle is now independent of Advanced Settings
    // No need to auto-enable Advanced Settings when Per-Post Toggle is checked
    // Both features work independently in simple and advanced modes

    // Handle dependency: Per-Post Toggle requires main plugin to be enabled
    function updatePerPostToggleState() {
        var $perPostToggle = $('#ecew_per_post_toggle');
        var $perPostRow = $perPostToggle.closest('tr');

        if (!$('#ecew_enabled').is(':checked')) {
            // Main plugin is disabled, visually disable per-post toggle
            // NOTE: We don't use .prop('disabled', true) because disabled fields
            // are excluded from form submission, causing save bugs
            $perPostRow.addClass('disabled-setting');

            // If it was checked, uncheck it
            if ($perPostToggle.is(':checked')) {
                $perPostToggle.prop('checked', false);
            }
        } else {
            // Main plugin is enabled, enable per-post toggle
            $perPostRow.removeClass('disabled-setting');
        }
    }

    // Handle dependency: Advanced Settings requires main plugin to be enabled
    function updateAdvancedSettingsState() {
        var $advancedToggle = $('#ecew_show_settings');
        var $advancedRow = $advancedToggle.closest('tr');

        if (!$('#ecew_enabled').is(':checked')) {
            // Main plugin is disabled, visually disable advanced settings
            // NOTE: We don't use .prop('disabled', true) because disabled fields
            // are excluded from form submission, causing save bugs
            $advancedRow.addClass('disabled-setting');

            // If it was checked, uncheck it and trigger change to hide sections
            if ($advancedToggle.is(':checked')) {
                $advancedToggle.prop('checked', false).trigger('change');
            }
        } else {
            // Main plugin is enabled, enable advanced settings
            $advancedRow.removeClass('disabled-setting');
        }
    }

    // Run on page load
    updatePerPostToggleState();
    updateAdvancedSettingsState();

    // Run when main toggle changes
    $('#ecew_enabled').on('change', function() {
        updatePerPostToggleState();
        updateAdvancedSettingsState();
    });

    // Toast notification function
    function showToast(message, type) {
        var $toast = $('<div class="ecew-toast ecew-toast-' + type + '">' + message + '</div>');
        $('body').append($toast);

        setTimeout(function() {
            $toast.addClass('show');
        }, TOAST_SHOW_DELAY);

        setTimeout(function() {
            $toast.removeClass('show');
            setTimeout(function() {
                $toast.remove();
            }, TOAST_FADE_OUT_DELAY);
        }, TOAST_AUTO_HIDE_DELAY);
    }

    // Unsaved changes detection
    var formChanged = false;
    var originalFormData = $('.classic-editor-plus-settings form').serialize();

    function updateUnsavedIndicator() {
        if (formChanged) {
            if ($('.unsaved-indicator').length === 0) {
                $('.submit-buttons #submit').before('<span class="unsaved-indicator">● Unsaved changes</span>');
            }
        } else {
            $('.unsaved-indicator').remove();
        }
    }

    // Track form changes
    $('.classic-editor-plus-settings form :input').on('change', function() {
        var currentFormData = $('.classic-editor-plus-settings form').serialize();
        formChanged = (originalFormData !== currentFormData);
        updateUnsavedIndicator();
    });

    // Warn on navigation if there are unsaved changes
    $(window).on('beforeunload', function(e) {
        if (formChanged) {
            var message = ecewSettings.i18n.unsavedChanges;
            e.returnValue = message;
            return message;
        }
    });

    // Animated save button
    $('.classic-editor-plus-settings form').on('submit', function(e) {
        var $form = $(this);
        var $submitBtn = $('#submit');
        var originalText = $submitBtn.val();

        // Disable button and show loading
        $submitBtn.prop('disabled', true).val('Saving...').addClass('saving');

        // Clear the unsaved changes flag (form is being saved)
        formChanged = false;

        // Note: Form will submit normally, we just show the animation
        // The page will reload, showing WordPress's success message
        // We can't prevent default here because we need WordPress to save the settings

        // Store that we're saving (for after page reload) with error handling
        try {
            sessionStorage.setItem('ecew_just_saved', 'true');
        } catch (e) {
            // Silently fail if sessionStorage is not available (privacy mode, etc.)
        }
    });

    // Check if we just saved (after page reload) with error handling
    var justSaved = false;
    try {
        justSaved = (sessionStorage.getItem('ecew_just_saved') === 'true');
        if (justSaved) {
            sessionStorage.removeItem('ecew_just_saved');
        }
    } catch (e) {
        // Silently fail if sessionStorage is not available
    }

    if (justSaved) {

        // Show success animation on the save button
        var $submitBtn = $('#submit');
        var originalText = $submitBtn.val();

        $submitBtn.val('✓ Settings Saved!').addClass('saved');
        showToast(ecewSettings.i18n.settingsSaved, 'success');

        // Reset button after delay
        setTimeout(function() {
            $submitBtn.removeClass('saved').val(originalText);
        }, BUTTON_RESET_DELAY);

        // Scroll to top to show any messages
        $('html, body').animate({ scrollTop: 0 }, SETTINGS_SCROLL_DURATION);
    }

    // Post Type/Role Selection Improvements
    function updatePostTypeCount() {
        var checked = $('input[name="ecew_settings[post_types][]"]:checked').length;
        var total = $('input[name="ecew_settings[post_types][]"]').length;
        $('.post-types-count').text(ecewSettings.i18n.postTypesSelected.replace('%d', checked).replace('%d', total));
    }

    function updateRoleCount() {
        var checked = $('input[name="ecew_settings[user_roles][]"]:checked').length;
        var total = $('input[name="ecew_settings[user_roles][]"]').length;
        $('.roles-count').text(ecewSettings.i18n.userRolesSelected.replace('%d', checked).replace('%d', total));
    }

    // Select All / Deselect All for post types
    $('.select-all-post-types').on('click', function() {
        $('input[name="ecew_settings[post_types][]"]').prop('checked', true).trigger('change');
        updatePostTypeCount();
    });

    $('.deselect-all-post-types').on('click', function() {
        $('input[name="ecew_settings[post_types][]"]').prop('checked', false).trigger('change');
        updatePostTypeCount();
    });

    // Update count when checkboxes change
    $('input[name="ecew_settings[post_types][]"]').on('change', function() {
        updatePostTypeCount();
    });

    // Select All / Deselect All for user roles
    $('.select-all-roles').on('click', function() {
        $('input[name="ecew_settings[user_roles][]"]').prop('checked', true).trigger('change');
        updateRoleCount();
    });

    $('.deselect-all-roles').on('click', function() {
        $('input[name="ecew_settings[user_roles][]"]').prop('checked', false).trigger('change');
        updateRoleCount();
    });

    // Update count when checkboxes change
    $('input[name="ecew_settings[user_roles][]"]').on('change', function() {
        updateRoleCount();
    });

    // Reset defaults button with custom modal
    $('#reset-defaults').on('click', function(e) {
        e.preventDefault();

        // Create modal HTML with accessibility attributes
        var modalHTML = `
            <div class="ecew-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="ecew-modal-title">
                <div class="ecew-modal">
                    <div class="ecew-modal-header">
                        <h2 id="ecew-modal-title">Reset All Settings to Defaults?</h2>
                    </div>
                    <div class="ecew-modal-body">
                        <p>This will reset all plugin settings to their default values:</p>
                        <ul>
                            <li><strong>Classic Editor:</strong> Enabled</li>
                            <li><strong>Advanced Settings:</strong> Disabled (Simple Mode)</li>
                            <li><strong>Per-Post Toggle:</strong> Enabled</li>
                            <li><strong>Classic Widgets:</strong> Enabled</li>
                            <li><strong>All post types:</strong> Selected</li>
                            <li><strong>All user roles:</strong> Selected</li>
                        </ul>
                        <p><strong>Note:</strong> Per-post editor preferences will not be affected.</p>
                    </div>
                    <div class="ecew-modal-footer">
                        <button type="button" class="button ecew-modal-cancel" aria-label="Cancel reset">Cancel</button>
                        <button type="button" class="button button-primary button-danger ecew-modal-confirm" aria-label="Confirm reset">Reset All Settings</button>
                    </div>
                </div>
            </div>
        `;

        $('body').append(modalHTML);
        $('.ecew-modal-overlay').fadeIn(MODAL_FADE_DURATION);

        // Set focus to the confirm button for accessibility
        $('.ecew-modal-confirm').focus();

        // Handle cancel (clicking cancel button or overlay)
        $('.ecew-modal-cancel, .ecew-modal-overlay').on('click', function(e) {
            if (e.target === this) {
                $('.ecew-modal-overlay').fadeOut(MODAL_FADE_DURATION, function() {
                    $(this).remove();
                });
            }
        });

        // Handle confirm
        $('.ecew-modal-confirm').on('click', function() {
            // Reset all form elements
            $('#ecew_enabled').prop('checked', true).trigger('change');
            $('#ecew_show_settings').prop('checked', false).trigger('change');
            $('#ecew_per_post_toggle').prop('checked', true);
            $('#ecew_classic_widgets_global').prop('checked', true);

            // Check all post type checkboxes
            $('input[name="ecew_settings[post_types][]"]').prop('checked', true);
            updatePostTypeCount();

            // Check all role checkboxes
            $('input[name="ecew_settings[user_roles][]"]').prop('checked', true);
            updateRoleCount();

            // Close modal
            $('.ecew-modal-overlay').fadeOut(MODAL_FADE_DURATION, function() {
                $(this).remove();
            });

            // Show toast
            showToast(ecewSettings.i18n.resetToDefaults, 'success');
        });
    });
}); 