<?php

namespace Tests\Unit;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Tests\Support\MySql\Concurrency\Backend;
use Tests\Support\MySql\Concurrency\Barrier;
use Tests\Support\MySql\Concurrency\Coordinator;
use Tests\Support\MySql\Concurrency\Journal;
use Tests\Support\MySql\Concurrency\NativeBackend;
use Tests\Support\MySql\Concurrency\Worker;
use Tests\Support\MySql\Concurrency\WorkerProcess;
use Tests\Support\MySql\DatabaseProbe;
use Tests\Support\MySql\Guard;
use Tests\Support\MySql\Profile;
use Tests\Support\MySql\UnsafeMySqlEnvironment;

final class MySqlConcurrencySafetyTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private array $directories = [];

    private function journal(): Journal
    {
        $journal = new Journal('Fictional operator');
        $this->directories[] = $journal->directory;

        return $journal;
    }

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $entry) {
                $entry->isDir() && ! $entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($directory);
        }
        parent::tearDown();
    }

    private function rows(): array
    {
        return array_fill_keys(NativeBackend::TABLES, []);
    }

    private function backend(): Backend
    {
        $backend = Mockery::mock(Backend::class);
        $backend->shouldReceive('verify')->with(true)->byDefault();
        $backend->shouldReceive('verify')->with(false)->byDefault();
        $backend->shouldReceive('mutexOwner')->andReturn(71)->byDefault();
        $backend->shouldReceive('rows')->andReturn($this->rows())->byDefault();

        return $backend;
    }

    private function coordinator(Backend $backend, Journal $journal): Coordinator
    {
        return new Coordinator($backend, $journal, fn () => null);
    }

    private function lease(): array
    {
        return ['protocol' => 1, 'database' => Profile::DATABASE, 'expires' => time() + 60, 'pid' => 123,
            'mutex_owner' => 71, 'capability_hash' => hash('sha256', str_repeat('a', 64))];
    }

    private function profile(): Profile
    {
        return Profile::fromValues(['APP_ENV' => 'testing', 'MYSQL_TEST_HOST' => '127.0.0.1', 'MYSQL_TEST_PORT' => '3306',
            'MYSQL_TEST_DATABASE' => Profile::DATABASE, 'MYSQL_TEST_USERNAME' => 'glos_test_runner',
            'MYSQL_TEST_PASSWORD' => str_repeat('x', 24), 'MYSQL_TEST_OWNERSHIP_TOKEN' => str_repeat('a', 64)]);
    }

    #[DataProvider('badIdentity')]
    public function test_native_backend_retains_existing_identity_and_marker_checks(string $failure): void
    {
        $pdo = Mockery::mock(\PDO::class);
        $guard = Mockery::mock(Guard::class);
        $guard->shouldReceive('prepared')->once()->with(Mockery::type(Profile::class), Mockery::type(DatabaseProbe::class), true)
            ->andThrow(new UnsafeMySqlEnvironment($failure));
        $backend = new NativeBackend($pdo, $this->profile(), $guard);
        $this->expectException(UnsafeMySqlEnvironment::class);
        $backend->verify(true);
    }

    public static function badIdentity(): array
    {
        return [['Development database'], ['Incorrect account'], ['Invalid marker'], ['Unexpected data']];
    }

    public function test_coordinator_requires_clean_baseline_before_work(): void
    {
        $backend = $this->backend();
        $backend->shouldReceive('verify')->with(true)->once()->andThrow(new UnsafeMySqlEnvironment('Unexpected data'));
        $backend->shouldNotReceive('deleteExact');
        $this->expectException(UnsafeMySqlEnvironment::class);
        $this->coordinator($backend, $this->journal());
    }

    public function test_conflicting_run_refuses_before_database_verification(): void
    {
        $backend = Mockery::mock(Backend::class);
        $backend->shouldNotReceive('verify');
        $this->expectException(UnsafeMySqlEnvironment::class);
        new Coordinator($backend, $this->journal(), fn () => throw new UnsafeMySqlEnvironment('Mutex already held'));
    }

    #[DataProvider('badLeases')]
    public function test_worker_refuses_unauthorised_participation_before_database_inspection(array $override, string $secret, bool $alive): void
    {
        $backend = Mockery::mock(Backend::class);
        $backend->shouldNotReceive('verify');
        $this->expectException(UnsafeMySqlEnvironment::class);
        Worker::authorise(array_replace($this->lease(), $override), $secret, $backend, fn () => $alive);
    }

    public static function badLeases(): array
    {
        return [
            [[], '', true], [[], str_repeat('b', 64), true], [[], str_repeat('a', 64), false],
            [['expires' => 0], str_repeat('a', 64), true], [['expires' => PHP_INT_MAX], str_repeat('a', 64), true],
            [['database' => 'glos_vessel_manager'], str_repeat('a', 64), true], [['protocol' => 99], str_repeat('a', 64), true],
        ];
    }

    public function test_worker_checks_schema_and_advisory_lock_after_capability_validation(): void
    {
        $backend = $this->backend();
        $backend->shouldReceive('verify')->with(true)->once();
        $backend->shouldReceive('mutexOwner')->once()->andReturn(71);
        Worker::authorise($this->lease(), str_repeat('a', 64), $backend, fn () => true);
        $this->addToAssertionCount(1);
    }

    public function test_worker_rejects_a_replaced_advisory_lock_owner(): void
    {
        $backend = $this->backend();
        $backend->shouldReceive('mutexOwner')->andReturn(72);
        $this->expectException(UnsafeMySqlEnvironment::class);
        Worker::authorise($this->lease(), str_repeat('a', 64), $backend, fn () => true);
    }

    public function test_barrier_messages_work_without_sleeps(): void
    {
        [$one, $two] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        try {
            Barrier::send($one, 'ready-w1');
            $this->assertSame('ready-w1', Barrier::receive($two, 100));
        } finally {
            fclose($one);
            fclose($two);
        }
    }

    public function test_worker_waits_have_a_bounded_timeout(): void
    {
        [$one, $two] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        try {
            $this->expectExceptionMessage('Worker barrier timed out');
            Barrier::receive($two, 1);
        } finally {
            fclose($one);
            fclose($two);
        }
    }

    public function test_barrier_does_not_wait_indefinitely_for_an_incomplete_message(): void
    {
        [$one, $two] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        fwrite($one, 'r');
        try {
            $this->expectExceptionMessage('Worker barrier timed out');
            Barrier::receive($two, 1);
        } finally {
            fclose($one);
            fclose($two);
        }
    }

    public function test_journal_is_private_and_records_only_safe_outcomes(): void
    {
        $journal = $this->journal();
        $journal->worker('w1', 'deadlock', 1, 1213);
        $journal->worker('w1', 'rolled-back', 1);
        $journal->worker('w1', 'stopped');
        $this->assertTrue($journal->workersStopped());
        $this->assertSame(0, fileperms($journal->directory) & 0077);
        $this->assertSame(0, fileperms($journal->directory.'/journal.json') & 0077);
        $this->assertStringContainsString('1213', file_get_contents($journal->directory.'/journal.json'));
    }

    public function test_fixture_ownership_and_relationships_are_verified(): void
    {
        $journal = $this->journal();
        $user = ['id' => 21, 'email' => 'fictional+'.$journal->run.'@example.test'];
        $vessel = ['id' => 31, 'vessel_name' => 'Concurrency '.$journal->run];
        $assignment = ['id' => 41, 'user_id' => 21, 'vessel_id' => 31];
        $journal->claim('users', $user);
        $journal->claim('vessels', $vessel);
        $journal->claim('vessel_user_access', $assignment);
        $rows = $this->rows();
        $rows['users'][21] = $user;
        $rows['vessels'][31] = $vessel;
        $rows['vessel_user_access'][41] = $assignment;
        $journal->assertRows($rows);
        $this->addToAssertionCount(1);
        $this->expectException(UnsafeMySqlEnvironment::class);
        $journal->claim('users', ['id' => 99, 'email' => 'company@example.com']);
    }

    public function test_unowned_parent_relationship_is_not_claimable(): void
    {
        $this->expectException(UnsafeMySqlEnvironment::class);
        $this->journal()->claim('certificate_versions', ['id' => 10, 'certificate_id' => 20]);
    }

    public function test_baseline_rows_are_never_claimable(): void
    {
        $this->expectException(UnsafeMySqlEnvironment::class);
        $this->journal()->claim('mysql_test_ownership', ['id' => 1]);
    }

    public function test_unsafe_cleanup_never_calls_delete(): void
    {
        $journal = $this->journal();
        $backend = $this->backend();
        $rows = $this->rows();
        $rows['users'][90] = ['id' => 90, 'email' => 'unexpected@example.test'];
        $backend->shouldReceive('rows')->andReturn($rows);
        $backend->shouldNotReceive('deleteExact');
        try {
            $this->coordinator($backend, $journal)->cleanup();
            $this->fail('Unowned data must not be deleted.');
        } catch (UnsafeMySqlEnvironment) {
            $this->assertStringContainsString('manual-review', file_get_contents($journal->directory.'/journal.json'));
        }
    }

    public function test_changed_owned_row_stops_cleanup(): void
    {
        $journal = $this->journal();
        $row = ['id' => 1, 'email' => 'fictional+'.$journal->run.'@example.test', 'is_active' => 0];
        $journal->claim('users', $row);
        $row['is_active'] = 1;
        $backend = $this->backend();
        $rows = $this->rows();
        $rows['users'][1] = $row;
        $backend->shouldReceive('rows')->andReturn($rows);
        $backend->shouldNotReceive('deleteExact');
        $this->expectException(UnsafeMySqlEnvironment::class);
        $this->coordinator($backend, $journal)->cleanup();
    }

    public function test_running_worker_prevents_cleanup(): void
    {
        $journal = $this->journal();
        $journal->worker('w1', 'running');
        $backend = $this->backend();
        $backend->shouldNotReceive('deleteExact');
        $this->expectException(UnsafeMySqlEnvironment::class);
        $this->coordinator($backend, $journal)->cleanup();
    }

    public function test_untracked_file_prevents_database_cleanup(): void
    {
        $journal = $this->journal();
        file_put_contents($journal->directory.'/storage/untracked.pdf', 'fictional');
        $backend = $this->backend();
        $backend->shouldNotReceive('deleteExact');
        $this->expectException(UnsafeMySqlEnvironment::class);
        $this->coordinator($backend, $journal)->cleanup();
    }

    public function test_exact_owned_cleanup_is_requested_and_verified(): void
    {
        $journal = $this->journal();
        $row = ['id' => 1, 'email' => 'fictional+'.$journal->run.'@example.test'];
        $journal->claim('users', $row);
        $rows = $this->rows();
        $rows['users'][1] = $row;
        $journal->recordFile('fixture.pdf');
        file_put_contents($journal->directory.'/storage/fixture.pdf', 'fictional');
        $backend = $this->backend();
        $backend->shouldReceive('rows')->andReturn($rows);
        $backend->shouldReceive('deleteExact')->once()->with($rows, 71);
        $this->coordinator($backend, $journal)->cleanup();
        $this->assertFileDoesNotExist($journal->directory.'/storage/fixture.pdf');
        $this->assertSame('complete', json_decode(file_get_contents($journal->directory.'/journal.json'), true)['cleanup']);
    }

    #[DataProvider('badPaths')]
    public function test_unsafe_file_paths_are_rejected(string $path): void
    {
        $this->expectException(UnsafeMySqlEnvironment::class);
        $this->journal()->recordFile($path);
    }

    public static function badPaths(): array
    {
        return [['../company.pdf'], ['/private/company.pdf'], ['a/../../company.pdf'], ['file.php'], ['a\\file.pdf']];
    }

    public function test_worker_process_timeout_stops_process_and_blocks_cleanup(): void
    {
        $journal = $this->journal();
        $process = Mockery::mock(Process::class);
        $process->shouldReceive('setTimeout')->once()->with(10);
        $exception = new ProcessTimedOutException(new Process([PHP_BINARY, '-v']), ProcessTimedOutException::TYPE_GENERAL);
        $process->shouldReceive('run')->once()->andThrow($exception);
        $process->shouldReceive('isRunning')->andReturn(true, false);
        $process->shouldReceive('stop')->once()->with(0);
        try {
            WorkerProcess::run($process, $journal, 'w1');
            $this->fail('Timeout must propagate.');
        } catch (ProcessTimedOutException) {
            $this->assertFalse($journal->workersStopped());
            $this->assertStringContainsString('timeout', file_get_contents($journal->directory.'/journal.json'));
        }
    }

    public function test_successful_worker_result_is_strictly_checked(): void
    {
        $journal = $this->journal();
        $process = Mockery::mock(Process::class);
        $process->shouldReceive('setTimeout')->once()->with(10);
        $process->shouldReceive('run')->once();
        $process->shouldReceive('isSuccessful')->once()->andReturn(true);
        $process->shouldReceive('getOutput')->once()->andReturn('{"status":"verified"}');
        $process->shouldReceive('isRunning')->twice()->andReturn(false);
        WorkerProcess::run($process, $journal, 'w1');
        $this->assertTrue($journal->workersStopped());
    }

    public function test_invalid_worker_result_is_retained_for_manual_review(): void
    {
        $journal = $this->journal();
        $process = Mockery::mock(Process::class);
        $process->shouldReceive('setTimeout')->once();
        $process->shouldReceive('run')->once();
        $process->shouldReceive('isSuccessful')->once()->andReturn(true);
        $process->shouldReceive('getOutput')->once()->andReturn('unexpected');
        $process->shouldReceive('isRunning')->twice()->andReturn(false);
        try {
            WorkerProcess::run($process, $journal, 'w1');
            $this->fail('Invalid result must fail closed.');
        } catch (UnsafeMySqlEnvironment) {
            $this->assertFalse($journal->workersStopped());
        }
    }

    public function test_native_cleanup_rolls_back_before_delete_if_rows_changed(): void
    {
        $pdo = Mockery::mock(\PDO::class);
        $guard = Mockery::mock(Guard::class);
        $guard->shouldReceive('prepared')->with(Mockery::type(Profile::class), Mockery::type(DatabaseProbe::class), false)->once();
        $locks = Mockery::mock(\PDOStatement::class);
        $locks->shouldReceive('fetchAll')->with(\PDO::FETCH_COLUMN)->andReturn([1]);
        $pdo->shouldReceive('query')->with('SELECT id FROM privilege_management_locks ORDER BY id')->andReturn($locks);
        foreach (['cache', 'cache_locks', 'failed_jobs', 'job_batches', 'jobs', 'password_reset_tokens', 'sessions'] as $table) {
            $count = Mockery::mock(\PDOStatement::class);
            $count->shouldReceive('fetchColumn')->andReturn(0);
            $pdo->shouldReceive('query')->with('SELECT COUNT(*) FROM `'.$table.'`')->andReturn($count);
        }
        foreach (NativeBackend::TABLES as $table) {
            $statement = Mockery::mock(\PDOStatement::class);
            $statement->shouldReceive('fetchAll')->with(\PDO::FETCH_UNIQUE | \PDO::FETCH_ASSOC)->andReturn([]);
            $pdo->shouldReceive('query')->with('SELECT * FROM `'.$table.'` ORDER BY id FOR UPDATE')->andReturn($statement);
        }
        $mutex = Mockery::mock(\PDOStatement::class);
        $mutex->shouldReceive('fetchColumn')->andReturn(71);
        $pdo->shouldReceive('query')->with("SELECT IS_USED_LOCK('glos_vessel_manager_test:integration-suite')")->andReturn($mutex);
        $pdo->shouldReceive('inTransaction')->andReturn(false, true);
        $pdo->shouldReceive('exec')->once()->with('SET SESSION innodb_lock_wait_timeout = 3');
        $pdo->shouldReceive('beginTransaction')->once();
        $pdo->shouldReceive('rollBack')->once();
        $pdo->shouldNotReceive('commit');
        $pdo->shouldNotReceive('prepare');
        $rows = $this->rows();
        $rows['users'][1] = ['id' => 1];
        $this->expectExceptionMessage('Cleanup snapshot changed');
        (new NativeBackend($pdo, $this->profile(), $guard))->deleteExact($rows, 71);
    }

    public function test_worker_private_input_is_bounded_and_not_taken_from_arguments(): void
    {
        [$one, $two] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $request = ['lease' => '/fictional/lease.json', 'capability' => str_repeat('a', 64), 'action' => 'probe'];
        fwrite($one, json_encode($request)."\n");
        try {
            $this->assertSame($request, Worker::readRequest($two, 100));
        } finally {
            fclose($one);
            fclose($two);
        }
    }

    public function test_worker_without_stdin_authorisation_cannot_wait_indefinitely(): void
    {
        [$one, $two] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        try {
            $this->expectExceptionMessage('Worker authorisation input timed out');
            Worker::readRequest($two, 1);
        } finally {
            fclose($one);
            fclose($two);
        }
    }

    public function test_native_cleanup_uses_only_exact_ids_inside_a_verified_transaction(): void
    {
        $pdo = Mockery::mock(\PDO::class);
        $guard = Mockery::mock(Guard::class);
        $guard->shouldReceive('prepared')->with(Mockery::type(Profile::class), Mockery::type(DatabaseProbe::class), false)->once();
        $guard->shouldReceive('prepared')->with(Mockery::type(Profile::class), Mockery::type(DatabaseProbe::class), true)->once();
        $locks = Mockery::mock(\PDOStatement::class);
        $locks->shouldReceive('fetchAll')->with(\PDO::FETCH_COLUMN)->andReturn([1]);
        $pdo->shouldReceive('query')->with('SELECT id FROM privilege_management_locks ORDER BY id')->twice()->andReturn($locks);
        foreach (['cache', 'cache_locks', 'failed_jobs', 'job_batches', 'jobs', 'password_reset_tokens', 'sessions'] as $table) {
            $count = Mockery::mock(\PDOStatement::class);
            $count->shouldReceive('fetchColumn')->andReturn(0);
            $pdo->shouldReceive('query')->with('SELECT COUNT(*) FROM `'.$table.'`')->once()->andReturn($count);
        }
        foreach (NativeBackend::TABLES as $table) {
            $statement = Mockery::mock(\PDOStatement::class);
            $statement->shouldReceive('fetchAll')->with(\PDO::FETCH_UNIQUE | \PDO::FETCH_ASSOC)
                ->andReturn($table === 'users' ? [7 => ['email' => 'fictional@example.test']] : []);
            $pdo->shouldReceive('query')->with('SELECT * FROM `'.$table.'` ORDER BY id FOR UPDATE')->once()->andReturn($statement);
            $delete = Mockery::mock(\PDOStatement::class);
            $pdo->shouldReceive('prepare')->with('DELETE FROM `'.$table.'` WHERE id = ?')->once()->andReturn($delete);
            if ($table === 'users') {
                $delete->shouldReceive('execute')->with([7])->once();
                $delete->shouldReceive('rowCount')->once()->andReturn(1);
            } else {
                $delete->shouldNotReceive('execute');
            }
        }
        $mutex = Mockery::mock(\PDOStatement::class);
        $mutex->shouldReceive('fetchColumn')->andReturn(71);
        $pdo->shouldReceive('query')->with("SELECT IS_USED_LOCK('glos_vessel_manager_test:integration-suite')")->twice()->andReturn($mutex);
        $pdo->shouldReceive('inTransaction')->once()->andReturn(false);
        $pdo->shouldReceive('exec')->once()->with('SET SESSION innodb_lock_wait_timeout = 3');
        $pdo->shouldReceive('beginTransaction')->once();
        $pdo->shouldReceive('commit')->once()->andReturn(true);
        $pdo->shouldNotReceive('rollBack');
        $rows = $this->rows();
        $rows['users'][7] = ['email' => 'fictional@example.test', 'id' => 7];
        (new NativeBackend($pdo, $this->profile(), $guard))->deleteExact($rows, 71);
        $this->addToAssertionCount(1);
    }

    public function test_replaced_storage_root_prevents_database_cleanup(): void
    {
        $journal = $this->journal();
        rmdir($journal->directory.'/storage');
        mkdir($journal->directory.'/other', 0700);
        symlink($journal->directory.'/other', $journal->directory.'/storage');
        $backend = $this->backend();
        $backend->shouldNotReceive('deleteExact');
        $this->expectExceptionMessage('Private storage root changed');
        $this->coordinator($backend, $journal)->cleanup();
    }

    public function test_lease_reader_rejects_missing_or_public_files(): void
    {
        $journal = $this->journal();
        file_put_contents($journal->directory.'/lease.json', '{}');
        chmod($journal->directory.'/lease.json', 0644);
        $this->expectException(UnsafeMySqlEnvironment::class);
        Worker::readLease($journal->directory.'/lease.json');
    }

    public function test_storage_symlink_blocks_cleanup(): void
    {
        $journal = $this->journal();
        symlink($journal->directory.'/journal.json', $journal->directory.'/storage/owned.pdf');
        $journal->recordFile('owned.pdf');
        $backend = $this->backend();
        $backend->shouldNotReceive('deleteExact');
        $this->expectException(UnsafeMySqlEnvironment::class);
        $this->coordinator($backend, $journal)->cleanup();
    }

    public function test_existing_suite_profiles_are_not_reused_for_concurrent_workers(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertStringNotContainsString('Concurrency', file_get_contents($root.'/tests/MySqlTestCase.php'));
        $this->assertStringNotContainsString('Concurrency', file_get_contents($root.'/phpunit.mysql.xml'));
        $this->assertStringContainsString('value=":memory:"', file_get_contents($root.'/phpunit.xml'));
        $this->assertStringContainsString("'fixture-probe' : 'probe'", file_get_contents($root.'/tests/Support/MySql/Concurrency/Coordinator.php'));
    }
}
