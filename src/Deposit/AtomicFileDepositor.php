<?php

declare(strict_types=1);

namespace Tmonier\SyliusErpFlatfilePlugin\Deposit;

/**
 * The file is written in the ".incoming" subdirectory of the exchange directory, then renamed: a rename inside one
 * file system is atomic, so the exchange directory only ever holds complete files.
 */
final readonly class AtomicFileDepositor implements FileDepositor
{
    private const INCOMING = '.incoming';

    public function __construct(private string $directory)
    {
    }

    public function deposit(string $fileName, iterable $chunks): void
    {
        $incoming = $this->incomingDirectory();
        $provisional = $incoming . '/' . $fileName;

        try {
            $this->write($provisional, $chunks);
            if (!@rename($provisional, $this->directory . '/' . $fileName)) {
                throw new ExchangeDirectoryUnavailable(sprintf('The file "%s" could not be moved into the exchange directory "%s".', $fileName, $this->directory));
            }
        } catch (\Throwable $e) {
            @unlink($provisional);

            throw $e;
        }
    }

    private function incomingDirectory(): string
    {
        if (!is_dir($this->directory)) {
            throw new ExchangeDirectoryUnavailable(sprintf('The exchange directory "%s" does not exist.', $this->directory));
        }
        if (!is_writable($this->directory)) {
            throw new ExchangeDirectoryUnavailable(sprintf('The exchange directory "%s" is not writable.', $this->directory));
        }

        $incoming = $this->directory . '/' . self::INCOMING;
        if (!is_dir($incoming) && !@mkdir($incoming) && !is_dir($incoming)) {
            throw new ExchangeDirectoryUnavailable(sprintf('The exchange directory "%s" is not writable: "%s" cannot be created.', $this->directory, self::INCOMING));
        }

        return $incoming;
    }

    /** @param iterable<string> $chunks */
    private function write(string $path, iterable $chunks): void
    {
        $handle = @fopen($path, 'wb');
        if (false === $handle) {
            throw new ExchangeDirectoryUnavailable(sprintf('The exchange directory "%s" is not writable.', $this->directory));
        }

        try {
            foreach ($chunks as $chunk) {
                $written = @fwrite($handle, $chunk);
                if (false === $written || $written < \strlen($chunk)) {
                    throw new ExchangeDirectoryUnavailable(sprintf('Writing in the exchange directory "%s" failed (disk full?).', $this->directory));
                }
            }
            if (!fflush($handle)) {
                throw new ExchangeDirectoryUnavailable(sprintf('Writing in the exchange directory "%s" failed (disk full?).', $this->directory));
            }
        } finally {
            fclose($handle);
        }
    }
}
