<?php

declare(strict_types=1);

namespace Tmonier\SyliusErpFlatfilePlugin\Import;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/** The primary key is a UUID, the pair (name, checksum) is unique: a batch cannot be recorded twice, whoever races. */
final readonly class DbalImportJournal implements ImportJournal
{
    public function __construct(private Connection $connection, private ClockInterface $clock)
    {
    }

    public function find(string $name, string $checksum): ?ImportReport
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM tmonier_erp_flatfile_import_batch WHERE name = ? AND checksum = ?', [$name, $checksum]);
        if (!\is_array($row)) {
            return null;
        }

        $rejections = [];
        foreach ((array) json_decode($this->text($row['report'] ?? '[]'), true) as $line) {
            if (\is_array($line)) {
                $rejections[] = new RejectedLine($this->number($line['line'] ?? 0), $this->text($line['reason'] ?? ''));
            }
        }

        return new ImportReport(
            $this->text($row['name'] ?? ''),
            $this->text($row['checksum'] ?? ''),
            ImportKind::tryFrom($this->text($row['kind'] ?? '')) ?? ImportKind::Prices,
            BatchOutcome::tryFrom($this->text($row['outcome'] ?? '')) ?? BatchOutcome::Rejected,
            $this->number($row['lines_read'] ?? 0),
            $this->number($row['lines_applied'] ?? 0),
            $this->number($row['lines_rejected'] ?? 0),
            $rejections,
        );
    }

    public function record(ImportReport $report): void
    {
        $this->connection->insert('tmonier_erp_flatfile_import_batch', [
            'id' => Uuid::v7()->toRfc4122(),
            'name' => $report->name,
            'checksum' => $report->checksum,
            'kind' => $report->kind->value,
            'outcome' => $report->outcome->value,
            'lines_read' => $report->read,
            'lines_applied' => $report->applied,
            'lines_rejected' => $report->rejected,
            'report' => json_encode(array_map(static fn (RejectedLine $line): array => ['line' => $line->line, 'reason' => $line->reason], $report->rejections), \JSON_THROW_ON_ERROR),
            'imported_at' => $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ]);
    }

    public function inTransaction(\Closure $work): void
    {
        $this->connection->transactional(static function () use ($work): void {
            $work();
        });
    }

    private function text(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }

    private function number(mixed $value): int
    {
        return \is_numeric($value) ? (int) $value : 0;
    }
}
