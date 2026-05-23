/**
 * Ofast X Social Login - Login Page Script
 * Extracted from class-ofast-social-login.php render_login_buttons()
 * Uses ofastSocial localized object for dynamic data.
 */
(function() {
    var ajaxUrl = ofastSocial.ajaxUrl;
    var errorMsg = ofastSocial.errorMsg;

    document.querySelectorAll('.ofast-social-btn[data-provider]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var provider = btn.getAttribute('data-provider');
            var nonce    = btn.getAttribute('data-nonce');

            btn.classList.add('ofast-social-btn-loading');
            btn.setAttribute('aria-busy', 'true');

            var fd = new FormData();
            fd.append('action',   'ofast_social_get_auth_url');
            fd.append('provider', provider);
            fd.append('nonce',    nonce);
            fd.append('redirect', window.location.href);

            fetch(ajaxUrl, { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success && data.data.url) {
                        window.location.href = data.data.url;
                    } else {
                        alert(data.data.message || errorMsg);
                        btn.classList.remove('ofast-social-btn-loading');
                        btn.removeAttribute('aria-busy');
                    }
                })
                .catch(function() {
                    btn.classList.remove('ofast-social-btn-loading');
                    btn.removeAttribute('aria-busy');
                });
        });
    });
})();
