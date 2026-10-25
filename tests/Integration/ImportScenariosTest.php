<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusErpFlatfilePlugin\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ChannelPricingInterface;
use Sylius\Component\Core\Model\Product;
use Sylius\Component\Core\Model\ProductVariant;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Repository\ProductVariantRepositoryInterface;
use Sylius\Component\Currency\Model\Currency;
use Sylius\Component\Locale\Model\Locale;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Tmonier\SyliusErpFlatfilePlugin\Entity\ImportedBatch;
use Tmonier\SyliusErpFlatfilePlugin\Import\BatchImporter;
use Tmonier\SyliusErpFlatfilePlugin\Import\BatchOutcome;
use Tmonier\SyliusErpFlatfilePlugin\Import\DbalImportJournal;
use Tmonier\SyliusErpFlatfilePlugin\Import\DoctrineCatalog;
use Tmonier\SyliusErpFlatfilePlugin\Import\ImportKind;

/** The import on the real database: the catalogue of Sylius (channel pricing, stock), the journal of the batches built by the migration. */
final class ImportScenariosTest extends KernelTestCase
{
    private const PRICES = "variant_code;price_minor;currency;channel\n";

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection()->beginTransaction();
        $this->catalogue();
    }

    protected function tearDown(): void
    {
        $this->connection()->rollBack();
        parent::tearDown();
    }

    public function testTheMigrationBuildsExactlyTheMappedSchemaOfTheJournal(): void
    {
        $entityManager = $this->entityManager();
        $statements = (new SchemaTool($entityManager))->getUpdateSchemaSql([$entityManager->getClassMetadata(ImportedBatch::class)]);

        $own = array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'tmonier_erp_flatfile_import_batch') && 1 !== preg_match('/\bDROP\b/', $sql));
        self::assertSame([], array_values($own));
    }

    public function testThePricesAreWrittenPerChannelAndAnExistingPriceIsUpdatedNotDuplicated(): void
    {
        $importer = $this->importer();

        $first = $importer->import(ImportKind::Prices, 'prices-1.csv', self::PRICES . "MUG-RED;1250;EUR;WEB\nMUG-RED;1100;EUR;B2B\n");
        $second = $importer->import(ImportKind::Prices, 'prices-2.csv', self::PRICES . "MUG-RED;1300;EUR;WEB\n");

        self::assertSame([BatchOutcome::Applied, BatchOutcome::Applied], [$first->outcome, $second->outcome]);
        self::assertSame(['B2B' => 1100, 'WEB' => 1300], $this->prices('MUG-RED'));
    }

    public function testTheStockIsAbsoluteAndTheHeldQuantityIsNotTouched(): void
    {
        $this->connection()->executeStatement('UPDATE sylius_product_variant SET on_hand = 5, on_hold = 3 WHERE code = ?', ['MUG-RED']);

        $report = $this->importer()->import(ImportKind::Stock, 'stock-1.csv', "variant_code;on_hand\nMUG-RED;40\nMUG-NOPE;1\n");

        self::assertSame(BatchOutcome::Partial, $report->outcome);
        self::assertSame([40, 3], array_map(static fn (mixed $value): int => is_numeric($value) ? (int) $value : -1, array_values((array) $this->connection()->fetchAssociative('SELECT on_hand, on_hold FROM sylius_product_variant WHERE code = ?', ['MUG-RED']))));
    }

    public function testOneInvalidPriceLeavesTheDatabaseUntouched(): void
    {
        $report = $this->importer()->import(ImportKind::Prices, 'prices-1.csv', self::PRICES . "MUG-RED;1250;EUR;WEB\nMUG-RED;900;USD;B2B\n");

        self::assertSame(BatchOutcome::Rejected, $report->outcome);
        self::assertSame([], $this->prices('MUG-RED'));
    }

    public function testABatchIsRecordedOnceWithItsReportAndIgnoredWhenItComesAgain(): void
    {
        $content = self::PRICES . "MUG-RED;1250;EUR;WEB\nNOPE;10;EUR;WEB\n";
        $importer = $this->importer();
        $first = $importer->import(ImportKind::Prices, 'prices-1.csv', $content);
        $again = $importer->import(ImportKind::Prices, 'prices-1.csv', $content);

        self::assertSame(BatchOutcome::Rejected, $first->outcome);
        self::assertSame(BatchOutcome::Duplicate, $again->outcome);
        $row = $this->connection()->fetchAssociative('SELECT * FROM tmonier_erp_flatfile_import_batch WHERE name = ?', ['prices-1.csv']);
        self::assertIsArray($row);
        self::assertSame(1, $this->number($this->connection()->fetchOne('SELECT COUNT(*) FROM tmonier_erp_flatfile_import_batch')));
        self::assertSame([2, 0, 1, 'rejected'], [$this->number($row['lines_read']), $this->number($row['lines_applied']), $this->number($row['lines_rejected']), $row['outcome']]);
        self::assertStringContainsString('unknown_variant', \is_string($row['report']) ? $row['report'] : '');
    }

    public function testTheDatabaseRefusesASecondLineForTheSameNameAndChecksum(): void
    {
        $report = $this->importer()->import(ImportKind::Prices, 'prices-1.csv', self::PRICES . "NOPE;1;EUR;WEB\n");

        $this->expectException(UniqueConstraintViolationException::class);
        (new DbalImportJournal($this->connection(), new MockClock('2026-10-25T10:00:00Z')))->record($report);
    }

    private function importer(): BatchImporter
    {
        $catalog = new DoctrineCatalog($this->entityManager(), $this->factory(), $this->variants(), $this->channels());

        return new BatchImporter($catalog, $catalog, new DbalImportJournal($this->connection(), new MockClock('2026-10-25T10:00:00Z')));
    }

    /** @return array<string, int> */
    private function prices(string $variantCode): array
    {
        $this->entityManager()->clear();
        $rows = $this->connection()->fetchAllAssociative('SELECT cp.channel_code, cp.price FROM sylius_channel_pricing cp JOIN sylius_product_variant v ON v.id = cp.product_variant_id WHERE v.code = ? ORDER BY cp.channel_code', [$variantCode]);

        return array_column(array_map(fn (array $row): array => [\is_string($row['channel_code']) ? $row['channel_code'] : '', $this->number($row['price'])], $rows), 1, 0);
    }

    private function catalogue(): void
    {
        $entityManager = $this->entityManager();
        $currency = new Currency();
        $currency->setCode('EUR');
        $locale = new Locale();
        $locale->setCode('en_US');
        $entityManager->persist($currency);
        $entityManager->persist($locale);
        foreach (['WEB', 'B2B'] as $code) {
            $channel = new Channel();
            $channel->setCode($code);
            $channel->setName($code);
            $channel->setBaseCurrency($currency);
            $channel->setDefaultLocale($locale);
            $channel->setTaxCalculationStrategy('order_items_based');
            $entityManager->persist($channel);
        }
        $product = new Product();
        $product->setCode('MUG');
        $product->setCurrentLocale('en_US');
        $product->setFallbackLocale('en_US');
        $product->setName('Mug');
        $product->setSlug('mug');
        $variant = new ProductVariant();
        $variant->setCode('MUG-RED');
        $variant->setProduct($product);
        $variant->setCurrentLocale('en_US');
        $variant->setFallbackLocale('en_US');
        $product->addVariant($variant);
        $entityManager->persist($product);
        $entityManager->flush();
        $entityManager->clear();
    }

    /** @return FactoryInterface<ChannelPricingInterface> */
    private function factory(): FactoryInterface
    {
        $factory = self::getContainer()->get('sylius.factory.channel_pricing');
        self::assertInstanceOf(FactoryInterface::class, $factory);

        return $factory;
    }

    /** @return ProductVariantRepositoryInterface<ProductVariantInterface> */
    private function variants(): ProductVariantRepositoryInterface
    {
        $repository = self::getContainer()->get('sylius.repository.product_variant');
        self::assertInstanceOf(ProductVariantRepositoryInterface::class, $repository);

        return $repository;
    }

    /** @return RepositoryInterface<ChannelInterface> */
    private function channels(): RepositoryInterface
    {
        $repository = self::getContainer()->get('sylius.repository.channel');
        self::assertInstanceOf(RepositoryInterface::class, $repository);

        return $repository;
    }

    private function number(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : -1;
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
