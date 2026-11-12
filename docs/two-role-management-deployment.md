# Two-role management deployment

## Implemented permissions and compatibility

The effective roles are Administrator (admin) and Client (client). Every active, eligible administrator can now review registration requests, manage administrator accounts, and permanently delete other users. Clients retain read-only assigned-vessel/certificate access.

Existing super_admin rows are interpreted as administrators until an operator explicitly converts them. Merely logging in or viewing pages never converts an account. All new grants use admin/client only. UserRole has two cases; isSuperAdmin remains a legacy inspection helper with no authorisation use. Existing super-admin URL prefixes, route names and controller/view directories remain compatible; navigation displays administrator labels. Historical audit role strings are rendered unchanged.

The old glos:bootstrap-super-admin command is retired and refuses writes. Its singleton bootstrap marker and earlier bootstrap audits remain intact. glos:create-admin remains the explicit CLI account-recovery/initial-administrator mechanism; use an accountable operator, a protected terminal and the existing password policy. Neither command was run against company data during this implementation.

Public registrations still request admin/client separately from effective privileges, remain inactive pending clients, require signed email verification and administrator approval, and never sign in automatically. Pending/rejected/unverified applicants cannot be activated through role-management or client-management routes.

## Database changes and deployment order

One additive migration adds nullable users.department (VARCHAR 255). Existing rows receive no department requirement or role/status change. Historical migrations are untouched. No business-table changes are required: users.role already stores strings, and audit role columns preserve old values without an enum restriction.

**No persistent-database migration or role conversion has been executed.** Before rollout:

1. Back up the database, confirm the intended environment/database without exposing credentials, and verify a known active eligible administrator can sign in.
2. Review outstanding migrations. Use maintenance/deployment controls so code selecting department does not serve requests before the column exists. Apply only this new migration after reviewing its SQL:
   ```sh
   php artisan migrate --path=database/migrations/2026_11_05_000001_add_department_to_users_table.php --pretend
   php artisan migrate --path=database/migrations/2026_11_05_000001_add_department_to_users_table.php --force
   ```
   These are proposed operator commands, not commands run by this implementation. Do not run migrate:fresh or reset the database.
3. Deploy/reload application code and restart long-lived queue workers using the normal deployment process. Verify existing administrator and client sessions, approvals, assignments and PDFs.
4. Preview legacy-role conversion, then optionally apply it from an interactive protected terminal:
   ```sh
   php artisan glos:convert-admin-roles
   php artisan glos:convert-admin-roles --apply --operator="accountable-operator"
   ```
   The first command only counts legacy accounts. The second requires explicit interactive confirmation. It refuses unattended/anonymous application, missing privilege lock state, or a database without an accessible administrator. It converts only super_admin roles, including inactive legacy accounts without activating them; preserves passwords, emails, verification/status, assignments and bootstrap state; and appends one role_conversion privilege audit per converted account. It never rewrites old audits. Repeat application produces no duplicate conversion audits. Audit failure rolls back the conversion.
5. Verify the intended administrator still signs in and can access management. Retain the compatibility alias until every deployment and restore procedure has been reviewed.

Do not automatically roll back role data or rewrite audit history during recovery. Restore a verified backup under an agreed recovery procedure if necessary. A backup with legacy super_admin roles remains usable with this compatibility code.

## Approval and deletion controls

Verified pending applicants have an Approve button on their review page. Approval POST needs only decision=approved and a CSRF token. Rejection uses a native confirmation dialog with Cancel/Confirm Rejection. No current-password, typed-reason or manual-checkbox fields are required for these decisions. System-generated reasons are stored in registration_decisions; unsupported role overrides, self-approval, unverified approval and repeat/conflicting decisions are rejected.

Deletion has a selected-account review page and a native modal with the requested irreversible-action warning, Cancel and Confirm Delete. The DELETE request requires CSRF, an accepted confirmation value, and a server-side account-review intent bound to the acting administrator, target fingerprint and ten-minute expiry. Changes to target identity/role/status invalidate that review. No password, target-email entry or typed DELETE is needed.

Decision, deletion, role changes and legacy conversion share the privilege singleton lock before user locks. Fresh actor eligibility is checked inside transactions. Self-deletion is forbidden; removing the last eligible active administrator by demotion, deactivation or deletion is protected. Privileged writes and their audit records commit atomically. Deletion detaches assignments, removes the target's primary-database sessions/password-reset token and relies on existing foreign-key SET NULL/CASCADE constraints. Certificate metadata, versions, vessels, private files and historical audit rows remain. Unexpected restrictive foreign keys fail safely with rollback. Deleted email addresses retain the existing ability to register anew under a new user ID.

Management mutations retain 5-per-minute throttling. Role/status changes (client-to-admin, admin-to-client, activation/deactivation) still require current-password/target confirmation/reason; the simplified requirements apply to approval/rejection and deletion. The broader administrator authority and removal of reauthentication from approval/deletion are intentional requested policy changes. MFA is not newly implemented or claimed.

## Departments and navigation

Public registration requires a suggested department or Other with a nonblank custom name of at most 255 characters. Suggested departments are Information Technology, Human Resources, Operations, Marine Operations, Health, Safety & Environment, Security, Finance / Accounts, Business Development and Other. A single nullable string stores the selected label or custom text. Custom text is escaped in Blade and never grants permissions. Existing and administrator-created accounts may have null departments. Departments are excluded from ordinary mass assignment.

All Users lists/searches/paginates every account and provides department editing, registration, role/status, client assignments and deletion links as appropriate. Administrators can edit any user's department through the separate department action; client forms also support optional department editing. No role/status/password field is updated by department editing. Approval details and administrator/client details show the department.

New routes:
- GET /admin/users (admin.users.index)
- GET /admin/users/{user}/department (admin.users.department.edit)
- PATCH /admin/users/{user}/department (admin.users.department.update)

The branded administrator navigation uses keyboard-accessible native details/summary sections: Dashboard, Vessels, Certificate Management (Certificates, Certificate Types), User Management (All Users, Clients / Assignments, Registration Approvals, Administrators), and CSRF-protected Logout. Current routes select and expand the relevant section. The Menu control collapses navigation on mobile without a frontend library; the sidebar scrolls on short desktop screens.

Registration reviewer selection now includes all eligible active administrators and legacy aliases, subject to the existing optional reviewer-ID restriction. Queued delivery remains supported. Mail transport and credentials were not changed.

## Verification results

Results after implementation:
- Targeted role/registration/deletion/client-management tests: 192 passed, 1,116 assertions, zero failures.
- Full suite: 358 passed, 2,109 assertions, zero failures/errors/skips reported.
- PHP syntax: all 97 PHP files under app, routes, config, database and tests passed php -l.
- Laravel Pint applied to all 31 changed/new PHP files; the subsequent scoped check passed.
- Repository-wide Pint check still identifies existing formatting in six 2026_10_27 domain migrations and EOF whitespace in Vessel, CertificateType and CertificateVersion. Those unrelated files and historical migrations were left untouched.

Tests use the existing fail-closed in-memory SQLite guard, isolated factories and notification/storage fakes. No real MySQL records, accounts, email settings, PDFs or SMTP deliveries were modified or exercised. SQLite tests verify locking requests/order and transactional rollback, not MySQL parallel-lock behaviour. Native modal/menu markup is tested server-side; interactive desktop/mobile browser testing remains a manual deployment check.

Existing tests were updated where they asserted the retired policy (admin denial, third-role promotion/bootstrap, password-required approval/deletion, typed deletion confirmation and Super Admin-only notifications). Their applicable denial, audit rollback, duplicate-decision, credential preservation, throttling, CSRF, certificate/PDF, assignment and session-revocation coverage remains.

## Manual checks after approved deployment

- Sign in as an existing legacy administrator; verify management access before conversion.
- Complete a new Admin request and a Client request, including a suggested and custom department. Verify that both remain unable to sign in before email verification and approval.
- Approve a verified applicant with one click; reject another through Cancel then Confirm Rejection. Refresh/repeat a decision and verify it cannot change.
- Edit department for an administrator and client; verify role/status/credentials and assignments remain unchanged.
- Test delete-dialog Cancel without deletion using disposable isolated accounts; only explicitly Confirm Delete when an authorised test identity is intended. Never test against company accounts.
- Verify self-deletion and final-administrator demotion/deactivation are rejected.
- On desktop and a narrow mobile viewport, expand/collapse menus, inspect active links, use keyboard focus/Enter/Escape on dialogs and menu, and sign out.
- Verify client denial of management endpoints and immediate access revocation after vessel unassignment; preview/download current and historical authorised PDFs.
- Check configured queue processing for reviewer notifications. Actual SMTP delivery has not been tested by this change.

## Files created

- `app/Console/Commands/ConvertAdministratorRoles.php`
- `app/Http/Controllers/Admin/UserManagementController.php`
- `app/Support/Departments.php`
- `database/migrations/2026_11_05_000001_add_department_to_users_table.php`
- `docs/two-role-management-deployment.md`
- `resources/views/admin/users/department.blade.php`
- `resources/views/admin/users/index.blade.php`
- `resources/views/shared/department-fields.blade.php`
- `tests/Feature/RoleSimplificationTest.php`

## Files modified

- `app/Console/Commands/BootstrapSuperAdmin.php`
- `app/Enums/UserRole.php`
- `app/Http/Controllers/Admin/ClientController.php`
- `app/Http/Controllers/Auth/RegistrationController.php`
- `app/Http/Controllers/SuperAdmin/AdministratorController.php`
- `app/Http/Controllers/SuperAdmin/RegistrationReviewController.php`
- `app/Http/Controllers/SuperAdmin/UserDeletionController.php`
- `app/Http/Middleware/EnsureUserHasRole.php`
- `app/Http/Requests/RegisterUserRequest.php`
- `app/Http/Requests/SaveClientRequest.php`
- `app/Models/User.php`
- `app/Notifications/ApplicantVerifyEmail.php`
- `app/Notifications/RegistrationMessage.php`
- `app/Support/DecideRegistration.php`
- `app/Support/DeleteUser.php`
- `app/Support/ManagePrivileges.php`
- `app/Support/RegistrationNotifications.php`
- `config/registration.php`
- `docs/permanent-user-deletion.md`
- `docs/registration-deployment.md`
- `docs/super-admin-deployment.md`
- `resources/views/admin/clients/form.blade.php`
- `resources/views/admin/clients/show.blade.php`
- `resources/views/admin/styles.blade.php`
- `resources/views/auth/register.blade.php`
- `resources/views/auth/registration-notice.blade.php`
- `resources/views/layouts/admin.blade.php`
- `resources/views/super-admin/administrators.blade.php`
- `resources/views/super-admin/change.blade.php`
- `resources/views/super-admin/delete-user-link.blade.php`
- `resources/views/super-admin/delete-user.blade.php`
- `resources/views/super-admin/registration.blade.php`
- `resources/views/super-admin/registrations.blade.php`
- `routes/web.php`
- `tests/Feature/BootstrapSuperAdminTest.php`
- `tests/Feature/ClientManagementTest.php`
- `tests/Feature/RegistrationApprovalTest.php`
- `tests/Feature/RegistrationNoticeTest.php`
- `tests/Feature/RegistrationTest.php`
- `tests/Feature/SuperAdminTest.php`
- `tests/Feature/UserDeletionTest.php`

No Git repository metadata was present in this workspace; this inventory records the files edited for the task. No dependency manifests, historical migrations, certificate/PDF implementation files, .env, or mail transport settings were changed.
