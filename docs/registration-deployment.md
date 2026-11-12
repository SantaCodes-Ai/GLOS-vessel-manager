> Superseded policy: see [Two-role management deployment](two-role-management-deployment.md) for current permissions, approval/deletion controls, department migration, and explicit legacy-role conversion. The original operational history below is retained for reference.

# Phase 10A — Registration, verification and approval

## Schema and deployment safety

One additive migration, `2026_11_02_000002_add_registration_approval_workflow.php`, adds nullable `users.requested_role` and `users.registration_status`, and creates `registration_decisions`. Existing users retain null values: no existing role, password, account status, email verification, vessel assignment or PDF is changed. Decision rows preserve applicant/actor IDs, requested role, prior/new effective role, decision, reason and timestamp. A unique applicant ID permits exactly one final decision. Rows are append-only through the application; the database owner can still alter them. Historic IDs deliberately have no cascading foreign keys.

The existing privilege-management migration and singleton must already be present. This work did not run any MySQL migration, bootstrap, seeding, or real email send. Tests migrate only guarded in-memory SQLite and use fake notifications/mail.

After reviewing backups, target database, transactional InnoDB configuration and deployment maintenance/rollback procedure, an authorised operator can review and then apply **only** this migration:

```bash
php artisan migrate --path=database/migrations/2026_11_02_000002_add_registration_approval_workflow.php --pretend
php artisan migrate --path=database/migrations/2026_11_02_000002_add_registration_approval_workflow.php --force
```

These commands were **not executed against MySQL**. Do not run reset/fresh/reseed operations or re-bootstrap the existing Super Admin. Roll out schema before the code receives traffic; otherwise new-column queries cannot work. Do not roll back/drop decision history casually. Review Phase 9A's remaining production-security concerns separately.

## Security and compatibility model

- Public signup accepts name, email, confirmed password and requested `admin`/`client` access only. It refuses privilege/status/verification inputs and a nonempty honeypot. All effective fields are assigned explicitly server-side and remain outside `Fillable`.
- New public users are `role=client`, `is_active=false`, `registration_status=pending`. Requested administrator access confers no administrator capability. No automatic login occurs.
- Protected routes require active status and, for public registrants, approved status plus verified email. Legacy and administrator-created accounts with null registration status retain their existing behaviour; adding Laravel's verification contract does not globally enforce verification on them.
- Ordinary client management excludes pending/rejected applicants and cannot bypass approval. Approved public clients remain manageable; changing their email clears verification and sends a new verification request. Their protected access resumes only after verification of the new address, provided they remain active and approved.
- Public duplicate-email submissions, including DB uniqueness races, receive the same generic notice as a new eligible request; existing accounts are never modified or automatically resent mail by signup. Resend also returns a generic response. This avoids explicit account enumeration; it is not a claim of constant-time network responses.
- Signup is limited to five requests per IP/hour and three per normalized-email/hour; resend to ten per IP/hour and three per email/hour. Verification links are limited to ten requests/minute; decision writes to five/minute per authenticated actor. CSRF remains enabled. These controls are not a CAPTCHA or a complete distributed-abuse prevention service; tune them for real traffic and trusted proxy configuration.

## Password policy

`PasswordPolicy::creationRules()` is shared by public signup, administrator-created clients and `glos:create-admin`. New passwords require confirmation, at least 12 characters, upper/lowercase letters, a number and a symbol, and at most **72 bytes**, not 72 characters. Null bytes are rejected before hashing. The existing configured Laravel password defaults also run, including production compromised-password validation. Non-ASCII characters can use several bytes; there is no silent truncation or prehash workaround.

Existing hashes are untouched. Login and current-password reauthentication deliberately remain compatible with existing longer bcrypt passwords. Bcrypt's legacy prefix semantics therefore remain for those already-created accounts; resolve them through a separately reviewed credential/algorithm migration, not a forced reset in this phase. Demo account seeding is now refused outside local/testing, but no live demo account was queried or changed.

## Email verification

Laravel's `VerifyEmail` notification is extended with queued delivery. Its signed `verification.verify` URL includes applicant ID, the standard email hash and an expiring signature (60 minutes by Laravel's default `auth.verification.expire`). It requires neither login nor access to a dashboard. The handler locks the current account, checks that the hash still matches the current email, and marks only email verification. It emits Laravel's `Verified` event after the transaction. Verification cannot activate or approve an account. Invalid/expired signatures return 403 with a resend explanation; nonexistent/ineligible records return 404. Repeated valid verification is idempotent.

Resending accepts an email address, performs a fresh pending/approved-unverified lookup, and reveals no eligibility result. Rejected registrations receive no new verification email. Rejection does not reopen registration for the same address; reopening/reapplication is not implemented.

## Approval and audit

Super Admin navigation now links to registration requests, with pending-first pagination and prior final decisions. A reviewer can approve only a verified pending applicant or reject a pending request, recording a reason. Every decision requires current-password reauthentication and explicit confirmation (stronger than requiring it only for Admin approvals).

The decision service acquires the existing privilege singleton lock, reloads/locks and verifies the active Super Admin actor, rechecks their password, locks the applicant, and rejects self-decision, stale/conflicting decisions, invalid requested roles, existing active/elevated accounts or missing verification on approval. Approval assigns **only** the stored Admin/Client request and activates the account. Rejection leaves it inactive. The user change and decision audit insert commit together; audit insertion failure rolls both back. The unique decision constraint is an additional replay/conflict safeguard. Super Admin promotion/demotion/last-active protections remain separate and unchanged. MFA is not implemented or claimed here.

## Notification delivery and operations

Laravel notifications generate:

1. Applicant verification mail.
2. A submitted-request notice to designated reviewers.
3. Approval or rejection mail to the applicant, without internal decision reasons or credentials.

`REGISTRATION_REVIEWER_IDS` can be a comma-separated set of existing Super Admin IDs. Empty means all active Super Admins. Inactive/ineligible users and ordinary administrators are excluded; no email addresses are hard-coded. Reviewer messages link to the protected review page and contain only the validated requested access, not untrusted applicant markdown or authentication data.

Development can use `MAIL_MAILER=log` and `QUEUE_CONNECTION=sync`, or a local capture SMTP service. Keep logs private: verification links are mailbox-authorisation bearer links. Do not commit environment credentials or signed links. Tests use `Notification::fake()` and `Mail::fake()`; no public email delivery has been independently verified.

For production, configure an approved SMTP/API mailer through environment secrets, a verified sender/domain, correct HTTPS `APP_URL` and proxy settings, and the provider's DNS/delivery requirements. Do not copy example credentials or assume the log mailer delivers externally. Configure and supervise the existing database queue or another supported queue backend. The notification classes request after-commit dispatch, three attempts and retry backoff of 60/300 seconds. Example worker operation, only after queue/mail configuration is reviewed:

```bash
php artisan queue:work --queue=default --tries=3 --timeout=60
```

Notification dispatch occurs after the state transaction. Enqueue/synchronous-send exceptions are caught and logged with recipient ID and exception type only, without message bodies, transport errors, passwords, hashes or verification URLs. New applicants remain pending if notification dispatch fails. A notification failure cannot authorise a decision; a valid already-committed approval remains approved if its email fails. Queue-worker failures use Laravel's failed-job facilities; restrict access to queue/log data and review retries. Failed enqueue is logged but has no persistent outbox/reconciliation workflow in this phase. Operators can investigate and resend/retry carefully; never repair mail by granting privileges.

## Routes

- `GET /register`, `POST /register` — guest-only signup.
- `GET /registration/notice` — generic notice/resend form.
- `GET /email/verify/{id}/{hash}` — signed, expiring, throttled verification.
- `POST /email/verification-notification` — public throttled generic resend.
- `GET /super-admin/registrations` — requests and prior decisions.
- `GET /super-admin/registrations/{applicant}` — individual review/decision history.
- `POST /super-admin/registrations/{applicant}/decision` — protected reauthenticated decision.

## Manual testing after operator-reviewed migration

1. Use an isolated/local mail environment; verify the reviewer-ID configuration and queue mode. Open `/register` while logged out and submit distinct Client and Administrator requests.
2. Confirm generic responses, no automatic login, inactive pending records, no effective administrator role, and generated verification/reviewer notifications. Duplicate submission must not overwrite the original account or return an existence error.
3. Open the captured signed verification link while logged out. Confirm verification success but continued login denial. Tamper with the signature and test expiration; both must fail without activation. Exercise generic resend and limits.
4. Sign in as Super Admin. Inspect request details, reject an unverified test request, and confirm approval of another unverified request is refused server-side.
5. Verify a request, approve it using the current Super Admin password, checkbox and reason; inspect the recorded actor/decision and resulting role. Repeating or reversing that decision must fail. Check Client/Admin login and the respective protected dashboards.
6. Confirm ordinary Admin/Client/guest denial of all review/decision endpoints, and inability to activate pending users through client editing. Existing accounts, assignments, certificate renewals and private PDFs must still work.
7. Disable the local mail transport/queue in the isolated environment. Confirm registration remains pending, invalid approvals grant nothing, and authorised audit/state remains consistent despite notification failure.
8. Verify real delivery separately with approved non-company test accounts only after the production mailer/domain/queue is configured; do not claim public delivery from fake/log results.

## Known limits

True concurrent MySQL requests, production mail delivery, worker retry execution and browser accessibility/appearance are not independently verified here. Reopening rejected requests, reviewer notification preferences beyond account-ID configuration, CAPTCHA, MFA and a durable notification outbox are not implemented. Phase 9A's other findings (for example structural PDF parsing and legacy formatting) remain separate. Existing users/roles, Super Admin bootstrap state, MySQL records and private PDFs were not altered by implementation or test execution.

## Verification and file manifest

Full suite: **299 tests passed, 1,675 assertions**. PHP syntax checks: **90 files passed**. Changed PHP files pass Laravel Pint. Repository-wide Pint still fails on the same nine pre-existing files: the six 27 October domain migrations and the `Vessel`, `CertificateType`, and `CertificateVersion` models. No historical migrations were reformatted. Existing tests that asserted registration was unavailable were explicitly updated for the now-authorised public workflow, retaining their privilege-injection protections; a fixture comparison was refreshed to include newly added nullable columns.

The tests cover public fields, requested roles, pending quarantine, exact password-byte boundaries, duplicate and concurrent-uniqueness handling, signed/expired/tampered links, throttling, reviewer designation, mail content, dispatch failure, verified approval/rejection, actor denials and password confirmation, duplicate decisions, audit rollback/lock intent, existing long-password compatibility, client-edit bypass prevention, changed-email reverification and production demo-seed refusal. Existing Super Admin, vessel, certificate, assignment, expiry and PDF tests also pass. Notifications and mail are faked; results do not demonstrate external delivery.

Created:

- `database/migrations/2026_11_02_000002_add_registration_approval_workflow.php`
- `app/Rules/PasswordBytes.php`
- `app/Support/PasswordPolicy.php`
- `app/Support/RegistrationNotifications.php`
- `app/Support/DecideRegistration.php`
- `app/Http/Requests/RegisterUserRequest.php`
- `app/Http/Controllers/Auth/RegistrationController.php`
- `app/Http/Controllers/SuperAdmin/RegistrationReviewController.php`
- `app/Notifications/ApplicantVerifyEmail.php`
- `app/Notifications/RegistrationMessage.php`
- `config/registration.php`
- `resources/views/auth/register.blade.php`
- `resources/views/auth/registration-notice.blade.php`
- `resources/views/super-admin/registrations.blade.php`
- `resources/views/super-admin/registration.blade.php`
- `tests/Feature/RegistrationApprovalTest.php`
- `docs/registration-deployment.md`

Modified:

- `app/Models/User.php`
- `app/Http/Middleware/EnsureUserHasRole.php`
- `app/Livewire/Auth/Login.php`
- `app/Http/Requests/SaveClientRequest.php`
- `app/Http/Controllers/Admin/ClientController.php`
- `app/Console/Commands/CreateAdmin.php`
- `app/Support/ManagePrivileges.php`
- `app/Providers/AppServiceProvider.php`
- `routes/web.php`
- `bootstrap/app.php`
- `database/seeders/DatabaseSeeder.php`
- `resources/views/layouts/admin.blade.php`
- `resources/views/layouts/auth.blade.php`
- `resources/views/livewire/auth/login.blade.php`
- `resources/views/super-admin/administrators.blade.php`
- `tests/Feature/RegistrationTest.php`
- `tests/Feature/ClientManagementTest.php`
- `tests/Feature/SuperAdminTest.php`
- `docs/super-admin-deployment.md`
