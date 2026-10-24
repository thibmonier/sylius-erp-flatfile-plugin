<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusErpFlatfilePlugin\Unit\Deposit;

use PHPUnit\Framework\TestCase;
use Tmonier\SyliusErpFlatfilePlugin\Deposit\AtomicFileDepositor;
use Tmonier\SyliusErpFlatfilePlugin\Deposit\ExchangeDirectoryUnavailable;

final class AtomicFileDepositorTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/erp-flatfile-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (array_merge(glob($this->directory . '/.incoming/*') ?: [], glob($this->directory . '/*') ?: []) as $file) {
            is_file($file) && unlink($file);
        }
        is_dir($this->directory . '/.incoming') && rmdir($this->directory . '/.incoming');
        is_dir($this->directory) && rmdir($this->directory);
    }

    public function testAReaderOfTheDirectoryNeverSeesAPartialFile(): void
    {
        $seen = [];
        $chunks = (function () use (&$seen): \Generator {
            yield "first chunk\n";
            $seen[] = $this->visibleFiles();
            yield "second chunk\n";
            $seen[] = $this->visibleFiles();
        })();

        (new AtomicFileDepositor($this->directory))->deposit('orders-1.csv', $chunks);

        self::assertSame([[], []], $seen, 'During the write, the reader sees no file at all.');
        self::assertSame(['orders-1.csv'], $this->visibleFiles());
        self::assertSame("first chunk\nsecond chunk\n", file_get_contents($this->directory . '/orders-1.csv'));
    }

    public function testNothingIsLeftWhenTheWriteFailsHalfway(): void
    {
        $chunks = (static function (): \Generator {
            yield "first chunk\n";

            throw new \RuntimeException('disk full');
        })();

        try {
            (new AtomicFileDepositor($this->directory))->deposit('orders-1.csv', $chunks);
            self::fail('The failure must be reported.');
        } catch (\RuntimeException) {
        }

        self::assertSame([], $this->visibleFiles(), 'No final file is left.');
        self::assertSame([], glob($this->directory . '/.incoming/*'), 'No provisional file is left.');
    }

    public function testAMissingDirectoryIsReportedWithItsPath(): void
    {
        $missing = $this->directory . '/absent';

        try {
            (new AtomicFileDepositor($missing))->deposit('orders-1.csv', ['x']);
            self::fail('A missing directory must be refused.');
        } catch (ExchangeDirectoryUnavailable $e) {
            self::assertStringContainsString($missing, $e->getMessage());
        }
    }

    /** @return list<string> */
    private function visibleFiles(): array
    {
        return array_values(array_filter(array_diff(scandir($this->directory) ?: [], ['.', '..']), fn (string $name): bool => is_file($this->directory . '/' . $name)));
    }
}
