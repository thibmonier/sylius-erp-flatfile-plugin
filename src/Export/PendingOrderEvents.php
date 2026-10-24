<?php

declare(strict_types=1);

namespace Tmonier\SyliusErpFlatfilePlugin\Export;

use Tmonier\SyliusWebhookOutboxPlugin\Entity\OutboxEventInterface;

/** The outbox events of the configured types that no file holds yet, oldest first. */
interface PendingOrderEvents
{
    /** @return list<OutboxEventInterface> */
    public function next(int $limit): array;
}
