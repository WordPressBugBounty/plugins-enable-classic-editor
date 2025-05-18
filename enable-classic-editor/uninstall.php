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

// Delete post meta data
global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_ecew_editor_choice'");
