<?php

declare(strict_types=1);

namespace Tmonier\SyliusErpFlatfilePlugin\Import;

final readonly class StockUpdate
{
    public function __construct(public string $variantCode, public int $onHand)
    {
    }
}
