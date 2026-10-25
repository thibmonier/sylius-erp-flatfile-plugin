<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusErpFlatfilePlugin\Unit\Import;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Tmonier\SyliusErpFlatfilePlugin\Support\InMemoryCatalog;
use Tests\Tmonier\SyliusErpFlatfilePlugin\Support\InMemoryImportJournal;
use Tmonier\SyliusErpFlatfilePlugin\Import\BatchImporter;
use Tmonier\SyliusErpFlatfilePlugin\Import\BatchOutcome;
use Tmonier\SyliusErpFlatfilePlugin\Import\ImportKind;

/**
 * The contract of the import (ADR 0017): prices `variant_code;price_minor;currency;channel`, stock `variant_code;on_hand`; UTF-8,
 * ";", a header, integers; a batch is its file name and its checksum, a batch already processed is ignored; prices all or nothing,
 * stock line by line; every refusal is a line of the report with a reason code.
 */
final class BatchImporterTest extends TestCase
{
    private const PRICES = "variant_code;price_minor;currency;channel\n";

    private const STOCK = "variant_code;on_hand\n";

    private InMemoryCatalog $catalog;

    private InMemoryImportJournal $journal;

    protected function setUp(): void
    {
        $this->catalog = new InMemoryCatalog();
        $this->journal = new InMemoryImportJournal();
    }

    public function testAValidBatchOfPricesIsAppliedPerChannelAndRecorded(): void
    {
        $report = $this->importer()->import(ImportKind::Prices, 'prices-1.csv', self::PRICES . "MUG-RED;1250;EUR;WEB\nMUG-RED;1100;EUR;B2B\nBOX-10;990;USD;US\n");

        self::assertSame(BatchOutcome::Applied, $report->outcome);
        self::assertSame([3, 3, 0], [$report->read, $report->applied, $report->rejected]);
        self::assertSame(['MUG-RED@WEB' => 1250, 'MUG-RED@B2B' => 1100, 'BOX-10@US' => 990], $this->catalog->prices);
        self::assertCount(1, $this->journal->recorded);
        self::assertSame(hash('sha256', self::PRICES . "MUG-RED;1250;EUR;WEB\nMUG-RED;1100;EUR;B2B\nBOX-10;990;USD;US\n"), $report->checksum);
    }

    public function testAPartiallyValidBatchOfStockAppliesTheValidLinesAndReportsTheOthers(): void
    {
        $report = $this->importer()->import(ImportKind::Stock, 'stock-1.csv', self::STOCK . "MUG-RED;40\nNOPE;5\nMUG-BLUE;-3\nBOX-10;12\n");

        self::assertSame(BatchOutcome::Partial, $report->outcome);
        self::assertSame([4, 2, 2], [$report->read, $report->applied, $report->rejected]);
        self::assertSame(['MUG-RED' => 40, 'BOX-10' => 12], $this->catalog->onHand);
        self::assertSame([[3, 'unknown_variant'], [4, 'on_hand']], array_map(static fn ($line): array => [$line->line, $line->reason], $report->rejections));
    }

    public function testTheStockNeverGoesBelowWhatIsOnHoldAndAVariantTwiceInOneFileIsRefused(): void
    {
        $report = $this->importer()->import(ImportKind::Stock, 'stock-1.csv', self::STOCK . "MUG-BLUE;1\nMUG-BLUE;5\n");

        self::assertSame([[2, 'below_on_hold'], [3, 'duplicate']], array_map(static fn ($line): array => [$line->line, $line->reason], $report->rejections), 'MUG-BLUE has 2 on hold: on_hand 1 would sell what is held');
        self::assertSame([], $this->catalog->onHand);
        self::assertSame(BatchOutcome::Rejected, $report->outcome);
    }

    public function testABatchAlreadyProcessedIsIgnoredAndNothingIsWrittenTwice(): void
    {
        $content = self::PRICES . "MUG-RED;1250;EUR;WEB\n";
        $importer = $this->importer();
        $importer->import(ImportKind::Prices, 'prices-1.csv', $content);
        $writes = $this->catalog->writes;

        $second = $importer->import(ImportKind::Prices, 'prices-1.csv', $content);

        self::assertSame(BatchOutcome::Duplicate, $second->outcome);
        self::assertSame($writes, $this->catalog->writes);
        self::assertCount(1, $this->journal->recorded, 'the journal keeps one line per batch');
    }

    public function testTheSameNameWithAnotherContentIsANewBatch(): void
    {
        $importer = $this->importer();
        $importer->import(ImportKind::Prices, 'prices-1.csv', self::PRICES . "MUG-RED;1250;EUR;WEB\n");

        $second = $importer->import(ImportKind::Prices, 'prices-1.csv', self::PRICES . "MUG-RED;1300;EUR;WEB\n");

        self::assertSame(BatchOutcome::Applied, $second->outcome);
        self::assertSame(1300, $this->catalog->prices['MUG-RED@WEB']);
    }

    public function testOneInvalidPriceRejectsTheWholeBatchAndWritesNothing(): void
    {
        $report = $this->importer()->import(ImportKind::Prices, 'prices-1.csv', self::PRICES . "MUG-RED;1250;EUR;WEB\nMUG-BLUE;980;GBP;WEB\nBOX-10;-5;EUR;B2B\nBOX-10;10;EUR;MARS\n");

        self::assertSame(BatchOutcome::Rejected, $report->outcome);
        self::assertSame([4, 0, 3], [$report->read, $report->applied, $report->rejected]);
        self::assertSame([], $this->catalog->prices, 'all or nothing');
        self::assertSame(0, $this->catalog->writes);
        self::assertSame([[3, 'currency'], [4, 'price'], [5, 'channel']], array_map(static fn ($line): array => [$line->line, $line->reason], $report->rejections));
        self::assertCount(1, $this->journal->recorded, 'the rejection is journalled with its report');
    }

    /** @return iterable<string, array{string, ImportKind, string}> */
    public static function unreadableFiles(): iterable
    {
        yield 'another encoding' => ["variant_code;on_hand\nMUG-RED;4\xff\n", ImportKind::Stock, 'encoding'];
        yield 'a byte order mark' => ["\xEF\xBB\xBFvariant_code;on_hand\nMUG-RED;4\n", ImportKind::Stock, 'encoding'];
        yield 'truncated: the last line has no line break' => ["variant_code;on_hand\nMUG-RED;4\nBOX-10;1", ImportKind::Stock, 'truncated'];
        yield 'empty' => ['', ImportKind::Stock, 'empty'];
        yield 'only the header' => ["variant_code;on_hand\n", ImportKind::Stock, 'empty'];
        yield 'another header' => ["sku;qty\nMUG-RED;4\n", ImportKind::Stock, 'header'];
        yield 'the header of the other kind' => ["variant_code;on_hand\nMUG-RED;4\n", ImportKind::Prices, 'header'];
    }

    #[DataProvider('unreadableFiles')]
    public function testAnUnreadableFileIsRejectedWholeWithItsReason(string $content, ImportKind $kind, string $reason): void
    {
        $report = $this->importer()->import($kind, 'file.csv', $content);

        self::assertSame(BatchOutcome::Rejected, $report->outcome);
        self::assertSame(0, $report->applied);
        self::assertSame($reason, $report->rejections[0]->reason);
        self::assertSame(0, $report->rejections[0]->line, 'the whole file, not a line');
        self::assertSame([], $this->catalog->onHand);
        self::assertCount(1, $this->journal->recorded);
    }

    public function testALineWithTheWrongNumberOfColumnsIsRefusedWithoutStoppingTheOthers(): void
    {
        $report = $this->importer()->import(ImportKind::Stock, 'stock-1.csv', self::STOCK . "MUG-RED;40;extra\nBOX-10;12\n");

        self::assertSame([[2, 'columns']], array_map(static fn ($line): array => [$line->line, $line->reason], $report->rejections));
        self::assertSame(['BOX-10' => 12], $this->catalog->onHand);
    }

    private function importer(): BatchImporter
    {
        return new BatchImporter($this->catalog, $this->catalog, $this->journal);
    }
}
