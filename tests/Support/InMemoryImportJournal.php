<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusErpFlatfilePlugin\Support;

use Tmonier\SyliusErpFlatfilePlugin\Import\ImportJournal;
use Tmonier\SyliusErpFlatfilePlugin\Import\ImportReport;

final class InMemoryImportJournal implements ImportJournal
{
    /** @var list<ImportReport> */
    public array $recorded = [];

    public function find(string $name, string $checksum): ?ImportReport
    {
        foreach ($this->recorded as $report) {
            if ($report->name === $name && $report->checksum === $checksum) {
                return $report;
            }
        }

        return null;
    }

    public function record(ImportReport $report): void
    {
        $this->recorded[] = $report;
    }

    public function inTransaction(\Closure $work): void
    {
        $work();
    }
}
