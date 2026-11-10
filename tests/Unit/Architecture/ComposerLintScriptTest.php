<?php

declare(strict_types=1);

namespace App\Tests\Unit\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins what `composer lint` runs, so a lint cannot drop out of the gate unnoticed.
 */
final class ComposerLintScriptTest extends TestCase
{
    #[Test]
    public function lintRunsEveryLintInOrder(): void
    {
        self::assertSame(
            [
                '@php bin/console lint:container',
                '@php bin/console lint:yaml config --parse-tags',
                '@php bin/console lint:twig src',
                '@php bin/check-tenant-setting',
                '@lint:docs',
                '@lint:copy',
            ],
            self::scripts()['lint'],
        );
    }

    #[Test]
    public function theDocsAndCopyLintsRunTheirScripts(): void
    {
        $scripts = self::scripts();

        self::assertSame('@php bin/lint-docs', $scripts['lint:docs']);
        self::assertSame('@php bin/lint-copy', $scripts['lint:copy']);
    }

    /**
     * @return array<string, string|list<string>>
     */
    private static function scripts(): array
    {
        $composer = json_decode((string) file_get_contents(\dirname(__DIR__, 3).'/composer.json'), true, 512, \JSON_THROW_ON_ERROR);
        \assert(\is_array($composer) && \is_array($composer['scripts']));

        /** @var array<string, string|list<string>> $scripts */
        $scripts = $composer['scripts'];

        return $scripts;
    }
}
