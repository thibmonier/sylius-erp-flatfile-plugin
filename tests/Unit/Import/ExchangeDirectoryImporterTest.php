<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusErpFlatfilePlugin\Unit\Import;

use PHPUnit\Framework\TestCase;
use Tests\Tmonier\SyliusErpFlatfilePlugin\Support\InMemoryCatalog;
use Tests\Tmonier\SyliusErpFlatfilePlugin\Support\InMemoryImportJournal;
use Tmonier\SyliusErpFlatfilePlugin\Deposit\ExchangeDirectoryUnavailable;
use Tmonier\SyliusErpFlatfilePlugin\Import\BatchImporter;
use Tmonier\SyliusErpFlatfilePlugin\Import\BatchOutcome;
use Tmonier\SyliusErpFlatfilePlugin\Import\ExchangeDirectoryImporter;

/**
 * The files `prices-*.csv` and `stock-*.csv` of the exchange directory are imported oldest name first, then moved to its `processed`
 * subdirectory (a rename: atomic); the files of orders and the acknowledgements are not its business.
 */
final class ExchangeDirectoryImporterTest extends TestCase
{
    private string $directory;

    private InMemoryCatalog $catalog;

    private InMemoryImportJournal $journal;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/erp-import-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
        $this->catalog = new InMemoryCatalog();
        $this->journal = new InMemoryImportJournal();
    }

    protected function tearDown(): void
    {
        foreach (array_merge(glob($this->directory . '/processed/*') ?: [], glob($this->directory . '/*') ?: []) as $file) {
            is_file($file) && unlink($file);
        }
        is_dir($this->directory . '/processed') && rmdir($this->directory . '/processed');
        is_dir($this->directory) && chmod($this->directory, 0777);
        rmdir($this->directory);
    }

    public function testThePricesAndStockFilesAreImportedAndMovedToTheProcessedDirectory(): void
    {
        $this->file('prices-20261025.csv', "variant_code;price_minor;currency;channel\nMUG-RED;1250;EUR;WEB\n");
        $this->file('stock-20261025.csv', "variant_code;on_hand\nMUG-RED;40\n");

        $reports = $this->importer()->importPending();

        self::assertSame(['prices-20261025.csv', 'stock-20261025.csv'], array_map(static fn ($report): string => $report->name, $reports));
        self::assertSame([BatchOutcome::Applied, BatchOutcome::Applied], array_map(static fn ($report): BatchOutcome => $report->outcome, $reports));
        self::assertSame(['MUG-RED@WEB' => 1250], $this->catalog->prices);
        self::assertSame(['MUG-RED' => 40], $this->catalog->onHand);
        self::assertSame([], glob($this->directory . '/*.csv'), 'moved away');
        self::assertSame(['prices-20261025.csv', 'stock-20261025.csv'], array_map('basename', glob($this->directory . '/processed/*.csv') ?: []));
    }

    public function testTheFilesOfOrdersAndTheAcknowledgementsAreLeftAlone(): void
    {
        $this->file('orders-20261025T100000Z-aaaaaaaa.csv', "header\nline\n");
        $this->file('ack-orders-20261025T100000Z-aaaaaaaa.csv', "header\nline\n");

        self::assertSame([], $this->importer()->importPending());

        self::assertCount(2, glob($this->directory . '/*.csv') ?: []);
    }

    public function testARejectedFileIsMovedTooAndAFileDroppedAgainIsReportedAsAlreadyProcessed(): void
    {
        $content = "variant_code;on_hand\nNOPE;1\n";
        $this->file('stock-1.csv', $content);
        $first = $this->importer()->importPending();
        $this->file('stock-1.csv', $content);

        $second = $this->importer()->importPending();

        self::assertSame(BatchOutcome::Rejected, $first[0]->outcome);
        self::assertSame(BatchOutcome::Duplicate, $second[0]->outcome);
        self::assertSame([], glob($this->directory . '/*.csv'));
        self::assertCount(2, glob($this->directory . '/processed/*.csv') ?: [], 'the second copy does not erase the first');
    }

    public function testNothingToImportIsNotAnError(): void
    {
        self::assertSame([], $this->importer()->importPending());
        self::assertDirectoryDoesNotExist($this->directory . '/processed');
    }

    public function testAMissingDirectoryIsReportedWithoutCreatingIt(): void
    {
        $missing = $this->directory . '/absent';

        try {
            (new ExchangeDirectoryImporter($missing, new BatchImporter($this->catalog, $this->catalog, $this->journal)))->importPending();
            self::fail('A missing exchange directory must be refused.');
        } catch (ExchangeDirectoryUnavailable $e) {
            self::assertStringContainsString($missing, $e->getMessage());
        }

        self::assertDirectoryDoesNotExist($missing);
    }

    private function file(string $name, string $content): void
    {
        file_put_contents($this->directory . '/' . $name, $content);
    }

    private function importer(): ExchangeDirectoryImporter
    {
        return new ExchangeDirectoryImporter($this->directory, new BatchImporter($this->catalog, $this->catalog, $this->journal));
    }
}
