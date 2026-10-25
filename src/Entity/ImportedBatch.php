<?php

declare(strict_types=1);

namespace Tmonier\SyliusErpFlatfilePlugin\Entity;

/** Mapping only: the lines are written with DBAL by the journal of the import. */
class ImportedBatch
{
    protected string $id;

    protected string $name;

    protected string $checksum;

    protected string $kind;

    protected string $outcome;

    protected int $linesRead;

    protected int $linesApplied;

    protected int $linesRejected;

    protected string $report;

    protected \DateTimeImmutable $importedAt;

    public function getName(): string
    {
        return $this->name;
    }

    public function getChecksum(): string
    {
        return $this->checksum;
    }

    public function getOutcome(): string
    {
        return $this->outcome;
    }

    public function getImportedAt(): \DateTimeImmutable
    {
        return $this->importedAt;
    }
}
