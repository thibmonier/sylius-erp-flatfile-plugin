<?php

declare(strict_types=1);

namespace Tmonier\SyliusErpFlatfilePlugin\Export;

use Doctrine\DBAL\Connection;

/** The primary key is the event id: a second line for the same event is refused by the database, whoever races. */
final readonly class DbalExportJournal implements ExportJournal
{
    public function __construct(private Connection $connection)
    {
    }

    public function commit(array $eventIds, string $fileName, \DateTimeImmutable $at, \Closure $deposit): void
    {
        $this->connection->transactional(function () use ($eventIds, $fileName, $at, $deposit): void {
            foreach ($eventIds as $eventId) {
                $this->connection->insert('tmonier_erp_flatfile_exported_event', [
                    'event_id' => $eventId,
                    'file_name' => $fileName,
                    'exported_at' => $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                ]);
            }

            $deposit();
        });
    }
}
