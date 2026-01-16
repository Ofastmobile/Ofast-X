import requests
from requests.exceptions import RequestException, Timeout

BASE_URL = "http://localhost:10004/wp-admin/"
SOCIAL_PROVIDERS = ["facebook", "google", "twitter", "linkedin", "github"]
TIMEOUT = 30

def validate_social_login_integration():
    headers = {
        "Content-Type": "application/json",
        "Accept": "application/json",
    }

    # For each social provider, attempt to initiate login and verify expected response flow
    for provider in SOCIAL_PROVIDERS:
        login_url = f"{BASE_URL}ofastx-social-login/{provider}/authenticate"
        try:
            # Step 1: Initiate social login (usually a GET to get the auth URL or redirect)
            response = requests.get(login_url, headers=headers, allow_redirects=False, timeout=TIMEOUT)
            # Social login usually responds with a redirect (302) to the provider's auth page
            assert response.status_code in (200, 302), f"Unexpected status code {response.status_code} for {provider} login initiation"
            if response.status_code == 302:
                location = response.headers.get("Location", "")
                assert location.startswith("https://") or location.startswith("http://"), f"Redirect location invalid for {provider}: {location}"
            else:
                # If 200, expect some JSON or page telling to proceed with login - allow for that
                assert response.headers.get("Content-Type", "").startswith("application/json") or "text/html" in response.headers.get("Content-Type", ""), f"Unexpected content type for {provider} social login response"
        except Timeout:
            assert False, f"Timeout during {provider} social login initiation"
        except RequestException as e:
            assert False, f"Request failed during {provider} social login initiation: {str(e)}"

        # Note: Full social login flow requires browser interaction for OAuth. Here we verify the initial integration
        # endpoint responds correctly to start the auth process.

    print("All social login integrations reachable and starting auth flow as expected.")

validate_social_login_integration()
