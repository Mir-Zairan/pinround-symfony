<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\AssetMapper\AssetMapperInterface;

/**
 * Every JavaScript module must be served from this origin. A vendored package that is missing on disk
 * (assets/vendor is git-ignored and restored by importmap:install) fails here.
 */
final class ImportmapTest extends KernelTestCase
{
    #[Test]
    public function everyImportmapEntryIsAMappedLocalAsset(): void
    {
        $reader = self::getContainer()->get('asset_mapper.importmap.config_reader');
        $mapper = self::getContainer()->get(AssetMapperInterface::class);

        $names = [];
        foreach ($reader->getEntries() as $entry) {
            $names[] = $entry->importName;
            $sourcePath = $reader->convertPathToFilesystemPath($entry->path);
            self::assertFileExists($sourcePath, $entry->importName.' is missing on disk; run importmap:install');
            self::assertNotNull($mapper->getAssetFromSourcePath((string) realpath($sourcePath)), $entry->importName.' is not found by the asset mapper');
        }

        self::assertEqualsCanonicalizing(['app', '@hotwired/stimulus', '@hotwired/turbo', '@symfony/stimulus-bundle'], $names);
    }

    #[Test]
    public function theRenderedImportmapNamesNoRemoteOrProtocolRelativeUrl(): void
    {
        $twig = self::getContainer()->get('twig');
        $html = $twig->createTemplate("{{ importmap('app') }}")->render();

        self::assertStringContainsString('"@hotwired/stimulus"', $html, 'The importmap rendered nothing to check.');
        self::assertDoesNotMatchRegularExpression('#(https?:)?\\\\?/\\\\?/[a-z0-9]#i', $html);
    }
}
