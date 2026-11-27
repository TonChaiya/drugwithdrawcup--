# Development guide

This repository maintains the CUP medicine withdrawal application. Start with [README.md](README.md) for the page and file map, then read [workflow rules](docs/WORKFLOWS.md) for the feature you are touching. For configuration or deployment, use [SESSION-DEPLOYMENT.md](SESSION-DEPLOYMENT.md). Read [release history](docs/RELEASE-HISTORY.md) for the current application source baseline, its verification status, and historical migrations.

## Before changing anything

- Verify the repository root, current branch, HEAD, and tracked/untracked state. When the task sets a baseline or authorizes a push, verify the approved origin fetch/push URLs and the actual remote branch. Stop on a required baseline mismatch. Preserve existing work and stage only explicitly intended paths.
- Separate the deployed application source SHA recorded in release history from later documentation-only HEADs. Compare actual changed paths before assuming a push includes a new application release.
- Keep changes focused. Reuse proven decisions, inspect only the necessary entry points and helpers, and avoid broad refactors and repeated full scans.

## Preserve these contracts

- Keep authenticated role and facility scope checks on the server. Facility visibility uses `host_code`. The outstanding draft constraint uses the creator’s `user_id`. Saving, cancelling, approval, and reopening have distinct permissions and audit behavior.
- Preserve stock reporting semantics, quantity × pack calculations, historical code/pack/unit snapshots, withdrawal numbers and statuses, and existing reports and exports. Check changes against their normal entry points and existing historical records.
- Preserve CSRF, session expiry/rotation, tab-scoped recovery, receipt-based duplicate protection, and transactional checks. The shared Enter guard applies to implicit POST submission. Preserve explicit button activation, textarea input, and search interactions.
- Use disposable local fixtures by default. Production access, deployment, and database migrations each require explicit authorization. Keep local and production configs separate. Keep credentials, live exports, session payloads, personal data, logs, and new generated artifacts out of Git.

## Verification and handoff

Run focused checks for the changed behavior. For documentation-only work, verify paths, factual claims, sensitivity, and the Markdown-only diff. Report actual results and unresolved limitations. Synchronize the authoritative guide and release record when implementation or verified deployment status changes.
