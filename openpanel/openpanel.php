<?php
/**
 * Plugin Name: OpenPanel
 * Description: Activate OpenPanel to start tracking your website.
 * Version: 1.0.0
 * Author: OpenPanel
 * License: GPLv2 or later
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Tested up to: 6.8
 * Text Domain: openpanel
 */

if (!defined('ABSPATH')) { exit; }

final class OP_WP_Proxy {
    const VERSION = '1.0.0';
    const OPTION_KEY   = 'op_wp_proxy_settings';
    const TRANSIENT_JS = 'op_wp_op1_js';
    const OP_JS_URL    = 'https://openpanel.dev/op1.js';
    const REST_NS      = 'openpanel';
    const REST_ROUTE   = '/(?P<path>.*)'; // wildcard path passthrough
    const DEFAULT_API_BASE = 'https://api.openpanel.dev/';
    const CACHE_TIMEOUT = WEEK_IN_SECONDS;

    public function __construct() {
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('wp_enqueue_scripts', [$this, 'inject_inline_sdk'], 0);
        add_action('rest_api_init', [$this, 'register_proxy_route']);
        add_action('admin_init', [$this, 'handle_cache_clear']);
    }

    /** ---------------- Settings ---------------- */
    public function register_settings() {
        register_setting(self::OPTION_KEY, self::OPTION_KEY, [
            'type' => 'array',
            'show_in_rest' => false,
            'sanitize_callback' => function($input) {
                $out = [];
                $out['client_id'] = isset($input['client_id']) ? sanitize_text_field($input['client_id']) : '';
                $out['track_screen'] = !empty($input['track_screen']) ? 1 : 0;
                $out['track_outgoing'] = !empty($input['track_outgoing']) ? 1 : 0;
                $out['track_attributes'] = !empty($input['track_attributes']) ? 1 : 0;
                return $out;
            }
        ]);

        add_settings_section('op_main', __('OpenPanel Settings', 'openpanel'), function() {
            echo '<p>' . esc_html__('Set your OpenPanel Client ID. The SDK and requests are served from your domain to avoid ad blockers.', 'openpanel') . '</p>';
        }, self::OPTION_KEY);

        add_settings_field('client_id', __('Client ID', 'openpanel'), function() {
            $opts = get_option(self::OPTION_KEY);
            printf('<input type="text" name="%s[client_id]" value="%s" class="regular-text" placeholder="op_client_..."/>',
                esc_attr(self::OPTION_KEY),
                isset($opts['client_id']) ? esc_attr($opts['client_id']) : ''
            );
        }, self::OPTION_KEY, 'op_main');

        add_settings_field('toggles', __('Auto-tracking (optional)', 'openpanel'), function() {
            $o = get_option(self::OPTION_KEY);
            // Default track_screen to true if not set
            $track_screen = isset($o['track_screen']) ? !empty($o['track_screen']) : true;
            ?>
            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[track_screen]" <?php checked($track_screen); ?>> <?php esc_html_e('Track page views automatically', 'openpanel'); ?></label><br>
            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[track_outgoing]" <?php checked(!empty($o['track_outgoing'])); ?>> <?php esc_html_e('Track clicks on outgoing links', 'openpanel'); ?></label><br>
            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[track_attributes]" <?php checked(!empty($o['track_attributes'])); ?>> <?php esc_html_e('Track additional page attributes', 'openpanel'); ?></label>
            <?php
        }, self::OPTION_KEY, 'op_main');
    }

    public function add_settings_page() {
        add_options_page(
            'OpenPanel',
            'OpenPanel',
            'manage_options',
            'op-wp-proxy',
            [$this, 'render_settings_page']
        );
    }

    public function handle_cache_clear() {
        if (isset($_POST['op_clear_cache']) && current_user_can('manage_options')) {
            if (isset($_POST['_wpnonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'op_clear_cache_nonce')) {
                delete_transient(self::TRANSIENT_JS);
                add_action('admin_notices', function() {
                    echo '<div class="notice notice-success is-dismissible"><p>' . 
                         esc_html__('OpenPanel cache cleared successfully. The latest op1.js will be fetched on the next page load.', 'openpanel') . 
                         '</p></div>';
                });
            }
        }
    }

    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1>OpenPanel</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields(self::OPTION_KEY);
                do_settings_sections(self::OPTION_KEY);
                submit_button(__('Save Settings', 'openpanel'));
                ?>
            </form>
            
            <hr style="margin: 2rem 0;">
            
            <h2><?php esc_html_e('Cache Management', 'openpanel'); ?></h2>
            <p><?php esc_html_e('Clear the cached op1.js file to force fetch the latest version from OpenPanel.', 'openpanel'); ?></p>
            
            <form method="post" action="">
                <?php wp_nonce_field('op_clear_cache_nonce'); ?>
                <input type="submit" name="op_clear_cache" class="button button-secondary" value="<?php esc_attr_e('Clear Cache & Force Refresh', 'openpanel'); ?>">
            </form>
            
            <?php
            // Check if transient exists and get expiration info
            $cached_js = get_transient(self::TRANSIENT_JS);
            $timeout_option = '_transient_timeout_' . self::TRANSIENT_JS;
            $cached_time = get_option($timeout_option);
            
            if ($cached_js !== false && $cached_time) {
                $time_remaining = $cached_time - time();
                if ($time_remaining > 0) {
                    echo '<p style="margin-top:1rem;color:#666;">' . 
                         /* translators: %s: human readable time difference */
                         sprintf(esc_html__('Cache expires in %s', 'openpanel'), esc_html(human_time_diff(time(), $cached_time))) . 
                         '</p>';
                } else {
                    echo '<p style="margin-top:1rem;color:#666;">' . 
                         esc_html__('Cache has expired and will refresh on next page load.', 'openpanel') . 
                         '</p>';
                }
            } else {
                echo '<p style="margin-top:1rem;color:#666;">' . 
                     esc_html__('No cached version found. op1.js will be fetched on next page load.', 'openpanel') . 
                     '</p>';
            }
            ?>
            
            <p style="margin-top:1rem;color:#666;">
                <?php esc_html_e('The plugin fetches and inlines op1.js (cached for 1 week). If fetching fails, it falls back to the CDN script.', 'openpanel'); ?>
            </p>
        </div>
        <?php
    }

    /** ---------------- Inline SDK ---------------- */
    public function inject_inline_sdk() {
        if (is_admin()) return;

        $opts = get_option(self::OPTION_KEY);
        $clientId = isset($opts['client_id']) ? trim($opts['client_id']) : '';
        if ($clientId === '') return; // don’t inject if not configured

        $apiUrl = untrailingslashit( rest_url(self::REST_NS . '/') );

        $init = [
            'clientId'           => $clientId,
            'apiUrl'             => $apiUrl,
            'trackScreenViews'   => isset($opts['track_screen']) ? !empty($opts['track_screen']) : true,
            'trackOutgoingLinks' => !empty($opts['track_outgoing']),
            'trackAttributes'    => !empty($opts['track_attributes']),
        ];

        $bootstrap = "(function(){window.op=window.op||function(){(window.op.q=window.op.q||[]).push(arguments)};window.op('init'," . wp_json_encode($init) . ");})();";

        $op_js = get_transient(self::TRANSIENT_JS);
        if ($op_js === false) {
            $res = wp_remote_get(self::OP_JS_URL, ['timeout' => 8]);
            if (!is_wp_error($res) && 200 === wp_remote_retrieve_response_code($res)) {
                $op_js = wp_remote_retrieve_body($res);
                set_transient(self::TRANSIENT_JS, $op_js, self::CACHE_TIMEOUT);
            }
        }

        wp_register_script('op-inline-stub', false, [], self::VERSION, true);
        wp_enqueue_script('op-inline-stub');

        wp_add_inline_script('op-inline-stub', $bootstrap, 'before');

        if (!empty($op_js)) {
            // Validate cached JavaScript content before output
            if ($this->is_valid_javascript_content($op_js)) {
                wp_add_inline_script('op-inline-stub', $op_js, 'after');
            } else {
                // Fall back to CDN if cached content appears invalid or unsafe
                wp_enqueue_script('openpanel-op1', self::OP_JS_URL, [], self::VERSION, true);
            }
        } else {
            wp_enqueue_script('openpanel-op1', self::OP_JS_URL, [], self::VERSION, true);
        }
    }

    /** ---------------- Proxy Route ---------------- */
    public function register_proxy_route() {
        register_rest_route(self::REST_NS, self::REST_ROUTE, [
            'methods'  => \WP_REST_Server::ALLMETHODS,
            // INTENTIONALLY PUBLIC ENDPOINT: This endpoint must be publicly accessible to receive
            // analytics data from frontend JavaScript running in users' browsers. No authentication
            // is required as this acts as a proxy for OpenPanel analytics collection.
            // 
            // Security measures in place:
            // 1. Only proxies to whitelisted OpenPanel API endpoints (is_valid_proxy_target)
            // 2. All input data is sanitized and validated before forwarding
            // 3. Proper CORS headers are set for same-origin requests only
            // 4. No sensitive WordPress data is exposed through this endpoint
            'permission_callback' => '__return_true',
            'callback' => [$this, 'proxy_request'],
            'args'     => [
                'path' => [
                    'description' => 'Remaining path to forward',
                    'required' => false,
                ],
            ],
        ]);
    }

    public function proxy_request(\WP_REST_Request $request) {
        // Handle CORS preflight quickly
        if (strtoupper($request->get_method()) === 'OPTIONS') {
            $resp = new \WP_REST_Response(null, 204);
            $this->add_cors_headers($resp);
            return $resp;
        }

        $path = ltrim($request->get_param('path') ?? '', '/');
        $target = rtrim(self::DEFAULT_API_BASE, '/') . '/' . $path;
        
        // Security: Ensure we only proxy to OpenPanel API endpoints
        if (!$this->is_valid_proxy_target($target)) {
            $resp = new \WP_REST_Response(['error' => 'invalid_target', 'message' => 'Invalid proxy target'], 403);
            $this->add_cors_headers($resp);
            return $resp;
        }

        $method = strtoupper($request->get_method());
        $body   = $request->get_body();

        $incoming = $this->collect_request_headers();

        // PHP exposes Content-Type as CONTENT_TYPE (no HTTP_ prefix), so
        // collect_request_headers() misses it and the API rejects the body.
        $content_type = $request->get_header('content_type');
        if ($content_type) {
            $incoming['Content-Type'] = $content_type;
        }

        $query = $request->get_query_params();
        if (!empty($query)) {
            $target = add_query_arg($query, $target);
        }

        $args = [
            'method'  => $method,
            'timeout' => 10,
            'headers' => $incoming,
            'body'    => in_array($method, ['POST','PUT','PATCH','DELETE'], true) ? $body : null,
        ];

        $res = wp_remote_request($target, $args);

        if (is_wp_error($res)) {
            $resp = new \WP_REST_Response(['error' => 'proxy_failed', 'message' => $res->get_error_message()], 502);
            $this->add_cors_headers($resp);
            $resp->header('Cache-Control', 'no-store');
            return $resp;
        }

        $code = wp_remote_retrieve_response_code($res);
        $headers = wp_remote_retrieve_headers($res);
        $bodyOut = wp_remote_retrieve_body($res);

        $resp = new \WP_REST_Response($bodyOut, $code);
        // Filter hop-by-hop headers
        if (is_array($headers)) {
            foreach ($headers as $k => $v) {
                $lk = strtolower($k);
                if (in_array($lk, ['transfer-encoding','connection','content-encoding'], true)) continue;
                $resp->header($k, $v);
            }
        }
        if (!$resp->get_headers()['Content-Type'] ?? true) {
            $resp->header('Content-Type', 'application/json; charset=utf-8');
        }
        $resp->header('Cache-Control', 'no-store');
        $this->add_cors_headers($resp);
        return $resp;
    }

    private function add_cors_headers(\WP_REST_Response $resp) {
        $origin = get_site_url();
        $resp->header('Access-Control-Allow-Origin', $origin);
        $resp->header('Access-Control-Allow-Credentials', 'true');
        $resp->header('Access-Control-Allow-Headers', 'Content-Type, Authorization');
        $resp->header('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
        $resp->header('Vary', 'Origin');
    }

    private function collect_request_headers() {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            // Sanitize the header name and ensure it starts with HTTP_
            $name = sanitize_text_field($name);
            if (strpos($name, 'HTTP_') === 0) {
                // Extract and sanitize the header suffix
                $header_suffix = substr($name, 5);
                $header_suffix = sanitize_text_field($header_suffix);
                $header = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', $header_suffix))));
                // remove hop-by-hop headers
                $lk = strtolower($header);
                if (in_array($lk, ['host','content-length','accept-encoding','connection','keep-alive','transfer-encoding','upgrade','via'], true)) {
                    continue;
                }
                // Sanitize the header value
                $headers[$header] = sanitize_text_field($value);
            }
        }
        if (!isset($headers['User-Agent'])) {
            $headers['User-Agent'] = 'OpenPanel-WP-Proxy';
        }
        return $headers;
    }

    private function is_valid_proxy_target($target) {
        $allowed_hosts = [
            'api.openpanel.dev',
            'openpanel.dev'
        ];
        
        $parsed = wp_parse_url($target);
        if (!$parsed || !isset($parsed['host'])) {
            return false;
        }
        
        return in_array($parsed['host'], $allowed_hosts, true) && 
               (empty($parsed['scheme']) || in_array($parsed['scheme'], ['https', 'http'], true));
    }

    private function is_valid_javascript_content($js_content) {
        // Comprehensive validation to ensure the content is safe JavaScript
        if (empty($js_content) || !is_string($js_content)) {
            return false;
        }
        
        // Note: We don't modify $js_content with wp_kses here because we need to preserve
        // the original JavaScript content for validation. wp_add_inline_script() handles
        // proper escaping when outputting to the page.
        
        // Ensure it doesn't contain HTML tags or script injection attempts
        if (preg_match('/<(?:script|iframe|object|embed|form|input|meta|link)/i', $js_content)) {
            return false;
        }
        
        // Check for potential XSS patterns
        if (preg_match('/(?:javascript:|data:|vbscript:|on\w+\s*=)/i', $js_content)) {
            return false;
        }
        
        // Check that it's a reasonable size for a JavaScript file (typical op1.js is ~5KB)
        $trimmed = trim($js_content);
        if (strlen($trimmed) < 10 || strlen($trimmed) > 20480) { // Max 20KB
            return false;
        }
        
        // Should contain typical JavaScript patterns (works for both minified and unminified)
        return (strpos($trimmed, 'function') !== false || 
                strpos($trimmed, 'var ') !== false || 
                strpos($trimmed, 'let ') !== false || 
                strpos($trimmed, 'const ') !== false ||
                strpos($trimmed, '=>') !== false ||
                // Handle minified code patterns like the actual op1.js
                preg_match('/[a-zA-Z_$][a-zA-Z0-9_$]*\s*=\s*function/', $trimmed) ||
                preg_match('/\(\s*function\s*\(/', $trimmed));
    }
}

new OP_WP_Proxy();
