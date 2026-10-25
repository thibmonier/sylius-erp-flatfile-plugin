<?php

declare(strict_types=1);

namespace Tmonier\SyliusErpFlatfilePlugin\Import;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ChannelPricingInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Repository\ProductVariantRepositoryInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Sylius\Resource\Factory\FactoryInterface;

/** The catalogue of Sylius: channel pricing (one price per variant and channel, no customer group) and quantity on hand. */
final readonly class DoctrineCatalog implements CatalogLookup, CatalogUpdates
{
    /**
     * @param FactoryInterface<ChannelPricingInterface>                  $channelPricingFactory
     * @param ProductVariantRepositoryInterface<ProductVariantInterface> $variants
     * @param RepositoryInterface<ChannelInterface>                      $channels
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private FactoryInterface $channelPricingFactory,
        private ProductVariantRepositoryInterface $variants,
        private RepositoryInterface $channels,
    ) {
    }

    public function variantOnHold(string $variantCode): ?int
    {
        $variant = $this->variant($variantCode);

        return $variant instanceof ProductVariantInterface ? ($variant->getOnHold() ?? 0) : null;
    }

    public function channelCurrency(string $channelCode): ?string
    {
        return $this->channel($channelCode)?->getBaseCurrency()?->getCode();
    }

    public function setPrices(array $prices): void
    {
        foreach ($prices as $price) {
            $variant = $this->variant($price->variantCode);
            $channel = $this->channel($price->channelCode);
            if (!$variant instanceof ProductVariantInterface || !$channel instanceof ChannelInterface) {
                continue;
            }
            $pricing = $variant->getChannelPricingForChannel($channel);
            if (!$pricing instanceof ChannelPricingInterface) {
                $pricing = $this->channelPricingFactory->createNew();
                $pricing->setChannelCode($price->channelCode);
                $variant->addChannelPricing($pricing);
            }
            $pricing->setPrice($price->priceMinor);
            $this->entityManager->persist($pricing);
        }
        $this->entityManager->flush();
    }

    public function setStocks(array $stocks): void
    {
        foreach ($stocks as $stock) {
            $this->variant($stock->variantCode)?->setOnHand($stock->onHand);
        }
        $this->entityManager->flush();
    }

    private function variant(string $code): ?ProductVariantInterface
    {
        $variant = $this->variants->findOneBy(['code' => $code]);

        return $variant instanceof ProductVariantInterface ? $variant : null;
    }

    private function channel(string $code): ?ChannelInterface
    {
        $channel = $this->channels->findOneBy(['code' => $code]);

        return $channel instanceof ChannelInterface ? $channel : null;
    }
}
