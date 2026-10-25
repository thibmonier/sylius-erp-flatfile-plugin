<?php

declare(strict_types=1);

namespace Tmonier\SyliusErpFlatfilePlugin\Import;

/**
 * The import of one batch of prices or stock (ADR 0017). A batch is its file name and the checksum (SHA-256) of its content: one already
 * processed is ignored. Prices are all or nothing (one invalid line rejects the batch, nothing is written); stock is line by line (the
 * valid lines are applied, the others are in the report). The batch and its writes are recorded in the same transaction.
 * Reason codes: columns, duplicate, unknown_variant, channel, currency, price, on_hand, below_on_hold; for a whole file (line 0):
 * empty, encoding, truncated, header.
 */
final readonly class BatchImporter
{
    public function __construct(
        private CatalogLookup $lookup,
        private CatalogUpdates $updates,
        private ImportJournal $journal,
    ) {
    }

    public function import(ImportKind $kind, string $name, string $content): ImportReport
    {
        $checksum = hash('sha256', $content);
        $previous = $this->journal->find($name, $checksum);
        if ($previous instanceof ImportReport) {
            return $previous->asDuplicate();
        }

        $lines = $this->lines($kind, $content);
        if ($lines instanceof RejectedLine) {
            return $this->rejected($name, $checksum, $kind, 0, [$lines]);
        }

        return ImportKind::Prices === $kind ? $this->prices($name, $checksum, $lines) : $this->stock($name, $checksum, $lines);
    }

    /**
     * @param array<int, list<string>> $lines the fields of each data line, by line number
     */
    private function prices(string $name, string $checksum, array $lines): ImportReport
    {
        $updates = [];
        $rejections = [];
        $seen = [];
        foreach ($lines as $number => $fields) {
            $reason = $this->priceProblem($fields, $seen);
            if (null !== $reason) {
                $rejections[] = new RejectedLine($number, $reason);

                continue;
            }
            $updates[] = new PriceUpdate($fields[0], $fields[3], (int) $fields[1]);
        }

        if ([] !== $rejections) {
            return $this->rejected($name, $checksum, ImportKind::Prices, \count($lines), $rejections);
        }

        $report = new ImportReport($name, $checksum, ImportKind::Prices, BatchOutcome::Applied, \count($lines), \count($updates), 0);
        $this->journal->inTransaction(function () use ($updates, $report): void {
            $this->updates->setPrices($updates);
            $this->journal->record($report);
        });

        return $report;
    }

    /**
     * @param array<int, list<string>> $lines
     */
    private function stock(string $name, string $checksum, array $lines): ImportReport
    {
        $updates = [];
        $rejections = [];
        $seen = [];
        foreach ($lines as $number => $fields) {
            $reason = $this->stockProblem($fields, $seen);
            if (null !== $reason) {
                $rejections[] = new RejectedLine($number, $reason);

                continue;
            }
            $updates[] = new StockUpdate($fields[0], (int) $fields[1]);
        }

        if ([] === $updates) {
            return $this->rejected($name, $checksum, ImportKind::Stock, \count($lines), $rejections);
        }

        $report = new ImportReport($name, $checksum, ImportKind::Stock, [] === $rejections ? BatchOutcome::Applied : BatchOutcome::Partial, \count($lines), \count($updates), \count($rejections), $rejections);
        $this->journal->inTransaction(function () use ($updates, $report): void {
            $this->updates->setStocks($updates);
            $this->journal->record($report);
        });

        return $report;
    }

    /**
     * @param list<string>        $fields
     * @param array<string, true> $seen
     */
    private function priceProblem(array $fields, array &$seen): ?string
    {
        if (4 !== \count($fields)) {
            return 'columns';
        }
        [$variant, $price, $currency, $channel] = $fields;
        $key = $variant . '@' . $channel;
        $duplicate = isset($seen[$key]);
        $seen[$key] = true;

        return match (true) {
            $duplicate => 'duplicate',
            null === $this->lookup->variantOnHold($variant) => 'unknown_variant',
            null === $this->lookup->channelCurrency($channel) => 'channel',
            $this->lookup->channelCurrency($channel) !== $currency => 'currency',
            1 !== preg_match('/^[1-9][0-9]*$/', $price) => 'price',
            default => null,
        };
    }

    /**
     * @param list<string>        $fields
     * @param array<string, true> $seen
     */
    private function stockProblem(array $fields, array &$seen): ?string
    {
        if (2 !== \count($fields)) {
            return 'columns';
        }
        [$variant, $onHand] = $fields;
        $duplicate = isset($seen[$variant]);
        $seen[$variant] = true;
        $onHold = $this->lookup->variantOnHold($variant);

        return match (true) {
            $duplicate => 'duplicate',
            null === $onHold => 'unknown_variant',
            1 !== preg_match('/^(?:0|[1-9][0-9]*)$/', $onHand) => 'on_hand',
            (int) $onHand < $onHold => 'below_on_hold',
            default => null,
        };
    }

    /**
     * @return array<int, list<string>>|RejectedLine the data lines by line number (the header is line 1), or the refusal of the whole file
     */
    private function lines(ImportKind $kind, string $content): array|RejectedLine
    {
        return match (true) {
            '' === $content => new RejectedLine(0, 'empty'),
            !mb_check_encoding($content, 'UTF-8') || str_starts_with($content, "\xEF\xBB\xBF") => new RejectedLine(0, 'encoding'),
            !str_ends_with($content, "\n") => new RejectedLine(0, 'truncated'),
            default => $this->dataLines($kind, $content),
        };
    }

    /**
     * @return array<int, list<string>>|RejectedLine
     */
    private function dataLines(ImportKind $kind, string $content): array|RejectedLine
    {
        $raw = explode("\n", substr($content, 0, -1));
        if (rtrim($raw[0], "\r") !== implode(';', $kind->header())) {
            return new RejectedLine(0, 'header');
        }

        $lines = [];
        foreach (\array_slice($raw, 1, null, true) as $index => $line) {
            $line = rtrim($line, "\r");
            if ('' !== trim($line)) {
                $lines[$index + 1] = array_map(static fn (?string $field): string => (string) $field, str_getcsv($line, ';', '"', ''));
            }
        }

        return [] === $lines ? new RejectedLine(0, 'empty') : $lines;
    }

    /** @param list<RejectedLine> $rejections */
    private function rejected(string $name, string $checksum, ImportKind $kind, int $read, array $rejections): ImportReport
    {
        $report = new ImportReport($name, $checksum, $kind, BatchOutcome::Rejected, $read, 0, \count($rejections), $rejections);
        $this->journal->record($report);

        return $report;
    }
}
