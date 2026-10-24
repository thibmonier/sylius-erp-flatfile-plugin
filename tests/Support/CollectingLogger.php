<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusErpFlatfilePlugin\Support;

use Psr\Log\AbstractLogger;

final class CollectingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string}> */
    public array $records = [];

    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => \is_scalar($level) ? (string) $level : '', 'message' => (string) $message];
    }
}
