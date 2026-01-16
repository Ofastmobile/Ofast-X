import requests

BASE_URL = "http://localhost:10004/wp-admin/"
TIMEOUT = 30

def test_spam_protection_mechanisms():
    """
    Verify that spam protection effectively blocks spam on all targeted forms and comment sections using multiple techniques.
    This test will:
    - Submit a normal form comment (expected success)
    - Submit a spammy form comment with common spam signatures (expected blocked)
    Spam protection techniques to verify implicitly include honeypot field, math captcha, nonce checks, rate limiting, and sanitation.
    """
    session = requests.Session()
    headers = {
        "Content-Type": "application/x-www-form-urlencoded",
        # Assuming authentication cookie/session is already handled or not required for spam test
    }

    # Endpoint for submitting a comment form (simulate typical WordPress comment post)
    comment_endpoint = BASE_URL + "admin-post.php"

    # Step 1: Submit a valid comment (should succeed)
    valid_comment_data = {
        'action': 'submit_comment',
        'comment_post_ID': '1',         # Example post ID
        'author': 'Test User',
        'email': 'testuser@example.com',
        'comment': 'This is a legitimate comment for spam protection testing.',
        # Including expected spam protection fields (honeypot field empty, valid math captcha answer)
        'honeypot': '',
        'math_captcha_answer': '7',      # Simulate correct answer (if applicable)
    }

    valid_response = session.post(comment_endpoint, data=valid_comment_data, headers=headers, timeout=TIMEOUT)

    assert valid_response.status_code in (200, 302), f"Valid comment submission failed with status {valid_response.status_code}."
    # Expect redirect or success page on successful comment post
    # Assuming server returns JSON or HTML with success message or redirection
    assert ("Thank you for your comment" in valid_response.text or valid_response.status_code == 302), \
        "Valid comment was not accepted as expected."

    # Step 2: Submit a spammy comment (should be blocked)
    spam_comment_data = {
        'action': 'submit_comment',
        'comment_post_ID': '1',
        'author': 'SpamBot',
        'email': 'spam@spamdomain.com',
        'comment': "Buy cheap meds fast!!! Visit http://spam.link now!!!",
        # Honeypot field filled (should trigger spam detection)
        'honeypot': 'I am a bot',
        # Wrong math captcha answer (should be blocked)
        'math_captcha_answer': '1000',
    }

    spam_response = session.post(comment_endpoint, data=spam_comment_data, headers=headers, timeout=TIMEOUT)

    # Possible spam blocking responses:
    # - 403 Forbidden or 400 Bad Request
    # - 200 OK but with error message in body
    assert spam_response.status_code in (200, 400, 403), f"Spam comment submission returned unexpected status {spam_response.status_code}."

    spam_blocked = False
    # Check for common spam block indications in the response
    spam_indications = [
        "blocked",
        "spam detected",
        "honeypot",
        "captcha",
        "nonce",
        "error",
        "refused",
        "failed",
    ]
    response_lower = spam_response.text.lower()
    for indication in spam_indications:
        if indication in response_lower:
            spam_blocked = True
            break

    assert spam_blocked, "Spam comment was not blocked as expected by spam protection mechanisms."

    # Optional: Additional test - submit multiple rapid requests to test rate limiting (simplified)
    rapid_spam_data = spam_comment_data.copy()
    rapid_spam_data['honeypot'] = ''

    blocked_count = 0
    for i in range(5):
        r = session.post(comment_endpoint, data=rapid_spam_data, headers=headers, timeout=TIMEOUT)
        if r.status_code in (400, 403) or any(word in r.text.lower() for word in ["rate limit", "blocked", "spam"]):
            blocked_count += 1

    assert blocked_count >= 1, "Rate limiter did not block repeated rapid spam submissions as expected."


test_spam_protection_mechanisms()
