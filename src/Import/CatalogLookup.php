<?php

declare(strict_types=1);

namespace Tmonier\SyliusErpFlatfilePlugin\Import;

/** What the validation needs to know about the catalogue. */
interface CatalogLookup
{
    /** @return int|null the quantity on hold of the variant, null when the code is unknown */
    public function variantOnHold(string $variantCode): ?int;

    /** @return string|null the currency of the channel, null when the code is unknown */
    public function channelCurrency(string $channelCode): ?string;
}
