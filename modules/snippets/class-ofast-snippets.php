<?php

/**
 * Ofast X - Code Snippets Module (Core)
 * Lean orchestrator: loads sub-classes, handles initialization,
 * snippet execution, shared utilities, schema, caching, and logging.
 *
 * Sub-classes:
 *  - Ofast_X_Snippets_Validator  → Validation, conflict & duplicate detection
 *  - Ofast_X_Snippets_Admin      → Admin page rendering, dashboard widget, CodeMirror
 *  - Ofast_X_Snippets_Ajax       → All AJAX handlers (toggle, delete, rename, bulk, etc.)
 *  - Ofast_X_Snippets_Import     → Import/export, plugin detection, selective import
 *
 * @since 1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Load sub-classes
require_once __DIR__ . '/class-ofast-snippets-validator.php';
require_once __DIR__ . '/class-ofast-snippets-admin.php';
require_once __DIR__ . '/class-ofast-snippets-ajax.php';
require_once __DIR__ . '/class-ofast-snippets-import.php';

class Ofast_X_Snippets
{
    /** @var Ofast_X_Snippets_Validator */
    public $validator;

    /** @var Ofast_X_Snippets_Admin */
    public $admin;

    /** @var Ofast_X_Snippets_Ajax */
    public $ajax;

    /** @var Ofast_X_Snippets_Import */
    public $importer;

    /**
     * Initialize module
     */
    public function init()
    {
        // Instantiate sub-classes
        $this->validator = new Ofast_X_Snippets_Validator($this);
        $this->admin     = new Ofast_X_Snippets_Admin($this);
        $this->ajax      = new Ofast_X_Snippets_Ajax($this);
        $this->importer  = new Ofast_X_Snippets_Import($this);

        // Register hooks from sub-classes
        $this->admin->register_hooks();
        $this->ajax->register_hooks();
        $this->importer->register_hooks();

        // Safe Mode: propagate ?ofast-safe-mode=1 through all admin/home URLs
        if ($this->is_safe_mode_requested()) {
            add_filter('admin_url', array($this, 'add_safe_mode_query_var'));
            add_filter('home_url', array($this, 'add_safe_mode_query_var'));
        }

        // Execute active snippets on plugins_loaded (priority 99) — late enough for
        // Ofast-X to be fully loaded, but early enough that snippet-registered hooks
        // (e.g. add_action('init', ...)) still fire at their default priority.
        add_action('plugins_loaded', array($this, 'execute_snippets'), 99);

        // Auto-purge trashed snippets via daily cron
        add_action('ofast_purge_trashed_snippets', array($this, 'purge_old_trashed_snippets'));
        if (!wp_next_scheduled('ofast_purge_trashed_snippets')) {
            wp_schedule_event(time(), 'daily', 'ofast_purge_trashed_snippets');
        }

        // Show runtime error notices
        add_action('admin_notices', array($this, 'show_runtime_error_notice'));
    }

    // =========================================================================
    // SAFE MODE — Bypass snippet execution via ?ofast-safe-mode=1
    // =========================================================================

    /**
     * Check if safe mode is requested (raw URL param check).
     * Used during early hooks (plugins_loaded) when user caps aren't ready.
     */
    public function is_safe_mode_requested()
    {
        return !empty($_REQUEST['ofast-safe-mode']);
    }

    /**
     * Check if safe mode is fully active (URL param + admin capability).
     * Used during admin_notices and other late hooks when user session is ready.
     */
    public function is_safe_mode()
    {
        return $this->is_safe_mode_requested() && current_user_can('manage_options');
    }

    /**
     * Propagate the safe mode query var through all admin/home URLs
     * so navigating the admin doesn't lose the safe mode flag.
     */
    public function add_safe_mode_query_var($url)
    {
        if (!empty($_REQUEST['ofast-safe-mode'])) {
            $url = add_query_arg('ofast-safe-mode', '1', $url);
        }
        return $url;
    }

    // =========================================================================
    // SHARED UTILITIES — used by sub-classes via $this->core->method()
    // =========================================================================

    /**
     * Normalize PHP snippets so pasted wrappers do not break execution.
     */
    public function normalize_php_code($code)
    {
        $code = (string) $code;
        $code = preg_replace('/^\xEF\xBB\xBF/', '', $code); // Remove UTF-8 BOM.
        // Normalize smart/curly quotes to straight quotes (common when copy-pasting from web/docs)
        $code = str_replace(
            array("\xe2\x80\x98", "\xe2\x80\x99", "\xe2\x80\x9c", "\xe2\x80\x9d", "\xc2\xab", "\xc2\xbb"),
            array("'", "'", '"', '"', '"', '"'),
            $code
        );
        $code = trim($code);

        if ($code === '') {
            return '';
        }

        // Convert short echo syntax wrapper to plain PHP expression.
        $code = preg_replace('/^\s*\<\?=\s*/i', 'echo ', $code);

        // Strip a single outer PHP wrapper if present.
        $code = preg_replace('/^\s*\<\?(?:php)?\s*/i', '', $code);
        $code = preg_replace('/\s*\?\>\s*$/', '', $code);

        return trim($code);
    }

    /**
     * Normalize language value to supported snippet types.
     */
    public function normalize_snippet_language($language)
    {
        $language = strtolower(trim((string) $language));

        $aliases = array(
            'js' => 'javascript',
            'htm' => 'html',
            'text' => 'html',
        );
        if (isset($aliases[$language])) {
            $language = $aliases[$language];
        }

        return in_array($language, array('php', 'javascript', 'css', 'html'), true) ? $language : 'php';
    }

    // =========================================================================
    // SCHEMA MANAGEMENT — ensures DB columns exist
    // =========================================================================

    /**
     * Ensure language column supports HTML values for snippet saves.
     */
    public function ensure_snippets_language_schema()
    {
        static $checked = false;
        static $supported = false;

        if ($checked) {
            return $supported;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ofast_snippets';
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));

        if (!$table_exists) {
            $checked = true;
            $supported = false;
            return false;
        }

        $column = $wpdb->get_row("SHOW COLUMNS FROM {$table} LIKE 'language'");
        if (!$column) {
            $checked = true;
            $supported = false;
            return false;
        }

        $column_type = strtolower((string) $column->Type);
        if (strpos($column_type, 'enum(') === 0 && strpos($column_type, "'html'") === false) {
            $wpdb->query("ALTER TABLE {$table} MODIFY COLUMN language ENUM('php', 'javascript', 'css', 'html') DEFAULT 'php'");
            $column = $wpdb->get_row("SHOW COLUMNS FROM {$table} LIKE 'language'");
            $column_type = $column ? strtolower((string) $column->Type) : '';
        }

        // Enum with html support is ideal; varchar/text columns are also acceptable.
        $supported = (strpos($column_type, "'html'") !== false) || (strpos($column_type, 'char') !== false) || (strpos($column_type, 'text') !== false);
        $checked = true;

        return $supported;
    }

    /**
     * Ensure priority column exists for snippets table.
     * Returns true when priority schema is available.
     */
    public function ensure_snippets_priority_schema()
    {
        static $checked = false;
        static $supported = false;

        if ($checked) {
            return $supported;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ofast_snippets';
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));

        if (!$table_exists) {
            $checked = true;
            $supported = false;
            return false;
        }

        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
        if (!in_array('priority', $columns, true)) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN priority INT(11) DEFAULT 10 AFTER executed_at");
            $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
        }

        $supported = in_array('priority', $columns, true);
        $checked = true;

        return $supported;
    }

    /**
     * Ensure trash columns exist for snippets table.
     * Returns true when trash schema is available.
     */
    public function ensure_snippets_trash_schema()
    {
        static $checked = false;
        static $supported = false;

        if ($checked) {
            return $supported;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ofast_snippets';
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));

        if (!$table_exists) {
            $checked = true;
            $supported = false;
            return false;
        }

        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);

        if (!in_array('status', $columns, true)) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN status VARCHAR(20) DEFAULT 'active'");
            $columns[] = 'status';
        }

        if (!in_array('trashed_at', $columns, true)) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN trashed_at DATETIME DEFAULT NULL");
            $columns[] = 'trashed_at';
        }

        if (in_array('status', $columns, true)) {
            $wpdb->query("UPDATE {$table} SET status = 'active' WHERE status IS NULL OR status = ''");
        }

        $supported = in_array('status', $columns, true) && in_array('trashed_at', $columns, true);
        $checked = true;

        return $supported;
    }

    // =========================================================================
    // SNIPPET EXECUTION ENGINE
    // =========================================================================

    /**
     * Execute active snippets.
     * Uses wp_cache (object cache) instead of transients to avoid stale data.
     * Object cache is per-request by default; if Redis/Memcached is active,
     * explicit cache clearing on save/toggle still works correctly.
     */
    public function execute_snippets()
    {
        // Safe Mode: skip ALL snippet execution when ?ofast-safe-mode=1
        if ($this->is_safe_mode_requested()) {
            return;
        }

        // PERFORMANCE: Get active snippets from object cache
        $snippets = wp_cache_get('ofast_active_snippets', 'ofast_snippets');

        if ($snippets === false) {
            global $wpdb;
            $table = $wpdb->prefix . 'ofast_snippets';
            $priority_supported = $this->ensure_snippets_priority_schema();
            $trash_supported = $this->ensure_snippets_trash_schema();
            $execution_order = $priority_supported ? 'priority ASC, id ASC' : 'id ASC';
            // Get all active, non-trashed snippets with all relevant fields
            $where = 'active = 1';
            if ($trash_supported) {
                $where .= " AND (status IS NULL OR status != 'trash')";
            }
            $snippets = $wpdb->get_results("SELECT id, code, language, scope, location, target_type, target_value, run_once, executed_at FROM $table WHERE {$where} ORDER BY {$execution_order}");

            // Store in object cache (persists for this request; cleared on save/toggle)
            wp_cache_set('ofast_active_snippets', $snippets, 'ofast_snippets');
        }

        if (empty($snippets)) {
            return;
        }

        foreach ($snippets as $snippet) {
            // Check scope (admin/frontend/global)
            $should_run = $this->should_snippet_run($snippet->scope);
            if (!$should_run) {
                continue;
            }

            // Check page targeting (only on frontend, skip for admin)
            if (!is_admin()) {
                $target_type = !empty($snippet->target_type) ? $snippet->target_type : 'all';
                $target_value = !empty($snippet->target_value) ? $snippet->target_value : '';

                if (!$this->should_run_on_page($target_type, $target_value)) {
                    continue;
                }
            }

            // Check run_once - if already executed, skip and deactivate
            if ($snippet->run_once && !empty($snippet->executed_at)) {
                global $wpdb;
                $table = $wpdb->prefix . 'ofast_snippets';
                $wpdb->update($table, array('active' => 0), array('id' => $snippet->id));
                $this->clear_snippets_cache();
                continue;
            }

            // Execute based on language
            $language = !empty($snippet->language) ? $snippet->language : 'php';
            $location = !empty($snippet->location) ? $snippet->location : 'footer';

            switch ($language) {
                case 'php':
                    $this->execute_php_snippet($snippet->code, $snippet->id, $snippet->run_once);
                    break;
                case 'javascript':
                    $this->execute_js_snippet($snippet->code, $location, $snippet->id, $snippet->run_once);
                    break;
                case 'css':
                    $this->execute_css_snippet($snippet->code, $location, $snippet->id, $snippet->run_once);
                    break;
                case 'html':
                    $this->execute_html_snippet($snippet->code, $location, $snippet->id, $snippet->run_once);
                    break;
            }
        }
    }

    /**
     * Execute PHP snippet with enhanced error handling.
     * Uses file-based include instead of eval() for WordPress.org compliance.
     */
    private function execute_php_snippet($code, $snippet_id = 0, $run_once = false)
    {
        $code = $this->normalize_php_code($code);
        if ($code === '') {
            return;
        }

        $snippet_file = $this->write_snippet_file($code, $snippet_id);
        if (!$snippet_file) {
            error_log('Ofast Snippet Error (ID: ' . $snippet_id . '): Could not create snippet file.');
            return;
        }

        try {
            include $snippet_file;

            // Mark as executed if successful
            $this->mark_snippet_executed($snippet_id, $run_once);
        } catch (Throwable $e) {
            // Log the error
            error_log('Ofast Snippet Runtime Error (ID: ' . $snippet_id . '): ' . $e->getMessage());

            // Auto-deactivate the problematic snippet
            if ($snippet_id > 0) {
                $this->auto_deactivate_snippet($snippet_id, $e->getMessage());
            }
        } finally {
            $this->cleanup_temp_snippet_file($snippet_file, $snippet_id);
        }
    }

    /**
     * Write snippet code to a temporary PHP file for safe execution via include.
     * Files are stored outside the web root (system temp dir) so they cannot
     * be accessed directly via URL on any server (Apache, Nginx, LiteSpeed).
     *
     * @param string $code       The PHP code to write.
     * @param int    $snippet_id The snippet ID (used for filename).
     * @return string|false The file path on success, false on failure.
     */
    public function write_snippet_file($code, $snippet_id = 0)
    {
        $dir = trailingslashit(get_temp_dir()) . 'ofast-snippets';
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        if ($snippet_id > 0) {
            $snippet_id = intval($snippet_id);
            $this->cleanup_legacy_snippet_files($dir, $snippet_id);
            $filename = 'snippet-' . $snippet_id . '.php';
        } else {
            // Randomized filename for ad-hoc execution
            $hash = wp_hash($code . wp_salt());
            $filename = 'snippet-tmp-' . substr($hash, 0, 12) . '.php';
        }
        $file = $dir . '/' . $filename;

        $written = @file_put_contents($file, "<?php\n" . $code);
        if ($written === false) {
            return false;
        }

        return $file;
    }

    /**
     * Cleanup legacy hashed snippet files for a given snippet ID.
     */
    private function cleanup_legacy_snippet_files($dir, $snippet_id)
    {
        $pattern = $dir . '/snippet-' . intval($snippet_id) . '-*.php';
        $legacy_files = glob($pattern);
        if (!empty($legacy_files)) {
            foreach ($legacy_files as $legacy_file) {
                @unlink($legacy_file);
            }
        }
    }

    /**
     * Remove temporary snippet files (non-persistent).
     */
    private function cleanup_temp_snippet_file($file, $snippet_id)
    {
        if ($snippet_id <= 0 && is_string($file) && $file !== '' && file_exists($file)) {
            @unlink($file);
        }
    }

    /**
     * Execute JavaScript snippet
     */
    private function execute_js_snippet($code, $location = 'footer', $snippet_id = 0, $run_once = false)
    {
        $hook = $this->get_injection_hook($location, 'js');
        $self = $this;

        add_action($hook, function () use ($code, $snippet_id, $run_once, $self) {
            echo "\n<script>\n" . $code . "\n</script>\n";
            $self->mark_snippet_executed($snippet_id, $run_once);
        }, 100);
    }

    /**
     * Execute CSS snippet
     */
    private function execute_css_snippet($code, $location = 'header', $snippet_id = 0, $run_once = false)
    {
        $hook = $this->get_injection_hook($location, 'css');
        $self = $this;

        add_action($hook, function () use ($code, $snippet_id, $run_once, $self) {
            echo "\n<style>\n" . $code . "\n</style>\n";
            $self->mark_snippet_executed($snippet_id, $run_once);
        }, 100);
    }

    /**
     * Execute HTML snippet
     */
    private function execute_html_snippet($code, $location = 'footer', $snippet_id = 0, $run_once = false)
    {
        $hook = $this->get_injection_hook($location, 'html');
        $self = $this;

        add_action($hook, function () use ($code, $snippet_id, $run_once, $self) {
            echo "\n" . $code . "\n";
            $self->mark_snippet_executed($snippet_id, $run_once);
        }, 100);
    }

    /**
     * Get WordPress hook based on injection location
     */
    private function get_injection_hook($location, $type = 'js')
    {
        $is_admin = is_admin();

        switch ($location) {
            case 'header':
                return $is_admin ? 'admin_head' : 'wp_head';
            case 'body':
                // wp_body_open is only available on frontend
                return $is_admin ? 'admin_head' : 'wp_body_open';
            case 'footer':
            default:
                return $is_admin ? 'admin_footer' : 'wp_footer';
        }
    }

    /**
     * Check if snippet should run based on scope
     */
    private function should_snippet_run($scope)
    {
        $scope = !empty($scope) ? $scope : 'global';

        switch ($scope) {
            case 'admin':
                return is_admin();
            case 'frontend':
                return !is_admin();
            case 'global':
            default:
                return true;
        }
    }

    /**
     * Check if snippet should run on current page based on targeting
     */
    private function should_run_on_page($target_type, $target_value)
    {
        // All pages - always run
        if ($target_type === 'all' || empty($target_type)) {
            return true;
        }

        // Homepage only
        if ($target_type === 'homepage') {
            return is_front_page() || is_home();
        }

        // Specific post type
        if ($target_type === 'post_type') {
            $post_types = array_map('trim', explode(',', $target_value));
            return is_singular($post_types);
        }

        // Specific page/post IDs
        if ($target_type === 'page_ids') {
            $ids = array_map('intval', array_map('trim', explode(',', $target_value)));
            $current_id = get_queried_object_id();
            return in_array($current_id, $ids);
        }

        // URL contains
        if ($target_type === 'url_contains') {
            $current_url = $_SERVER['REQUEST_URI'];
            $patterns = array_map('trim', explode(',', $target_value));
            foreach ($patterns as $pattern) {
                if (!empty($pattern) && strpos($current_url, $pattern) !== false) {
                    return true;
                }
            }
            return false;
        }

        return true; // Default: run
    }

    /**
     * Mark snippet as executed (for run_once)
     */
    public function mark_snippet_executed($snippet_id, $run_once)
    {
        if (!$run_once) return;

        global $wpdb;
        $table = $wpdb->prefix . 'ofast_snippets';
        $wpdb->update($table, array(
            'executed_at' => current_time('mysql'),
            'active' => 0
        ), array('id' => $snippet_id));

        // Clear cache so deactivated snippet is not re-executed from stale cache
        $this->clear_snippets_cache();
    }

    // =========================================================================
    // CACHING
    // =========================================================================

    /**
     * Clear snippets cache when snippets are modified.
     * Uses wp_cache (object cache) — always fresh, no TTL stale data issues.
     */
    public function clear_snippets_cache()
    {
        wp_cache_delete('ofast_active_snippets', 'ofast_snippets');
    }

    /**
     * Test PHP snippet code for runtime errors BEFORE activation.
     * Executes the code in a sandboxed include with output buffering
     * to catch ParseErrors and Throwables without affecting the site.
     *
     * @param string $code The snippet code to test.
     * @param int    $snippet_id The snippet ID.
     * @return true|string True if code runs without error, error message string on failure.
     */
    public function test_snippet_code($code, $snippet_id = 0)
    {
        $code = $this->normalize_php_code($code);
        if (empty($code)) {
            return true;
        }

        // Write to temp file for include-based execution
        $snippet_file = $this->write_snippet_file($code, 0); // Use 0 for temp file
        if (!$snippet_file) {
            return 'Could not create temporary file for code testing.';
        }

        ob_start();
        try {
            include $snippet_file;
            ob_end_clean();
            @unlink($snippet_file);
            return true;
        } catch (\ParseError $e) {
            ob_end_clean();
            @unlink($snippet_file);
            return 'Parse error: ' . ucfirst(rtrim($e->getMessage(), '.')) . ' (line ' . $e->getLine() . ')';
        } catch (\Throwable $e) {
            ob_end_clean();
            @unlink($snippet_file);
            return 'Runtime error: ' . ucfirst(rtrim($e->getMessage(), '.')) . ' (line ' . $e->getLine() . ')';
        }
    }

    // =========================================================================
    // TRASH / CRON
    // =========================================================================

    /**
     * Auto-purge trashed snippets older than the configured retention period.
     * Called daily via wp_cron event 'ofast_purge_trashed_snippets'.
     */
    public function purge_old_trashed_snippets()
    {
        $retention_days = (int) get_option('ofast_snippets_trash_retention', 30);

        // If set to 0 (Never), skip purge
        if ($retention_days <= 0) {
            return;
        }

        if (!$this->ensure_snippets_trash_schema()) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ofast_snippets';
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($retention_days * DAY_IN_SECONDS));

        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM $table WHERE status = 'trash' AND trashed_at IS NOT NULL AND trashed_at < %s",
            $cutoff
        ));

        if ($deleted > 0) {
            $this->clear_snippets_cache();
            $this->log_snippet_action('AUTO_PURGED', 0, '', "Purged {$deleted} trashed snippet(s) older than {$retention_days} days");
        }
    }

    // =========================================================================
    // REVISIONS
    // =========================================================================

    /**
     * Save a revision of snippet code
     */
    public function save_revision($snippet_id, $code)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'ofast_snippet_revisions';

        // Limit to last 10 revisions per snippet
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE snippet_id = %d",
            $snippet_id
        ));

        if ($count >= 10) {
            // Delete oldest revision
            $wpdb->query($wpdb->prepare(
                "DELETE FROM $table WHERE snippet_id = %d ORDER BY changed_at ASC LIMIT 1",
                $snippet_id
            ));
        }

        // Save new revision
        $wpdb->insert($table, array(
            'snippet_id' => $snippet_id,
            'code' => $code,
            'changed_at' => current_time('mysql'),
            'changed_by' => get_current_user_id()
        ));
    }

    /**
     * Get revisions for a snippet
     */
    public function get_revisions($snippet_id)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'ofast_snippet_revisions';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT r.*, u.display_name as user_name 
             FROM $table r 
             LEFT JOIN {$wpdb->users} u ON r.changed_by = u.ID 
             WHERE r.snippet_id = %d 
             ORDER BY r.changed_at DESC",
            $snippet_id
        ));
    }

    // =========================================================================
    // LOGGING & SECURITY
    // =========================================================================

    /**
     * SECURITY: Log snippet actions for audit trail
     */
    public function log_snippet_action($action, $snippet_id, $snippet_name = '', $details = '')
    {
        $user = wp_get_current_user();
        $log_entry = sprintf(
            '[%s] SNIPPET %s: ID=%d, Name="%s", User=%s (ID:%d), IP=%s %s',
            current_time('Y-m-d H:i:s'),
            strtoupper($action),
            $snippet_id,
            $snippet_name,
            $user->user_login,
            $user->ID,
            $this->get_client_ip(),
            $details ? "| {$details}" : ''
        );

        // Log to WordPress debug log
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[OFAST_SNIPPETS] ' . $log_entry);
        }

        // Also store in options for admin viewing
        $logs = get_option('ofast_snippet_audit_log', array());
        array_unshift($logs, array(
            'time' => current_time('mysql'),
            'action' => $action,
            'snippet_id' => $snippet_id,
            'snippet_name' => $snippet_name,
            'user_id' => $user->ID,
            'user_login' => $user->user_login,
            'ip' => $this->get_client_ip(),
            'details' => $details
        ));

        // Keep only last 100 entries
        $logs = array_slice($logs, 0, 100);
        update_option('ofast_snippet_audit_log', $logs);
    }

    /**
     * Get client IP address
     */
    private function get_client_ip()
    {
        $ip_keys = array('HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR');
        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = explode(',', $_SERVER[$key]);
                return trim($ip[0]);
            }
        }
        return 'Unknown';
    }

    /**
     * SECURITY: Rate limiting check
     * Returns true if action is allowed, false if rate limited
     */
    public function check_rate_limit($action = 'snippet_action')
    {
        $user_id = get_current_user_id();
        $transient_key = "ofast_rate_{$action}_{$user_id}";
        $attempts = get_transient($transient_key);

        if ($attempts === false) {
            // First attempt
            set_transient($transient_key, 1, 60); // 60 second window
            return true;
        }

        if ($attempts >= 30) { // Max 30 actions per minute
            return false;
        }

        set_transient($transient_key, $attempts + 1, 60);
        return true;
    }

    // =========================================================================
    // ERROR HANDLING
    // =========================================================================

    /**
     * Auto-deactivate snippet on runtime error
     */
    private function auto_deactivate_snippet($snippet_id, $error_message = '')
    {
        global $wpdb;
        $table = $wpdb->prefix . 'ofast_snippets';

        // Get snippet details before deactivating
        $snippet = $wpdb->get_row($wpdb->prepare(
            "SELECT name, code FROM $table WHERE id = %d",
            $snippet_id
        ));

        if (!$snippet) {
            return;
        }

        // Deactivate the snippet
        $wpdb->update(
            $table,
            array('active' => 0),
            array('id' => $snippet_id)
        );

        // Clear cache so deactivated snippet is not re-executed from stale cache
        $this->clear_snippets_cache();

        // Log the auto-deactivation
        $this->log_snippet_action(
            'AUTO_DEACTIVATED',
            $snippet_id,
            $snippet->name,
            'Runtime error: ' . substr($error_message, 0, 200)
        );

        // Store error for admin notice
        $failed_snippets = get_transient('ofast_failed_snippets') ?: array();
        $failed_snippets[] = array(
            'id' => $snippet_id,
            'name' => $snippet->name,
            'error' => $error_message,
            'time' => current_time('mysql')
        );
        set_transient('ofast_failed_snippets', $failed_snippets, DAY_IN_SECONDS);

        // Show admin notice on next page load
        add_action('admin_notices', array($this, 'show_runtime_error_notice'));
    }

    /**
     * Show admin notice for runtime errors and safe mode status.
     */
    public function show_runtime_error_notice()
    {
        // Safe Mode banner — persistent, non-dismissible native notice
        if ($this->is_safe_mode()) {
            $exit_url = remove_query_arg('ofast-safe-mode');
            $snippets_url = admin_url('admin.php?page=ofast-snippets');
            echo '<div class="notice notice-warning" style="border-left-color:#f59e0b;background:#fffbeb;padding:12px 16px;">';
            echo '<p style="margin:0;font-size:14px;">';
            echo '<strong>🛡️ Ofast Safe Mode Active</strong> — All code snippets are paused. ';
            echo '<a href="' . esc_url($snippets_url) . '">Manage Snippets</a> | ';
            echo '<a href="' . esc_url($exit_url) . '" style="color:#dc2626;font-weight:600;">Exit Safe Mode</a>';
            echo '</p></div>';
        }

        $failed_snippets = get_transient('ofast_failed_snippets');

        if (empty($failed_snippets)) {
            return;
        }

        foreach ($failed_snippets as $failed) {
            $message = '<strong>Snippet Auto-Deactivated:</strong> "' . esc_html($failed['name']) . '" encountered a runtime error and was automatically deactivated for safety. ';
            $message .= '<strong>Error:</strong> ' . esc_html($failed['error']) . ' ';
            $message .= '<a href="' . admin_url('admin.php?page=ofast-snippets&edit=' . $failed['id']) . '" style="color:#fff;text-decoration:underline;">Fix Snippet</a>';
            echo Ofast_X_Toast::render($message, 'error');
        }

        // Clear the transient after showing
        delete_transient('ofast_failed_snippets');
    }
}
