<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusErpFlatfilePlugin\Unit\Contract;

use PHPUnit\Framework\TestCase;
use Tests\Tmonier\SyliusErpFlatfilePlugin\Support\OrderEvents;
use Tmonier\SyliusErpFlatfilePlugin\Contract\OrderLinesCsv;

/** The contract of the order file (README, "Contract"): one line per order line. */
final class OrderLinesCsvTest extends TestCase
{
    private const HEADER = 'order_number;webhook_id;placed_at;currency;customer_email;variant_code;quantity;unit_price_minor;line_total_minor;ship_country';

    public function testAnOrderOfTwoLinesGivesTheHeaderAndTwoLines(): void
    {
        $csv = $this->csv([OrderEvents::placed()]);

        self::assertSame(
            self::HEADER . "\n"
            . '000000042;' . OrderEvents::FIRST_ID . ';2026-10-24T10:15:00Z;EUR;buyer@example.com;MUG-RED;2;1250;2500;FR' . "\n"
            . '000000042;' . OrderEvents::FIRST_ID . ';2026-10-24T10:15:00Z;EUR;buyer@example.com;MUG-BLUE;1;990;990;FR' . "\n",
            $csv,
        );
    }

    public function testSeveralOrdersShareOneHeader(): void
    {
        $csv = $this->csv([
            OrderEvents::placed(OrderEvents::id(1), '000000001'),
            OrderEvents::placed(OrderEvents::id(2), '000000002', [OrderEvents::item('BOX', 3, 500)]),
            OrderEvents::placed(OrderEvents::id(3), '000000003'),
        ]);

        $lines = explode("\n", rtrim($csv, "\n"));
        self::assertCount(1 + 2 + 1 + 2, $lines);
        self::assertSame(self::HEADER, $lines[0]);
        self::assertSame(1, substr_count($csv, 'order_number'));
    }

    public function testTheFileIsUtf8WithoutByteOrderMark(): void
    {
        $csv = $this->csv([OrderEvents::placed(email: 'zoé@example.com')]);

        self::assertStringContainsString('zoé@example.com', $csv);
        self::assertStringStartsWith('order_number', $csv);
        self::assertTrue(mb_check_encoding($csv, 'UTF-8'));
    }

    public function testAFieldWithTheSeparatorIsQuoted(): void
    {
        $csv = $this->csv([OrderEvents::placed(items: [OrderEvents::item('A;B', 1, 100)])]);

        self::assertStringContainsString(';"A;B";1;100;100;', $csv);
    }

    /** @param list<\Tmonier\SyliusWebhookOutboxPlugin\Entity\OutboxEventInterface> $events */
    private function csv(array $events): string
    {
        return implode('', iterator_to_array((new OrderLinesCsv())->lines($events), false));
    }
}
