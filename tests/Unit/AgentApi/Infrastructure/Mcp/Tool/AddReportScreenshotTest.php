<?php

declare(strict_types=1);

namespace App\Tests\Unit\AgentApi\Infrastructure\Mcp\Tool;

use App\AgentApi\Infrastructure\Mcp\ScreenshotContextArguments;
use App\AgentApi\Infrastructure\Mcp\TicketVisibilityCheck;
use App\AgentApi\Infrastructure\Mcp\Tool\AddReportScreenshot;
use App\AgentApi\Infrastructure\Mcp\ToolCallOutcome;
use App\AgentApi\Infrastructure\Mcp\ToolRefusal;
use App\Identity\Api\ApiTokenAccess;
use App\Identity\Api\CurrentApiToken;
use App\Identity\Api\CurrentUser;
use App\Media\Api\CompletedUpload;
use App\Media\Api\MediaImageStatus;
use App\Media\Api\UploadTarget;
use App\Review\Api\ReportDraftRefused;
use App\Review\Api\ReportDraftView;
use App\Review\Api\ScreenshotWithPins;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\TicketTurnSnapshot;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketTurnQueries;
use Mcp\Capability\Discovery\SchemaValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * add_report_screenshot over ScriptedReportDrafts and ScriptedUploadCompletions: the status and count are what
 * ReportDrafts found under the draft's row lock, not what ofOwner() read before. Lena's SHOP draft shows one picture
 * when ofOwner() reads it; another request may change it before the add locks the row. The clock is frozen on
 * 10 Nov 2026, not today.
 */
final class AddReportScreenshotTest extends TestCase
{
    /** Public, for the anonymous fakes. */
    public const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000d001';
    public const string LENA = '0197a3c0-0000-7000-8000-00000000d002';
    private const string DRAFT = '0197a3c0-0000-7000-8000-00000000d003';
    private const string FIRST_PICTURE = '0197a3c0-0000-7000-8000-00000000d011';
    private const string UPLOAD = '0197a3c0-0000-7000-8000-00000000d021';
    private const string NOW = '2026-11-10 09:00:20 UTC';

    private ScriptedUploadCompletions $uploads;

    protected function setUp(): void
    {
        $this->uploads = new ScriptedUploadCompletions();
        $this->uploads->add(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), self::UPLOAD, new UploadTarget('new_report', 'SHOP'), new CompletedUpload(self::UPLOAD, MediaImageStatus::Ready));
    }

    /**
     * ofOwner() shows one picture, not this one; under the lock the draft holds five, this one among them.
     */
    #[Test]
    public function aPictureAddedSinceTheReadIsAlreadyInTheDraftWithTheCountUnderTheLock(): void
    {
        $drafts = new ScriptedReportDrafts(self::draftWithOnePicture(), [self::FIRST_PICTURE, ...self::otherPictures(3), self::UPLOAD]);

        $answer = $this->callWith($drafts);

        self::assertSame(['draft_id' => self::DRAFT, 'media_id' => self::UPLOAD, 'status' => AddReportScreenshot::ALREADY_IN_DRAFT, 'screenshot_count' => 5], $answer);
        self::assertSame([], $drafts->added);
    }

    /**
     * ofOwner() shows one picture; under the lock the draft holds six others, so the add makes seven, not two.
     */
    #[Test]
    public function aPictureAddedToADraftThatGrewSinceTheReadAnswersTheCountUnderTheLock(): void
    {
        $drafts = new ScriptedReportDrafts(self::draftWithOnePicture(), [self::FIRST_PICTURE, ...self::otherPictures(5)]);

        $answer = $this->callWith($drafts);

        self::assertSame(['draft_id' => self::DRAFT, 'media_id' => self::UPLOAD, 'status' => AddReportScreenshot::ADDED, 'screenshot_count' => 7], $answer);
        self::assertEquals([[self::DRAFT, new ScreenshotWithPins(self::UPLOAD), new \DateTimeImmutable(self::NOW)]], $drafts->added);
    }

    /**
     * ofOwner() shows room for the picture; under the lock another request filled the draft.
     */
    #[Test]
    public function aDraftFilledSinceTheReadIsRefusedWithReviewsTextAndNothingIsAdded(): void
    {
        $drafts = new ScriptedReportDrafts(self::draftWithOnePicture(), [self::FIRST_PICTURE, ...self::otherPictures(9)]);
        $tool = $this->tool($drafts);
        $arguments = ['report' => self::DRAFT, 'upload_id' => self::UPLOAD];
        $target = $tool->targetOf($arguments);

        try {
            $tool->call($arguments, $target);
            self::fail('The call was not refused.');
        } catch (ToolRefusal $refusal) {
            self::assertSame(ReportDraftRefused::TOO_MANY_SCREENSHOTS, $refusal->getMessage());
        }
        self::assertSame([], $drafts->added);
    }

    /**
     * tools/list shows mcpDefinition(); /api/v1, which the browser extension calls, checks definition() (P14.T11).
     */
    #[Test]
    public function overMcpTheSchemaRequiresTheTitleAndTheSummaryWhileApiV1sLeavesThemOptional(): void
    {
        $tool = $this->tool(new ScriptedReportDrafts(self::draftWithOnePicture(), [self::FIRST_PICTURE]));

        self::assertSame(['report', 'upload_id', 'title', 'summary'], $tool->mcpDefinition()->inputSchema['required'] ?? null);
        self::assertSame(['report', 'upload_id'], $tool->definition()->inputSchema['required'] ?? null);
        self::assertStringContainsString('{report, upload_id, title, summary, pins?', (string) $tool->mcpDefinition()->description);
        self::assertStringContainsString(ScreenshotContextArguments::RULE, (string) $tool->mcpDefinition()->description);
        self::assertStringContainsString('{report, upload_id, title?, summary?, pins?', (string) $tool->definition()->description);
        self::assertStringNotContainsString(ScreenshotContextArguments::RULE, (string) $tool->definition()->description);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function argumentsWithoutPictureContext(): iterable
    {
        $upload = ['report' => self::DRAFT, 'upload_id' => self::UPLOAD];
        yield 'no title' => [[...$upload, 'summary' => 'The total.'], '/title'];
        yield 'no summary' => [[...$upload, 'title' => 'Cart'], '/summary'];
        yield 'a null title' => [[...$upload, 'title' => null, 'summary' => 'The total.'], '/title'];
        yield 'a blank title of CR LF and an ideographic space' => [[...$upload, 'title' => " \r\n\u{3000}", 'summary' => 'The total.'], '/title'];
        yield 'a summary of a BOM and a zero-width space' => [[...$upload, 'title' => 'Cart', 'summary' => "\u{FEFF}\u{200B}"], '/summary'];
        yield 'an empty summary' => [[...$upload, 'title' => 'Cart', 'summary' => ''], '/summary'];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[Test]
    #[DataProvider('argumentsWithoutPictureContext')]
    public function overMcpAMissingOrBlankTitleOrSummaryIsRefusedWithTheReasonAndNothingIsAdded(array $arguments, string $pointer): void
    {
        $drafts = new ScriptedReportDrafts(self::draftWithOnePicture(), [self::FIRST_PICTURE]);

        $refusal = self::refusalOf(fn () => $this->tool($drafts)->refuseMissingScreenshotContext($arguments));

        self::assertSame(ToolCallOutcome::InvalidInput, $refusal->outcome);
        self::assertSame(\sprintf(ToolRefusal::INVALID_INPUT, \sprintf(ScreenshotContextArguments::MISSING_OR_BLANK, $pointer)), $refusal->getMessage());
        self::assertSame([], $drafts->added);
    }

    #[Test]
    public function overMcpATitleAndSummaryWithCrLfAndMultibyteTextPassTheCheckAndTheSchema(): void
    {
        $tool = $this->tool(new ScriptedReportDrafts(self::draftWithOnePicture(), [self::FIRST_PICTURE]));
        $arguments = ['report' => self::DRAFT, 'upload_id' => self::UPLOAD, 'title' => "Cart\r\nPanier · 価格", 'summary' => "Der Gesamtbetrag ist doppelt.\r\n合計が二重です。"];

        $tool->refuseMissingScreenshotContext($arguments);

        self::assertSame([], new SchemaValidator()->validateAgainstJsonSchema(
            json_decode(json_encode($arguments, \JSON_THROW_ON_ERROR), false, flags: \JSON_THROW_ON_ERROR),
            $tool->mcpDefinition()->inputSchema,
        ));
    }

    /**
     * @param \Closure(): mixed $call
     */
    private static function refusalOf(\Closure $call): ToolRefusal
    {
        try {
            $call();
        } catch (ToolRefusal $refusal) {
            return $refusal;
        }
        self::fail('The call was not refused.');
    }

    /**
     * @return array<mixed> the structured answer
     */
    private function callWith(ScriptedReportDrafts $drafts): array
    {
        $tool = $this->tool($drafts);
        $arguments = ['report' => self::DRAFT, 'upload_id' => self::UPLOAD];
        $result = $tool->call($arguments, $tool->targetOf($arguments));
        self::assertFalse($result->isError);
        $structured = $result->structuredContent;
        self::assertIsArray($structured);

        return $structured;
    }

    /**
     * @return list<string> $count picture ids, none of them FIRST_PICTURE or UPLOAD
     */
    private static function otherPictures(int $count): array
    {
        return array_map(static fn (int $number): string => \sprintf('0197a3c0-0000-7000-8000-0000000d01%02d', $number), range(1, $count));
    }

    private function tool(ScriptedReportDrafts $drafts): AddReportScreenshot
    {
        return new AddReportScreenshot(
            new class implements CurrentUser {
                public function id(): UserId
                {
                    return new UserId(AddReportScreenshotTest::LENA);
                }

                public function idOf(mixed $tokenUser): ?UserId
                {
                    return null;
                }
            },
            new TicketVisibilityCheck(
                new class implements CurrentApiToken {
                    public function access(): ApiTokenAccess
                    {
                        return new ApiTokenAccess('0197a3c0-0000-7000-8000-00000000d031', new OrganizationId(AddReportScreenshotTest::ACME_FOODS), ['reports:write'], new \DateTimeImmutable('2027-02-08 09:00:00 UTC'));
                    }
                },
                new class implements TicketTurnQueries {
                    public function factsOf(TicketId $ticketId): ?TicketTurnSnapshot
                    {
                        throw new \LogicException('add_report_screenshot reads no ticket.');
                    }
                },
                new class implements AuthorizationCheckerInterface {
                    public function isGranted(mixed $attribute, mixed $subject = null, ?AccessDecision $accessDecision = null): bool
                    {
                        throw new \LogicException('add_report_screenshot leaves the voter to the gate.');
                    }
                },
            ),
            $drafts,
            $this->uploads,
            new MockClock(self::NOW),
        );
    }

    private static function draftWithOnePicture(): ReportDraftView
    {
        $at = new \DateTimeImmutable('2026-11-05 08:00:00 UTC');

        return new ReportDraftView(self::DRAFT, new UserId(self::LENA), 'SHOP', 'The cart total goes below 0', '', [new ScreenshotWithPins(self::FIRST_PICTURE)], 2, $at, $at);
    }
}
