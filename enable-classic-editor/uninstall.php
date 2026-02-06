<?php
/**
 * Uninstall file for Enable Classic Editor & Widgets
 *
 * This file runs when the plugin is uninstalled.
 */

// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete plugin options
delete_option('ecew_settings');
delete_option('ecew_settings_last_saved');

// Delete post meta data using prepared statement for security
global $wpdb;
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s",
        '_ecew_editor_choice'
    )
);
