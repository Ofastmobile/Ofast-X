<?php

/**
 * Ofast X - Code Snippets Validator
 * Handles all code validation: PHP, JS, CSS, HTML syntax checks,
 * security scanning, conflict detection, and duplicate detection.
 *
 * @since 1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Snippets_Validator
{
    /** @var Ofast_X_Snippets */
    private $core;

    public function __construct(Ofast_X_Snippets $core)
    {
        $this->core = $core;
    }

    // =========================================================================
    // SECURITY TIER DEFINITIONS
    // =========================================================================

    /**
     * Tier 1: CRITICAL — Hard block, no override.
     * These are NEVER safe in a snippet context.
     */
    private function get_tier1_functions()
    {
        return array(
            'exec'            => 'Execute system commands',
            'shell_exec'      => 'Execute shell commands',
            'system'          => 'Execute system commands',
            'passthru'        => 'Execute commands and output',
            'popen'           => 'Open process pipe',
            'proc_open'       => 'Execute command via process',
            'pcntl_exec'      => 'Execute program',
            'eval'            => 'Execute arbitrary PHP (nested eval not allowed)',
            'assert'          => 'Execute code as assertion',
            'create_function' => 'Create anonymous function (deprecated, uses eval internally)',
        );
    }

    /**
     * Tier 2: WARNING — Allow with admin confirmation.
     * Legitimate uses exist, but carry risk. Admin takes responsibility.
     */
    private function get_tier2_functions()
    {
        return array(
            'unlink'                     => 'Deletes files — make sure you know which files',
            'rmdir'                      => 'Removes directories — use with caution',
            'rename'                     => 'Renames/moves files',
            'copy'                       => 'Copies files',
            'file_put_contents'          => 'Writes to files — common for exports/cache',
            'fwrite'                     => 'Writes to file handle',
            'fputs'                      => 'Writes to file handle',
            'fopen'                      => 'Opens files — also used for reading',
            'curl_exec'                  => 'Makes external HTTP requests — use wp_remote_get/post when possible',
            'header'                     => 'Sends HTTP headers — use wp_redirect() when possible',
            'setcookie'                  => 'Sets cookies — can cause "headers already sent" if used late',
            'ob_start'                   => 'Output buffering — very common in WordPress development',
            'ob_end_clean'               => 'Ends output buffering',
            'ob_end_flush'               => 'Flushes output buffer',
            'ini_set'                    => 'Modifies PHP configuration at runtime',
            'set_time_limit'             => 'Changes execution time limit — needed for long tasks',
            'sleep'                      => 'Pauses execution — can cause timeouts if misused',
            'usleep'                     => 'Pauses execution (microseconds)',
            'session_start'              => 'Starts a PHP session — may conflict with WordPress',
            'error_reporting'            => 'Changes error reporting level',
            'restore_error_handler'      => 'Restores default error handler',
            'register_shutdown_function' => 'Registers a shutdown callback',
        );
    }

    /**
     * Tier 3: INFO — Allow freely, show notice only.
     * Extremely common in real WordPress code. No blocking.
     */
    private function get_tier3_functions()
    {
        return array(
            'preg_replace'  => 'The legacy /e modifier was removed in PHP 7.0 — this is safe on modern PHP',
            'base64_decode' => 'Used for decoding API responses, email content, image data',
            'include'       => 'Standard PHP file inclusion — WordPress core pattern',
            'include_once'  => 'Standard PHP file inclusion',
            'require'       => 'Standard PHP file inclusion',
            'require_once'  => 'Standard PHP file inclusion',
            'exit'          => 'Used in AJAX handlers (wp_die is a wrapper for this)',
            'die'           => 'Used in AJAX handlers (wp_die is a wrapper for this)',
            'mail'          => 'Consider using wp_mail() instead for better compatibility',
            'define'        => 'Defines constants — extremely common in WordPress',
            'constant'      => 'Reads constant values — harmless read-only operation',
        );
    }

    /**
     * Validate code based on its language.
     *
     * Returns:
     *   true              — valid, no issues
     *   string            — hard error (Tier 1 block or syntax error)
     *   array             — Tier 2/3 result: ['tier' => 2|3, 'message' => '...', 'functions' => [...]]
     */
    public function validate_code($code, $language)
    {
        switch ($language) {
            case 'php':
            case '':
                return $this->validate_php_code($code);
            case 'javascript':
                return $this->validate_js_code($code);
            case 'css':
                return $this->validate_css_code($code);
            case 'html':
                return $this->validate_html_code($code);
            default:
                return true;
        }
    }

    /**
     * Validate PHP code syntax and security.
     *
     * Returns:
     *   true   — valid, no issues
     *   string — hard error (Tier 1 block, syntax error, or crash prevention)
     *   array  — Tier 2/3 advisory: ['tier' => 2|3, 'message' => '...', 'functions' => [...]]
     */
    public function validate_php_code($code)
    {
        $code = $this->core->normalize_php_code($code);

        // Allow empty code (for saving templates/drafts)
        if (empty($code)) {
            return true;
        }

        // ── TIER 1: CRITICAL — Hard block, no override ──────────────────────
        $tier1 = $this->get_tier1_functions();
        foreach ($tier1 as $func => $reason) {
            $pattern = '/\b' . preg_quote($func, '/') . '\s*\(/i';
            if (preg_match($pattern, $code)) {
                return "\xF0\x9F\x9A\xA8 Security blocked: '{$func}()' is not allowed. Reason: {$reason}";
            }
        }

        // ── DANGEROUS SQL — Hard block ───────────────────────────────────────
        $dangerous_sql = array(
            '/\bDROP\s+(TABLE|DATABASE|INDEX)/i' => 'DROP TABLE/DATABASE - Destroys data permanently',
            '/\bTRUNCATE\s+TABLE/i' => 'TRUNCATE TABLE - Deletes all data from table',
            '/\bDELETE\s+FROM\s+\w+\s*(;|$)/i' => 'DELETE without WHERE - Deletes all rows',
            '/\bALTER\s+TABLE/i' => 'ALTER TABLE - Can break database structure',
            '/\bCREATE\s+(TABLE|DATABASE)/i' => 'CREATE TABLE/DATABASE - Should use WordPress dbDelta()',
            '/\$wpdb\s*->\s*query\s*\(\s*["\']?\s*DELETE/i' => 'Raw DELETE query - Use $wpdb->delete() instead',
        );

        foreach ($dangerous_sql as $pattern => $reason) {
            if (preg_match($pattern, $code)) {
                return "Database protection: {$reason}";
            }
        }

        // ── UNLIMITED SELECT — Hard block ────────────────────────────────────
        if (preg_match('/\$wpdb\s*->\s*get_results\s*\(\s*["\'][^"\']*SELECT\s+\*\s+FROM[^"\']*["\'](?![^)]*LIMIT)/i', $code)) {
            return "Database protection: SELECT * FROM without LIMIT can crash your site on large tables. Add LIMIT clause (e.g., LIMIT 100).";
        }
        if (preg_match('/["\']SELECT\s+\*\s+FROM\s+\w+["\']\s*(?!.*LIMIT)/i', $code)) {
            if (!preg_match('/LIMIT\s+\d+/i', $code)) {
                return "Database protection: SELECT * FROM without LIMIT can crash your site on large tables. Add LIMIT clause.";
            }
        }

        // ── INFINITE LOOP DETECTION — Hard block ─────────────────────────────
        $infinite_loop_patterns = array(
            '/while\s*\(\s*true\s*\)/i' => 'while(true) infinite loop',
            '/while\s*\(\s*1\s*\)/i' => 'while(1) infinite loop',
            '/while\s*\(\s*!\s*false\s*\)/i' => 'while(!false) infinite loop',
            '/for\s*\(\s*;\s*;\s*\)/' => 'for(;;) infinite loop',
            '/while\s*\(\s*\$[a-z_]+\s*=\s*\$[a-z_]+\s*\)/i' => 'Self-referential while condition',
        );

        foreach ($infinite_loop_patterns as $pattern => $reason) {
            if (preg_match($pattern, $code)) {
                return "Crash prevention: {$reason} detected. This would freeze or crash your site.";
            }
        }

        // ── MEMORY EXHAUSTION — Hard block ───────────────────────────────────
        $memory_patterns = array(
            '/str_repeat\s*\(.{0,50}\d{6,}/i' => 'str_repeat with very large number can exhaust memory',
            '/array_fill\s*\(.{0,50}\d{6,}/i' => 'array_fill with very large number can exhaust memory',
            '/range\s*\(.{0,30}\d{7,}/i' => 'range() with large numbers can exhaust memory',
        );

        foreach ($memory_patterns as $pattern => $reason) {
            if (preg_match($pattern, $code)) {
                return "Memory protection: {$reason}";
            }
        }

        // ── SYNTAX VALIDATION — Hard block ───────────────────────────────────
        $test_code = '<?php ' . $code;
        $old_error_reporting = error_reporting(0);
        $tokens = @token_get_all($test_code);
        error_reporting($old_error_reporting);

        if ($tokens === false) {
            return 'Invalid PHP syntax detected';
        }

        $open_paren = 0;
        $open_bracket = 0;
        $open_brace = 0;

        foreach ($tokens as $token) {
            if (is_string($token)) {
                if ($token === '(') $open_paren++;
                if ($token === ')') $open_paren--;
                if ($token === '[') $open_bracket++;
                if ($token === ']') $open_bracket--;
                if ($token === '{') $open_brace++;
                if ($token === '}') $open_brace--;
            }
        }

        if ($open_paren != 0) return 'Unclosed parenthesis ( ) detected';
        if ($open_bracket != 0) return 'Unclosed bracket [ ] detected';
        if ($open_brace != 0) return 'Unclosed brace { } detected';

        // Semicolon check — only for standalone function calls, NOT after } or inside class/function bodies
        $last_tokens = array_slice($tokens, -5);
        $has_semicolon = false;
        $ends_with_brace = false;

        foreach ($last_tokens as $token) {
            if ($token === ';') $has_semicolon = true;
            if ($token === '}') $ends_with_brace = true;
        }

        if (!$has_semicolon && !$ends_with_brace && (strpos($code, 'add_action') !== false || strpos($code, 'add_filter') !== false)) {
            return 'Missing semicolon ; at the end of function call';
        }

        // ── TIER 2: WARNING — Collect, don't block ──────────────────────────
        $tier2 = $this->get_tier2_functions();
        $tier2_found = array();

        foreach ($tier2 as $func => $reason) {
            $pattern = '/\b' . preg_quote($func, '/') . '\s*\(/i';
            if (preg_match($pattern, $code)) {
                $tier2_found[$func] = $reason;
            }
        }

        // RECURSIVE FUNCTION DETECTION — Tier 2 warning (not hard block)
        if (preg_match_all('/function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/i', $code, $func_matches)) {
            foreach ($func_matches[1] as $func_name) {
                $self_call_pattern = '/function\s+' . preg_quote($func_name, '/') . '\s*\([^)]*\)\s*\{[^}]*' . preg_quote($func_name, '/') . '\s*\(/is';
                if (preg_match($self_call_pattern, $code)) {
                    $safe_recursion = '/function\s+' . preg_quote($func_name, '/') . '\s*\([^)]*\)\s*\{[^}]*(if\s*\([^)]+\)\s*\{?\s*return|if\s*\([^)]+\)\s*return|switch\s*\(|while\s*\(.*\)\s*\{[^}]*return)/is';
                    if (!preg_match($safe_recursion, $code)) {
                        $tier2_found['recursive:' . $func_name] = "Function '{$func_name}' appears recursive. If it worked in your source plugin, it likely has proper exit conditions that our parser can't detect.";
                    }
                }
            }
        }

        if (!empty($tier2_found)) {
            $func_list = array_keys($tier2_found);
            $messages = array();
            foreach ($tier2_found as $func => $reason) {
                $messages[] = "{$func}: {$reason}";
            }
            return array(
                'tier'      => 2,
                'message'   => 'This snippet uses functions that require confirmation: ' . implode(', ', $func_list),
                'functions' => $tier2_found,
            );
        }

        // ── TIER 3: INFO — Collect, never block ─────────────────────────────
        $tier3 = $this->get_tier3_functions();
        $tier3_found = array();

        foreach ($tier3 as $func => $reason) {
            // include/require are language constructs — work with or without parentheses
            if (in_array($func, array('include', 'include_once', 'require', 'require_once'), true)) {
                $pattern = '/\b' . preg_quote($func, '/') . '\s*[\(\'"]/i';
            } else {
                $pattern = '/\b' . preg_quote($func, '/') . '\s*\(/i';
            }
            if (preg_match($pattern, $code)) {
                $tier3_found[$func] = $reason;
            }
        }

        if (!empty($tier3_found)) {
            return array(
                'tier'      => 3,
                'message'   => 'Info: This snippet uses common functions that are safe on modern PHP.',
                'functions' => $tier3_found,
            );
        }

        return true; // Valid, no issues!
    }

    /**
     * Check if a validation result is a hard error (blocks activation).
     * Tier 1 blocks and syntax errors return strings. Tier 2/3 return arrays.
     *
     * @param mixed $result Return value from validate_php_code() or validate_code()
     * @return bool True if the result is a hard block.
     */
    public function is_hard_error($result)
    {
        return is_string($result);
    }

    /**
     * Check if a validation result requires admin confirmation (Tier 2).
     *
     * @param mixed $result Return value from validate_php_code() or validate_code()
     * @return bool
     */
    public function is_tier2_warning($result)
    {
        return is_array($result) && isset($result['tier']) && $result['tier'] === 2;
    }

    /**
     * Check if a validation result is informational only (Tier 3).
     *
     * @param mixed $result Return value from validate_php_code() or validate_code()
     * @return bool
     */
    public function is_tier3_info($result)
    {
        return is_array($result) && isset($result['tier']) && $result['tier'] === 3;
    }

    /**
     * Get a human-readable message from any validation result.
     *
     * @param mixed $result Return value from validate_php_code() or validate_code()
     * @return string
     */
    public function get_validation_message($result)
    {
        if ($result === true) {
            return '';
        }
        if (is_string($result)) {
            return $result;
        }
        if (is_array($result) && isset($result['message'])) {
            return $result['message'];
        }
        return '';
    }

    /**
     * Validate JavaScript code syntax (basic bracket balance check)
     * Returns true if valid, error message if invalid
     */
    public function validate_js_code($code)
    {
        $code = trim($code);
        if (empty($code)) return true;

        $open_paren = 0;
        $open_bracket = 0;
        $open_brace = 0;
        $in_string = false;
        $string_char = '';
        $in_line_comment = false;
        $in_block_comment = false;
        $len = strlen($code);

        for ($i = 0; $i < $len; $i++) {
            $char = $code[$i];
            $next = ($i + 1 < $len) ? $code[$i + 1] : '';

            // Handle line comments
            if ($in_line_comment) {
                if ($char === "\n") $in_line_comment = false;
                continue;
            }

            // Handle block comments
            if ($in_block_comment) {
                if ($char === '*' && $next === '/') {
                    $in_block_comment = false;
                    $i++;
                }
                continue;
            }

            // Handle strings
            if ($in_string) {
                if ($char === '\\') { $i++; continue; }
                if ($char === $string_char) $in_string = false;
                continue;
            }

            // Start comments
            if ($char === '/' && $next === '/') { $in_line_comment = true; $i++; continue; }
            if ($char === '/' && $next === '*') { $in_block_comment = true; $i++; continue; }

            // Start strings
            if ($char === '"' || $char === "'" || $char === '`') {
                $in_string = true;
                $string_char = $char;
                continue;
            }

            if ($char === '(') $open_paren++;
            if ($char === ')') $open_paren--;
            if ($char === '[') $open_bracket++;
            if ($char === ']') $open_bracket--;
            if ($char === '{') $open_brace++;
            if ($char === '}') $open_brace--;

            if ($open_paren < 0) return 'Extra closing parenthesis ) detected';
            if ($open_bracket < 0) return 'Extra closing bracket ] detected';
            if ($open_brace < 0) return 'Extra closing brace } detected';
        }

        if ($open_paren != 0) return 'Unclosed parenthesis ( ) detected';
        if ($open_bracket != 0) return 'Unclosed bracket [ ] detected';
        if ($open_brace != 0) return 'Unclosed brace { } detected';
        if ($in_string) return 'Unclosed string detected';

        return true;
    }

    /**
     * Validate CSS code syntax (basic structure check)
     * Returns true if valid, error message if invalid
     */
    public function validate_css_code($code)
    {
        $code = trim($code);
        if (empty($code)) return true;

        $open_brace = 0;
        $open_paren = 0;
        $in_string = false;
        $string_char = '';
        $in_comment = false;
        $len = strlen($code);

        for ($i = 0; $i < $len; $i++) {
            $char = $code[$i];
            $next = ($i + 1 < $len) ? $code[$i + 1] : '';

            if ($in_comment) {
                if ($char === '*' && $next === '/') {
                    $in_comment = false;
                    $i++;
                }
                continue;
            }

            if ($in_string) {
                if ($char === '\\') { $i++; continue; }
                if ($char === $string_char) $in_string = false;
                continue;
            }

            if ($char === '/' && $next === '*') { $in_comment = true; $i++; continue; }

            if ($char === '"' || $char === "'") {
                $in_string = true;
                $string_char = $char;
                continue;
            }

            if ($char === '{') $open_brace++;
            if ($char === '}') $open_brace--;
            if ($char === '(') $open_paren++;
            if ($char === ')') $open_paren--;

            if ($open_brace < 0) return 'Extra closing brace } detected';
            if ($open_paren < 0) return 'Extra closing parenthesis ) detected';
        }

        if ($open_brace != 0) return 'Unclosed brace { } detected';
        if ($open_paren != 0) return 'Unclosed parenthesis ( ) detected';

        return true;
    }

    /**
     * Validate HTML code syntax (basic tag balance check)
     * Returns true if valid, error message if invalid
     */
    public function validate_html_code($code)
    {
        $code = trim($code);
        if (empty($code)) return true;

        // Check for balanced common HTML tags (ignore self-closing and void elements)
        $void_elements = array('area','base','br','col','embed','hr','img','input','link','meta','param','source','track','wbr');

        $tag_stack = array();
        // Match all opening and closing tags
        if (preg_match_all('/<\/?([a-zA-Z][a-zA-Z0-9]*)\b[^>]*>/', $code, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $tag = strtolower($match[1]);
                $is_closing = (strpos($match[0], '</') === 0);
                $is_self_closing = (substr($match[0], -2) === '/>');

                // Skip void elements and self-closing tags
                if (in_array($tag, $void_elements) || $is_self_closing) continue;
                // Skip script and style (they can contain unbalanced < > inside)
                if ($tag === 'script' || $tag === 'style') continue;

                if ($is_closing) {
                    if (empty($tag_stack)) {
                        return "Extra closing tag </{$tag}> without matching opening tag";
                    }
                    $expected = array_pop($tag_stack);
                    if ($expected !== $tag) {
                        return "Mismatched tags: expected </{$expected}> but found </{$tag}>";
                    }
                } else {
                    $tag_stack[] = $tag;
                }
            }
        }

        if (!empty($tag_stack)) {
            $unclosed = $tag_stack[count($tag_stack) - 1];
            return "Unclosed <{$unclosed}> tag detected";
        }

        return true;
    }

    /**
     * Detect the likely language of code based on patterns.
     * Returns 'php', 'javascript', 'css', 'html', or null if unsure.
     */
    public function detect_code_language($code)
    {
        $code = trim($code);
        if (empty($code)) return null;

        $scores = array('php' => 0, 'javascript' => 0, 'css' => 0, 'html' => 0);

        // PHP indicators
        $php_patterns = array(
            '/\$[a-zA-Z_]\w*/' => 3,           // $variable
            '/\b(add_action|add_filter|remove_action|remove_filter)\s*\(/' => 5,
            '/\b(get_option|update_option|delete_option)\s*\(/' => 5,
            '/\b(wp_enqueue_script|wp_enqueue_style)\s*\(/' => 5,
            '/\b(function\s+\w+\s*\()/' => 2,   // function declaration
            '/->/' => 3,                         // object operator
            '/::/' => 3,                         // static operator
            '/\barray\s*\(/' => 3,               // array()
            '/\b(echo|print|return)\s/' => 2,
            '/\b(global\s+\$|namespace\s|use\s)/' => 4,
            '/\$wpdb/' => 5,
            '/\bdo_action\s*\(/' => 5,
            '/\bapply_filters\s*\(/' => 5,
        );

        // JavaScript indicators
        $js_patterns = array(
            '/\b(document\.|window\.|navigator\.)/' => 5,
            '/\b(getElementById|querySelector|addEventListener)\s*\(/' => 5,
            '/\b(console\.(log|warn|error))\s*\(/' => 5,
            '/\b(const|let)\s+\w+/' => 4,        // const/let declarations
            '/\bvar\s+\w+/' => 2,                 // var (could be PHP too)
            '/=>\s*\{/' => 4,                      // arrow function with block
            '/=>\s*[^{]/' => 3,                    // arrow function expression
            '/\b(async|await)\s/' => 4,
            '/\bnew\s+Promise\s*\(/' => 5,
            '/\b(fetch|XMLHttpRequest)\s*\(/' => 5,
            '/\b(module\.exports|require\s*\()/' => 5,
            '/\bJSON\.(parse|stringify)\s*\(/' => 4,
            '/\balert\s*\(/' => 3,
            '/\bsetTimeout\s*\(/' => 3,
        );

        // CSS indicators
        $css_patterns = array(
            '/[.#]\w+\s*\{/' => 4,               // .class { or #id {
            '/\b(margin|padding|color|background|font-size|display|position|border)\s*:/' => 4,
            '/@media\s/' => 5,
            '/@keyframes\s/' => 5,
            '/@import\s/' => 4,
            '/\b(flex|grid|none|block|inline|absolute|relative|fixed)\s*;/' => 3,
            '/\b(width|height|top|left|right|bottom)\s*:\s*\d/' => 3,
            '/:\s*(hover|focus|active|visited)\s*\{/' => 5, // pseudo-classes
            '/\b(opacity|z-index|overflow|transform|transition)\s*:/' => 4,
        );

        // HTML indicators
        $html_patterns = array(
            '/^<(!DOCTYPE|html|head|body)/im' => 5,
            '/<(div|span|p|h[1-6]|table|form|input|button|a|img|ul|ol|li|section|article|nav|header|footer)\b/i' => 4,
            '/<\/\w+>/' => 3,                     // closing tags
            '/\b(class|id|style|href|src|alt)\s*=\s*["\']/' => 3,
            '/<script\b/' => 3,
            '/<style\b/' => 3,
        );

        foreach ($php_patterns as $pattern => $weight) {
            if (preg_match($pattern, $code)) $scores['php'] += $weight;
        }
        foreach ($js_patterns as $pattern => $weight) {
            if (preg_match($pattern, $code)) $scores['javascript'] += $weight;
        }
        foreach ($css_patterns as $pattern => $weight) {
            if (preg_match($pattern, $code)) $scores['css'] += $weight;
        }
        foreach ($html_patterns as $pattern => $weight) {
            if (preg_match($pattern, $code)) $scores['html'] += $weight;
        }

        // Get the winner
        arsort($scores);
        $top = array_keys($scores);
        $top_lang = $top[0];
        $top_score = $scores[$top_lang];

        // Only return if confident (score >= 4 and at least 2x the runner-up)
        $runner_up_score = $scores[$top[1]];
        if ($top_score >= 4 && ($runner_up_score == 0 || $top_score >= $runner_up_score * 1.5)) {
            return $top_lang;
        }

        return null; // Not confident enough
    }

    /**
     * Check for function name conflicts.
     * Smart detection: if a function is wrapped in a function_exists() guard
     * (e.g., `if (!function_exists('my_func')) { function my_func() {} }`),
     * it is excluded from conflict detection since the guard prevents redeclaration.
     *
     * Returns true if no conflicts, error message string if conflicts found.
     */
    public function check_function_conflicts($code)
    {
        $code = $this->core->normalize_php_code($code);

        // Extract function names from the code
        $function_names = array();

        // Match "function function_name(" patterns
        if (preg_match_all('/function\s+([a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)\s*\(/i', $code, $matches)) {
            $function_names = $matches[1];
        }

        if (empty($function_names)) {
            return true; // No named functions = no conflicts possible
        }

        // Detect guarded declarations: function_exists() / class_exists() checks.
        // If a function is wrapped in `if (!function_exists('func'))`, skip it.
        $guarded_names = array();
        if (preg_match_all('/(?:function_exists|class_exists)\s*\(\s*[\'"]([a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)[\'"]\s*\)/i', $code, $guard_matches)) {
            $guarded_names = array_map('strtolower', $guard_matches[1]);
        }

        $conflicts = array();
        foreach ($function_names as $func_name) {
            // Skip if this function has a function_exists() guard
            if (in_array(strtolower($func_name), $guarded_names, true)) {
                continue;
            }

            // Check if function already exists
            if (function_exists($func_name)) {
                $conflicts[] = $func_name;
            }
        }

        if (!empty($conflicts)) {
            $conflict_list = implode(', ', $conflicts);
            return "Function conflict detected! These functions already exist: {$conflict_list}. This may be caused by another plugin (Code Snippets, WPCode, etc.) or another active snippet using the same function names. Deactivate the conflicting snippet/plugin first, or rename the functions in this code. Tip: Wrap your function in if (!function_exists('name')) { } to prevent this.";
        }

        return true;
    }

    /**
     * Extract function names from PHP code
     */
    public function extract_function_names($code)
    {
        $code = $this->core->normalize_php_code($code);
        $function_names = array();
        if (preg_match_all('/function\s+([a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)\s*\(/i', $code, $matches)) {
            $function_names = $matches[1];
        }
        return $function_names;
    }

    /**
     * Get duplicate/conflict reasons against already active snippets.
     * Used to prevent activating snippets that could trigger fatal conflicts.
     */
    public function get_active_duplicate_conflicts($snippet_id, $snippet_name, $snippet_code)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'ofast_snippets';
        $conflicts = array();

        $current_name = strtolower(trim((string) $snippet_name));
        $current_code = trim($this->core->normalize_php_code($snippet_code));
        $current_functions = $this->extract_function_names($current_code);
        $current_hash = $current_code !== '' ? md5($current_code) : '';

        if ($this->core->ensure_snippets_trash_schema()) {
            $active_snippets = $wpdb->get_results($wpdb->prepare(
                "SELECT id, name, code FROM $table WHERE id != %d AND active = 1 AND (status IS NULL OR status != 'trash')",
                $snippet_id
            ));
        } else {
            $active_snippets = $wpdb->get_results($wpdb->prepare(
                "SELECT id, name, code FROM $table WHERE id != %d AND active = 1",
                $snippet_id
            ));
        }

        if (empty($active_snippets)) {
            return array();
        }

        foreach ($active_snippets as $other) {
            $other_name = strtolower(trim((string) $other->name));
            $other_code = trim($this->core->normalize_php_code($other->code));
            $other_hash = $other_code !== '' ? md5($other_code) : '';

            if ($current_name !== '' && $current_name === $other_name) {
                $conflicts[] = "Same name as active snippet #{$other->id}";
            }

            if ($current_hash !== '' && $other_hash !== '' && $current_hash === $other_hash) {
                $conflicts[] = "Exact same code as active snippet #{$other->id}";
            }

            if (!empty($current_functions)) {
                $other_functions = $this->extract_function_names($other_code);
                $overlap = array_intersect($current_functions, $other_functions);
                if (!empty($overlap)) {
                    $conflicts[] = "Shares function(s) " . implode(', ', array_unique($overlap)) . " with active snippet #{$other->id}";
                }
            }
        }

        return array_values(array_unique($conflicts));
    }

    /**
     * Find active snippets that may depend on functions defined in the given snippet.
     * Used to warn users before deactivating a snippet whose functions are called by others.
     *
     * @param int    $snippet_id   The snippet being deactivated.
     * @param string $snippet_code The snippet's code.
     * @return array Array of dependent snippet objects (id, name).
     */
    public function get_dependent_snippets($snippet_id, $snippet_code)
    {
        $code = $this->core->normalize_php_code($snippet_code);
        $defined_functions = $this->extract_function_names($code);

        if (empty($defined_functions)) {
            return array();
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ofast_snippets';

        // Get other active PHP snippets
        if ($this->core->ensure_snippets_trash_schema()) {
            $active_snippets = $wpdb->get_results($wpdb->prepare(
                "SELECT id, name, code FROM $table WHERE id != %d AND active = 1 AND (language = 'php' OR language IS NULL OR language = '') AND (status IS NULL OR status != 'trash')",
                $snippet_id
            ));
        } else {
            $active_snippets = $wpdb->get_results($wpdb->prepare(
                "SELECT id, name, code FROM $table WHERE id != %d AND active = 1 AND (language = 'php' OR language IS NULL OR language = '')",
                $snippet_id
            ));
        }

        if (empty($active_snippets)) {
            return array();
        }

        $dependents = array();
        foreach ($active_snippets as $other) {
            $other_code = $this->core->normalize_php_code($other->code);
            foreach ($defined_functions as $func) {
                // Check if the other snippet calls this function (not defines it)
                if (preg_match('/\b' . preg_quote($func, '/') . '\s*\(/', $other_code)) {
                    // Make sure the other snippet doesn't also define this function
                    $other_functions = $this->extract_function_names($other_code);
                    if (!in_array($func, $other_functions, true)) {
                        $dependents[] = $other;
                        break; // One match is enough to flag this snippet
                    }
                }
            }
        }

        return $dependents;
    }

    /**
     * Check for potential duplicates within our snippets table
     * Returns array with 'has_duplicate' boolean and 'reasons' array
     */
    public function get_potential_duplicates($snippet_id, $snippet_name, $snippet_code)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'ofast_snippets';
        $trash_supported = $this->core->ensure_snippets_trash_schema();

        $empty_result = array(
            'has_duplicate' => false,
            'reasons' => array()
        );

        // Cache snippet rows and parsed metadata once per request.
        static $duplicates_cache = array();
        $cache_key = $table . '|' . ($trash_supported ? 'with_trash_schema' : 'without_trash_schema');

        if (!isset($duplicates_cache[$cache_key])) {
            if ($trash_supported) {
                $all_snippets = $wpdb->get_results("SELECT id, name, code, active FROM $table WHERE status IS NULL OR status != 'trash'");
            } else {
                $all_snippets = $wpdb->get_results("SELECT id, name, code, active FROM $table");
            }

            $parsed = array();
            if (!empty($all_snippets)) {
                foreach ($all_snippets as $snippet_row) {
                    $row_id = (int) $snippet_row->id;
                    $normalized_code = trim($this->core->normalize_php_code($snippet_row->code));
                    $parsed[$row_id] = array(
                        'name_key' => strtolower(trim((string) $snippet_row->name)),
                        'active' => !empty($snippet_row->active),
                        'code_hash' => $normalized_code !== '' ? md5($normalized_code) : '',
                        'functions' => $this->extract_function_names($snippet_row->code),
                    );
                }
            }

            $result_map = array();
            foreach ($parsed as $row_id => $meta) {
                $result_map[$row_id] = $empty_result;
            }

            // Compute duplicate reasons once for all rows to avoid O(n^2) work per table row render.
            $row_ids = array_keys($parsed);
            foreach ($row_ids as $row_id) {
                $current = $parsed[$row_id];
                foreach ($row_ids as $other_id) {
                    if ($other_id === $row_id) {
                        continue;
                    }

                    $other = $parsed[$other_id];
                    $status = $other['active'] ? 'ACTIVE' : 'inactive';

                    if ($other['name_key'] === $current['name_key']) {
                        $result_map[$row_id]['reasons'][] = "Same name as snippet #{$other_id} ({$status})";
                    }

                    if ($current['code_hash'] !== '' && $other['code_hash'] !== '' && $current['code_hash'] === $other['code_hash']) {
                        $result_map[$row_id]['reasons'][] = "Exact same code as snippet #{$other_id} ({$status})";
                    }

                    if (!empty($current['functions']) && !empty($other['functions'])) {
                        $overlap = array_intersect($current['functions'], $other['functions']);
                        if (!empty($overlap)) {
                            $result_map[$row_id]['reasons'][] = "Shares functions (" . implode(', ', $overlap) . ") with snippet #{$other_id} ({$status})";
                        }
                    }
                }

                if (!empty($result_map[$row_id]['reasons'])) {
                    $result_map[$row_id]['reasons'] = array_values(array_unique($result_map[$row_id]['reasons']));
                    $result_map[$row_id]['has_duplicate'] = true;
                }
            }

            $duplicates_cache[$cache_key] = array(
                'parsed' => $parsed,
                'result_map' => $result_map,
            );
        }

        $parsed_rows = $duplicates_cache[$cache_key]['parsed'];
        $snippet_id = (int) $snippet_id;
        if (isset($duplicates_cache[$cache_key]['result_map'][$snippet_id])) {
            return $duplicates_cache[$cache_key]['result_map'][$snippet_id];
        }

        // Fallback for non-persisted/unlisted snippets.
        if (empty($parsed_rows)) {
            return $empty_result;
        }

        $result = $empty_result;
        $snippet_name_key = strtolower(trim((string) $snippet_name));
        $normalized_snippet_code = trim($this->core->normalize_php_code($snippet_code));
        $snippet_code_hash = $normalized_snippet_code !== '' ? md5($normalized_snippet_code) : '';
        $my_functions = $this->extract_function_names($snippet_code);

        foreach ($parsed_rows as $other_id => $other_parsed) {
            if ((int) $other_id === $snippet_id) {
                continue;
            }

            $status = $other_parsed['active'] ? 'ACTIVE' : 'inactive';

            if ($other_parsed['name_key'] === $snippet_name_key) {
                $result['reasons'][] = "Same name as snippet #{$other_id} ({$status})";
            }

            if ($snippet_code_hash !== '' && $other_parsed['code_hash'] !== '' && $snippet_code_hash === $other_parsed['code_hash']) {
                $result['reasons'][] = "Exact same code as snippet #{$other_id} ({$status})";
            }

            if (!empty($my_functions) && !empty($other_parsed['functions'])) {
                $overlap = array_intersect($my_functions, $other_parsed['functions']);
                if (!empty($overlap)) {
                    $result['reasons'][] = "Shares functions (" . implode(', ', $overlap) . ") with snippet #{$other_id} ({$status})";
                }
            }
        }

        if (!empty($result['reasons'])) {
            $result['reasons'] = array_values(array_unique($result['reasons']));
            $result['has_duplicate'] = true;
        }

        return $result;
    }
}
