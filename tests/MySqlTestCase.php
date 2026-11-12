<?php

namespace Tests;

use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Foundation\Testing\DatabaseTransactionsManager;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\Support\MySql\DatabaseProbe;
use Tests\Support\MySql\Guard;
use Tests\Support\MySql\Manifest;
use Tests\Support\MySql\Profile;
use Tests\Support\MySql\Runtime;

abstract class MySqlTestCase extends BaseTestCase
{
    private bool $managedTransactionStarted = false;

    final public function createApplication()
    {
        Guard::traits(static::class);
        $this->traitsUsedByTest = class_uses_recursive(static::class);
        $profile = Runtime::runner();
        $root = dirname(__DIR__);
        $guard = new Guard(new Manifest($root));
        $guard->prepared($profile, Runtime::databaseMutex($profile, $guard), true);
        $app = Runtime::application($root, $profile, function ($app) use ($profile, $guard) {
            $connection = $app['db']->connection('mysql_testing');
            $guard->prepared($profile, new DatabaseProbe($connection->getPdo()), true);
            $connection->beforeExecuting(function ($sql) use ($app, $profile, $connection, $guard) {
                Guard::effective($app['config']->all(), $profile);
                Profile::assertAmbient(dirname(__DIR__), Runtime::ambient(), []);
                Guard::statement($sql, $connection->getPdo()->inTransaction());
                if (preg_match('/\A\s*(insert|update|delete)\b/i', $sql)) {
                    $guard->prepared($profile, new DatabaseProbe($connection->getPdo()));
                }
            });
            $app['events']->listen(TransactionCommitting::class, function ($event) {
                Profile::require($event->connection->transactionLevel() > 1, 'Committing the managed MySQL test transaction is forbidden.');
            });
        });

        return $app;
    }

    final protected function setUp(): void
    {
        parent::setUp();
        $manager = new DatabaseTransactionsManager(['mysql_testing']);
        $this->app->instance('db.transactions', $manager);
        $connection = $this->app['db']->connection();
        $connection->setTransactionManager($manager);
        $connection->beginTransaction();
        $this->managedTransactionStarted = true;
        Notification::fake();
        Mail::fake();
    }

    final protected function tearDown(): void
    {
        try {
            if ($this->app && $this->managedTransactionStarted) {
                $connection = $this->app['db']->connection();
                Profile::require($connection->getPdo()->inTransaction(), 'The managed test transaction was lost; manual schema/data review is required.');
                $connection->rollBack(0);
                (new Guard(new Manifest(dirname(__DIR__))))->prepared(Runtime::runner(), new DatabaseProbe($connection->getPdo()), true);
            }
        } finally {
            parent::tearDown();
        }
    }
}
