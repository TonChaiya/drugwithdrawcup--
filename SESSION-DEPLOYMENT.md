# Session and withdrawal recovery rollout

This is the operational runbook. See [release history](docs/RELEASE-HISTORY.md) for the application baseline, the historical SQL inventory, and the operator-reported deployment. Deployment completion and production UAT are tracked separately.

## Configuration and hosting

- Laragon uses a private localhost configuration. The root config at `includes/db.php` is excluded by Git. Maintain the InfinityFree config separately. If a private working copy is named `includes/db-production.php`, exclude it locally and verify with `git check-ignore` before staging. Upload the production copy only as `includes/db.php`.
- Preserve the production DSN, credentials, `BASE_URL`, timezone, security headers, and helper functions. Never upload the Laragon config, developer backups, SQL exports, test artifacts, or runtime import logs. Some older historical artifacts are tracked, so assemble the upload file list explicitly.
- InfinityFree Free Hosting allows its MySQL database to be used by PHP in the hosting account or through its phpMyAdmin. Laragon and external DB clients cannot use direct Remote MySQL on this plan. [InfinityFree MySQL documentation](https://forum.infinityfree.com/t/connecting-to-mysql-from-an-external-application/49339)
- The hosting challenge requires browser JavaScript and cookies. Authenticated same-origin AJAX is supported after browser verification. Cross-origin AJAX and automated HTTP clients can encounter a challenge response, 403, or HTML in place of JSON. Use the website’s browser Network tab to diagnose this. Treat unexpected HTML from the session endpoint as a failed check, never as a successful save. [InfinityFree browser security documentation](https://forum.infinityfree.com/t/browser-security-system-features-and-limitations/49353)

## Deployment order on InfinityFree

1. Back up the current release and the live `includes/db.php` to a private location. Preserve the live DSN, credentials, `BASE_URL`, security headers, timezone, and helper functions.
2. On an authorized target where the tables have not been installed, run [20261127_app_sessions.sql](migrations/20261127_app_sessions.sql) followed by [20261127_withdrawal_receipts.sql](migrations/20261127_withdrawal_receipts.sql) in phpMyAdmin. Both create standalone InnoDB tables without modifying existing business tables. Verify their schema and that the application database user can select, insert, update, and delete in them.
3. From the same database connection, verify that `SELECT GET_LOCK('drugwithdraw_session_probe', 0)` and then `SELECT RELEASE_LOCK('drugwithdraw_session_probe')` each return 1. Verify PDO MySQL, `session.auto_start=0`, and the requirements of the release's Composer lockfile and generated platform check. The project declares PHP 8.2+. Do this before switching handlers.
4. Have users save or close active forms before rollout. In FileZilla, use the saved InfinityFree connection and verify the site's document root (`htdocs`, or the site's own `htdocs` for a multi-site account). Upload the approved PHP, CSS, JavaScript, and required assets together during the maintenance window. Upload `includes/session_store.php` before switching the bootstrap. Keep the entire deployed `vendor/` compatible with [composer.lock](composer.lock). Verify the FileZilla transfer queue completed. [InfinityFree FileZilla instructions](https://forum.infinityfree.com/t/how-to-upload-files-with-ftp/49306)
5. Apply the private bootstrap edit below to the existing production config or upload the prepared production config under the live filename `includes/db.php` once the new code and tables are in place. Old file-backed sessions require users to log in again. Run the host validation checklist and record evidence separately.

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

## Session reliability

[AppSessionStore](includes/session_store.php) uses `app_sessions` with hashed IDs, payloads, and expiry. The handler holds a database named lock per session through validation, read, and write, with a five-second lock acquisition limit. Expired or deleted rows cannot be recreated by a stale writer. PHP strict mode and cookie-only session IDs remain enabled. Cookies use HttpOnly, SameSite=Lax, and Secure on HTTPS. Login rotates the session ID, logout destroys the stored session, and protected requests revalidate account approval, activation, and role against the database. Facility and zone checks still apply at the endpoint.

The idle limit is **7,200 seconds** (2 hours) and the absolute limit is **43,200 seconds** (12 hours). The browser manager sends activity keep-alives at most once every **120 seconds** during real interaction in a visible form, with a best-effort cross-tab throttle. Save preflight is separate. The absolute limit still applies. The session handler and receipt endpoint perform opportunistic, bounded cleanup of expired rows.

## Withdrawal recovery and save receipts

[withdrawal-session.js](assets/js/withdrawal-session.js) keeps temporary form changes in tab-scoped `sessionStorage` with 12-hour retention. The scope includes account, facility context, and either the withdrawal ID or the new form. It keeps blank and zero distinct, preserves notes and pending drug rows, and refreshes recoverable active-drug details from the server. Do not expect recovery from another tab, account, facility, or browser profile.

[withdrawal_session.php](withdrawal_session.php) handles authenticated, same-origin, CSRF-protected preflight, keep-alive, restore, and receipt checks. When login expires, the browser keeps the edits and offers a return to an allowed page. Reauthenticate as the same account, review recovery, and explicitly confirm before saving again with the new page’s CSRF token. The browser does not silently replay a POST.

[withdrawal_recovery.php](includes/withdrawal_recovery.php) checks the existing form’s version to avoid overwriting concurrent changes and records a unique receipt in the same transaction as the business write. A retry with the same operation token cannot create a second committed save. Network interruption or unexpected content does not prove failure or success. The browser checks `withdrawal_save_receipts` and removes saved values after confirmation, while failed or blocked attempts preserve the snapshot. A draft that blocks a new form is handled through the [outstanding draft workflow](docs/WORKFLOWS.md#outstanding-drafts), without merging the two forms.

## Validate on the host

- Log in, open the app in two tabs, and confirm that logout invalidates both. Verify Secure, HttpOnly, and SameSite=Lax on HTTPS.
- Enter a test withdrawal with stock, quantity, and a note. Observe the activity request in DevTools at roughly 120-second intervals only while interacting with the visible form. Check that hiding the tab stops it.
- Expire the test account's session or log it out in another tab. Try saving, sign in again with the same account, restore the changes, and save explicitly. Test both a new withdrawal and an existing draft. A second account must never receive a restore prompt for those values.
- Disconnect during save, reconnect, and revisit the form in the same tab. If MySQL committed the save, confirm the receipt clears recovery without a duplicate. If it did not, confirm recovery stays available.
- Verify the outstanding draft warning and resolve the last draft before creating another. Verify the same facility's other account can still create. Confirm Enter in a cancellation field does not cancel and explicit activation still works.
- Generate a multi-page PDF as a different authorized account and inspect the first, middle, and last footers.

## Rollback

Restore the previous tracked release and the backed-up **host** `includes/db.php` together. Users may need to log in again. Leave the two new tables in place while diagnosing. Restore neither table into a business table. Existing withdrawals need no data rollback.
