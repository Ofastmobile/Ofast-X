# Email Queue - Future Module

**Location:** `blueprint/future_modules/email_queue/`

## Archived Files
- `class-ofast-email-queue.php` - Queue system with database storage
- `class-ofast-email-queue-admin.php` - Queue admin UI

## Why Archived
WP-Cron is unreliable (depends on site traffic). Third-party SMTP APIs (SendGrid, Mailgun) have their own built-in queues on their servers.

---

## Current Behavior (v1.0)
- All emails send immediately via `wp_mail()`
- Safe for 50 or fewer recipients
- At 50+ recipients, shows recommendation to use SMTP API

---

## Future Improvement: Batching with Delay

For safer sending of 100+ emails on shared hosting without external queue:

```php
// Send 20 emails at a time with 2-second pause between batches
$batch_size = 20;
$delay_seconds = 2;

$chunks = array_chunk($users, $batch_size);
foreach ($chunks as $i => $batch) {
    foreach ($batch as $user) {
        $message = replace_placeholders($body, $user);
        $html = get_email_template($message);
        wp_mail($user->user_email, $subject, $html, $headers);
    }
    
    // Pause between batches (except after last batch)
    if ($i < count($chunks) - 1) {
        sleep($delay_seconds);
    }
}
```

**Pros:**
- No heartbeat/cron dependency
- Spreads load across time (reduces rate limit hits)
- Works immediately, no background processing

**Cons:**
- Admin waits for entire send (page stays loading)
- Still limited by PHP max_execution_time

**When to implement:**
- If users report timeout issues with 50+ emails
- If shared hosting users complain about rate limiting

---

## Alternative: AJAX Progressive Sending

More advanced approach using AJAX to send batches without page freeze:

1. Admin clicks "Send"
2. JavaScript breaks recipients into batches of 20
3. AJAX sends batch 1, waits for response
4. AJAX sends batch 2, shows progress bar
5. Repeat until done

This keeps page responsive and avoids PHP timeout.
