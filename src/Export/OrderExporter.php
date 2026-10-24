<?php

declare(strict_types=1);

namespace Tmonier\SyliusErpFlatfilePlugin\Export;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Tmonier\SyliusErpFlatfilePlugin\Contract\OrderLinesCsv;
use Tmonier\SyliusErpFlatfilePlugin\Deposit\FileDepositor;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\OutboxEventInterface;

/** One batch: the pending events become one file of the exchange directory. At least once: see the README. */
final readonly class OrderExporter
{
    private OrderLinesCsv $csv;

    public function __construct(
        private PendingOrderEvents $pending,
        private ExportJournal $journal,
        private FileDepositor $depositor,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private int $batchSize,
    ) {
        $this->csv = new OrderLinesCsv();
    }

    public function export(): ExportResult
    {
        $events = $this->pending->next($this->batchSize);
        if ([] === $events) {
            return new ExportResult(null, 0, 0);
        }

        $ids = array_map(static fn (OutboxEventInterface $event): string => $event->getId(), $events);
        $now = $this->clock->now();
        $fileName = sprintf('orders-%s-%s.csv', $now->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z'), substr(hash('sha256', implode(',', $ids)), 0, 8));

        $written = 0;
        $chunks = (function () use ($events, &$written): \Generator {
            foreach ($this->csv->lines($events) as $line) {
                ++$written;

                yield $line;
            }
        })();

        try {
            $this->journal->commit($ids, $fileName, $now, fn () => $this->depositor->deposit($fileName, $chunks));
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('The export of %d order event(s) failed, they stay to export: %s', \count($ids), $e->getMessage()), ['exception' => $e]);

            throw $e;
        }

        return new ExportResult($fileName, \count($ids), $written - 1);
    }
}
