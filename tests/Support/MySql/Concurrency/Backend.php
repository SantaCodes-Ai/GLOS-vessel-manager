<?php

namespace Tests\Support\MySql\Concurrency;

interface Backend
{
    public function verify(bool $clean): void;

    public function mutexOwner(): int;

    /** All business rows, indexed by table then primary ID. Never written to logs/journals. */
    public function rows(): array;

    /** Fixed three-record scenario only; journal identities before committing. */
    public function createFixture(Journal $journal, int $mutexOwner): void;

    /** Must check the snapshot again under locks and delete atomically. */
    public function deleteExact(array $snapshot, int $mutexOwner): void;
}
