import requests
import json

BASE_URL = "http://localhost:10004/wp-admin"
TIMEOUT = 30
HEADERS = {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
    # Add authentication headers here if required, e.g.:
    # 'Authorization': 'Bearer <token>'
}

def update_content_order(post_type, ordered_ids):
    url = f"{BASE_URL}/ofast-content-ordering/api/order"
    payload = {
        "type": post_type,
        "order": ordered_ids
    }
    resp = requests.put(url, headers=HEADERS, json=payload, timeout=TIMEOUT)
    resp.raise_for_status()
    return resp.json()


def get_content_order(post_type):
    url = f"{BASE_URL}/ofast-content-ordering/api/order?type={post_type}"
    resp = requests.get(url, headers=HEADERS, timeout=TIMEOUT)
    resp.raise_for_status()
    return resp.json()  # Expected to return current order list of post IDs


def test_verify_content_ordering_functionality():
    post_types = ['post', 'page', 'custom_type']

    # For testing purposes, provide dummy existing IDs
    # Since creation is not possible via the tested API, assume fixed IDs.
    dummy_ids = [101, 102, 103]

    for pt in post_types:
        # Reverse the dummy IDs list
        reversed_order = dummy_ids[::-1]

        update_resp = update_content_order(pt, reversed_order)
        # Validate update response contains success indication
        assert update_resp.get("success") is True or update_resp.get("status") == "ok", f"Failed to update order for type {pt}"

        current_order_resp = get_content_order(pt)
        assert isinstance(current_order_resp, list), f"Expected list order for type {pt}"
        assert current_order_resp == reversed_order, f"Content order mismatch for {pt}. Expected {reversed_order}, got {current_order_resp}"


test_verify_content_ordering_functionality()
