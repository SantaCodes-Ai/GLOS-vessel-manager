# Controlled committed-fixture lifecycle — Phase 14B-5B

Implementation and mock verification only. No MySQL connection, fixture creation, live
worker execution or native cleanup was performed during this phase. Application business
logic, credentials, MySQL privileges, migrations and PDFs are unchanged.

## Inspection findings and design

The previous harness already retained the existing file and server advisory mutexes,
verified the prepared schema, authorised a bounded independent worker and journalled exact
row fingerprints. It lacked fixture creation, durable commit-state tracking and a worker
guard for known committed rows. The ordinary MySQL test suite must remain rollback-only.

The new entry point permits exactly one scenario, `fixture-lifecycle-v1`, using the restricted
runner profile and only `glos_vessel_manager_test`. The existing Guard, Profile, Runtime,
MySqlTestCase, ownership marker, schema manifest and test configurations are unchanged.
The coordinator retains both mutexes until the entire command exits. Another ordinary
suite, preparation process or coordinator cannot run simultaneously through those guards.

## Proposed live command — not executed, separate approval required

```sh
php scripts/mysql-concurrency.php --fixture-lifecycle \
  --confirm-fixture-lifecycle=glos_vessel_manager_test \
  --operator="Ivan"
```

The write/cleanup confirmation flag must exactly name the test database. Missing confirmation
is rejected before reading the private profile or opening a connection. Exactly one mode
may be chosen; this cannot be combined with `--probe` or `--inspect`. Private profile,
ambient-override, cached-configuration and scoped-grant restrictions still apply. Do not
change `.env` or weaken guards to make this command run.

This flag is an explicit operational confirmation, not an interactive prompt. Do not run
it until separately approved. There are no business races, arbitrary fixture definitions,
worker commands, automatic recovery commands or uncontrolled parallel workers.

## Expected records

The clean test baseline is required before insertion. The coordinator inserts exactly:

1. One fictional user named `Concurrency <random-run-id>`, email
   `fixture+<random-run-id>@example.test`, role `client`, inactive, unverified, pending,
   requested role `client`. It receives a randomly generated unusable-in-practice password
   hash; its password is never displayed or journalled. It is not an administrator.
2. One vessel named `Concurrency <random-run-id>`, type `Test Fixture`, flag `TEST`, inactive,
   null IMO and official numbers.
3. One `vessel_user_access` row linking those two new primary IDs.

No certificates, versions, PDFs, audit records, sessions, cache entries, jobs, emails or
notifications are created. No Laravel application or model observers are booted. SQL is
fixed, parameterised and executed only through the dedicated restricted runner. There is
no public input for role, account status, record IDs or arbitrary SQL.

## Transaction and journal protocol

The coordinator verifies identity, grants, ownership, migration/schema fingerprint, protected
baseline rows and suite mutex ownership before inserting. It uses one InnoDB transaction
with a fixed three-second session lock-wait bound. The journal is in a private random run
directory, mode 700; files are mode 600 and intentionally retained.

Before insertion the journal persists the scenario, run-specific fictional name/email and
expected record count. Each INSERT is followed by an exact-ID read and durable claim of
the generated primary ID and full-row fingerprint. Passwords, password hashes and raw rows
are not written to the journal. All three rows and their relationships are validated before
commit. These states are recorded using atomic file replacement and file fsync:

`planned → prepared → commit-attempted → committed → observed → cleaned`

`commit-attempted` is persisted before calling COMMIT. `committed` means the call returned
success and its acknowledgement was journalled. A commit error, lost acknowledgement or
post-commit journal failure is uncertain and never authorises automatic cleanup. A failure
before COMMIT can be classified `rolled-back` only when rollback was acknowledged; even
then the scenario stops and retains its journal. There are no transaction retries.

The journal is not a second database transaction. OS/storage failure can still leave a
partial or stale filesystem journal. Do not infer missing database records solely from a
journal state or a lost connection. No parent rollback can undo a worker/connection commit.

## Independent worker verification

After acknowledged commit, exactly one independent read-only PHP worker runs under the
existing private stdin capability, expiring lease, living coordinator PID and server-mutex
owner checks. Credentials are read from the private runner profile, never passed in argv,
lease or output. Input and process deadlines remain two and ten seconds respectively.

The action must match the coordinator lease. The original empty-baseline probe still calls
the existing clean guard unchanged. Only `fixture-probe` may use the new run-scoped guard:
it rechecks all existing identity/ownership/schema protections, then requires exactly one
user, one vessel and one assignment, with matching IDs/fingerprints, the fixed safe field
values, and owned parent relationships. All other business tables must remain empty;
infrastructure baseline rows remain protected. This guard cannot admit arbitrary fixtures.

The worker performs no writes. The coordinator verifies the same fixture again after the
worker stops. Failed/timed-out workers or changed/unknown rows stop the scenario, retain the
journal and committed fixtures, and prevent automatic cleanup.

## Controlled cleanup

Only the `observed` lifecycle state permits scenario cleanup. The existing exact snapshot,
worker-stop, private-storage, symlink, ownership and mutex checks remain in force. The
native adapter repeats the full business-row snapshot under locks in a new transaction.
Deletes use exact journal-confirmed IDs and preserve FK checks. For this scenario the order
is assignment, vessel, user. No broad delete, truncate, reset, counter adjustment, migration,
privilege change, or deletion of ownership/migration/privilege-lock records is permitted.

The baseline is verified before cleanup commit and again afterwards. Cleanup success and
final lifecycle state are journalled. An unknown or modified record refuses cleanup; an
uncertain cleanup commit or final verification failure stops for manual review. The command
does not automatically rerun cleanup, repair data or adopt previously stranded fixtures.
Existing guards will refuse a new run if an earlier run left business rows behind.

## Failure limitations and review

After acknowledged fixture commit, a worker failure or process crash can intentionally
leave three test-owned records. A lost commit response may leave zero or three records;
InnoDB atomicity does not make the client acknowledgement certain. Partial pre-commit work
must roll back on the owning transaction/connection, not by deleting guessed IDs. Never
run schema preparation, resets or unscoped cleanup to recover. Retain the private journal
and obtain separately reviewed exact-record recovery instructions.

A successful lifecycle can advance auto-increment counters even after the baseline is
restored. Gaps are expected; counters must not be reset. This verifies fixture visibility
and cleanup, not business concurrency or crash recovery. Protection against malicious code
running with the same OS identity/private credentials is outside this harness's boundary.

No previously decided behaviour changes are introduced: in-flight administrator revocation,
certificate deduplication/idempotency and Staff last-writer-wins semantics are unchanged.

## Actual automated verification

- New lifecycle tests: **17 passed, 529 assertions**.
- Combined lifecycle, concurrency, MySQL safety and isolated-environment tests:
  **135 passed, 825 assertions**.
- Complete SQLite/mock regression suite: **635 passed, 3,842 assertions**.
- PHP syntax: all **10** changed/new PHP files passed.
- Changed-file Laravel Pint: passed.
- MySQL integration tests and live coordinator/worker commands: **not executed**.
  Live commit visibility, native cleanup and actual post-run baseline remain pending approval.

## File inventory

Created:

- `tests/Support/MySql/Concurrency/FixtureSet.php`
- `tests/Unit/MySqlFixtureLifecycleTest.php`
- `docs/mysql-concurrency-fixture-lifecycle.md`

Modified:

- `scripts/mysql-concurrency.php`
- `scripts/mysql-concurrency-worker.php`
- `tests/Support/MySql/Concurrency/Backend.php`
- `tests/Support/MySql/Concurrency/Coordinator.php`
- `tests/Support/MySql/Concurrency/Journal.php`
- `tests/Support/MySql/Concurrency/NativeBackend.php`
- `tests/Support/MySql/Concurrency/Worker.php`
- `tests/Unit/MySqlConcurrencySafetyTest.php` (cleanup commit acknowledgement mock and
  the existing worker-action compatibility assertion updated for the new fixed action)
- `docs/mysql-concurrency-harness.md` (historical-phase notice linking these instructions)
