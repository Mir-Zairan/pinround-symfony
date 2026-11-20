<?php

declare(strict_types=1);

namespace App\Tests\Unit\AgentApi\Infrastructure\Mcp\Tool;

use App\AgentApi\Infrastructure\Mcp\ScreenshotContextArguments;
use App\AgentApi\Infrastructure\Mcp\TicketVisibilityCheck;
use App\AgentApi\Infrastructure\Mcp\Tool\CreateReport;
use App\AgentApi\Infrastructure\Mcp\ToolCallOutcome;
use App\AgentApi\Infrastructure\Mcp\ToolRefusal;
use App\Identity\Api\CurrentApiToken;
use App\Identity\Api\CurrentUser;
use App\Media\Api\UploadCompletions;
use App\Review\Api\ReportDrafts;
use App\Ticketing\Api\ProjectQueries;
use App\Ticketing\Api\TicketTurnQueries;
use Mcp\Capability\Discovery\SchemaValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * create_report's picture context over MCP (P14.T11): each screenshot's title and summary are required in the schema
 * tools/list shows and refused when missing or blank, while /api/v1's schema leaves them optional. Its other rules are
 * proven through /mcp in the functional CreateReportTest. The clock is frozen on 10 Nov 2026, not today.
 */
final class CreateReportTest extends TestCase
{
    private const string FIRST_UPLOAD = '019a5f00-0000-7000-8000-00000000f101';
    private const string SECOND_UPLOAD = '019a5f00-0000-7000-8000-00000000f102';

    #[Test]
    public function overMcpEachScreenshotsSchemaRequiresTheTitleAndTheSummaryWhileApiV1sLeavesThemOptional(): void
    {
        $tool = $this->createReport();

        self::assertSame(['upload_id', 'title', 'summary'], self::pictureSchemaOf($tool->mcpDefinition()->inputSchema)['required'] ?? null);
        self::assertSame(['upload_id'], self::pictureSchemaOf($tool->definition()->inputSchema)['required'] ?? null);
        self::assertSame(['project', 'title'], $tool->mcpDefinition()->inputSchema['required'] ?? null, 'A report without screenshots stays allowed.');
        self::assertStringContainsString('screenshots?: [{upload_id, title, summary, pins?', (string) $tool->mcpDefinition()->description);
        self::assertStringContainsString(ScreenshotContextArguments::RULE, (string) $tool->mcpDefinition()->description);
    }

    /**
     * The first screenshot is complete, so the reason names the second one's.
     *
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function secondScreenshotsWithoutContext(): iterable
    {
        yield 'no title' => [['upload_id' => self::SECOND_UPLOAD, 'summary' => 'The total.'], '/screenshots/1/title'];
        yield 'no summary' => [['upload_id' => self::SECOND_UPLOAD, 'title' => 'Cart'], '/screenshots/1/summary'];
        yield 'a blank summary' => [['upload_id' => self::SECOND_UPLOAD, 'title' => 'Cart', 'summary' => " \r\n\u{2028}"], '/screenshots/1/summary'];
    }

    /**
     * @param array<string, mixed> $secondScreenshot
     */
    #[Test]
    #[DataProvider('secondScreenshotsWithoutContext')]
    public function overMcpAScreenshotWithAMissingOrBlankTitleOrSummaryIsRefusedWithTheReasonAndNoDraftIsMade(array $secondScreenshot, string $pointer): void
    {
        $reportDrafts = $this->createMock(ReportDrafts::class);
        $reportDrafts->expects(self::never())->method('create');
        $arguments = ['project' => 'SHOP', 'title' => 'Total is doubled', 'screenshots' => [self::completeScreenshot(), $secondScreenshot]];

        try {
            $this->createReport($reportDrafts)->refuseMissingScreenshotContext($arguments);
            self::fail('The call was not refused.');
        } catch (ToolRefusal $refusal) {
            self::assertSame(ToolCallOutcome::InvalidInput, $refusal->outcome);
            self::assertSame(\sprintf(ToolRefusal::INVALID_INPUT, \sprintf(ScreenshotContextArguments::MISSING_OR_BLANK, $pointer)), $refusal->getMessage());
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function argumentsTheCheckLeavesAlone(): iterable
    {
        yield 'no screenshots' => [['project' => 'SHOP', 'title' => 'Total is doubled']];
        yield 'an empty list' => [['project' => 'SHOP', 'title' => 'Total is doubled', 'screenshots' => []]];
        yield 'screenshots of CR LF and multibyte text' => [['project' => 'SHOP', 'title' => 'Total is doubled', 'screenshots' => [self::completeScreenshot()]]];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[Test]
    #[DataProvider('argumentsTheCheckLeavesAlone')]
    public function overMcpAReportWithoutScreenshotsOrWithCompleteOnesPassesTheCheckAndTheSchema(array $arguments): void
    {
        $tool = $this->createReport();

        $tool->refuseMissingScreenshotContext($arguments);

        self::assertSame([], new SchemaValidator()->validateAgainstJsonSchema(
            json_decode(json_encode($arguments, \JSON_THROW_ON_ERROR), false, flags: \JSON_THROW_ON_ERROR),
            $tool->mcpDefinition()->inputSchema,
        ));
    }

    /**
     * An object where the list should be is the schema's to refuse, as "screenshots" is no array there.
     */
    #[Test]
    public function screenshotsThatAreNoListAreLeftToTheSchema(): void
    {
        $tool = $this->createReport();
        $arguments = ['project' => 'SHOP', 'title' => 'Total is doubled', 'screenshots' => ['first' => ['upload_id' => self::FIRST_UPLOAD]]];

        $tool->refuseMissingScreenshotContext($arguments);

        self::assertNotSame([], new SchemaValidator()->validateAgainstJsonSchema(
            json_decode(json_encode($arguments, \JSON_THROW_ON_ERROR), false, flags: \JSON_THROW_ON_ERROR),
            $tool->mcpDefinition()->inputSchema,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private static function completeScreenshot(): array
    {
        return ['upload_id' => self::FIRST_UPLOAD, 'title' => "Cart\r\nPanier · 価格", 'summary' => "Der Gesamtbetrag ist doppelt.\r\n合計が二重です。"];
    }

    /**
     * @param array<string, mixed> $inputSchema
     *
     * @return array<mixed> the schema of one item of screenshots
     */
    private static function pictureSchemaOf(array $inputSchema): array
    {
        $properties = $inputSchema['properties'] ?? null;
        self::assertIsArray($properties);
        $screenshots = $properties['screenshots'] ?? null;
        self::assertIsArray($screenshots);
        $items = $screenshots['items'] ?? null;
        self::assertIsArray($items);

        return $items;
    }

    private function createReport(?ReportDrafts $reportDrafts = null): CreateReport
    {
        return new CreateReport(
            self::createStub(CurrentUser::class),
            new TicketVisibilityCheck(self::createStub(CurrentApiToken::class), self::createStub(TicketTurnQueries::class), self::createStub(AuthorizationCheckerInterface::class)),
            self::createStub(ProjectQueries::class),
            self::createStub(UploadCompletions::class),
            $reportDrafts ?? self::createStub(ReportDrafts::class),
            self::createStub(UrlGeneratorInterface::class),
            new MockClock('2026-11-10 09:00:20 UTC'),
        );
    }
}
