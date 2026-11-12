<?php

namespace Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Tests\Support\MySql\Concurrency\Backend;
use Tests\Support\MySql\Concurrency\Coordinator;
use Tests\Support\MySql\Concurrency\FixtureSet;
use Tests\Support\MySql\Concurrency\Journal;
use Tests\Support\MySql\Concurrency\NativeBackend;
use Tests\Support\MySql\Concurrency\Worker;
use Tests\Support\MySql\Guard;
use Tests\Support\MySql\Profile;
use Tests\Support\MySql\UnsafeMySqlEnvironment;

final class MySqlFixtureLifecycleTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private array $directories = [];

    private function journal(): Journal
    {
        $journal = new Journal('Fictional lifecycle operator');
        $this->directories[] = $journal->directory;

        return $journal;
    }

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            (new Filesystem)->deleteDirectory($directory);
        }
        parent::tearDown();
    }

    private function fixtureRows(Journal $journal): array
    {
        $rows = array_fill_keys(NativeBackend::TABLES, []);
        $rows['users'][11] = ['id' => 11, 'name' => 'Concurrency '.$journal->run, 'email' => 'fixture+'.$journal->run.'@example.test',
            'password' => 'fictional-hash-not-a-credential', 'role' => 'client', 'is_active' => 0, 'email_verified_at' => null,
            'registration_status' => 'pending', 'requested_role' => 'client'];
        $rows['vessels'][22] = ['id' => 22, 'vessel_name' => 'Concurrency '.$journal->run, 'vessel_type' => 'Test Fixture',
            'flag_state' => 'TEST', 'vessel_status' => 'inactive', 'imo_number' => null, 'official_number' => null];
        $rows['vessel_user_access'][33] = ['id' => 33, 'user_id' => 11, 'vessel_id' => 22];

        return $rows;
    }

    private function recordFixture(Journal $journal, array $rows): void
    {
        $journal->planFixture();
        foreach (['users', 'vessels', 'vessel_user_access'] as $table) {
            $journal->claim($table, reset($rows[$table]));
        }
        $journal->lifecycle('prepared');
        $journal->lifecycle('commit-attempted');
        $journal->lifecycle('committed');
    }

    /** Fake PDO only: no profile file, MySQL socket, application bootstrap or SQL execution. */
    private function native(Journal $journal, string $failure = ''): array
    {
        $rows = $this->fixtureRows($journal);
        $state = (object) ['rows' => array_fill_keys(NativeBackend::TABLES, []), 'tx' => false, 'last' => '',
            'commits' => 0, 'rollbacks' => 0, 'deletes' => [], 'baseline_checks' => 0, 'mutex' => 71];
        $pdo = Mockery::mock(PDO::class);
        $guard = Mockery::mock(Guard::class);
        $guard->shouldReceive('prepared')->andReturnUsing(function ($profile, $probe, $clean) use ($state) {
            if ($clean) {
                $this->assertSame(0, array_sum(array_map('count', $state->rows)));
                $state->baseline_checks++;
            }
        });
        $profile = Profile::fromValues(['APP_ENV' => 'testing', 'MYSQL_TEST_HOST' => '127.0.0.1', 'MYSQL_TEST_PORT' => '3306',
            'MYSQL_TEST_DATABASE' => Profile::DATABASE, 'MYSQL_TEST_USERNAME' => 'glos_test_runner',
            'MYSQL_TEST_PASSWORD' => str_repeat('x', 24), 'MYSQL_TEST_OWNERSHIP_TOKEN' => str_repeat('a', 64)]);
        $pdo->shouldReceive('inTransaction')->andReturnUsing(fn () => $state->tx);
        $pdo->shouldReceive('lastInsertId')->andReturnUsing(fn () => $state->last);
        $pdo->shouldReceive('exec')->with('SET SESSION innodb_lock_wait_timeout = 3')->andReturn(0);
        $pdo->shouldReceive('beginTransaction')->andReturnUsing(function () use ($state) {
            $this->assertFalse($state->tx);
            $state->before = $state->rows;
            $state->tx = true;

            return true;
        });
        $pdo->shouldReceive('rollBack')->andReturnUsing(function () use ($state) {
            $state->rollbacks++;
            $state->rows = $state->before;
            $state->tx = false;

            return true;
        });
        $pdo->shouldReceive('commit')->andReturnUsing(function () use ($state, $journal, $failure) {
            $this->assertTrue($state->tx);
            $state->commits++;
            if ($state->commits === 1) {
                $data = json_decode(file_get_contents($journal->directory.'/journal.json'), true);
                $this->assertSame('commit-attempted', $data['lifecycle']);
                $this->assertCount(3, $data['fixtures']);
                $this->assertCount(1, $data['fixtures']['vessel_user_access']);
                $this->assertSame('fixture+'.$journal->run.'@example.test', $data['plan']['email']);
                $this->assertStringNotContainsString('fictional-hash', json_encode($data));
                if ($failure === 'commit-rejected') {
                    throw new \RuntimeException('Simulated commit rejection');
                }
            }
            $state->tx = false;
            if ($failure === 'commit-lost' && $state->commits === 1) {
                throw new \RuntimeException('Simulated lost commit acknowledgement');
            }

            return true;
        });
        $pdo->shouldReceive('query')->andReturnUsing(function ($sql) use ($state) {
            $statement = Mockery::mock(PDOStatement::class);
            if ($sql === "SELECT IS_USED_LOCK('glos_vessel_manager_test:integration-suite')") {
                $statement->shouldReceive('fetchColumn')->andReturn($state->mutex);
            } elseif ($sql === 'SELECT id FROM privilege_management_locks ORDER BY id') {
                $statement->shouldReceive('fetchAll')->with(PDO::FETCH_COLUMN)->andReturn([1]);
            } elseif (preg_match('/\ASELECT COUNT\(\*\) FROM `(cache|cache_locks|failed_jobs|job_batches|jobs|password_reset_tokens|sessions)`\z/', $sql)) {
                $statement->shouldReceive('fetchColumn')->andReturn(0);
            } else {
                $this->assertMatchesRegularExpression('/\ASELECT \* FROM `([a-z_]+)` ORDER BY id(?: FOR UPDATE)?\z/', $sql);
                preg_match('/FROM `([a-z_]+)`/', $sql, $match);
                $table = $match[1];
                $this->assertContains($table, NativeBackend::TABLES);
                $withoutId = $state->rows[$table];
                foreach ($withoutId as &$row) {
                    unset($row['id']);
                }
                unset($row);
                $statement->shouldReceive('fetchAll')->with(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC)->andReturn($withoutId);
            }

            return $statement;
        });
        $pdo->shouldReceive('prepare')->andReturnUsing(function ($sql) use ($state, $rows, $failure, $journal) {
            $statement = Mockery::mock(PDOStatement::class);
            if (preg_match('/\AINSERT INTO (users|vessels|vessel_user_access) /', $sql, $match)) {
                $table = $match[1];
                $statement->shouldReceive('execute')->andReturnUsing(function ($values) use ($state, $rows, $table, $failure, $journal) {
                    $this->assertTrue($state->tx);
                    $this->assertSame('planned', $journal->stage());
                    if ($table === 'vessels' && $failure === 'insert-fails') {
                        throw new \RuntimeException('Simulated insert failure');
                    }
                    $row = reset($rows[$table]);
                    if ($table === 'users') {
                        $this->assertSame($row['name'], $values[0]);
                        $this->assertSame($row['email'], $values[1]);
                        $this->assertStringStartsWith('$2y$', $values[2]);
                    }
                    if ($table === 'vessel_user_access') {
                        $this->assertSame([11, 22], $values);
                    }
                    $state->rows[$table][$row['id']] = $row;
                    $state->last = (string) $row['id'];

                    return true;
                });
            } elseif (preg_match('/\ASELECT \* FROM `(users|vessels|vessel_user_access)` WHERE id = \?\z/', $sql, $match)) {
                $table = $match[1];
                $statement->shouldReceive('execute')->with([array_key_first($rows[$table])])->andReturn(true);
                $statement->shouldReceive('fetch')->with(PDO::FETCH_ASSOC)->andReturnUsing(fn () => reset($state->rows[$table]));
            } else {
                $this->assertMatchesRegularExpression('/\ADELETE FROM `([a-z_]+)` WHERE id = \?\z/', $sql);
                preg_match('/FROM `([a-z_]+)`/', $sql, $match);
                $table = $match[1];
                $this->assertContains($table, NativeBackend::TABLES);
                $statement->shouldReceive('execute')->andReturnUsing(function ($ids) use ($state, $table) {
                    $this->assertTrue($state->tx);
                    $this->assertCount(1, $ids);
                    if (in_array($table, ['vessels', 'users'], true)) {
                        $this->assertSame([], $state->rows['vessel_user_access'], 'FK child must be deleted first.');
                    }
                    $this->assertArrayHasKey($ids[0], $state->rows[$table]);
                    unset($state->rows[$table][$ids[0]]);
                    $state->deletes[] = [$table, $ids[0]];

                    return true;
                });
                $statement->shouldReceive('rowCount')->andReturn(1);
            }

            return $statement;
        });

        return [new NativeBackend($pdo, $profile, $guard), $state];
    }

    public function test_native_fixture_creation_journals_all_ids_before_commit(): void
    {
        $journal = $this->journal();
        [$backend, $state] = $this->native($journal);
        $backend->createFixture($journal, 71);
        $this->assertSame('committed', $journal->stage());
        $this->assertSame(1, $state->commits);
        $this->assertSame(0, $state->rollbacks);
        $this->assertSame(3, array_sum(array_map('count', $state->rows)));
        FixtureSet::verify($state->rows, $journal->run, $journal->manifest());
    }

    public function test_native_exact_cleanup_obeys_fk_order_and_restores_baseline(): void
    {
        $journal = $this->journal();
        [$backend, $state] = $this->native($journal);
        $backend->createFixture($journal, 71);
        $backend->deleteExact($backend->rows(), 71);
        $this->assertSame([['vessel_user_access', 33], ['vessels', 22], ['users', 11]], $state->deletes);
        $this->assertSame(2, $state->commits);
        $this->assertSame(2, $state->baseline_checks);
        $backend->verify(true);
        $this->assertSame(0, array_sum(array_map('count', $state->rows)));
    }

    public function test_insert_failure_rolls_back_without_automatic_cleanup(): void
    {
        $journal = $this->journal();
        [$backend, $state] = $this->native($journal, 'insert-fails');
        try {
            $backend->createFixture($journal, 71);
            $this->fail('Expected insertion failure');
        } catch (\RuntimeException) {
            $this->assertSame('rolled-back', $journal->stage());
            $this->assertSame(1, $state->rollbacks);
            $this->assertSame(0, $state->commits);
            $this->assertSame([], $state->deletes);
            $this->assertSame(0, array_sum(array_map('count', $state->rows)));
        }
        $this->expectException(UnsafeMySqlEnvironment::class);
        $journal->assertCleanupAllowed();
    }

    public function test_lost_commit_acknowledgement_retains_records_and_requires_review(): void
    {
        $journal = $this->journal();
        [$backend, $state] = $this->native($journal, 'commit-lost');
        try {
            $backend->createFixture($journal, 71);
            $this->fail('Expected lost response');
        } catch (\RuntimeException) {
            $this->assertSame('uncertain', $journal->stage());
            $this->assertSame(3, array_sum(array_map('count', $state->rows)));
            $this->assertSame([], $state->deletes);
        }
        $this->expectException(UnsafeMySqlEnvironment::class);
        $journal->assertCleanupAllowed();
    }

    public function test_commit_rejection_is_not_mistaken_for_success_even_if_rollback_works(): void
    {
        $journal = $this->journal();
        [$backend, $state] = $this->native($journal, 'commit-rejected');
        try {
            $backend->createFixture($journal, 71);
            $this->fail('Expected commit failure');
        } catch (\RuntimeException) {
            $this->assertSame('uncertain', $journal->stage());
            $this->assertSame(1, $state->rollbacks);
            $this->assertSame(0, array_sum(array_map('count', $state->rows)));
        }
    }

    public function test_native_creation_requires_retained_mutex(): void
    {
        $journal = $this->journal();
        [$backend, $state] = $this->native($journal);
        $state->mutex = 0;
        try {
            $backend->createFixture($journal, 71);
            $this->fail('Missing mutex must fail');
        } catch (UnsafeMySqlEnvironment) {
            $this->assertFalse($state->tx);
            $this->assertSame('none', $journal->stage());
        }
    }

    public function test_worker_accepts_only_the_exact_fixture_allowlist(): void
    {
        $journal = $this->journal();
        $rows = $this->fixtureRows($journal);
        $this->recordFixture($journal, $rows);
        $backend = Mockery::mock(Backend::class);
        $backend->shouldReceive('verify')->with(false)->once();
        $backend->shouldReceive('rows')->once()->andReturn($rows);
        $backend->shouldReceive('mutexOwner')->once()->andReturn(71);
        Worker::authorise($this->lease($journal), str_repeat('a', 64), $backend, fn () => true);
        $this->addToAssertionCount(1);
    }

    private function lease(Journal $journal): array
    {
        return ['protocol' => 1, 'database' => Profile::DATABASE, 'expires' => time() + 60, 'pid' => 123, 'mutex_owner' => 71,
            'capability_hash' => hash('sha256', str_repeat('a', 64)), 'action' => 'fixture-probe', 'scenario' => 'fixture-lifecycle-v1',
            'run' => $journal->run, 'fixtures' => $journal->manifest()];
    }

    public function test_worker_rejects_extra_records_even_with_valid_coordinator_authorisation(): void
    {
        $journal = $this->journal();
        $rows = $this->fixtureRows($journal);
        $this->recordFixture($journal, $rows);
        $rows['users'][99] = $rows['users'][11];
        $backend = Mockery::mock(Backend::class);
        $backend->shouldReceive('verify')->with(false)->once();
        $backend->shouldReceive('rows')->once()->andReturn($rows);
        $this->expectException(UnsafeMySqlEnvironment::class);
        Worker::authorise($this->lease($journal), str_repeat('a', 64), $backend, fn () => true);
    }

    public function test_changed_record_or_unowned_assignment_is_rejected(): void
    {
        $journal = $this->journal();
        $rows = $this->fixtureRows($journal);
        $this->recordFixture($journal, $rows);
        $rows['vessel_user_access'][33]['vessel_id'] = 999;
        $this->expectException(UnsafeMySqlEnvironment::class);
        FixtureSet::verify($rows, $journal->run, $journal->manifest());
    }

    public function test_fixture_manifest_cannot_authorise_an_active_or_administrator_account(): void
    {
        $journal = $this->journal();
        $rows = $this->fixtureRows($journal);
        $rows['users'][11]['role'] = 'admin';
        $this->recordFixture($journal, $rows);
        $this->expectExceptionMessage('Unsafe fixture account');
        FixtureSet::verify($rows, $journal->run, $journal->manifest());
    }

    public function test_fixture_worker_without_a_manifest_is_rejected(): void
    {
        $journal = $this->journal();
        $lease = $this->lease($journal);
        unset($lease['fixtures']);
        $backend = Mockery::mock(Backend::class);
        $backend->shouldNotReceive('verify');
        $this->expectExceptionMessage('Missing fixture scenario authorisation');
        Worker::authorise($lease, str_repeat('a', 64), $backend, fn () => true);
    }

    public function test_invalid_commit_tracking_transition_cannot_enable_cleanup(): void
    {
        $journal = $this->journal();
        $journal->planFixture();
        $this->expectExceptionMessage('Unsafe fixture lifecycle transition');
        $journal->lifecycle('observed');
    }

    public function test_write_mode_requires_confirmation_before_loading_any_private_profile(): void
    {
        $this->expectExceptionMessage('Explicit fixture write/cleanup confirmation required');
        Coordinator::open(dirname(__DIR__, 2), 'Fictional', ['--fixture-lifecycle']);
    }

    public function test_fixture_lifecycle_cannot_run_on_an_ordinary_coordinator(): void
    {
        $journal = $this->journal();
        $backend = Mockery::mock(Backend::class);
        $backend->shouldReceive('verify')->with(true)->once();
        $backend->shouldReceive('mutexOwner')->once()->andReturn(71);
        $backend->shouldNotReceive('createFixture');
        $coordinator = new Coordinator($backend, $journal, fn () => null);
        $this->expectExceptionMessage('Fixture lifecycle is not explicitly authorised');
        $coordinator->fixtureLifecycle('/unused');
    }

    public function test_successful_coordinator_lifecycle_observes_fixture_before_cleanup(): void
    {
        $journal = $this->journal();
        [$backend, $state] = $this->native($journal);
        $worker = function ($process, $actualJournal, $id) use ($backend) {
            $this->assertSame('committed', $actualJournal->stage());
            $request = json_decode(trim($process->getInput()), true);
            $lease = Worker::readLease($request['lease']);
            $this->assertSame('fixture-probe', $request['action']);
            $actualJournal->worker($id, 'running');
            Worker::authorise($lease, $request['capability'], $backend, fn () => true);
            $actualJournal->worker($id, 'stopped');
        };
        $coordinator = new Coordinator($backend, $journal, fn () => null, true, $worker);
        $coordinator->fixtureLifecycle(dirname(__DIR__, 2));
        $this->assertSame('cleaned', $journal->stage());
        $this->assertSame('complete', json_decode(file_get_contents($journal->directory.'/journal.json'), true)['cleanup']);
        $this->assertSame(0, array_sum(array_map('count', $state->rows)));
    }

    public function test_record_changed_after_worker_verification_stops_cleanup(): void
    {
        $journal = $this->journal();
        [$backend, $state] = $this->native($journal);
        $worker = function ($process, $actualJournal, $id) use ($state) {
            $actualJournal->worker($id, 'running');
            $actualJournal->worker($id, 'stopped');
            $state->rows['users'][11]['name'] = 'Unexpected change';
        };
        $coordinator = new Coordinator($backend, $journal, fn () => null, true, $worker);
        try {
            $coordinator->fixtureLifecycle(dirname(__DIR__, 2));
            $this->fail('Changed fixture must not be cleaned');
        } catch (UnsafeMySqlEnvironment) {
            $this->assertSame('uncertain', $journal->stage());
            $this->assertSame([], $state->deletes);
            $this->assertSame(3, array_sum(array_map('count', $state->rows)));
        }
    }

    public function test_failed_worker_leaves_committed_fixture_and_journal_for_review(): void
    {
        $journal = $this->journal();
        [$backend, $state] = $this->native($journal);
        $worker = function ($process, $actualJournal, $id) {
            $actualJournal->worker($id, 'running');
            $actualJournal->worker($id, 'failed');
            $actualJournal->worker($id, 'stopped');
            throw new \RuntimeException('Simulated worker failure');
        };
        $coordinator = new Coordinator($backend, $journal, fn () => null, true, $worker);
        try {
            $coordinator->fixtureLifecycle(dirname(__DIR__, 2));
            $this->fail('Worker failure must propagate');
        } catch (\RuntimeException) {
            $this->assertSame('uncertain', $journal->stage());
            $this->assertSame(3, array_sum(array_map('count', $state->rows)));
            $this->assertSame([], $state->deletes);
            $this->assertFileExists($journal->directory.'/journal.json');
        }
        $this->expectException(UnsafeMySqlEnvironment::class);
        $coordinator->cleanup();
    }
}
