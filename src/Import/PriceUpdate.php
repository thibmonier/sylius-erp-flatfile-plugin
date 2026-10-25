<?php

declare(strict_types=1);

namespace Tmonier\SyliusErpFlatfilePlugin\Import;

final readonly class PriceUpdate
{
    public function __construct(public string $variantCode, public string $channelCode, public int $priceMinor)
    {
    }
}
