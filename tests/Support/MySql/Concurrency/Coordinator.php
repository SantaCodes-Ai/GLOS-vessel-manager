<?php

namespace Tests\Support\MySql\Concurrency;

use Symfony\Component\Process\Process;
use Tests\Support\MySql\Guard;
use Tests\Support\MySql\Manifest;
use Tests\Support\MySql\Profile;
use Tests\Support\MySql\Runtime;
use Throwable;

final class Coordinator
{
    private int $owner;

    private string $capability;

    private bool $uncertain = false;

    public function __construct(private Backend $backend, public readonly Journal $journal, callable $acquireMutexes, private bool $fixtureAuthorised = false, private ?\Closure $runWorker = null)
    {
        $acquireMutexes();
        $backend->verify(true);
        $this->owner = $backend->mutexOwner();
        Profile::require($this->owner > 0, 'Coordinator must retain the exclusive suite mutex.');
        $this->capability = bin2hex(random_bytes(32));
    }

    public static function open(string $root, string $operator, array $arguments): self
    {
        Profile::require(count(array_intersect($arguments, ['--inspect', '--probe', '--fixture-lifecycle'])) === 1 && trim($operator) !== '', 'Explicit scenario and operator required.');
        $fixture = in_array('--fixture-lifecycle', $arguments, true);
        Profile::require(! $fixture || in_array('--confirm-fixture-lifecycle='.Profile::DATABASE, $arguments, true), 'Explicit fixture write/cleanup confirmation required.');
        $profile = Profile::load($root, false, Runtime::ambient(), $arguments);
        $guard = new Guard(new Manifest($root));
        // Existing file and server locks are acquired unchanged and retained by Runtime.
        Runtime::lock($root);
        Runtime::databaseMutex($profile, $guard);
        $backend = new NativeBackend(Runtime::connect($profile), $profile, $guard);

        return new self($backend, new Journal($operator), fn () => null, $fixture);
    }

    private function assertOwner(): void
    {
        Profile::require(! $this->uncertain && $this->backend->mutexOwner() === $this->owner, 'Run ownership is uncertain; manual review required.');
        $this->backend->verify(false);
    }

    /** One read-only worker: either the empty baseline or the fixed three-row fixture. */
    public function probe(string $root, string $worker = 'w1', bool $fixture = false): void
    {
        $this->assertOwner();
        if ($fixture) {
            Profile::require($this->fixtureAuthorised && $this->journal->stage() === 'committed', 'Committed fixture authorisation required.');
            $this->assertFixture();
        } else {
            $this->backend->verify(true);
        }
        $lease = ['protocol' => 1, 'database' => Profile::DATABASE, 'pid' => getmypid(), 'expires' => time() + 60,
            'mutex_owner' => $this->owner, 'capability_hash' => hash('sha256', $this->capability)];
        $lease['action'] = $fixture ? 'fixture-probe' : 'probe';
        if ($fixture) {
            $lease['scenario'] = 'fixture-lifecycle-v1';
            $lease['run'] = $this->journal->run;
            $lease['fixtures'] = $this->journal->manifest();
        }
        $path = $this->journal->directory.'/lease.json';
        $handle = fopen($path, 'x');
        Profile::require(is_resource($handle), 'Lease already exists.');
        chmod($path, 0600);
        $json = json_encode($lease, JSON_THROW_ON_ERROR);
        try {
            Profile::require(fwrite($handle, $json) === strlen($json) && fflush($handle) && fsync($handle), 'Lease write failed.');
        } finally {
            fclose($handle);
        }
        $process = new Process([PHP_BINARY, $root.'/scripts/mysql-concurrency-worker.php'], $root);
        $process->setInput(json_encode(['lease' => $path, 'capability' => $this->capability, 'action' => $fixture ? 'fixture-probe' : 'probe'], JSON_THROW_ON_ERROR)."\n");
        try {
            if ($this->runWorker !== null) {
                ($this->runWorker)($process, $this->journal, $worker);
            } else {
                WorkerProcess::run($process, $this->journal, $worker);
            }
        } catch (Throwable $exception) {
            $this->uncertain = true;
            throw $exception;
        } finally {
            unlink($path);
        }
        if ($fixture) {
            $this->assertFixture();
        } else {
            $this->backend->verify(true);
        }
    }

    private function assertFixture(): void
    {
        $this->assertOwner();
        $rows = $this->backend->rows();
        $this->journal->assertRows($rows);
        FixtureSet::verify($rows, $this->journal->run, $this->journal->manifest());
    }

    public function fixtureLifecycle(string $root): void
    {
        Profile::require($this->fixtureAuthorised && $this->journal->stage() === 'none', 'Fixture lifecycle is not explicitly authorised.');
        try {
            $this->assertOwner();
            $this->backend->verify(true);
            $this->backend->createFixture($this->journal, $this->owner);
            Profile::require($this->journal->stage() === 'committed', 'Fixture commit is not acknowledged.');
            $this->probe($root, 'w1', true);
            $this->journal->lifecycle('observed');
            $this->cleanup();
            $this->journal->lifecycle('cleaned');
        } catch (Throwable $exception) {
            $this->uncertain = true;
            if (in_array($this->journal->stage(), ['planned', 'prepared', 'commit-attempted', 'committed', 'observed'], true)) {
                $this->journal->lifecycle('uncertain');
            }
            $this->journal->cleanup('manual-review');
            throw $exception;
        }
    }

    public function cleanup(): void
    {
        try {
            $this->assertOwner();
            $this->journal->assertCleanupAllowed();
            Profile::require($this->journal->workersStopped(), 'Workers must stop before cleanup.');
            $rows = $this->backend->rows();
            $this->journal->assertRows($rows);
            // Preflight all file ownership before deleting any database record.
            $storage = $this->journal->directory.'/storage';
            Profile::require(! is_link($storage) && realpath($storage) === realpath($this->journal->directory).'/storage', 'Private storage root changed.');
            $actual = [];
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($storage, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                Profile::require(! $file->isLink() && $file->isFile(), 'Unsafe storage entry.');
                $actual[] = substr($file->getPathname(), strlen($storage) + 1);
            }
            $expected = $this->journal->files();
            sort($actual);
            sort($expected);
            Profile::require($actual === $expected, 'Untracked or missing test files; manual review required.');
            $this->journal->cleanup('verified');
            $this->backend->deleteExact($rows, $this->owner);
            $this->journal->cleanup('database-clean');
            $this->backend->verify(true);
            foreach ($expected as $relative) {
                Profile::require(unlink($storage.'/'.$relative), 'Test file cleanup failed; retain journal.');
            }
            $this->journal->cleanup('complete');
        } catch (Throwable $exception) {
            $this->uncertain = true;
            $this->journal->cleanup('manual-review');
            throw $exception;
        }
    }
}
