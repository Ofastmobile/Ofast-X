/**
 * Ofast X - Turnstile Submit Protection
 *
 * Prevents form submission until the Turnstile challenge is completed.
 * Forked from Simple Cloudflare Turnstile's disable-submit.js with
 * adaptations for Ofast X form selectors and widget classes.
 *
 * Covers:
 * - WordPress login form (#loginform)
 * - WordPress comment form (#commentform)
 * - Contact Form 7 (.wpcf7-form)
 * - WooCommerce login/register forms
 * - Tutor LMS registration forms
 * - Generic forms with .ofast-turnstile-widget
 *
 * @since 2.0.0
 */
(function () {
    'use strict';

    // =========================================================================
    // Global Turnstile Callbacks
    // =========================================================================

    /**
     * Called by Turnstile when challenge is successfully completed.
     * Re-enables all submit buttons that were disabled by this script.
     */
    window.ofastTurnstileSuccess = function () {
        document.querySelectorAll('.ofast-submit-disabled').forEach(function (el) {
            el.style.pointerEvents = 'auto';
            el.style.opacity = '1';
            el.removeAttribute('aria-disabled');
        });
    };

    /**
     * Called by Turnstile when an error occurs (network, timeout, etc).
     * Keeps buttons disabled — the widget will auto-retry per data-retry="auto".
     */
    window.ofastTurnstileError = function () {
        // Intentionally no-op: buttons stay disabled until success callback fires.
        // The widget auto-retries thanks to data-retry="auto".
    };

    // =========================================================================
    // Form-Specific Submit Blocking
    // =========================================================================

    document.addEventListener('DOMContentLoaded', function () {

        // -------------------------------------------------------------------
        // 1. WordPress Login Form
        // -------------------------------------------------------------------
        var loginForm = document.getElementById('loginform');
        if (loginForm && loginForm.querySelector('.cf-turnstile')) {
            var loginSubmit = document.getElementById('wp-submit');
            if (loginSubmit) {
                // Visually disable the submit button
                loginSubmit.style.pointerEvents = 'none';
                loginSubmit.style.opacity = '0.5';
                loginSubmit.setAttribute('aria-disabled', 'true');
                loginSubmit.classList.add('ofast-submit-disabled');
            }

            // Check if the Turnstile response is present
            function isLoginTurnstileComplete() {
                var response = loginForm.querySelector('input[name="cf-turnstile-response"]');
                return response && response.value.length > 0;
            }

            // Block form submit event (Enter key, button click, requestSubmit())
            loginForm.addEventListener('submit', function (e) {
                if (!isLoginTurnstileComplete()) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                }
            }, true); // Capture phase — runs before any other submit handlers

            // Block programmatic form.submit() calls
            var originalSubmit = HTMLFormElement.prototype.submit;
            loginForm.submit = function () {
                if (isLoginTurnstileComplete()) {
                    originalSubmit.call(loginForm);
                }
            };
        }

        // -------------------------------------------------------------------
        // 2. WordPress Comment Form
        // -------------------------------------------------------------------
        var commentForm = document.getElementById('commentform');
        if (commentForm && commentForm.querySelector('.cf-turnstile')) {
            var commentSubmit = commentForm.querySelector('input[type="submit"], button[type="submit"]');
            if (commentSubmit) {
                commentSubmit.style.pointerEvents = 'none';
                commentSubmit.style.opacity = '0.5';
                commentSubmit.setAttribute('aria-disabled', 'true');
                commentSubmit.classList.add('ofast-submit-disabled');
            }

            commentForm.addEventListener('submit', function (e) {
                var response = commentForm.querySelector('input[name="cf-turnstile-response"]');
                if (!response || !response.value.length) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                }
            }, true);
        }

        // -------------------------------------------------------------------
        // 3. Contact Form 7
        // -------------------------------------------------------------------
        document.querySelectorAll('.wpcf7-form').forEach(function (cf7Form) {
            if (!cf7Form.querySelector('.cf-turnstile')) return;

            var cf7Submit = cf7Form.querySelector('.wpcf7-submit');
            if (cf7Submit) {
                cf7Submit.style.pointerEvents = 'none';
                cf7Submit.style.opacity = '0.5';
                cf7Submit.setAttribute('aria-disabled', 'true');
                cf7Submit.classList.add('ofast-submit-disabled');
            }
        });

        // -------------------------------------------------------------------
        // 4. WooCommerce Login Form
        // -------------------------------------------------------------------
        document.querySelectorAll('.woocommerce-form-login').forEach(function (wooForm) {
            if (!wooForm.querySelector('.cf-turnstile')) return;

            var wooSubmit = wooForm.querySelector('.woocommerce-form-login__submit');
            if (wooSubmit) {
                wooSubmit.style.pointerEvents = 'none';
                wooSubmit.style.opacity = '0.5';
                wooSubmit.setAttribute('aria-disabled', 'true');
                wooSubmit.classList.add('ofast-submit-disabled');
            }
        });

        // -------------------------------------------------------------------
        // 5. WooCommerce Register Form
        // -------------------------------------------------------------------
        document.querySelectorAll('.woocommerce-form-register').forEach(function (wooForm) {
            if (!wooForm.querySelector('.cf-turnstile')) return;

            var wooSubmit = wooForm.querySelector('.woocommerce-form-register__submit');
            if (wooSubmit) {
                wooSubmit.style.pointerEvents = 'none';
                wooSubmit.style.opacity = '0.5';
                wooSubmit.setAttribute('aria-disabled', 'true');
                wooSubmit.classList.add('ofast-submit-disabled');
            }
        });

        // -------------------------------------------------------------------
        // 6. Tutor LMS Registration Forms
        // -------------------------------------------------------------------
        document.querySelectorAll('.tutor-form-row, .tutor-registration-form').forEach(function (tutorForm) {
            var form = tutorForm.closest('form');
            if (!form || !form.querySelector('.cf-turnstile')) return;

            var tutorSubmit = form.querySelector('button[type="submit"], input[type="submit"]');
            if (tutorSubmit && !tutorSubmit.classList.contains('ofast-submit-disabled')) {
                tutorSubmit.style.pointerEvents = 'none';
                tutorSubmit.style.opacity = '0.5';
                tutorSubmit.setAttribute('aria-disabled', 'true');
                tutorSubmit.classList.add('ofast-submit-disabled');
            }
        });

        // -------------------------------------------------------------------
        // 7. Generic fallback — any form containing an Ofast Turnstile widget
        //    that wasn't already handled above.
        // -------------------------------------------------------------------
        document.querySelectorAll('.ofast-turnstile-widget').forEach(function (widget) {
            var form = widget.closest('form');
            if (!form) return;

            // Skip if we already handled this form
            if (form.querySelector('.ofast-submit-disabled')) return;

            var submitBtn = form.querySelector('button[type="submit"], input[type="submit"]');
            if (submitBtn) {
                submitBtn.style.pointerEvents = 'none';
                submitBtn.style.opacity = '0.5';
                submitBtn.setAttribute('aria-disabled', 'true');
                submitBtn.classList.add('ofast-submit-disabled');
            }
        });

    }); // DOMContentLoaded

    // =========================================================================
    // CF7 AJAX re-render support
    // =========================================================================
    // After CF7 submits via AJAX and resets the form, the Turnstile widget
    // needs to be re-rendered and the submit button re-disabled.
    document.addEventListener('wpcf7mailsent', function (event) {
        var form = event.target;
        if (!form) return;

        // Re-disable the submit button
        var cf7Submit = form.querySelector('.wpcf7-submit');
        if (cf7Submit) {
            cf7Submit.style.pointerEvents = 'none';
            cf7Submit.style.opacity = '0.5';
            cf7Submit.setAttribute('aria-disabled', 'true');
            cf7Submit.classList.add('ofast-submit-disabled');
        }

        // Re-render the Turnstile widget
        setTimeout(function () {
            if (typeof turnstile === 'undefined') return;
            var widget = form.querySelector('.cf-turnstile');
            if (!widget) return;
            try {
                turnstile.reset(widget);
            } catch (e) {
                try {
                    turnstile.remove(widget);
                    turnstile.render(widget);
                } catch (e2) { /* silent */ }
            }
        }, 500);
    });

})();
