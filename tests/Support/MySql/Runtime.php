<?php

namespace Tests\Support\MySql;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use PDO;

final class Runtime
{
    private static mixed $lock = null;

    private static ?Profile $runner = null;

    private static ?DatabaseProbe $databaseLock = null;

    private static ?string $storage = null;

    public static function ambient(): array
    {
        return array_filter(array_replace(getenv(), $_SERVER, $_ENV), 'is_string');
    }

    public static function lock(string $root): void
    {
        if (self::$lock !== null) {
            return;
        }
        $path = $root.'/.mysql-testing.lock';
        Profile::require(! is_link($path), 'Invalid test mutex.');
        $handle = fopen($path, 'c');
        Profile::require(is_resource($handle) && flock($handle, LOCK_EX | LOCK_NB), 'Another MySQL test/preparation process is active.');
        self::$lock = $handle;
    }

    public static function initializeRunner(string $root, array $arguments): void
    {
        Profile::require((self::ambient()['GLOS_MYSQL_TEST_PROFILE'] ?? null) === 'isolated-v1', 'Use only the dedicated MySQL PHPUnit profile.');
        self::$runner = Profile::load($root, false, self::ambient(), $arguments);
        self::lock($root);
    }

    public static function runner(): Profile
    {
        Profile::require(self::$runner !== null, 'Dedicated MySQL bootstrap was not loaded.');

        return self::$runner;
    }

    public static function application(string $root, Profile $profile, callable $beforeProviders): Application
    {
        Profile::assertAmbient($root, self::ambient(), []);
        $settings = [
            'APP_ENV' => 'testing', 'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
            'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'mysql_testing',
            'DB_HOST' => Profile::HOST, 'DB_PORT' => Profile::PORT, 'DB_DATABASE' => Profile::DATABASE,
            'DB_USERNAME' => $profile->username, 'DB_PASSWORD' => $profile->connection()['password'],
            'DB_URL' => '', 'DB_SOCKET' => '', 'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array', 'FILESYSTEM_DISK' => 'local', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
            'LOG_CHANNEL' => 'null', 'BROADCAST_CONNECTION' => 'null',
            'PULSE_ENABLED' => 'false', 'TELESCOPE_ENABLED' => 'false', 'NIGHTWATCH_ENABLED' => 'false',
        ];
        foreach ($settings as $key => $value) {
            putenv($key.'='.$value);
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
        if (self::$storage === null) {
            self::$storage = sys_get_temp_dir().'/glos-mysql-tests-'.bin2hex(random_bytes(16));
            register_shutdown_function(function () {
                // Only this process's private random test directory is eligible.
                (new Filesystem)->deleteDirectory(self::$storage);
            });
        }
        foreach (['framework/cache/data', 'framework/sessions', 'framework/views', 'logs', 'app/private/certificates', 'app/public'] as $directory) {
            $path = self::$storage.'/'.$directory;
            if (! is_dir($path)) {
                mkdir($path, 0700, true);
            }
        }
        $app = require $root.'/bootstrap/app.php';
        // Replace only this test application's dotenv step; never probe a missing file.
        $app->bind(LoadEnvironmentVariables::class, LoadIsolatedEnvironment::class);
        $app->useStoragePath(self::$storage);
        $app->booting(function () use ($app, $profile, $beforeProviders) {
            $app['config']->set([
                'database.default' => 'mysql_testing', 'database.connections' => ['mysql_testing' => $profile->connection()],
                'cache.default' => 'array', 'cache.stores' => ['array' => ['driver' => 'array', 'serialize' => false]],
                'session.driver' => 'array', 'queue.default' => 'sync', 'queue.connections' => ['sync' => ['driver' => 'sync']],
                'mail.default' => 'array', 'mail.mailers' => ['array' => ['transport' => 'array']], 'logging.default' => 'null',
                'filesystems.default' => 'local',
                'filesystems.disks' => [
                    'local' => ['driver' => 'local', 'root' => self::$storage.'/app/private', 'throw' => true],
                    'certificates' => ['driver' => 'local', 'root' => self::$storage.'/app/private/certificates', 'visibility' => 'private', 'throw' => true, 'serve' => false],
                    'public' => ['driver' => 'local', 'root' => self::$storage.'/app/public', 'visibility' => 'public', 'throw' => true],
                ],
                'view.compiled' => self::$storage.'/framework/views',
            ]);
            $beforeProviders($app);
        });
        $app->make(Kernel::class)->bootstrap();
        Guard::effective($app['config']->all(), $profile);

        return $app;
    }

    public static function databaseMutex(Profile $profile, Guard $guard): DatabaseProbe
    {
        if (self::$databaseLock === null) {
            $database = new DatabaseProbe(self::connect($profile));
            $guard->identity($profile, $database);
            Profile::require($database->acquireMutex(), 'Another test/preparation process owns the MySQL test schema.');
            // Retain the control connection: its advisory lock is released on process exit.
            self::$databaseLock = $database;
        }

        return self::$databaseLock;
    }

    public static function connect(Profile $profile): PDO
    {
        $config = $profile->connection();

        return new PDO('mysql:host='.Profile::HOST.';port='.Profile::PORT.';dbname='.Profile::DATABASE.';charset=utf8mb4',
            $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 5]);
    }
}
