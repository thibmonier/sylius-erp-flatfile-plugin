<?php

declare(strict_types=1);

namespace Tmonier\SyliusErpFlatfilePlugin\Import;

/** The two files of the import (ADR 0017): prices per channel, stock on hand. The kind of a file is the prefix of its name. */
enum ImportKind: string
{
    case Prices = 'prices';
    case Stock = 'stock';

    /** @return list<string> */
    public function header(): array
    {
        return match ($this) {
            self::Prices => ['variant_code', 'price_minor', 'currency', 'channel'],
            self::Stock => ['variant_code', 'on_hand'],
        };
    }

    public static function ofFile(string $fileName): ?self
    {
        foreach (self::cases() as $kind) {
            if (str_starts_with($fileName, $kind->value . '-') && str_ends_with($fileName, '.csv')) {
                return $kind;
            }
        }

        return null;
    }
}
