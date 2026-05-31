# Ofast Toolkit: Licensing & Pro-Feature Strategy

This guide outlines the "Freemium" licensing model for Ofast Toolkit.

## 1. The Strategy: "Single Binary"
Instead of two plugins, we use one plugin that "unlocks" features based on a license key.
*   **Free Users:** See all modules but can only use basic features.
*   **Pro Users:** Enter a license key purchased from ofastshop.com to unlock premium features.

---

## 2. Technical Architecture

### A. The Central "Gatekeeper"
Every licensing check goes through `ofast_toolkit_is_pro()` in `includes/core/class-ofast-licensing.php`.

```php
/**
 * Check if the user has a valid Pro license.
 * Freemium model: Free features always available, Pro requires a license key.
 *
 * @return boolean True if Pro (licensed), False if free.
 */
function ofast_toolkit_is_pro() {
    if ( ofast_toolkit_has_valid_license() ) {
        return true;
    }
    return false;
}
```

### B. UI Layer: The "Padlock" Pattern
For features inside a module that are Pro-only, follow this pattern:
1.  **Visible but Disabled:** Show the setting so users know it exists (and want it!).
2.  **The Padlock:** Use `ofast_toolkit_pro_badge()` next to the label.
3.  **The Disabled Attribute:** Use `ofast_toolkit_pro_disabled()` on the input/select.

**Example (HTML/PHP):**
```html
<label>
    Auto-Resend Failed Emails
    <?php ofast_toolkit_pro_badge(); ?>
</label>
<input type="checkbox" <?php ofast_toolkit_pro_disabled(); ?>>
```

### C. Logic Layer: Guarding Actions
Never trust the UI alone. You must also guard the backend save handler so a crafted POST can't bypass the locked button.

```php
public function handle_save() {
    // Block entire save if module is Pro-only and user is Free
    if ( ! ofast_toolkit_is_pro() ) {
        return;
    }
}
```

---

## 3. Licensing Flow

1.  **Purchase:** User buys on ofastshop.com → license key auto-generated and emailed.
2.  **Activate:** User enters key in WP Admin → Ofast Toolkit → License page.
3.  **Server Validates:** Key is checked against `ofastshop.com/wp-json/ofast-license/v1/activate`.
4.  **Pro Unlocked:** `ofast_toolkit_is_pro()` returns `true`, all Pro features available.
5.  **Periodic Re-validation:** Monthly cron re-validates the license against the server.
6.  **One Key = One Site:** Deactivate from one site before activating on another.

---

## 4. Why this works for you
*   **Ease of Management:** 100% of your code is in one folder.
*   **Frictionless Upgrade:** Users don't have to delete the free plugin to install the Pro one. They just "unlock" it.
*   **Marketing:** Having "locked" features in the free version is the best way to get users to upgrade.
*   **Security:** Server-side HMAC signature + domain binding prevents forgery and sharing.

---

## 5. Recommended "Pro" Boundaries

| Module | Free Features | Pro Features |
| :--- | :--- | :--- |
| **SMTP** | Basic SMTP, Email Log | Rate Limiting, Fallback Host, Health Reports |
| **Spam Protection** | Honeypot, Basic CAPTCHA | Force All Forms, Fail Open |
| **Login Redesign** | Simple Template | Two-Column, Modern Dark templates |
| **Email Template** | Classic, Minimal styles | Modern, Custom styles |
| **Admin Studio** | — | Entire module (Admin Tweaks) |
| **White Label** | — | Entire module |
| **Social Login** | — | Entire module |
| **Forms** | Full access | — |
| **Redirects** | Full access | — |
| **Snippets** | Full access | — |
