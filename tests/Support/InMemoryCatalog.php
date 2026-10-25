<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusErpFlatfilePlugin\Support;

use Tmonier\SyliusErpFlatfilePlugin\Import\CatalogLookup;
use Tmonier\SyliusErpFlatfilePlugin\Import\CatalogUpdates;

/** A catalogue in memory: variants with their stock, channels with their currency; records what the importer writes. */
final class InMemoryCatalog implements CatalogLookup, CatalogUpdates
{
    /** @var array<string, int> variant code => on_hold */
    public array $variants = ['MUG-RED' => 0, 'MUG-BLUE' => 2, 'BOX-10' => 0];

    /** @var array<string, string> channel code => currency */
    public array $channels = ['WEB' => 'EUR', 'B2B' => 'EUR', 'US' => 'USD'];

    /** @var array<string, int> "VARIANT@CHANNEL" => price in the minor unit */
    public array $prices = [];

    /** @var array<string, int> variant code => on_hand */
    public array $onHand = [];

    public int $writes = 0;

    public function variantOnHold(string $variantCode): ?int
    {
        return $this->variants[$variantCode] ?? null;
    }

    public function channelCurrency(string $channelCode): ?string
    {
        return $this->channels[$channelCode] ?? null;
    }

    public function setPrices(array $prices): void
    {
        foreach ($prices as $price) {
            $this->prices[$price->variantCode . '@' . $price->channelCode] = $price->priceMinor;
            ++$this->writes;
        }
    }

    public function setStocks(array $stocks): void
    {
        foreach ($stocks as $stock) {
            $this->onHand[$stock->variantCode] = $stock->onHand;
            ++$this->writes;
        }
    }
}
