<?php

/**
 * Ofast X - Math CAPTCHA
 * 
 * Simple arithmetic challenge for spam protection.
 * No external dependencies - works offline.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ofast_X_Math_Captcha
{
    private static $instance = null;
    
    // Session key for storing the correct answer
    const SESSION_KEY = 'ofast_math_captcha_answer';
    const NONCE_KEY = 'ofast_math_captcha_nonce';
    
    /**
     * Get singleton instance
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Initialize Math CAPTCHA
     */
    public function init()
    {
        // Start session if not already started (needed for storing answer)
        add_action('init', array($this, 'maybe_start_session'), 1);
        
        // Also start session on login page (fires before authenticate filter)
        add_action('login_init', array($this, 'maybe_start_session'), 1);
    }
    
    /**
     * Start session if needed
     */
    public function maybe_start_session()
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }
    }
    
    /**
     * Check if Math CAPTCHA is configured (always true - no external keys needed)
     */
    public function is_configured()
    {
        return true; // Always configured - no API keys required
    }
    
    /**
     * Get difficulty setting
     * @return string easy|medium|hard
     */
    private function get_difficulty()
    {
        return get_option('ofast_math_captcha_difficulty', 'easy');
    }
    
    /**
     * Get allowed operations
     * @return array
     */
    private function get_operations()
    {
        $ops = get_option('ofast_math_captcha_operations', array('add'));
        if (empty($ops)) {
            return array('add');
        }
        return $ops;
    }
    
    /**
     * Generate a math problem
     * @return array ['question' => '5 + 3', 'answer' => 8, 'hash' => 'abc123']
     */
    public function generate_problem()
    {
        $difficulty = $this->get_difficulty();
        $operations = $this->get_operations();
        
        // Set number ranges based on difficulty
        switch ($difficulty) {
            case 'hard':
                $min = 10;
                $max = 99;
                break;
            case 'medium':
                $min = 5;
                $max = 50;
                break;
            case 'easy':
            default:
                $min = 1;
                $max = 10;
                break;
        }
        
        // Pick random operation
        $operation = $operations[array_rand($operations)];
        
        // Generate numbers
        $num1 = wp_rand($min, $max);
        $num2 = wp_rand($min, $max);
        
        // For subtraction, ensure result is positive
        if ($operation === 'subtract' && $num2 > $num1) {
            $temp = $num1;
            $num1 = $num2;
            $num2 = $temp;
        }
        
        // Calculate answer and build question string
        switch ($operation) {
            case 'subtract':
                $answer = $num1 - $num2;
                $question = "{$num1} − {$num2}";
                $symbol = '−';
                break;
            case 'multiply':
                // Keep multiplication simpler
                $num1 = wp_rand(2, min(12, $max));
                $num2 = wp_rand(2, min(12, $max));
                $answer = $num1 * $num2;
                $question = "{$num1} × {$num2}";
                $symbol = '×';
                break;
            case 'add':
            default:
                $answer = $num1 + $num2;
                $question = "{$num1} + {$num2}";
                $symbol = '+';
                break;
        }
        
        // Create a unique hash for this problem
        $salt = wp_salt('auth');
        $hash = wp_hash($answer . $salt . time());
        
        // Store in session
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION[self::SESSION_KEY] = $answer;
            $_SESSION[self::NONCE_KEY] = $hash;
        }
        
        // Also store in transient as fallback (for environments without sessions)
        set_transient('ofast_math_' . $hash, $answer, 3600); // 1 hour expiry
        
        return array(
            'question' => $question,
            'answer' => $answer,
            'hash' => $hash,
            'num1' => $num1,
            'num2' => $num2,
            'symbol' => $symbol
        );
    }
    
    /**
     * Render Math CAPTCHA widget HTML
     * @param string $form_id Optional form identifier
     * @return string HTML
     */
    public function render_widget($form_id = 'default')
    {
        $problem = $this->generate_problem();
        
        $html = '<div class="ofast-math-captcha" style="margin: 15px 0;">';
        $html .= '<label style="display: flex; align-items: center; gap: 10px; font-weight: 500; color: #334155;">';
        $html .= '<span style="background: #6366f1; color: #fff; padding: 8px 12px; border-radius: 6px; font-size: 16px; font-weight: 600;">';
        $html .= esc_html($problem['question']) . ' = ?';
        $html .= '</span>';
        $required = ($form_id === 'preview') ? '' : 'required';
        $html .= '<input type="number" name="ofast_math_answer" ' . $required . ' autocomplete="off" ';
        $html .= 'style="width: 80px; padding: 8px 12px; border: 2px solid #e2e8f0; border-radius: 6px; font-size: 16px; text-align: center;" ';
        $html .= 'placeholder="?">';
        $html .= '</label>';
        $html .= '<input type="hidden" name="ofast_math_hash" value="' . esc_attr($problem['hash']) . '">';
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Verify the answer
     * @param int|null $answer User's answer (if null, reads from $_POST)
     * @param string|null $hash Problem hash (if null, reads from $_POST)
     * @return array ['success' => bool, 'error' => string|null]
     */
    public function verify($answer = null, $hash = null)
    {
        // Get values from POST if not provided
        if ($answer === null) {
            $answer = isset($_POST['ofast_math_answer']) ? intval($_POST['ofast_math_answer']) : null;
        }
        if ($hash === null) {
            $hash = isset($_POST['ofast_math_hash']) ? sanitize_text_field($_POST['ofast_math_hash']) : '';
        }
        
        // Check if answer provided
        if ($answer === null || $answer === '') {
            return array(
                'success' => false,
                'error' => 'Please solve the math problem'
            );
        }
        
        $answer = intval($answer);
        $correct_answer = null;
        
        // Try session first
        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION[self::SESSION_KEY])) {
            if (isset($_SESSION[self::NONCE_KEY]) && $_SESSION[self::NONCE_KEY] === $hash) {
                $correct_answer = intval($_SESSION[self::SESSION_KEY]);
            }
        }
        
        // Fallback to transient
        if ($correct_answer === null && !empty($hash)) {
            $stored = get_transient('ofast_math_' . $hash);
            if ($stored !== false) {
                $correct_answer = intval($stored);
                // Delete transient after use (one-time use)
                delete_transient('ofast_math_' . $hash);
            }
        }
        
        // No stored answer found
        if ($correct_answer === null) {
            return array(
                'success' => false,
                'error' => 'Math challenge expired. Please try again.'
            );
        }
        
        // Verify answer
        if ($answer === $correct_answer) {
            // Clear session data
            if (session_status() === PHP_SESSION_ACTIVE) {
                unset($_SESSION[self::SESSION_KEY]);
                unset($_SESSION[self::NONCE_KEY]);
            }
            
            return array(
                'success' => true,
                'error' => null
            );
        }
        
        // Wrong answer
        return array(
            'success' => false,
            'error' => 'Incorrect answer. Please try again.'
        );
    }
    
    /**
     * Render settings form for admin
     */
    public function render_settings_form()
    {
        $difficulty = get_option('ofast_math_captcha_difficulty', 'easy');
        $operations = get_option('ofast_math_captcha_operations', array('add'));
        
        if (!is_array($operations)) {
            $operations = array('add');
        }
        ?>
        <p class="description" style="margin-bottom: 15px;">
            <span style="color: #10b981;">✓</span> No API keys required - works offline!
        </p>
        
        <table class="form-table" style="margin: 0;">
            <tr>
                <th scope="row">Difficulty</th>
                <td>
                    <select name="math_captcha_difficulty" style="min-width: 200px;">
                        <option value="easy" <?php selected($difficulty, 'easy'); ?>>Easy (1-10)</option>
                        <option value="medium" <?php selected($difficulty, 'medium'); ?>>Medium (5-50)</option>
                        <option value="hard" <?php selected($difficulty, 'hard'); ?>>Hard (10-99)</option>
                    </select>
                    <p class="description">Higher difficulty = larger numbers</p>
                </td>
            </tr>
            <tr>
                <th scope="row">Operations</th>
                <td>
                    <label style="display: block; margin-bottom: 8px;">
                        <input type="checkbox" name="math_captcha_operations[]" value="add" 
                            <?php checked(in_array('add', $operations)); ?>>
                        Addition (+)
                    </label>
                    <label style="display: block; margin-bottom: 8px;">
                        <input type="checkbox" name="math_captcha_operations[]" value="subtract" 
                            <?php checked(in_array('subtract', $operations)); ?>>
                        Subtraction (−)
                    </label>
                    <label style="display: block;">
                        <input type="checkbox" name="math_captcha_operations[]" value="multiply" 
                            <?php checked(in_array('multiply', $operations)); ?>>
                        Multiplication (×)
                    </label>
                    <p class="description" style="margin-top: 8px;">Select at least one operation</p>
                </td>
            </tr>
        </table>
        
        <div style="margin-top: 20px;">
            <strong>Preview:</strong>
            <div style="margin-top: 10px;">
                <?php echo $this->render_widget('preview'); ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Save settings from admin form
     */
    public static function save_settings($data)
    {
        if (isset($data['math_captcha_difficulty'])) {
            $allowed = array('easy', 'medium', 'hard');
            $difficulty = sanitize_text_field($data['math_captcha_difficulty']);
            if (in_array($difficulty, $allowed)) {
                update_option('ofast_math_captcha_difficulty', $difficulty);
            }
        }
        
        if (isset($data['math_captcha_operations'])) {
            $allowed_ops = array('add', 'subtract', 'multiply');
            $ops = array_intersect($data['math_captcha_operations'], $allowed_ops);
            if (!empty($ops)) {
                update_option('ofast_math_captcha_operations', $ops);
            }
        }
    }
}
