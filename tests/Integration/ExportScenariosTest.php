<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusErpFlatfilePlugin\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Tests\Tmonier\SyliusErpFlatfilePlugin\Support\CollectingLogger;
use Tests\Tmonier\SyliusErpFlatfilePlugin\Support\OrderEvents;
use Tmonier\SyliusErpFlatfilePlugin\Deposit\AtomicFileDepositor;
use Tmonier\SyliusErpFlatfilePlugin\Deposit\ExchangeDirectoryUnavailable;
use Tmonier\SyliusErpFlatfilePlugin\Entity\ExportedEvent;
use Tmonier\SyliusErpFlatfilePlugin\Export\DbalExportJournal;
use Tmonier\SyliusErpFlatfilePlugin\Export\DoctrinePendingOrderEvents;
use Tmonier\SyliusErpFlatfilePlugin\Export\OrderExporter;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\OutboxEventInterface;

/** The export on the real database: the outbox table, the journal built by the migration, the exchange directory. */
final class ExportScenariosTest extends KernelTestCase
{
    private string $directory;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection()->beginTransaction();
        $this->directory = sys_get_temp_dir() . '/erp-flatfile-it-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        $this->connection()->rollBack();
        foreach (array_merge(glob($this->directory . '/.incoming/*') ?: [], glob($this->directory . '/*') ?: []) as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->directory . '/.incoming')) {
            rmdir($this->directory . '/.incoming');
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    public function testTheMigrationBuildsExactlyTheMappedSchema(): void
    {
        $entityManager = $this->entityManager();
        $statements = (new SchemaTool($entityManager))->getUpdateSchemaSql([$entityManager->getClassMetadata(ExportedEvent::class)]);

        $own = array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'tmonier_erp_flatfile_exported_event') && 1 !== preg_match('/\bDROP\b/', $sql));
        self::assertSame([], array_values($own));
    }

    public function testAPlacedOrderOfTheOutboxIsWrittenToTheExchangeDirectory(): void
    {
        $this->record(OrderEvents::placed(OrderEvents::id(1)));

        $result = $this->exporter($this->directory)->export();

        self::assertNotNull($result->fileName);
        $content = (string) file_get_contents($this->directory . '/' . $result->fileName);
        self::assertStringStartsWith('order_number;webhook_id;', $content);
        self::assertSame(2, substr_count($content, ';' . OrderEvents::id(1) . ';'));
        self::assertSame(1, $this->journalLines());
    }

    public function testAReplayedEventIsNotWrittenTwice(): void
    {
        $this->record(OrderEvents::placed(OrderEvents::id(1)));
        $exporter = $this->exporter($this->directory);

        $exporter->export();
        $second = $exporter->export();

        self::assertNull($second->fileName);
        self::assertCount(1, glob($this->directory . '/*.csv') ?: []);
    }

    public function testTheDatabaseRefusesASecondJournalLineForTheSameEvent(): void
    {
        $journal = new DbalExportJournal($this->connection());
        $at = new \DateTimeImmutable('2026-10-24T10:15:00Z');
        $journal->commit([OrderEvents::id(1)], 'a.csv', $at, static function (): void {});

        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
        $journal->commit([OrderEvents::id(1)], 'b.csv', $at, static function (): void {});
    }

    public function testAnUnavailableDirectoryKeepsTheEventsToExport(): void
    {
        $this->record(OrderEvents::placed(OrderEvents::id(1)));

        try {
            $this->exporter($this->directory . '/absent')->export();
            self::fail('The failure must reach the caller.');
        } catch (ExchangeDirectoryUnavailable) {
        }

        self::assertSame(0, $this->journalLines());
        self::assertCount(1, $this->pending()->next(10));
    }

    public function testAnonymizedEventsAreNotExported(): void
    {
        $event = OrderEvents::placed(OrderEvents::id(1));
        $event->markAnonymized(new \DateTimeImmutable('2026-10-24T10:20:00Z'));
        $this->record($event);

        self::assertSame([], $this->pending()->next(10));
    }

    private function journalLines(): int
    {
        $count = $this->connection()->fetchOne('SELECT COUNT(*) FROM tmonier_erp_flatfile_exported_event');

        return is_numeric($count) ? (int) $count : -1;
    }

    private function exporter(string $directory): OrderExporter
    {
        return new OrderExporter(
            $this->pending(),
            new DbalExportJournal($this->connection()),
            new AtomicFileDepositor($directory),
            new MockClock('2026-10-24T10:15:00Z'),
            new CollectingLogger(),
            500,
        );
    }

    private function pending(): DoctrinePendingOrderEvents
    {
        return new DoctrinePendingOrderEvents($this->entityManager(), $this->outboxEventClass(), ['order.placed']);
    }

    private function record(OutboxEventInterface $template): void
    {
        $class = $this->outboxEventClass();
        $event = new $class($template->getType(), $template->getVersion(), $template->getOccurredAt(), $template->getPayload(), $template->getChannelCode(), $template->getResourceType(), $template->getResourceId(), null, $template->getId());
        if ($template->isAnonymized()) {
            $event->markAnonymized(new \DateTimeImmutable('2026-10-24T10:20:00Z'));
        }
        $this->entityManager()->persist($event);
        $this->entityManager()->flush();
    }

    /** @return class-string<OutboxEventInterface> */
    private function outboxEventClass(): string
    {
        /** @var class-string<OutboxEventInterface> $class */
        $class = self::getContainer()->getParameter('tmonier_webhook.model.outbox_event.class');

        return $class;
    }

    private function connection(): Connection
    {
        return $this->entityManager()->getConnection();
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');

        return $entityManager;
    }
}
