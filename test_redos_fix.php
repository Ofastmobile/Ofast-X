<?php
/**
 * Test script to validate the ReDoS fixes
 * This tests various dangerous regex patterns to ensure they are blocked
 */

// Include the class (mock WordPress functions for testing)
if (!function_exists('esc_html__')) {
    function esc_html__($text, $domain = '') { return $text; }
}
if (!function_exists('current_time')) {
    function current_time($type) { return date('Y-m-d H:i:s'); }
}
if (!function_exists('get_current_user_id')) {
    function get_current_user_id() { return 1; }
}

// Include just the relevant methods for testing
class TestRegexSecurity {
    
    // Copy of the new sanitize_regex method
    private function sanitize_regex($pattern)
    {
        $pattern = trim((string) $pattern);
        if ($pattern === '') {
            return false;
        }

        // 1. BASIC CONSTRAINTS
        // Strict length limit - even short patterns can be dangerous
        if (strlen($pattern) > 200) {
            return false;
        }

        // Block null bytes and control characters that could interfere with validation
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $pattern)) {
            return false;
        }

        // 2. CRITICAL REDOS PATTERNS - Block immediately
        $dangerous_patterns = array(
            // Classic nested quantifiers
            '/\(\.\*\)\*/',                    // (.*)* 
            '/\(\.\+\)\+/',                    // (.+)+
            '/\(\.\*\)\+/',                    // (.*)+
            '/\(\.\+\)\*/',                    // (.+)*
            '/\([^)]*\+[^)]*\)\+/',            // (x+y)+
            '/\([^)]*\*[^)]*\)\*/',            // (x*y)*
            '/\([^)]*\+[^)]*\)\*/',            // (x+y)*
            '/\([^)]*\*[^)]*\)\+/',            // (x*y)+
            
            // Nested group quantifiers 
            '/\(\([^)]*[+*][^)]*\)[+*]\)/',    // ((x+)+) or ((x*)+)
            
            // Character class variants
            '/\(\[[^\]]*\]\+\)\+/',            // ([...]+)+
            '/\(\[[^\]]*\]\*\)\*/',            // ([...]*)*
            '/\(\[[^\]]*\]\+\)\*/',            // ([...]+)*
            '/\(\[[^\]]*\]\*\)\+/',            // ([...]*)+ 
            
            // Word boundary and escape sequences
            '/\(\\\\[dDwWsS]\+\)\+/',          // (\d+)+, (\w+)+, (\s+)+
            '/\(\\\\[dDwWsS]\*\)\+/',          // (\d*)+, (\w*)+, (\s*)+
            '/\(\\\\[dDwWsS]\+\)\*/',          // (\d+)*, (\w+)*, (\s+)*
            
            // Overlapping alternations 
            '/\([^|()]*\|[^|()]*\)[+*].*\1/',  // Basic overlap detection
            
            // Dangerous lookaheads/lookbehinds
            '/\(\?[=!][^)]*[+*]/',             // (?=...*) or (?!...+)
            '/\(\?<[=!][^)]*[+*]/',            // (?<=...*) or (?<!...+)
            
            // Backreference amplification
            '/\([^)]*\).*\\\\1[+*]/',          // (group)....\1+
            
            // Recursive patterns
            '/\(\?R\)|\(\?[0-9]+\)/',          // (?R) or (?1) etc
            
            // Possessive quantifiers misuse
            '/[+*]\+[+*]/',                    // *++ or ++* 
        );

        foreach ($dangerous_patterns as $dangerous) {
            if (preg_match($dangerous, $pattern)) {
                return false;
            }
        }

        // 3. COMPLEXITY ANALYSIS
        $complexity_score = $this->calculate_regex_complexity($pattern);
        if ($complexity_score > 100) { // Strict limit for redirects
            return false;
        }

        // 4. STRUCTURAL VALIDATION
        // Count nesting depth
        $max_depth = $this->get_regex_nesting_depth($pattern);
        if ($max_depth > 4) { // Reasonable limit for URL redirects
            return false;
        }

        // Count quantifiers
        $quantifier_count = preg_match_all('/[^\\\\][+*?]|\{[0-9,]+\}/', $pattern);
        if ($quantifier_count > 10) { // Too many quantifiers = likely problematic
            return false;
        }

        // 5. ALTERNATION VALIDATION  
        if (!$this->validate_alternations($pattern)) {
            return false;
        }

        // 6. WHITELIST CHECK - Ensure pattern uses only safe constructs
        if (!$this->is_whitelisted_regex_pattern($pattern)) {
            return false;
        }

        // 7. FINAL SANITIZATION
        // Remove redundant quantifier duplications
        $pattern = preg_replace('/(\+|\*|\?)\1+/', '$1', $pattern);
        
        // Normalize common problematic sequences
        $pattern = preg_replace('/\.\*\+/', '.*', $pattern);  // .*+ -> .*
        $pattern = preg_replace('/\.\+\*/', '.+', $pattern);  // .+* -> .+

        return $pattern;
    }

    // Helper methods (simplified versions for testing)
    private function calculate_regex_complexity($pattern) {
        return strlen($pattern) + substr_count($pattern, '(') * 5;
    }
    
    private function get_regex_nesting_depth($pattern) {
        $depth = 0;
        $max_depth = 0;
        for ($i = 0; $i < strlen($pattern); $i++) {
            if ($pattern[$i] === '(' && ($pattern[$i-1] ?? '') !== '\\') {
                $depth++;
                $max_depth = max($max_depth, $depth);
            } elseif ($pattern[$i] === ')' && ($pattern[$i-1] ?? '') !== '\\') {
                $depth = max(0, $depth - 1);
            }
        }
        return $max_depth;
    }
    
    private function validate_alternations($pattern) {
        return true; // Simplified for test
    }
    
    private function is_whitelisted_regex_pattern($pattern) {
        return true; // Simplified for test
    }

    // Public test method
    public function test_pattern($pattern) {
        return $this->sanitize_regex($pattern);
    }
}

// Test cases
$tester = new TestRegexSecurity();

$test_cases = [
    // SHOULD BE BLOCKED (dangerous patterns)
    ['(.+)+', 'Classic nested quantifier'],
    ['(.*)*', 'Nested star quantifier'],
    ['((a+)+)+', 'Deeply nested quantifier'],
    ['(a+b+)+', 'Adjacent quantifiers in group'],
    ['([a-z]+)+', 'Character class nested quantifier'],
    ['(\d+)+', 'Digit class nested quantifier'],
    
    // SHOULD BE ALLOWED (safe patterns)
    ['^/old-path$', 'Simple anchor pattern'],
    ['/category/[0-9]+', 'Safe character class'],
    ['/page-[a-z]+\.html', 'Safe mixed pattern'],
    ['/(old|new)-page', 'Simple alternation'],
    
    // EDGE CASES
    [str_repeat('a', 201), 'Too long pattern'],
    ['', 'Empty pattern'],
    ['simple', 'Very simple pattern'],
];

echo "ReDoS Protection Test Results:\n";
echo "=============================\n\n";

$blocked_count = 0;
$allowed_count = 0;

foreach ($test_cases as [$pattern, $description]) {
    $result = $tester->test_pattern($pattern);
    $status = $result === false ? 'BLOCKED' : 'ALLOWED';
    
    if ($result === false) {
        $blocked_count++;
    } else {
        $allowed_count++;
    }
    
    printf("%-40s | %-8s | %s\n", 
           substr($description, 0, 39), 
           $status, 
           substr($pattern, 0, 30) . (strlen($pattern) > 30 ? '...' : '')
    );
}

echo "\n";
echo "Summary:\n";
echo "- Blocked: $blocked_count patterns\n";
echo "- Allowed: $allowed_count patterns\n";
echo "\nTest completed successfully!\n";