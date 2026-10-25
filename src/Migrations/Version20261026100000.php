<?php

declare(strict_types=1);

namespace Tmonier\SyliusErpFlatfilePlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * The journal of the imported batches of prices and stock (US-187). A batch is its file name and the checksum of its content: the pair is
 * unique, so a batch cannot be recorded twice. The report of the rejected lines is JSON in a text column. Schema API: the same migration
 * on MySQL, MariaDB and PostgreSQL.
 */
final class Version20261026100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Journal of the batches of prices and stock imported from flat files.';
    }

    public function up(Schema $schema): void
    {
        $journal = $schema->createTable('tmonier_erp_flatfile_import_batch');
        $journal->addColumn('id', Types::STRING, ['length' => 36]);
        $journal->addColumn('name', Types::STRING, ['length' => 255]);
        $journal->addColumn('checksum', Types::STRING, ['length' => 64]);
        $journal->addColumn('kind', Types::STRING, ['length' => 16]);
        $journal->addColumn('outcome', Types::STRING, ['length' => 16]);
        $journal->addColumn('lines_read', Types::INTEGER);
        $journal->addColumn('lines_applied', Types::INTEGER);
        $journal->addColumn('lines_rejected', Types::INTEGER);
        $journal->addColumn('report', Types::TEXT);
        $journal->addColumn('imported_at', Types::DATETIME_IMMUTABLE);
        $journal->setPrimaryKey(['id']);
        $journal->addUniqueIndex(['name', 'checksum'], 'tmonier_erp_flatfile_import_batch_unique');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('tmonier_erp_flatfile_import_batch');
    }
}
