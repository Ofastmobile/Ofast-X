import requests
import time

BASE_URL = "http://localhost:10004/wp-json/ofast-x/"
HEADERS = {
    "Content-Type": "application/json",
    "Accept": "application/json"
}
TIMEOUT = 30

def test_email_notifications_and_smtp_integration():
    # Step 1: Setup - create a new email template (customizable)
    create_template_url = BASE_URL + "email-templates"
    template_data = {
        "name": "test_transactional_template",
        "subject": "Test Transactional Email",
        "body": "<h1>Hello {{username}}</h1><p>This is a test transactional email.</p>",
        "type": "transactional",
        "is_active": True
    }
    template_id = None
    try:
        resp_create_template = requests.post(create_template_url, json=template_data, headers=HEADERS, timeout=TIMEOUT)
        assert resp_create_template.status_code == 201, f"Failed to create email template: {resp_create_template.text}"
        template_resp_json = resp_create_template.json()
        assert "id" in template_resp_json, "Template creation response lacks 'id'"
        template_id = template_resp_json["id"]

        # Step 2: Setup SMTP configuration
        smtp_url = BASE_URL + "smtp-settings"
        smtp_config = {
            "host": "smtp.testserver.com",
            "port": 587,
            "username": "testuser",
            "password": "testpass",
            "encryption": "tls",
            "from_email": "noreply@testdomain.com",
            "from_name": "Test Sender",
            "is_active": True
        }
        resp_smtp = requests.put(smtp_url, json=smtp_config, headers=HEADERS, timeout=TIMEOUT)
        assert resp_smtp.status_code in (200, 204), f"Failed to configure SMTP settings: {resp_smtp.text}"

        # Step 3: Send transactional email using the newly created template
        send_email_url = BASE_URL + "email/send"
        send_email_payload = {
            "to": "recipient@testdomain.com",
            "template_id": template_id,
            "template_vars": {"username": "RecipientUser"},
            "email_type": "transactional"
        }
        resp_send = requests.post(send_email_url, json=send_email_payload, headers=HEADERS, timeout=TIMEOUT)
        assert resp_send.status_code == 202, f"Failed to send transactional email: {resp_send.text}"
        send_resp_json = resp_send.json()
        assert send_resp_json.get("status") == "queued" or send_resp_json.get("status") == "sent", "Email was not queued or sent"

        # Step 4: Poll email queue/status endpoint to confirm email dispatch (simulate email received)
        email_status_url = BASE_URL + f"email/status/{send_resp_json.get('email_id')}"
        for _ in range(10):
            resp_status = requests.get(email_status_url, headers=HEADERS, timeout=TIMEOUT)
            assert resp_status.status_code == 200, f"Failed to fetch email status: {resp_status.text}"
            status_json = resp_status.json()
            email_status = status_json.get("delivery_status")
            if email_status in ("delivered", "failed"):
                break
            time.sleep(3)
        else:
            assert False, "Email delivery status not updated in expected time"

        assert email_status == "delivered", f"Email not delivered successfully, status: {email_status}"

        # Step 5: Create a notification email template and send a notification email
        notification_template_data = {
            "name": "test_notification_template",
            "subject": "Test Notification Email",
            "body": "<p>Notification: Your action was successful.</p>",
            "type": "notification",
            "is_active": True
        }
        resp_create_notif = requests.post(create_template_url, json=notification_template_data, headers=HEADERS, timeout=TIMEOUT)
        assert resp_create_notif.status_code == 201, f"Failed to create notification template: {resp_create_notif.text}"
        notif_template_id = resp_create_notif.json().get("id")
        assert notif_template_id, "Notification template ID missing"

        send_notif_payload = {
            "to": "notifyrecipient@testdomain.com",
            "template_id": notif_template_id,
            "template_vars": {},
            "email_type": "notification"
        }
        resp_send_notif = requests.post(send_email_url, json=send_notif_payload, headers=HEADERS, timeout=TIMEOUT)
        assert resp_send_notif.status_code == 202, f"Failed to send notification email: {resp_send_notif.text}"
        notif_resp_json = resp_send_notif.json()
        assert notif_resp_json.get("status") in ("queued", "sent"), "Notification email was not queued or sent"

        # Poll notification email status
        notif_status_url = BASE_URL + f"email/status/{notif_resp_json.get('email_id')}"
        for _ in range(10):
            resp_notif_status = requests.get(notif_status_url, headers=HEADERS, timeout=TIMEOUT)
            assert resp_notif_status.status_code == 200, f"Failed to get notification email status: {resp_notif_status.text}"
            notif_status_json = resp_notif_status.json()
            notif_email_status = notif_status_json.get("delivery_status")
            if notif_email_status in ("delivered", "failed"):
                break
            time.sleep(3)
        else:
            assert False, "Notification email delivery status not updated in expected time"

        assert notif_email_status == "delivered", f"Notification email not delivered successfully, status: {notif_email_status}"

    finally:
        # Cleanup: delete created email templates
        if template_id:
            try:
                del_url = create_template_url + f"/{template_id}"
                resp_del = requests.delete(del_url, headers=HEADERS, timeout=TIMEOUT)
                assert resp_del.status_code in (200, 204), f"Failed to delete transactional template: {resp_del.text}"
            except Exception:
                pass
        if 'notif_template_id' in locals() and notif_template_id:
            try:
                del_url_notif = create_template_url + f"/{notif_template_id}"
                resp_del_notif = requests.delete(del_url_notif, headers=HEADERS, timeout=TIMEOUT)
                assert resp_del_notif.status_code in (200, 204), f"Failed to delete notification template: {resp_del_notif.text}"
            except Exception:
                pass

test_email_notifications_and_smtp_integration()