import requests

BASE_URL = "http://localhost:10004/wp-admin/"
TIMEOUT = 30
HEADERS = {
    "Accept": "application/json",
    "Content-Type": "application/json",
}

def validate_security_hardening_modules():
    """
    Verify that all security modules including honeypot, math captcha, nonce checks,
    rate limiting, sanitizer, and spam detection effectively prevent common threats
    without impacting user experience.
    """

    # Common threat simulation payloads and endpoints for security features testing
    # Since no direct API schema provided in PRD, assume REST endpoints for testing

    # 1. Honeypot test: Submit a form with honeypot field filled (should be blocked)
    honeypot_endpoint = BASE_URL + "security/honeypot-test"
    honeypot_payload = {
        "username": "testuser",
        "email": "testuser@example.com",
        "honeypot_field": "I am a bot"  # This should be empty normally
    }

    # 2. Math Captcha test: Submit form with incorrect answer
    math_captcha_endpoint = BASE_URL + "security/math-captcha-test"
    math_captcha_payload = {
        "username": "testuser",
        "captcha_answer": "wrong_answer"
    }

    # 3. Nonce checks test: Submit request with missing or invalid nonce
    nonce_endpoint = BASE_URL + "security/nonce-test"
    nonce_headers_invalid = HEADERS.copy()
    nonce_headers_invalid["X-WP-Nonce"] = "invalid_nonce"

    # 4. Rate limiting test: Send multiple rapid requests and expect blocking after threshold
    rate_limit_endpoint = BASE_URL + "security/rate-limit-test"
    rate_limit_payload = {"action": "test"}

    # 5. Sanitizer test: Submit input with malicious scripts and check if sanitized
    sanitizer_endpoint = BASE_URL + "security/sanitizer-test"
    sanitizer_payload = {
        "comment": "<script>alert('xss')</script>This is safe text"
    }

    # 6. Spam detection test: Submit spam-like content and expect rejection
    spam_detection_endpoint = BASE_URL + "security/spam-detection-test"
    spam_detection_payload = {
        "comment": "Buy cheap meds at spammy-website.com now!!!"
    }

    try:
        # Honeypot test - should reject submission
        resp = requests.post(honeypot_endpoint, json=honeypot_payload, headers=HEADERS, timeout=TIMEOUT)
        assert resp.status_code == 403 or (resp.status_code == 400 and "honeypot" in resp.text.lower()), \
            f"Honeypot test failed: Expected blocking status, got {resp.status_code}"

        # Math captcha test - should reject incorrect answer
        resp = requests.post(math_captcha_endpoint, json=math_captcha_payload, headers=HEADERS, timeout=TIMEOUT)
        assert resp.status_code == 403 or (resp.status_code == 400 and "captcha" in resp.text.lower()), \
            f"Math Captcha test failed: Expected blocking status, got {resp.status_code}"

        # Nonce check test - invalid nonce should be rejected
        resp = requests.post(nonce_endpoint, json={}, headers=nonce_headers_invalid, timeout=TIMEOUT)
        assert resp.status_code == 401 or resp.status_code == 403, \
            f"Nonce check failed: Expected 401 or 403, got {resp.status_code}"

        # Rate limiting test - send 10 rapid requests, expect last few to be blocked
        allowed = 0
        blocked = 0
        for i in range(10):
            resp = requests.post(rate_limit_endpoint, json=rate_limit_payload, headers=HEADERS, timeout=TIMEOUT)
            if resp.status_code == 200:
                allowed += 1
            elif resp.status_code == 429:
                blocked += 1
            else:
                assert False, f"Rate limiting unexpected response code {resp.status_code} on attempt {i+1}"
        assert blocked > 0, "Rate limiting test failed: No requests blocked after rapid submissions"

        # Sanitizer test - malicious script input should be sanitized in response
        resp = requests.post(sanitizer_endpoint, json=sanitizer_payload, headers=HEADERS, timeout=TIMEOUT)
        assert resp.status_code == 200, f"Sanitizer test failed: Unexpected status code {resp.status_code}"
        sanitized_content = resp.json().get("sanitized_comment", "")
        assert "<script>" not in sanitized_content.lower(), "Sanitizer test failed: script tag found in sanitized content"

        # Spam detection test - spammy comment should be rejected
        resp = requests.post(spam_detection_endpoint, json=spam_detection_payload, headers=HEADERS, timeout=TIMEOUT)
        assert resp.status_code == 403 or (resp.status_code == 400 and "spam" in resp.text.lower()), \
            f"Spam detection test failed: Expected blocking status, got {resp.status_code}"

        # Additional basic check to assert user experience not impacted: test a clean form submission passes
        clean_payload = {
            "username": "normaluser",
            "email": "normaluser@example.com",
            "honeypot_field": "",
            "captcha_answer": "correct_answer",  # Assume correct for test environment
            "comment": "This is a normal comment."
        }
        resp = requests.post(honeypot_endpoint, json=clean_payload, headers=HEADERS, timeout=TIMEOUT)
        assert resp.status_code == 200, "Clean submission blocked unexpectedly by honeypot"

    except requests.exceptions.RequestException as e:
        assert False, f"Request failed: {e}"

validate_security_hardening_modules()