<?php

namespace Tests\Support\MySql;

use PDO;

class DatabaseProbe
{
    public function __construct(protected PDO $pdo) {}

    public function identity(): array
    {
        Profile::require($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql', 'Only the MySQL driver is permitted.');

        return $this->pdo->query('SELECT DATABASE() AS db, CURRENT_USER() AS account, @@port AS port, @@server_uuid AS server_uuid, @@version AS version, @@global.partial_revokes AS partial_revokes')->fetch(PDO::FETCH_ASSOC);
    }

    public function acquireMutex(): bool
    {
        return (int) $this->pdo->query("SELECT GET_LOCK('glos_vessel_manager_test:integration-suite', 0)")->fetchColumn() === 1;
    }

    public function grants(): array
    {
        return $this->pdo->query('SHOW GRANTS FOR CURRENT_USER')->fetchAll(PDO::FETCH_COLUMN);
    }

    public function tables(): array
    {
        return $this->pdo->query('SELECT TABLE_NAME AS name, TABLE_TYPE AS type, ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function migrations(): array
    {
        return $this->pdo->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN);
    }

    public function marker(): array
    {
        return $this->pdo->query('SELECT * FROM mysql_test_ownership ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function schemaHash(): string
    {
        $parts = [];
        foreach ([
            'COLUMNS' => 'TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLLATION_NAME',
            'STATISTICS' => 'TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, SUB_PART',
            'KEY_COLUMN_USAGE' => 'TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME',
            'REFERENTIAL_CONSTRAINTS' => 'TABLE_NAME, CONSTRAINT_NAME, UNIQUE_CONSTRAINT_NAME, UPDATE_RULE, DELETE_RULE',
        ] as $table => $columns) {
            // Fixed metadata queries; no request/profile identifier is interpolated.
            $parts[$table] = $this->pdo->query('SELECT '.$columns.' FROM information_schema.'.$table.' WHERE '.($table === 'REFERENTIAL_CONSTRAINTS' ? 'CONSTRAINT_SCHEMA' : 'TABLE_SCHEMA').' = DATABASE() ORDER BY '.$columns)->fetchAll(PDO::FETCH_ASSOC);
        }

        return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
    }

    public function counts(): array
    {
        $counts = [];
        foreach (Manifest::TABLES as $table) {
            $counts[$table] = (int) $this->pdo->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn();
        }

        return $counts;
    }

    public function createMarker(): void
    {
        $this->pdo->exec('CREATE TABLE mysql_test_ownership (id INT UNSIGNED PRIMARY KEY, ownership_hash CHAR(64) NOT NULL, migration_hash CHAR(64) NOT NULL, schema_hash CHAR(64) NOT NULL, server_uuid CHAR(36) NOT NULL, prepared_by VARCHAR(100) NOT NULL, prepared_at DATETIME NOT NULL, protocol_version INT NOT NULL) ENGINE=InnoDB');
    }

    public function recordMarker(array $values): void
    {
        $statement = $this->pdo->prepare('INSERT INTO mysql_test_ownership (id, ownership_hash, migration_hash, schema_hash, server_uuid, prepared_by, prepared_at, protocol_version) VALUES (1, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), 1)');
        $statement->execute($values);
    }
}
