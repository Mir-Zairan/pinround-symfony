<?php

declare(strict_types=1);

namespace App\Tests\Unit\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ModuleSkeletonTest extends TestCase
{
    private const array MODULE_ENTRIES = ['Api', 'Application', 'Domain', 'Infrastructure', 'README.md', 'UI'];

    #[Test]
    public function everyModuleHasAReadmeAndExactlyTheFiveLayerFolders(): void
    {
        $srcDir = \dirname(__DIR__, 3).'/src';
        $modules = glob($srcDir.'/*', \GLOB_ONLYDIR);
        self::assertNotFalse($modules);
        self::assertNotEmpty($modules);

        foreach ($modules as $moduleDir) {
            $entries = scandir($moduleDir);
            self::assertNotFalse($entries);
            $visibleEntries = array_values(array_filter($entries, static fn (string $entry): bool => !str_starts_with($entry, '.')));

            self::assertEqualsCanonicalizing(self::MODULE_ENTRIES, $visibleEntries, \sprintf('src/%s must hold README.md and the five layer folders only', basename($moduleDir)));
            self::assertDirectoryExists($moduleDir.'/Api');
            self::assertFileExists($moduleDir.'/README.md');
        }
    }
}
