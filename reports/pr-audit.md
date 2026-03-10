# PR Audit (1-14)

Base branch: origin/main

## PR #1: Fix security vulnerability in email logging
- Diff stat: 2 files changed, 166 insertions(+), 5 deletions(-)
- Files changed (2):
  - modules/smtp/class-ofast-smtp-admin.php
  - modules/smtp/class-ofast-smtp.php

## PR #2: Fix Insecure Direct Object Reference in Draft Management
- Diff stat: 1 file changed, 129 insertions(+), 20 deletions(-)
- Files changed (1):
  - modules/email/class-ofast-email-admin.php

## PR #3: Fix insufficient access control for content ordering
- Diff stat: 1 file changed, 5 insertions(+)
- Files changed (1):
  - modules/admin-studio/class-ofast-content-ordering.php

## PR #4: Fix CWE-329 Weak Password Encryption Vulnerability
- Diff stat: 1 file changed, 17 insertions(+), 4 deletions(-)
- Files changed (1):
  - modules/smtp/class-ofast-smtp.php

## PR #5: Fix security vulnerability CWE-532 in SMTP test function
- Diff stat: 1 file changed, 100 insertions(+), 4 deletions(-)
- Files changed (1):
  - modules/smtp/class-ofast-smtp.php

## PR #6: Validate module keys in Setup Wizard to prevent arbitrary data storage
- Diff stat: 1 file changed, 38 insertions(+), 1 deletion(-)
- Files changed (1):
  - includes/core/class-ofast-setup-wizard.php

## PR #7: Require encryption for WhatsApp API credentials
- Diff stat: 1 file changed, 36 insertions(+), 21 deletions(-)
- Files changed (1):
  - modules/whatsapp/class-ofast-whatsapp.php

## PR #8: Fix CWE-269 Insufficient Authorization vulnerability in user role man...
- Diff stat: 1 file changed, 124 insertions(+), 3 deletions(-)
- Files changed (1):
  - modules/admin-studio/class-ofast-user-roles.php

## PR #9: Fix XSS vulnerability in Form Builder Preview
- Diff stat: 1 file changed, 38 insertions(+), 9 deletions(-)
- Files changed (1):
  - modules/forms/class-ofast-forms-builder.php

## PR #10: Refine SMTP log security handling and resend safeguards
- Diff stat: 2 files changed, 190 insertions(+), 17 deletions(-)
- Files changed (2):
  - modules/smtp/class-ofast-smtp-admin.php
  - modules/smtp/class-ofast-smtp.php

## PR #11: Fix CWE-532 Sensitive Data Exposure in Email Logs
- Diff stat: 3 files changed, 203 insertions(+), 49 deletions(-)
- Files changed (3):
  - includes/core/class-ofast-activator.php
  - modules/smtp/class-ofast-smtp-admin.php
  - modules/smtp/class-ofast-smtp.php

## PR #12: Fix SQL injection vulnerability in form submissions
- Diff stat: 1 file changed, 19 insertions(+), 7 deletions(-)
- Files changed (1):
  - modules/forms/class-ofast-forms-submissions.php

## PR #13: Fix weak encryption implementation for sensitive data
- Diff stat: 1 file changed, 11 insertions(+), 5 deletions(-)
- Files changed (1):
  - includes/core/class-ofast-security-hardening.php

## PR #14: Add CSRF protection for admin URL settings
- Diff stat: 1 file changed, 110 insertions(+), 28 deletions(-)
- Files changed (1):
  - modules/admin-studio/class-ofast-admin-tweaks.php

## Overlapping files across PRs (potential merge conflicts)
- modules/smtp/class-ofast-smtp-admin.php: PRs [1, 10, 11]
- modules/smtp/class-ofast-smtp.php: PRs [1, 4, 5, 10, 11]
