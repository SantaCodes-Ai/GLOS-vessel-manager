<?php

require dirname(__DIR__).'/vendor/autoload.php';

use Tests\Support\MySql\Concurrency\Coordinator;
use Tests\Support\MySql\Profile;

// No application bootstrap, application dotenv loading, migrations or business races.
try {
    $arguments = array_slice($argv, 1);
    $operator = '';
    foreach ($arguments as $argument) {
        if (str_starts_with($argument, '--operator=')) {
            $operator = substr($argument, 11);
        } elseif (str_starts_with($argument, '--confirm-fixture-lifecycle=')) {
            Profile::require($argument === '--confirm-fixture-lifecycle='.Profile::DATABASE, 'Incorrect fixture database confirmation.');
        } else {
            Profile::require(in_array($argument, ['--inspect', '--probe', '--fixture-lifecycle'], true), 'Unsupported coordinator argument.');
        }
    }
    $coordinator = Coordinator::open(dirname(__DIR__), $operator, $arguments);
    if (in_array('--fixture-lifecycle', $arguments, true)) {
        $coordinator->fixtureLifecycle(dirname(__DIR__));
        fwrite(STDOUT, "Fictional three-record fixture committed, independently verified and cleaned. Baseline verified.\n");
    } elseif (in_array('--probe', $arguments, true)) {
        $coordinator->probe(dirname(__DIR__));
        fwrite(STDOUT, "One read-only authorised worker verified. No fixtures or scenarios executed.\n");
    } else {
        fwrite(STDOUT, "Concurrency infrastructure baseline verified. No workers or fixtures executed.\n");
    }
    fwrite(STDOUT, 'Private journal: '.$coordinator->journal->directory."\n");
} catch (Throwable) {
    if (isset($coordinator)) {
        fwrite(STDERR, 'Retained private journal: '.$coordinator->journal->directory."\n");
    }
    fwrite(STDERR, "Concurrency coordinator refused. No automatic cleanup or repair is performed.\n");
    exit(1);
}
