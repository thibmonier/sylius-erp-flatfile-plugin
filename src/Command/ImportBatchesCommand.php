<?php

declare(strict_types=1);

namespace Tmonier\SyliusErpFlatfilePlugin\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Tmonier\SyliusErpFlatfilePlugin\Deposit\ExchangeDirectoryUnavailable;
use Tmonier\SyliusErpFlatfilePlugin\Import\BatchOutcome;
use Tmonier\SyliusErpFlatfilePlugin\Import\ExchangeDirectoryImporter;

/** Imports the files `prices-*.csv` and `stock-*.csv` of the exchange directory, then moves them to its `processed` subdirectory. */
#[AsCommand(name: 'tmonier:erp-flatfile:import-batches', description: 'Import the prices and stock files of the exchange directory')]
final class ImportBatchesCommand extends Command
{
    public function __construct(private readonly ExchangeDirectoryImporter $importer)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $reports = $this->importer->importPending();
        } catch (ExchangeDirectoryUnavailable $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return Command::FAILURE;
        }

        if ([] === $reports) {
            $output->writeln('Nothing to import.');

            return Command::SUCCESS;
        }

        $rejected = false;
        foreach ($reports as $report) {
            $output->writeln(sprintf('%s: %s, %d read, %d applied, %d rejected.', $report->name, $report->outcome->value, $report->read, $report->applied, $report->rejected));
            foreach ($report->rejections as $line) {
                $output->writeln(sprintf('  line %d: %s', $line->line, $line->reason));
            }
            $rejected = $rejected || BatchOutcome::Rejected === $report->outcome;
        }

        return $rejected ? Command::FAILURE : Command::SUCCESS;
    }
}
