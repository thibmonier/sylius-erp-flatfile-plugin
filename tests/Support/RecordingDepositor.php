<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusErpFlatfilePlugin\Support;

use Tmonier\SyliusErpFlatfilePlugin\Deposit\FileDepositor;

final class RecordingDepositor implements FileDepositor
{
    /** @var array<string, string> file name => content */
    public array $files = [];

    public function deposit(string $fileName, iterable $chunks): void
    {
        $this->files[$fileName] = implode('', [...$chunks]);
    }
}
