/**
 * Admin JavaScript for Enable Classic Editor Settings
 */
jQuery(document).ready(function($) {
    // Toggle advanced settings visibility
    $('#ecew_show_settings').on('change', function() {
        if ($(this).is(':checked')) {
            $('.advanced-settings').fadeIn(300);
        } else {
            $('.advanced-settings').fadeOut(300);
        }
    });
    
    // Reset defaults button
    $('#reset-defaults').on('click', function(e) {
        e.preventDefault();
        
        if (confirm(ecewSettings.resetConfirmMessage)) {
            // Reset all form elements
            $('#ecew_enabled').prop('checked', true);
            $('#ecew_show_settings').prop('checked', false).trigger('change');
            $('#ecew_per_post_toggle').prop('checked', true);
            $('#ecew_classic_widgets').prop('checked', true);
            
            // Check all post type checkboxes
            $('input[name="ecew_settings[post_types][]"]').prop('checked', true);
            
            // Check all role checkboxes
            $('input[name="ecew_settings[user_roles][]"]').prop('checked', true);
        }
    });
}); 