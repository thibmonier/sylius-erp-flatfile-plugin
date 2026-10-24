<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusErpFlatfilePlugin\Support;

use Tmonier\SyliusWebhookOutboxPlugin\Entity\OutboxEvent;

/** Outbox events as the webhook outbox plugin records them (data of the "order" payload, version 1). */
final class OrderEvents
{
    public const FIRST_ID = '0192a000-0000-7000-8000-000000000001';

    /**
     * @param list<array{variant_code: string, quantity: int, unit_price: int, total: int}> $items
     */
    public static function placed(
        string $id = self::FIRST_ID,
        string $number = '000000042',
        array $items = [],
        string $type = 'order.placed',
        string $placedAt = '2026-10-24T12:15:00+02:00',
        string $email = 'buyer@example.com',
        string $country = 'FR',
    ): OutboxEvent {
        $items = [] === $items ? [self::item('MUG-RED', 2, 1250), self::item('MUG-BLUE', 1, 990)] : $items;

        return new OutboxEvent(
            $type,
            1,
            new \DateTimeImmutable('2026-10-24T10:15:01Z'),
            [
                'number' => $number,
                'currency_code' => 'EUR',
                'checkout_completed_at' => $placedAt,
                'customer' => ['email' => $email],
                'shipping_address' => ['country_code' => $country],
                'items' => $items,
            ],
            'WEB',
            'order',
            '42',
            null,
            $id,
        );
    }

    /** @return array{variant_code: string, quantity: int, unit_price: int, total: int} */
    public static function item(string $variantCode, int $quantity, int $unitPrice): array
    {
        return ['variant_code' => $variantCode, 'quantity' => $quantity, 'unit_price' => $unitPrice, 'total' => $quantity * $unitPrice];
    }

    public static function id(int $n): string
    {
        return sprintf('0192a000-0000-7000-8000-%012d', $n);
    }
}
