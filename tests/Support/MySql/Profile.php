<?php

namespace Tests\Support\MySql;

use Dotenv\Dotenv;

final readonly class Profile
{
    public const DATABASE = 'glos_vessel_manager_test';

    public const HOST = '127.0.0.1';

    public const PORT = '3306';

    private function __construct(
        public string $username,
        #[\SensitiveParameter] private string $password,
        #[\SensitiveParameter] private string $token,
        public bool $schema,
    ) {}

    public static function load(string $root, bool $schema, array $ambient, array $arguments = []): self
    {
        self::assertAmbient($root, $ambient, $arguments);
        $path = $root.($schema ? '/.env.testing.mysql.schema' : '/.env.testing.mysql');
        self::require(is_file($path) && ! is_link($path) && is_readable($path), 'A private MySQL test profile is required.');
        self::require((fileperms($path) & 0077) === 0 && filesize($path) <= 16384, 'Test credentials must be private (chmod 600).');
        try {
            $values = Dotenv::parse(file_get_contents($path));
        } catch (\Throwable) {
            throw new UnsafeMySqlEnvironment('Invalid private MySQL test profile.');
        }

        return self::fromValues($values, $schema);
    }

    public static function fromValues(#[\SensitiveParameter] array $values, bool $schema = false): self
    {
        $expected = [
            'APP_ENV' => 'testing', 'MYSQL_TEST_HOST' => self::HOST, 'MYSQL_TEST_PORT' => self::PORT,
            'MYSQL_TEST_DATABASE' => self::DATABASE, 'MYSQL_TEST_USERNAME' => $schema ? 'glos_test_schema' : 'glos_test_runner',
        ];
        foreach ($expected as $key => $value) {
            self::require(($values[$key] ?? null) === $value, 'MySQL test profile identity is not approved.');
        }
        $allowed = [...array_keys($expected), 'MYSQL_TEST_PASSWORD', 'MYSQL_TEST_OWNERSHIP_TOKEN'];
        self::require(array_diff(array_keys($values), $allowed) === [], 'Unexpected test profile settings.');
        self::require(is_string($values['MYSQL_TEST_PASSWORD'] ?? null) && strlen($values['MYSQL_TEST_PASSWORD']) >= 16, 'A dedicated test password of at least 16 characters is required.');
        self::require(is_string($values['MYSQL_TEST_OWNERSHIP_TOKEN'] ?? null) && preg_match('/\A[a-f0-9]{64}\z/', $values['MYSQL_TEST_OWNERSHIP_TOKEN']) === 1, 'A private 64-character hex ownership token is required.');

        return new self($expected['MYSQL_TEST_USERNAME'], $values['MYSQL_TEST_PASSWORD'], $values['MYSQL_TEST_OWNERSHIP_TOKEN'], $schema);
    }

    public static function assertAmbient(string $root, array $ambient, array $arguments): void
    {
        self::require(! is_file($root.'/bootstrap/cache/config.php') && ($ambient['APP_CONFIG_CACHE'] ?? '') === '', 'Cached Laravel configuration is forbidden.');
        foreach (['DB_URL', 'DATABASE_URL', 'DB_SOCKET', 'TEST_TOKEN', 'PARATEST', 'LARAVEL_PARALLEL_TESTING'] as $key) {
            self::require(($ambient[$key] ?? '') === '', 'URL, socket and parallel-testing overrides are forbidden.');
        }
        foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql_testing', 'DB_DATABASE' => self::DATABASE, 'DB_HOST' => self::HOST, 'DB_PORT' => self::PORT] as $key => $expected) {
            self::require(! isset($ambient[$key]) || (string) $ambient[$key] === $expected, 'Unexpected ambient database configuration.');
        }
        foreach (array_keys($ambient) as $key) {
            self::require(! preg_match('/\ADB_(READ|WRITE)_/', $key), 'Read/write database overrides are forbidden.');
        }
        foreach ($arguments as $argument) {
            self::require(! preg_match('/\A--(?:parallel|processes|recreate-databases|without-databases)/', $argument), 'Parallel database execution is not enabled.');
        }
    }

    public function connection(): array
    {
        return ['driver' => 'mysql', 'host' => self::HOST, 'port' => self::PORT, 'database' => self::DATABASE,
            'username' => $this->username, 'password' => $this->password, 'url' => null, 'unix_socket' => '',
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true, 'engine' => 'InnoDB'];
    }

    public function ownershipHash(): string
    {
        return hash('sha256', $this->token);
    }

    public function __debugInfo(): array
    {
        return ['database' => self::DATABASE, 'account' => $this->username, 'secrets' => '[redacted]'];
    }

    public static function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new UnsafeMySqlEnvironment($message);
        }
    }
}
