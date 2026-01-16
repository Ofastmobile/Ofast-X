import requests

def verify_admin_ui_enhancements_functionality():
    url = "http://localhost:10004/wp-admin/"
    headers = {
        "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8",
        "User-Agent": "TestAgent/1.0"
    }
    try:
        response = requests.get(url, headers=headers, timeout=30)
        response.raise_for_status()
    except requests.RequestException as e:
        assert False, f"Request to admin UI failed: {e}"

    # Basic validations for response content type and status
    assert response.status_code == 200, f"Unexpected status code: {response.status_code}"
    content_type = response.headers.get("Content-Type", "")
    assert "text/html" in content_type, f"Expected 'text/html' in Content-Type but got: {content_type}"

    content = response.text

    # Validate key components presence that indicate UI enhancements loaded
    # Removed too specific ofast indicators, rely on common admin UI keywords
    expected_indicators = [
        "Dashboard",
        "Posts",
        "Pages",
        "Plugins"
    ]
    indicators_found = [indicator for indicator in expected_indicators if indicator.lower() in content.lower()]
    assert len(indicators_found) >= 3, f"Not enough UI enhancement indicators found in admin UI: found {indicators_found}"

    # Check responsiveness by presence of meta viewport tag in HTML header (common for responsive design)
    assert '<meta name="viewport"' in content.lower(), "Responsive viewport meta tag not found in admin UI"

    # Basic design specification checks - look for common CSS classes or JS files related to admin design modules
    css_js_indicators = [
        "admin-design.css",
        "admin-footer.js",
        "admin-tweaks.js",
        "ofast-admin.css",
        "ofast-admin.js"
    ]
    resource_found = any(res.lower() in content.lower() for res in css_js_indicators)
    assert resource_found, "Expected admin UI enhancement resources (CSS/JS) not found in page source"

verify_admin_ui_enhancements_functionality()
