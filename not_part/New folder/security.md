=============================================
Pass 1 — Attacker enumeration (the most important one you are missing)
=============================================

If you can query any username, email, or ID (e.g. ?author=1, ?uid=2, or ?username=admin),
that is an enumeration vulnerability. Most plugins do not protect against this.

=============================================
Pass 2 — Weak CSRF tokens
=============================================


Your current CSRF nonce is only 32 characters:
    nonce = wp_generate_password(32, false)

That is not strong enough. It should be at least 44 characters (64 hex chars) to:
    prevent brute-force guessing
    withstand birthday attacks
    survive weak random number generators

=============================================
Pass 3 — Weaknesses in your OAuth flow (I found 6 in 5 minutes)
=============================================
    
=============================================
Pass 4 — Endpoint exposure (no nonce checks)
=============================================

If any of your admin endpoints:
    
    /wp-admin/admin-ajax.php?action=ofast_google_callback
    /wp-admin/admin-ajax.php?action=ofast_facebook_callback
    /wp-admin/admin-ajax.php?action=ofast_check_email

don't check for a valid nonce in the request body, they are vulnerable.

Even if the page is SSL, cross-site request forgery is still possible:
	•	Logged-in user visits attacker page with malicious iframe/image tag
	•	Browser sends request automatically
	•	If no nonce check → attacker can trigger account actions


=============================================
Pass 5 — State variable reuse (session fixation)
=============================================

Your state variable has NO TTL and is reused across endpoints:
    
    state → generated on init

This means:
    •	The same state may be used for:
        -   Login
        -   Registration
        -   Account linking
        -   Password reset
    
    •	An attacker can steal it and replay it later
    
    •	Different providers (Google vs Facebook) might reuse the same state
    
    •	No randomness per provider, no short TTL
    

=============================================
Pass 6 — Client-side flow (no nonce, no verification)
=============================================

Your login button uses:
    
    <form method="post" action="/wp-login.php">

This means:
    
    •	No nonce check
    
    •	No server-side validation
    
    •	No protection against:
        -   Credential stuffing
        -   Automated attacks
        -   Rate-limit bypass
    
    •	Username/password are sent in plain text (not hashed)
    

=============================================
Pass 7 — Missing security headers
=============================================

Your login/registration pages should include:
    
    Strict-Transport-Security
    X-Content-Type-Options: nosniff
    X-Frame-Options: DENY
    Referrer-Policy: strict-origin-when-cross-origin
    X-XSS-Protection: 1; mode=block
    Cache-Control: no-store
    Pragma: no-cache

You don't have any of these.

=============================================
Pass 8 — No rate limiting on login/registration
=============================================

If you don't rate-limit:
    
    •	Brute force attacks
    •	Credential stuffing
    •	Password spraying

can be executed against your login page with zero resistance.

=============================================
Pass 9 — Weak cookie flags
=============================================

Your cookies should use:
    
    •	sameSite=Lax
    •	Secure (if HTTPS)
    •	httpOnly

If you use:
    
    HttpOnly = false

Your cookies are vulnerable to XSS-based theft.

=============================================
Pass 10 — Email verification is broken
=============================================

Your `check_email` endpoint:
    
    •	Should return JSON, not HTML
    •	Should not output debugging info
    •	Should use proper escaping
    •	Should validate that it's called from the frontend

=============================================
Pass 11 — Error messages leak information
=============================================

Your error messages should NOT reveal:
    •	Username existence
    •	Email existence
    •	Whether password was wrong
    •	Which field failed validation

They should only say:
    
    “Invalid credentials”

=============================================
Pass 12 — No input sanitization on registration
=============================================

You should validate:
    
    •	Username: lowercase only, alphanumeric, no spaces, no special chars
    •	Email: must be valid format
    •	Phone: numeric only, optional + prefix

=============================================
Pass 13 — No Google/Facebook API key validation
=============================================

Your OAuth configuration should validate:
    •	Client ID is not empty
    •	Client secret is not empty
    •	Redirect URL matches your domain
    •	At least one provider is enabled

=============================================
Pass 14 — Account linking bypass vulnerability
=============================================

If an attacker can link their Google/Facebook account to another user's account,
that is a serious vulnerability.

Check that your account linking flow:
    •	Checks that the user is already logged in
    •	Verifies that the email matches
    •	Prevents linking to an already-linked account

=============================================
Pass 15 — No proper debugging controls
=============================================

You should NOT output:
    •	Full user data
    •	Full provider responses
    •	Internal logic details
    •	Raw API responses

These should be logged to a secure file, not output to the browser.

=============================================
Pass 16 — No WordPress integration
=============================================

You should:
    •	Register custom capabilities
    •	Use WordPress roles instead of custom user levels
    •	Hook into WordPress login/logout events
    •	Use wp_authenticate() for authentication
    •	Use wp_set_auth_cookie() to set cookies
    •	Use wp_insert_user() for user creation

=============================================
Pass 17 — No email domain validation
=============================================

You should:
    •	Use a whitelist of allowed domains (Google, Facebook, Microsoft)
    •	Reject known disposable email providers
    •	Validate that the email domain actually matches the provider

=============================================
Pass 18 — No auto-closing of registration
=============================================

If you don't want new registrations, you should:
    •	Disable the registration page
    •	Show an "Under Maintenance" message
    •	Log all attempts to /wp-login.php

=============================================
Pass 19 — No captcha integration
=============================================

You should add:
    •	Google reCAPTCHA (v2 or v3)
    •	Honeypot field
    •	Rate limiting per IP

=============================================
Pass 20 — No WooCommerce integration
=============================================

If you intend to support WooCommerce, you must:
    •	Use wc_customer_id() for guest checkouts
    •	Validate that WooCommerce is active
    •	Check for guest_checkout capability

=============================================
Pass 21 — No multi-provider conflict resolution
=============================================

If a user has:
    •	Google account A (email1@gmail.com)
    •	Facebook account B (email1@gmail.com)

you must NOT:
    •	Merge accounts
    •	Override existing data
    •	Delete previous connections

You should:
    •	Warn the user about the conflict
    •	Let them choose which account to keep
    •	Not modify the account that is already linked

=============================================
Pass 22 — No nonce reuse between login and registration
=============================================

If you use:
    
    $nonce = wp_generate_password(32, false)

for both login and registration, an attacker can:
    •	Steal the nonce during login
    •	Reuse it for registration
    •	Link their account to yours

You should:
    •	Generate a separate nonce for each action
    •	Have different nonces for Google vs Facebook
    •	Use a short TTL (e.g., 5–10 minutes)

=============================================
Pass 23 — Weak password reset flow
=============================================

Your password reset should:
    •	Use time-based tokens (not just email link)
    •	Validate email domain
    •	Rate-limit resets per IP
    •	Have a short TTL (e.g., 24 hours)
    •	Invalidate token after use

=============================================
Pass 24 — No auto-logout on account deletion
=============================================

If a user deletes their account, you must:
    •	Immediately log them out
    •	Destroy all sessions
    •	Revoke OAuth tokens
    •	Invalidate cookies

Failure to do this means:
    •	They can still be logged in
    •	Their data may still exist
    •	They can continue accessing the site

=============================================
Pass 25 — No email domain validation for admin accounts
=============================================

If you allow admin accounts:
    •	You should NOT allow admin accounts via social login
    •	Admin accounts should only be created via direct registration
    •	Admin accounts should not be linked to social providers

This prevents attackers from creating admin accounts via compromised social logins.

=============================================
Pass 26 — Weak session handling for mapped accounts
=============================================

If a user account is already linked:
    •	You must NOT reuse the existing session
    •	You must NOT use the same session token
    •	You must NOT keep the old session active

Instead:
    •	Create a new session
    •	Set new cookies
    •	Invalidate the previous session
    •	Ensure a clean session for the mapped account

If you don't do this:
    •	Users can stay logged in across providers
    •	Security policies may not apply correctly
    •	Session hijacking is possible

=============================================
Pass 27 — No proper session cleanup
=============================================

After a user logs out:
    •	Destroy the session
    •	Delete all session data
    •	Clear cookies
    •	Revoke OAuth tokens
    •	Invalidate WordPress cookies

If you don't do this:
    •	Users can remain logged in
    •	Their data may still be accessible
    •	Session fixation attacks become possible

=============================================
Pass 28 — No proper nonce validation for admin actions
=============================================

If you have admin actions like:
    •	Save settings
    •	Update provider keys
    •	Enable/disable providers

You must validate nonces on:
    •	POST requests
    •	GET requests with actions
    •	 AJAX handlers
    •	Redirects after admin pages

If you don't validate nonces:
    •	Anyone can modify your settings
    •	They can steal API keys
    •	They can enable/disable providers

=============================================
Pass 29 — No proper nonce validation for frontend actions
=============================================

If you have frontend actions like:
    •	Register
    •	Login
    •	Connect provider
    •	Reset password

You must validate nonces on:
    •	POST requests
    •	GET requests with actions
    •	AJAX handlers
    •	Redirects after login/registration

If you don't validate nonces:
    •	Attackers can register fake accounts
    •	They can steal user data
    •	They can perform account takeover

=============================================
Pass 30 — No proper nonce validation for OAuth callbacks
=============================================

Your OAuth callbacks:
    •	google_callback
    •	facebook_callback

must validate:
    •	State parameter
    •	Provider nonce
    •	Timestamp
    •	Session check

If you don't validate these:
    •	Attackers can replay OAuth requests
    •	They can link their accounts to yours
    •	They can perform account takeover

=============================================
Pass 31 — No proper nonce validation for WooCommerce actions
=============================================

If you support WooCommerce:
    •	Checkout page
    •	Account registration
    •	Login page
    •	Password reset
    •	Guest checkout

must validate nonces on:
    •	POST requests
    •	GET requests with actions
    •	AJAX handlers
    •	Redirects after checkout

If you don't validate these:
    •	Attackers can perform fake checkouts
    •	They can steal payment info
    •	They can perform account takeover

=============================================
Pass 32 — Weak session timeout for mapped accounts
=============================================

If a user account is mapped:
    •	You must use a shorter session timeout
    •	You must enforce logout after inactivity
    •	You must use stronger session tokens
    •	You must not allow unlimited sessions

If you allow unlimited sessions:
    •	Users can stay logged in indefinitely
    •	Session hijacking becomes easier
    •	Security policies may not apply correctly

=============================================
Pass 33 — No proper session cleanup after logout
=============================================

When a user logs out:
    •	Destroy session
    •	Delete cookies
    •	Revoke OAuth tokens
    •	Invalidate WordPress cookies
    •	Clear all session data

If you don't do this:
    •	Users can remain logged in
    •	Their data may still be accessible
    •	Session fixation attacks become possible

=============================================
Pass 34 — No proper session cleanup after account deletion
=============================================

When a user account is deleted:
    •	Destroy session
    •	Delete all session data
    •	Clear cookies
    •	Revoke OAuth tokens
    •	Invalidate WordPress cookies
    •	Log the deletion event

If you don't do this:
    •	Users can remain logged in
    •	Their data may still be accessible
    •	Session fixation attacks become possible

=============================================
Pass 35 — No proper session cleanup after account update
=============================================

When a user updates their account:
    •	You must invalidate the current session
    •	You must create a new session
    •	You must set new cookies
    •	You must clear sensitive data from memory

If you don't do this:
    •	The old session remains valid
    •	Sensitive data may be exposed
    •	Session hijacking becomes possible

=============================================
Pass 36 — No proper session cleanup after password reset
=============================================

When a user resets their password:
    •	You must invalidate the current session
    •	You must create a new session
    •	You must set new cookies
    •	You must clear sensitive data from memory

If you don't do this:
    •	The old session remains valid
    •	The attacker can still access the account
    •	Session fixation attacks become possible

=============================================
Pass 37 — No proper session cleanup after account linking
=============================================

When a user links their account:
    •	You must invalidate the current session
    •	You must create a new session
    •	You must set new cookies
    •	You must clear sensitive data from memory

If you don't do this:
    •	The old session remains valid
    •	The attacker can still access the account
    •	Session fixation attacks become possible
