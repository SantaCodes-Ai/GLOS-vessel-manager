<?php

namespace Tests\Support\MySql;

class Preparation
{
    public const CONFIRMATION = 'PREPARE glos_vessel_manager_test on 127.0.0.1:3306';

    public function __construct(private Guard $guard, private Manifest $manifest) {}

    public function run(Profile $profile, DatabaseProbe $database, string $operator, string $confirmation, callable $migrate): void
    {
        Profile::require($profile->schema && trim($operator) !== '' && mb_strlen($operator) <= 100
            && $confirmation === self::CONFIRMATION, 'Explicit schema-account operator confirmation is required.');
        $identity = $this->guard->identity($profile, $database);
        Profile::require($database->tables() === [], 'Schema preparation requires an empty schema; populated/unrecognised schemas are never reset.');
        $migrate();
        // DDL cannot be rolled back. Any failure leaves an unmarked schema for manual review.
        $this->guard->identity($profile, $database);
        $database->createMarker();
        $this->guard->tables($database);
        Profile::require($database->migrations() === $this->manifest->migrations, 'Migrations did not produce the approved baseline.');
        $this->guard->clean($database, false);
        $database->recordMarker([$profile->ownershipHash(), $this->manifest->hash, $database->schemaHash(), $identity['server_uuid'], trim($operator)]);
        $this->guard->prepared($profile, $database, true);
    }
}
