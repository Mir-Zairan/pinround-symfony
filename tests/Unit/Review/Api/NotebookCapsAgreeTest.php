<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\Api;

use App\Review\Api\Notebooks;
use App\Review\Domain\Notebook\Notebook;
use App\Review\Domain\Notebook\NotebookRefused;
use App\Review\Domain\Notebook\NotebookRows;
use App\Review\Domain\Scenario\ScenarioRefused;
use App\Review\Domain\Scenario\ScenarioRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The notebook caps a caller reads on Notebooks are the ones the domain refuses past, the scenario schema publishes,
 * rvw_notebook's CHECKs hold and the refusal texts name, so no layer lets through what another refuses.
 */
final class NotebookCapsAgreeTest extends TestCase
{
    private const string SCHEMA_FILE = __DIR__.'/../../../../docs/api/scenario.v1.json';
    private const string MIGRATION_FILE = __DIR__.'/../../../../migrations/Version20261120100000.php';

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function apiAndDomainCaps(): iterable
    {
        yield 'screenshots, Notebook' => [Notebooks::MAX_SCREENSHOTS, Notebook::MAX_SCREENSHOTS];
        yield 'scenarios, ScenarioRules' => [Notebooks::MAX_SCENARIOS, ScenarioRules::MAX_SCENARIOS];
    }

    #[Test]
    #[DataProvider('apiAndDomainCaps')]
    public function theApiCapIsTheDomainCap(int $apiCap, int $domainCap): void
    {
        self::assertSame($apiCap, $domainCap);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusalsPastTheCaps(): iterable
    {
        yield 'screenshots' => [\sprintf('A notebook holds at most %d screenshots.', Notebooks::MAX_SCREENSHOTS), NotebookRefused::TOO_MANY_SCREENSHOTS];
        yield 'scenarios' => [\sprintf('A notebook holds at most %d scenarios.', Notebooks::MAX_SCENARIOS), ScenarioRefused::TOO_MANY_SCENARIOS];
    }

    #[Test]
    #[DataProvider('refusalsPastTheCaps')]
    public function theRefusalNamesTheApiCap(string $textOfTheApiCap, string $refusal): void
    {
        self::assertSame($textOfTheApiCap, $refusal);
    }

    #[Test]
    public function theSchemaListsAtMostTheApisScenarios(): void
    {
        $schema = json_decode((string) file_get_contents(self::SCHEMA_FILE), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($schema);
        self::assertIsArray($schema['$defs'] ?? null);
        self::assertIsArray($schema['$defs']['scenarios'] ?? null);

        self::assertSame(Notebooks::MAX_SCENARIOS, $schema['$defs']['scenarios']['maxItems'] ?? null);
    }

    #[Test]
    public function theTableHoldsTheApiCapsAndTheDomainTitleCap(): void
    {
        self::assertSame(Notebooks::MAX_SCREENSHOTS, self::tableCap('/jsonb_array_length\(screenshots\) <= (\d+)\)/'));
        self::assertSame(Notebooks::MAX_SCENARIOS, self::tableCap('/jsonb_array_length\(scenarios\) <= (\d+)\)/'));
        self::assertSame(Notebook::TITLE_MAX_LENGTH, self::tableCap('/char_length\(title\) BETWEEN 1 AND (\d+)\)/'));
    }

    #[Test]
    public function aPageOfTheListFitsWhatTheRowsReadAtOnce(): void
    {
        self::assertGreaterThanOrEqual(1, Notebooks::NOTEBOOKS_PER_PAGE);
        self::assertLessThanOrEqual(NotebookRows::MAX_PAGE_SIZE, Notebooks::NOTEBOOKS_PER_PAGE);
    }

    /**
     * The one number $pattern captures in rvw_notebook's migration; the test fails when the CHECK is gone or written
     * twice.
     */
    private static function tableCap(string $pattern): int
    {
        $migration = (string) file_get_contents(self::MIGRATION_FILE);
        self::assertSame(1, preg_match_all($pattern, $migration, $matches), $pattern);

        return (int) $matches[1][0];
    }
}
