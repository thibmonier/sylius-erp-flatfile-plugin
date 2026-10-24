<?php

declare(strict_types=1);

namespace Tmonier\SyliusErpFlatfilePlugin\Export;

use Doctrine\ORM\EntityManagerInterface;
use Tmonier\SyliusErpFlatfilePlugin\Entity\ExportedEvent;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\OutboxEventInterface;

/** Reads the outbox as it is (no change to the webhook outbox plugin); the events erased for the GDPR are left out. */
final readonly class DoctrinePendingOrderEvents implements PendingOrderEvents
{
    /**
     * @param class-string<OutboxEventInterface> $outboxEventClass
     * @param list<string>                       $eventTypes
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private string $outboxEventClass,
        private array $eventTypes,
    ) {
    }

    public function next(int $limit): array
    {
        /** @var list<OutboxEventInterface> $events */
        $events = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from($this->outboxEventClass, 'e')
            ->andWhere('e.type IN (:types)')
            ->andWhere('e.anonymizedAt IS NULL')
            ->andWhere(sprintf('NOT EXISTS (SELECT x.eventId FROM %s x WHERE x.eventId = e.id)', ExportedEvent::class))
            ->orderBy('e.occurredAt', 'ASC')
            ->addOrderBy('e.id', 'ASC')
            ->setMaxResults($limit)
            ->setParameter('types', $this->eventTypes)
            ->getQuery()
            ->getResult()
        ;

        return $events;
    }
}
