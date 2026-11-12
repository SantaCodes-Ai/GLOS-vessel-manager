# Phase 14B-4 — MySQL vessel, certificate, PDF and dashboard integration

## Results

All checks passed without warnings or isolation failures:

| Verification | Tests | Assertions |
| --- | ---: | ---: |
| New mysql-fleet group | 80 | 726 |
| Complete MySQL integration suite | 125 | 1,092 |
| Existing SQLite/mock regression | 575 | 3,178 |

The complete MySQL run includes the unchanged account group (44 tests, 363 assertions), infrastructure smoke (1 test, 3 assertions) and the new fleet group. PHP syntax checks passed for all 130 PHP files checked. Changed-file Laravel Pint passed for the six new PHP files.

No application defect or application-code incompatibility was found in the covered workflows. No application code or existing test/infrastructure file was modified.

## Inspection and scope

Reviewed vessel/type requests/controllers/models and Blade forms; certificate registration/renewal requests/controllers and relationships; current-version selection; private document serving; qpdf validation; expiry calculations; administrator dashboard/card links/status filtering; Staff assignment queries and document controllers; existing SQLite cases; and the MySQL transaction/ownership/grant/schema guards.

Vessel/type forms are server-rendered Blade forms using FormRequests. There are no vessel-specific Livewire components; the existing Livewire component is authentication.

All new tests extend the existing MySqlTestCase and use its dedicated runner, managed transaction, prepared-schema verification, locks, fake mail/notifications and private temporary storage. They do not install SQLite grammars, use PRAGMA, apply RefreshDatabase, run DDL or commit the outer transaction.

## Vessel and certificate-type findings

Genuine MySQL/controller coverage verifies:
- Valid vessel registration and updates, preserving an unrelated vessel.
- Name/type/flag/status requirements, string lengths, malformed arrays and safe create/edit redisplay.
- Seven-digit IMO format/check-digit validation, optional IMO and ordinary uniqueness errors.
- Direct duplicate IMO insertion rejected by the real MySQL unique index (1062).
- Multiple absent IMO values and duplicate vessel names remain allowed under existing business rules.
- Vessel search and active/inactive filtering.
- Certificate-type creation/editing, description/validity updates, malformed form input and preservation of unrelated types.
- Case-insensitive duplicate type-name validation and the native MySQL uniqueness constraint.

No uniqueness requirement was invented for vessel names, and no existing form rules or UI design were changed.

## Certificate lifecycle and versioning findings

- Initial registration creates one certificate and exactly version 1, correctly linked to its vessel/type and authenticated administrator.
- Submitted uploader/version/path privilege fields do not control stored metadata.
- PDF bytes are stored unchanged with private visibility, generated UUID paths and original filename metadata; public storage remains empty.
- Repeated renewals create versions 2 and 3, preserve historical metadata/files and unrelated certificate metadata, and retain vessel/type/remarks despite injected association fields.
- Highest version_number wins even if lower-numbered history is inserted later; renewing version 7 creates version 8.
- Invalid dates/types/numbers/associations leave existing certificate/version metadata and files unchanged.
- Permanent creation/renewal clears expiry dates; optional issuance/number fields remain supported.
- Real version uniqueness and parent/uploader FK constraints reject invalid DML with MySQL 1062/1452.

## PDF validation, failures and rollback

The installed real validator was verified as qpdf 12.4.2. Valid fixture documents pass the existing upload rules. Both initial registration and renewal reject renamed non-PDF files, header-only PDFs, fake structures, truncation, broken cross references, wrong extensions and oversized uploads. Validator unavailability fails closed.

Tests compare database rows, historical attributes and private-file inventories before/after failure. Validation failures create no new certificate/version/file and do not expose temporary upload paths.

Deterministic failure tests exercise existing controllers against genuine MySQL constraints:
- A test-only CertificateVersion created listener attempts a duplicate of the just-inserted version. MySQL 1062 forces rollback after persistence; initial registration/renewal remove only the newly stored PDF.
- A test-only Certificate updated listener attempts an invalid vessel FK after renewal has updated parent metadata. MySQL 1452 forces rollback of the parent number, new version and newly stored file.

These are scoped Eloquent test listeners, not database triggers or application changes. Each listener is removed in finally.

Storage failure is simulated at the adapter boundary: it writes a partial file to the existing isolated test disk and throws. Both controllers compensate by deleting that partial test-owned file, preserving historical files and rows. This is not a claim that a real disk-full/permissions outage was reproduced.

## Expiry and dashboard consistency

Controlled dates verify all categories and the existing inclusive boundaries:
- Yesterday: Expired.
- Today and 14 days: Critical.
- 15 and 30 days: Expiring Soon.
- 31 days: Valid.
- Permanent ignores stale expiry dates.
- Unknown handles absent versions, missing fixed dates, unsupported legacy types and issuance-after-expiry data.

A separate timezone case verifies the application calendar rather than UTC date. No expiry threshold or calculator was changed.

All eight administrator card URLs are extracted from the real rendered dashboard and followed. Totals and all six category filters match the corresponding certificate IDs/counts. Expired history on a currently valid record does not inflate counts or the attention list.

The suite also verifies grouped vessel/type searches, latest-first ordering with ID tie-breaking, 15-record pagination, query-parameter persistence, clearing status, empty results and invalid-filter rejection.

## Staff authorisation findings

Real assignment-backed queries verify:
- Dashboard counts/attention, vessel listings/details and certificate listings/details show assigned records only.
- Search cannot expose an unassigned vessel/type.
- Direct unassigned URLs return 404.
- Current and historical preview/download require assignment; version numbers belonging only to another certificate do not resolve through an authorised certificate.
- Responses preserve PDF MIME, nosniff, sandbox CSP, no-store and inline/attachment behaviour.
- Administrators retain document access, while client-only routes remain role-restricted.
- Removing assignments through the existing administrator client-update controller revokes subsequent metadata/current/historical PDF access immediately, even with a deliberately stale loaded vessels relation.
- Staff cannot access administrator filters or create/update vessel/type/assignment records, register certificates or renew them.
- Unsupported certificate PUT remains HTTP 405 because no update endpoint exists for any role.
- Guests/inactive Staff are denied; missing files and unsafe stored test paths return 404.

## MySQL integrity and baseline

Real server errors verified:
- 1062: duplicate IMO/type/version keys.
- 1452: missing version parent/uploader and injected late renewal FK failure.
- 1292 / SQLSTATE 22007: strict DATE storage rejects invalid text.

The SQLite fixture that stores literal invalid in a DATE column was not copied. Unknown-status cases use database-representable data, and strict invalid-date rejection is tested separately. SQL mode was not weakened.

Every test teardown rolled back its transaction and passed the existing ownership, migration manifest, schema fingerprint and clean-row-baseline checks. The subsequent complete MySQL run passed all 125 cases, including account tests and smoke, with the same safeguards.

Only expected migration records, the privilege-lock sentinel and ownership marker remain after rollback. InnoDB auto-increment counters may advance despite rollback; no counter reset was attempted. This is expected MySQL allocation behaviour, not retained fixture rows or schema drift.

No automatic cleanup/repair was necessary. Test-owned files were confined to the existing runtime's private random temporary directory and removed by its normal shutdown cleanup. Company PDFs were not accessed or modified.

## Commands executed

New group:

    vendor/bin/pest --configuration=phpunit.mysql.xml --group=mysql-fleet --stop-on-error --stop-on-failure --display-warnings

Combined new/existing MySQL suite:

    vendor/bin/pest --configuration=phpunit.mysql.xml --stop-on-error --stop-on-failure --display-warnings

SQLite/mock regression:

    php artisan test

Formatting:

    vendor/bin/pint --test tests/Integration/MySql/Support/FleetFixtures.php tests/Integration/MySql/VesselTypeManagementTest.php tests/Integration/MySql/CertificateLifecycleTest.php tests/Integration/MySql/PdfLifecycleSecurityTest.php tests/Integration/MySql/ExpiryDashboardTest.php tests/Integration/MySql/StaffCertificateAccessTest.php

The sandbox initially denied the local MySQL connection before fixture writes. The authorised command then succeeded outside the sandbox. No safety guard was bypassed or altered. Tests were run serially.

## Files created

- tests/Integration/MySql/Support/FleetFixtures.php
- tests/Integration/MySql/VesselTypeManagementTest.php
- tests/Integration/MySql/CertificateLifecycleTest.php
- tests/Integration/MySql/PdfLifecycleSecurityTest.php
- tests/Integration/MySql/ExpiryDashboardTest.php
- tests/Integration/MySql/StaffCertificateAccessTest.php
- docs/mysql-fleet-certificate-integration-tests.md

Existing files modified: none.

## Limitations and next recommendations

- No concurrency, simultaneous renewal allocation, outer transaction commit or DDL scenario was implemented. Those require a separately approved approach.
- qpdf structural checks are not malware scanning, full page-content semantics or document authenticity/signature verification.
- Storage-error compensation was tested with deletion still available; complete storage outages and cleanup-delete failure durability were not reproduced.
- These HTTP/Livewire-independent controller integration checks do not replace interactive browser/device checks or production-scale performance tests.
- Future work can cover controlled concurrency and separately reviewed storage-outage recovery. It should retain the existing isolation guards and never reset the prepared schema automatically.

No migrations, schema preparation, table creation/drop/alter, privilege/account changes, real email delivery or development-database access occurred. No company records, real PDFs, role permissions, branding or application logic were changed.
