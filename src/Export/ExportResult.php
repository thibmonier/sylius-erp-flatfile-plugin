<?php

declare(strict_types=1);

namespace Tmonier\SyliusErpFlatfilePlugin\Export;

final readonly class ExportResult
{
    public function __construct(public ?string $fileName, public int $events, public int $lines)
    {
    }
}
