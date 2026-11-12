> Superseded policy: see [Two-role management deployment](two-role-management-deployment.md) for current permissions, approval/deletion controls, department migration, and explicit legacy-role conversion. The original operational history below is retained for reference.

# Permanent user deletion — Super Admin only

## Schema review and preservation

The current source schema supports permanent account deletion without a migration:

- `users.email` is unique; hard deletion releases that address.
- `vessel_user_access.user_id` cascades on user deletion. The service explicitly detaches only this user's assignments inside the transaction as well.
- `certificates.created_by` and `certificate_versions.uploaded_by` are nullable foreign keys with `ON DELETE SET NULL`. Their values become null; the certificates, versions, metadata, content and PDFs remain. No model-level or filesystem deletion of those records occurs.
- `registration_decisions.user_id` is unique but has no users foreign key. Its original numeric ID and decision remain unchanged. A new registration receives a new user ID, so the old decision does not conflict with a new decision for the same email.
- `privilege_audits` actor and target IDs deliberately have no cascading/restrictive users foreign keys. Their historical IDs and events remain unchanged.
- The users migration's `sessions.user_id` is indexed without a users foreign key. Matching rows are explicitly deleted. `password_reset_tokens` is keyed by email; the selected user's tokens are deleted so they cannot follow a reused email address.

No historical or additive migration was created or run for this feature. Foreign-key checks remain enabled. If the live schema has additional restrictive references, deletion fails closed and rolls back, rather than disabling integrity checks or deleting unknown dependent records. Live MySQL constraints and concurrent execution were not exercised; tests use isolated SQLite with foreign keys enabled.

## Routes and controls

- `GET /super-admin/users/{user}/delete` (`super-admin.users.delete`) displays identity and an irreversible-deletion warning.
- `DELETE /super-admin/users/{user}` (`super-admin.users.destroy`) performs the confirmed action.

Both require an authenticated, active, access-eligible Super Admin. The DELETE request also has the existing five-per-minute privileged-operation throttle and web CSRF protection. The form requires the actor's current password, the target's exact email, and the exact text `DELETE`. GET never deletes. No new ordinary admin/client deletion route was added.

Delete User links appear on pending/rejected/approved registration details, administrator privilege review/list pages where applicable, and client account details **only for Super Admin viewers**. Super Admin and self-deletion controls are absent and those operations are rejected server-side regardless of submitted input. Clients have no deletion controls in their portal.

The service starts a transaction, locks the existing privilege singleton, reloads/locks the actor and target, rechecks the actor's role/eligibility/password, rejects self-deletion and every role except `admin`/`client`, validates the target's current status and confirmed email, writes a deletion audit event, removes assignments/reset tokens/session rows, and hard-deletes the user. All of that commits together or rolls back. The lock order matches promotion/demotion and registration decisions, preventing a concurrent promotion from being overlooked. Repeated requests to the removed account return 404 without duplicate audit events.

## Audit retention and minimisation

Original registration decisions and privilege events are retained without rewriting them. A new `privilege_audits` event records `delete_user`, actor/target numeric IDs, previous role/active state, a `deleted` outcome, timestamp, and a fixed server-generated reason including the former registration status. No extra copy of the target's name, email, password, hash or verification/reset token is stored by this feature. Numeric IDs are pseudonymous historical references, not a claim of full anonymisation.

Application retention policy for this feature is **preserve existing security evidence; no automatic purge**. Existing free-text audit reasons are preserved and may contain personal information entered previously. A legally/business-approved retention period, redaction/purge process, independent log export and backup retention remain operator policy decisions; no arbitrary retention duration is invented here. This is account deletion, not an assertion that every historical email/log/backup has been erased.

## Revocation and related data

The default primary-database `sessions` rows for the deleted user are removed. Laravel's user provider cannot resolve a deleted ID on subsequent requests, including with file/Redis/cookie sessions; old remember tokens and registration-status session context likewise cannot resolve the deleted account. Other session stores are not enumerated or physically purged by this implementation. If session tables/connections are customised, review that storage cleanup separately; the current source schema's primary `sessions` table is the cleanup target.

Old verification links bind the old user ID and cannot verify a new account with the same email. Reset tokens keyed to that email are removed. Original audits bind IDs, not email addresses. Vessels and unrelated assignments are preserved; certificate attribution is cleared by the existing constraints, not reassigned to the deleting Super Admin or the newly registered account.

The notification system, SMTP configuration, queues, job payloads and queued-email delivery are untouched. Jobs referring to an account deleted before execution may encounter a missing user; they are not replayed or retargeted to a new registration. No existing account was deleted during implementation, and the current Super Admin credentials/account were not altered. No company PDF was read, changed or removed.

## Manual verification

1. Sign in as an active Super Admin and choose a disposable test account you explicitly intend to delete, using registration details, client details or administrator management.
2. Select **Delete User**. Check displayed name, email, immutable account ID, role/status and warning carefully.
3. Enter the selected email, type `DELETE`, enter your current Super Admin password and submit. This is the irreversible operation; no separate migration is needed.
4. Confirm the success message and `delete_user` audit entry. The original registration decision/privilege events must remain associated with the old ID. The account and its vessel assignments should be gone, while vessels, certificates, versions and PDFs remain. Creator/uploader references formerly pointing to the user should be null.
5. In the deleted account's previous browser session, request its dashboard or a PDF again; it must be denied/redirected to login. A repeated deletion URL should return 404.
6. Log out, register using the deleted email, and repeat the normal verification/approval process. Confirm a new ID, inactive pending state, and no inherited assignments or old decision.
7. Verify ordinary Admin/Client/guest denial, wrong-password/missing-confirmation refusal, and that no Super Admin account (including self/inactive accounts) has a working deletion control.

Use only test accounts for this exercise; no accounts were automatically selected or deleted by the implementation. SMTP/verification mail during a manual re-registration follows the existing configured notification system.

## Verification results

- Deletion suite: **30 tests passed, 188 assertions**.
- Full suite: **338 tests passed, 1,919 assertions**.
- Existing authentication/registration/approval/notice regressions were also executed explicitly.
- Changed PHP files pass syntax checks and Laravel Pint.
- Repository-wide Pint still fails on the same nine pre-existing untouched files (six historical domain migrations plus `Vessel`, `CertificateType`, `CertificateVersion` models).
- Tests cover supported statuses/roles, complete email reuse/verification/approval, assignment cleanup, certificate/version/PDF preservation, immutable audit history, sessions/tokens, actor/target rechecks, rollback, unexpected restrictive foreign keys, CSRF enforcement with the test bypass disabled, throttling and repeated submissions.
- Browser appearance and actual concurrent MySQL locking remain unverified. Audit storage is append-only through application routes, not tamper-proof against database-owner access. Direct trusted SQL/Tinker operations are outside route/service safeguards.

## Files

Created:

- `app/Support/DeleteUser.php`
- `app/Http/Controllers/SuperAdmin/UserDeletionController.php`
- `resources/views/super-admin/delete-user.blade.php`
- `resources/views/super-admin/delete-user-link.blade.php`
- `tests/Feature/UserDeletionTest.php`
- `docs/permanent-user-deletion.md`

Modified:

- `routes/web.php`
- `resources/views/super-admin/registration.blade.php`
- `resources/views/super-admin/change.blade.php`
- `resources/views/super-admin/administrators.blade.php`
- `resources/views/admin/clients/show.blade.php`
- `resources/views/admin/styles.blade.php`
