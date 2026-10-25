<?php

declare(strict_types=1);

namespace Tmonier\SyliusErpFlatfilePlugin\Import;

/** One line of the rejection report: its number in the file (1 is the header; 0 stands for the whole file) and a reason code. */
final readonly class RejectedLine
{
    public function __construct(public int $line, public string $reason)
    {
    }
}
