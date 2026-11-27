# Application workflows

This guide describes the current code. See the [release history](RELEASE-HISTORY.md) for earlier decisions and the [runbook](../SESSION-DEPLOYMENT.md) for rollout details.

## Accounts and facility scope

- Self-registration collects username, password, name, position, and facility information. It creates a `user` account with `approval_status = pending`. User management reviews requests against the facility directory. Account approval (`pending`, `approved`, `rejected`) and account activation (`is_active`) are separate. A rejected registration can be reviewed again. Login requires an approved, active account.
- `user` sees withdrawals and reports for their own `host_code`. Ordinary draft editing is limited to the creator. Multiple accounts at a facility share visibility, not ownership.
- `admin` works within assigned active zones and their active facilities. Managers can review accounts in scope and manage eligible withdrawals in scope. A zone admin manages regular users in scope.
- `superadmin` can see all facilities, manage accounts and role assignments, maintain zones and facilities, and manage the drug catalog. Sensitive account operations require the acting administrator's password where enforced, and safeguards protect the administrator's own account and the last eligible superadmin. User deletion is restricted where withdrawal history exists. Zone reassignment and facility name changes preserve historical withdrawal facility codes.

Authority is applied in [auth.php](../includes/auth.php), [register.php](../register.php), and [admin_zones.php](../admin_zones.php). Direct requests are subject to the same scope as the UI.

## Drug catalog and import

- Only superadmins maintain drugs. `working_code` is the drug identity for duplicate detection and import matching. Different codes may share a display name. The fiscal year is stored in Buddhist Era notation. For a replacement code set, import new codes and retire old codes separately to preserve history.
- The admin catalog searches by code or name across the loaded catalog before paginating at 30 rows. Status and fiscal-year filters also apply to the list. Bulk status changes can target selected rows, the current page, or all rows matching the filters.
- Active drugs can be selected for new lines. The admin catalog supports single and bulk activation/suspension, and reopening validates the record. Permanent deletion requires a suspended drug with no withdrawal or inventory snapshot references. Status changes, edits, import changes, and deletion are audited.
- Import accepts UTF-8 CSV, XLSX, or XLS. The required headers are `working_code,name,pack_size,unit,type,fiscal_year`. The application checks file format and size (up to 5 MB), row and column limits (5,000 data rows and 20 columns), required fields, positive pack size, and duplicate codes within the file. Use a complete numeric value such as `500` or `10.5` for pack size.
- Preview validates and summarizes the upload before any catalog changes, including existing codes and same-name warnings. Confirmation is bound to an expiring token and uses a transaction. `add_only` skips existing codes. `update_by_code` updates details of matching codes while preserving their activation state. New records use the activation option chosen at preview. The handler writes database audit events and a private import summary when the log directory is available.
- Download the import template through [download_drug_template.php](../download_drug_template.php), including the CSV option. The direct document URL may be blocked by hosting rules. Match the deployed `vendor/` to the release lockfile if CSV works while XLSX preview fails.

## Withdrawals and calculations

- New records are created as `draft` (save) or `submitted` (send). Positive requests require a stock value, including an explicit zero if appropriate. A valid new withdrawal needs at least one requested line. The current stock entry is for reporting. Totals use quantity × pack size, separately for requested and delivered quantities.
- New lines capture the working code, pack size, and unit from the active catalog. Existing snapshots preserve historical units and quantities. The withdrawal and PDF can use current catalog fields for parts of the legacy display when snapshots are missing. INVC requires both the code and pack snapshots.
- The three-month reference is the sum of delivered quantities × pack size in the preceding three completed calendar months for the facility, drawn from submitted and approved withdrawals. It does not drive the requested total.
- The withdrawal number comes from the withdrawal ID and is formatted with an `S` prefix and zero padding. The creation handler attempts to assign it on creation, including draft creation. The submission path assigns it if missing.
- A draft owner or in-scope manager can edit requested quantities, stock, and notes and add or remove eligible lines. Existing draft submission is a separate action from saving edits. Submitted requests lock normal-user editing. Managers can save actual delivery and notes or approve the request. Blank delivery is treated as zero. Negative values and values above requested are rejected server-side. Approval can include zero-delivery lines, including an all-zero approval. Approved views separate delivered and undelivered lines.
- Cancellation uses its own confirmation and the withdrawal’s five-digit facility code. It records `rejected` and leaves the historical record in place. Current permissions and status guards determine who can cancel and from which state. The status menu requires confirmation. Approval uses its dedicated action.

The transition helper defines these allowed edges. Each endpoint still applies its own role and action rules.

| Current status | Allowed next states |
| --- | --- |
| `draft` | `submitted`, `rejected` |
| `submitted` | `draft`, `approved`, `rejected` |
| `approved` | `draft`, `rejected` |
| `rejected` | `draft` |

### Outstanding drafts

[withdrawal_drafts.php](../includes/withdrawal_drafts.php) checks the creator’s `user_id`. An existing `draft` blocks new creation for that account alone, even when another account shares its facility. The new-form GET and creation POST enforce the same rule. The final check is inside the creation transaction after locking the owner’s user row. Manager transitions back to `draft` use that same owner lock before locking the withdrawal.

The [warning dialog](../includes/withdrawal_draft_warning.php) shows **มีใบเบิกฉบับร่างค้างอยู่** and **ยังไม่สามารถสร้างใบเบิกใหม่ได้**. It asks the user to submit or cancel their drafts before creating a new one. It shows timestamps, item counts, and amber status badges in divided rows for drafts the account can access. **จัดการใบเบิกฉบับร่าง** links to the current draft and additional rows link to older drafts. **ปิด** returns to the dashboard. Out-of-scope drafts require administrator help. Cancellation remains in the existing detail workflow. The account can create again after every draft is resolved. Submitted, approved, and rejected records do not block creation.

A blocked or stale submission leaves the browser’s new-form recovery data intact. It is not merged into, or restored over, an existing draft.

## Keyboard and security

[app.js](../assets/js/app.js) delegates Enter handling for single-line inputs in POST forms, including forms and fields added dynamically. Explicit button clicks and keyboard activation, Tab navigation, deliberate programmatic submits, textarea newlines, GET search/filtering, datalist and autocomplete behavior, and IME composition are preserved. [nav.php](../includes/nav.php) loads the shared script for application pages. Login and signup load it directly. All three use the script modification time for cache versioning.

Server-side authorization, CSRF checks, input validation, prepared statements, scoped record access, and transaction locks remain essential. Login uses password hashes and throttling. Sensitive changes are recorded in the corresponding audit tables. Withdrawal audit failures are logged by the helper, which can allow the operation to continue. Keep audit and import diagnostics private.

## Reports and exports

- The dashboard and approval queue are read-only. The queue covers in-scope submitted requests, newest first, with a 200-record limit. [Reports](../report/report.php) support drug name/code lookup, withdrawal number, status, facility, and date range. The drug selector includes active drugs and flags suspended drugs with existing withdrawal history. Selector eligibility checks for any referenced withdrawal item. Report results themselves are restricted by the viewer’s facility scope.
- [PDF](../printpdf/print_withdrawal.php) uses mPDF and retains the original tables and signatures. Every page has a two-line footer with creator, facility, and creation timestamp, then authenticated generator, generation timestamp, and page counter. “Printer” means the account generating the PDF. The header is measured before the first page and the top margin stretches only if long names or facility text require it.
- [INVC XLSX](../export_withdrawal_invc.php) is restricted to approved, in-scope withdrawals. Columns are `HOSP_CODE` (blank), `QTY_DISP` (actual delivered packs × pack snapshot, in individual units), and `WORKING_CODE` (code snapshot). Zero-delivery rows are excluded. No workbook is emitted when nothing was delivered or values required for a safe export are missing or invalid.
