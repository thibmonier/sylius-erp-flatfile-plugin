<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusErpFlatfilePlugin\Unit\Import;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Tmonier\SyliusErpFlatfilePlugin\Support\InMemoryCatalog;
use Tests\Tmonier\SyliusErpFlatfilePlugin\Support\InMemoryImportJournal;
use Tmonier\SyliusErpFlatfilePlugin\Command\ImportBatchesCommand;
use Tmonier\SyliusErpFlatfilePlugin\Import\BatchImporter;
use Tmonier\SyliusErpFlatfilePlugin\Import\ExchangeDirectoryImporter;

final class ImportBatchesCommandTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/erp-import-cmd-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (array_merge(glob($this->directory . '/processed/*') ?: [], glob($this->directory . '/*') ?: []) as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->directory . '/processed')) {
            rmdir($this->directory . '/processed');
        }
        rmdir($this->directory);
    }

    public function testItPrintsOneLinePerBatchAndTheRejectedLines(): void
    {
        file_put_contents($this->directory . '/stock-1.csv', "variant_code;on_hand\nMUG-RED;40\nNOPE;1\n");

        $tester = $this->tester($this->directory);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('stock-1.csv: partial, 2 read, 1 applied, 1 rejected.', $tester->getDisplay());
        self::assertStringContainsString('line 3: unknown_variant', $tester->getDisplay());
    }

    public function testNothingToImportIsASuccess(): void
    {
        $tester = $this->tester($this->directory);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Nothing to import.', $tester->getDisplay());
    }

    public function testARejectedBatchIsAFailureCode(): void
    {
        file_put_contents($this->directory . '/stock-1.csv', "sku;qty\n");

        self::assertSame(Command::FAILURE, $this->tester($this->directory)->execute([]));
    }

    public function testAMissingDirectoryIsAClearMessageAndAFailureCode(): void
    {
        $tester = $this->tester($this->directory . '/absent');

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('does not exist', $tester->getDisplay());
    }

    private function tester(string $directory): CommandTester
    {
        $catalog = new InMemoryCatalog();

        return new CommandTester(new ImportBatchesCommand(new ExchangeDirectoryImporter($directory, new BatchImporter($catalog, $catalog, new InMemoryImportJournal()))));
    }
}
