<?php

declare(strict_types=1);

namespace App\Tests\Unit\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConfigDocTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function envFiles(): iterable
    {
        foreach (['.env', '.env.dev', '.env.test'] as $file) {
            yield $file => [$file];
        }
    }

    #[Test]
    #[DataProvider('envFiles')]
    public function everyVariableOfTheEnvFileIsNamedInTheConfigurationReference(string $envFile): void
    {
        $missing = $this->undocumentedNames($this->root().'/'.$envFile, $this->root().'/docs/operations/config.md');

        self::assertSame(
            [],
            $missing,
            \sprintf('%s sets variables that docs/operations/config.md does not name in backticks: %s', $envFile, implode(', ', $missing)),
        );
    }

    #[Test]
    public function aVariableMissingFromThePageIsReportedByName(): void
    {
        $fixtures = $this->root().'/tests/Fixtures/ConfigDoc';

        self::assertSame(
            ['UNDOCUMENTED_NAME'],
            $this->undocumentedNames($fixtures.'/env.sample', $fixtures.'/page.md'),
        );
    }

    #[Test]
    public function theParserFindsKnownVariables(): void
    {
        self::assertContains('APP_ENV', $this->variableNames($this->root().'/.env'));
        self::assertContains('DATABASE_URL', $this->variableNames($this->root().'/.env.dev'));
        self::assertContains('KERNEL_CLASS', $this->variableNames($this->root().'/.env.test'));
    }

    #[Test]
    public function commentedOutExamplesAreNotVariables(): void
    {
        $names = $this->variableNames($this->root().'/.env');

        self::assertSame(1, \count(array_keys($names, 'OTEL_EXPORTER_OTLP_ENDPOINT', true)));
    }

    private function root(): string
    {
        return \dirname(__DIR__, 3);
    }

    /**
     * @return list<string> names set in the env file but not named in backticks on the page
     */
    private function undocumentedNames(string $envPath, string $pagePath): array
    {
        return array_values(array_diff($this->variableNames($envPath), $this->documentedNames($pagePath)));
    }

    /**
     * @return list<string> names set by a NAME= line; comments and blank lines are skipped
     */
    private function variableNames(string $envPath): array
    {
        $lines = file($envPath, \FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);

        $names = [];
        foreach ($lines as $line) {
            if (1 === preg_match('/^([A-Z][A-Z0-9_]*)=/', $line, $match)) {
                $names[] = $match[1];
            }
        }

        return $names;
    }

    /**
     * @return list<string> every `NAME` in backticks on the page
     */
    private function documentedNames(string $pagePath): array
    {
        $page = file_get_contents($pagePath);
        self::assertIsString($page);

        preg_match_all('/`([A-Z][A-Z0-9_]*)`/', $page, $matches);

        return array_values(array_unique($matches[1]));
    }
}
