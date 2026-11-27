# Release and migration history

## Current application source baseline

`460411653fd961aeb9895c236d627f454e9d6c12` is the application baseline for this release. Later documentation-only commits do not change the application revision or imply a host deployment.

| Commit | Completed milestone |
| --- | --- |
| `daec051` | MySQL session storage |
| `54cf6b2` | Unsaved withdrawal recovery |
| `c29a2a0` | PDF creator and printer identity |
| `525500d` | Dynamic space for long PDF headers |
| `ea0c8ab` | Outstanding draft protection |
| `f540042` | Shared implicit Enter submission protection |
| `4604116` | Draft warning UI refinement |

### Deployment status and next checks

- **Reported by the operator on 2026-11-27 (Asia/Bangkok):** MySQL named-lock checks passed in phpMyAdmin. Both session-era migrations completed without errors. The production configuration was prepared and uploaded, and the application changes were uploaded to InfinityFree. The operator reported that targeted local tests passed.
- **Pending:** Full production user acceptance testing (UAT). Use the [host validation checklist](../SESSION-DEPLOYMENT.md#validate-on-the-host). The deployment report is not independent verification of live behavior.
- **Known limitations:** Free hosting requires browser-based, same-site validation and restricts direct remote MySQL access. Recovery is tab-scoped with limited retention. INVC requires historical snapshots. Full database setup requires an approved schema source.
- **Future maintenance:** Record production UAT evidence, keep this baseline and the runbook synchronized with releases, and review any historical artifact cleanup separately. No additional feature or schema change is scheduled here.

## Current tracked migrations

Apply in this order on a target that needs the session-era schema. Review existing table definitions before deciding whether a migration is already in place.

1. [20261127_app_sessions.sql](../migrations/20261127_app_sessions.sql) creates the InnoDB session store keyed by a hashed session ID, with an expiry index.
2. [20261127_withdrawal_receipts.sql](../migrations/20261127_withdrawal_receipts.sql) creates the InnoDB save-receipt store for transaction-backed duplicate protection and reconciliation, with an expiry index.

The two migrations create standalone tables. They do not modify business tables. The operator reports both already applied to production. Draft protection, Enter protection, the warning UI, and both PDF changes require no additional migration.

## Historical local SQL

These notes summarize the older SQL artifacts in the local `databaseSQL/` tree. That tree is not part of the tracked release. Consult an approved copy and the target schema if maintaining an older installation. Some scripts change existing data or structure and are not safe to rerun blindly.

| Historical file | Purpose and dependency |
| --- | --- |
| `databaseSQL/old/20260909_add_user_approval.sql` | Introduces account review fields. Existing users were marked approved and new signups start pending. |
| `databaseSQL/old/20260909_add_user_position.sql` | Adds the user position field. |
| `databaseSQL/old/20260909_harden_drug_items.sql` | Adds catalog status and auditing, handles legacy incomplete entries and a code BOM, and protects historical references from cascading deletion. |
| `databaseSQL/old/20260911_add_drug_fiscal_year.sql` | Follows catalog hardening. Assigns the old catalog to fiscal year 2569 and supports new code sets without treating duplicate display names as duplicate identities. |
| `databaseSQL/old/20260911_add_withdrawal_working_code_snapshot.sql` | Adds the historical working-code snapshot with a backfill for existing lines. Back up before applying. |
| `databaseSQL/20260911_add_service_zone_roles.sql` | Adds three-level access and zone/facility scope. The original rollout seeds facility data and a legacy zone, assigns existing admins to it, and promotes one existing admin only if no superadmin exists. The superadmin then configures real zones. |
| `databaseSQL/20260911_add_withdrawal_security_audit.sql` | Adds the withdrawal audit table. |
| `databaseSQL/20260911_add_security_rate_limits.sql` | Adds persistent attempt throttling. |

Historical deployment troubleshooting also established that a stale PhpSpreadsheet `vendor/` package on the host can break Excel preview even when CSV and the same workbook on Laragon work. Keep deployed dependencies aligned with the checked-in lockfile.
