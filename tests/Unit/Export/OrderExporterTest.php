<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusErpFlatfilePlugin\Unit\Export;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Tests\Tmonier\SyliusErpFlatfilePlugin\Support\CollectingLogger;
use Tests\Tmonier\SyliusErpFlatfilePlugin\Support\InMemoryExchange;
use Tests\Tmonier\SyliusErpFlatfilePlugin\Support\OrderEvents;
use Tests\Tmonier\SyliusErpFlatfilePlugin\Support\RecordingDepositor;
use Tmonier\SyliusErpFlatfilePlugin\Deposit\ExchangeDirectoryUnavailable;
use Tmonier\SyliusErpFlatfilePlugin\Deposit\FileDepositor;
use Tmonier\SyliusErpFlatfilePlugin\Export\OrderExporter;

final class OrderExporterTest extends TestCase
{
    public function testThreePendingOrdersAreInOneFileWithAUniqueName(): void
    {
        $exchange = new InMemoryExchange([OrderEvents::placed(OrderEvents::id(1)), OrderEvents::placed(OrderEvents::id(2)), OrderEvents::placed(OrderEvents::id(3))]);
        $depositor = new RecordingDepositor();

        $result = $this->exporter($exchange, $depositor)->export();

        self::assertCount(1, $depositor->files);
        self::assertSame(3, $result->events);
        self::assertSame(6, $result->lines);
        self::assertSame(array_key_first($depositor->files), $result->fileName);
        self::assertMatchesRegularExpression('/^orders-20261024T101500Z-[0-9a-f]{8}\.csv$/', (string) $result->fileName);
        self::assertNotNull($result->fileName);
        self::assertSame(6 + 1, substr_count($depositor->files[$result->fileName], "\n"));
    }

    public function testTwoBatchesGetTwoDifferentNames(): void
    {
        $exchange = new InMemoryExchange([OrderEvents::placed(OrderEvents::id(1)), OrderEvents::placed(OrderEvents::id(2))]);
        $depositor = new RecordingDepositor();

        $this->exporter($exchange, $depositor, batchSize: 1)->export();
        $this->exporter($exchange, $depositor, batchSize: 1)->export();

        self::assertCount(2, $depositor->files);
    }

    public function testAnEventAlreadyExportedIsNotWrittenAgain(): void
    {
        $exchange = new InMemoryExchange([OrderEvents::placed()]);
        $depositor = new RecordingDepositor();
        $exporter = $this->exporter($exchange, $depositor);

        $exporter->export();
        $second = $exporter->export();

        self::assertCount(1, $depositor->files);
        self::assertNull($second->fileName);
        self::assertSame(0, $second->events);
    }

    public function testOtherEventTypesAreLeftAlone(): void
    {
        $exchange = new InMemoryExchange([OrderEvents::placed(type: 'order.paid')], ['order.placed']);
        $depositor = new RecordingDepositor();

        $result = $this->exporter($exchange, $depositor)->export();

        self::assertNull($result->fileName);
        self::assertSame([], $depositor->files);
    }

    public function testAnUnavailableDirectoryLogsAClearErrorAndKeepsTheEventsToExport(): void
    {
        $exchange = new InMemoryExchange([OrderEvents::placed()]);
        $logger = new CollectingLogger();
        $failing = new class() implements FileDepositor {
            public function deposit(string $fileName, iterable $chunks): void
            {
                throw new ExchangeDirectoryUnavailable('The exchange directory "/srv/exchange" does not exist.');
            }
        };

        try {
            $this->exporter($exchange, $failing, logger: $logger)->export();
            self::fail('The failure must reach the caller.');
        } catch (ExchangeDirectoryUnavailable) {
        }

        self::assertSame([], $exchange->exported);
        self::assertCount(1, $exchange->next(10));
        self::assertSame('error', $logger->records[0]['level']);
        self::assertStringContainsString('/srv/exchange', $logger->records[0]['message']);
    }

    private function exporter(InMemoryExchange $exchange, FileDepositor $depositor, int $batchSize = 500, ?CollectingLogger $logger = null): OrderExporter
    {
        return new OrderExporter(
            $exchange,
            $exchange,
            $depositor,
            new MockClock('2026-10-24T10:15:00Z'),
            $logger ?? new CollectingLogger(),
            $batchSize,
        );
    }
}
