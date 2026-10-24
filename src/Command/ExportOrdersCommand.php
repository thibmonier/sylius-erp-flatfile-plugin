<?php

declare(strict_types=1);

namespace Tmonier\SyliusErpFlatfilePlugin\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Tmonier\SyliusErpFlatfilePlugin\Deposit\ExchangeDirectoryUnavailable;
use Tmonier\SyliusErpFlatfilePlugin\Export\OrderExporter;

/** Exports one batch per call; schedule it (cron, Symfony Scheduler) at the rhythm the ERP reads the directory. */
#[AsCommand(name: 'tmonier:erp-flatfile:export-orders', description: 'Write the pending order events of the outbox to a file of the exchange directory')]
final class ExportOrdersCommand extends Command
{
    public function __construct(private readonly OrderExporter $exporter)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $result = $this->exporter->export();
        } catch (ExchangeDirectoryUnavailable $e) {
            $output->writeln(sprintf('<error>%s The events stay to export.</error>', $e->getMessage()));

            return Command::FAILURE;
        }

        if (null === $result->fileName) {
            $output->writeln('Nothing to export.');

            return Command::SUCCESS;
        }

        $output->writeln(sprintf('%s: %d order(s), %d line(s).', $result->fileName, $result->events, $result->lines));

        return Command::SUCCESS;
    }
}
