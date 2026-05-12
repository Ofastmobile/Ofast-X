<?php
$file = 'includes/security/class-ofast-math-captcha.php';
$content = file_get_contents($file);

// Find the current render_widget method
$old = <<<'PHP'
    public function render_widget($form_id = 'default')
    {
        $problem = $this->generate_problem();
        
        $html = '<div class="ofast-math-captcha" style="margin: 15px 0;">';
        $html .= '<label style="display: flex; align-items: center; gap: 8px; font-weight: 500; color: #334155;">';
        $html .= '<span style="background: #6366f1; color: #fff; padding: 4px 8px; border-radius: 5px; font-size: 13px; font-weight: 600;">';
        $html .= esc_html($problem['question']) . ' = ?';
        $html .= '</span>';
        $required = ($form_id === 'preview') ? '' : 'required';
        $html .= '<input type="number" name="ofast_math_answer" ' . $required . ' autocomplete="off" ';
        $html .= 'style="width: 60px; padding: 4px 6px; border: 1.5px solid #e2e8f0; border-radius: 5px; font-size: 13px; text-align: center;" ';
        $html .= 'placeholder="?">';
        $html .= '</label>';
        $html .= '<input type="hidden" name="ofast_math_hash" value="' . esc_attr($problem['hash']) . '">';
        $html .= '</div>';
        
        return $html;
    }
PHP;

$new = <<<'PHP'
    public function render_widget($form_id = 'default')
    {
        $problem = $this->generate_problem();
        $is_preview = ($form_id === 'preview');

        // Admin preview uses original sizing; frontend forms use compact input
        if ($is_preview) {
            $badge_style = 'background: #6366f1; color: #fff; padding: 8px 12px; border-radius: 6px; font-size: 16px; font-weight: 600;';
            $input_style = 'width: 80px; padding: 8px 12px; border: 2px solid #e2e8f0; border-radius: 6px; font-size: 16px; text-align: center;';
            $label_gap = '10px';
        } else {
            $badge_style = 'background: #6366f1; color: #fff; padding: 6px 10px; border-radius: 6px; font-size: 14px; font-weight: 600;';
            $input_style = 'width: 70px; padding: 4px 6px; border: 1.5px solid #e2e8f0; border-radius: 5px; font-size: 13px; text-align: center;';
            $label_gap = '8px';
        }

        $required = $is_preview ? '' : 'required';

        $html = '<div class="ofast-math-captcha" style="margin: 15px 0;">';
        $html .= '<label style="display: flex; align-items: center; gap: ' . $label_gap . '; font-weight: 500; color: #334155;">';
        $html .= '<span style="' . $badge_style . '">';
        $html .= esc_html($problem['question']) . ' = ?';
        $html .= '</span>';
        $html .= '<input type="number" name="ofast_math_answer" ' . $required . ' autocomplete="off" ';
        $html .= 'style="' . $input_style . '" ';
        $html .= 'placeholder="?">';
        $html .= '</label>';
        $html .= '<input type="hidden" name="ofast_math_hash" value="' . esc_attr($problem['hash']) . '">';
        $html .= '</div>';
        
        return $html;
    }
PHP;

if (strpos($content, $old) !== false) {
    $content = str_replace($old, $new, $content);
    file_put_contents($file, $content);
    echo "SUCCESS: Split styling - admin preview normal, frontend compact";
} else {
    echo "ERROR: Could not find render_widget method";
}
