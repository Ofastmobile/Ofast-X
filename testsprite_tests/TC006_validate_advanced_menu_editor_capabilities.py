import requests

BASE_URL = "http://localhost:10004/"
TIMEOUT = 30

# Authentication credentials (assumed admin user for menu editing)
AUTH = ('admin', 'admin_password')  # Replace with valid credentials

HEADERS = {
    'Content-Type': 'application/json',
    'Accept': 'application/json'
}

def test_validate_advanced_menu_editor_capabilities():
    """
    Test advanced menu editor allows deeper menu customization,
    changes are saved and reflected properly.
    """
    session = requests.Session()
    session.auth = AUTH
    session.headers.update(HEADERS)
    # Use REST API endpoint typical for WP plugins
    menu_endpoint = BASE_URL + "wp-json/ofast-menu-editor/v1/menus"
    timeout = TIMEOUT

    # Step 1: Create a new menu with advanced nested structure
    new_menu_data = {
        "name": "Test Advanced Menu",
        "items": [
            {
                "title": "Home",
                "url": "/home",
                "children": [
                    {
                        "title": "Sub Home 1",
                        "url": "/home/sub1"
                    },
                    {
                        "title": "Sub Home 2",
                        "url": "/home/sub2",
                        "children": [
                            {
                                "title": "Sub Sub Home 1",
                                "url": "/home/sub2/subsub1"
                            }
                        ]
                    }
                ]
            },
            {
                "title": "About",
                "url": "/about"
            }
        ]
    }

    created_menu_id = None

    try:
        # Create menu
        create_resp = session.post(menu_endpoint, json=new_menu_data, timeout=timeout)
        assert create_resp.status_code == 201, f"Menu creation failed: {create_resp.text}"
        created_menu = create_resp.json()
        assert 'id' in created_menu, "Created menu response missing 'id'"
        created_menu_id = created_menu['id']

        # Step 2: Retrieve the created menu and verify structure
        get_resp = session.get(f"{menu_endpoint}/{created_menu_id}", timeout=timeout)
        assert get_resp.status_code == 200, f"Menu retrieval failed: {get_resp.text}"
        retrieved_menu = get_resp.json()

        assert retrieved_menu['name'] == new_menu_data['name'], "Menu name mismatch"
        assert 'items' in retrieved_menu, "Menu items missing in retrieved menu"
        # Basic structure check: verify top-level items count
        assert len(retrieved_menu['items']) == len(new_menu_data['items']), "Top-level items count mismatch"

        # Step 3: Update menu - reorder items and change titles
        updated_menu_data = {
            "name": "Test Advanced Menu Updated",
            "items": [
                {
                    "title": "About Updated",
                    "url": "/about"
                },
                {
                    "title": "Home Updated",
                    "url": "/home",
                    "children": [
                        {
                            "title": "Sub Home Updated 1",
                            "url": "/home/sub1"
                        },
                        {
                            "title": "Sub Home Updated 2",
                            "url": "/home/sub2",
                            "children": [
                                {
                                    "title": "Sub Sub Home Updated 1",
                                    "url": "/home/sub2/subsub1"
                                }
                            ]
                        }
                    ]
                }
            ]
        }

        update_resp = session.put(f"{menu_endpoint}/{created_menu_id}", json=updated_menu_data, timeout=timeout)
        assert update_resp.status_code == 200, f"Menu update failed: {update_resp.text}"
        updated_menu_resp = update_resp.json()
        assert updated_menu_resp['name'] == updated_menu_data['name'], "Updated menu name mismatch"

        # Step 4: Retrieve menu again to verify update is persisted
        get_updated_resp = session.get(f"{menu_endpoint}/{created_menu_id}", timeout=timeout)
        assert get_updated_resp.status_code == 200, f"Updated menu retrieval failed: {get_updated_resp.text}"
        retrieved_updated_menu = get_updated_resp.json()

        assert retrieved_updated_menu['name'] == updated_menu_data['name'], "Persisted menu name mismatch after update"
        assert len(retrieved_updated_menu['items']) == len(updated_menu_data['items']), "Persisted top-level items count mismatch after update"
        assert retrieved_updated_menu['items'][0]['title'] == updated_menu_data['items'][0]['title'], "Persisted first item title mismatch"
        assert retrieved_updated_menu['items'][1]['children'][1]['children'][0]['title'] == updated_menu_data['items'][1]['children'][1]['children'][0]['title'], "Persisted nested item title mismatch"

    finally:
        # Cleanup: Delete created menu if exists
        if created_menu_id:
            del_resp = session.delete(f"{menu_endpoint}/{created_menu_id}", timeout=timeout)
            assert del_resp.status_code in (200, 204), f"Menu deletion failed: {del_resp.text}"

test_validate_advanced_menu_editor_capabilities()
