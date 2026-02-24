# Email Module - Future Updates

## 1. Internationalization (i18n) - Deferred

**What:** Wrap all user-facing strings in `__('text', 'ofast-x')` for translation support.

**Why deferred:** Most users are English-only. Not blocking for WordPress.org submission.

**Scope:** ~200+ strings across `class-ofast-email-admin.php`

**Examples to wrap:**
```php
// Buttons
__('Send Email', 'ofast-x')
__('Save as Draft', 'ofast-x')
__('Preview Email', 'ofast-x')

// Messages
__('Email sent successfully!', 'ofast-x')
__('Failed to send email.', 'ofast-x')
__('Draft saved.', 'ofast-x')

// Labels
__('Subject', 'ofast-x')
__('Recipients', 'ofast-x')
__('Schedule Time', 'ofast-x')
```

**How to implement later:**
1. Search for hardcoded English strings
2. Wrap in `__()` or `esc_html__()`
3. Use `wp_localize_script()` for JS strings
4. Generate .pot file with `wp i18n make-pot`

---

## 2. Email Queue System - Archived

**Location:** `blueprint/future_modules/email_queue/`

**When to bring back:**
- If users report timeout issues with 50+ emails
- If shared hosting users need rate limiting
- When implementing reliable background processing (real cron or AJAX)

**See:** `FUTURE_IMPROVEMENTS.md` in that folder for implementation notes.

---

## 3. Extract Inline CSS/JS - Deferred

**What:** Move inline `<style>` and `<script>` blocks from PHP into separate files.

**Current state:** 17 inline blocks in `class-ofast-email-admin.php`
- Lines: 118, 360, 467, 623, 702, 936, 1309, 1755, 1835, 1924, 2052, 2358, 2414, 2630, 2662, 2973

**Target files to create:**
```
modules/email/css/ofast-email-admin.css
modules/email/js/ofast-email-admin.js
```

**Why deferred:** 
- Works as-is
- WordPress.org accepts inline CSS/JS
- Low priority refactoring

**How to implement later:**
1. Create CSS/JS files in `modules/email/css/` and `modules/email/js/`
2. Move each style/script block to appropriate file
3. Use `wp_enqueue_style()` and `wp_enqueue_script()` in `enqueue_scripts()` method
4. Test each tab thoroughly after extraction
