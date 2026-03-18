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
     * Note: Uses WordPress transients for storage (no PHP sessions)
     */
    public function init()
    {
        // No initialization needed - transients handle storage
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
     * Generate a math problem with enhanced security
     * @return array ['question' => '5 + 3', 'answer' => 8, 'hash' => 'abc123']
     */
    public function generate_problem()
    {
        $difficulty = $this->get_difficulty();
        $operations = $this->get_operations();
        
        // Add randomized complexity to make automated solving harder
        $complexity_boost = wp_rand(1, 3);
        
        // Set number ranges based on difficulty with random variance
        switch ($difficulty) {
            case 'hard':
                $min = wp_rand(8, 15);
                $max = wp_rand(75, 120);
                break;
            case 'medium':
                $min = wp_rand(3, 8);
                $max = wp_rand(35, 65);
                break;
            case 'easy':
            default:
                $min = wp_rand(1, 3);
                $max = wp_rand(8, 15);
                break;
        }
        
        // Enhanced operation selection with weighted randomness
        $weighted_operations = array();
        foreach ($operations as $op) {
            // Add each operation multiple times for weighted selection
            for ($i = 0; $i < wp_rand(2, 4); $i++) {
                $weighted_operations[] = $op;
            }
        }
        $operation = $weighted_operations[wp_rand(0, count($weighted_operations) - 1)];
        
        // Generate more complex problems based on random selection
        $problem_type = wp_rand(1, 100);
        
        if ($problem_type <= 30 && $complexity_boost >= 2) {
            // Multi-step problems (30% chance with complexity boost)
            return $this->generate_multistep_problem($min, $max, $operations);
        } elseif ($problem_type <= 50 && in_array('multiply', $operations)) {
            // Pattern-based problems (20% additional chance)
            return $this->generate_pattern_problem($min, $max);
        } else {
            // Enhanced basic problems with noise
            return $this->generate_enhanced_basic_problem($min, $max, $operation);
        }
    }
    
    /**
     * Generate multi-step math problems
     */
    private function generate_multistep_problem($min, $max, $operations)
    {
        // Create a two-step problem: (a op1 b) op2 c
        $a = wp_rand($min, intval($max * 0.6));
        $b = wp_rand($min, intval($max * 0.6));
        $c = wp_rand($min, intval($max * 0.4));
        
        $op1 = $operations[wp_rand(0, count($operations) - 1)];
        $op2 = $operations[wp_rand(0, count($operations) - 1)];
        
        // Calculate first step
        switch ($op1) {
            case 'subtract':
                if ($b > $a) { $temp = $a; $a = $b; $b = $temp; }
                $step1_result = $a - $b;
                $op1_symbol = '−';
                break;
            case 'multiply':
                $step1_result = $a * $b;
                $op1_symbol = '×';
                break;
            case 'add':
            default:
                $step1_result = $a + $b;
                $op1_symbol = '+';
                break;
        }
        
        // Calculate final result
        switch ($op2) {
            case 'subtract':
                if ($c > $step1_result) { $c = wp_rand($min, $step1_result); }
                $answer = $step1_result - $c;
                $op2_symbol = '−';
                break;
            case 'multiply':
                $c = wp_rand(2, 5); // Keep multiplication manageable
                $answer = $step1_result * $c;
                $op2_symbol = '×';
                break;
            case 'add':
            default:
                $answer = $step1_result + $c;
                $op2_symbol = '+';
                break;
        }
        
        $question = "({$a} {$op1_symbol} {$b}) {$op2_symbol} {$c}";
        
        return $this->finalize_problem($question, $answer, $a, $b, $op1_symbol);
    }
    
    /**
     * Generate pattern-based problems
     */
    private function generate_pattern_problem($min, $max)
    {
        $pattern_type = wp_rand(1, 3);
        $num1 = 0;
        $num2 = 0;
        $symbol = "+";
        
        switch ($pattern_type) {
            case 1:
                // Square numbers: "What is 7²?"
                $base = wp_rand(3, intval(sqrt($max)));
                $answer = $base * $base;
                $question = "{$base}²";
                $num1 = $base;
                $num2 = $base;
                $symbol = '²';
                break;
                
            case 2:
                // Sequential addition: "2 + 4 + 6"
                $start = wp_rand(2, 5) * 2; // Even numbers
                $answer = $start + ($start + 2) + ($start + 4);
                $question = "{$start} + " . ($start + 2) . " + " . ($start + 4);
                $num1 = $start;
                $num2 = $start + 2;
                $symbol = "+";
                break;
                
            case 3:
            default:
                // Factorial-like: "3 × 4 × 2"
                $nums = array(wp_rand(2, 4), wp_rand(2, 5), wp_rand(2, 3));
                shuffle($nums);
                $answer = $nums[0] * $nums[1] * $nums[2];
                $question = "{$nums[0]} × {$nums[1]} × {$nums[2]}";
                $num1 = $nums[0];
                $num2 = $nums[1];
                $symbol = '×';
                break;
        }
        
        return $this->finalize_problem($question, $answer, $num1, $num2, $symbol);
    }
    
    /**
     * Generate enhanced basic problems with additional entropy
     */
    private function generate_enhanced_basic_problem($min, $max, $operation)
    {
        // Add entropy through varied number generation
        $entropy_factor = wp_rand(1, 4);
        
        if ($entropy_factor === 1) {
            // Use prime numbers for added complexity
            $primes = array(2, 3, 5, 7, 11, 13, 17, 19, 23, 29, 31, 37, 41, 43, 47);
            $available_primes = array_filter($primes, function($p) use ($min, $max) {
                return $p >= $min && $p <= $max;
            });
            
            if (!empty($available_primes)) {
                $num1 = $available_primes[array_rand($available_primes)];
                $num2 = wp_rand($min, $max);
            } else {
                $num1 = wp_rand($min, $max);
                $num2 = wp_rand($min, $max);
            }
        } else {
            // Standard random generation with variance
            $range_variance = wp_rand(0, intval(($max - $min) * 0.3));
            $num1 = wp_rand($min, $max - $range_variance);
            $num2 = wp_rand($min + $range_variance, $max);
        }
        
        // For subtraction, ensure result is positive with additional checks
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
                // Enhanced multiplication with controlled complexity
                $num1 = wp_rand(2, min(15, $max));
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
        
        return $this->finalize_problem($question, $answer, $num1, $num2, $symbol);
    }
    
    /**
     * Finalize problem generation with enhanced security
     */
    private function finalize_problem($question, $answer, $num1, $num2, $symbol)
    {
        // Create enhanced hash with multiple entropy sources
        $salt = wp_salt('auth');
        $entropy = wp_rand(100000, 999999);
        $timestamp = time();
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
        $remote_addr = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
        
        $hash_input = $answer . $salt . $entropy . $timestamp . md5($user_agent . $remote_addr);
        $hash = wp_hash($hash_input);
        
        // Store answer with additional metadata for verification
        $stored_data = array(
            'answer' => $answer,
            'timestamp' => $timestamp,
            'entropy' => $entropy,
            'attempts' => 0
        );
        set_transient('ofast_math_' . $hash, $stored_data, 1800); // Reduced to 30 minutes
        
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
     * Verify the answer with enhanced security checks
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
        
        // Rate limiting check
        $rate_limit_check = $this->check_rate_limit();
        if (!$rate_limit_check['allowed']) {
            return array(
                'success' => false,
                'error' => $rate_limit_check['error']
            );
        }
        
        // Check if answer provided
        if ($answer === null || $answer === '') {
            return array(
                'success' => false,
                'error' => 'Please solve the math problem'
            );
        }
        
        $answer = intval($answer);
        $stored_data = null;
        
        // Get stored data from transient
        if (!empty($hash)) {
            $stored = get_transient('ofast_math_' . $hash);
            if ($stored !== false) {
                // Handle both old format (integer) and new format (array)
                if (is_array($stored)) {
                    $stored_data = $stored;
                } else {
                    // Legacy support for old format
                    $stored_data = array(
                        'answer' => intval($stored),
                        'timestamp' => time(),
                        'entropy' => 0,
                        'attempts' => 0
                    );
                }
            }
        }
        
        // No stored answer found
        if ($stored_data === null) {
            return array(
                'success' => false,
                'error' => 'Math challenge expired. Please try again.'
            );
        }
        
        // Check for replay attacks by verifying timestamp
        $age = time() - $stored_data['timestamp'];
        if ($age > 1800) { // 30 minutes max
            delete_transient('ofast_math_' . $hash);
            return array(
                'success' => false,
                'error' => 'Math challenge expired. Please try again.'
            );
        }
        
        // Increment attempt counter and update
        $stored_data['attempts']++;
        if ($stored_data['attempts'] > 3) {
            delete_transient('ofast_math_' . $hash);
            return array(
                'success' => false,
                'error' => 'Too many attempts. Please refresh and try again.'
            );
        }
        
        // Update attempt counter
        set_transient('ofast_math_' . $hash, $stored_data, 1800);
        
        // Verify answer with additional entropy validation
        $correct_answer = intval($stored_data['answer']);
        if ($answer === $correct_answer) {
            // Success - delete transient (one-time use)
            delete_transient('ofast_math_' . $hash);
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
     * Rate limiting to prevent brute force attacks
     * @return array ['allowed' => bool, 'error' => string|null]
     */
    private function check_rate_limit()
    {
        $client_ip = $this->get_client_ip();
        $rate_key = 'ofast_math_rate_' . md5($client_ip);
        $attempts = get_transient($rate_key);
        
        if ($attempts === false) {
            $attempts = 0;
        }
        
        // Allow 10 attempts per 5 minutes
        if ($attempts >= 10) {
            return array(
                'allowed' => false,
                'error' => 'Too many attempts. Please wait 5 minutes before trying again.'
            );
        }
        
        // Increment and store attempts
        set_transient($rate_key, $attempts + 1, 300); // 5 minutes
        
        return array(
            'allowed' => true,
            'error' => null
        );
    }
    
    /**
     * Get client IP with proxy support
     * @return string
     */
    private function get_client_ip()
    {
        $ip_keys = array(
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        );
        
        foreach ($ip_keys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                $ip = $_SERVER[$key];
                if (strpos($ip, ',') !== false) {
                    $ip = explode(',', $ip)[0];
                }
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
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
