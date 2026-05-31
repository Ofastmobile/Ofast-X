# Fix Main Branch Bugs + Module Reorganization

We stay on `main` (`05167bc`) and fix surgically. **No commits are reverted.** Your full history stays intact.

---

## Phase 1: Fix CSS/JS Scope Leaks (Bugs 1, 2, 3)

These three bugs share the same root cause: the inline-to-asset CSS/JS migration extracted styles into external files, but the enqueue hooks may load them too broadly OR the extraction lost/mangled some rules.

### Bug 1: Dashboard Shifted Down + Menu Shifted + Dark Background Cut

### Bug 2: Media Library Doesn't Display Images

### Bug 3: Admin Studio & Emailer Footer Text Jumps to Middle

**Root Cause:** When CSS was moved from inline `<style>` blocks (scoped to one page) to external `.css` files, the enqueue hook `strpos($hook, 'ofast-admin-tweaks')` should restrict loading. But if any CSS leaks globally OR if the extracted CSS has layout rules affecting `#wpcontent`, `#adminmenuwrap`, `#wpfooter`, or media modal elements, it breaks other pages.

**Approach:** Compare the stable commit's inline CSS against the current extracted CSS files to find mismatches, then fix scoping.

#### [MODIFY] [class-ofast-admin-tweaks.php](file:///c:/Users/bodma/Local%20Sites/ofast-x-dev/app/public/wp-content/plugins/ofast-x/modules/admin-studio/class-ofast-admin-tweaks.php)
- Verify `enqueue_assets()` only fires on Admin Studio pages
- Ensure no global admin hooks inject styles on all pages

#### [MODIFY] [admin-tweaks.css](file:///c:/Users/bodma/Local%20Sites/ofast-x-dev/app/public/wp-content/plugins/ofast-x/modules/admin-studio/assets/css/admin-tweaks.css)
- Compare against `865ebd8` inline CSS to find layout-breaking differences
- Add proper scoping so styles don't leak into dashboard/media/footer

#### [MODIFY] [admin-tweaks.js](file:///c:/Users/bodma/Local%20Sites/ofast-x-dev/app/public/wp-content/plugins/ofast-x/modules/admin-studio/assets/js/admin-tweaks.js)
- Verify no global DOM manipulation that could affect other pages

#### [MODIFY] [class-ofast-whos-admin.php](file:///c:/Users/bodma/Local%20Sites/ofast-x-dev/app/public/wp-content/plugins/ofast-x/modules/admin-studio/class-ofast-whos-admin.php)
- Check inline CSS for checkbox overrides, layout rules that may leak to media/footer

#### [INVESTIGATE] Email admin CSS
- Check [email-admin.css](file:///c:/Users/bodma/Local%20Sites/ofast-x-dev/app/public/wp-content/plugins/ofast-x/modules/email/assets/css/email-admin.css) for footer positioning conflicts

---

## Phase 2: Fix Emailer User List (Bug 4)

### Bug 4a: Remove 500-User Cap

**Root Cause:** Hard-coded `get_users(['number' => 500])` at [class-email-tab-send.php:656](file:///c:/Users/bodma/Local%20Sites/ofast-x-dev/app/public/wp-content/plugins/ofast-x/modules/email/tabs/class-email-tab-send.php#L655-L656).

**Fix:** Remove the `number` parameter entirely so `get_users()` returns ALL users. The existing client-side pagination (default 20 per page with selectable 10/20/50/100/All) handles the UI.

#### [MODIFY] [class-email-tab-send.php](file:///c:/Users/bodma/Local%20Sites/ofast-x-dev/app/public/wp-content/plugins/ofast-x/modules/email/tabs/class-email-tab-send.php)
- Line 656: Change `get_users(['number' => 500, ...])` → `get_users(['orderby' => 'ID', 'order' => 'ASC'])`
- Remove the comment about "Cap at 500"

### Bug 4b: Role(s) Column Not Wrapping on Live Server

**Root Cause:** The roles column renders as a comma-separated string in a `<td>`. On live servers with more complex role names, the text doesn't wrap because there's no `word-wrap` or `max-width` on that column.

**Fix:**
#### [MODIFY] [class-email-tab-send.php](file:///c:/Users/bodma/Local%20Sites/ofast-x-dev/app/public/wp-content/plugins/ofast-x/modules/email/tabs/class-email-tab-send.php)
- Add `style="max-width: 180px; word-wrap: break-word; white-space: normal;"` to the Role(s) `<td>` cell

#### [MODIFY] [email-admin.css](file:///c:/Users/bodma/Local%20Sites/ofast-x-dev/app/public/wp-content/plugins/ofast-x/modules/email/assets/css/email-admin.css)
- Add a CSS rule for the user table role column to wrap properly

---

## Phase 3: Move Throttle Settings to Emailer (Bug 5)

### Current State
- **SMTP module** has the Bulk Email Throttle UI (batch size, delay inputs) and saves `ofast_email_batch_size` to `wp_options`
- **Email Processor** (`class-ofast-email-processor.php`) **reads** `ofast_email_batch_size` and `ofast_email_batch_delay` from `wp_options` to control queue batch sizes — **so the throttle IS functional, not just UI**
- The 3-second default delay (`DEFAULT_RAPID_DELAY = 3`) is the pause between loopback batches to avoid SMTP burst detection

### Decision: Move to Emailer
The throttle controls email sending behavior, so it belongs in the Emailer module. The SMTP module should only handle SMTP connection/transport settings.

#### [MODIFY] [class-ofast-smtp-admin.php](file:///c:/Users/bodma/Local%20Sites/ofast-x-dev/app/public/wp-content/plugins/ofast-x/modules/smtp/class-ofast-smtp-admin.php)
- Remove the "Bulk Email Throttle" section HTML and its save handler
- Keep SMTP-only settings (host, port, auth, test email, health report)

#### [MODIFY] Emailer settings
- Add a "Queue & Throttle Settings" section to the emailer (e.g., in the Templates/Settings tab)
- Show: Emails Per Batch, Batch Delay (seconds), Queue Threshold
- These control the processor directly — making debugging straightforward since settings and behavior are in the same module

---

## Phase 4: Reorganize White Label Module Files

### Current Architecture (messy)

```
modules/admin-studio/           ← Admin Tweaks module
  ├── class-ofast-admin-tweaks.php
  ├── class-ofast-whos-admin.php     ← White Label (loaded from here but is a separate module!)
  ├── class-ofast-admin-url.php      ← Admin URL (White Label feature)
  ├── class-ofast-user-roles.php     ← User Roles (Admin Tweaks sub-module)
  ├── class-ofast-menu-editor.php    ← Menu Editor (White Label feature)
  └── ...

modules/admin-design/           ← Admin Design module
  ├── class-ofast-admin-design.php
  └── custom-dashboard/          ← Custom Dashboard (loaded by White Label!)
      └── class-ofast-custom-dashboard.php

modules/admin-footer/           ← Admin Footer (loaded by White Label!)
  └── class-ofast-admin-footer.php
```

### Proposed Architecture (clean)

```
modules/white-label/                 ← All White Label features in one place
  ├── class-ofast-whos-admin.php          ← Main White Label controller
  ├── class-ofast-admin-url.php           ← Admin URL protection
  ├── class-ofast-menu-editor.php         ← Menu Editor
  ├── class-ofast-admin-footer.php        ← Footer customization
  ├── class-ofast-custom-dashboard.php    ← Custom Dashboard
  └── assets/
      ├── css/
      │   ├── menu-editor.css
      │   └── admin-url.css
      └── js/
          └── menu-editor.js

modules/admin-studio/            ← Only Admin Tweaks features
  ├── class-ofast-admin-tweaks.php
  ├── class-ofast-user-roles.php
  ├── class-ofast-content-ordering.php
  └── assets/
      ├── css/admin-tweaks.css, content-ordering.css, ofast-tabs.css
      └── js/admin-tweaks.js, content-ordering.js, ofast-tabs.js

modules/admin-design/            ← Only custom CSS/design
  ├── class-ofast-admin-design.php
  └── assets/
```

> [!IMPORTANT]
> This file relocation requires updating ALL `require_once` paths in `class-ofast-core.php` and any `plugins_url()` / `plugin_dir_url(__FILE__)` calls inside the moved files.

#### Files to Move

| From | To |
|------|----|
| `modules/admin-studio/class-ofast-whos-admin.php` | `modules/white-label/class-ofast-whos-admin.php` |
| `modules/admin-studio/class-ofast-admin-url.php` | `modules/white-label/class-ofast-admin-url.php` |
| `modules/admin-studio/class-ofast-menu-editor.php` | `modules/white-label/class-ofast-menu-editor.php` |
| `modules/admin-studio/assets/css/menu-editor.css` | `modules/white-label/assets/css/menu-editor.css` |
| `modules/admin-studio/assets/css/admin-url.css` | `modules/white-label/assets/css/admin-url.css` |
| `modules/admin-studio/assets/js/menu-editor.js` | `modules/white-label/assets/js/menu-editor.js` |
| `modules/admin-design/custom-dashboard/` (entire folder) | `modules/white-label/custom-dashboard/` |
| `modules/admin-footer/class-ofast-admin-footer.php` | `modules/white-label/class-ofast-admin-footer.php` |

#### [MODIFY] [class-ofast-core.php](file:///c:/Users/bodma/Local%20Sites/ofast-x-dev/app/public/wp-content/plugins/ofast-x/includes/core/class-ofast-core.php)
- Update all `require_once` paths in `load_whos_admin()` to point to `modules/white-label/`
- Update `load_admin_url()` path

#### [MODIFY] All moved PHP files
- Update any `plugin_dir_url(__FILE__)` and `plugins_url()` calls (asset paths will auto-resolve via `__FILE__`)

---

## Execution Order

| Step | Phase | Description |
|------|-------|-------------|
| 1 | Phase 1 | Audit & fix CSS/JS enqueue scoping in admin-tweaks, whos-admin, email |
| 2 | Phase 1 | Compare inline CSS from `865ebd8` vs extracted CSS files, fix mismatches |
| 3 | Phase 1 | Test dashboard, media, footer in browser |
| 4 | Phase 2 | Remove 500-user cap, fix role column wrapping |
| 5 | Phase 3 | Move throttle UI from SMTP to Emailer, verify processor reads settings |
| 6 | Phase 4 | Create `modules/white-label/` folder, move files, update paths |
| 7 | Phase 4 | Test all White Label features load correctly from new location |

---

## Verification Plan

### Browser Testing (after each phase)
- [ ] WordPress admin dashboard renders correctly (no shift, dark background complete)
- [ ] Media Library opens and displays images
- [ ] Admin Studio page — footer stays at bottom
- [ ] Emailer Send page — footer at bottom, user list shows ALL users
- [ ] Emailer user table — roles column wraps on narrow screens
- [ ] SMTP settings — throttle section removed
- [ ] Emailer settings — throttle settings present and functional
- [ ] White Label features (menu editor, admin URL, custom dashboard, footer) all work

### Console/Logs
- [ ] No 404 errors for CSS/JS assets
- [ ] No PHP fatal errors from missing `require_once` paths
