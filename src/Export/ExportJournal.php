<?php

declare(strict_types=1);

namespace Tmonier\SyliusErpFlatfilePlugin\Export;

/** Remembers which events a file holds. The webhook id (the id of the event) is the idempotence key. */
interface ExportJournal
{
    /**
     * Records the events, then runs the deposit in the same transaction: when the deposit throws, nothing is recorded.
     *
     * @param list<string> $eventIds
     */
    public function commit(array $eventIds, string $fileName, \DateTimeImmutable $at, \Closure $deposit): void;
}
