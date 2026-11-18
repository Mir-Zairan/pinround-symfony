<?php

declare(strict_types=1);

namespace App\Tests\Unit\AgentApi\Infrastructure\Mcp\Tool;

use App\AgentApi\Infrastructure\Mcp\TicketVisibilityCheck;
use App\AgentApi\Infrastructure\Mcp\Tool\SetVerdict;
use App\AgentApi\Infrastructure\Mcp\ToolCallOutcome;
use App\AgentApi\Infrastructure\Mcp\ToolRefusal;
use App\AgentApi\Infrastructure\Mcp\ToolTarget;
use App\Identity\Api\ApiTokenAccess;
use App\Identity\Api\CurrentApiToken;
use App\Identity\Api\CurrentUser;
use App\Review\Api\CheckItemAccessFacts;
use App\Review\Api\ChecklistPermission;
use App\Review\Api\ChecklistQueries;
use App\Review\Api\ChecklistRefused;
use App\Review\Api\ChecklistView;
use App\Review\Api\RoundAccessFacts;
use App\Review\Api\RoundCommands;
use App\Review\Api\RoundPermission;
use App\Review\Api\RoundRefused;
use App\Review\Api\RoundScreenshotView;
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
 * set_verdict over fakes of Review's and Ticketing's Api: its two input shapes as the gate's SchemaValidator reads
 * them, the target and voter attribute of each, the verdict names it maps, and Review's refusals as its own. Omar owns
 * the unshared draft on SHOP-1; Mia owns another unshared draft and a shared one there. SHOP-2 is a ticket of its own.
 */
final class SetVerdictTest extends TestCase
{
    /** Public, for the anonymous fakes. */
    public const string ACME_FOODS = '019a5f00-0000-7000-8000-00000000e601';
    public const string OMAR = '019a5f00-0000-7000-8000-00000000e621';
    public const string SHOP_1 = '019a5f00-0000-7000-8000-00000000e631';
    public const string SHOP_2 = '019a5f00-0000-7000-8000-00000000e632';
    public const string CHECK_ITEM = '019a5f00-0000-7000-8000-00000000e661';
    public const string OTHER_TICKETS_CHECK_ITEM = '019a5f00-0000-7000-8000-00000000e662';
    public const string CHECKLIST = '019a5f00-0000-7000-8000-00000000e671';
    private const string MIA = '019a5f00-0000-7000-8000-00000000e622';
    private const string OMARS_DRAFT = '019a5f00-0000-7000-8000-00000000e641';
    private const string MIAS_DRAFT = '019a5f00-0000-7000-8000-00000000e642';
    private const string MIAS_SHARED_DRAFT = '019a5f00-0000-7000-8000-00000000e643';
    private const string REPORT = '019a5f00-0000-7000-8000-00000000e644';
    private const string OMARS_SHOT = '019a5f00-0000-7000-8000-00000000e651';
    private const string MIAS_SHOT = '019a5f00-0000-7000-8000-00000000e652';
    private const string MIAS_SHARED_SHOT = '019a5f00-0000-7000-8000-00000000e653';
    private const string REPORT_SHOT = '019a5f00-0000-7000-8000-00000000e654';
    private const string NOW = '2026-11-10 09:00:20 UTC';

    private KnownRounds $rounds;
    private RecordingRoundCommands $roundCommands;
    private RecordingChecklistMarks $checklistMarks;
    private bool $isTicketHidden = false;
    private bool $isTicketUnreadable = false;
    /** @var \ArrayObject<int, TicketVisibilityFacts> the subject of each TicketPermission VIEW asked */
    private \ArrayObject $ticketViewsAsked;

    protected function setUp(): void
    {
        $this->rounds = new KnownRounds(new OrganizationId(self::ACME_FOODS));
        $shop1 = new TicketId(self::SHOP_1);
        $this->rounds->add($shop1, new RoundView(self::REPORT, 0, 'report', new UserId(self::MIA), new \DateTimeImmutable('2026-11-04 10:00:00 UTC'), 'open', [self::screenshot(self::REPORT_SHOT, null)]));
        $this->rounds->add($shop1, new RoundView(self::OMARS_DRAFT, null, RoundView::DRAFT, new UserId(self::OMAR), null, 'open', [self::screenshot(self::OMARS_SHOT, 'Case B: the total')]));
        $this->rounds->add($shop1, new RoundView(self::MIAS_DRAFT, null, RoundView::DRAFT, new UserId(self::MIA), null, 'open', [self::screenshot(self::MIAS_SHOT, null)]));
        $this->rounds->add($shop1, new RoundView(self::MIAS_SHARED_DRAFT, 1, RoundView::DRAFT, new UserId(self::MIA), new \DateTimeImmutable('2026-11-05 10:00:00 UTC'), 'open', [self::screenshot(self::MIAS_SHARED_SHOT, null)]));
        $this->roundCommands = new RecordingRoundCommands();
        $this->checklistMarks = new RecordingChecklistMarks();
        $this->ticketViewsAsked = new \ArrayObject();
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function validArguments(): iterable
    {
        yield 'a verdict' => [['key' => 'SHOP-1', 'screenshot_id' => self::OMARS_SHOT, 'verdict' => 'looks_right']];
        yield 'a verdict and a summary' => [['key' => 'SHOP-1', 'screenshot_id' => self::OMARS_SHOT, 'verdict' => 'not_right_yet', 'summary' => 'The total is wrong.']];
        yield 'an empty summary' => [['key' => 'SHOP-1', 'screenshot_id' => self::OMARS_SHOT, 'verdict' => 'not_right_yet', 'summary' => '']];
        yield 'a checklist item fixed' => [['key' => 'SHOP-1', 'check_item_id' => self::CHECK_ITEM, 'state' => 'fixed']];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[Test]
    #[DataProvider('validArguments')]
    public function eachShapeOnItsOwnPassesTheInputSchema(array $arguments): void
    {
        self::assertSame([], $this->problemsOf($arguments));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidArguments(): iterable
    {
        yield 'both shapes' => [['key' => 'SHOP-1', 'screenshot_id' => self::OMARS_SHOT, 'verdict' => 'looks_right', 'check_item_id' => self::CHECK_ITEM, 'state' => 'fixed']];
        yield 'a screenshot and a check item id' => [['key' => 'SHOP-1', 'screenshot_id' => self::OMARS_SHOT, 'verdict' => 'looks_right', 'check_item_id' => self::CHECK_ITEM]];
        yield 'a check item with a verdict' => [['key' => 'SHOP-1', 'check_item_id' => self::CHECK_ITEM, 'state' => 'fixed', 'verdict' => 'looks_right']];
        yield 'a check item with a summary' => [['key' => 'SHOP-1', 'check_item_id' => self::CHECK_ITEM, 'state' => 'fixed', 'summary' => 'Fixed.']];
        yield 'a screenshot with a state' => [['key' => 'SHOP-1', 'screenshot_id' => self::OMARS_SHOT, 'verdict' => 'looks_right', 'state' => 'fixed']];
        yield 'a screenshot without a verdict' => [['key' => 'SHOP-1', 'screenshot_id' => self::OMARS_SHOT]];
        yield 'a screenshot with a summary only' => [['key' => 'SHOP-1', 'screenshot_id' => self::OMARS_SHOT, 'summary' => 'The total.']];
        yield 'a verdict without a screenshot' => [['key' => 'SHOP-1', 'verdict' => 'looks_right']];
        yield 'a check item without a state' => [['key' => 'SHOP-1', 'check_item_id' => self::CHECK_ITEM]];
        yield 'a state without a check item' => [['key' => 'SHOP-1', 'state' => 'fixed']];
        yield 'the key alone' => [['key' => 'SHOP-1']];
        yield 'no key' => [['screenshot_id' => self::OMARS_SHOT, 'verdict' => 'looks_right']];
        yield 'the stored verdict name' => [['key' => 'SHOP-1', 'screenshot_id' => self::OMARS_SHOT, 'verdict' => RoundCommands::VERDICT_NOT_RIGHT_YET]];
        yield 'the label' => [['key' => 'SHOP-1', 'screenshot_id' => self::OMARS_SHOT, 'verdict' => 'Looks right']];
        yield 'a state other than fixed' => [['key' => 'SHOP-1', 'check_item_id' => self::CHECK_ITEM, 'state' => 'ok']];
        yield 'a summary that is no string' => [['key' => 'SHOP-1', 'screenshot_id' => self::OMARS_SHOT, 'verdict' => 'looks_right', 'summary' => null]];
        yield 'a field of no shape' => [['key' => 'SHOP-1', 'screenshot_id' => self::OMARS_SHOT, 'verdict' => 'looks_right', 'title' => 'Case B']];
        yield 'an id past the cap' => [['key' => 'SHOP-1', 'screenshot_id' => str_repeat('a', 65), 'verdict' => 'looks_right']];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[Test]
    #[DataProvider('invalidArguments')]
    public function aMixOrAnIncompleteShapeFailsTheInputSchema(array $arguments): void
    {
        self::assertNotSame([], $this->problemsOf($arguments));
    }

    #[Test]
    public function itNeedsTheRoundsWriteScopeAndListsTheProductsVerdictLabels(): void
    {
        $tool = $this->setVerdict();

        self::assertSame('rounds:write', $tool->requiredScope());
        self::assertSame(SetVerdict::NAME, $tool->definition()->name);
        $properties = $tool->definition()->inputSchema['properties'] ?? null;
        self::assertIsArray($properties);
        $verdict = $properties['verdict'] ?? null;
        self::assertIsArray($verdict);
        self::assertSame(['looks_right', 'not_right_yet'], $verdict['enum'] ?? null);
    }

    #[Test]
    public function aScreenshotOfTheCallersUnsharedDraftIsGuardedByRoundEditAndAuditedAsTheScreenshot(): void
    {
        $target = $this->setVerdict()->targetOf(['key' => 'SHOP-1', 'screenshot_id' => strtoupper(self::OMARS_SHOT), 'verdict' => 'looks_right']);

        self::assertSame(RoundPermission::EDIT, $target->voterAttribute);
        self::assertSame([SetVerdict::SCREENSHOT_AUDIT_TYPE, self::OMARS_SHOT], [$target->auditType, $target->auditId]);
        self::assertEquals(new RoundAccessFacts(new OrganizationId(self::ACME_FOODS), self::OMARS_DRAFT, new TicketId(self::SHOP_1), new UserId(self::OMAR), true, false), $target->subject);
        self::assertSame([[self::SHOP_1, strtoupper(self::OMARS_SHOT)]], $this->rounds->screenshotsLookedUp);
        self::assertEquals(
            [new TicketVisibilityFacts(new OrganizationId(self::ACME_FOODS), TicketVisibilityFacts::TEAM_ONLY, null, new UserId(self::OMAR))],
            $this->ticketViewsAsked->getArrayCopy(),
            'TicketPermission VIEW is asked on the ticket as Ticketing read it, in the token\'s organization',
        );
    }

    /**
     * @return iterable<string, array{bool, bool}>
     */
    public static function ticketsNotSeen(): iterable
    {
        yield 'a ticket TicketPermission VIEW denies' => [true, false];
        yield 'a ticket Ticketing does not read' => [false, true];
    }

    /**
     * RoundPermission grants an unshared draft to its owner without reading the ticket, so the owner of a draft on a
     * ticket they may no longer see is refused here, before the draft is looked up.
     */
    #[Test]
    #[DataProvider('ticketsNotSeen')]
    public function theOwnersDraftOnATicketTheCallerMayNotSeeIsNotFound(bool $isTicketHidden, bool $isTicketUnreadable): void
    {
        $this->isTicketHidden = $isTicketHidden;
        $this->isTicketUnreadable = $isTicketUnreadable;

        $refusal = self::refusalOf(fn () => $this->setVerdict()->targetOf(['key' => 'SHOP-1', 'screenshot_id' => self::OMARS_SHOT, 'verdict' => 'looks_right']));

        self::assertSame(ToolCallOutcome::NotFound, $refusal->outcome);
        self::assertSame(ToolRefusal::NOT_FOUND, $refusal->getMessage());
        self::assertSame([], $this->rounds->screenshotsLookedUp);
        self::assertSame([], $this->roundCommands->verdictsSet);
    }

    /**
     * Review finds the draft whoever owns it; RoundPermission EDIT, which only its owner is granted, keeps it from the
     * caller, and the gate answers that denial as not found.
     */
    #[Test]
    public function aScreenshotOfAnotherPersonsUnsharedDraftIsLeftToRoundEditOnThatPersonsDraft(): void
    {
        $target = $this->setVerdict()->targetOf(['key' => 'SHOP-1', 'screenshot_id' => self::MIAS_SHOT, 'verdict' => 'looks_right']);

        self::assertSame(RoundPermission::EDIT, $target->voterAttribute);
        self::assertEquals(new RoundAccessFacts(new OrganizationId(self::ACME_FOODS), self::MIAS_DRAFT, new TicketId(self::SHOP_1), new UserId(self::MIA), true, false), $target->subject);
    }

    #[Test]
    public function aScreenshotOfASharedDraftIsGuardedByRoundViewAndIsReadOnlyWithNothingSaved(): void
    {
        $tool = $this->setVerdict();
        $arguments = ['key' => 'SHOP-1', 'screenshot_id' => self::MIAS_SHARED_SHOT, 'verdict' => 'looks_right', 'summary' => 'Fine.'];
        $target = $tool->targetOf($arguments);
        self::assertSame(RoundPermission::VIEW, $target->voterAttribute);

        $refusal = self::refusalOf(static fn () => $tool->call($arguments, $target));

        self::assertSame(ToolCallOutcome::ReadOnly, $refusal->outcome);
        self::assertSame(ToolRefusal::READ_ONLY, $refusal->getMessage());
        self::assertSame([], $this->roundCommands->verdictsSet);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function screenshotsNotFound(): iterable
    {
        yield 'a report screenshot, of no draft' => ['SHOP-1', self::REPORT_SHOT];
        yield 'the draft of another ticket key' => ['SHOP-2', self::OMARS_SHOT];
        yield 'an unknown key' => ['SHOP-9', self::OMARS_SHOT];
        yield 'a lowercase key' => ['shop-1', self::OMARS_SHOT];
        yield 'an unknown screenshot' => ['SHOP-1', '019a5f00-0000-7000-8000-00000000e659'];
        yield 'a malformed screenshot id' => ['SHOP-1', self::OMARS_SHOT."\n"];
    }

    #[Test]
    #[DataProvider('screenshotsNotFound')]
    public function aScreenshotOutsideTheCallersDraftsOfTheTicketIsNotFound(string $key, string $screenshotId): void
    {
        $refusal = self::refusalOf(fn () => $this->setVerdict()->targetOf(['key' => $key, 'screenshot_id' => $screenshotId, 'verdict' => 'looks_right']));

        self::assertSame(ToolCallOutcome::NotFound, $refusal->outcome);
        self::assertSame(ToolRefusal::NOT_FOUND, $refusal->getMessage());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function verdictNames(): iterable
    {
        yield 'looks right' => ['looks_right', RoundCommands::VERDICT_LOOKS_RIGHT];
        yield 'not right yet' => ['not_right_yet', RoundCommands::VERDICT_NOT_RIGHT_YET];
    }

    #[Test]
    #[DataProvider('verdictNames')]
    public function theVerdictNameIsSavedAsTheRoundsOwnAndTheSummaryIsKeptWithoutOne(string $verdict, string $storedVerdict): void
    {
        $result = $this->callFor(['key' => 'SHOP-1', 'screenshot_id' => self::OMARS_SHOT, 'verdict' => $verdict]);

        self::assertSame([[self::OMARS_DRAFT, self::OMAR, self::OMARS_SHOT, $storedVerdict, null]], $this->roundCommands->verdictsSet);
        $answer = ['screenshot_id' => self::OMARS_SHOT, 'verdict' => $verdict, 'verdict_label' => SetVerdict::VERDICT_LABELS[$verdict], 'summary_saved' => false];
        self::assertSame($answer, $result['structured']);
        self::assertSame(json_encode($answer, \JSON_THROW_ON_ERROR), $result['text']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function summaries(): iterable
    {
        yield 'a summary' => ['The total still adds the old delivery fee.'];
        yield 'an empty one, which removes it' => [''];
    }

    #[Test]
    #[DataProvider('summaries')]
    public function aSummaryGoesWithTheVerdictToReviewAsItWasSentInOneCommand(string $summary): void
    {
        $result = $this->callFor(['key' => 'SHOP-1', 'screenshot_id' => self::OMARS_SHOT, 'verdict' => 'not_right_yet', 'summary' => $summary]);

        self::assertSame([[self::OMARS_DRAFT, self::OMAR, self::OMARS_SHOT, RoundCommands::VERDICT_NOT_RIGHT_YET, $summary]], $this->roundCommands->verdictsSet);
        self::assertTrue($result['structured']['summary_saved'] ?? null);
    }

    /**
     * @return iterable<string, array{RoundRefused, ToolCallOutcome, string}>
     */
    public static function roundRefusals(): iterable
    {
        yield 'not found' => [new RoundRefused(RoundRefused::NOT_FOUND), ToolCallOutcome::NotFound, ToolRefusal::NOT_FOUND];
        yield 'no such screenshot' => [new RoundRefused(RoundRefused::SCREENSHOT_NOT_FOUND), ToolCallOutcome::NotFound, ToolRefusal::NOT_FOUND];
        yield 'shared since it was read' => [RoundRefused::readOnly('This draft round is shared, so it no longer changes.'), ToolCallOutcome::ReadOnly, ToolRefusal::READ_ONLY];
        yield 'closed' => [RoundRefused::readOnly('This draft round is closed.'), ToolCallOutcome::ReadOnly, ToolRefusal::READ_ONLY];
        yield 'a summary over its cap' => [RoundRefused::invalidContent('A picture summary holds at most 600 characters.'), ToolCallOutcome::InvalidInput, \sprintf(ToolRefusal::INVALID_INPUT, 'A picture summary holds at most 600 characters.')];
    }

    #[Test]
    #[DataProvider('roundRefusals')]
    public function reviewsRefusalIsTheToolsOwnByItsKind(RoundRefused $roundRefusal, ToolCallOutcome $outcome, string $message): void
    {
        $this->roundCommands->refusal = $roundRefusal;

        $refusal = self::refusalOf(fn () => $this->callFor(['key' => 'SHOP-1', 'screenshot_id' => self::OMARS_SHOT, 'verdict' => 'looks_right', 'summary' => 'Fine.']));

        self::assertSame($outcome, $refusal->outcome);
        self::assertSame($message, $refusal->getMessage());
        self::assertSame([], $this->roundCommands->verdictsSet);
    }

    /**
     * @return iterable<string, array{RoundRefused}>
     */
    public static function refusalsOfNoKind(): iterable
    {
        yield 'changed meanwhile' => [new RoundRefused(RoundRefused::DRAFT_CHANGED, 0, new \RuntimeException('The round changed since it was read.'))];
        // The cause alone says nothing: only the kind Review set does.
        yield 'a domain refusal Review gave no kind' => [new RoundRefused('Only the person who owns a draft round can change it.', 0, new \DomainException('Only the person who owns a draft round can change it.'))];
        yield 'an invalid argument Review gave no kind' => [new RoundRefused('Not a kind.', 0, new \InvalidArgumentException('Not a kind.'))];
    }

    #[Test]
    #[DataProvider('refusalsOfNoKind')]
    public function aRefusalOfNoKindGoesOnAsAFailure(RoundRefused $roundRefusal): void
    {
        $this->roundCommands->refusal = $roundRefusal;

        $this->expectExceptionObject($roundRefusal);

        $this->callFor(['key' => 'SHOP-1', 'screenshot_id' => self::OMARS_SHOT, 'verdict' => 'looks_right']);
    }

    #[Test]
    public function aCheckItemOfTheTicketIsGuardedByMarkFixedAndMarkedFixedByTheCaller(): void
    {
        $tool = $this->setVerdict();
        $arguments = ['key' => 'SHOP-1', 'check_item_id' => strtoupper(self::CHECK_ITEM), 'state' => 'fixed'];
        $target = $tool->targetOf($arguments);

        self::assertSame(ChecklistPermission::MARK_FIXED, $target->voterAttribute);
        self::assertSame([SetVerdict::CHECK_ITEM_AUDIT_TYPE, self::CHECK_ITEM], [$target->auditType, $target->auditId]);
        self::assertSame([], $this->rounds->screenshotsLookedUp, 'a check item needs no round');
        self::assertSame([], $this->ticketViewsAsked->getArrayCopy(), 'ChecklistPermission MARK_FIXED asks the ticket itself');

        $result = $tool->call($arguments, $target);

        self::assertSame([[self::CHECK_ITEM, self::OMAR, '2026-11-10 09:00:20']], $this->checklistMarks->markedFixed);
        self::assertSame(['check_item_id' => self::CHECK_ITEM, 'pin_number' => 3, 'state' => 'fixed'], $result->structuredContent);
        self::assertSame([], $this->roundCommands->verdictsSet);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function checkItemsNotFound(): iterable
    {
        yield 'an item of another ticket' => ['SHOP-1', self::OTHER_TICKETS_CHECK_ITEM];
        yield 'an item under the other ticket\'s key' => ['SHOP-2', self::CHECK_ITEM];
        yield 'an unknown item' => ['SHOP-1', '019a5f00-0000-7000-8000-00000000e669'];
        yield 'an unknown key' => ['SHOP-9', self::CHECK_ITEM];
    }

    #[Test]
    #[DataProvider('checkItemsNotFound')]
    public function aCheckItemOutsideTheTicketsChecklistIsNotFound(string $key, string $itemId): void
    {
        $refusal = self::refusalOf(fn () => $this->setVerdict()->targetOf(['key' => $key, 'check_item_id' => $itemId, 'state' => 'fixed']));

        self::assertSame(ToolCallOutcome::NotFound, $refusal->outcome);
    }

    /**
     * @return iterable<string, array{string, ToolCallOutcome}>
     */
    public static function checklistRefusals(): iterable
    {
        yield 'not found' => [ChecklistRefused::NOT_FOUND, ToolCallOutcome::NotFound];
        yield 'marked fixed already' => ['This check is marked fixed already.', ToolCallOutcome::ReadOnly];
        yield 'confirmed OK' => ['This check is confirmed OK, so it no longer changes.', ToolCallOutcome::ReadOnly];
    }

    #[Test]
    #[DataProvider('checklistRefusals')]
    public function theChecklistsRefusalIsTheToolsOwn(string $reason, ToolCallOutcome $outcome): void
    {
        $this->checklistMarks->refusal = new ChecklistRefused($reason);
        $tool = $this->setVerdict();
        $arguments = ['key' => 'SHOP-1', 'check_item_id' => self::CHECK_ITEM, 'state' => 'fixed'];
        $target = $tool->targetOf($arguments);

        $refusal = self::refusalOf(static fn () => $tool->call($arguments, $target));

        self::assertSame($outcome, $refusal->outcome);
        self::assertSame([], $this->checklistMarks->markedFixed);
    }

    #[Test]
    public function aTargetOfAnotherKindIsAFailure(): void
    {
        $this->expectExceptionObject(new \LogicException('set_verdict ran without a target of its own.'));

        $this->setVerdict()->call(['key' => 'SHOP-1', 'check_item_id' => self::CHECK_ITEM, 'state' => 'fixed'], new ToolTarget('TICKET_VIEW', new TicketId(self::SHOP_1), 'ticket', self::SHOP_1));
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{structured: array<mixed>, text: string}
     */
    private function callFor(array $arguments): array
    {
        $tool = $this->setVerdict();
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
            $this->setVerdict()->definition()->inputSchema,
        );
    }

    private static function screenshot(string $id, ?string $title): RoundScreenshotView
    {
        return new RoundScreenshotView($id, '019a5f00-0000-7000-8000-00000000e6f1', 0, null, null, $title, null, []);
    }

    private function setVerdict(): SetVerdict
    {
        return new SetVerdict(
            new class implements CurrentUser {
                public function id(): UserId
                {
                    return new UserId(SetVerdictTest::OMAR);
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
                    throw new \LogicException('set_verdict never asks for a key.');
                }

                public function idOf(string $key): ?TicketId
                {
                    $ticketId = ['SHOP-1' => SetVerdictTest::SHOP_1, 'SHOP-2' => SetVerdictTest::SHOP_2][$key] ?? null;

                    return null === $ticketId ? null : new TicketId($ticketId);
                }
            },
            $this->ticketVisibilityCheck(),
            $this->rounds,
            $this->roundCommands,
            // Any case of the id, as the real query reads it.
            new class implements ChecklistQueries {
                public function accessFactsOf(string $itemId): ?CheckItemAccessFacts
                {
                    $ticketOfItem = [SetVerdictTest::CHECK_ITEM => SetVerdictTest::SHOP_1, SetVerdictTest::OTHER_TICKETS_CHECK_ITEM => SetVerdictTest::SHOP_2][strtolower($itemId)] ?? null;

                    return null === $ticketOfItem ? null : new CheckItemAccessFacts(new OrganizationId(SetVerdictTest::ACME_FOODS), strtolower($itemId), SetVerdictTest::CHECKLIST, new TicketId($ticketOfItem), 3);
                }

                public function forTicket(TicketId $ticketId): ?ChecklistView
                {
                    throw new \LogicException('set_verdict never reads a checklist.');
                }
            },
            $this->checklistMarks,
            new MockClock(self::NOW),
        );
    }

    private function ticketVisibilityCheck(): TicketVisibilityCheck
    {
        return new TicketVisibilityCheck(
            new class implements CurrentApiToken {
                public function access(): ApiTokenAccess
                {
                    return new ApiTokenAccess('019a5f00-0000-7000-8000-00000000e611', new OrganizationId(SetVerdictTest::ACME_FOODS), ['rounds:write'], new \DateTimeImmutable('2027-01-01 00:00 UTC'));
                }
            },
            new readonly class($this->isTicketUnreadable) implements TicketTurnQueries {
                public function __construct(private bool $isTicketUnreadable)
                {
                }

                public function factsOf(TicketId $ticketId): ?TicketTurnSnapshot
                {
                    if ($this->isTicketUnreadable) {
                        return null;
                    }

                    return new TicketTurnSnapshot($ticketId, '019a5f00-0000-7000-8000-00000000e681', '019a5f00-0000-7000-8000-00000000e682', 'todo', 'todo', null, null, null, [], [], TicketVisibilityFacts::TEAM_ONLY, 'major', null, new UserId(SetVerdictTest::OMAR));
                }
            },
            new readonly class($this->isTicketHidden, $this->ticketViewsAsked) implements AuthorizationCheckerInterface {
                /**
                 * @param \ArrayObject<int, TicketVisibilityFacts> $ticketViewsAsked
                 */
                public function __construct(private bool $isTicketHidden, private \ArrayObject $ticketViewsAsked)
                {
                }

                public function isGranted(mixed $attribute, mixed $subject = null, ?AccessDecision $accessDecision = null): bool
                {
                    if (TicketPermission::VIEW !== $attribute || !$subject instanceof TicketVisibilityFacts) {
                        return false;
                    }
                    $this->ticketViewsAsked[] = $subject;

                    return !$this->isTicketHidden;
                }
            },
        );
    }
}
