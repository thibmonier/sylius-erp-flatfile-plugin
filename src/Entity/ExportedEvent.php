<?php

declare(strict_types=1);

namespace Tmonier\SyliusErpFlatfilePlugin\Entity;

/** Mapping only: the lines are written with DBAL by the journal. */
class ExportedEvent
{
    protected string $eventId;

    protected string $fileName;

    protected \DateTimeImmutable $exportedAt;

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function getFileName(): string
    {
        return $this->fileName;
    }

    public function getExportedAt(): \DateTimeImmutable
    {
        return $this->exportedAt;
    }
}
