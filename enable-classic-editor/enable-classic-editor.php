<?php
/*
Plugin Name: Enable Classic Editor & Widgets
Plugin URI: https://www.ayonm.com
Description: A simple & lightweight plugin to enable the classic editor on WordPress with advanced configuration options.
Version: 3.2
Author: ayonm
Author URI: https://www.ayonm.com
License: GPL2
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: enable-classic-editor


Enable Classic Editor plugin is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 2 of the License, or any later version.
 
Enable Classic Editor is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.
 
You should have received a copy of the GNU General Public License along
with Enable Classic Editor or WordPress. If not, see https://www.gnu.org/licenses/gpl-2.0.html.

Copyright (c) 2025 AYONM. All rights reserved.
 */

defined( 'ABSPATH' ) || die( 'No Entry!' );

// Define plugin constants
if (!defined('ECEW_VERSION')) {
    define('ECEW_VERSION', '3.4');
}
if (!defined('ECEW_PLUGIN_DIR')) {
    define('ECEW_PLUGIN_DIR', plugin_dir_path(__FILE__));
}
if (!defined('ECEW_PLUGIN_URL')) {
    define('ECEW_PLUGIN_URL', plugin_dir_url(__FILE__));
}
if (!defined('ECEW_TEXT_DOMAIN')) {
    define('ECEW_TEXT_DOMAIN', 'enable-classic-editor');
}

/**
 * Main plugin class
 */
class Enable_Classic_Editor {
    /**
     * Plugin instance
     */
    private static $instance = null;

    /**
     * Plugin settings
     */
    private $settings;

    /**
     * Cached current user for performance
     */
    private $current_user_cache = null;

    /**
     * Get plugin instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        // Get plugin settings with safe defaults (no function calls that may not be available yet)
        $this->settings = get_option('ecew_settings', array(
            'enabled' => true,
            'post_types' => array(),  // Will be populated with actual post types if empty
            'user_roles' => array(),  // Will be populated with actual roles if empty
            'use_classic_widgets' => true,
            'show_settings_page' => false,
            'allow_per_post_toggle' => true
        ));

        // Populate empty post_types and user_roles with safe defaults
        if (empty($this->settings['post_types']) && function_exists('get_post_types')) {
            $this->settings['post_types'] = array_keys(get_post_types(array('public' => true), 'names'));
        }
        if (empty($this->settings['user_roles']) && function_exists('wp_roles')) {
            $this->settings['user_roles'] = array_keys(wp_roles()->roles);
        }

        // Initialize plugin
        $this->init();
    }

    /**
     * Initialize plugin
     */
    private function init() {
        // Legacy mode - if settings page is not enabled, use the original simple behavior
        if (empty($this->settings['show_settings_page'])) {
            // In simple mode, check per-post overrides if enabled, otherwise default to classic
            if (!empty($this->settings['allow_per_post_toggle'])) {
                add_filter('use_block_editor_for_post', array($this, 'disable_gutenberg_for_post_simple_mode'), 10, 2);
            } else {
                // Use a callback that checks enabled state instead of __return_false
                add_filter('use_block_editor_for_post', array($this, 'disable_gutenberg_simple_mode_no_toggle'), 10, 2);
            }
        } else {
            // Advanced mode - use settings to determine behavior
            add_filter('use_block_editor_for_post', array($this, 'disable_gutenberg_for_post'), 10, 2);
            add_filter('use_block_editor_for_post_type', array($this, 'disable_gutenberg_for_post_type'), 10, 2);
        }

        // Classic Widgets toggle is now independent - works in both simple and advanced modes
        // Only disable block widgets if the setting is enabled
        if (!empty($this->settings['use_classic_widgets'])) {
            add_action('after_setup_theme', array($this, 'disable_block_widgets'));
        }

        // Per-post toggle is now independent of advanced settings
        // It works in both simple and advanced modes
        // BUT it requires the main plugin to be enabled to have any effect
        if (!empty($this->settings['allow_per_post_toggle']) && !empty($this->settings['enabled'])) {
            // Add meta box to post edit screen
            add_action('add_meta_boxes', array($this, 'add_editor_choice_meta_box'));
            // Save post meta
            add_action('save_post', array($this, 'save_editor_choice'));
            // Add column to post list
            add_filter('manage_posts_columns', array($this, 'add_editor_column'));
            add_filter('manage_pages_columns', array($this, 'add_editor_column'));
            add_action('manage_posts_custom_column', array($this, 'display_editor_column'), 10, 2);
            add_action('manage_pages_custom_column', array($this, 'display_editor_column'), 10, 2);
            // Add quick edit option
            add_action('quick_edit_custom_box', array($this, 'add_quick_edit_field'), 10, 2);
            add_action('admin_enqueue_scripts', array($this, 'quick_edit_javascript'));
        }

        // Always add settings page and links
        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_styles'));

        // Add settings link to plugins page
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_settings_link'));
    }

    /**
     * Disable block widgets
     */
    public function disable_block_widgets() {
        remove_theme_support('widgets-block-editor');
    }

    /**
     * Simple mode without per-post toggle - always use classic editor if plugin is enabled
     */
    public function disable_gutenberg_simple_mode_no_toggle($use_block_editor, $post) {
        // If classic editor is not enabled, don't change the status
        if (empty($this->settings['enabled'])) {
            return $use_block_editor;
        }

        // Plugin is enabled, use classic editor
        return false;
    }

    /**
     * Handle per-post overrides in simple mode
     * In simple mode, classic editor is default but per-post toggle allows overrides
     */
    public function disable_gutenberg_for_post_simple_mode($use_block_editor, $post) {
        // If classic editor is not enabled, don't change the status
        if (empty($this->settings['enabled'])) {
            return $use_block_editor;
        }

        // Check if we have a post-specific setting
        $editor_choice = get_post_meta($post->ID, '_ecew_editor_choice', true);

        if ($editor_choice === 'block') {
            // Post explicitly wants to use block editor
            return true;
        } elseif ($editor_choice === 'classic') {
            // Post explicitly wants to use classic editor
            return false;
        }

        // No post-specific setting, default to classic editor in simple mode
        return false;
    }

    /**
     * Check if post should use classic editor based on post meta
     */
    public function disable_gutenberg_for_post($use_block_editor, $post) {
        // If classic editor is not enabled, don't change the status
        if (empty($this->settings['enabled'])) {
            return $use_block_editor;
        }

        // Check if we have a post-specific setting (only if per-post toggle is enabled)
        if (!empty($this->settings['allow_per_post_toggle'])) {
            $editor_choice = get_post_meta($post->ID, '_ecew_editor_choice', true);

            if ($editor_choice === 'block') {
                // Post explicitly wants to use block editor
                return true;
            } elseif ($editor_choice === 'classic') {
                // Post explicitly wants to use classic editor
                return false;
            }
        }

        // No post-specific setting, use post type setting (advanced mode only)
        // In simple mode with per-post toggle, we already return false in init()
        return $this->disable_gutenberg_for_post_type($use_block_editor, $post->post_type);
    }

    /**
     * Disable Gutenberg based on post type settings
     */
    public function disable_gutenberg_for_post_type($use_block_editor, $post_type) {
        // If classic editor is not enabled, don't change the status
        if (empty($this->settings['enabled'])) {
            return $use_block_editor;
        }
        
        // Check if current post type should use classic editor
        if (!in_array($post_type, $this->settings['post_types'])) {
            return $use_block_editor;
        }
        
        // Check if current user role should use classic editor
        $current_user = wp_get_current_user();
        $user_roles = array_intersect($current_user->roles, $this->settings['user_roles']);
        
        if (empty($user_roles)) {
            return $use_block_editor;
        }
        
        // Disable Gutenberg
        return false;
    }

    /**
     * Add meta box for editor choice
     */
    public function add_editor_choice_meta_box() {
        $post_types = get_post_types(array('public' => true), 'names');
        
        foreach ($post_types as $post_type) {
            add_meta_box(
                'ecew_editor_choice',
                __('Editor Preference', 'enable-classic-editor'),
                array($this, 'render_editor_choice_meta_box'),
                $post_type,
                'side',
                'high'
            );
        }
    }

    /**
     * Render meta box content
     */
    public function render_editor_choice_meta_box($post) {
        // Add nonce for security
        wp_nonce_field('ecew_editor_choice_nonce', 'ecew_editor_choice_nonce');

        // Get current value
        $editor_choice = get_post_meta($post->ID, '_ecew_editor_choice', true);

        // Default to post type setting if not set
        if (empty($editor_choice)) {
            $editor_choice = 'default';
        }

        // Determine what "default" means for this post
        $default_editor = $this->get_actual_editor_for_post($post);
        $default_editor_label = $default_editor === 'classic' ? __('Classic Editor', 'enable-classic-editor') : __('Block Editor', 'enable-classic-editor');
        ?>
        <p>
            <label>
                <input type="radio" name="ecew_editor_choice" value="default" <?php checked($editor_choice, 'default'); ?>>
                <?php _e('Use default setting', 'enable-classic-editor'); ?>
                <span class="description">(<?php echo esc_html($default_editor_label); ?>)</span>
            </label>
        </p>
        <p>
            <label>
                <input type="radio" name="ecew_editor_choice" value="classic" <?php checked($editor_choice, 'classic'); ?>>
                <?php _e('Force Classic Editor', 'enable-classic-editor'); ?>
            </label>
        </p>
        <p>
            <label>
                <input type="radio" name="ecew_editor_choice" value="block" <?php checked($editor_choice, 'block'); ?>>
                <?php _e('Force Block Editor', 'enable-classic-editor'); ?>
            </label>
        </p>
        <p class="description">
            <?php _e('This setting applies only to this post/page. Update/publish to see changes.', 'enable-classic-editor'); ?>
        </p>
        <?php
    }

    /**
     * Save editor choice meta
     */
    public function save_editor_choice($post_id) {
        // Check if autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Check permissions
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Determine if this is a quick edit or meta box save
        $is_quick_edit = isset($_POST['_inline_edit']);
        $is_meta_box = isset($_POST['ecew_editor_choice_nonce']);

        // Verify nonces based on the save type
        if ($is_quick_edit) {
            // Quick edit uses WordPress's built-in nonce
            if (!check_ajax_referer('inlineeditnonce', '_inline_edit', false)) {
                return;
            }
        } elseif ($is_meta_box) {
            // Meta box uses our custom nonce
            if (!wp_verify_nonce($_POST['ecew_editor_choice_nonce'], 'ecew_editor_choice_nonce')) {
                return;
            }
        } else {
            // No valid nonce found, exit
            return;
        }

        // Save editor choice
        if (isset($_POST['ecew_editor_choice'])) {
            update_post_meta($post_id, '_ecew_editor_choice', sanitize_text_field($_POST['ecew_editor_choice']));
        }
    }

    /**
     * Add editor column to post list
     */
    public function add_editor_column($columns) {
        $new_columns = array();
        
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            
            // Add editor column after title
            if ($key === 'title') {
                $new_columns['editor_choice'] = __('Editor', 'enable-classic-editor');
            }
        }
        
        return $new_columns;
    }

    /**
     * Display editor column content
     */
    public function display_editor_column($column, $post_id) {
        if ($column !== 'editor_choice') {
            return;
        }

        $editor_choice = get_post_meta($post_id, '_ecew_editor_choice', true);
        $post = get_post($post_id);

        // Determine actual editor that will be used
        $actual_editor = $this->get_actual_editor_for_post($post);

        switch ($editor_choice) {
            case 'classic':
                echo '<span class="dashicons dashicons-editor-kitchensink" title="' . esc_attr__('Classic Editor (Forced)', 'enable-classic-editor') . '" style="color: #2271b1;"></span>';
                break;
            case 'block':
                echo '<span class="dashicons dashicons-block-default" title="' . esc_attr__('Block Editor (Forced)', 'enable-classic-editor') . '" style="color: #2271b1;"></span>';
                break;
            default:
                // Show the actual editor that will be used
                $icon = $actual_editor === 'classic' ? 'editor-kitchensink' : 'block-default';
                $editor_name = $actual_editor === 'classic' ? __('Classic Editor', 'enable-classic-editor') : __('Block Editor', 'enable-classic-editor');
                $title = sprintf(
                    __('Default (%s)', 'enable-classic-editor'),
                    $editor_name
                );
                echo '<span class="dashicons dashicons-' . esc_attr($icon) . '" title="' . esc_attr($title) . '" style="opacity: 0.6;"></span>';
                break;
        }
    }

    /**
     * Add quick edit field
     */
    public function add_quick_edit_field($column_name, $post_type) {
        if ($column_name !== 'editor_choice') {
            return;
        }
        ?>
        <fieldset class="inline-edit-col-right">
            <div class="inline-edit-col">
                <label class="inline-edit-group">
                    <span class="title"><?php _e('Editor', 'enable-classic-editor'); ?></span>
                    <select name="ecew_editor_choice">
                        <option value="default"><?php _e('Default', 'enable-classic-editor'); ?></option>
                        <option value="classic"><?php _e('Classic Editor', 'enable-classic-editor'); ?></option>
                        <option value="block"><?php _e('Block Editor', 'enable-classic-editor'); ?></option>
                    </select>
                </label>
            </div>
        </fieldset>
        <?php
    }

    /**
     * Enqueue JavaScript for quick edit on post list screens
     */
    public function quick_edit_javascript() {
        $screen = get_current_screen();

        if (!$screen || !in_array($screen->base, array('edit', 'edit-tags'))) {
            return;
        }

        // Check if user has capability to edit posts
        if (!current_user_can('edit_posts')) {
            return;
        }

        // Enqueue the admin.js file which contains the quick edit functionality
        wp_enqueue_script(
            'classic-editor-admin-js',
            ECEW_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery', 'inline-edit-post'),
            ECEW_VERSION,
            true
        );
    }

    /**
     * Add settings page to admin menu
     */
    public function add_settings_page() {
        // Add a dedicated top-level menu for Classic Editor
        add_menu_page(
            __('Classic Editor', 'enable-classic-editor'),
            __('Classic Editor', 'enable-classic-editor'),
            'manage_options',
            'classic-editor-settings',
            array($this, 'render_settings_page'),
            'dashicons-editor-kitchensink',
            100
        );
        
        // Also keep the settings page under Settings menu for consistency
        add_options_page(
            __('Classic Editor Settings', 'enable-classic-editor'),
            __('Classic Editor', 'enable-classic-editor'),
            'manage_options',
            'classic-editor-settings',
            array($this, 'render_settings_page')
        );
    }

    /**
     * Register plugin settings
     */
    public function register_settings() {
        register_setting(
            'ecew_settings_group',
            'ecew_settings',
            array($this, 'sanitize_settings')
        );
    }

    /**
     * Sanitize settings
     */
    public function sanitize_settings($input) {
        $sanitized_input = array();

        // Sanitize enabled status
        $sanitized_input['enabled'] = isset($input['enabled']) ? 1 : 0;

        // Sanitize post types with validation against actual registered post types
        if (isset($input['post_types']) && is_array($input['post_types'])) {
            $valid_post_types = array_keys(get_post_types(array('public' => true), 'names'));
            $sanitized_input['post_types'] = array_intersect(
                array_map('sanitize_text_field', $input['post_types']),
                $valid_post_types
            );
        } else {
            $sanitized_input['post_types'] = array();
        }

        // Sanitize user roles with validation against actual registered roles
        if (isset($input['user_roles']) && is_array($input['user_roles'])) {
            $valid_roles = array_keys(wp_roles()->roles);
            $sanitized_input['user_roles'] = array_intersect(
                array_map('sanitize_text_field', $input['user_roles']),
                $valid_roles
            );
        } else {
            $sanitized_input['user_roles'] = array();
        }

        // Sanitize classic widgets toggle
        $sanitized_input['use_classic_widgets'] = isset($input['use_classic_widgets']) ? 1 : 0;

        // Sanitize settings page toggle (Advanced Settings)
        $sanitized_input['show_settings_page'] = isset($input['show_settings_page']) ? 1 : 0;

        // Sanitize per-post toggle
        $sanitized_input['allow_per_post_toggle'] = isset($input['allow_per_post_toggle']) ? 1 : 0;

        // Enforce dependencies: If main plugin is disabled, force dependent features OFF
        // This ensures data integrity regardless of JavaScript state or disabled form fields
        if (empty($sanitized_input['enabled'])) {
            $sanitized_input['allow_per_post_toggle'] = 0;
            $sanitized_input['show_settings_page'] = 0;
        }

        // Store last saved timestamp
        update_option('ecew_settings_last_saved', current_time('timestamp'));

        return $sanitized_input;
    }

    /**
     * Enqueue admin styles
     */
    public function enqueue_admin_styles($hook) {
        if (!in_array($hook, array('settings_page_classic-editor-settings', 'toplevel_page_classic-editor-settings'))) {
            return;
        }
        
        wp_enqueue_style(
            'classic-editor-admin',
            ECEW_PLUGIN_URL . 'classic-editor-admin.css',
            array(),
            ECEW_VERSION
        );

        // Add script for toggle switches
        wp_enqueue_script(
            'classic-editor-admin-js',
            ECEW_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            ECEW_VERSION,
            true
        );
        
        // Pass translatable strings and settings to JavaScript
        wp_localize_script(
            'classic-editor-admin-js',
            'ecewSettings',
            array(
                // Legacy setting (kept for compatibility)
                'resetConfirmMessage' => __('Are you sure you want to reset all settings to default values?', ECEW_TEXT_DOMAIN),

                // Translatable strings for admin.js
                'i18n' => array(
                    'unsavedChanges' => __('You have unsaved changes. Leave anyway?', ECEW_TEXT_DOMAIN),
                    'postTypesSelected' => __('%d of %d post types selected', ECEW_TEXT_DOMAIN),
                    'userRolesSelected' => __('%d of %d user roles selected', ECEW_TEXT_DOMAIN),
                    'settingsSaved' => __('Settings saved successfully!', ECEW_TEXT_DOMAIN),
                    'resetToDefaults' => __('Settings reset to defaults. Click "Save Settings" to apply.', ECEW_TEXT_DOMAIN)
                )
            )
        );
    }

    /**
     * Add settings link on plugin page
     */
    public function add_settings_link($links) {
        $settings_link = '<a href="admin.php?page=classic-editor-settings">' . __('Settings', 'enable-classic-editor') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * Helper function to output help tooltip
     */
    private function help_tooltip($text) {
        $tooltip_id = 'ecew-tooltip-' . md5($text);
        ?>
        <span class="ecew-help-tooltip"
              data-tooltip="<?php echo esc_attr($text); ?>"
              tabindex="0"
              role="button"
              aria-label="<?php echo esc_attr__('Help', ECEW_TEXT_DOMAIN); ?>"
              aria-describedby="<?php echo esc_attr($tooltip_id); ?>">
            <span class="dashicons dashicons-editor-help"></span>
            <span id="<?php echo esc_attr($tooltip_id); ?>" class="screen-reader-text"><?php echo esc_html($text); ?></span>
        </span>
        <?php
    }

    /**
     * Helper to determine actual editor for a post
     * This is what will actually be used when "Default" is selected
     */
    private function get_actual_editor_for_post($post) {
        // If plugin is disabled, WordPress default is Block Editor
        if (empty($this->settings['enabled'])) {
            return 'block';
        }

        // Simple mode - Classic Editor for everything
        if (empty($this->settings['show_settings_page'])) {
            return 'classic';
        }

        // Advanced mode - check post type and user role
        $post_type = is_object($post) ? $post->post_type : get_post_type($post);

        // Cache current user for performance (avoids multiple wp_get_current_user() calls)
        if ($this->current_user_cache === null) {
            $this->current_user_cache = wp_get_current_user();
        }

        $post_type_enabled = in_array($post_type, $this->settings['post_types']);
        $user_role_enabled = !empty(array_intersect($this->current_user_cache->roles, $this->settings['user_roles']));

        return ($post_type_enabled && $user_role_enabled) ? 'classic' : 'block';
    }

    /**
     * Get validation warnings for current settings
     */
    private function get_settings_warnings() {
        $warnings = array();

        // Check if plugin is disabled
        if (empty($this->settings['enabled'])) {
            $warnings[] = array(
                'type' => 'info',
                'message' => __('Classic Editor is currently disabled. WordPress will use the Block Editor by default.', 'enable-classic-editor')
            );
        }

        // Check Advanced Mode with no selections
        if (!empty($this->settings['show_settings_page']) && !empty($this->settings['enabled'])) {
            if (empty($this->settings['post_types'])) {
                $warnings[] = array(
                    'type' => 'warning',
                    'message' => __('Advanced Mode is enabled but no post types are selected. Classic Editor will not be applied to any content.', ECEW_TEXT_DOMAIN)
                );
            }
            if (empty($this->settings['user_roles'])) {
                $warnings[] = array(
                    'type' => 'warning',
                    'message' => __('Advanced Mode is enabled but no user roles are selected. Classic Editor will not be applied to any users.', ECEW_TEXT_DOMAIN)
                );
            }

            // Check if per-post toggle is enabled with Advanced Mode
            if (!empty($this->settings['allow_per_post_toggle'])) {
                $warnings[] = array(
                    'type' => 'info',
                    'message' => __('Per-Post Toggle is enabled in Advanced Mode. Individual post editor preferences can override Post Type and User Role restrictions. Consider disabling Per-Post Toggle if you want strict role-based control.', ECEW_TEXT_DOMAIN)
                );
            }
        }

        return $warnings;
    }

    /**
     * Render mode indicator section
     */
    private function render_mode_indicator($is_advanced_mode) {
        $current_mode = $is_advanced_mode ? __('Advanced Mode', ECEW_TEXT_DOMAIN) : __('Simple Mode', ECEW_TEXT_DOMAIN);
        ?>
        <div class="ecew-mode-indicator <?php echo esc_attr($is_advanced_mode ? 'advanced' : 'simple'); ?>">
            <span class="mode-badge"><?php echo esc_html($current_mode); ?></span>
            <p class="mode-description">
                <?php if ($is_advanced_mode): ?>
                    <?php _e('You are using Advanced Mode. Configure specific post types and user roles.', ECEW_TEXT_DOMAIN); ?>
                <?php else: ?>
                    <?php _e('You are using Simple Mode. Classic editor is enabled for all content. Enable "Advanced Settings" for granular control.', ECEW_TEXT_DOMAIN); ?>
                <?php endif; ?>
            </p>
        </div>
        <?php
    }

    /**
     * Render validation warnings
     */
    private function render_validation_warnings() {
        $warnings = $this->get_settings_warnings();
        if (empty($warnings)) {
            return;
        }

        foreach ($warnings as $warning):
            $notice_class = $warning['type'] === 'warning' ? 'notice-warning' : 'notice-info';
        ?>
            <div class="notice <?php echo esc_attr($notice_class); ?> inline">
                <p><?php echo esc_html($warning['message']); ?></p>
            </div>
        <?php
        endforeach;
    }

    /**
     * Render global settings section
     */
    private function render_global_settings_section() {
        ?>
        <div class="postbox">
            <h2 class="hndle"><span><?php _e('Global Settings', ECEW_TEXT_DOMAIN); ?></span></h2>
            <div class="inside">
                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="ecew_enabled">
                                    <?php _e('Enable Classic Editor', ECEW_TEXT_DOMAIN); ?>
                                    <?php $this->help_tooltip(__('When enabled, WordPress will use the Classic Editor instead of the Block Editor (Gutenberg) based on your configuration below.', ECEW_TEXT_DOMAIN)); ?>
                                </label>
                            </th>
                            <td>
                                <label class="toggle-switch" for="ecew_enabled" aria-label="<?php esc_attr_e('Toggle Classic Editor', ECEW_TEXT_DOMAIN); ?>">
                                    <input type="checkbox"
                                           id="ecew_enabled"
                                           name="ecew_settings[enabled]"
                                           value="1"
                                           <?php checked(!empty($this->settings['enabled'])); ?>>
                                    <span class="slider round"></span>
                                </label>
                                <p class="description">
                                    <?php _e('Uncheck to use Block Editor as the default editor.', ECEW_TEXT_DOMAIN); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="ecew_show_settings">
                                    <?php _e('Advanced Settings', ECEW_TEXT_DOMAIN); ?>
                                    <?php $this->help_tooltip(__('Enable this to configure Classic Editor by specific post types and user roles. When disabled, Classic Editor is enabled for all content (Simple Mode).', ECEW_TEXT_DOMAIN)); ?>
                                </label>
                            </th>
                            <td>
                                <label class="toggle-switch" for="ecew_show_settings" aria-label="<?php esc_attr_e('Toggle Advanced Settings', ECEW_TEXT_DOMAIN); ?>">
                                    <input type="checkbox"
                                           id="ecew_show_settings"
                                           name="ecew_settings[show_settings_page]"
                                           value="1"
                                           <?php checked(!empty($this->settings['show_settings_page'])); ?>
                                           <?php disabled(empty($this->settings['enabled'])); ?>>
                                    <span class="slider round"></span>
                                </label>
                                <p class="description">
                                    <?php _e('Enable to configure the classic editor by specific post types and user roles. When disabled, the plugin uses the classic editor for all content and users (Simple Mode).', ECEW_TEXT_DOMAIN); ?>
                                    <?php if (empty($this->settings['enabled'])): ?>
                                        <br><strong style="color: #d63638;">⚠️ <?php _e('Note: Advanced Settings requires "Enable Classic Editor" to be ON.', ECEW_TEXT_DOMAIN); ?></strong>
                                    <?php endif; ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="ecew_classic_widgets_global">
                                    <?php _e('Classic Widgets', ECEW_TEXT_DOMAIN); ?>
                                    <?php $this->help_tooltip(__('Restores the traditional widgets interface from before WordPress 5.8. This is independent of the editor settings and works in both Simple and Advanced modes.', ECEW_TEXT_DOMAIN)); ?>
                                </label>
                            </th>
                            <td>
                                <label class="toggle-switch" for="ecew_classic_widgets_global" aria-label="<?php esc_attr_e('Toggle Classic Widgets', ECEW_TEXT_DOMAIN); ?>">
                                    <input type="checkbox"
                                           id="ecew_classic_widgets_global"
                                           name="ecew_settings[use_classic_widgets]"
                                           value="1"
                                           <?php checked(!empty($this->settings['use_classic_widgets'])); ?>>
                                    <span class="slider round"></span>
                                </label>
                                <p class="description">
                                    <?php _e('Use Classic Widgets interface instead of block-based widgets (applies in all modes).', ECEW_TEXT_DOMAIN); ?>
                                    <br>
                                    <em style="color: #646970; font-size: 12px;">
                                        <?php _e('Note: This setting only applies to themes that support widgets. Most themes include widget areas, but some modern block themes may not have widget support.', ECEW_TEXT_DOMAIN); ?>
                                    </em>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="ecew_per_post_toggle">
                                    <?php _e('Per-Post Toggle', ECEW_TEXT_DOMAIN); ?>
                                    <?php $this->help_tooltip(__('Adds a meta box to each post/page edit screen allowing you to override the default editor choice for that specific post. Works in both Simple and Advanced modes. Priority: Per-post settings override Advanced Settings (Post Type & User Role).', ECEW_TEXT_DOMAIN)); ?>
                                </label>
                            </th>
                            <td>
                                <label class="toggle-switch" for="ecew_per_post_toggle" aria-label="<?php esc_attr_e('Toggle Per-Post Editor Selection', ECEW_TEXT_DOMAIN); ?>">
                                    <input type="checkbox"
                                           id="ecew_per_post_toggle"
                                           name="ecew_settings[allow_per_post_toggle]"
                                           value="1"
                                           <?php checked(!empty($this->settings['allow_per_post_toggle'])); ?>
                                           <?php disabled(empty($this->settings['enabled'])); ?>>
                                    <span class="slider round"></span>
                                </label>
                                <p class="description">
                                    <?php _e('Allows you to choose which editor (Classic or Block) to use for individual posts/pages. A meta box will appear on post edit screens. Works in both simple and advanced modes.', ECEW_TEXT_DOMAIN); ?>
                                    <?php if (empty($this->settings['enabled'])): ?>
                                        <br><strong style="color: #d63638;">⚠️ <?php _e('Note: This feature requires "Enable Classic Editor" to be ON.', ECEW_TEXT_DOMAIN); ?></strong>
                                    <?php endif; ?>
                                    <?php if (!empty($this->settings['show_settings_page'])): ?>
                                        <br><strong style="color: #d63638;">⚠️ <?php _e('Important: Per-post overrides take precedence over Advanced Settings (Post Type and User Role restrictions).', ECEW_TEXT_DOMAIN); ?></strong>
                                    <?php endif; ?>
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    /**
     * Render post type control section
     */
    private function render_post_type_control_section($post_types) {
        ?>
        <div class="postbox">
            <h2 class="hndle"><span><?php _e('Post Type Control', ECEW_TEXT_DOMAIN); ?></span></h2>
            <div class="inside">
                <?php if (!empty($this->settings['allow_per_post_toggle'])): ?>
                <div class="notice notice-info inline" style="margin: 0 0 15px 0; padding: 8px 12px;">
                    <p style="margin: 0.5em 0;">
                        <span class="dashicons dashicons-info" style="color: #2271b1;"></span>
                        <strong><?php _e('Note:', ECEW_TEXT_DOMAIN); ?></strong>
                        <?php _e('Per-Post Toggle is enabled. Individual post editor preferences will override the Post Type and User Role settings below.', ECEW_TEXT_DOMAIN); ?>
                    </p>
                </div>
                <?php endif; ?>
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 15px; gap: 15px;">
                    <p class="description" style="margin: 0; flex: 1;">
                        <?php _e('Select which post types should use the Classic Editor instead of Gutenberg.', ECEW_TEXT_DOMAIN); ?>
                    </p>
                    <div class="selection-controls" style="display: flex; gap: 8px; flex-shrink: 0;">
                        <button type="button" class="button button-small select-all-post-types"><?php _e('Select All', ECEW_TEXT_DOMAIN); ?></button>
                        <button type="button" class="button button-small deselect-all-post-types"><?php _e('Deselect All', ECEW_TEXT_DOMAIN); ?></button>
                    </div>
                </div>
                <div class="selection-count" style="margin-bottom: 12px; font-size: 13px; color: #646970;">
                    <span class="post-types-count">
                        <?php
                        $selected_count = count($this->settings['post_types']);
                        $total_count = count($post_types);
                        printf(__('%d of %d post types selected', ECEW_TEXT_DOMAIN), $selected_count, $total_count);
                        ?>
                    </span>
                </div>
                <div class="post-type-checkboxes">
                    <?php foreach ($post_types as $post_type) : ?>
                    <div class="checkbox-item">
                        <label for="ecew_post_type_<?php echo esc_attr($post_type->name); ?>">
                            <input type="checkbox"
                                id="ecew_post_type_<?php echo esc_attr($post_type->name); ?>"
                                name="ecew_settings[post_types][]"
                                value="<?php echo esc_attr($post_type->name); ?>"
                                <?php checked(in_array($post_type->name, $this->settings['post_types'])); ?>>
                            <?php echo esc_html($post_type->label); ?>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render user role control section
     */
    private function render_user_role_control_section($roles) {
        ?>
        <div class="postbox">
            <h2 class="hndle"><span><?php _e('User Role Control', ECEW_TEXT_DOMAIN); ?></span></h2>
            <div class="inside">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 15px; gap: 15px;">
                    <p class="description" style="margin: 0; flex: 1;">
                        <?php _e('Select which user roles should use the Classic Editor instead of Gutenberg.', ECEW_TEXT_DOMAIN); ?>
                    </p>
                    <div class="selection-controls" style="display: flex; gap: 8px; flex-shrink: 0;">
                        <button type="button" class="button button-small select-all-roles"><?php _e('Select All', ECEW_TEXT_DOMAIN); ?></button>
                        <button type="button" class="button button-small deselect-all-roles"><?php _e('Deselect All', ECEW_TEXT_DOMAIN); ?></button>
                    </div>
                </div>
                <div class="selection-count" style="margin-bottom: 12px; font-size: 13px; color: #646970;">
                    <span class="roles-count">
                        <?php
                        $selected_count_roles = count($this->settings['user_roles']);
                        $total_count_roles = count($roles);
                        printf(__('%d of %d user roles selected', ECEW_TEXT_DOMAIN), $selected_count_roles, $total_count_roles);
                        ?>
                    </span>
                </div>
                <div class="role-checkboxes">
                    <?php foreach ($roles as $role_key => $role) : ?>
                    <div class="checkbox-item">
                        <label for="ecew_role_<?php echo esc_attr($role_key); ?>">
                            <input type="checkbox"
                                id="ecew_role_<?php echo esc_attr($role_key); ?>"
                                name="ecew_settings[user_roles][]"
                                value="<?php echo esc_attr($role_key); ?>"
                                <?php checked(in_array($role_key, $this->settings['user_roles'])); ?>>
                            <?php echo esc_html(translate_user_role($role['name'])); ?>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        // Get all registered post types and roles
        $post_types = get_post_types(array('public' => true), 'objects');
        $roles = wp_roles()->roles;
        $is_advanced_mode = !empty($this->settings['show_settings_page']);
        ?>
        <div class="wrap classic-editor-plus-settings">
            <!-- Page Header -->
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                <h1 style="margin: 0;"><?php _e('Enable Classic Editor Settings', ECEW_TEXT_DOMAIN); ?></h1>
                <?php
                $last_saved = get_option('ecew_settings_last_saved', false);
                if ($last_saved):
                ?>
                <div class="ecew-last-saved" style="color: #646970; font-size: 13px;">
                    <?php
                    printf(
                        __('Last saved: %s ago', ECEW_TEXT_DOMAIN),
                        human_time_diff($last_saved, current_time('timestamp'))
                    );
                    ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Mode Indicator -->
            <?php $this->render_mode_indicator($is_advanced_mode); ?>

            <!-- Validation Warnings -->
            <?php $this->render_validation_warnings(); ?>

            <!-- WordPress Settings Errors -->
            <?php settings_errors('ecew_settings'); ?>

            <!-- Settings Form -->
            <form method="post" action="options.php">
                <?php settings_fields('ecew_settings_group'); ?>

                <div class="metabox-holder">
                    <!-- Global Settings Section -->
                    <?php $this->render_global_settings_section(); ?>

                    <!-- Advanced Settings Sections -->
                    <div class="advanced-settings" <?php echo empty($this->settings['show_settings_page']) ? 'style="display:none;"' : ''; ?>>
                        <?php $this->render_post_type_control_section($post_types); ?>
                        <?php $this->render_user_role_control_section($roles); ?>
                    </div>
                </div>

                <!-- Submit Buttons -->
                <div class="submit-buttons">
                    <button type="button" class="button button-secondary" id="reset-defaults"><?php _e('Reset Defaults', ECEW_TEXT_DOMAIN); ?></button>
                    <?php submit_button(__('Save Settings', ECEW_TEXT_DOMAIN), 'primary', 'submit', false); ?>
                </div>
            </form>
        </div>
        <?php
    }
}

// Initialize the plugin
Enable_Classic_Editor::get_instance();
