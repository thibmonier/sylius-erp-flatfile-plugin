<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusErpFlatfilePlugin\Support;

use Tmonier\SyliusErpFlatfilePlugin\Export\ExportJournal;
use Tmonier\SyliusErpFlatfilePlugin\Export\PendingOrderEvents;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\OutboxEventInterface;

/** The outbox and the journal in memory: an event is pending until the journal holds it. */
final class InMemoryExchange implements PendingOrderEvents, ExportJournal
{
    /** @var array<string, string> event id => file name */
    public array $exported = [];

    /**
     * @param list<OutboxEventInterface> $events
     * @param list<string>               $types
     */
    public function __construct(private readonly array $events, private readonly array $types = ['order.placed'])
    {
    }

    public function next(int $limit): array
    {
        $pending = array_filter($this->events, fn (OutboxEventInterface $event): bool => \in_array($event->getType(), $this->types, true) && !isset($this->exported[$event->getId()]));

        return \array_slice(array_values($pending), 0, $limit);
    }

    public function commit(array $eventIds, string $fileName, \DateTimeImmutable $at, \Closure $deposit): void
    {
        $before = $this->exported;
        foreach ($eventIds as $id) {
            $this->exported[$id] = $fileName;
        }

        try {
            $deposit();
        } catch (\Throwable $e) {
            $this->exported = $before;

            throw $e;
        }
    }
}
