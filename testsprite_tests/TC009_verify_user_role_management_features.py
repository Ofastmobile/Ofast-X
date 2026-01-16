import requests

BASE_ENDPOINT = "http://localhost:10004/wp-json/"
HEADERS = {
    "Content-Type": "application/json",
    "Accept": "application/json"
}
TIMEOUT = 30

# Assuming WordPress nonce or login cookie is required for authentication.
# For this example, assume basic auth or a cookie token.
# Replace 'admin' and 'password' with actual credentials or token management.
AUTH = ('admin', 'password')


def verify_user_role_management_features():
    import json

    role_name = "test_role_ofastx"
    capability_name = "edit_ofastx_content"
    user_id = None  # Will create and assign role to user if possible

    def create_role():
        url = BASE_ENDPOINT + "ofastx-user-roles/v1/roles"
        payload = {
            "role": role_name,
            "capabilities": {capability_name: True}
        }
        response = requests.post(url, json=payload, headers=HEADERS, auth=AUTH, timeout=TIMEOUT)
        assert response.status_code == 201, f"Failed to create role: {response.text}"
        resp_json = response.json()
        assert resp_json.get("role") == role_name
        assert capability_name in resp_json.get("capabilities", {})
        return resp_json

    def update_role_capabilities():
        url = BASE_ENDPOINT + f"ofastx-user-roles/v1/roles/{role_name}"
        updated_caps = {
            capability_name: False,
            "manage_options": True
        }
        payload = {
            "capabilities": updated_caps
        }
        response = requests.put(url, json=payload, headers=HEADERS, auth=AUTH, timeout=TIMEOUT)
        assert response.status_code == 200, f"Failed to update role: {response.text}"
        resp_json = response.json()
        caps = resp_json.get("capabilities", {})
        assert caps.get(capability_name) is False
        assert caps.get("manage_options") is True
        return resp_json

    def assign_role_to_user(uid):
        url = BASE_ENDPOINT + f"ofastx-user-roles/v1/users/{uid}/roles"
        payload = {
            "role": role_name
        }
        response = requests.post(url, json=payload, headers=HEADERS, auth=AUTH, timeout=TIMEOUT)
        assert response.status_code == 200, f"Failed to assign role to user: {response.text}"
        resp_json = response.json()
        assert role_name in resp_json.get("roles", []), "Role not assigned properly"
        return resp_json

    def create_test_user():
        url = BASE_ENDPOINT + "ofastx-user-roles/v1/users"
        user_payload = {
            "username": "test_ofastx_user",
            "email": "test_ofastx_user@example.com",
            "password": "StrongPassword!2026"
        }
        response = requests.post(url, json=user_payload, headers=HEADERS, auth=AUTH, timeout=TIMEOUT)
        assert response.status_code == 201, f"Failed to create user: {response.text}"
        resp_json = response.json()
        return resp_json.get("id")

    def delete_role():
        url = BASE_ENDPOINT + f"ofastx-user-roles/v1/roles/{role_name}"
        response = requests.delete(url, headers=HEADERS, auth=AUTH, timeout=TIMEOUT)
        assert response.status_code in (200, 204), f"Failed to delete role: {response.text}"

    def delete_user(uid):
        url = BASE_ENDPOINT + f"ofastx-user-roles/v1/users/{uid}"
        response = requests.delete(url, headers=HEADERS, auth=AUTH, timeout=TIMEOUT)
        assert response.status_code in (200, 204), f"Failed to delete user: {response.text}"

    role_created = False
    user_created = False
    uid = None

    try:
        # Create Role with capabilities
        create_role()
        role_created = True

        # Update role capabilities
        update_role_capabilities()

        # Create user to assign role
        uid = create_test_user()
        user_created = True

        # Assign role to user
        assign_role_to_user(uid)

    finally:
        if user_created and uid:
            delete_user(uid)
        if role_created:
            delete_role()


verify_user_role_management_features()
