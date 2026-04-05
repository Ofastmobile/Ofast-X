# Ofast Toolkit: Licensing & Pro-Feature Strategy

This guide outlines how to implement a professional "Granular Licensing" model for Ofast Toolkit. 

## 1. The Strategy: "Single Binary"
Instead of two plugins, we use one plugin that "unlocks" features based on a license key.
*   **Standard Users:** See all modules but can only use basic features.
*   **Pro Users:** Unlock advanced settings, automation, and premium UI.

---

## 2. Technical Architecture

### A. The Central "Gatekeeper"
Every licensing check should go through a single, central helper function. This makes it easy to swap Freemius for another system later if needed.

```php
/**
 * Check if the user has a valid Pro license.
 * 
 * @return boolean True if Pro, False if Free.
 */
function ofast_toolkit_is_pro() {
    // This is the "Master Switch"
    // In development, we can force this to true for testing.
    return false; 
}
```

### B. UI Layer: The "Padlock" Pattern
For features inside a module that are Pro-only, follow this pattern:
1.  **Visible but Disabled:** Show the setting so users know it exists (and want it!).
2.  **The Padlock:** Add a dashicon-lock next to the label.
3.  **The Upsell:** If they try to click it, show a sleek modal or a "Get Pro" link.

**Example (HTML/PHP):**
```html
<label>
    Auto-Resend Failed Emails
    <?php if ( ! ofast_toolkit_is_pro() ) : ?>
        <span class="dashicons dashicons-lock ofast-pro-badge"></span>
    <?php endif; ?>
</label>
<input type="checkbox" <?php disabled( ! ofast_toolkit_is_pro() ); ?>>
```

### C. Logic Layer: Guarding Actions
Never trust the UI alone. You must also "lock" the backend logic so a hacker can't just bypass the locked button.

```php
public function handle_save() {
    // Only save the Pro setting if the user actually has Pro
    if ( isset( $_POST['pro_feature_x'] ) && ! ofast_toolkit_is_pro() ) {
        return; 
    }
}
```

---

## 3. Recommended "Pro" Boundaries

Based on your current modules, here is the suggested "Granular" split:

| Module | Free Version Includes | Pro Version Adds |
| :--- | :--- | :--- |
| **SMTP** | Basic SMTP, Email Log (7 days) | Auto-Resend, Email Attachments, Email Reporting |
| **Forms** | Simple Fields, Basic Email Notification | Multi-step Forms, Conditional Logic, File Uploads |
| **Security** | Honeypot, Standard CAPTCHA | Advanced Hardening, Emergency Access Lock |
| **Admin** | Basic Admin Tweaks | **FULL White Label**, Custom Login URL |

---

## 4. Why this works for you
*   **Ease of Management:** 100% of your code is in one folder.
*   **Frictionless Upgrade:** Users don't have to delete the free plugin to install the Pro one. They just "unlock" it.
*   **Marketing:** Having "locked" features in the free version is the best way to get users to upgrade.

---

## 5. Next Implementation Steps
1.  **Helper File:** Create `includes/core/class-ofast-licensing.php`.
2.  **Initialize SDK:** Add the Freemius SDK hooks.
3.  **Audit UI:** Scroll through every settings page and add "Padlock" checks to the Pro candidates.
