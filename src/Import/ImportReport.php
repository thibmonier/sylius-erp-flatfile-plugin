<?php

declare(strict_types=1);

namespace Tmonier\SyliusErpFlatfilePlugin\Import;

/** What an import did with a batch; the journal keeps it. `rejected` counts the lines refused, `applied` the lines written. */
final readonly class ImportReport
{
    /** @param list<RejectedLine> $rejections */
    public function __construct(
        public string $name,
        public string $checksum,
        public ImportKind $kind,
        public BatchOutcome $outcome,
        public int $read,
        public int $applied,
        public int $rejected,
        public array $rejections = [],
    ) {
    }

    public function asDuplicate(): self
    {
        return new self($this->name, $this->checksum, $this->kind, BatchOutcome::Duplicate, $this->read, $this->applied, $this->rejected, $this->rejections);
    }
}
