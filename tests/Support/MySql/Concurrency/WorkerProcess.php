<?php

namespace Tests\Support\MySql\Concurrency;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Tests\Support\MySql\Profile;
use Throwable;

final class WorkerProcess
{
    /** No shell, no arbitrary command construction, no child processes inside the worker. */
    public static function run(Process $process, Journal $journal, string $worker): void
    {
        $journal->worker($worker, 'running');
        $process->setTimeout(10);
        try {
            $process->run();
            Profile::require($process->isSuccessful() && trim($process->getOutput()) === '{"status":"verified"}', 'Worker failed; manual review required.');
        } catch (Throwable $exception) {
            $journal->worker($worker, $exception instanceof ProcessTimedOutException ? 'timeout' : 'failed');
            throw $exception;
        } finally {
            if ($process->isRunning()) {
                $process->stop(0);
            }
            Profile::require(! $process->isRunning(), 'Worker did not stop; cleanup is forbidden.');
            $journal->worker($worker, 'stopped');
        }
    }
}
