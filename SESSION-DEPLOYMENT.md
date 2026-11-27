# Session and withdrawal recovery rollout

## Deployment order on InfinityFree

1. Back up the current release and the live `includes/db.php` to a private location. Preserve the live DSN, credentials, `BASE_URL`, security headers, timezone, and helper functions.
2. Run `migrations/20261127_app_sessions.sql` and `migrations/20261127_withdrawal_receipts.sql` in the application database. Both create standalone InnoDB tables. Existing business tables and records are untouched. Check that the application database user can select, insert, update, and delete in them.
3. From the same database connection, verify that `SELECT GET_LOCK('drugwithdraw_session_probe', 0)` and then `SELECT RELEASE_LOCK('drugwithdraw_session_probe')` each return 1. Verify PHP 8.2+ with PDO MySQL and `session.auto_start=0`. Do this check on the host before switching session handlers.
4. Upload the tracked code together in a maintenance window. Upload `includes/session_store.php` first. Apply the private bootstrap edit below to the **host's existing** `includes/db.php` during the same rollout. Do not upload a developer's database config or any local test files. Keep the installed Composer dependencies compatible with the deployed lockfile.
5. Existing file-based sessions will require a new login. Have users save or close open withdrawals before the maintenance window.

## Private bootstrap edit

In `includes/db.php`, remove the original session setup block (the one containing `session_set_cookie_params()` and `session_start()`). Keep the security headers and the existing PDO connection code. After PDO initializes successfully and before the CSRF helper functions, insert:

```php
require_once __DIR__ . '/session_store.php';
start_app_session($pdo);
```

In the existing PDO exception handler, before the regular HTML/text response, add:

```php
if (defined('APP_SESSION_JSON')) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    exit('{"status":"unavailable"}');
}
```

The session handler deliberately fails closed if it cannot read or lock the session table. Check the PHP error log and table/lock permissions if login returns 503. Idle and absolute limits are 2 and 12 hours. The session and receipt stores use indexed, bounded cleanup. If the host disables PHP session GC or the tables accumulate expired rows, use bounded maintenance deletes on the two new tables using `expires_at <= UNIX_TIMESTAMP()`.

## Validate on the host

- Log in, open the app in two tabs, and confirm that logout invalidates both. Verify Secure, HttpOnly, and SameSite=Lax on HTTPS.
- Enter a test withdrawal with stock, quantity, and a note. Observe the activity request in DevTools at roughly 120-second intervals only while interacting with the visible form. Check that hiding the tab stops it.
- Expire the test account's session or log it out in another tab. Try saving, sign in again with the same account, restore the changes, and save explicitly. Test both a new withdrawal and an existing draft. A second account must never receive a restore prompt for those values.
- Disconnect during save, reconnect, and revisit the form in the same tab. If MySQL committed the save, confirm the receipt clears recovery without a duplicate. If it did not, confirm recovery stays available.
- Generate a multi-page PDF as a different authorized account and inspect the first, middle, and last footers.

## Rollback

Restore the previous tracked release and the backed-up **host** `includes/db.php` together. Users may need to log in again. Leave the two new tables in place while diagnosing. Restore neither table into a business table. Existing withdrawals need no data rollback.
