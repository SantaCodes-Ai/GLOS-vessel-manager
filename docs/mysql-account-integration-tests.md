# Phase 14B-3 — MySQL authentication and account integration tests

## Outcome

Implemented the first business integration group using the prepared glos_vessel_manager_test schema, the existing dedicated runner and unchanged MySqlTestCase safety/transaction lifecycle.

Results:
- Account group: **44 tests passed, 363 assertions**, no warnings/errors.
- Infrastructure smoke: **1 test passed, 3 assertions**, no warnings/errors.
- Existing SQLite/mock regression: **575 tests passed, 3,178 assertions**.
- Changed-file Laravel Pint: **passed** for all five new PHP files.
- PHP syntax: **124 files checked, zero failures**.

All account test teardowns passed the existing ownership, schema fingerprint, migration manifest and clean-row-baseline checks. The subsequent smoke test passed those checks again. No isolation guard failed and no automatic/manual database cleanup or repair was performed.

## Inspection and scenario selection

Reviewed authentication/Livewire login tests, registration and signed verification, registration approval/rejection, account deletion, role/active-account middleware, privilege management and MySQL guards/base lifecycle.

The new suite calls existing routes, Livewire Login and application services. It does not use RefreshDatabase, replace query grammars, manufacture SQLite exceptions, use PRAGMA, create tables or commit the outer transaction. It checks native MySQL driver error codes where appropriate.

## Implemented coverage

### Authentication and permissions

- Active Admin and Staff login against actual persisted MySQL users.
- Correct dashboard redirects, password-field clearing and session regeneration.
- Incorrect password and unknown email produce generic denial.
- Inactive Admin/Staff login denial.
- Existing-session denial after database deactivation.
- Pending/rejected registrations remain inaccessible, including incorrectly activated fixtures.
- Approved-but-unverified public accounts remain inaccessible.
- Current admin/client role enum/helpers and management/dashboard boundaries.
- Guest denial for protected dashboards and account management.

Staff terminology remains user-facing; the internal role stays client. No new legacy Super Admin account is created.

### Registration, verification and decisions

- Public Staff and Admin requests remain inactive pending clients, with requested access stored separately.
- Department selection and custom department storage.
- Real password hashing and no automatic authentication.
- Applicant/reviewer notifications are generated through Notification::fake; Mail::fake prevents external delivery.
- A genuine signed verification URL updates verification state without activating the account.
- Ordinary active administrators approve verified applicants, creating an audit decision and activating only the requested role.
- Approved Admin/Staff can log in with the original password.
- Rejection stays inactive and preserves its decision.
- Unverified approval denial; Staff/guest/inactive-reviewer denial.
- Duplicate public registration returns the generic response and does not mutate the existing user.
- A direct duplicate email insert using a case variant fails with actual MySQL 1062 under the configured collation.
- Repeated/conflicting approvals cannot change an already recorded decision or send another approval notification.

Decision atomicity is verified using a constructed pending fixture with a pre-existing decision audit. The service updates the applicant, then the real decision uniqueness constraint rejects its second audit insert. Its nested transaction rolls back activation/role/status, retaining the original audit. This is deterministic fault coverage, not a concurrency simulation.

### Deletion, history and data preservation

- Eligible approved Staff, pending/rejected applicants and another eligible Administrator can be deleted by an active administrator.
- Self-deletion is denied, including the sole active administrator.
- Sole accessible administrator demotion/deactivation is denied; inactive and unverified administrators do not count as recovery access.
- Account deletion removes assignments, stored session rows and password-reset rows.
- Other Staff assignments, unrelated vessels, certificate types, certificates and both certificate-version records survive.
- Native SET NULL preserves creator/uploader records after their account is deleted.
- Current/historical downloads remain available to the administrator and return unchanged test-owned PDF bytes.
- Historical registration decisions and privilege audits, including historical role strings, remain unchanged.
- Deleted email can register again under a new inactive pending identity, with old audit IDs retained.
- Staff/guest/inactive-administrator deletion denial.

Atomic deletion failure uses a test-only Eloquent deleted listener, not a database trigger or schema change. After the real user DELETE and its FK actions, the listener attempts an assignment to that just-deleted user. MySQL rejects it with 1452. The existing service/controller transaction rolls back the deletion, assignment/session/token removals, creator/uploader nulling and newly inserted deletion audit. The test confirms the fault actually occurred after DELETE and removes its listener in finally.

### Database integrity

- Native orphan-assignment and certificate-creator FK rejection: SQLSTATE 23000, MySQL 1452.
- Native duplicate-assignment rejection: SQLSTATE 23000, MySQL 1062.
- Native vessel deletion restriction while certificates reference it: SQLSTATE 23000, MySQL 1451.
- A direct fixture-user DELETE proves assignment CASCADE independently of the service's detach call.
- Explicit nested/savepoint rollback restores inserted/updated fixture rows and retains the managed outer transaction.

Fixture helpers create only isolated test accounts/records. Their two certificate versions and PDF bytes are disposable fixtures in the existing runtime's private random temporary storage, never company PDF files.

## Commands executed

Targeted MySQL group:

    vendor/bin/pest --configuration=phpunit.mysql.xml --group=mysql-accounts --stop-on-error --stop-on-failure --display-warnings

Existing smoke test after the account group:

    vendor/bin/pest --configuration=phpunit.mysql.xml --filter=InfrastructureSmokeTest --stop-on-error --stop-on-failure --display-warnings

SQLite regression:

    php artisan test

Formatting:

    vendor/bin/pint --test tests/Integration/MySql/Support/AccountFixtures.php tests/Integration/MySql/AuthenticationAccountsTest.php tests/Integration/MySql/RegistrationApprovalAccountsTest.php tests/Integration/MySql/DeletionAccountsTest.php tests/Integration/MySql/AccountIntegrityTest.php

The sandbox initially blocked the local MySQL connection with Operation not permitted before any assertions or test-fixture writes. The authorised command was then run outside the sandbox. That execution restriction was not an isolation-guard failure. Stop-on-error/failure remained enabled.

## Baseline and MySQL findings

No application-code incompatibility was found in these covered MySQL workflows. Native uniqueness, restrictive FKs, SET NULL, CASCADE and nested rollback behaved as asserted.

The approved baseline was verified after each case and by the final smoke test: migration records, the privilege-lock sentinel and ownership marker remain; business/test-fixture rows do not remain. Schema and migration fingerprints stayed valid.

InnoDB auto-increment values can advance even when fixture inserts roll back or fail. Tests intentionally do not assume IDs start at one or are consecutive. These expected gaps are not leftover data or schema drift, and counters were not reset (which would require prohibited schema work).

## Files created

- tests/Integration/MySql/Support/AccountFixtures.php
- tests/Integration/MySql/AuthenticationAccountsTest.php
- tests/Integration/MySql/RegistrationApprovalAccountsTest.php
- tests/Integration/MySql/DeletionAccountsTest.php
- tests/Integration/MySql/AccountIntegrityTest.php
- docs/mysql-account-integration-tests.md

No existing files were modified. Application code/configuration, private profiles, .env, MySQL guards, migrations, role values, branding and existing SQLite tests remain unchanged.

## Exclusions, limitations and next recommendations

- The unexpected additional restrictive-FK deletion fixture from SQLite requires creating a table. It was excluded. Existing prepared-schema constraints and a genuine late FK error provide DML-only failure coverage instead.
- The deletion service's last-active-administrator count branch cannot be independently reached by a different authorised active administrator when the target is the sole active administrator: that actor would itself make two. The supported tests cover sole-admin self-deletion denial and actual last-admin demotion/deactivation checks rather than claiming branch coverage.
- This phase does not implement concurrency, DDL tests, outer transaction commits, schema preparation or a full port of all business tests.
- Notifications were faked; no real SMTP or queue-worker delivery was exercised.
- Further DML-only MySQL coverage can target vessel/certificate validation, renewal/version allocation, expiry/date behaviour, dashboard filter consistency and Staff document access. Concurrency needs its own separately approved approach.

No MySQL migrations, schema preparation, resets, truncation, table creation/deletion or account/permission changes were executed. No connection to glos_vessel_manager was used. Real accounts, vessels, certificates, PDFs and audit records were untouched.
