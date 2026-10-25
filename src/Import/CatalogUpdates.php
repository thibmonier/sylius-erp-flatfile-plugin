<?php

declare(strict_types=1);

namespace Tmonier\SyliusErpFlatfilePlugin\Import;

/** What the import writes: the price of a variant in a channel, the quantity on hand of a variant (the quantity on hold is never touched). */
interface CatalogUpdates
{
    /** @param list<PriceUpdate> $prices */
    public function setPrices(array $prices): void;

    /** @param list<StockUpdate> $stocks */
    public function setStocks(array $stocks): void;
}
