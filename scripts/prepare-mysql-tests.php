<?php

require dirname(__DIR__).'/vendor/autoload.php';

use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\MySql\DatabaseProbe;
use Tests\Support\MySql\Guard;
use Tests\Support\MySql\Manifest;
use Tests\Support\MySql\Preparation;
use Tests\Support\MySql\Profile;
use Tests\Support\MySql\Runtime;

try {
    $root = dirname(__DIR__);
    $arguments = array_slice($_SERVER['argv'], 1);
    $operator = '';
    foreach ($arguments as $argument) {
        if (str_starts_with($argument, '--operator=')) {
            $operator = substr($argument, 11);
        } else {
            Profile::require($argument === '--prepare', 'Unsupported preparation argument.');
        }
    }
    Profile::require(in_array('--prepare', $arguments, true) && trim($operator) !== '' && stream_isatty(STDIN), 'An explicit interactive --prepare --operator invocation is required.');
    $profile = Profile::load($root, true, Runtime::ambient(), $arguments);
    fwrite(STDOUT, 'Type exactly: '.Preparation::CONFIRMATION."\n> ");
    $confirmation = trim((string) fgets(STDIN));
    Profile::require($confirmation === Preparation::CONFIRMATION, 'Preparation cancelled.');
    Runtime::lock($root);
    $manifest = new Manifest($root);
    $database = Runtime::databaseMutex($profile, new Guard($manifest));
    (new Preparation(new Guard($manifest), $manifest))->run($profile, $database, $operator, $confirmation, function () use ($root, $profile) {
        $app = Runtime::application($root, $profile, function ($app) use ($profile, $root) {
            $connection = $app['db']->connection();
            $connection->beforeExecuting(function ($sql) use ($connection, $profile, $app, $root) {
                Guard::effective($app['config']->all(), $profile);
                Profile::assertAmbient($root, Runtime::ambient(), []);
                (new Guard(new Manifest($root)))->identity($profile, new DatabaseProbe($connection->getPdo()));
                Profile::require(preg_match('/\A\s*(select|show|create|alter|insert|set)\b/i', $sql) === 1, 'Unexpected schema preparation statement.');
            });
        });
        $output = new BufferedOutput;
        Profile::require($app->make(Kernel::class)->call('migrate', ['--database' => 'mysql_testing', '--force' => true], $output) === 0, 'Test schema migration failed.');
    });
    fwrite(STDOUT, "Test schema prepared and ownership verified. No application database was accessed.\n");
} catch (Throwable) {
    fwrite(STDERR, "Preparation refused or failed. No automatic reset/repair is performed. If DDL started, inspect the partial test schema manually before retrying.\n");
    exit(1);
}
