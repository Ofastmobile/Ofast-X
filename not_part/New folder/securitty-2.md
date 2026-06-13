=====================================
Pass 1 — Attacker enumeration (the most important one you are missing)
=====================================

You are a penetration tester. For this WordPress plugin, enumerate every 
attack vector for each of these roles separately:

1. Unauthenticated visitor
2. Subscriber-level logged-in user
3. Editor-level user
4. Administrator (but not a malicious developer — someone who 
   might click a crafted link or be socially engineered)
5. Someone with direct read access to the database but no 
   WordPress credentials

For each vector: state the entry point, the exact file and line, 
the worst-case impact, and a one-paragraph proof of concept. 
Do not skip any file. Do not group findings — one finding per entry.

=====================================
Pass 2 — WordPress coding standards compliance
=====================================
Review this plugin strictly against the WordPress Plugin Security 
Coding Standards. Check every instance of:

- $_POST, $_GET, $_REQUEST, $_SERVER reads — is wp_unslash applied 
  before sanitization? Is the right sanitization function used?
- Every database query — is $wpdb->prepare() used? Are table names 
  hardcoded or variable?
- Every output to the browser — is the right esc_* function used 
  for the context (esc_html, esc_attr, esc_url, esc_js, wp_kses)?
- Every form — does it have a nonce? Is the nonce verified with 
  check_admin_referer or check_ajax_referer before any action?
- Every privileged operation — is current_user_can() called first?

Report every violation even if the practical exploit risk is low. 
The goal is zero PHPCS WordPress-Security warnings.

==========================================
Pass 3 — Session fixation vulnerability in account linking & OAuth flows
==========================================
Verify that every endpoint that handles an OAuth callback (Google, 
Facebook, Apple) or account linking operation:

- Generates a fresh state value per request (or uses a proper short-lived 
  nonce)
- Invalidates the state once used
- Associates the state with the specific provider flow
- Does not reuse session IDs or any shared identifier across steps
- Performs proper CSRF protection for any POST/AJAX action

If you can identify a single request where the same state value can be 
submitted twice with different results, report it with a full attack 
scenario.

==========================================
Pass 4 — Weak nonce implementation
==========================================
Your nonce generation uses wp_generate_password(32, false):
	•	Length 32 is too short for a cryptographically secure nonce
	•	It should be at least 44 hex chars (22 bytes) or 64 hex chars (32 bytes)
	•	No TTL is set — same nonce can be reused indefinitely
	•	No randomness tied to user/session

Report every endpoint that uses a nonce smaller than 44 bytes or reuses a 
once across requests.

=====================================
Pass 3 — Secret and credential handling
=====================================
Focus only on how this plugin stores, retrieves, and transmits 
secrets, API keys, and user credentials. For each secret:

1. Where is it stored? (wp_options, user_meta, transient, constant)
2. Is it encrypted at rest? What algorithm?
3. Where is it decrypted? Is the decrypted value ever printed, 
   logged, or placed in a DOM attribute?
4. Is it ever transmitted? Over HTTP or HTTPS? In a header or 
   URL query string?
5. What happens if encryption fails — does the plugin fail open 
   or closed?


   ============================
   Pass 4 — The one you already used, but tightened
   ============================

   "Review all credential and secret storage, encryption, transmission, and
     error-handling paths. For each secret (OAuth client secrets, API keys,
     tokens):

	 •	Where is it stored? (wp_options, user_meta, transient, constant)
	 •	Is it encrypted at rest? What algorithm?
	 •	Where is it decrypted? Is the decrypted value ever printed,
	 logged, or placed in a DOM attribute?
	 •	Is it ever transmitted? Over HTTP or HTTPS? In a header or
	 URL query string?
	 •	What happens if encryption fails — does the plugin fail open
	or closed?



    Review these modules as a security engineer focused on HTTP-layer 
security posture. For every response the plugin can generate 
(login page, admin settings pages, AJAX responses, redirects), 
state exactly which security headers are present, which are absent, 
and why each absent header matters for this specific context. 
Then provide the exact PHP code to add each missing header at the 
correct WordPress hook.