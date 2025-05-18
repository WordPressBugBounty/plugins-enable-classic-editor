<?php
/*
Plugin Name: Enable Classic Editor & Widgets
Plugin URI: https://www.ayonm.com
Description: A simple & lightweight plugin to enable the classic editor on WordPress with advanced configuration options.
Version: 3.0
Author: Ayon M
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
        // Get plugin settings or use defaults
        $this->settings = get_option('ecew_settings', array(
            'enabled' => true,
            'post_types' => array_keys(get_post_types(array('public' => true), 'names')),
            'user_roles' => array_keys(wp_roles()->roles),
            'use_classic_widgets' => true,
            'show_settings_page' => false,
            'allow_per_post_toggle' => true
        ));

        // Initialize plugin
        $this->init();
    }

    /**
     * Initialize plugin
     */
    private function init() {
        // Legacy mode - if settings page is not enabled, use the original simple behavior
        if (empty($this->settings['show_settings_page'])) {
            add_filter('use_block_editor_for_post', '__return_false');
            add_action('after_setup_theme', array($this, 'disable_block_widgets'));
        } else {
            // Advanced mode - use settings to determine behavior
            add_filter('use_block_editor_for_post', array($this, 'disable_gutenberg_for_post'), 10, 2);
            add_filter('use_block_editor_for_post_type', array($this, 'disable_gutenberg_for_post_type'), 10, 2);
            
            if (!empty($this->settings['use_classic_widgets'])) {
                add_action('after_setup_theme', array($this, 'disable_block_widgets'));
            }
            
            // Add per-post toggle if enabled
            if (!empty($this->settings['allow_per_post_toggle'])) {
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
                add_action('admin_footer', array($this, 'quick_edit_javascript'));
            }
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
     * Check if post should use classic editor based on post meta
     */
    public function disable_gutenberg_for_post($use_block_editor, $post) {
        // Check if we have a post-specific setting
        $editor_choice = get_post_meta($post->ID, '_ecew_editor_choice', true);
        
        if ($editor_choice === 'block') {
            // Post explicitly wants to use block editor
            return true;
        } elseif ($editor_choice === 'classic') {
            // Post explicitly wants to use classic editor
            return false;
        }
        
        // No post-specific setting, use post type setting
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
        ?>
        <p>
            <label>
                <input type="radio" name="ecew_editor_choice" value="default" <?php checked($editor_choice, 'default'); ?>>
                <?php _e('Use default setting', 'enable-classic-editor'); ?>
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
        // Check if nonce is set
        if (!isset($_POST['ecew_editor_choice_nonce'])) {
            return;
        }
        
        // Verify nonce
        if (!wp_verify_nonce($_POST['ecew_editor_choice_nonce'], 'ecew_editor_choice_nonce')) {
            return;
        }
        
        // Check if autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        // Check permissions
        if (!current_user_can('edit_post', $post_id)) {
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
        
        switch ($editor_choice) {
            case 'classic':
                echo '<span class="dashicons dashicons-editor-kitchensink" title="' . esc_attr__('Classic Editor', 'enable-classic-editor') . '"></span>';
                break;
            case 'block':
                echo '<span class="dashicons dashicons-block-default" title="' . esc_attr__('Block Editor', 'enable-classic-editor') . '"></span>';
                break;
            default:
                echo '<span class="dashicons dashicons-admin-generic" title="' . esc_attr__('Default Editor', 'enable-classic-editor') . '"></span>';
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
     * Add JavaScript for quick edit
     */
    public function quick_edit_javascript() {
        $screen = get_current_screen();
        
        if (!$screen || !in_array($screen->base, array('edit', 'edit-tags'))) {
            return;
        }
        ?>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
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
            });
        </script>
        <?php
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
        
        // Sanitize post types
        if (isset($input['post_types']) && is_array($input['post_types'])) {
            $sanitized_input['post_types'] = array_map('sanitize_text_field', $input['post_types']);
        } else {
            $sanitized_input['post_types'] = array();
        }
        
        // Sanitize user roles
        if (isset($input['user_roles']) && is_array($input['user_roles'])) {
            $sanitized_input['user_roles'] = array_map('sanitize_text_field', $input['user_roles']);
        } else {
            $sanitized_input['user_roles'] = array();
        }
        
        // Sanitize classic widgets toggle
        $sanitized_input['use_classic_widgets'] = isset($input['use_classic_widgets']) ? 1 : 0;
        
        // Sanitize settings page toggle
        $sanitized_input['show_settings_page'] = isset($input['show_settings_page']) ? 1 : 0;
        
        // Sanitize per-post toggle
        $sanitized_input['allow_per_post_toggle'] = isset($input['allow_per_post_toggle']) ? 1 : 0;
        
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
            plugin_dir_url(__FILE__) . 'classic-editor-admin.css',
            array()
        );

        // Add script for toggle switches
        wp_enqueue_script(
            'classic-editor-admin-js',
            plugin_dir_url(__FILE__) . 'assets/js/admin.js',
            array('jquery'),
            null,
            true
        );
        
        // Pass settings to JavaScript
        wp_localize_script(
            'classic-editor-admin-js',
            'ecewSettings',
            array(
                'resetConfirmMessage' => __('Are you sure you want to reset all settings to default values?', 'enable-classic-editor')
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
     * Render settings page
     */
    public function render_settings_page() {
        // Get all registered post types
        $post_types = get_post_types(array('public' => true), 'objects');
        
        // Get all user roles
        $roles = wp_roles()->roles;
        ?>
        <div class="wrap classic-editor-plus-settings">
            <h1><?php _e('Enable Classic Editor Settings', 'enable-classic-editor'); ?></h1>
            
            <?php settings_errors('ecew_settings'); ?>
            
            <form method="post" action="options.php">
                <?php settings_fields('ecew_settings_group'); ?>
                
                <div class="metabox-holder">
                    <!-- Global Settings Section -->
                    <div class="postbox">
                        <h2 class="hndle"><span><?php _e('Global Settings', 'enable-classic-editor'); ?></span></h2>
                        <div class="inside">
                            <table class="form-table">
                                <tbody>
                                    <tr>
                                        <th scope="row">
                                            <label for="ecew_enabled">
                                                <?php _e('Enable Classic Editor', 'enable-classic-editor'); ?>
                                            </label>
                                        </th>
                                        <td>
                                            <label class="toggle-switch" for="ecew_enabled">
                                                <input type="checkbox" 
                                                       id="ecew_enabled" 
                                                       name="ecew_settings[enabled]" 
                                                       value="1"
                                                       <?php checked(!empty($this->settings['enabled'])); ?>>
                                                <span class="slider round"></span>
                                            </label>
                                            <p class="description">
                                                <?php _e('Uncheck to use Block Editor as the default editor.', 'enable-classic-editor'); ?>
                                            </p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">
                                            <label for="ecew_show_settings">
                                                <?php _e('Advanced Settings', 'enable-classic-editor'); ?>
                                            </label>
                                        </th>
                                        <td>
                                            <label class="toggle-switch" for="ecew_show_settings">
                                                <input type="checkbox" 
                                                       id="ecew_show_settings" 
                                                       name="ecew_settings[show_settings_page]" 
                                                       value="1"
                                                       <?php checked(!empty($this->settings['show_settings_page'])); ?>>
                                                <span class="slider round"></span>
                                            </label>
                                            <p class="description">
                                                <?php _e('When disabled, the plugin will use the classic editor for all content (original behavior).', 'enable-classic-editor'); ?>
                                            </p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">
                                            <label for="ecew_per_post_toggle">
                                                <?php _e('Per-Post Toggle', 'enable-classic-editor'); ?>
                                            </label>
                                        </th>
                                        <td>
                                            <label class="toggle-switch" for="ecew_per_post_toggle">
                                                <input type="checkbox" 
                                                       id="ecew_per_post_toggle" 
                                                       name="ecew_settings[allow_per_post_toggle]" 
                                                       value="1"
                                                       <?php checked(!empty($this->settings['allow_per_post_toggle'])); ?>>
                                                <span class="slider round"></span>
                                            </label>
                                            <p class="description">
                                                <?php _e('When enabled, a meta box will be added to the post/page edit screen to select which editor to use.', 'enable-classic-editor'); ?>
                                            </p>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Advanced Settings Sections -->
                    <div class="advanced-settings" <?php echo empty($this->settings['show_settings_page']) ? 'style="display:none;"' : ''; ?>>
                        <!-- Post Type Control Section -->
                        <div class="postbox">
                            <h2 class="hndle"><span><?php _e('Post Type Control', 'enable-classic-editor'); ?></span></h2>
                            <div class="inside">
                                <p class="description">
                                    <?php _e('Select which post types should use the Classic Editor instead of Gutenberg.', 'enable-classic-editor'); ?>
                                </p>
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
                        
                        <!-- User Role Control Section -->
                        <div class="postbox">
                            <h2 class="hndle"><span><?php _e('User Role Control', 'enable-classic-editor'); ?></span></h2>
                            <div class="inside">
                                <p class="description">
                                    <?php _e('Select which user roles should use the Classic Editor instead of Gutenberg.', 'enable-classic-editor'); ?>
                                </p>
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
                        
                        <!-- Classic Widgets Toggle Section -->
                        <div class="postbox">
                            <h2 class="hndle"><span><?php _e('Classic Widgets Control', 'enable-classic-editor'); ?></span></h2>
                            <div class="inside">
                                <table class="form-table">
                                    <tbody>
                                        <tr>
                                            <th scope="row">
                                                <label for="ecew_classic_widgets">
                                                    <?php _e('Widget Interface', 'enable-classic-editor'); ?>
                                                </label>
                                            </th>
                                            <td>
                                                <label class="toggle-switch" for="ecew_classic_widgets">
                                                    <input type="checkbox" 
                                                           id="ecew_classic_widgets" 
                                                           name="ecew_settings[use_classic_widgets]" 
                                                           value="1"
                                                           <?php checked(!empty($this->settings['use_classic_widgets'])); ?>>
                                                    <span class="slider round"></span>
                                                </label>
                                                <p class="description">
                                                    <?php _e('Use Classic Widgets interface instead of block-based widgets', 'enable-classic-editor'); ?>
                                                </p>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="submit-buttons">
                    <button type="button" class="button button-secondary" id="reset-defaults"><?php _e('Reset Defaults', 'enable-classic-editor'); ?></button>
                    <?php submit_button(__('Save Settings', 'enable-classic-editor'), 'primary', 'submit', false); ?>
                </div>
            </form>
        </div>
        <?php
    }
}

// Initialize the plugin
Enable_Classic_Editor::get_instance();
