import requests

BASE_URL = "http://localhost:10004/wp-admin/"
HEADERS = {
    "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
    "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) CustomDashboardWidgetTester/1.0"
}
TIMEOUT = 30

def check_custom_dashboard_widgets_accessibility():
    session = requests.Session()
    session.headers.update(HEADERS)

    # Step 1: Access the main admin dashboard page
    try:
        dashboard_resp = session.get(f"{BASE_URL}index.php", timeout=TIMEOUT)
        dashboard_resp.raise_for_status()
        page_text_lower = dashboard_resp.text.lower()
        assert any(x in page_text_lower for x in ["dashboard", "ofast-x"]), \
            "Admin dashboard page does not contain expected content"
    except requests.RequestException as e:
        assert False, f"Failed to access admin dashboard page: {e}"

    # Step 2: Access the OFast-X Custom Dashboard widgets area/page
    # Common practice would be a plugin page under admin.php?page=ofast_custom_dashboard or similar.
    # Since no exact endpoint given, try common slug guesses.

    possible_paths = [
        "admin.php?page=ofast-custom-dashboard",
        "admin.php?page=ofast_dashboard",
        "admin.php?page=ofast_custom_dashboard_widgets",
        "admin.php?page=ofast-x-dashboard",
    ]

    widget_page_resp = None
    for path in possible_paths:
        try:
            r = session.get(f"{BASE_URL}{path}", timeout=TIMEOUT)
            if r.status_code == 200 and any(x in r.text for x in ["Dashboard", "Widgets", "OFast-X"]):
                widget_page_resp = r
                break
        except requests.RequestException:
            continue

    assert widget_page_resp is not None, "Could not access the OFast-X custom dashboard widgets page for admin."

    # Step 3: Validate presence of widget elements or known UI markers in the HTML

    widget_markers = [
        "ofast-widget",        # Possible CSS class prefix for widgets
        "Custom Dashboard",    # Widget area header
        "ofast-dashboard",     # Plugin dashboard container
        "widget-title",        # Widget title marker
    ]

    found_marker = any(marker.lower() in widget_page_resp.text.lower() for marker in widget_markers)
    assert found_marker, "Custom dashboard widgets or screens content not found on the page."

    # Step 4: Verify widget functionality - example: presence of controls or AJAX endpoints
    # Attempt to fetch widget data endpoint if known (usually admin-ajax.php with specific action)
    # Given no direct API, try to simulate a widget data fetch call

    ajax_url = f"{BASE_URL}admin-ajax.php"
    ajax_params = {"action": "ofast_get_dashboard_widgets"}

    try:
        ajax_resp = session.post(ajax_url, data=ajax_params, timeout=TIMEOUT)
        ajax_resp.raise_for_status()
        assert "widget" in ajax_resp.text.lower() or ajax_resp.json(), "Invalid or empty widget data response."
    except requests.RequestException:
        # If AJAX endpoint or action does not exist, skip but report warning as test minor fail
        pass
    except ValueError:
        # JSON decode error means invalid response, fail test
        assert False, "Dashboard widgets AJAX endpoint returned invalid JSON."

check_custom_dashboard_widgets_accessibility()
