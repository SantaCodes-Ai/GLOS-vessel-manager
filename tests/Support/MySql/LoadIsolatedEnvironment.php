<?php

namespace Tests\Support\MySql;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;

final class LoadIsolatedEnvironment extends LoadEnvironmentVariables
{
    public function bootstrap(Application $app): void
    {
        // Runtime already installed the validated private profile. Read no dotenv files.
        $ambient = Runtime::ambient();
        Profile::assertAmbient($app->basePath(), $ambient, []);
        Profile::require(($ambient['APP_ENV'] ?? null) === 'testing'
            && ($ambient['DB_CONNECTION'] ?? null) === 'mysql_testing'
            && ($ambient['DB_DATABASE'] ?? null) === Profile::DATABASE
            && in_array($ambient['DB_USERNAME'] ?? null, ['glos_test_runner', 'glos_test_schema'], true),
            'The isolated MySQL runtime environment has not been installed.');
    }
}
