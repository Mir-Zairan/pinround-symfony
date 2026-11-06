<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\AssetMapper\AssetMapperInterface;

/**
 * The shell assets must be served by AssetMapper from this origin only: no CDN, no remote font.
 */
final class AssetPipelineTest extends KernelTestCase
{
    private const array STYLESHEETS = [
        'styles/tokens.css',
        'styles/fonts.css',
        'styles/base.css',
        'styles/shell.css',
        'styles/components.css',
    ];

    private const array FONTS = [
        'fonts/atkinson-hyperlegible-next-latin-wght-normal.woff2',
        'fonts/atkinson-hyperlegible-mono-latin-wght-normal.woff2',
    ];

    #[Test]
    public function theEntrypointStylesheetsAndFontsAreMapped(): void
    {
        $mapper = $this->assetMapper();

        foreach (['app.js', ...self::STYLESHEETS, ...self::FONTS] as $logicalPath) {
            self::assertNotNull($mapper->getAsset($logicalPath), $logicalPath.' is not mapped');
            self::assertNotNull($mapper->getPublicPath($logicalPath), $logicalPath.' has no public path');
        }
    }

    #[Test]
    public function aMissingAssetIsNotMapped(): void
    {
        self::assertNull($this->assetMapper()->getAsset('styles/missing.css'));
    }

    #[Test]
    public function everyUrlInTheStylesheetsIsALocalMappedAsset(): void
    {
        $mapper = $this->assetMapper();
        $urlCount = 0;

        foreach (self::STYLESHEETS as $logicalPath) {
            foreach ($this->urlsIn($logicalPath) as $url) {
                ++$urlCount;
                $target = $this->normalizePath(\dirname($logicalPath).'/'.$url);
                self::assertNotNull($mapper->getAsset($target), $logicalPath.' references '.$url.', which is not a mapped asset');
            }
        }

        // fonts.css points at both fonts; zero would mean the check found nothing.
        self::assertSame(2, $urlCount);
    }

    #[Test]
    public function noStylesheetNamesARemoteHost(): void
    {
        foreach (self::STYLESHEETS as $logicalPath) {
            self::assertDoesNotMatchRegularExpression('#(https?:)?//[a-z0-9]#i', $this->contentsOf($logicalPath), $logicalPath.' references a remote host');
        }
    }

    #[Test]
    public function theEntrypointDoesNotImportStylesheets(): void
    {
        // A CSS import turns into a data: URL in the importmap, which the CSP forbids.
        self::assertDoesNotMatchRegularExpression('/\bimport\b[^;]*\.css[\'"]/', $this->stripComments($this->contentsOf('app.js')));
    }

    private function assetMapper(): AssetMapperInterface
    {
        return self::getContainer()->get(AssetMapperInterface::class);
    }

    /**
     * @return list<string>
     */
    private function urlsIn(string $logicalPath): array
    {
        preg_match_all('/url\(\s*[\'"]?([^\'")\s]+)[\'"]?\s*\)/i', $this->contentsOf($logicalPath), $matches);

        return $matches[1];
    }

    private function contentsOf(string $logicalPath): string
    {
        $asset = $this->assetMapper()->getAsset($logicalPath);
        self::assertNotNull($asset, $logicalPath.' is not mapped');
        $contents = file_get_contents($asset->sourcePath);
        self::assertIsString($contents);

        return $contents;
    }

    private function stripComments(string $source): string
    {
        return preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $source) ?? $source;
    }

    private function normalizePath(string $path): string
    {
        $parts = [];
        foreach (explode('/', $path) as $segment) {
            if ('..' === $segment) {
                array_pop($parts);
            } elseif ('.' !== $segment && '' !== $segment) {
                $parts[] = $segment;
            }
        }

        return implode('/', $parts);
    }
}
