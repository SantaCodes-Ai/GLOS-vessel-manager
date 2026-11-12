# Phase 14B-2 — MySQL smoke-test environment warning

## Confirmed cause

Runtime::application previously called loadEnvironmentFrom with a deliberately nonexistent, random .env.mysql-runtime-disabled-* filename to isolate tests from the application's .env.

That selects a filename; it does not disable Laravel's LoadEnvironmentVariables bootstrapper. The bootstrapper still calls phpdotenv's safeLoad, whose Reader attempts file_get_contents. Although the library tolerates a missing file, the underlying file-read warning was recorded by PHPUnit.

A regression test reproduced the exact warning before this fix, both without an application environment file and with a poisoned dummy environment file.

## Exact fix

Only the isolated Runtime application binds Laravel's LoadEnvironmentVariables bootstrapper to the new test-only LoadIsolatedEnvironment implementation.

The implementation checks the already-installed test environment and existing ambient/cache safeguards, then performs no dotenv reads. Runtime still obtains its credentials from the explicitly validated private profile before application bootstrap. No missing placeholder file or empty secret-bearing environment file is needed.

The normal company application bootstrap and .env loading remain unchanged. No vendor code, global warning/error suppression, MySQL identity/ownership/grant guard, migration fingerprint, transaction lifecycle or private profile was changed.

## Files

Modified:
- tests/Support/MySql/Runtime.php

Created:
- tests/Support/MySql/LoadIsolatedEnvironment.php
- tests/Unit/MySqlEnvironmentBootstrapTest.php
- docs/mysql-smoke-environment-warning-fix.md

tests/MySqlTestCase.php, tests/mysql-bootstrap.php and phpunit.mysql.xml were inspected and required no change.

## Regression coverage

The new environment-only kernel exercises the actual Runtime application/bootstrap chain without providers or database access. A temporary local error handler turns even suppressed file-read warnings into exceptions and is restored afterward.

Two scenarios verify:
- No environment file: application bootstrap succeeds without a file-read warning.
- Poisoned dummy application .env: its development-database/root-user settings and sentinel cannot enter the test runtime.

Assertions check testing environment, exact private-profile connection, only mysql_testing registered, correct test database and no development connection. Temporary fixtures contain dummy values, no real secrets; environment/container/facade state and the error handler are restored.

Existing safety tests continue covering incorrect/development database names, missing/invalid profiles, cached configuration, actual server identity, URL/socket overrides, grants, ownership markers, baseline drift and accidental migration traits.

## Actual verification

- Targeted safety group: **75 tests passed, 161 assertions**; no warnings reported.
- Default SQLite/mock regression suite: **575 tests passed, 3,178 assertions**.
- Prepared MySQL infrastructure smoke: **1 test passed, 3 assertions, no warnings reported**.
- PHP syntax: **119 files checked, zero failures**.
- Changed-file Laravel Pint: **passed** for the three changed/new PHP files.

Commands:

    php artisan test --group=mysql-safety --display-warnings
    php artisan test
    vendor/bin/pest --configuration=phpunit.mysql.xml --display-warnings
    vendor/bin/pint --test tests/Support/MySql/Runtime.php tests/Support/MySql/LoadIsolatedEnvironment.php tests/Unit/MySqlEnvironmentBootstrapTest.php

The first MySQL attempt was blocked by the filesystem/network sandbox with SQLSTATE 2002, Operation not permitted. The same authorised command succeeded outside the sandbox. This was an execution-permission restriction, not an application/database configuration failure.

## Safety and limitations

No migrations, schema preparation, resets, truncation, account/privilege changes or application-data modifications were performed. The smoke test checks the existing prepared schema and transaction state; it does not insert application records. Its existing ownership, schema, grant and clean-baseline checks passed.

Private credentials/tokens and the company .env were neither displayed nor edited. Existing certificates, PDF files, branding, role permissions and application logic remain unchanged.

The environment warning is resolved. The MySQL result covers the infrastructure smoke test only; it is not a claim that the full business/concurrency suite has run against MySQL.
