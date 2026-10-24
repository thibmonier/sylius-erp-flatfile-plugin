<?php

declare(strict_types=1);

namespace Tmonier\SyliusErpFlatfilePlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * The journal of the exported events (US-185). The event id (the webhook id) is the primary key: an event is in
 * one file only, even when two exports run at the same time. No foreign key to the outbox table: purging the
 * outbox must not depend on this journal. Schema API: the same migration on MySQL, MariaDB and PostgreSQL.
 */
final class Version20261025100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Journal of the order events exported to flat files.';
    }

    public function up(Schema $schema): void
    {
        $journal = $schema->createTable('tmonier_erp_flatfile_exported_event');
        $journal->addColumn('event_id', Types::STRING, ['length' => 36]);
        $journal->addColumn('file_name', Types::STRING, ['length' => 255]);
        $journal->addColumn('exported_at', Types::DATETIME_IMMUTABLE);
        $journal->setPrimaryKey(['event_id']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('tmonier_erp_flatfile_exported_event');
    }
}
