# Senior Review Notes: PRs #1-#14

## Scope and conflict mapping
- PRs touching SMTP overlap heavily: #1, #4, #5, #10, #11 all modify `modules/smtp/class-ofast-smtp.php` and #1/#10/#11 also modify `modules/smtp/class-ofast-smtp-admin.php`.
- Non-SMTP PRs are mostly isolated by module/file.

## Findings by PR
- **PR #1** (SMTP logging security): useful direction, but superseded by PR #10/#11 and conflicts with them.
- **PR #2** (IDOR drafts): adds ownership checks before delete/send/update/load. Good security intent; uses direct `error_log` for audit entries.
- **PR #3** (content ordering ACL): adds `current_user_can('edit_post', $post_id)` guard before reorder update; low risk and aligns with WP capability checks.
- **PR #4** (SMTP encryption IV): improves crypto by prepending random IV and backward-compatible decrypt fallback for old format. Conflicts with other SMTP PRs.
- **PR #5** (SMTP debug leakage): sanitizes SMTP error/debug output. Good for CWE-532 mitigation; conflicts with other SMTP PRs.
- **PR #6** (setup wizard keys): adds module whitelist filtering via `array_intersect_key`, good hardening with low risk.
- **PR #7** (WhatsApp credential encryption requirement): enforces encryption class availability and fails closed (`wp_die`) when unavailable; stronger security but may be operationally disruptive if hardening class is missing.
- **PR #8** (user role authorization): requires `promote_users` and adds privilege-level checks; strong security intent, but introduces custom hierarchy logic that could behave unexpectedly with custom roles.
- **PR #9** (form builder preview XSS): introduces JS escaping helpers and allowed input type list; good front-end XSS mitigation.
- **PR #10** (SMTP log controls refinement): improves POST handling with `wp_unslash`, adds resend guards for missing body, and hook for logging events; strong WP-standard improvements but conflicts with #1/#4/#5/#11.
- **PR #11** (SMTP sensitive log exposure): broad SMTP overhaul including new options and sanitization patterns; overlaps/conflicts with #1/#4/#5/#10, so should not be merged together blindly.
- **PR #12** (forms SQLi): converts dynamic WHERE concatenation to parameterized query with `$wpdb->prepare()` and parameter array; strong fix.
- **PR #13** (core encryption IV): random IV + IV prefix in ciphertext in security hardening core class; crypto improvement, check compatibility with existing encrypted options.
- **PR #14** (admin URL CSRF): adds dedicated nonce and central handler + slug/IP validation; useful, but includes extensive behavior changes and direct `error_log`, so requires focused QA.

## Merge safety recommendation
- **Safe to merge first (low conflict / high value):** #3, #6, #9, #12.
- **Merge with targeted QA:** #2, #7, #8, #13, #14.
- **Do not merge together (pick one SMTP path, then rebase):** #1, #4, #5, #10, #11.

## Suggested SMTP strategy
1. Use **PR #10** as SMTP baseline (better WP-standard hygiene around unslashing + resend guard).
2. Cherry-pick missing crypto fix from **#4** and debug sanitization from **#5** into that branch.
3. Avoid merging #1/#11 directly on top unless manually reconciled; they overlap the same code paths/options.
