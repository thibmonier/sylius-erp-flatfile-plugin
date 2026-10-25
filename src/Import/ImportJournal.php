<?php

declare(strict_types=1);

namespace Tmonier\SyliusErpFlatfilePlugin\Import;

/** The journal of the batches: one line per (name, checksum). */
interface ImportJournal
{
    public function find(string $name, string $checksum): ?ImportReport;

    public function record(ImportReport $report): void;

    /** Runs the work in one transaction: when it throws, nothing it wrote stays. */
    public function inTransaction(\Closure $work): void;
}
