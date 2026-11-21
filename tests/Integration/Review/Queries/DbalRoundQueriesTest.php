<?php

declare(strict_types=1);

namespace App\Tests\Integration\Review\Queries;

use App\Review\Api\RoundPinView;
use App\Review\Api\RoundQueries;
use App\Review\Api\RoundScreenshotView;
use App\Review\Api\RoundView;
use App\Review\Api\TicketRounds;
use App\Review\Application\RoundsVisibleTo;
use App\Review\Infrastructure\Doctrine\DbalRoundQueries;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\ReadsSentSql;
use App\Tests\Integration\Review\Doctrine\RoundRows;
use App\Tests\Integration\Ticketing\ShopTeamRows;
use App\Tests\Integration\Ticketing\TicketRows;
use App\Ticketing\Api\TicketVisibility;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DbalRoundQueries with the services the container wires it with, on rows committed once per class: Acme Foods' Shop team (Lena leads it,
 * Omar develops, Mia tests), Sara an admin in no team and Noor a member in no team; Beta Bakery has a ticket of its own
 * and a round on Acme Foods' ticket id. DbalRoundQueries comes from the container as the RoundQueries Api.
 * Rounds, screenshots and pins go in out of their number, position and id order, so only the query's order can put them
 * right. Every time is fixed in March 2027; nothing reads the clock.
 */
final class DbalRoundQueriesTest extends KernelTestCase
{
    use ReadsSentSql;

    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000f5301';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000f5302';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000f5311';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000f5312';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000f5313';
    private const string SARA = '0197a3c0-0000-7000-8000-0000000f5314';
    private const string NOOR = '0197a3c0-0000-7000-8000-0000000f5315';
    private const string BEN = '0197a3c0-0000-7000-8000-0000000f5316';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-0000000f5321';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000f5322';
    private const string BAKERY_PROJECT = '0197a3c0-0000-7000-8000-0000000f5323';
    private const string TICKET = '0197a3c0-0000-7000-8000-0000000f5331';
    private const string TEAM_ONLY_TICKET = '0197a3c0-0000-7000-8000-0000000f5332';
    private const string QUIET_TICKET = '0197a3c0-0000-7000-8000-0000000f5333';
    private const string UNPINNED_TICKET = '0197a3c0-0000-7000-8000-0000000f5334';
    private const string BAKERY_TICKET = '0197a3c0-0000-7000-8000-0000000f5335';
    private const string CAPPED_TICKET = '0197a3c0-0000-7000-8000-0000000f5336';
    private const string EXACT_TICKET = '0197a3c0-0000-7000-8000-0000000f5337';
    private const string ROW_ROUNDS_TICKET = '0197a3c0-0000-7000-8000-0000000f5338';
    private const string UNKNOWN_TICKET = '0197a3c0-0000-7000-8000-0000000f5339';
    private const string ROUND_TWO = '0197a3c0-0000-7000-8000-0000000f5341';
    private const string OMAR_DRAFT = '0197a3c0-0000-7000-8000-0000000f5342';
    private const string OMAR_SHARED_DRAFT = '0197a3c0-0000-7000-8000-0000000f5343';
    private const string LENA_DRAFT = '0197a3c0-0000-7000-8000-0000000f5344';
    private const string REPORT = '0197a3c0-0000-7000-8000-0000000f5345';
    private const string ROUND_ONE = '0197a3c0-0000-7000-8000-0000000f5346';
    private const string TEAM_ONLY_REPORT = '0197a3c0-0000-7000-8000-0000000f5347';
    private const string QUIET_ROUND_ONE = '0197a3c0-0000-7000-8000-0000000f5348';
    private const string QUIET_OMAR_DRAFT = '0197a3c0-0000-7000-8000-0000000f5349';
    private const string UNPINNED_REPORT = '0197a3c0-0000-7000-8000-0000000f534a';
    private const string BAKERY_REPORT = '0197a3c0-0000-7000-8000-0000000f534b';
    private const string BAKERY_ROUND_ON_ACME_TICKET = '0197a3c0-0000-7000-8000-0000000f534c';
    private const string OMAR_SECOND_DRAFT = '0197a3c0-0000-7000-8000-0000000f5340';
    private const string REPORT_SECOND_SCREENSHOT = '0197a3c0-0000-7000-8000-0000000f5351';
    private const string REPORT_FIRST_SCREENSHOT = '0197a3c0-0000-7000-8000-0000000f5352';
    private const string ROUND_TWO_AFTER = '0197a3c0-0000-7000-8000-0000000f5353';
    private const string ROUND_TWO_BEFORE = '0197a3c0-0000-7000-8000-0000000f5354';
    private const string OMAR_DRAFT_SCREENSHOT = '0197a3c0-0000-7000-8000-0000000f5355';
    private const string TEAM_ONLY_SCREENSHOT = '0197a3c0-0000-7000-8000-0000000f5356';
    private const string UNPINNED_SCREENSHOT = '0197a3c0-0000-7000-8000-0000000f5357';
    private const string BAKERY_SCREENSHOT = '0197a3c0-0000-7000-8000-0000000f5358';
    private const string BAKERY_SCREENSHOT_ON_ACME_TICKET = '0197a3c0-0000-7000-8000-0000000f5359';
    private const string FIRST_PIN_THREE = '0197a3c0-0000-7000-8000-0000000f5361';
    private const string FIRST_PIN_ONE = '0197a3c0-0000-7000-8000-0000000f5362';
    private const string SECOND_PIN_ONE = '0197a3c0-0000-7000-8000-0000000f5363';
    private const string ROUND_TWO_BEFORE_PIN = '0197a3c0-0000-7000-8000-0000000f5364';
    private const string OMAR_DRAFT_PIN = '0197a3c0-0000-7000-8000-0000000f5365';
    private const string TEAM_ONLY_PIN = '0197a3c0-0000-7000-8000-0000000f5366';
    private const string BAKERY_PIN = '0197a3c0-0000-7000-8000-0000000f5367';
    private const string BAKERY_PIN_ON_ACME_TICKET = '0197a3c0-0000-7000-8000-0000000f5368';
    private const string FIRST_MEDIA = '0197a3c0-0000-7000-8000-0000000f5371';
    private const string SECOND_MEDIA = '0197a3c0-0000-7000-8000-0000000f5372';
    private const string CAPPED_REPORT = '0197a3c0-0000-7000-8000-0000000f5420';
    private const string CAPPED_OMAR_DRAFT = '0197a3c0-0000-7000-8000-0000000f5421';
    private const string CAPPED_LENA_DRAFT = '0197a3c0-0000-7000-8000-0000000f5422';
    private const string BAKERY_ROUND_ON_CAPPED_TICKET = '0197a3c0-0000-7000-8000-0000000f5423';
    private const string ROW_CHECKLIST = '0197a3c0-0000-7000-8000-0000000f5424';
    private const string ROW_REVIEW = '0197a3c0-0000-7000-8000-0000000f5425';
    private const string ROW_CHECKLIST_SCREENSHOT = '0197a3c0-0000-7000-8000-0000000f5426';
    private const string ROW_REVIEW_SCREENSHOT = '0197a3c0-0000-7000-8000-0000000f5427';
    private const string ROW_REVIEW_PIN = '0197a3c0-0000-7000-8000-0000000f5428';
    private const int CAPPED_ROUND_COUNT = 25;
    private const string REPORTED_AT = '2027-03-15 10:20:30+00';
    private const string ROUND_ONE_SHARED_AT = '2027-03-16 09:00:00+00';
    private const string ROUND_TWO_SHARED_AT = '2027-03-17 09:00:00+00';
    private const string DRAFT_SHARED_AT = '2027-03-18 09:00:00+00';

    /** Every viewer's user id and organization. */
    private const array VIEWERS = [
        'Lena' => [self::LENA, self::ACME_FOODS],
        'Omar' => [self::OMAR, self::ACME_FOODS],
        'Mia' => [self::MIA, self::ACME_FOODS],
        'Sara' => [self::SARA, self::ACME_FOODS],
        'Noor' => [self::NOOR, self::ACME_FOODS],
        'Ben' => [self::BEN, self::BETA_BAKERY],
    ];

    public static function setUpBeforeClass(): void
    {
        self::removeRows();
        self::shopTeamRows()->add(self::ACME_FOODS, self::SHOP_TEAM, self::SHOP_PROJECT, self::LENA, [
            self::LENA => ['Lena', 'lead', 'developer'],
            self::OMAR => ['Omar', 'member', 'developer'],
            self::MIA => ['Mia', 'member', 'tester'],
        ]);
        foreach ([[self::SARA, 'Sara', '{admin}'], [self::NOOR, 'Noor', '{}']] as [$userId, $name, $orgRoles]) {
            self::shopTeamRows()->addUser($userId, $name);
            self::transactions()->committedAs(self::ACME_FOODS, static fn (): int|string => self::connection()->executeStatement(
                "INSERT INTO org_membership (organization_id, user_id, org_roles, joined_at) VALUES (?, ?, ?, '2027-03-15 10:00:00+00')",
                [self::ACME_FOODS, $userId, $orgRoles],
            ));
        }
        $ticketRows = new TicketRows(self::migrator(), self::tenantContext());
        $ticketRows->addWithAudienceOf(self::ACME_FOODS, self::TICKET, self::SHOP_PROJECT, 1, 'all', self::SHOP_TEAM, self::MIA);
        $ticketRows->addWithAudienceOf(self::ACME_FOODS, self::TEAM_ONLY_TICKET, self::SHOP_PROJECT, 2, 'team', self::SHOP_TEAM, self::LENA);
        $ticketRows->addWithAudienceOf(self::ACME_FOODS, self::QUIET_TICKET, self::SHOP_PROJECT, 3, 'all', self::SHOP_TEAM, self::OMAR);
        $ticketRows->addWithAudienceOf(self::ACME_FOODS, self::UNPINNED_TICKET, self::SHOP_PROJECT, 4, 'all', self::SHOP_TEAM, self::MIA);
        $ticketRows->addOf(self::BETA_BAKERY, self::BAKERY_TICKET, self::BAKERY_PROJECT, self::BEN);
        $ticketRows->addWithAudienceOf(self::ACME_FOODS, self::CAPPED_TICKET, self::SHOP_PROJECT, 5, 'all', self::SHOP_TEAM, self::MIA);
        $ticketRows->addWithAudienceOf(self::ACME_FOODS, self::EXACT_TICKET, self::SHOP_PROJECT, 6, 'all', self::SHOP_TEAM, self::MIA);

        self::addRoundRows(self::ACME_FOODS, [
            [self::ROUND_TWO, self::TICKET, 2, 'review', self::LENA, self::ROUND_TWO_SHARED_AT, 'open'],
            [self::OMAR_DRAFT, self::TICKET, null, 'draft', self::OMAR, null, 'open'],
            [self::OMAR_SHARED_DRAFT, self::TICKET, 3, 'draft', self::OMAR, self::DRAFT_SHARED_AT, 'open'],
            [self::LENA_DRAFT, self::TICKET, null, 'draft', self::LENA, null, 'open'],
            [self::REPORT, self::TICKET, 0, 'report', self::MIA, self::REPORTED_AT, 'open'],
            [self::ROUND_ONE, self::TICKET, 1, 'checklist', self::OMAR, self::ROUND_ONE_SHARED_AT, 'closed'],
            [self::TEAM_ONLY_REPORT, self::TEAM_ONLY_TICKET, 0, 'report', self::LENA, self::REPORTED_AT, 'open'],
            [self::QUIET_ROUND_ONE, self::QUIET_TICKET, 1, 'draft', self::OMAR, self::ROUND_ONE_SHARED_AT, 'open'],
            [self::QUIET_OMAR_DRAFT, self::QUIET_TICKET, null, 'draft', self::OMAR, null, 'open'],
            [self::UNPINNED_REPORT, self::UNPINNED_TICKET, 0, 'report', self::MIA, self::REPORTED_AT, 'open'],
        ], [
            [self::REPORT_SECOND_SCREENSHOT, self::REPORT, self::SECOND_MEDIA, 1, null, 'not_right', null, 'The button sits under the footer.'],
            [self::REPORT_FIRST_SCREENSHOT, self::REPORT, self::FIRST_MEDIA, 0, null, 'looks_right', 'Checkout — the total', null],
            [self::ROUND_TWO_AFTER, self::ROUND_TWO, self::SECOND_MEDIA, 1, 'after', null, null, null],
            [self::ROUND_TWO_BEFORE, self::ROUND_TWO, self::FIRST_MEDIA, 0, 'before', null, null, null],
            [self::OMAR_DRAFT_SCREENSHOT, self::OMAR_DRAFT, self::FIRST_MEDIA, 0, null, null, null, null],
            [self::TEAM_ONLY_SCREENSHOT, self::TEAM_ONLY_REPORT, self::FIRST_MEDIA, 0, null, null, null, null],
            [self::UNPINNED_SCREENSHOT, self::UNPINNED_REPORT, self::FIRST_MEDIA, 0, null, null, null, null],
        ], [
            [self::FIRST_PIN_THREE, self::REPORT_FIRST_SCREENSHOT, 3, '12.35', '7.10', 'The total is off', null, 'human', null, self::LENA],
            [self::FIRST_PIN_ONE, self::REPORT_FIRST_SCREENSHOT, 1, '0.00', '100.00', 'Prix affiché en €', 'still_wrong', 'human', null, self::MIA],
            [self::SECOND_PIN_ONE, self::REPORT_SECOND_SCREENSHOT, 1, '99.99', '50.00', 'Hidden here', 'fixed', 'ai', 'kept', self::MIA],
            [self::ROUND_TWO_BEFORE_PIN, self::ROUND_TWO_BEFORE, 1, '40.00', '60.00', 'Was cut off', null, 'human', null, self::LENA],
            [self::OMAR_DRAFT_PIN, self::OMAR_DRAFT_SCREENSHOT, 1, '40.00', '60.00', 'Mine only', null, 'human', null, self::OMAR],
            [self::TEAM_ONLY_PIN, self::TEAM_ONLY_SCREENSHOT, 1, '40.00', '60.00', 'Team only', null, 'human', null, self::LENA],
        ]);
        // 25 numbered rounds on one ticket and 20 on another, numbers in a shuffled order (7 and 25 share no factor).
        $cappedRounds = [
            [self::CAPPED_REPORT, self::CAPPED_TICKET, 0, 'report', self::MIA, self::REPORTED_AT, 'open'],
            [self::CAPPED_OMAR_DRAFT, self::CAPPED_TICKET, null, 'draft', self::OMAR, null, 'open'],
            [self::CAPPED_LENA_DRAFT, self::CAPPED_TICKET, null, 'draft', self::LENA, null, 'open'],
        ];
        for ($step = 1; $step <= self::CAPPED_ROUND_COUNT; ++$step) {
            $number = ($step * 7) % self::CAPPED_ROUND_COUNT + 1;
            $cappedRounds[] = [self::cappedRoundId($number), self::CAPPED_TICKET, $number, 'review', self::LENA, self::ROUND_ONE_SHARED_AT, 'open'];
        }
        $exactRounds = [];
        for ($step = 1; $step <= RoundQueries::MAX_NUMBERED_ROUNDS; ++$step) {
            $number = ($step * 7) % RoundQueries::MAX_NUMBERED_ROUNDS + 1;
            $exactRounds[] = [self::exactRoundId($number), self::EXACT_TICKET, $number, 'review', self::LENA, self::ROUND_ONE_SHARED_AT, 'open'];
        }
        $ticketRows->addWithAudienceOf(self::ACME_FOODS, self::ROW_ROUNDS_TICKET, self::SHOP_PROJECT, 7, 'all', self::SHOP_TEAM, self::MIA);
        self::addRoundRows(self::ACME_FOODS, [
            ...$cappedRounds,
            ...$exactRounds,
            [self::ROW_CHECKLIST, self::ROW_ROUNDS_TICKET, 1, 'checklist', self::OMAR, self::ROUND_ONE_SHARED_AT, 'open'],
            [self::ROW_REVIEW, self::ROW_ROUNDS_TICKET, 2, 'review', self::LENA, self::ROUND_TWO_SHARED_AT, 'open'],
        ], [
            [self::ROW_CHECKLIST_SCREENSHOT, self::ROW_CHECKLIST, self::FIRST_MEDIA, 0, null, null, null, null],
            [self::ROW_REVIEW_SCREENSHOT, self::ROW_REVIEW, self::FIRST_MEDIA, 0, null, null, null, null],
        ], [
            [self::ROW_REVIEW_PIN, self::ROW_REVIEW_SCREENSHOT, 1, '40.00', '60.00', 'Not loaded', null, 'human', null, self::LENA],
        ]);
        // rvw_round.ticket_id has no foreign key, so Beta Bakery may hold a shared round on Acme Foods' ticket id.
        self::addRoundRows(self::BETA_BAKERY, [
            [self::BAKERY_REPORT, self::BAKERY_TICKET, 0, 'report', self::BEN, self::REPORTED_AT, 'open'],
            [self::BAKERY_ROUND_ON_ACME_TICKET, self::TICKET, 4, 'review', self::BEN, self::ROUND_ONE_SHARED_AT, 'open'],
            [self::BAKERY_ROUND_ON_CAPPED_TICKET, self::CAPPED_TICKET, 26, 'review', self::BEN, self::ROUND_ONE_SHARED_AT, 'open'],
        ], [
            [self::BAKERY_SCREENSHOT, self::BAKERY_REPORT, self::FIRST_MEDIA, 0, null, null, null, null],
            [self::BAKERY_SCREENSHOT_ON_ACME_TICKET, self::BAKERY_ROUND_ON_ACME_TICKET, self::FIRST_MEDIA, 0, null, null, null, null],
        ], [
            [self::BAKERY_PIN, self::BAKERY_SCREENSHOT, 1, '40.00', '60.00', 'Bakery', null, 'human', null, self::BEN],
            [self::BAKERY_PIN_ON_ACME_TICKET, self::BAKERY_SCREENSHOT_ON_ACME_TICKET, 1, '40.00', '60.00', 'Bakery on Acme', null, 'human', null, self::BEN],
        ]);
        self::ensureKernelShutdown();
    }

    public static function tearDownAfterClass(): void
    {
        self::removeRows();
        self::ensureKernelShutdown();
        parent::tearDownAfterClass();
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        self::tenantContext()->reset();
        parent::tearDown();
    }

    #[Test]
    public function roundsComeByNumberThenTheViewersOwnUnsharedDraftWithScreenshotsByPositionAndPinsByNumber(): void
    {
        $rounds = $this->roundsFor(self::TICKET, 'Omar');

        $sharedAt = static fn (string $time): \DateTimeImmutable => new \DateTimeImmutable($time);
        self::assertEquals([
            new RoundView(self::REPORT, 0, 'report', new UserId(self::MIA), $sharedAt(self::REPORTED_AT), 'open', [
                new RoundScreenshotView(self::REPORT_FIRST_SCREENSHOT, self::FIRST_MEDIA, 0, null, 'looks_right', 'Checkout — the total', null, [
                    new RoundPinView(self::FIRST_PIN_ONE, 1, 0.0, 100.0, 'Prix affiché en €', 'still_wrong', 'human', null, new UserId(self::MIA), null),
                    new RoundPinView(self::FIRST_PIN_THREE, 3, 12.35, 7.1, 'The total is off', null, 'human', null, new UserId(self::LENA), null),
                ]),
                new RoundScreenshotView(self::REPORT_SECOND_SCREENSHOT, self::SECOND_MEDIA, 1, null, 'not_right', null, 'The button sits under the footer.', [
                    new RoundPinView(self::SECOND_PIN_ONE, 1, 99.99, 50.0, 'Hidden here', 'fixed', 'ai', 'kept', new UserId(self::MIA), null),
                ]),
            ], 2),
            new RoundView(self::ROUND_ONE, 1, 'checklist', new UserId(self::OMAR), $sharedAt(self::ROUND_ONE_SHARED_AT), 'closed', [], 0),
            new RoundView(self::ROUND_TWO, 2, 'review', new UserId(self::LENA), $sharedAt(self::ROUND_TWO_SHARED_AT), 'open', [], 2),
            new RoundView(self::OMAR_SHARED_DRAFT, 3, 'draft', new UserId(self::OMAR), $sharedAt(self::DRAFT_SHARED_AT), 'open', [], 0),
            new RoundView(self::OMAR_DRAFT, null, 'draft', new UserId(self::OMAR), null, 'open', [
                new RoundScreenshotView(self::OMAR_DRAFT_SCREENSHOT, self::FIRST_MEDIA, 0, null, null, null, null, [
                    new RoundPinView(self::OMAR_DRAFT_PIN, 1, 40.0, 60.0, 'Mine only', null, 'human', null, new UserId(self::OMAR), null),
                ]),
            ], 1),
        ], $rounds);
        self::assertTrue($rounds[4]->isUnsharedDraft());
        self::assertFalse($rounds[3]->isUnsharedDraft());
    }

    /**
     * 25 numbered rounds, Round 0, Omar's draft and Lena's draft, and a Beta Bakery round numbered 26 on the same ticket
     * id: Omar gets Round 0, the newest 20 numbered rounds (6 to 25, the 5 oldest left out, the other organization's 26
     * not counted) and his own draft, and the cap does not take Round 0 or the draft from the 20.
     */
    #[Test]
    public function aTicketWithMoreRoundsThanTheCapLoadsRoundZeroTheNewestTwentyAndTheViewersDraftAndCountsTheRest(): void
    {
        $ticketRounds = $this->ticketRoundsFor(self::CAPPED_TICKET, 'Omar');

        self::assertSame(
            [self::CAPPED_REPORT, ...array_map(self::cappedRoundId(...), range(6, 25)), self::CAPPED_OMAR_DRAFT],
            self::idsOf($ticketRounds->rounds),
        );
        self::assertSame(5, $ticketRounds->olderRoundsNotShown);
        self::assertSame(RoundQueries::MAX_NUMBERED_ROUNDS + 2, \count($ticketRounds->rounds));
    }

    #[Test]
    public function aTicketWithExactlyTheCapOfNumberedRoundsLeavesNoneOut(): void
    {
        $ticketRounds = $this->ticketRoundsFor(self::EXACT_TICKET, 'Omar');

        self::assertSame(array_map(self::exactRoundId(...), range(1, 20)), self::idsOf($ticketRounds->rounds));
        self::assertSame(0, $ticketRounds->olderRoundsNotShown);
    }

    /**
     * The ticket with five rounds has no numbered round past the cap, so nothing is counted as left out.
     */
    #[Test]
    public function aTicketUnderTheCapCountsNoRoundAsLeftOut(): void
    {
        self::assertSame(0, $this->ticketRoundsFor(self::TICKET, 'Omar')->olderRoundsNotShown);
    }

    /**
     * Omar's unshared draft is left out for the lead of the ticket's team, an admin and the team's tester alike; the
     * lead gets her own draft last instead, and everyone gets Omar's shared one.
     *
     * @param list<string> $expectedRoundIds
     */
    #[Test]
    #[DataProvider('viewersOfAnotherPersonsDraft')]
    public function anotherPersonsUnsharedDraftIsLeftOutForEveryone(string $viewer, array $expectedRoundIds): void
    {
        $roundIds = self::idsOf($this->roundsFor(self::TICKET, $viewer));

        self::assertNotSame([], $roundIds);
        self::assertNotContains(self::OMAR_DRAFT, $roundIds, \sprintf('%s is shown Omar\'s unshared draft.', $viewer));
        self::assertContains(self::OMAR_SHARED_DRAFT, $roundIds);
        self::assertSame($expectedRoundIds, $roundIds);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function viewersOfAnotherPersonsDraft(): iterable
    {
        $sharedRounds = [self::REPORT, self::ROUND_ONE, self::ROUND_TWO, self::OMAR_SHARED_DRAFT];

        yield 'Lena, the lead of the ticket\'s team, with a draft of her own' => ['Lena', [...$sharedRounds, self::LENA_DRAFT]];
        yield 'Sara, an admin' => ['Sara', $sharedRounds];
        yield 'Mia, the team\'s tester' => ['Mia', $sharedRounds];
    }

    /**
     * ADR-0044 lets someone other than the assignee own a draft, as the reviewer or tester does in their turn. Lena's
     * unshared draft is such a draft: a developer of the team, an admin and the tester never get it.
     */
    #[Test]
    #[DataProvider('viewersOfANonAssigneesDraft')]
    public function aDraftOfSomeoneOtherThanTheAssigneeIsLeftOutForEveryoneElse(string $viewer): void
    {
        $roundIds = self::idsOf($this->roundsFor(self::TICKET, $viewer));

        self::assertNotSame([], $roundIds);
        self::assertContains(self::OMAR_SHARED_DRAFT, $roundIds);
        self::assertNotContains(self::LENA_DRAFT, $roundIds, \sprintf('%s is shown Lena\'s unshared draft.', $viewer));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function viewersOfANonAssigneesDraft(): iterable
    {
        yield 'Omar, a developer of the team' => ['Omar'];
        yield 'Sara, an admin' => ['Sara'];
        yield 'Mia, the team\'s tester' => ['Mia'];
    }

    /**
     * Four queries besides the viewer's roles for a ticket with several rounds, screenshots and pins: the visibility
     * check, then rounds, screenshots and pins, one each.
     */
    #[Test]
    public function aTicketWithSeveralRoundsScreenshotsAndPinsCostsFourQueriesBesidesTheRoles(): void
    {
        [$rounds, $tables] = $this->roundsAndQueriedTablesFor(self::TICKET, 'Omar');

        self::assertCount(5, $rounds);
        self::assertSame(['tkt_ticket', 'rvw_round', 'rvw_screenshot', 'rvw_pin'], $tables);
    }

    #[Test]
    public function roundsWithNoScreenshotsHaveNoneAndCostNoScreenshotOrPinQuery(): void
    {
        [$rounds, $tables] = $this->roundsAndQueriedTablesFor(self::QUIET_TICKET, 'Omar');

        self::assertSame([[self::QUIET_ROUND_ONE, []], [self::QUIET_OMAR_DRAFT, []]], array_map(static fn (RoundView $round): array => [$round->id, $round->screenshots], $rounds));
        self::assertSame(['tkt_ticket', 'rvw_round'], $tables);
    }

    /**
     * A checklist and a review round that hold a screenshot and a pin each are rows on the page: they come with none,
     * and no screenshot or pin query is sent for them.
     */
    #[Test]
    public function aChecklistOrReviewRoundIsLoadedWithNoScreenshotOrPinAndCostsNoQueryForThem(): void
    {
        [$rounds, $tables] = $this->roundsAndQueriedTablesFor(self::ROW_ROUNDS_TICKET, 'Omar');

        self::assertSame([self::ROW_CHECKLIST, self::ROW_REVIEW], self::idsOf($rounds));
        self::assertSame([[], []], array_map(static fn (RoundView $round): array => $round->screenshots, $rounds));
        self::assertSame(['tkt_ticket', 'rvw_round'], $tables);
    }

    #[Test]
    public function screenshotsWithNoPinsHaveNoneAndCostNoPinQuery(): void
    {
        [$rounds, $tables] = $this->roundsAndQueriedTablesFor(self::UNPINNED_TICKET, 'Omar');

        self::assertSame([self::UNPINNED_REPORT], self::idsOf($rounds));
        self::assertSame([[self::UNPINNED_SCREENSHOT, []]], array_map(static fn (RoundScreenshotView $screenshot): array => [$screenshot->id, $screenshot->pins], $rounds[0]->screenshots));
        self::assertSame(['tkt_ticket', 'rvw_round', 'rvw_screenshot'], $tables);
    }

    /**
     * Each of these tickets has a round its own viewers get (Lena the team-only one, Ben the bakery's), so the empty
     * answer comes from the visibility check, which then stops before any round query.
     */
    #[Test]
    #[DataProvider('ticketsTheViewerMayNotSee')]
    public function aTicketTheViewerMayNotSeeHasNoRoundsAndCostsNoRoundQuery(string $ticketId, string $viewer, string $viewerWhoSeesIt, string $roundTheySee): void
    {
        self::assertSame([$roundTheySee], self::idsOf($this->roundsFor($ticketId, $viewerWhoSeesIt)));

        [$rounds, $tables] = $this->roundsAndQueriedTablesFor($ticketId, $viewer);

        self::assertSame([], $rounds);
        self::assertSame(['tkt_ticket'], $tables);
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function ticketsTheViewerMayNotSee(): iterable
    {
        yield 'a team-only ticket, to a member outside the team' => [self::TEAM_ONLY_TICKET, 'Noor', 'Lena', self::TEAM_ONLY_REPORT];
        yield 'another organization\'s ticket, to its lead' => [self::BAKERY_TICKET, 'Lena', 'Ben', self::BAKERY_REPORT];
        yield 'another organization\'s ticket, to an admin' => [self::BAKERY_TICKET, 'Sara', 'Ben', self::BAKERY_REPORT];
    }

    /**
     * Beta Bakery's shared round on Acme Foods' ticket id, with a screenshot and a pin, passes the visibility check,
     * which reads Acme's ticket; only the organization of the round, screenshot and pin queries keeps it out.
     */
    #[Test]
    public function anotherOrganizationsRoundOnTheSameTicketIdIsLeftOut(): void
    {
        $roundIds = self::idsOf($this->roundsFor(self::TICKET, 'Omar'));

        self::assertNotContains(self::BAKERY_ROUND_ON_ACME_TICKET, $roundIds);
        self::assertSame([self::REPORT, self::ROUND_ONE, self::ROUND_TWO, self::OMAR_SHARED_DRAFT, self::OMAR_DRAFT], $roundIds);
    }

    /**
     * The round, screenshot and pin queries of DbalRoundQueries with their organization condition taken out, run as
     * pinround_app: under Acme Foods as tenant, row-level security alone hides Beta Bakery's rows on Acme's ticket id,
     * which Beta's own tenant gets.
     */
    #[Test]
    public function rowLevelSecurityAloneHidesAnotherOrganizationsRoundScreenshotAndPinOnTheTicketId(): void
    {
        $rowIdsAs = static fn (string $organizationId): array => self::transactions()->rolledBackAs($organizationId, static fn (): array => [
            self::connection()->fetchOne('SELECT current_user'),
            self::connection()->fetchFirstColumn(
                'SELECT round.id FROM rvw_round AS round WHERE round.ticket_id = CAST(:ticket AS uuid)'
                .' AND (round.shared_at IS NOT NULL OR round.owner_id = CAST(:viewer AS uuid)) ORDER BY round.number NULLS LAST, round.id',
                ['ticket' => self::TICKET, 'viewer' => self::OMAR],
            ),
            self::connection()->fetchFirstColumn(
                'SELECT screenshot.id FROM rvw_screenshot AS screenshot WHERE screenshot.round_id = ANY(CAST(:rounds AS uuid[])) ORDER BY screenshot.position, screenshot.id',
                ['rounds' => '{'.self::BAKERY_ROUND_ON_ACME_TICKET.'}'],
            ),
            self::connection()->fetchFirstColumn(
                'SELECT pin.id FROM rvw_pin AS pin WHERE pin.screenshot_id = ANY(CAST(:screenshots AS uuid[])) ORDER BY pin.number, pin.id',
                ['screenshots' => '{'.self::BAKERY_SCREENSHOT_ON_ACME_TICKET.'}'],
            ),
        ]);

        self::assertSame(
            ['pinround_app', [self::REPORT, self::ROUND_ONE, self::ROUND_TWO, self::OMAR_SHARED_DRAFT, self::OMAR_DRAFT], [], []],
            $rowIdsAs(self::ACME_FOODS),
        );
        self::assertSame(
            ['pinround_app', [self::BAKERY_ROUND_ON_ACME_TICKET], [self::BAKERY_SCREENSHOT_ON_ACME_TICKET], [self::BAKERY_PIN_ON_ACME_TICKET]],
            $rowIdsAs(self::BETA_BAKERY),
        );
    }

    /**
     * A deleted draft frees its owner's draft slot (Version20261116100000), so Omar may hold one beside his open
     * draft; it is left out of his rounds, and his open draft is still listed.
     */
    #[Test]
    public function theViewersDeletedDraftIsLeftOut(): void
    {
        $roundIds = self::transactions()->rolledBackAs(self::ACME_FOODS, function (): array {
            self::connection()->executeStatement(
                "INSERT INTO rvw_round (organization_id, id, ticket_id, number, kind, owner_id, shared_at, state, version, created_at, deleted_at) VALUES (?, ?, ?, NULL, 'draft', ?, NULL, 'closed', 2, '2027-03-15 10:00:00+00', '2027-03-15 11:00:00+00')",
                [self::ACME_FOODS, self::OMAR_SECOND_DRAFT, self::TICKET, self::OMAR],
            );

            return self::idsOf($this->roundQueries()->forTicket(new TicketId(self::TICKET), new UserId(self::OMAR))->rounds);
        });

        self::assertSame([self::REPORT, self::ROUND_ONE, self::ROUND_TWO, self::OMAR_SHARED_DRAFT, self::OMAR_DRAFT], $roundIds);
    }

    /**
     * sharedRoundOf reads one round by its number for the round page: the viewer who may see it gets it, and each
     * refusal is null with nothing of the round.
     */
    #[Test]
    public function sharedRoundOfGivesTheNumberedRoundToAViewerWhoMaySeeIt(): void
    {
        self::assertSame(self::OMAR_SHARED_DRAFT, $this->sharedRoundFor(self::TICKET, 'Lena', 3)?->id);
        self::assertSame(self::TEAM_ONLY_REPORT, $this->sharedRoundFor(self::TEAM_ONLY_TICKET, 'Lena', 0)?->id);
        self::assertSame(self::BAKERY_REPORT, $this->sharedRoundFor(self::BAKERY_TICKET, 'Ben', 0)?->id);
    }

    /**
     * @return iterable<string, array{string, string, int}> ticket, viewer, number
     */
    public static function sharedRoundsRefused(): iterable
    {
        yield 'a negative number' => [self::TICKET, 'Lena', -1];
        yield 'a team-only ticket, to a member outside the team' => [self::TEAM_ONLY_TICKET, 'Noor', 0];
        yield 'another organization\'s round on the ticket id, to Acme Foods\' lead' => [self::TICKET, 'Lena', 4];
        yield 'Acme Foods\' ticket, to another organization\'s lead' => [self::TICKET, 'Ben', 4];
        yield 'a number only an unshared draft could have' => [self::TICKET, 'Omar', 5];
    }

    #[Test]
    #[DataProvider('sharedRoundsRefused')]
    public function sharedRoundOfRefusesWithNull(string $ticketId, string $viewer, int $number): void
    {
        self::assertNull($this->sharedRoundFor($ticketId, $viewer, $number));
    }

    /**
     * An unshared draft has no number, so no number reads it, not even for its owner.
     */
    #[Test]
    public function sharedRoundOfNeverGivesAnUnsharedDraft(): void
    {
        $roundIds = [];
        foreach ([0, 1, 2, 3] as $number) {
            $roundIds[] = $this->sharedRoundFor(self::TICKET, 'Omar', $number)?->id;
        }

        self::assertSame([self::REPORT, self::ROUND_ONE, self::ROUND_TWO, self::OMAR_SHARED_DRAFT], $roundIds);
    }

    private function sharedRoundFor(string $ticketId, string $viewer, int $number): ?RoundView
    {
        [$userId, $organizationId] = self::VIEWERS[$viewer];
        $teams = self::BEN === $userId || \in_array($userId, [self::SARA, self::NOOR], true) ? [] : [new TeamId(self::SHOP_TEAM)];
        $visibility = TicketVisibility::for(new OrganizationId($organizationId), new UserId($userId), $teams, false, null);

        return self::transactions()->rolledBackAs($organizationId, fn (): ?RoundView => $this->roundsVisibleTo()->sharedRoundOf(new TicketId($ticketId), $visibility, $number));
    }

    #[Test]
    public function anUnknownTicketHasNoRoundsAndCostsNoRoundQuery(): void
    {
        [$rounds, $tables] = $this->roundsAndQueriedTablesFor(self::UNKNOWN_TICKET, 'Sara');

        self::assertSame([], $rounds);
        self::assertSame(['tkt_ticket'], $tables);
    }

    /**
     * Another organization's predicate for an Acme Foods ticket is refused whole, with no query: the tenant is Acme
     * Foods.
     */
    #[Test]
    public function aVisibilityOfAnotherOrganizationIsRefusedBeforeAnyQuery(): void
    {
        $visibility = TicketVisibility::for(new OrganizationId(self::BETA_BAKERY), new UserId(self::BEN), [], true, null);
        $debugData = $this->debugData();

        $refusal = self::transactions()->rolledBackAs(self::ACME_FOODS, function () use ($visibility, $debugData): ?string {
            $debugData->reset();
            try {
                $this->roundsVisibleTo()->forVisibleTo(new TicketId(self::TEAM_ONLY_TICKET), $visibility);
            } catch (\InvalidArgumentException $refusal) {
                self::assertSame(0, self::queryCountOf($debugData));

                return $refusal->getMessage();
            }

            return null;
        });

        self::assertSame('The ticket visibility given is not one of the tenant\'s organization.', $refusal);
    }

    /**
     * The viewer's own predicate, handed in, saves the roles and the triager reads: the same four queries as the
     * visibility check, rounds, screenshots and pins, and the same rounds as when the query builds the predicate. The
     * ticket's shared draft holds no screenshot, which forVisibleTo would not load (DbalRoundQueriesScreenshotCountTest).
     */
    #[Test]
    public function aVisibilityHandedInSavesTheRolesReadsAndGivesTheSameRounds(): void
    {
        $debugData = $this->debugData();
        $visibility = TicketVisibility::for(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR), [new TeamId(self::SHOP_TEAM)], false, null);
        $built = $this->roundsFor(self::TICKET, 'Omar');

        [$handedIn, $queryCount] = self::transactions()->rolledBackAs(self::ACME_FOODS, function () use ($visibility, $debugData): array {
            $debugData->reset();
            $rounds = $this->roundsVisibleTo()->forVisibleTo(new TicketId(self::TICKET), $visibility)->rounds;

            return [$rounds, self::queryCountOf($debugData)];
        });

        self::assertNotSame([], $built);
        self::assertEquals($built, $handedIn);
        self::assertSame(4, $queryCount);
    }

    #[Test]
    public function aReadOutsideATransactionIsRefusedBeforeAnyQuery(): void
    {
        self::tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        $this->debugData()->reset();
        try {
            $this->roundQueries()->forTicket(new TicketId(self::TICKET), new UserId(self::OMAR));
            self::fail('Rounds were read outside a transaction.');
        } catch (\LogicException $refusal) {
            self::assertSame('Rounds are read in a transaction under the organization as tenant; none is open.', $refusal->getMessage());
        } finally {
            self::tenantContext()->clear();
        }

        self::assertSame(0, self::queryCountOf($this->debugData()));
    }

    #[Test]
    public function aReadWithNoTenantIsRefusedBeforeAnyQuery(): void
    {
        $connection = self::connection();
        $connection->beginTransaction();
        $this->debugData()->reset();
        try {
            $this->roundQueries()->forTicket(new TicketId(self::TICKET), new UserId(self::OMAR));
            self::fail('Rounds were read with no tenant.');
        } catch (\LogicException $refusal) {
            self::assertSame('No organization is set for this unit of work.', $refusal->getMessage());
            // Counted before the rollback, which the debug data records as a query of its own.
            self::assertSame(0, self::queryCountOf($this->debugData()));
        } finally {
            $connection->rollBack();
        }
    }

    private static function cappedRoundId(int $number): string
    {
        return \sprintf('0197a3c0-0000-7000-8000-0000000f54%02x', $number);
    }

    private static function exactRoundId(int $number): string
    {
        return \sprintf('0197a3c0-0000-7000-8000-0000000f55%02x', $number);
    }

    private function ticketRoundsFor(string $ticketId, string $viewer): TicketRounds
    {
        [$userId, $organizationId] = self::VIEWERS[$viewer];

        return self::transactions()->rolledBackAs($organizationId, fn (): TicketRounds => $this->roundQueries()->forTicket(new TicketId($ticketId), new UserId($userId)));
    }

    /**
     * @return list<RoundView>
     */
    private function roundsFor(string $ticketId, string $viewer): array
    {
        [$userId, $organizationId] = self::VIEWERS[$viewer];

        return self::transactions()->rolledBackAs($organizationId, fn (): array => $this->roundQueries()->forTicket(new TicketId($ticketId), new UserId($userId))->rounds);
    }

    /**
     * The rounds and, in the order sent, the table each query besides the viewer's roles (Organization's org_ tables)
     * reads its rows from.
     *
     * @return array{list<RoundView>, list<string>}
     */
    private function roundsAndQueriedTablesFor(string $ticketId, string $viewer): array
    {
        [$userId, $organizationId] = self::VIEWERS[$viewer];

        return self::transactions()->rolledBackAs($organizationId, function () use ($ticketId, $userId): array {
            $this->debugData()->reset();
            $rounds = $this->roundQueries()->forTicket(new TicketId($ticketId), new UserId($userId))->rounds;
            $tables = [];
            foreach (self::sqlOf($this->debugData()) as $sql) {
                if (1 === preg_match('/\b(FROM|JOIN) org_/', $sql)) {
                    continue;
                }
                // The outer query's table: the one filtered by the bound organization, never an EXISTS below it nor a
                // table joined to it.
                self::assertSame(1, preg_match('/FROM (\w+) AS (\w+) (?:(?:LEFT )?JOIN .+? )?WHERE \2\.organization_id = CAST\(/', $sql, $match), $sql);
                $tables[] = $match[1];
            }

            return [$rounds, $tables];
        });
    }

    /**
     * @param list<RoundView> $rounds
     *
     * @return list<string>
     */
    private static function idsOf(array $rounds): array
    {
        return array_map(static fn (RoundView $round): string => $round->id, $rounds);
    }

    /**
     * Writes the rows as the app would, under the organization as tenant.
     *
     * @param list<array{string, string, ?int, string, string, ?string, string}>                         $rounds      id, ticket, number, kind, owner, shared at, state
     * @param list<array{string, string, string, int, ?string, ?string, ?string, ?string}>               $screenshots id, round, media, position, variant label, verdict, title, summary
     * @param list<array{string, string, int, string, string, string, ?string, string, ?string, string}> $pins        id, screenshot, number, x, y, note, verdict, source, AI state, author
     */
    private static function addRoundRows(string $organizationId, array $rounds, array $screenshots, array $pins): void
    {
        self::transactions()->committedAs($organizationId, static function () use ($organizationId, $rounds, $screenshots, $pins): void {
            foreach ($rounds as $round) {
                self::connection()->executeStatement(
                    "INSERT INTO rvw_round (organization_id, id, ticket_id, number, kind, owner_id, shared_at, state, version, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, '2027-03-15 10:00:00+00')",
                    [$organizationId, ...$round],
                );
            }
            foreach ($screenshots as $screenshot) {
                self::connection()->executeStatement(
                    "INSERT INTO rvw_screenshot (organization_id, id, round_id, media_id, position, variant_label, verdict, title, summary, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, '2027-03-15 10:00:00+00')",
                    [$organizationId, ...$screenshot],
                );
            }
            foreach ($pins as $pin) {
                self::connection()->executeStatement(
                    "INSERT INTO rvw_pin (organization_id, id, screenshot_id, number, x_pct, y_pct, note, verdict, source, ai_state, author_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '2027-03-15 10:00:00+00')",
                    [$organizationId, ...$pin],
                );
            }
        });
    }

    private static function removeRows(): void
    {
        new RoundRows(self::migrator(), self::tenantContext())->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        self::shopTeamRows()->removeAllOf([self::LENA, self::OMAR, self::MIA, self::SARA, self::NOOR], self::ACME_FOODS, self::BETA_BAKERY);
    }

    private function roundsVisibleTo(): RoundsVisibleTo
    {
        $roundQueries = self::getContainer()->get('test.review_round_queries');
        \assert($roundQueries instanceof RoundsVisibleTo);

        return $roundQueries;
    }

    private function roundQueries(): RoundQueries
    {
        $roundQueries = self::getContainer()->get('test.review_round_queries');
        \assert($roundQueries instanceof DbalRoundQueries);

        return $roundQueries;
    }

    private static function shopTeamRows(): ShopTeamRows
    {
        return new ShopTeamRows(self::migrator(), self::tenantContext());
    }

    private static function transactions(): TenantTransactions
    {
        return new TenantTransactions(self::entityManager(), self::tenantContext());
    }

    private static function connection(): Connection
    {
        return self::entityManager()->getConnection();
    }

    private static function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private static function migrator(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.migrator_connection');
    }

    private static function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }
}
