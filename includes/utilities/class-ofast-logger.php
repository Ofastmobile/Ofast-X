<?php
/**
 * Ofast X Logger
 * File-based logging system for debugging and monitoring
 * 
 * @package Ofast_X
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Logger {
    
    /**
     * Log directory path
     * 
     * @var string
     */
    private static $log_dir = '';
    
    /**
     * Log levels
     */
    const ERROR   = 'ERROR';
    const WARNING = 'WARNING';
    const INFO    = 'INFO';
    const DEBUG   = 'DEBUG';
    
    /**
     * Initialize logger
     */
    public static function init() {
        // Set log directory
        $upload_dir = wp_upload_dir();
        self::$log_dir = $upload_dir['basedir'] . '/ofast-x-logs';
        
        // Create log directory if doesn't exist
        self::create_log_dir();
    }
    
    /**
     * Create log directory with security
     */
    private static function create_log_dir() {
        if (!file_exists(self::$log_dir)) {
            wp_mkdir_p(self::$log_dir);
            
            // Create .htaccess to deny access
            $htaccess_content = "Order deny,allow\nDeny from all";
            file_put_contents(self::$log_dir . '/.htaccess', $htaccess_content);
            
            // Create index.php to prevent directory listing
            file_put_contents(self::$log_dir . '/index.php', '<?php // Silence is golden');
        }
    }
    
    /**
     * Get log file path
     * 
     * @return string Log file path
     */
    private static function get_log_file() {
        return self::$log_dir . '/ofast-x-' . date('Y-m-d') . '.log';
    }
    
    /**
     * Write log entry
     * 
     * @param string $level Log level
     * @param string $message Log message
     * @param array $context Additional context data
     */
    private static function write($level, $message, $context = array()) {
        // Only log if WP_DEBUG is enabled or it's an error
        if (!defined('WP_DEBUG') || (!WP_DEBUG && $level !== self::ERROR)) {
            return;
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $user_id = get_current_user_id();
        $user_info = $user_id ? 'User:' . $user_id : 'Guest';
        
        // Format log entry
        $log_entry = sprintf(
            "[%s] [%s] [%s] %s",
            $timestamp,
            $level,
            $user_info,
            $message
        );
        
        // Add context if provided
        if (!empty($context)) {
            $log_entry .= ' | Context: ' . json_encode($context);
        }
        
        $log_entry .= PHP_EOL;
        
        // Write to file
        file_put_contents(self::get_log_file(), $log_entry, FILE_APPEND);
    }
    
    /**
     * Log error message
     * 
     * @param string $message Error message
     * @param array $context Additional context
     */
    public static function error($message, $context = array()) {
        self::write(self::ERROR, $message, $context);
        
        // Also log to PHP error log
        error_log('Ofast-X ERROR: ' . $message);
    }
    
    /**
     * Log warning message
     * 
     * @param string $message Warning message
     * @param array $context Additional context
     */
    public static function warning($message, $context = array()) {
        self::write(self::WARNING, $message, $context);
    }
    
    /**
     * Log info message
     * 
     * @param string $message Info message
     * @param array $context Additional context
     */
    public static function info($message, $context = array()) {
        self::write(self::INFO, $message, $context);
    }
    
    /**
     * Log debug message
     * 
     * @param string $message Debug message
     * @param array $context Additional context
     */
    public static function debug($message, $context = array()) {
        self::write(self::DEBUG, $message, $context);
    }
    
    /**
     * Log security event
     * 
     * @param string $event Event description
     * @param array $context Additional context
     */
    public static function security($event, $context = array()) {
        $context['ip'] = self::get_user_ip();
        $context['user_agent'] = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'Unknown';
        
        self::write('SECURITY', $event, $context);
    }
    
    /**
     * Log email event
     * 
     * @param string $event Event description
     * @param array $context Additional context
     */
    public static function email($event, $context = array()) {
        self::write('EMAIL', $event, $context);
    }
    
    /**
     * Get user IP address
     * 
     * @return string IP address
     */
    private static function get_user_ip() {
        $ip = '';
        
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }
    
    /**
     * Get recent log entries
     * 
     * @param int $lines Number of lines to retrieve
     * @param string $level Filter by level (optional)
     * @return array Log entries
     */
    public static function get_recent($lines = 100, $level = '') {
        $log_file = self::get_log_file();
        
        if (!file_exists($log_file)) {
            return array();
        }
        
        $content = file($log_file);
        $content = array_reverse($content);
        
        // Filter by level if specified
        if ($level) {
            $content = array_filter($content, function($line) use ($level) {
                return strpos($line, '[' . $level . ']') !== false;
            });
        }
        
        // Limit lines
        $content = array_slice($content, 0, $lines);
        
        return $content;
    }
    
    /**
     * Clear old log files
     * 
     * @param int $days Keep logs newer than X days
     */
    public static function cleanup($days = 30) {
        if (!is_dir(self::$log_dir)) {
            return;
        }
        
        $files = glob(self::$log_dir . '/ofast-x-*.log');
        $cutoff = strtotime('-' . $days . ' days');
        
        foreach ($files as $file) {
            if (filemtime($file) < $cutoff) {
                unlink($file);
            }
        }
        
        self::info('Log cleanup completed', array('days' => $days));
    }
    
    /**
     * Get log file size
     * 
     * @return string Human-readable file size
     */
    public static function get_log_size() {
        $log_file = self::get_log_file();
        
        if (!file_exists($log_file)) {
            return '0 B';
        }
        
        return size_format(filesize($log_file));
    }
    
    /**
     * Get all log files
     * 
     * @return array Array of log files with metadata
     */
    public static function get_log_files() {
        if (!is_dir(self::$log_dir)) {
            return array();
        }
        
        $files = glob(self::$log_dir . '/ofast-x-*.log');
        $log_files = array();
        
        foreach ($files as $file) {
            $log_files[] = array(
                'name' => basename($file),
                'path' => $file,
                'size' => size_format(filesize($file)),
                'date' => date('Y-m-d H:i:s', filemtime($file))
            );
        }
        
        // Sort by date (newest first)
        usort($log_files, function($a, $b) {
            return strcmp($b['date'], $a['date']);
        });
        
        return $log_files;
    }
    
    /**
     * Download log file
     * 
     * @param string $filename Log filename
     */
    public static function download($filename) {
        $file_path = self::$log_dir . '/' . $filename;
        
        if (!file_exists($file_path)) {
            wp_die(__('Log file not found.', 'ofast-x'));
        }
        
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($file_path));
        
        readfile($file_path);
        exit;
    }
    
    /**
     * Delete log file
     * 
     * @param string $filename Log filename
     * @return bool Success status
     */
    public static function delete($filename) {
        $file_path = self::$log_dir . '/' . $filename;
        
        if (!file_exists($file_path)) {
            return false;
        }
        
        return unlink($file_path);
    }
}

// Initialize logger
Ofast_X_Logger::init();