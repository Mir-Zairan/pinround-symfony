<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\UI\Checklist;

use App\Review\Api\CheckItemView;
use App\Review\Api\ChecklistCommands;
use App\Review\Api\ChecklistPermission;
use App\Review\Api\ChecklistView;
use App\Review\Application\Checklist\CheckItemPicture;
use App\Review\Application\Checklist\RoundChecklist;
use App\Review\UI\Checklist\ChecklistCard;
use App\Review\UI\Checklist\ChecklistCards;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\TicketTurnSnapshot;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\Review\UI\Media\InMemoryTicketTurnQueries;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ChecklistCards on SHOP-1 of Acme Foods, whose checklist round and Mia's findings round each have items: the viewer
 * may mark items fixed but not check them.
 */
final class ChecklistCardsTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000c501';
    private const string TICKET = '0197a3c0-0000-7000-8000-00000000c511';
    private const string BROKEN_TICKET = '0197a3c0-0000-7000-8000-00000000c512';
    private const string CHECKLIST_ROUND = '0197a3c0-0000-7000-8000-00000000c521';
    private const string FINDINGS_ROUND = '0197a3c0-0000-7000-8000-00000000c522';

    /**
     * The voter decides on the item's ticket alone, so each permission is asked once for the ticket, not once per card.
     */
    #[Test]
    public function bothPermissionsAreDecidedOnceForEveryCardOfTheTicket(): void
    {
        $authorizationChecker = new CountingAuthorizationChecker([ChecklistPermission::MARK_FIXED]);
        $ticketTurnQueries = new InMemoryTicketTurnQueries([self::ticket()], new TicketId(self::BROKEN_TICKET));
        $cards = new ChecklistCards(new FixedRoundChecklistQueries([
            self::CHECKLIST_ROUND => self::roundChecklistOf(self::CHECKLIST_ROUND, 1, false),
            self::FINDINGS_ROUND => self::roundChecklistOf(self::FINDINGS_ROUND, 2, true),
        ]), $ticketTurnQueries, $authorizationChecker);

        $checklistCards = $cards->forRounds(new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), [self::CHECKLIST_ROUND, self::FINDINGS_ROUND]);

        self::assertSame([self::CHECKLIST_ROUND, self::FINDINGS_ROUND], array_keys($checklistCards));
        self::assertSame(
            [[false, true, true], [true, true, true]],
            array_map(static fn (ChecklistCard $card): array => [$card->isFindings, $card->rows[0]->mayMarkFixed, $card->hasControls], array_values($checklistCards)),
        );
        self::assertSame([ChecklistPermission::MARK_FIXED, ChecklistPermission::CHECK], $authorizationChecker->askedAttributes);
        self::assertSame([self::TICKET], $ticketTurnQueries->askedTicketIds);
        $facts = $authorizationChecker->askedSubjects[0] ?? self::fail('Nothing was asked.');
        self::assertSame([self::TICKET, self::TICKET], [$facts->ticketId->toString(), $facts->ticket?->ticketId->toString()]);
    }

    #[Test]
    public function roundsWithoutItemsAskNothingAndGetNoCard(): void
    {
        $authorizationChecker = new CountingAuthorizationChecker([ChecklistPermission::MARK_FIXED]);
        $ticketTurnQueries = new InMemoryTicketTurnQueries([self::ticket()], new TicketId(self::BROKEN_TICKET));
        $cards = new ChecklistCards(new FixedRoundChecklistQueries([]), $ticketTurnQueries, $authorizationChecker);

        self::assertSame([], $cards->forRounds(new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), [self::FINDINGS_ROUND]));
        self::assertSame([[], []], [$authorizationChecker->askedAttributes, $ticketTurnQueries->askedTicketIds]);
    }

    private static function roundChecklistOf(string $roundId, int $number, bool $isFindings): RoundChecklist
    {
        $itemId = substr($roundId, 0, -1).'f';

        return new RoundChecklist(
            new ChecklistView($roundId, $number, [new CheckItemView($itemId, 1, 'Wrong total.', ChecklistCommands::STATE_OPEN)]),
            $isFindings,
            [$itemId => new CheckItemPicture(0, null)],
        );
    }

    private static function ticket(): TicketTurnSnapshot
    {
        return new TicketTurnSnapshot(
            new TicketId(self::TICKET),
            '0197a3c0-0000-7000-8000-00000000c531',
            '0197a3c0-0000-7000-8000-00000000c532',
            'test',
            'check',
            null,
            null,
            null,
            [],
            [],
            'all',
            'normal',
            null,
            new UserId('0197a3c0-0000-7000-8000-00000000c541'),
            new UserId('0197a3c0-0000-7000-8000-00000000c542'),
        );
    }
}
