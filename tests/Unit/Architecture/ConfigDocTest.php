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
        $documented = $this->documentedNames();
        $missing = array_values(array_diff($this->variableNames($envFile), $documented));

        self::assertSame(
            [],
            $missing,
            \sprintf('%s sets variables that docs/operations/config.md does not name in backticks: %s', $envFile, implode(', ', $missing)),
        );
    }

    #[Test]
    public function theParserFindsKnownVariables(): void
    {
        self::assertContains('APP_ENV', $this->variableNames('.env'));
        self::assertContains('DATABASE_URL', $this->variableNames('.env.dev'));
        self::assertContains('KERNEL_CLASS', $this->variableNames('.env.test'));
    }

    #[Test]
    public function commentedOutExamplesAreNotVariables(): void
    {
        $names = $this->variableNames('.env');

        self::assertSame(1, \count(array_keys($names, 'OTEL_EXPORTER_OTLP_ENDPOINT', true)));
    }

    /**
     * @return list<string> names set by a NAME= line; comments and blank lines are skipped
     */
    private function variableNames(string $envFile): array
    {
        $lines = file(\dirname(__DIR__, 3).'/'.$envFile, \FILE_IGNORE_NEW_LINES);
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
    private function documentedNames(): array
    {
        $page = file_get_contents(\dirname(__DIR__, 3).'/docs/operations/config.md');
        self::assertIsString($page);

        preg_match_all('/`([A-Z][A-Z0-9_]*)`/', $page, $matches);

        return array_values(array_unique($matches[1]));
    }
}
