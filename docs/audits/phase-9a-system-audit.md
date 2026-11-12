# Phase 9A — System audit

Global Link Ocean Streams Vessel Certificate Management System  
Audit date: 29 October 2026 (Europe/London)

## A. System overview

The implemented application covers the operational phases through 8B: administrator management, certificate registration/renewal, private documents, expiry tracking, client management, vessel assignments, and a read-only client portal. Application routes use Laravel session authentication and active-role middleware; login uses Livewire. Documents are served through a shared private-disk responder.

This is an inspection and evaluation, not a security certification. Evidence comprises source review, the unchanged existing suite, isolated SQLite probes, syntax checks, and formatting checks. No development MySQL connection was used for audit tests. No databases were created or dropped on MySQL, no private production/development PDFs were opened or changed, and no credentials were created or changed. Only these audit documents were added; application code, migrations, and tests were not modified.

PHP CLI was verified as 8.5.12. The declared Laravel/Livewire/MySQL technology is consistent with project configuration, but the live MySQL server version and deployed infrastructure were not independently verified.

## B. Verified functional requirements

| Area | Implementation and verification | Status |
|---|---|---|
| Authentication | Email/password login, generic invalid/inactive error, five failed attempts per email/IP per minute, session regeneration, POST logout and CSRF-token regeneration | Implemented; regression tests pass |
| Role restrictions | All 29 administrator routes and nine client routes have authentication and matching active-role middleware | Implemented; route inventory and denial tests verified |
| Public registration | No registration GET/POST endpoint or registration component | Disabled; regression verified |
| Vessels | Administrator create/edit/detail/list/search/status filter, optional unique seven-digit IMO/check-digit rule, pagination; no deletion | Implemented; regression verified |
| Certificate types | Administrator create/edit/detail/list/search, unique name and optional positive validity months; no deletion | Implemented; regression verified |
| Registration | Existing vessel/type validation, optional metadata, fixed/permanent expiry rules, certificate plus version 1 in a transaction | Implemented; regression verified |
| Uploads | Required PDF extension, detected MIME, signature prefix and 10 MB application limit; private generated names | Partially satisfies genuine-PDF validation: malformed header-only content passes |
| Renewals | Parent row lock, latest-version locking read, next number, uploader metadata, transaction, new private file; historical records/files preserved | Implemented; SQLite transaction/lock-request tests pass; true MySQL concurrency pending |
| Version history | Highest version number is current; paginated metadata, protected current/historical preview and download | Implemented; regression verified |
| Expiry | Shared calendar-date calculator using configured timezone, exact thresholds and Unknown fallback; permanent never expired | Implemented; frozen-time/timezone/DST tests pass |
| Administrator dashboard | Live vessel/certificate/type counts, expiry counts, recent records and attention list | Implemented; regression verified |
| Client management | Server-assigned client role, hashed password, edit/status controls, client-only targets, assignment sync in transaction | Implemented; regression verified |
| Client portal | Assigned-only dashboard, vessels/certificates, search/pagination, current/history metadata and private PDFs; no administrative controls | Implemented; object-access tests pass |
| Revocation | Live pivot checks on each request; inactive users denied by role middleware | Implemented; stale-assignment and previously-authenticated deactivation tests pass |

Missing or not yet demonstrated capabilities:

- Password-reset/forgot-password pages and an administrative password-change action do not exist. The Laravel password broker configuration alone does not implement recovery.
- No scheduled expiry notifications, downloadable reporting, persistent operator audit trail, malware scanning, or automated DB/filesystem reconciliation was found. These are future capabilities rather than claims that earlier phases promised them.
- No browser automation, production deployment audit, backup/restore exercise, dependency-advisory audit, or true concurrent MySQL test was performed.

## C. Security findings

### Confirmed controls

- Login uses `Auth::attempt()` with `is_active = true`. Sessions regenerate on success; failed/inactive credentials share a generic error. Logout invalidates the session and regenerates its token.
- User privileges remain excluded from `Fillable`; web writes use validated fields or explicit server-side assignments. Client editing prohibits role, password, remember-token, and email-verification inputs. Administrator account targets are rejected.
- Administrator writes are protected server-side. Clients receive assignment-scoped database queries via `ClientRecords`; scoping is applied before resolving certificates or invoking the document responder. Historical versions are resolved through that certificate's relationship.
- The client portal does not expose internal remarks, uploader identities, password hashes, or raw document paths. Blade templates use escaped output; no raw `{!! ... !!}` output was found in application views.
- Laravel 13's web middleware includes `PreventRequestForgery`, with no application-level exemptions configured. Mutating Blade forms include `@csrf`; Livewire provides its own request integration. An isolated middleware probe with its unit-test bypass disabled rejected a missing token and accepted a valid token. Existing HTTP feature tests normally bypass CSRF, so their passing status is not browser-level CSRF verification.

### Findings requiring attention

| ID | Priority | Evidence and impact | Recommended action |
|---|---|---|---|
| SEC-01 | High before production seeding | `database/seeders/DatabaseSeeder.php` calls `UserFactory`, which supplies a predictable password. Schema defaults make this an active client. No such account was queried or claimed to exist in the live database. It initially has no assignments but can become a real access risk if assigned vessels. | Guard/remove demo seeding for production and inspect deployed accounts through an authorised administrative process. Do not run this seeder against company data. |
| SEC-02 | Medium | `StoreCertificateRequest.php:37–43` checks extension, MIME, size and `%PDF-`, but an isolated header-only malformed PDF passed all upload rules. Renewal inherits these rules. | Add structural parsing/validation or sanitisation as warranted, with malformed/polyglot fixtures. MIME and prefix checks do not establish complete PDF validity or absence of active content/malware. This did not demonstrate server-side code execution. |
| SEC-03 | Medium | Bcrypt is configured; create/login rules contain no byte-length policy. A local probe confirmed that a hash accepted a different suffix after byte 72. | Adopt an explicit compatible password byte limit with clear validation, or evaluate Argon2id and rehash migration. Preserve existing credentials; do not silently truncate inputs. Bound oversized login inputs as well. |
| SEC-04 | Medium, deployment gate High | Local configuration is `APP_ENV=local`, debug enabled, without an explicit secure-cookie setting. This is normal local development, not proof of an exposed production vulnerability. | Before deployment verify production/debug flags, HTTPS, Secure/HttpOnly/SameSite cookies, proxy trust, document-root isolation and permissions. Do not copy local flags unchanged. |
| SEC-05 | Medium, defence in depth | Dedicated certificates disk has `serve=false`, private visibility and no public links. However its root is beneath the separate `local` disk root, where `serve=true`; Laravel registers signed storage routes without role middleware. Unsigned access is rejected. No application code was found issuing such signed links. | Consider disabling unnecessary local-disk serving or separating roots so a future signed bearer link cannot bypass role/assignment revocation. No current unsigned PDF bypass was demonstrated. |
| SEC-06 | Medium | File cleanup is attempted after failed writes, but a process crash between file storage and DB commit, or a failed delete, can leave an orphan. Cleanup logs record only exception class. | Add private reconciliation/recovery and useful non-content correlation IDs. Preserve historical files; never blindly delete unreferenced paths during an audit. |
| SEC-07 | Low/Medium | Rate limit is email/IP-specific. There is no additional aggregate IP/account limit, MFA, or per-request password-size bound. A check followed by a hit also does not prove strict behaviour under simultaneous attempts. | Evaluate stronger limits and MFA against deployment risk; retain generic errors. Distributed/parallel brute-force behaviour is not verified by sequential tests. |

Deactivation currently denies protected requests while inactive; it does not permanently destroy all stored sessions. Reactivation can make a still-valid old session usable again. Decide whether permanent session revocation is required by company policy before treating this as a defect. Downloaded documents cannot be remotely revoked, and assignment removal cannot retract bytes already delivered.

## D. Database integrity findings

Migration definitions and SQLite-executed constraints provide:

- Unique user email, optional vessel IMO and certificate-type name.
- Unique `(user_id, vessel_id)` assignments and `(certificate_id, version_number)` version numbers.
- Restrictive vessel/type certificate foreign keys and certificate-version foreign key.
- Nullable creator/uploader references with null-on-user-delete; pivot rows cascade on user/vessel deletion.
- Matching Eloquent relationships, including `currentVersion()->ofMany('version_number', 'max')`.

Registration creates certificate/version 1 atomically. Renewal locks the parent certificate first, then reads the highest version with a locking read inside the same transaction, inserts exactly the next number, and updates the parent reference. It retries deadlocks up to three times, guards version-number overflow, and retains the unique index. Client updates lock the client row before account changes and assignment `sync()`.

Concerns and limits:

1. Live MySQL schema and storage-engine metadata were not queried; migration files are not proof that a deployed schema matches them. Real InnoDB lock contention, deadlocks, collation/uniqueness, SQL modes, date coercion and foreign-key behaviour remain integration gates.
2. Domain constraints such as role/status values, positive validity, minimum version 1 and fixed/permanent date consistency are principally application rules, not database CHECK constraints. Direct SQL/imports can bypass them. Future corrective migrations can be considered; existing migrations were left unchanged.
3. The certificate number is duplicated on the parent and version rows. Application renewal keeps it consistent, but future writers/imports must use the same transaction contract.
4. History is immutable through current HTTP routes, not through a database-level immutability policy. No application delete/replace route was found.
5. A disk write and a database transaction are not one atomic resource; ordinary rollback is tested, crash recovery is not implemented.

## E. Performance findings

Verified strengths: 15-row pagination for normal listings/history; eager loading of vessel, type and current version; correlated counts rather than per-vessel collection loads; bounded ten-record attention lists; and no per-certificate query pattern in the tested dashboard summary. The scoped summary regression test permits at most six queries for its single batch.

Concerns grounded in code:

- `CertificateDashboard.php:17` scans all in-scope certificates on every dashboard request, in batches of 500. Memory is bounded, but query work and model hydration still scale with total records. Roughly two scan queries per batch plus attention-fetch queries are required; exactly-full final batches can cause an additional empty-page check. This is not a constant-query dashboard for arbitrary data volume.
- All dashboards recompute counts separately and do not use one consistent read snapshot; simultaneous registration/renewal can momentarily make displayed totals and attention records disagree.
- `%search%` predicates and relationship `whereHas` searches can require broad scans. Actual MySQL EXPLAIN/latency measurements have not been run. Index design should follow measured representative workloads, not guesses.
- Registration and client-assignment forms fetch all vessels/types. Assignment selection is capped at 1,000 entries by validation, while the displayed options are unbounded.
- `SaveClientRequest.php:38` validates existence per vessel ID, which can issue one existence query per selected ID. A batched existence check is preferable at scale.
- Schema definitions do not explicitly index common sort/filter fields such as user role, vessel name/status or record creation time. Existing foreign-key/unique indexes help relationships, but production plans must be inspected before adding indexes.

## F. Automated test and code-quality results

| Check | Actual result |
|---|---|
| `php artisan test --log-junit=/tmp/glos-phase9a-junit.xml` | 197 tests, 1,188 assertions; 197 passed, 0 failed, 0 errors, 0 skipped |
| PHP syntax checks over application/routes/config/bootstrap/database/tests, excluding generated cache | 70 PHP files checked; 0 failures |
| `vendor/bin/pint --test` over repository | Failed: ten existing files listed below; no formatter changes applied |
| Additional PHPStan attempt | Version command reports 2.3.1; analysis exited 255 with no diagnostics, including a direct PHP retry. Static analysis could not be verified; no claim of success |

Pint failures:

- `app/Models/User.php`
- `app/Models/Vessel.php`
- `app/Models/CertificateType.php`
- `app/Models/CertificateVersion.php`
- `database/migrations/2026_10_27_160444_create_vessels_table.php`
- `database/migrations/2026_10_27_170834_create_certificate_types_table.php`
- `database/migrations/2026_10_27_171351_create_certificates_table.php`
- `database/migrations/2026_10_27_171939_create_certificate_versions_table.php`
- `database/migrations/2026_10_27_172518_create_vessel_user_access_table.php`
- `database/migrations/2026_10_27_174801_add_role_and_is_active_to_users_table.php`

Four model failures concern final newlines; migration failures also include indentation, braces, commas and class layout. Migrations were not edited to satisfy formatting.

Additional isolated probes, separate from the 197-test count:

- Guarded an in-memory SQLite connection before applying migrations; no persistent DB was involved.
- Header-only malformed PDF accepted: confirmed validation gap.
- Rejected array input redisplayed in vessel and certificate-type forms: both raised a TypeError at `old($field, ...)` output (`resources/views/admin/vessels/form.blade.php:15`, `resources/views/admin/certificate-types/form.blade.php:15`). They need robust old-input handling and regression tests. Client form has defensive handling already.
- Missing CSRF token rejected and valid token accepted with test bypass disabled.
- Different password suffix after bcrypt byte 72 accepted: confirmed.

The existing suite tests access controls, revocation, history preservation, failure rollback, rate limiting and expiry boundaries. It is not evidence of browser behaviour, strict parallel throttling, malware safety, crash recovery or actual MySQL concurrency. Tests were not changed to improve results.

## G. MySQL integration test status

**Pending — no safe dedicated environment is configured.**

`phpunit.xml:26–28` selects SQLite `:memory:` and an empty DB URL. No `.env.testing` or separate MySQL PHPUnit configuration exists. Local `.env` targets the development database. No MySQL migrations, destructive commands, database creation or database deletion were executed.

Safe proposed setup, not performed by this audit:

1. Report the intended provisioning operation first: a new, empty, disposable MySQL 8.4 schema/server for this run, with a test-only principal restricted to that schema. Do not reuse a database containing company records or existing test records. Do not give the test principal rights on `glos_vessel_manager`.
2. Ignore `.env.testing` before putting secrets in it: current `.gitignore` does not cover that filename. Keep credentials in an ignored local environment or CI secrets, never reports or tracked XML.
3. Add a separate `phpunit.mysql.xml` retaining the original SQLite configuration. Explicitly force `APP_ENV=testing`, the MySQL connection, the unique disposable database name and an empty `DB_URL`. Supply host/socket and credentials only through the isolated test environment. Account for externally supplied environment variables and cached configuration.
4. Add a fail-closed guard before `RefreshDatabase` or any migration: verify the **effective** driver, database name and isolated host, assert an approved disposable database name, reject the development name, and verify the schema is empty. Environment labels alone are insufficient.
5. Review SQLite-specific test fixtures rather than weakening expectations: `CertificateRenewalTest` installs `SQLiteGrammar`; `ClientManagementTest` matches a double-quoted SQLite INSERT to simulate failure; `CertificateExpiryTest` writes invalid date text that strict MySQL DATE columns should reject. Preserve equivalent platform-appropriate assertions and isolate genuinely SQLite-specific tests.
6. Only after those safeguards run portable HTTP/storage/integrity tests on the disposable schema, plus true concurrent renewals over separate MySQL connections/processes. Test InnoDB lock order, consecutive numbering, unique conflicts, deadlock retries and file compensation; do not wrap the entire concurrency exercise in one connection's test transaction.
7. Any eventual cleanup/drop of the disposable database must be reported and limited to that new schema. No provisioning or cleanup operation has been authorised or executed merely by this report.

The existing SQLite XML has no `force=true` attributes or runtime database-safety assertion. A shell/CI override or cached configuration could defeat the intended defaults. That is a high-priority test-safety improvement before enabling MySQL tests.

## H. Outstanding limitations and UX/maintainability

- No identified access-control bypass was demonstrated in the reviewed current routes/tests. This is a scoped conclusion, not a guarantee that the application has no vulnerabilities.
- File signature checks are not a structural PDF parser or malware scan. PDF viewer behaviour, active content and deployment headers need browser/security review.
- CLI upload/post limits were both reported as 2 MB in this environment, below the application's 10 MB file rule. Web/FPM and reverse-proxy limits were not measured; verify all layers before claiming browser uploads up to 10 MB work. The whole POST limit needs room above the file limit.
- Application timezone resolves to UTC. Calculations correctly follow configuration, but the company should explicitly confirm the desired business timezone.
- Public welcome content remains starter-kit material. Password recovery is absent; inactive users can encounter a forbidden dashboard until signing out. Unknown-expiry records are counted separately rather than included in the dated attention list.
- No browser accessibility/responsiveness regression or production asset-build test was run. A local Vite hot marker is present; that is not proof that the dev server or production asset deployment is healthy.
- Upload/write/cleanup orchestration is duplicated in registration and renewal controllers. Layout shell/navigation, pagination and search handling also repeat. Shared expiry logic, validation/form fields, query scoping and PDF serving are positive reuse points.
- Client forms reuse CSS named `vessel-*`; client pages include an expiry partial in the administrator view tree. These are naming/coupling concerns, not authorisation bypasses.
- Broad `Throwable` catches prevent technical errors reaching forms but reduce diagnostic precision; cleanup logs lack correlation identifiers. Repeated full data scans and lack of recovery workflows matter more than cosmetic refactoring.
- Package lockfiles exist, but an online dependency vulnerability/advisory scan was not performed. No packages were installed or updated.

## I. Prioritised recommendations

**Critical:** No confirmed critical exploit found in this audit. Do not treat passing SQLite tests as production certification.

**High:**

1. Establish fail-closed disposable MySQL test isolation before any MySQL regression run; verify actual concurrent renewal and database behaviour.
2. Guard/remove predictable demo-account seeding before production use; do not assert or change live accounts without an authorised review.
3. Treat production debug/TLS/cookie/storage configuration as a release gate. Current local settings should remain development-only.

**Medium:**

1. Strengthen PDF validation and evaluate sanitisation/scanning according to document risk.
2. Resolve bcrypt's 72-byte semantics with a deliberate compatible password policy or algorithm migration.
3. Fix confirmed malformed-old-input form crashes and add permanent regression tests.
4. Provide DB/filesystem reconciliation and useful protected diagnostics for failed cleanup/crashes.
5. Measure and optimise dashboard scans, selectors, assignment existence validation and search plans at realistic volumes.
6. Decide password recovery, session-revocation and operator audit-trail requirements.
7. Separate the private certificate tree from unnecessary signed local-file serving where practical.
8. Restore the configured PHPStan check and add browser/CSRF integration coverage.

**Low:**

1. Correct existing model formatting; handle migration formatting only in a separately agreed change respecting migration history.
2. Consolidate duplicated storage orchestration/layout components after security and integration gates are addressed.
3. Replace starter welcome content, confirm timezone and production assets, and improve inactive-session/error recovery UX.

No finding in this report is represented as fixed. Findings should be addressed in concrete, separately reviewed changes with tests.

## J. Files inspected

See [the source/configuration/test inventory](phase-9a-files-inspected.txt). The review covered application models, all controllers/requests/middleware/support classes, login/admin command, routes, relevant configuration, all migrations, factories/seeder, existing feature/unit tests, layouts/views, frontend configuration and manifests. Views were also scanned for raw output and form protection. Dependency manifests/lockfiles were inventory inputs, not an advisory analysis.

Environment files were inspected only through a redacted extractor for environment/test-safety flags. Their credentials and contents are not reproduced. Generated JUnit and temporary probe outputs were kept outside the source tree under `/tmp`; no application test or implementation file was added or modified for these probes.
