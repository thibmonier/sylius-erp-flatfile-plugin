<?php

declare(strict_types=1);

namespace Tmonier\SyliusErpFlatfilePlugin\Import;

use Tmonier\SyliusErpFlatfilePlugin\Deposit\ExchangeDirectoryUnavailable;

/**
 * The files `prices-*.csv` and `stock-*.csv` of the exchange directory, imported by name order, then moved to its `processed`
 * subdirectory (a rename inside one file system: atomic, so a file is never seen half moved). The other files (orders, acknowledgements,
 * anything else) are not its business. A file of the same name already in `processed` is not erased: the new one is prefixed with
 * the start of its checksum.
 */
final readonly class ExchangeDirectoryImporter
{
    public const PROCESSED = 'processed';

    public function __construct(private string $directory, private BatchImporter $importer)
    {
    }

    /** @return list<ImportReport> */
    public function importPending(): array
    {
        if (!is_dir($this->directory) || !is_readable($this->directory)) {
            throw new ExchangeDirectoryUnavailable(sprintf('The exchange directory "%s" does not exist or is not readable.', $this->directory));
        }

        $files = [...(glob(rtrim($this->directory, '/') . '/prices-*.csv') ?: []), ...(glob(rtrim($this->directory, '/') . '/stock-*.csv') ?: [])];
        usort($files, static fn (string $a, string $b): int => basename($a) <=> basename($b));

        $reports = [];
        foreach ($files as $file) {
            $kind = ImportKind::ofFile(basename($file));
            $content = @file_get_contents($file);
            if (!$kind instanceof \Tmonier\SyliusErpFlatfilePlugin\Import\ImportKind || false === $content) {
                continue;
            }
            $report = $this->importer->import($kind, basename($file), $content);
            $this->moveToProcessed($file, $report->checksum);
            $reports[] = $report;
        }

        return $reports;
    }

    private function moveToProcessed(string $file, string $checksum): void
    {
        $processed = rtrim($this->directory, '/') . '/' . self::PROCESSED;
        if (!is_dir($processed) && !@mkdir($processed) && !is_dir($processed)) {
            throw new ExchangeDirectoryUnavailable(sprintf('The exchange directory "%s" is not writable.', $this->directory));
        }

        $target = $processed . '/' . basename($file);
        if (file_exists($target)) {
            $target = $processed . '/' . substr($checksum, 0, 8) . '-' . basename($file);
        }
        if (!@rename($file, $target)) {
            throw new ExchangeDirectoryUnavailable(sprintf('The file "%s" could not be moved to "%s".', basename($file), self::PROCESSED));
        }
    }
}
