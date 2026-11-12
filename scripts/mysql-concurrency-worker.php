<?php

require dirname(__DIR__).'/vendor/autoload.php';

use Tests\Support\MySql\Concurrency\NativeBackend;
use Tests\Support\MySql\Concurrency\Worker;
use Tests\Support\MySql\Guard;
use Tests\Support\MySql\Manifest;
use Tests\Support\MySql\Profile;
use Tests\Support\MySql\Runtime;

try {
    Profile::require(count($argv) === 1, 'Worker accepts only private stdin authorisation.');
    $request = Worker::readRequest(STDIN);
    Profile::require(in_array($request['action'] ?? null, ['probe', 'fixture-probe'], true), 'No business workloads are enabled.');
    $lease = Worker::readLease($request['lease'] ?? '');
    Profile::require(($lease['action'] ?? 'probe') === $request['action'], 'Worker action differs from coordinator lease.');
    // Refuse missing/expired authorisation before opening any connection.
    Profile::require(($lease['expires'] ?? 0) > time() && preg_match('/\A[a-f0-9]{64}\z/', $request['capability'] ?? '') === 1
        && hash_equals($lease['capability_hash'] ?? '', hash('sha256', $request['capability'])), 'Invalid worker capability.');
    $root = dirname(__DIR__);
    $profile = Profile::load($root, false, Runtime::ambient());
    $backend = new NativeBackend(Runtime::connect($profile), $profile, new Guard(new Manifest($root)));
    Worker::authorise($lease, $request['capability'], $backend, fn ($pid) => function_exists('posix_kill') && posix_kill($pid, 0));
    // NativeBackend exposes no arbitrary SQL or application-writing workloads.
    fwrite(STDOUT, '{"status":"verified"}'."\n");
} catch (Throwable) {
    fwrite(STDERR, "Worker refused; no automatic recovery performed.\n");
    exit(1);
}
