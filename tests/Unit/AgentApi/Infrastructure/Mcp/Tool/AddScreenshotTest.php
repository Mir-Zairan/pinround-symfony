<?php

declare(strict_types=1);

namespace App\Tests\Unit\AgentApi\Infrastructure\Mcp\Tool;

use App\AgentApi\Infrastructure\Mcp\TicketVisibilityCheck;
use App\AgentApi\Infrastructure\Mcp\Tool\AddScreenshot;
use App\AgentApi\Infrastructure\Mcp\ToolCallOutcome;
use App\AgentApi\Infrastructure\Mcp\ToolRefusal;
use App\AgentApi\Infrastructure\Mcp\ToolTarget;
use App\Identity\Api\ApiTokenAccess;
use App\Identity\Api\CurrentApiToken;
use App\Identity\Api\CurrentUser;
use App\Media\Api\CompletedUpload;
use App\Media\Api\MediaImageStatus;
use App\Media\Api\UploadIntentNotFound;
use App\Media\Api\UploadRefused;
use App\Media\Api\UploadTarget;
use App\Media\Api\UploadTargetDenied;
use App\Review\Api\AddedDraftScreenshot;
use App\Review\Api\RoundAccessFacts;
use App\Review\Api\RoundPermission;
use App\Review\Api\RoundRefused;
use App\Review\Api\RoundView;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\TicketTurnSnapshot;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketKeyQueries;
use App\Ticketing\Api\TicketPermission;
use App\Ticketing\Api\TicketTurnQueries;
use App\Ticketing\Api\TicketVisibilityFacts;
use Mcp\Capability\Discovery\SchemaValidator;
use Mcp\Schema\Content\TextContent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * add_screenshot over fakes of Media's, Review's and Ticketing's Api: its input schema as the gate's SchemaValidator
 * reads it, the round an upload finds and its voter attribute, what each processing state answers, the one Review
 * command a ready picture runs and both of its answers, and Review's and Media's refusals as its own. Omar owns an
 * unshared draft on SHOP-1 that holds one picture already, and a shared draft there; Mia owns an unshared draft there.
 * SHOP-2 is a ticket of its own.
 */
final class AddScreenshotTest extends TestCase
{
    /** Public, for the anonymous fakes. */
    public const string ACME_FOODS = '019a5f00-0000-7000-8000-00000000e701';
    public const string OMAR = '019a5f00-0000-7000-8000-00000000e721';
    public const string SHOP_1 = '019a5f00-0000-7000-8000-00000000e731';
    public const string SHOP_2 = '019a5f00-0000-7000-8000-00000000e732';
    private const string BETA_BAKERY = '019a5f00-0000-7000-8000-00000000e702';
    private const string MIA = '019a5f00-0000-7000-8000-00000000e722';
    private const string OMARS_DRAFT = '019a5f00-0000-7000-8000-00000000e741';
    private const string OMARS_SHARED_DRAFT = '019a5f00-0000-7000-8000-00000000e742';
    private const string MIAS_DRAFT = '019a5f00-0000-7000-8000-00000000e743';
    private const string OMARS_ADDED_SHOT = '019a5f00-0000-7000-8000-00000000e751';
    private const string ADDED_UPLOAD = '019a5f00-0000-7000-8000-00000000e761';
    private const string READY_UPLOAD = '019a5f00-0000-7000-8000-00000000e762';
    private const string PENDING_UPLOAD = '019a5f00-0000-7000-8000-00000000e763';
    private const string REJECTED_UPLOAD = '019a5f00-0000-7000-8000-00000000e764';
    private const string SHARED_DRAFT_UPLOAD = '019a5f00-0000-7000-8000-00000000e765';
    private const string MIAS_DRAFT_UPLOAD_OF_OMAR = '019a5f00-0000-7000-8000-00000000e766';
    private const string MIAS_OWN_UPLOAD = '019a5f00-0000-7000-8000-00000000e767';
    private const string TICKET_UPLOAD = '019a5f00-0000-7000-8000-00000000e768';
    private const string UNKNOWN_ROUND_UPLOAD = '019a5f00-0000-7000-8000-00000000e769';
    private const string BETA_BAKERY_UPLOAD = '019a5f00-0000-7000-8000-00000000e76a';
    private const string NOW = '2026-11-10 09:00:20 UTC';

    private KnownDraftRounds $rounds;
    private ScriptedUploadCompletions $uploads;
    private RecordingDraftScreenshots $draftScreenshots;
    private bool $isTicketHidden = false;

    protected function setUp(): void
    {
        $acmeFoods = new OrganizationId(self::ACME_FOODS);
        $omar = new UserId(self::OMAR);
        $shop1 = new TicketId(self::SHOP_1);
        $this->rounds = new KnownDraftRounds($acmeFoods);
        $this->rounds->add($shop1, new RoundView(self::OMARS_DRAFT, null, RoundView::DRAFT, $omar, null, 'open', []));
        $this->rounds->add($shop1, new RoundView(self::OMARS_SHARED_DRAFT, 1, RoundView::DRAFT, $omar, new \DateTimeImmutable('2026-11-05 10:00:00 UTC'), 'open', []));
        $this->rounds->add($shop1, new RoundView(self::MIAS_DRAFT, null, RoundView::DRAFT, new UserId(self::MIA), null, 'open', []));

        $this->uploads = new ScriptedUploadCompletions();
        $roundOf = static fn (string $roundId): UploadTarget => new UploadTarget('round', $roundId);
        $ready = static fn (string $mediaId): CompletedUpload => new CompletedUpload($mediaId, MediaImageStatus::Ready);
        $this->uploads->add($acmeFoods, $omar, self::ADDED_UPLOAD, $roundOf(self::OMARS_DRAFT), $ready(self::ADDED_UPLOAD));
        $this->uploads->add($acmeFoods, $omar, self::READY_UPLOAD, $roundOf(self::OMARS_DRAFT), $ready(self::READY_UPLOAD));
        $this->uploads->add($acmeFoods, $omar, self::PENDING_UPLOAD, $roundOf(self::OMARS_DRAFT), new CompletedUpload(self::PENDING_UPLOAD, MediaImageStatus::Pending));
        $this->uploads->add($acmeFoods, $omar, self::REJECTED_UPLOAD, $roundOf(self::OMARS_DRAFT), new CompletedUpload(self::REJECTED_UPLOAD, MediaImageStatus::Rejected));
        $this->uploads->add($acmeFoods, $omar, self::SHARED_DRAFT_UPLOAD, $roundOf(self::OMARS_SHARED_DRAFT), $ready(self::SHARED_DRAFT_UPLOAD));
        $this->uploads->add($acmeFoods, $omar, self::MIAS_DRAFT_UPLOAD_OF_OMAR, $roundOf(self::MIAS_DRAFT), $ready(self::MIAS_DRAFT_UPLOAD_OF_OMAR));
        $this->uploads->add($acmeFoods, new UserId(self::MIA), self::MIAS_OWN_UPLOAD, $roundOf(self::MIAS_DRAFT), $ready(self::MIAS_OWN_UPLOAD));
        $this->uploads->add($acmeFoods, $omar, self::TICKET_UPLOAD, new UploadTarget('ticket', self::SHOP_1), $ready(self::TICKET_UPLOAD));
        $this->uploads->add($acmeFoods, $omar, self::UNKNOWN_ROUND_UPLOAD, $roundOf('019a5f00-0000-7000-8000-00000000e749'), $ready(self::UNKNOWN_ROUND_UPLOAD));
        $this->uploads->add(new OrganizationId(self::BETA_BAKERY), $omar, self::BETA_BAKERY_UPLOAD, $roundOf(self::OMARS_DRAFT), $ready(self::BETA_BAKERY_UPLOAD));
        $this->draftScreenshots = new RecordingDraftScreenshots();
        $this->draftScreenshots->heldBefore[self::ADDED_UPLOAD] = new AddedDraftScreenshot(self::OMARS_ADDED_SHOT, true, 2);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function validArguments(): iterable
    {
        $pin = ['x_pct' => 12.5, 'y_pct' => 40, 'note' => 'The total'];
        yield 'the key and the upload alone' => [['key' => 'SHOP-1', 'upload_id' => self::READY_UPLOAD]];
        yield 'everything' => [['key' => 'SHOP-1', 'upload_id' => self::READY_UPLOAD, 'title' => 'Cart', 'summary' => 'The total.', 'pins' => [$pin]]];
        yield 'no pins' => [['key' => 'SHOP-1', 'upload_id' => self::READY_UPLOAD, 'pins' => []]];
        yield 'a title of 120 characters of two bytes each' => [['key' => 'SHOP-1', 'upload_id' => self::READY_UPLOAD, 'title' => str_repeat('é', 120)]];
        yield 'a summary of 600 characters' => [['key' => 'SHOP-1', 'upload_id' => self::READY_UPLOAD, 'summary' => str_repeat('a', 600)]];
        yield 'pins at the edges' => [['key' => 'SHOP-1', 'upload_id' => self::READY_UPLOAD, 'pins' => [['x_pct' => 0, 'y_pct' => 100, 'note' => '']]]];
        yield '100 pins' => [['key' => 'SHOP-1', 'upload_id' => self::READY_UPLOAD, 'pins' => array_fill(0, 100, $pin)]];
        yield 'a note of 1,000 characters' => [['key' => 'SHOP-1', 'upload_id' => self::READY_UPLOAD, 'pins' => [['x_pct' => 1, 'y_pct' => 1, 'note' => str_repeat('n', 1000)]]]];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[Test]
    #[DataProvider('validArguments')]
    public function argumentsWithinTheCapsOfTheUiPassTheInputSchema(array $arguments): void
    {
        self::assertSame([], $this->problemsOf($arguments));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidArguments(): iterable
    {
        $pin = ['x_pct' => 12.5, 'y_pct' => 40, 'note' => 'The total'];
        $upload = ['key' => 'SHOP-1', 'upload_id' => self::READY_UPLOAD];
        yield 'no upload' => [['key' => 'SHOP-1']];
        yield 'no key' => [['upload_id' => self::READY_UPLOAD]];
        yield 'a title of 121 characters' => [[...$upload, 'title' => str_repeat('é', 121)]];
        yield 'a summary of 601 characters' => [[...$upload, 'summary' => str_repeat('a', 601)]];
        yield '101 pins' => [[...$upload, 'pins' => array_fill(0, 101, $pin)]];
        yield 'a pin right of the picture' => [[...$upload, 'pins' => [[...$pin, 'x_pct' => 100.5]]]];
        yield 'a pin above the picture' => [[...$upload, 'pins' => [[...$pin, 'y_pct' => -1]]]];
        yield 'a pin without a note' => [[...$upload, 'pins' => [['x_pct' => 1, 'y_pct' => 1]]]];
        yield 'a note of 1,001 characters' => [[...$upload, 'pins' => [[...$pin, 'note' => str_repeat('n', 1001)]]]];
        yield 'a position as text' => [[...$upload, 'pins' => [[...$pin, 'x_pct' => '10']]]];
        yield 'a pin with a field of its own' => [[...$upload, 'pins' => [[...$pin, 'number' => 1]]]];
        yield 'pins that are no list' => [[...$upload, 'pins' => 'here']];
        yield 'a picture as base64' => [[...$upload, 'image' => 'iVBORw0KGgo=']];
        yield 'an upload id past the cap' => [['key' => 'SHOP-1', 'upload_id' => str_repeat('a', 65)]];
        yield 'a title that is no string' => [[...$upload, 'title' => null]];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[Test]
    #[DataProvider('invalidArguments')]
    public function argumentsPastACapOrOfAnotherShapeFailTheInputSchema(array $arguments): void
    {
        self::assertNotSame([], $this->problemsOf($arguments));
    }

    #[Test]
    public function itNeedsTheRoundsWriteScope(): void
    {
        $tool = $this->addScreenshot();

        self::assertSame('rounds:write', $tool->requiredScope());
        self::assertSame(AddScreenshot::NAME, $tool->definition()->name);
    }

    #[Test]
    public function anUploadForTheCallersUnsharedDraftIsGuardedByRoundEditAndAuditedAsTheRound(): void
    {
        $target = $this->addScreenshot()->targetOf(['key' => 'SHOP-1', 'upload_id' => strtoupper(self::READY_UPLOAD)]);

        self::assertSame(RoundPermission::EDIT, $target->voterAttribute);
        self::assertSame([AddScreenshot::AUDIT_TARGET_TYPE, self::OMARS_DRAFT], [$target->auditType, $target->auditId]);
        self::assertEquals(new RoundAccessFacts(new OrganizationId(self::ACME_FOODS), self::OMARS_DRAFT, new TicketId(self::SHOP_1), new UserId(self::OMAR), true, false), $target->subject);
        self::assertSame([], $this->uploads->completions, 'finding the target completes nothing');
    }

    /**
     * Media never lets a person begin an upload for another person's draft; were there one, RoundPermission EDIT,
     * which only the draft's owner is granted, keeps it from the caller.
     */
    #[Test]
    public function anUploadForAnotherPersonsUnsharedDraftIsLeftToRoundEditOnThatPersonsDraft(): void
    {
        $target = $this->addScreenshot()->targetOf(['key' => 'SHOP-1', 'upload_id' => self::MIAS_DRAFT_UPLOAD_OF_OMAR]);

        self::assertSame(RoundPermission::EDIT, $target->voterAttribute);
        self::assertEquals(new RoundAccessFacts(new OrganizationId(self::ACME_FOODS), self::MIAS_DRAFT, new TicketId(self::SHOP_1), new UserId(self::MIA), true, false), $target->subject);
    }

    #[Test]
    public function anUploadForASharedDraftIsGuardedByRoundViewAndIsReadOnlyBeforeAnythingIsCompleted(): void
    {
        $tool = $this->addScreenshot();
        $arguments = ['key' => 'SHOP-1', 'upload_id' => self::SHARED_DRAFT_UPLOAD, 'title' => 'Cart'];
        $target = $tool->targetOf($arguments);
        self::assertSame(RoundPermission::VIEW, $target->voterAttribute);

        $refusal = self::refusalOf(static fn () => $tool->call($arguments, $target));

        self::assertSame(ToolCallOutcome::ReadOnly, $refusal->outcome);
        self::assertSame(ToolRefusal::READ_ONLY, $refusal->getMessage());
        self::assertSame([], $this->uploads->completions);
        $this->assertNothingChanged();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function uploadsNotFound(): iterable
    {
        yield 'another person\'s upload' => ['SHOP-1', self::MIAS_OWN_UPLOAD];
        yield 'an upload of another organization' => ['SHOP-1', self::BETA_BAKERY_UPLOAD];
        yield 'an upload for a ticket, not a round' => ['SHOP-1', self::TICKET_UPLOAD];
        yield 'an upload for a round there is not' => ['SHOP-1', self::UNKNOWN_ROUND_UPLOAD];
        yield 'an upload for a round of another ticket' => ['SHOP-2', self::READY_UPLOAD];
        yield 'an unknown key' => ['SHOP-9', self::READY_UPLOAD];
        yield 'an unknown upload' => ['SHOP-1', '019a5f00-0000-7000-8000-00000000e76f'];
        yield 'a malformed upload id' => ['SHOP-1', 'not-an-id'];
    }

    #[Test]
    #[DataProvider('uploadsNotFound')]
    public function anUploadThatIsNotTheCallersForARoundOfTheTicketIsNotFound(string $key, string $uploadId): void
    {
        $refusal = self::refusalOf(fn () => $this->addScreenshot()->targetOf(['key' => $key, 'upload_id' => $uploadId]));

        self::assertSame(ToolCallOutcome::NotFound, $refusal->outcome);
        self::assertSame(ToolRefusal::NOT_FOUND, $refusal->getMessage());
        self::assertSame([], $this->uploads->completions);
    }

    /**
     * RoundPermission EDIT grants Omar his own unshared draft without reading the ticket, so the tool asks
     * TicketPermission VIEW before it looks the upload up.
     */
    #[Test]
    public function theOwnersReadyUploadOnATicketHeMayNotSeeIsNotFoundAndNothingIsCompleted(): void
    {
        $this->isTicketHidden = true;

        $refusal = self::refusalOf(fn () => $this->addScreenshot()->targetOf(['key' => 'SHOP-1', 'upload_id' => self::READY_UPLOAD]));

        self::assertSame(ToolCallOutcome::NotFound, $refusal->outcome);
        self::assertSame(ToolRefusal::NOT_FOUND, $refusal->getMessage());
        self::assertSame([], $this->uploads->completions);
        $this->assertNothingChanged();
    }

    #[Test]
    public function aPictureStillProcessingIsANonErrorAnswerAndAddsNothing(): void
    {
        $result = $this->callFor(['key' => 'SHOP-1', 'upload_id' => self::PENDING_UPLOAD, 'title' => 'Cart']);

        self::assertSame(['upload_id' => self::PENDING_UPLOAD, 'status' => AddScreenshot::PROCESSING, 'message' => AddScreenshot::STILL_PROCESSING], $result['structured']);
        self::assertSame([[self::PENDING_UPLOAD, self::OMAR, '2026-11-10 09:00:20']], $this->uploads->completions);
        self::assertSame([], $this->rounds->draftsRead);
        $this->assertNothingChanged();
    }

    #[Test]
    public function aRejectedPictureIsAPlainRefusalAndAddsNothing(): void
    {
        $refusal = self::refusalOf(fn () => $this->callFor(['key' => 'SHOP-1', 'upload_id' => self::REJECTED_UPLOAD]));

        self::assertSame(ToolCallOutcome::Refused, $refusal->outcome);
        self::assertSame(AddScreenshot::PICTURE_REJECTED, $refusal->getMessage());
        $this->assertNothingChanged();
    }

    #[Test]
    public function aReadyPictureIsAddedWithItsTitleSummaryAndPinsInOrder(): void
    {
        $result = $this->callFor([
            'key' => 'SHOP-1',
            'upload_id' => self::READY_UPLOAD,
            'title' => 'Cart',
            'summary' => 'The total leaves out the fee.',
            'pins' => [['x_pct' => 12.5, 'y_pct' => 40, 'note' => 'The total'], ['x_pct' => 0, 'y_pct' => 100, 'note' => '']],
        ]);

        self::assertSame([[self::OMARS_DRAFT, self::OMAR, self::READY_UPLOAD, 'Cart', 'The total leaves out the fee.', [[12.5, 40.0, 'The total'], [0.0, 100.0, '']], '2026-11-10 09:00:20']], $this->draftScreenshots->added);
        $answer = ['screenshot_id' => RecordingDraftScreenshots::NEW_SCREENSHOT, 'round_id' => self::OMARS_DRAFT, 'status' => AddScreenshot::ADDED, 'pin_count' => 2];
        self::assertSame($answer, $result['structured']);
        self::assertSame(json_encode($answer, \JSON_THROW_ON_ERROR), $result['text']);
    }

    #[Test]
    public function aReadyPictureAloneIsAddedWithNoTextOrPins(): void
    {
        $result = $this->callFor(['key' => 'SHOP-1', 'upload_id' => self::READY_UPLOAD, 'pins' => []]);

        self::assertSame([[self::OMARS_DRAFT, self::OMAR, self::READY_UPLOAD, null, null, [], '2026-11-10 09:00:20']], $this->draftScreenshots->added);
        self::assertSame(['screenshot_id' => RecordingDraftScreenshots::NEW_SCREENSHOT, 'round_id' => self::OMARS_DRAFT, 'status' => AddScreenshot::ADDED, 'pin_count' => 0], $result['structured']);
    }

    #[Test]
    public function aSummaryAloneIsSavedWithNoTitle(): void
    {
        $this->callFor(['key' => 'SHOP-1', 'upload_id' => self::READY_UPLOAD, 'summary' => 'The fee.']);

        self::assertSame([[self::OMARS_DRAFT, self::OMAR, self::READY_UPLOAD, null, 'The fee.', [], '2026-11-10 09:00:20']], $this->draftScreenshots->added);
    }

    #[Test]
    public function aPictureReviewFindsInTheDraftAlreadyIsAnsweredAsAddedBeforeWithItsOwnPinCount(): void
    {
        $result = $this->callFor(['key' => 'SHOP-1', 'upload_id' => strtoupper(self::ADDED_UPLOAD), 'title' => 'Another title', 'pins' => [['x_pct' => 1, 'y_pct' => 1, 'note' => 'Another']]]);

        self::assertSame(['screenshot_id' => self::OMARS_ADDED_SHOT, 'round_id' => self::OMARS_DRAFT, 'status' => AddScreenshot::ADDED_BEFORE, 'pin_count' => 2], $result['structured']);
        $this->assertNothingChanged();
    }

    /**
     * @return iterable<string, array{\Throwable, ToolCallOutcome, string}>
     */
    public static function completionRefusals(): iterable
    {
        yield 'no intent of the caller' => [new UploadIntentNotFound('No such upload intent.'), ToolCallOutcome::NotFound, ToolRefusal::NOT_FOUND];
        yield 'the round now refuses the caller' => [new UploadTargetDenied('Denied.'), ToolCallOutcome::NotFound, ToolRefusal::NOT_FOUND];
        yield 'expired unused' => [new UploadRefused('The page was open too long. Try again.'), ToolCallOutcome::Refused, AddScreenshot::UPLOAD_EXPIRED];
    }

    #[Test]
    #[DataProvider('completionRefusals')]
    public function mediasRefusalToCompleteIsTheToolsOwn(\Throwable $mediaRefusal, ToolCallOutcome $outcome, string $message): void
    {
        $this->uploads->add(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR), self::READY_UPLOAD, new UploadTarget('round', self::OMARS_DRAFT), $mediaRefusal);

        $refusal = self::refusalOf(fn () => $this->callFor(['key' => 'SHOP-1', 'upload_id' => self::READY_UPLOAD]));

        self::assertSame($outcome, $refusal->outcome);
        self::assertSame($message, $refusal->getMessage());
        $this->assertNothingChanged();
    }

    /**
     * @return iterable<string, array{RoundRefused, ToolCallOutcome, string}>
     */
    public static function roundRefusals(): iterable
    {
        yield 'the draft is gone or no longer the caller\'s' => [new RoundRefused(RoundRefused::NOT_FOUND), ToolCallOutcome::NotFound, ToolRefusal::NOT_FOUND];
        yield 'shared since it was read' => [RoundRefused::readOnly('This draft round is shared, so it no longer changes.'), ToolCallOutcome::ReadOnly, ToolRefusal::READ_ONLY];
        yield 'a full draft' => [RoundRefused::invalidContent('A round holds at most 50 screenshots.'), ToolCallOutcome::InvalidInput, \sprintf(ToolRefusal::INVALID_INPUT, 'A round holds at most 50 screenshots.')];
        yield 'a title Review refuses' => [RoundRefused::invalidContent('A picture title holds at most 120 characters.'), ToolCallOutcome::InvalidInput, \sprintf(ToolRefusal::INVALID_INPUT, 'A picture title holds at most 120 characters.')];
        yield 'changed meanwhile' => [new RoundRefused(RoundRefused::DRAFT_CHANGED), ToolCallOutcome::Refused, RoundRefused::DRAFT_CHANGED];
        yield 'a picture Review does not take' => [new RoundRefused(RoundRefused::MEDIA_NOT_UPLOADED_BY_YOU), ToolCallOutcome::Refused, RoundRefused::MEDIA_NOT_UPLOADED_BY_YOU];
    }

    #[Test]
    #[DataProvider('roundRefusals')]
    public function reviewsRefusalIsTheToolsOwnByItsKind(RoundRefused $roundRefusal, ToolCallOutcome $outcome, string $message): void
    {
        $this->draftScreenshots->refusal = $roundRefusal;

        $refusal = self::refusalOf(fn () => $this->callFor(['key' => 'SHOP-1', 'upload_id' => self::READY_UPLOAD, 'title' => 'Cart', 'pins' => [['x_pct' => 1, 'y_pct' => 1, 'note' => 'The total']]]));

        self::assertSame($outcome, $refusal->outcome);
        self::assertSame($message, $refusal->getMessage());
        $this->assertNothingChanged();
    }

    #[Test]
    public function aTargetOfAnotherKindIsAFailure(): void
    {
        $this->expectExceptionObject(new \LogicException('add_screenshot runs on the round its targetOf() found.'));

        $this->addScreenshot()->call(['key' => 'SHOP-1', 'upload_id' => self::READY_UPLOAD], new ToolTarget('TICKET_VIEW', new TicketId(self::SHOP_1), 'ticket', self::SHOP_1));
    }

    private function assertNothingChanged(): void
    {
        self::assertSame([], $this->draftScreenshots->added);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{structured: array<mixed>, text: string}
     */
    private function callFor(array $arguments): array
    {
        $tool = $this->addScreenshot();
        $result = $tool->call($arguments, $tool->targetOf($arguments));
        self::assertFalse($result->isError);
        $structured = $result->structuredContent;
        self::assertIsArray($structured);
        $content = $result->content[0] ?? null;
        self::assertInstanceOf(TextContent::class, $content);
        $text = $content->text;
        self::assertIsString($text);

        return ['structured' => $structured, 'text' => $text];
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
     * Through the gate's own validator, on the arguments as a JSON object decodes.
     *
     * @param array<string, mixed> $arguments
     *
     * @return list<array{pointer: string, keyword: string, message: string}>
     */
    private function problemsOf(array $arguments): array
    {
        return new SchemaValidator()->validateAgainstJsonSchema(
            json_decode(json_encode($arguments, \JSON_THROW_ON_ERROR), false, flags: \JSON_THROW_ON_ERROR),
            $this->addScreenshot()->definition()->inputSchema,
        );
    }

    private function addScreenshot(): AddScreenshot
    {
        return new AddScreenshot(
            new class implements CurrentUser {
                public function id(): UserId
                {
                    return new UserId(AddScreenshotTest::OMAR);
                }

                public function idOf(mixed $tokenUser): ?UserId
                {
                    return null;
                }
            },
            // Exact keys only, as the real query matches them.
            new class implements TicketKeyQueries {
                public function keyOf(TicketId $ticketId): ?string
                {
                    throw new \LogicException('add_screenshot never asks for a key.');
                }

                public function idOf(string $key): ?TicketId
                {
                    $ticketId = ['SHOP-1' => AddScreenshotTest::SHOP_1, 'SHOP-2' => AddScreenshotTest::SHOP_2][$key] ?? null;

                    return null === $ticketId ? null : new TicketId($ticketId);
                }
            },
            new TicketVisibilityCheck(
                new class implements CurrentApiToken {
                    public function access(): ApiTokenAccess
                    {
                        return new ApiTokenAccess('019a5f00-0000-7000-8000-00000000e781', new OrganizationId(AddScreenshotTest::ACME_FOODS), ['read', 'rounds:write'], new \DateTimeImmutable('2027-02-08 09:00:00 UTC'));
                    }
                },
                new class implements TicketTurnQueries {
                    public function factsOf(TicketId $ticketId): TicketTurnSnapshot
                    {
                        return new TicketTurnSnapshot($ticketId, '019a5f00-0000-7000-8000-00000000e791', '019a5f00-0000-7000-8000-00000000e792', 'todo', 'todo', null, null, null, [], [], TicketVisibilityFacts::TEAM_ONLY, 'major', null, new UserId(AddScreenshotTest::OMAR));
                    }
                },
                new readonly class($this->isTicketHidden) implements AuthorizationCheckerInterface {
                    public function __construct(private bool $isTicketHidden)
                    {
                    }

                    public function isGranted(mixed $attribute, mixed $subject = null, ?AccessDecision $accessDecision = null): bool
                    {
                        return TicketPermission::VIEW === $attribute && $subject instanceof TicketVisibilityFacts && !$this->isTicketHidden;
                    }
                },
            ),
            $this->uploads,
            $this->rounds,
            $this->draftScreenshots,
            new MockClock(self::NOW),
        );
    }
}
