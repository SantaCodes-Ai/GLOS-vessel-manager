<?php

namespace Tests\Integration\MySql;

use Tests\MySqlTestCase;

final class InfrastructureSmokeTest extends MySqlTestCase
{
    public function test_the_prepared_schema_is_guarded_and_dml_is_transactional(): void
    {
        $connection = $this->app['db']->connection();
        $this->assertSame('glos_vessel_manager_test', $connection->getDatabaseName());
        $this->assertSame(1, $connection->transactionLevel());
        $this->assertTrue($connection->getPdo()->inTransaction());
    }
}
