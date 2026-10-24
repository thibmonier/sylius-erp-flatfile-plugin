<?php

declare(strict_types=1);

namespace Tmonier\SyliusErpFlatfilePlugin\Deposit;

/** Puts a file in the exchange directory so that a reader never sees it half written. */
interface FileDepositor
{
    /**
     * @param iterable<string> $chunks the content, written as it is produced
     *
     * @throws ExchangeDirectoryUnavailable when the directory is missing, not writable or full; nothing is left behind
     */
    public function deposit(string $fileName, iterable $chunks): void;
}
