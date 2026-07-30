<?php

// Minimal WordPress/PHPStan stubs for analysis. No implementation, only signatures.

if (! defined('ABSPATH')) {
    define('ABSPATH', dirname(dirname(__DIR__)) . '/');
}

if (! defined('WHOP_WOOCOMMERCE_FILE')) {
    define('WHOP_WOOCOMMERCE_FILE', dirname(dirname(__DIR__)) . '/whop-woocommerce.php');
}

if (! defined('WHOP_WOOCOMMERCE_TEMPLATES')) {
    define('WHOP_WOOCOMMERCE_TEMPLATES', dirname(dirname(__DIR__)) . '/templates');
}

if (! defined('WHOP_WOOCOMMERCE_ASSETS_URL')) {
    define('WHOP_WOOCOMMERCE_ASSETS_URL', '');
}

if (! defined('WHOP_WOOCOMMERCE_VERSION')) {
    define('WHOP_WOOCOMMERCE_VERSION', '0.1.0');
}

// Core classes
class WP_User {
    public int $ID;
    public string $user_email;
    public string $display_name;
    public string $user_firstname;
    public string $user_lastname;
    public function exists(): bool {}
}

class WP_REST_Request {
    public function get_header(string $name): string|false {}
}
class WP_REST_Response {
    public function set_status(int $code): self {}
}
class WP_REST_Server {
    public const CREATABLE = 'POST';
    public function get_routes(): array {}
}

// WooCommerce placeholders may be in separate stub file.

// Functions used by plugin
function add_action(string $hook, $callable, $priority = 10, $accepted_args = 1) {}
function add_filter(string $hook, $callable, $priority = 10, $accepted_args = 1) {}
function register_rest_route(string $namespace, string $route, array $args) {}
function rest_ensure_response($value): WP_REST_Response {}
function rest_get_server(): WP_REST_Server {}
function plugin_basename($file) {}
function add_option($name, $value, $deprecated = '', $autoload = 'yes') {}
function get_option($name, $default = null) {}
function current_time($type, $gmt = 0) {}
function wp_parse_url($url, $component = -1) {}
function is_user_logged_in(): bool {}
function wp_get_current_user(): WP_User {}
function sanitize_email(string $email): string {}
function sanitize_text_field(string $text): string {}
function wp_unslash($value) {}
function __($text, $domain = null) {}
function esc_html__($text, $domain = null) {}
function esc_attr($text) {}
function esc_html($text) {}
function esc_html_e($text, $domain = null) {}
function settings_fields($option_group) {}
function do_settings_sections($page) {}
function submit_button($text = null) {}
function wp_enqueue_script($handle, $src, $deps = [], $ver = false, $in_footer = false) {}
function wp_localize_script($handle, $object_name, $l10n) {}
function wp_create_nonce($action = -1) {}
function check_ajax_referer($action = -1, $query_arg = false, $die = true) {}
function wp_send_json_error($response = null, $status_code = null) {}
function wp_send_json_success($response = null, $status_code = null) {}
function is_plugin_active($plugin) {}
function plugin_dir_url($file) {}
// plugin_basename declared further above; avoid duplicate declaration in stubs.
// function plugin_basename($file) {}
function register_activation_hook($file, $callback) {}
function register_deactivation_hook($file, $callback) {}
function register_uninstall_hook($file, $callback) {}
function register_setting($option_group, $option_name, $args = []) {}
function add_settings_section($id, $title, $callback, $page) {}
function add_settings_field($id, $title, $callback, $page, $section) {}
function settings_errors() {}
function admin_url($path = '') {}
function wp_nonce_field($action = -1, $name = '_wpnonce', $referer = true, $echo = true) {}
function wp_verify_nonce($nonce, $action = -1) {}
function wp_get_referer() {}
function home_url($path = '') {}
function wp_safe_redirect($location, $status = 302) {}
function add_submenu_page($parent_slug, $page_title, $menu_title, $capability, $menu_slug, $function = '') {}
function current_user_can($capability) {}
function checked($checked, $current = true, $echo = true) {}
function wp_generate_password($length = 12, $special_chars = true, $extra_special_chars = false) {}
function wp_generate_uuid4() {}
function wp_json_encode($value, $options = 0, $depth = 512) {}
function absint($value) {}
function esc_url($url) {}
function esc_url_raw($url, $protocols = null) {}
function deactivate_plugins($plugins, $silent = false) {}
function get_current_user_id() {}
// do not stub PHP language construct 'exit' — remove stub to avoid parse error
function wp_die($message = '', $title = '', $args = []) {}

// Transients
function set_transient($transient, $value, $expiration) {}
function get_transient($transient) {}
function delete_transient($transient) {}

// HTTP
function wp_remote_get($url, $args = []) {}
function wp_remote_post($url, $args = []) {}
function is_wp_error($thing) {}
function wp_remote_retrieve_response_code($response) {}
function wp_remote_retrieve_body($response) {}

// Options
function update_option($option, $value) {}
function delete_option($option) {}

// Post meta and post helpers
function get_post_meta($post_id, $key = '', $single = false) {}
function update_post_meta($post_id, $meta_key, $meta_value) {}
function get_post_status($post_id) {}
function wp_delete_post($post_id, $force_delete = false) {}

// WooCommerce helpers stubbed here minimally for PHPStan
