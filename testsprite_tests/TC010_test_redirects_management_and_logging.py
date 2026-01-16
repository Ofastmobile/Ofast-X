import requests
import json

BASE_URL = "http://localhost:10004/wp-admin/wp-json/"
HEADERS = {
    "Content-Type": "application/json",
    "Accept": "application/json",
}

def test_redirects_management_and_logging():
    timeout = 30
    created_redirect_id = None
    imported_id = None

    try:
        # Step 1: Create a new URL redirect
        create_url = BASE_URL + "ofast-redirects/api/redirects"
        payload_create = {
            "source_url": "/old-page",
            "target_url": "/new-page",
            "status_code": 301,
            "enabled": True,
            "description": "Test redirect created by automated test"
        }
        create_response = requests.post(create_url, headers=HEADERS, json=payload_create, timeout=timeout)
        assert create_response.status_code == 201, f"Failed to create redirect: {create_response.text}"
        created_redirect = create_response.json()
        assert "id" in created_redirect, "Created redirect response missing 'id'"
        created_redirect_id = created_redirect["id"]

        # Step 2: Verify the redirect appears in list (import/export representation)
        list_url = BASE_URL + "ofast-redirects/api/redirects"
        list_response = requests.get(list_url, headers=HEADERS, timeout=timeout)
        assert list_response.status_code == 200, f"Failed to list redirects: {list_response.text}"
        redirects_list = list_response.json()
        assert any(r.get("id") == created_redirect_id for r in redirects_list), "Created redirect not found in list"

        # Step 3: Export redirects (simulate export functionality)
        export_url = BASE_URL + "ofast-redirects/api/redirects/export"
        export_response = requests.get(export_url, headers=HEADERS, timeout=timeout)
        assert export_response.status_code == 200, f"Failed to export redirects: {export_response.text}"
        exported_data = export_response.json()
        assert any(r.get("id") == created_redirect_id for r in exported_data), "Created redirect missing in export data"

        # Step 4: Import redirects (simulate import functionality)
        import_url = BASE_URL + "ofast-redirects/api/redirects/import"
        # Import same redirect but with modified description to test import overwrite/addition
        imported_redirect = {
            "source_url": "/old-page-import",
            "target_url": "/new-page-import",
            "status_code": 302,
            "enabled": True,
            "description": "Imported redirect from automated test"
        }
        import_response = requests.post(import_url, headers=HEADERS, json=[imported_redirect], timeout=timeout)
        assert import_response.status_code in (200, 201), f"Failed to import redirects: {import_response.text}"
        imported_import_ids = import_response.json()
        if isinstance(imported_import_ids, list) and len(imported_import_ids) > 0:
            imported_id = imported_import_ids[0]
        else:
            imported_id = None

        # Step 5: Validate import by checking list includes imported redirect
        list_response_after_import = requests.get(list_url, headers=HEADERS, timeout=timeout)
        assert list_response_after_import.status_code == 200, f"Failed to list redirects after import: {list_response_after_import.text}"
        after_import_list = list_response_after_import.json()
        assert any(r.get("source_url") == "/old-page-import" and r.get("target_url") == "/new-page-import" for r in after_import_list), "Imported redirect not found in list"

        # Step 6: Verify logging of redirects (fetch logs)
        logs_url = BASE_URL + "ofast-redirects/api/logs"
        logs_response = requests.get(logs_url, headers=HEADERS, timeout=timeout)
        assert logs_response.status_code == 200, f"Failed to fetch redirect logs: {logs_response.text}"
        logs_data = logs_response.json()
        # There should be at least one log related to creation/import
        found_create_log = any(
            ("created" in str(log.get("message", "")).lower() or "imported" in str(log.get("message", "")).lower())
            and (str(created_redirect_id) in str(log))  # log likely contain redirect id
            for log in logs_data
        )
        assert found_create_log, "No log entry found for redirect creation or import"

    finally:
        # Cleanup: Delete created redirects if any
        if created_redirect_id:
            delete_url = BASE_URL + f"ofast-redirects/api/redirects/{created_redirect_id}"
            try:
                requests.delete(delete_url, headers=HEADERS, timeout=timeout)
            except Exception:
                pass
        # Also attempt to cleanup imported redirect if id known
        if imported_id:
            delete_import_url = BASE_URL + f"ofast-redirects/api/redirects/{imported_id}"
            try:
                requests.delete(delete_import_url, headers=HEADERS, timeout=timeout)
            except Exception:
                pass

test_redirects_management_and_logging()
