# Phase 14B-1 — Isolated MySQL integration infrastructure

Implemented 10 November 2026. **Infrastructure only: no MySQL connection, database/account creation, migration, reset, preparation-script invocation or MySQL integration test was performed.**

## Profiles and isolation

The original phpunit.xml, tests/TestCase.php, tests/Pest.php, application configuration/code and historical migrations remain unchanged. The 500 existing tests retain the in-memory SQLite guard and existing lifecycle.

phpunit.mysql.xml is a separate suite containing only tests/Integration/MySql. Its bootstrap requires an explicit private profile, forces APP_ENV=testing and fixed database identity settings, and rejects unsafe configuration before any connection. Running a MySQL test through the ordinary SQLite profile fails because its dedicated bootstrap is absent.

No real credential file was created. .env.testing.example is a credential-free template. Actual files are:

| Purpose | Private filename | Required account |
| --- | --- | --- |
| Ordinary integration tests | .env.testing.mysql | glos_test_runner |
| Explicit initial schema preparation | .env.testing.mysql.schema | glos_test_schema |

Both files must be regular, readable, non-symlink files with mode 600 and no group/other permissions. Files over 16 KiB or unknown keys are rejected. Both use these exact values:

    APP_ENV=testing
    MYSQL_TEST_HOST=127.0.0.1
    MYSQL_TEST_PORT=3306
    MYSQL_TEST_DATABASE=glos_vessel_manager_test
    MYSQL_TEST_USERNAME=glos_test_runner
    MYSQL_TEST_PASSWORD=
    MYSQL_TEST_OWNERSHIP_TOKEN=

The schema profile changes only MYSQL_TEST_USERNAME to glos_test_schema and uses that account's separate password. Each password must be at least 16 characters. The ownership token must be the same privately generated random 64-character lowercase hex value in both files. Blank template values fail closed. Supply secrets privately; do not commit them or place them in shared logs/reports.

.gitignore now excludes .env.testing, .env.testing.* and the local mutex, while retaining .env.testing.example. Profiles are parsed directly; ambient MYSQL_TEST variables do not replace file values. Laravel deliberately loads neither the application .env nor either private profile into its normal dotenv loader. The runtime receives only the selected account's settings and a generated test-only application key.

## Implemented guards

Before application bootstrap, the runner verifies its private profile, absence of cached configuration, exact database/host/port, dedicated account and approved scoped grants. It then requires a recognised prepared schema before allowing application tests to start.

Checks include:

- Development database glos_vessel_manager and every other schema name are rejected.
- APP_ENV must be testing; nonempty DB_URL, DATABASE_URL, DB_SOCKET, custom configuration-cache paths and read/write connection overrides are refused.
- Cached bootstrap/cache/config.php is rejected rather than silently cleared.
- Actual PDO driver, DATABASE(), CURRENT_USER(), server port, MySQL 8.4 version and server UUID are checked.
- The connected account must be glos_test_runner@127.0.0.1 or, only for explicit preparation, glos_test_schema@127.0.0.1.
- SHOW GRANTS must contain only the approved exact-schema privileges plus ordinary USAGE. Root/global grants, wildcard schema access, roles, extra privileges and GRANT OPTION are rejected.
- All expected tables must be present, with no extra tables/views and all using InnoDB.
- The migration names and a SHA-256 manifest of migration filenames/content must match.
- Exactly one ownership-marker row must match the private token hash, server UUID, protocol version and fingerprints.
- The prepared schema fingerprint covers columns, indexes and foreign-key metadata.
- Before/after each test, application tables must be empty except for migrations, the pre-existing privilege-lock sentinel and the ownership marker. Unexpected records cause refusal, never automatic deletion.
- Before each ordinary DML statement, effective Laravel configuration, identity, marker and baseline are rechecked. DDL, USE, SET, manual COMMIT and multiple SQL statements are rejected.
- Only mysql_testing is registered as an application database connection; other persistent connection names cannot fall back to development settings.

The marker table mysql_test_ownership is test-only and is created by the explicit preparation script, not an application migration.

## Lifecycle and side effects

MySqlTestCase starts a managed transaction for DML-only tests and rolls it back in teardown. It uses Laravel's testing transaction manager for nested/application transaction semantics. Attempting to commit the outer managed transaction through Laravel is blocked. Loss of the PDO transaction fails teardown and requires manual review.

RefreshDatabase, LazilyRefreshDatabase, DatabaseMigrations, DatabaseTruncation, an additional DatabaseTransactions trait and cached-config test traits are prohibited. No migration/refresh/truncation operation runs automatically.

Mail and notifications are faked. Mailers, queues, cache and sessions are restricted to in-memory/array/synchronous test facilities. File disks and compiled views use a private random temporary directory, never company certificate storage or public assets; that process-owned directory is removed at shutdown.

A local exclusive file mutex and a fixed zero-wait MySQL advisory mutex prevent overlapping suite/preparation runs, including separate checkouts on the same server. The advisory lock is taken only after database/account/grant verification, and its control connection is retained until process exit. Generic parallel-testing flags/tokens are refused; automatic parallel database recreation is not supported.

## Required accounts and privileges — future setup only

**Database/user setup remains unauthorised and was not executed.** Once separately approved, a database administrator should create a new empty glos_vessel_manager_test schema using utf8mb4/utf8mb4_unicode_ci and the exact TCP accounts above.

Required database-level permissions:

| Account | Permitted privileges on the exact test schema |
| --- | --- |
| glos_test_schema | SELECT, INSERT, CREATE, ALTER, INDEX, REFERENCES |
| glos_test_runner | SELECT, INSERT, UPDATE, DELETE |

Neither receives DROP, global privileges, FILE, GRANT OPTION or privileges on glos_vessel_manager. Existing users must not be repurposed. The stricter schema account intentionally rejects the broader grant set from the earlier planning discussion.

Review @@global.partial_revokes before granting privileges. With it OFF, escape the underscores in the database-level grant identifier; with it ON, use the literal identifier. Do not change the server setting for testing. See [MySQL GRANT documentation](https://dev.mysql.com/doc/refman/8.4/en/grant.html).

Example grant statements for partial_revokes=OFF, **not executed**:

    GRANT SELECT, INSERT, CREATE, ALTER, INDEX, REFERENCES
    ON `glos\_vessel\_manager\_test`.*
    TO 'glos_test_schema'@'127.0.0.1';

    GRANT SELECT, INSERT, UPDATE, DELETE
    ON `glos\_vessel\_manager\_test`.*
    TO 'glos_test_runner'@'127.0.0.1';

If either account/schema already exists, stop for review; do not adopt, reset, change or populate it automatically. Verify actual grants before preparation.

## Schema preparation — do not run until separately approved

After database/account creation is authorised and completed:

1. Copy the template privately to the two filenames above, set the correct account/password/token in each and apply chmod 600. Do not copy the application .env.
2. Verify that the intended schema is new and empty, its server/port/version are correct and its dedicated grants match.
3. From an interactive terminal, explicitly invoke:

       php scripts/prepare-mysql-tests.php --prepare --operator="accountable-operator"

4. Type the exact confirmation:

       PREPARE glos_vessel_manager_test on 127.0.0.1:3306

The script requires the schema profile and operator confirmation before connection, verifies identity and exclusive locks, rejects every schema containing tables/views, then calls only ordinary migrate on the isolated mysql_testing connection. It never creates/drops databases, runs migrate:fresh or resets/truncates a schema.

After migration, it creates the marker table, checks the exact table/migration baseline and expected initial row counts, and records the ownership hash, migration/schema fingerprints, server UUID, operator and preparation timestamp. Success is recorded only after these checks pass.

MySQL DDL is not treated as transactionally reversible. Any preparation failure can leave a partial **test** schema; the script reports failure and never automatically repairs/drops it. The missing/unverified marker prevents subsequent test execution. Retry/recovery requires explicit operator review and, if necessary, separately approved schema work. Re-running preparation against an already prepared schema is intentionally refused.

## Verification performed

Only the default SQLite profile and mock-based safety tests were executed:

- Safety group: **73 tests passed, 145 assertions**; no persistent database connection is used.
- Combined default regression suite: **573 passed, 3,162 assertions**. This includes the existing 500 tests/3,017 assertions and the 73 new safety tests.
- PHP syntax: **117 files checked, zero failures**.
- Changed-file Laravel Pint: **passed** for all 12 new PHP files.
- phpunit.mysql.xml validated against the installed PHPUnit XML schema.
- Actual private profiles and .mysql-testing.lock remain absent.

Commands executed:

    php artisan test --group=mysql-safety
    php artisan test

The safety tests cover wrong/development database names, host/port/account overrides, missing/private profile requirements, URL/socket/cache/parallel overrides, actual server identity, unrecognised tables, missing/duplicate/forged markers, changed migration/schema fingerprints, non-InnoDB tables, unexpected records, excessive/wildcard grants, unmanaged/DDL/multi-statement SQL, accidental migration traits, unsafe/cancelled preparation, preparation failure, mocked successful marker creation, absent dedicated bootstrap and a mocked advisory mutex.

No MySQL integration/profile command and no preparation-script invocation (including help) was executed.

## Files created

- phpunit.mysql.xml
- .env.testing.example
- tests/mysql-bootstrap.php
- tests/MySqlTestCase.php
- tests/Support/MySql/UnsafeMySqlEnvironment.php
- tests/Support/MySql/Profile.php
- tests/Support/MySql/Manifest.php
- tests/Support/MySql/DatabaseProbe.php
- tests/Support/MySql/Guard.php
- tests/Support/MySql/Preparation.php
- tests/Support/MySql/Runtime.php
- tests/Integration/MySql/InfrastructureSmokeTest.php
- tests/Unit/MySqlSafetyTest.php
- scripts/prepare-mysql-tests.php
- docs/mysql-integration-infrastructure.md

Modified: .gitignore only. No existing application PHP/configuration, SQLite test configuration/guard, migration, credential, role, account, PDF or business/audit record was modified.

## Remaining setup and limitations

- Obtain explicit approval before creating accounts/database, preparing/migrating the test schema or running MySQL integration tests.
- Use only the guarded entrypoints. Ordinary php artisan migrate (including --env=testing) and composer setup do not load these private profiles/guards and must not be used for MySQL test preparation.
- Verify actual server version, authentication-host matching, grants, metadata fingerprint stability and migration compatibility against the approved isolated server. These paths are mock-tested, not claimed live-verified.
- After approved preparation, the future suite command is vendor/bin/pest --configuration=phpunit.mysql.xml. **It was not run.**
- The MySQL directory currently contains an infrastructure smoke test, not the full business integration/concurrency matrix. Port database-sensitive cases deliberately; retain SQLite-specific grammar/PRAGMA/invalid-date tests in the existing suite.
- Controlled multi-process concurrency tests need a separate reviewed harness and committed disposable fixtures. Ordinary parallel runs remain prohibited.
- This is protection against accidental misconfiguration, not a sandbox against arbitrary malicious PHP or privileged database operators. Direct raw-PDO commits/writes are outside the supported test lifecycle; a lost transaction or dirty baseline causes failure and manual review, not an automatic reset.
- Prepared schemas intentionally become invalid after migration-source/schema drift. Updating one requires a separately reviewed preparation/update plan; the runner never repairs it.
- Required qpdf availability from Phase 14A remains applicable to later PDF integration tests. CI/service-container provisioning is not implemented in this phase.
