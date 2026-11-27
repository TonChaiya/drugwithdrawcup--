# ระบบเบิกยา CUP สันกำแพง

Facility-based medicine withdrawal, approval, reporting, and document export. The application runs as server-rendered PHP with PDO and MySQL/MariaDB. Laragon is the local development environment and InfinityFree is the production host. Composer manages mPDF, PhpSpreadsheet, and FPDI. The root requirement is PHP 8.2 or newer. Verify the actual installed dependencies against the target PHP and extensions.

## Start here

1. [Workflows and business rules](docs/WORKFLOWS.md) describes what the application does and which permissions apply.
2. [Session and deployment runbook](SESSION-DEPLOYMENT.md) covers configuration, migrations, FileZilla deployment, rollback, and production validation.
3. [Release history](docs/RELEASE-HISTORY.md) identifies the current application source baseline, historical migrations, and validation status.
4. [AGENTS.md](AGENTS.md) is the development guide. [CLAUDE.md](CLAUDE.md) points to the same guidance.

## Architecture

| Surface | Implementation |
| --- | --- |
| Login, signup, logout | [index.php](index.php), [signup.php](signup.php), and [logout.php](logout.php). [Authentication and scope helpers](includes/auth.php) validate the account and apply role and facility rules. [Rate limiting](includes/rate_limit.php) backs login and signup throttling. |
| Home and approval queue | [dashboard.php](dashboard.php) shows scoped totals, search, and work lists. [admin_all_withdrawals.php](admin_all_withdrawals.php) is the read-only manager queue for submitted withdrawals. |
| Withdrawal workflow | [withdraw.php](withdraw.php) shows the new form, [submit_withdrawal.php](submit_withdrawal.php) creates records, and [view_withdrawal.php](view_withdrawal.php) displays and manages existing records. [search_drugs.php](search_drugs.php) provides authenticated active-drug lookup. |
| Administration | [register.php](register.php) manages accounts. [admin_zones.php](admin_zones.php) manages zones, facilities, and assignments. [admin_drug_items.php](admin_drug_items.php) and [its action handler](includes/admin_drug_actions.php) manage the catalog and imports. [Template downloads](download_drug_template.php) and [import logs](admin_import_logs.php) are superadmin pages. |
| Reports and files | [report/report.php](report/report.php) runs scoped searches. [printpdf/print_withdrawal.php](printpdf/print_withdrawal.php) generates PDF. [export_withdrawal_invc.php](export_withdrawal_invc.php) generates the approved-withdrawal INVC workbook. |
| Shared UI and form behavior | [Nav](includes/nav.php), [footer](includes/footer.php), and [app.css](assets/css/app.css) provide the shell and responsive styling. [app.js](assets/js/app.js) handles shared menus, modals, and Enter protection. [withdrawal.js](assets/js/withdrawal.js) handles the new form’s search, notes, quantities, and confirmation. Existing withdrawal detail controls also use page-local JavaScript. |
| Session and recovery | Private `includes/db.php` initializes PDO and the [session handler](includes/session_store.php). The [session endpoint](withdrawal_session.php), [browser recovery manager](assets/js/withdrawal-session.js), and [receipt helpers](includes/withdrawal_recovery.php) protect in-progress edits. |
| Supporting files | [migrations/](migrations/) contains versioned schema-only migrations. [tool/](tool/) supplies import templates. Runtime logs, local SQL history, database exports, and validation artifacts are outside the release file set. |

## Database relationships

- `users` holds account, role, approval, and activation information. `facilities` is keyed by `host_code` and assigned to `service_zones`. `user_zone_assignments` links admins to zones. Multiple accounts may share a facility.
- `withdrawals` records the owner as `user_id` and the facility as `host_code`. `withdrawal_items` belongs to a withdrawal and references `drug_item`. Line fields hold requested and delivered quantities, notes, and stock, pack, unit, and working-code snapshots.
- `drug_item` stores the current catalog. `inventory_snapshots` is existing inventory history referenced by catalog deletion safeguards. A withdrawal’s stock field is a reporting snapshot.
- `withdrawal_audit_logs`, `drug_item_audit_logs`, and `zone_audit_logs` record changes in their respective workflows. `security_rate_limits` stores throttling state. `app_sessions` and `withdrawal_save_receipts` are separate operational stores.

For local development, use the approved local schema and disposable fixtures. Use a local-only ignored database config and the dependencies pinned by [composer.lock](composer.lock). The repository is not a standalone fresh database installer. See the [historical migration inventory](docs/RELEASE-HISTORY.md#historical-local-sql) and keep live database exports outside Git.
