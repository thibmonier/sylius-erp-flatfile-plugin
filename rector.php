<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php81\Rector\Property\ReadOnlyPropertyRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withSkip([
        __DIR__ . '/tests/TestApplication',
        // Doctrine hydrates the entity: its properties stay writable.
        ReadOnlyPropertyRector::class => [__DIR__ . '/src/Entity'],
    ])
    // PHP level is derived from composer.json "require.php" so Rector never
    // introduces syntax newer than the lowest supported version.
    ->withPhpSets()
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        earlyReturn: true,
    );
