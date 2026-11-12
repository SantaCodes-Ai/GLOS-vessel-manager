<?php

namespace Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use Illuminate\Foundation\Console\Kernel;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\MySql\Profile;
use Tests\Support\MySql\Runtime;

// Exercise the real Runtime/bootstrap chain without providers or any database connection.
final class EnvironmentOnlyKernel extends Kernel
{
    public function bootstrap()
    {
        $this->app->bootstrapWith([LoadEnvironmentVariables::class, LoadConfiguration::class]);
        $this->app->boot();
    }
}

#[Group('mysql-safety')]
final class MySqlEnvironmentBootstrapTest extends TestCase
{
    #[DataProvider('environmentFiles')]
    public function test_runtime_bootstraps_without_file_warnings_or_application_dotenv_values(bool $poisonedFile): void
    {
        $environmentBefore = getenv();
        $envBefore = $_ENV;
        $serverBefore = $_SERVER;
        $containerBefore = Container::getInstance();
        $facadeBefore = Facade::getFacadeApplication();
        $root = sys_get_temp_dir().'/glos-environment-bootstrap-'.bin2hex(random_bytes(8));
        mkdir($root.'/bootstrap', 0700, true);
        mkdir($root.'/config', 0700);
        file_put_contents($root.'/bootstrap/app.php', '<?php
$app = new \\Illuminate\\Foundation\\Application(dirname(__DIR__));
$app->singleton(\\Illuminate\\Contracts\\Console\\Kernel::class, \\Tests\\Unit\\EnvironmentOnlyKernel::class);
return $app;');
        file_put_contents($root.'/config/app.php', "<?php return ['env' => env('APP_ENV'), 'name' => env('GLOS_ENV_POISON', 'not-loaded')];");
        if ($poisonedFile) {
            file_put_contents($root.'/.env', "GLOS_ENV_POISON=application-file-loaded\nDB_DATABASE=glos_vessel_manager\nDB_USERNAME=root\n");
        }
        foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql_testing', 'DB_DATABASE' => Profile::DATABASE, 'DB_HOST' => Profile::HOST, 'DB_PORT' => Profile::PORT] as $key => $value) {
            putenv($key.'='.$value);
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
        $profile = Profile::fromValues([
            'APP_ENV' => 'testing', 'MYSQL_TEST_HOST' => Profile::HOST, 'MYSQL_TEST_PORT' => Profile::PORT,
            'MYSQL_TEST_DATABASE' => Profile::DATABASE, 'MYSQL_TEST_USERNAME' => 'glos_test_runner',
            'MYSQL_TEST_PASSWORD' => str_repeat('x', 24), 'MYSQL_TEST_OWNERSHIP_TOKEN' => str_repeat('a', 64),
        ]);
        $callbackRan = false;
        set_error_handler(static function ($severity, $message, $file, $line) {
            // Catch even @-suppressed file reads: the old implementation must fail.
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            $app = Runtime::application($root, $profile, function ($app) use (&$callbackRan) {
                $callbackRan = true;
            });
            $this->assertTrue($callbackRan);
            $this->assertSame('testing', $app['config']->get('app.env'));
            $this->assertSame('not-loaded', $app['config']->get('app.name'));
            $this->assertSame('mysql_testing', $app['config']->get('database.default'));
            $this->assertSame(['mysql_testing' => $profile->connection()], $app['config']->get('database.connections'));
            $this->assertSame(Profile::DATABASE, $app['config']->get('database.connections.mysql_testing.database'));
            $this->assertNull($app['config']->get('database.connections.mysql'));
            $this->assertArrayNotHasKey('GLOS_ENV_POISON', Runtime::ambient());
        } finally {
            restore_error_handler();
            foreach (array_keys(getenv()) as $key) {
                if (! array_key_exists($key, $environmentBefore)) {
                    putenv($key);
                }
            }
            foreach ($environmentBefore as $key => $value) {
                putenv($key.'='.$value);
            }
            $_ENV = $envBefore;
            $_SERVER = $serverBefore;
            Container::setInstance($containerBefore);
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($facadeBefore);
            if ($poisonedFile) {
                unlink($root.'/.env');
            }
            unlink($root.'/bootstrap/app.php');
            unlink($root.'/config/app.php');
            rmdir($root.'/bootstrap');
            rmdir($root.'/config');
            rmdir($root);
        }
    }

    public static function environmentFiles(): array
    {
        return ['no environment file' => [false], 'poisoned application environment' => [true]];
    }
}
