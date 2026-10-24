<?php

declare(strict_types=1);

namespace Tmonier\SyliusErpFlatfilePlugin\Contract;

use Tmonier\SyliusWebhookOutboxPlugin\Entity\OutboxEventInterface;

/**
 * The contract of the order file: one line per order line, taken from the "order" payload (version 1)
 * of the outbox events. UTF-8, ";" separator, header line, amounts as integers in the minor unit.
 */
final class OrderLinesCsv
{
    public const HEADER = [
        'order_number',
        'webhook_id',
        'placed_at',
        'currency',
        'customer_email',
        'variant_code',
        'quantity',
        'unit_price_minor',
        'line_total_minor',
        'ship_country',
    ];

    /**
     * @param iterable<OutboxEventInterface> $events
     *
     * @return \Generator<int, string> the header, then one line per order line; each line ends with "\n"
     */
    public function lines(iterable $events): \Generator
    {
        yield $this->encode(self::HEADER);

        foreach ($events as $event) {
            foreach ($this->rowsOf($event) as $row) {
                yield $this->encode($row);
            }
        }
    }

    /** @return list<list<string>> */
    private function rowsOf(OutboxEventInterface $event): array
    {
        $payload = $event->getPayload();
        $customer = $this->map($payload['customer'] ?? null);
        $address = $this->map($payload['shipping_address'] ?? null);

        $rows = [];
        foreach ($this->list($payload['items'] ?? null) as $item) {
            $rows[] = [
                $this->text($payload['number'] ?? null),
                $event->getId(),
                $this->placedAt($payload['checkout_completed_at'] ?? null, $event->getOccurredAt()),
                $this->text($payload['currency_code'] ?? null),
                $this->text($customer['email'] ?? null),
                $this->text($item['variant_code'] ?? null),
                $this->text($item['quantity'] ?? null),
                $this->text($item['unit_price'] ?? null),
                $this->text($item['total'] ?? null),
                $this->text($address['country_code'] ?? null),
            ];
        }

        return $rows;
    }

    /** UTC, whatever the offset of the payload; the date of the event when the order has none. */
    private function placedAt(mixed $value, \DateTimeImmutable $fallback): string
    {
        try {
            $date = \is_string($value) && '' !== $value ? new \DateTimeImmutable($value) : $fallback;
        } catch (\Exception) {
            $date = $fallback;
        }

        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    /** @param list<string> $fields */
    private function encode(array $fields): string
    {
        $stream = fopen('php://memory', 'w+b');
        \assert(false !== $stream);
        // No escape character: a quote inside a field is doubled, as RFC 4180 says.
        fputcsv($stream, $fields, ';', '"', '', "\n");
        rewind($stream);
        $line = (string) stream_get_contents($stream);
        fclose($stream);

        return $line;
    }

    private function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    /** @return array<string, mixed> */
    private function map(mixed $value): array
    {
        /** @var array<string, mixed> $map */
        $map = \is_array($value) ? $value : [];

        return $map;
    }

    /** @return list<array<string, mixed>> */
    private function list(mixed $value): array
    {
        $items = [];
        foreach (\is_array($value) ? $value : [] as $item) {
            $items[] = $this->map($item);
        }

        return $items;
    }
}
