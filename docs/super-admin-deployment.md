> Superseded policy: see [Two-role management deployment](two-role-management-deployment.md) for current permissions, approval/deletion controls, department migration, and explicit legacy-role conversion. The original operational history below is retained for reference.

# Super Admin deployment and recovery

## What changes and what does not

`super_admin` is an additional server-controlled role. `User::isAdmin()` now means administrator capability, covering both `admin` and `super_admin`. `isSuperAdmin()` is an exact role check. Existing admin routes and form requests inherit that capability; client-only routes still require `client`. Super Admin login uses the existing administrator dashboard. No existing user is promoted by deploying the code.

The new migration creates only `privilege_management_locks` and `privilege_audits`. It does not change the users table, passwords, assignments, historical migrations or PDFs. The sentinel row serializes bootstrap and privileged writes; its timestamp is a durable one-time bootstrap marker.

No application migration or real-account bootstrap was executed during implementation. Feature tests use disposable in-memory SQLite and a fail-closed guard in `tests/TestCase.php` before database-refresh traits run. MySQL concurrency has not been exercised. Future MySQL tests require a separately reviewed isolated harness; the current guard deliberately refuses them.

## Review these commands before executing

An authorised operator must first verify the intended environment/database, take tested database and private-storage backups, and review pending migrations with `php artisan migrate:status`. Do not use a database reset, `migrate:fresh`, demo seeding or a blanket promotion. Review production HTTPS, debug/cookie settings and the Phase 9A findings before release.

The exact proposed migration commands, **not run by the implementation**, are:

```bash
php artisan migrate --path=database/migrations/2026_11_02_000001_create_privilege_management_tables.php --pretend
php artisan migrate --path=database/migrations/2026_11_02_000001_create_privilege_management_tables.php --force
```

The first previews SQL; the second applies only this additive migration. Review the preview before the second command. Existing rows are preserved. Stop if either new table already exists unexpectedly, the configured database is not the intended deployment, or another operator is changing privileged accounts. Ensure the database uses transactional InnoDB tables. Do not remove the singleton or roll this migration back after bootstrap: doing so destroys audit/one-time state.

Identify the intended existing administrator by both its immutable account ID and exact stored email using a trusted administrative review. Require an active `admin`, not a client, unknown account, inactive administrator or pre-existing Super Admin. Supply the operator's accountable identity, not a password or secret. Example with placeholders that must be replaced deliberately:

```bash
php artisan glos:bootstrap-super-admin ACCOUNT_ID --email='EXACT_STORED_EMAIL' --operator='OPERATOR_ID'
```

Run it in an interactive terminal. It asks for a reason and explicit yes/no confirmation (default No) for the specified ID. Never include credentials in the reason/operator identifier. The command never asks for or displays an account password, never changes credentials, refuses non-interactive execution, and refuses a mismatched ID/email. Any existing Super Admin, including an inactive one, or a used bootstrap marker blocks bootstrap. The database lock and transaction serialize concurrent attempts. There is no force/reset/bypass option.

Confirm success in the existing administrator dashboard and **Super Admin management** navigation, then review the `bootstrap` audit event. Establish a second independently controlled, active Super Admin through the protected web flow. Do not depend on a single operator/account for recovery.

The terminal operator boundary is trusted host access, not an application login: `--operator` is an accountable label, not independently verified identity. Restrict shell/database access and preserve deployment logs externally.

## Privileged web operations

Routes:

- `GET /super-admin/administrators` — administrator/Super Admin listing and 25 latest audit entries.
- `GET /super-admin/administrators/{administrator}/change` — explicit review form.
- `POST /super-admin/administrators/{administrator}/change` — promote, demote, activate or deactivate.

Only active Super Admins may use these routes. Every write requires the actor's current password, a confirmation checkbox, the exact target email, a reason, and a valid CSRF request. A previous password-confirmation timestamp is deliberately insufficient: reauthentication is required for each action. Writes are limited to five requests per minute per authenticated actor by Laravel's throttle middleware.

The service locks the singleton, reloads/locks and reauthorises the actor, checks their fresh password hash, locks the target and rechecks its confirmed identity, validates the transition, then writes the user and audit atomically. Last-active protection uses a locking read of active Super Admin rows. Both demotion and deactivation are refused when they would remove the last active Super Admin. Inactive Super Admins do not satisfy this requirement. An inactive administrator must be activated before promotion. Client targets are rejected. Self-demotion requires another active Super Admin and retains ordinary administrator access; self-deactivation logs the actor out.

All current application paths that modify elevated roles/status use this service. Client management cannot target administrators or Super Admins. `role` and `is_active` remain excluded from mass assignment. Trusted direct SQL, arbitrary Tinker code, future new writers and database-owner access are outside these application guarantees; they must not bypass the same locking/auditing contract.

Audit entries contain actor ID (or console operator identifier), target ID, action, old/new role and active status, reason and timestamp. Passwords, password hashes, session tokens and PDF contents are not recorded. Audit insertion failure rolls back the privilege change. Entries are append-only through current application APIs, **not tamper-proof against a database administrator**. Retain/export audit events to an independently controlled log store and restrict database privileges where appropriate. Failed attempts are rejected but do not yet have their own persistent audit stream.

## MFA design — not enforced

MFA is **not implemented, required or tested** by this phase. Password reauthentication is the enforced step-up control. Do not describe these pages as MFA-protected. If company policy requires MFA for elevated role changes, do not enable that workflow in production until the following is implemented and tested:

1. Verified enrolment (prefer WebAuthn/passkeys or a vetted TOTP implementation), secure recovery codes and independently reviewed factor reset.
2. A server-held, short-lived, one-use challenge tied to actor ID, session, target account, proposed action and nonce. A client boolean or reusable `mfa_confirmed=true` session flag is insufficient.
3. Password verification plus successful factor verification before entering the privilege transaction; recheck and consume the action-bound grant atomically with the change so a replay or changed target cannot use it.
4. Factor-attempt throttling, revocation on logout/password/factor change, and tests for expiry, replay, wrong factor, cross-session and cross-target use, concurrent consumption and recovery.
5. A deployment fail-closed gate when MFA policy is enabled. Do not add a policy switch claiming enforcement without the verifier and tests.

## Public registration compatibility (Phase 10A)

`UserRole` remains the granted role and `RequestedAccessRole` is untrusted applicant intent. Phase 10A now implements public requests, verification and Super Admin decisions; see [the registration deployment runbook](registration-deployment.md). Applicants are inactive pending clients, regardless of requested access, until an audited verified approval assigns Admin or Client access server-side. Super Admin can never be requested or granted through registration. Existing null registration status preserves legacy accounts.

The promotion/demotion workflow and its one-time bootstrap marker are unchanged. Registration decisions share its singleton lock and require current-password confirmation, but cannot grant Super Admin. MFA remains designed, not enforced.

## Disaster recovery

1. Prefer another independently controlled active Super Admin to reactivate an inactive elevated account through the password-confirmed, audited status flow. This does not change credentials.
2. For forgotten passwords, use an independently reviewed credential-recovery process. This phase does not add password recovery or reset existing credentials.
3. If no accessible active Super Admin survives, the one-time bootstrap command is intentionally **not** a recovery bypass. Do not clear its timestamp, delete its audit event, demote all Super Admins in SQL or rerun migrations to make bootstrap available.
4. Declare a controlled break-glass incident. Verify operator/account ownership out of band; require two-person approval, protected backups and a maintenance window. Prepare a separately reviewed narrowly targeted recovery transaction/tool that acquires the same singleton lock, reactivates only the explicitly identified existing Super Admin, preserves credentials and records the operator/reason and old/new state atomically. Such a recovery tool is **not implemented** here and must not be improvised through blanket updates. Review no-active-user corruption and audit continuity before reopening access.
5. If restoration is needed, first validate the backup and audit continuity in an isolated environment; never overwrite current company data as a routine recovery step. Document all incident operations and rotate host/database credentials only under the incident response plan.

## Known limits

- MySQL row-lock behaviour and actual simultaneous role changes remain unverified on a dedicated integration database; SQLite tests verify lock intent, invariants and transactional rollback.
- Phase 9A findings unrelated to this role feature, including PDF parsing, bcrypt byte semantics, demo seeding, production configuration and legacy formatting, are not silently fixed by this work.
- The audit UI shows the 25 latest events; the full table is retained. No privilege-audit export or full privilege-history pagination is implemented. Registration approval is now available through Phase 10A.
- No automatic promotion, deployment migration, database reset, administrator credential change or PDF modification is part of this implementation.

## Implementation verification and file manifest

On 2 November 2026, targeted tests passed: **37 tests, 183 assertions**. The full existing-plus-new suite passed: **234 tests, 1,371 assertions**. PHP syntax checks passed for **78** source/configuration/test files. Pint passes on all changed PHP files. Repository-wide Pint still fails on nine pre-existing untouched files: the six 27 October domain migrations and `Vessel`, `CertificateType`, and `CertificateVersion` models. Historical migrations were not reformatted.

Tests cover the existing admin/client login flows, Super Admin login and inherited capabilities, role denials, explicit target/password confirmation, throttling, last-active protection, activation/deactivation, self-demotion and self-deactivation, stale actor rejection, audit rollback, lock ordering, bootstrap confirmation/refusal/one-time behaviour, and existing vessel/certificate/client-assignment regressions. The bootstrap command was exercised **only with temporary in-memory test accounts**, not against an existing deployment account.

Created:

- `app/Enums/UserRole.php`
- `app/Enums/RequestedAccessRole.php`
- `app/Support/ManagePrivileges.php`
- `app/Console/Commands/BootstrapSuperAdmin.php`
- `app/Http/Controllers/SuperAdmin/AdministratorController.php`
- `database/migrations/2026_11_02_000001_create_privilege_management_tables.php`
- `resources/views/super-admin/administrators.blade.php`
- `resources/views/super-admin/change.blade.php`
- `tests/Feature/SuperAdminTest.php`
- `tests/Feature/BootstrapSuperAdminTest.php`
- `docs/super-admin-deployment.md`

Modified:

- `app/Models/User.php`
- `app/Http/Middleware/EnsureUserHasRole.php`
- `routes/web.php`
- `resources/views/layouts/admin.blade.php`
- `tests/TestCase.php`
