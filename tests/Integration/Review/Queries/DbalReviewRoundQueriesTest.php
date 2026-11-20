<?php

declare(strict_types=1);

namespace App\Tests\Integration\Review\Queries;

use App\Review\Api\AreaState;
use App\Review\Api\AreaView;
use App\Review\Api\ReviewRoundQueries;
use App\Review\Api\ReviewRoundView;
use App\Review\Api\RoundPinView;
use App\Review\Api\RoundScreenshotView;
use App\Review\Infrastructure\Doctrine\DbalReviewRoundQueries;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\ReadsSentSql;
use App\Tests\Integration\Review\Doctrine\RoundRows;
use App\Tests\Integration\Ticketing\ShopTeamRows;
use App\Tests\Integration\Ticketing\TicketRows;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DbalReviewRoundQueries from the container as the ReviewRoundQueries Api, on rows committed once per class. Acme Foods'
 * Shop team: Lena leads it, Omar develops, Mia tests; Noor is a member in no team. On the ticket, Round 0 (the report)
 * has two pictures, Round 1 compares its two "after" pictures with them, and Round 2 compares its three with Round 1's
 * two and nothing. Round 3 is Omar's shared draft with no area, and Omar has an unshared draft with an area of its own.
 * Every picture also holds AI pins nobody kept or that were dismissed. Another ticket has a Round 1 and a Round 2 with
 * two areas, a team-only ticket a Round 1 whose only pin was dismissed, and Beta Bakery a Round 2 and a Round 5 on the
 * ticket's id.
 * Areas and pins go in out of their position and number order. Every time is fixed in April 2027; nothing reads the
 * clock.
 */
final class DbalReviewRoundQueriesTest extends KernelTestCase
{
    use ReadsSentSql;

    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000fb001';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000fb002';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000fb011';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000fb012';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000fb013';
    private const string NOOR = '0197a3c0-0000-7000-8000-0000000fb014';
    private const string BEN = '0197a3c0-0000-7000-8000-0000000fb015';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-0000000fb021';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000fb022';
    private const string BAKERY_PROJECT = '0197a3c0-0000-7000-8000-0000000fb023';
    private const string TICKET = '0197a3c0-0000-7000-8000-0000000fb031';
    private const string OTHER_TICKET = '0197a3c0-0000-7000-8000-0000000fb032';
    private const string TEAM_ONLY_TICKET = '0197a3c0-0000-7000-8000-0000000fb033';
    private const string BAKERY_TICKET = '0197a3c0-0000-7000-8000-0000000fb034';
    private const string UNKNOWN_TICKET = '0197a3c0-0000-7000-8000-0000000fb035';
    private const string REPORT = '0197a3c0-0000-7000-8000-0000000fb041';
    private const string ROUND_ONE = '0197a3c0-0000-7000-8000-0000000fb042';
    private const string ROUND_TWO = '0197a3c0-0000-7000-8000-0000000fb043';
    private const string SHARED_DRAFT = '0197a3c0-0000-7000-8000-0000000fb044';
    private const string OMAR_DRAFT = '0197a3c0-0000-7000-8000-0000000fb045';
    private const string OTHER_ROUND_TWO = '0197a3c0-0000-7000-8000-0000000fb046';
    private const string TEAM_ONLY_ROUND_ONE = '0197a3c0-0000-7000-8000-0000000fb047';
    private const string BAKERY_ROUND_TWO = '0197a3c0-0000-7000-8000-0000000fb048';
    private const string BAKERY_ROUND_FIVE = '0197a3c0-0000-7000-8000-0000000fb049';
    private const string OTHER_ROUND_ONE = '0197a3c0-0000-7000-8000-0000000fb04a';
    private const string REPORT_FIRST = '0197a3c0-0000-7000-8000-0000000fb051';
    private const string REPORT_SECOND = '0197a3c0-0000-7000-8000-0000000fb052';
    private const string ROUND_ONE_FIRST_AFTER = '0197a3c0-0000-7000-8000-0000000fb053';
    private const string ROUND_ONE_SECOND_AFTER = '0197a3c0-0000-7000-8000-0000000fb054';
    private const string ORDER_AFTER = '0197a3c0-0000-7000-8000-0000000fb055';
    private const string PRODUCT_AFTER = '0197a3c0-0000-7000-8000-0000000fb056';
    private const string COMBINED_AFTER = '0197a3c0-0000-7000-8000-0000000fb057';
    private const string DRAFT_SHOT = '0197a3c0-0000-7000-8000-0000000fb058';
    private const string OTHER_SHOT = '0197a3c0-0000-7000-8000-0000000fb059';
    private const string TEAM_ONLY_SHOT = '0197a3c0-0000-7000-8000-0000000fb05a';
    private const string BAKERY_SHOT = '0197a3c0-0000-7000-8000-0000000fb05b';
    private const string BAKERY_SECOND_SHOT = '0197a3c0-0000-7000-8000-0000000fb05c';
    private const string OTHER_ROUND_ONE_SHOT = '0197a3c0-0000-7000-8000-0000000fb05d';
    private const string OTHER_SECOND_SHOT = '0197a3c0-0000-7000-8000-0000000fb05e';
    private const string ROUND_ONE_FIRST_AREA = '0197a3c0-0000-7000-8000-0000000fb061';
    private const string ROUND_ONE_SECOND_AREA = '0197a3c0-0000-7000-8000-0000000fb062';
    private const string ORDER_AREA = '0197a3c0-0000-7000-8000-0000000fb063';
    private const string PRODUCT_AREA = '0197a3c0-0000-7000-8000-0000000fb064';
    private const string COMBINED_AREA = '0197a3c0-0000-7000-8000-0000000fb065';
    private const string DRAFT_AREA = '0197a3c0-0000-7000-8000-0000000fb066';
    private const string OTHER_AREA = '0197a3c0-0000-7000-8000-0000000fb067';
    private const string TEAM_ONLY_AREA = '0197a3c0-0000-7000-8000-0000000fb068';
    private const string BAKERY_AREA = '0197a3c0-0000-7000-8000-0000000fb069';
    private const string OTHER_SECOND_AREA = '0197a3c0-0000-7000-8000-0000000fb06a';
    private const string REPORT_PIN_ONE = '0197a3c0-0000-7000-8000-0000000fb071';
    private const string REPORT_KEPT_PIN_TWO = '0197a3c0-0000-7000-8000-0000000fb072';
    private const string REPORT_PIN_THREE = '0197a3c0-0000-7000-8000-0000000fb073';
    private const string REPORT_SUGGESTED_PIN = '0197a3c0-0000-7000-8000-0000000fb074';
    private const string REPORT_DISMISSED_PIN = '0197a3c0-0000-7000-8000-0000000fb075';
    private const string REPORT_SECOND_SUGGESTED_PIN = '0197a3c0-0000-7000-8000-0000000fb076';
    private const string ORDER_BEFORE_PIN = '0197a3c0-0000-7000-8000-0000000fb077';
    private const string PRODUCT_BEFORE_KEPT_PIN = '0197a3c0-0000-7000-8000-0000000fb078';
    private const string PRODUCT_BEFORE_FIXED_PIN = '0197a3c0-0000-7000-8000-0000000fb079';
    private const string PRODUCT_BEFORE_SUGGESTED_PIN = '0197a3c0-0000-7000-8000-0000000fb07a';
    private const string PRODUCT_BEFORE_DISMISSED_PIN = '0197a3c0-0000-7000-8000-0000000fb07b';
    private const string ORDER_AFTER_PIN = '0197a3c0-0000-7000-8000-0000000fb07c';
    private const string PRODUCT_AFTER_SUGGESTED_PIN = '0197a3c0-0000-7000-8000-0000000fb07d';
    private const string DRAFT_PIN = '0197a3c0-0000-7000-8000-0000000fb07e';
    private const string OTHER_PIN = '0197a3c0-0000-7000-8000-0000000fb07f';
    private const string TEAM_ONLY_DISMISSED_PIN = '0197a3c0-0000-7000-8000-0000000fb080';
    private const string BAKERY_PIN = '0197a3c0-0000-7000-8000-0000000fb081';
    private const string OTHER_ROUND_ONE_PIN = '0197a3c0-0000-7000-8000-0000000fb082';
    private const string FIRST_MEDIA = '0197a3c0-0000-7000-8000-0000000fb091';
    private const string SECOND_MEDIA = '0197a3c0-0000-7000-8000-0000000fb092';
    private const string REPORTED_AT = '2027-04-01 09:00:00+00';
    private const string ROUND_ONE_SHARED_AT = '2027-04-02 09:00:00+00';
    private const string ROUND_TWO_SHARED_AT = '2027-04-03 09:12:00+00';
    private const string DRAFT_SHARED_AT = '2027-04-04 09:00:00+00';
    private const string ORDER_DECIDED_AT = '2027-04-03 10:00:00+00';
    private const string PRODUCT_DECIDED_AT = '2027-04-03 10:30:00+00';
    private const string ROUND_ONE_DECIDED_AT = '2027-04-02 10:00:00+00';

    /** Every viewer's user id and organization. */
    private const array VIEWERS = [
        'Lena' => [self::LENA, self::ACME_FOODS],
        'Omar' => [self::OMAR, self::ACME_FOODS],
        'Mia' => [self::MIA, self::ACME_FOODS],
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
        self::shopTeamRows()->addUser(self::NOOR, 'Noor');
        self::transactions()->committedAs(self::ACME_FOODS, static fn (): int|string => self::connection()->executeStatement(
            "INSERT INTO org_membership (organization_id, user_id, org_roles, joined_at) VALUES (?, ?, '{}', '2027-04-01 08:00:00+00')",
            [self::ACME_FOODS, self::NOOR],
        ));
        $ticketRows = new TicketRows(self::migrator(), self::tenantContext());
        $ticketRows->addWithAudienceOf(self::ACME_FOODS, self::TICKET, self::SHOP_PROJECT, 1, 'all', self::SHOP_TEAM, self::LENA, ['assignee_id' => self::OMAR, 'tester_id' => self::MIA]);
        $ticketRows->addWithAudienceOf(self::ACME_FOODS, self::OTHER_TICKET, self::SHOP_PROJECT, 2, 'all', self::SHOP_TEAM, self::LENA);
        $ticketRows->addWithAudienceOf(self::ACME_FOODS, self::TEAM_ONLY_TICKET, self::SHOP_PROJECT, 3, 'team', self::SHOP_TEAM, self::LENA);
        $ticketRows->addOf(self::BETA_BAKERY, self::BAKERY_TICKET, self::BAKERY_PROJECT, self::BEN);

        self::addRows(self::ACME_FOODS, [
            [self::ROUND_TWO, self::TICKET, 2, 'review', self::OMAR, self::ROUND_TWO_SHARED_AT, 'open'],
            [self::OMAR_DRAFT, self::TICKET, null, 'draft', self::OMAR, null, 'open'],
            [self::REPORT, self::TICKET, 0, 'report', self::MIA, self::REPORTED_AT, 'open'],
            [self::SHARED_DRAFT, self::TICKET, 3, 'draft', self::OMAR, self::DRAFT_SHARED_AT, 'open'],
            [self::ROUND_ONE, self::TICKET, 1, 'review', self::OMAR, self::ROUND_ONE_SHARED_AT, 'closed'],
            [self::OTHER_ROUND_TWO, self::OTHER_TICKET, 2, 'review', self::OMAR, self::ROUND_TWO_SHARED_AT, 'open'],
            [self::OTHER_ROUND_ONE, self::OTHER_TICKET, 1, 'review', self::OMAR, self::ROUND_ONE_SHARED_AT, 'closed'],
            [self::TEAM_ONLY_ROUND_ONE, self::TEAM_ONLY_TICKET, 1, 'review', self::OMAR, self::ROUND_ONE_SHARED_AT, 'open'],
        ], [
            [self::REPORT_SECOND, self::REPORT, self::SECOND_MEDIA, 1, null, 'not_right', null, 'The button sits under the footer.'],
            [self::REPORT_FIRST, self::REPORT, self::FIRST_MEDIA, 0, null, 'looks_right', 'Checkout — the total', 'Totals counted twice.'],
            [self::ROUND_ONE_SECOND_AFTER, self::ROUND_ONE, self::SECOND_MEDIA, 1, 'after', null, 'Per product', null],
            [self::ROUND_ONE_FIRST_AFTER, self::ROUND_ONE, self::FIRST_MEDIA, 0, 'after', null, 'Per order', 'Sum fixed.'],
            [self::COMBINED_AFTER, self::ROUND_TWO, self::FIRST_MEDIA, 2, 'after', null, null, null],
            [self::ORDER_AFTER, self::ROUND_TWO, self::SECOND_MEDIA, 0, 'after', null, null, null],
            [self::PRODUCT_AFTER, self::ROUND_TWO, self::FIRST_MEDIA, 1, 'after', null, 'Per product', null],
            [self::DRAFT_SHOT, self::OMAR_DRAFT, self::FIRST_MEDIA, 0, null, null, null, null],
            [self::OTHER_SHOT, self::OTHER_ROUND_TWO, self::FIRST_MEDIA, 0, 'after', null, null, null],
            [self::OTHER_SECOND_SHOT, self::OTHER_ROUND_TWO, self::SECOND_MEDIA, 1, 'after', null, null, null],
            [self::OTHER_ROUND_ONE_SHOT, self::OTHER_ROUND_ONE, self::SECOND_MEDIA, 0, 'after', null, null, null],
            [self::TEAM_ONLY_SHOT, self::TEAM_ONLY_ROUND_ONE, self::FIRST_MEDIA, 0, 'after', null, null, null],
        ], [
            [self::REPORT_PIN_THREE, self::REPORT_FIRST, 3, '12.35', '7.10', 'The total is off', null, 'human', null, self::LENA],
            [self::REPORT_SUGGESTED_PIN, self::REPORT_FIRST, 4, '20.00', '20.00', 'AI thinks so', null, 'ai', 'suggested', self::LENA],
            [self::REPORT_PIN_ONE, self::REPORT_FIRST, 1, '0.00', '100.00', 'Prix affiché en €', 'still_wrong', 'human', null, self::MIA],
            [self::REPORT_DISMISSED_PIN, self::REPORT_FIRST, 5, '30.00', '30.00', 'AI was wrong', null, 'ai', 'dismissed', self::LENA],
            [self::REPORT_KEPT_PIN_TWO, self::REPORT_FIRST, 2, '99.99', '50.00', 'Hidden here', 'fixed', 'ai', 'kept', self::MIA],
            [self::REPORT_SECOND_SUGGESTED_PIN, self::REPORT_SECOND, 1, '40.00', '60.00', 'AI only', null, 'ai', 'suggested', self::MIA],
            [self::PRODUCT_BEFORE_FIXED_PIN, self::ROUND_ONE_SECOND_AFTER, 2, '40.00', '60.00', 'Planned is 2,850', 'fixed', 'human', null, self::MIA],
            [self::PRODUCT_BEFORE_DISMISSED_PIN, self::ROUND_ONE_SECOND_AFTER, 4, '10.00', '10.00', 'Dismissed', null, 'ai', 'dismissed', self::MIA],
            [self::PRODUCT_BEFORE_KEPT_PIN, self::ROUND_ONE_SECOND_AFTER, 1, '50.00', '50.00', 'Column left-aligned', null, 'ai', 'kept', self::MIA],
            [self::PRODUCT_BEFORE_SUGGESTED_PIN, self::ROUND_ONE_SECOND_AFTER, 3, '15.00', '15.00', 'Suggested', null, 'ai', 'suggested', self::MIA],
            [self::ORDER_BEFORE_PIN, self::ROUND_ONE_FIRST_AFTER, 1, '25.00', '75.00', 'Was cut off', null, 'human', null, self::MIA],
            [self::ORDER_AFTER_PIN, self::ORDER_AFTER, 1, '60.00', '40.00', 'Still a gap', null, 'human', null, self::OMAR],
            [self::PRODUCT_AFTER_SUGGESTED_PIN, self::PRODUCT_AFTER, 1, '60.00', '40.00', 'AI after', null, 'ai', 'suggested', self::OMAR],
            [self::DRAFT_PIN, self::DRAFT_SHOT, 1, '40.00', '60.00', 'Mine only', null, 'human', null, self::OMAR],
            [self::OTHER_PIN, self::OTHER_SHOT, 1, '40.00', '60.00', 'Other ticket', null, 'human', null, self::LENA],
            [self::OTHER_ROUND_ONE_PIN, self::OTHER_ROUND_ONE_SHOT, 1, '40.00', '60.00', 'Other ticket earlier', null, 'human', null, self::MIA],
            [self::TEAM_ONLY_DISMISSED_PIN, self::TEAM_ONLY_SHOT, 1, '40.00', '60.00', 'Dismissed', null, 'ai', 'dismissed', self::LENA],
        ], [
            [self::COMBINED_AREA, self::ROUND_TWO, 'Combined view', 2, null, self::COMBINED_AFTER, null, null, null, null],
            [self::ORDER_AREA, self::ROUND_TWO, 'Per order', 0, self::ROUND_ONE_FIRST_AFTER, self::ORDER_AFTER, 'pass', 'Totals match now.', self::MIA, self::ORDER_DECIDED_AT],
            [self::PRODUCT_AREA, self::ROUND_TWO, 'Per product', 1, self::ROUND_ONE_SECOND_AFTER, self::PRODUCT_AFTER, 'question', null, self::LENA, self::PRODUCT_DECIDED_AT],
            [self::ROUND_ONE_SECOND_AREA, self::ROUND_ONE, 'Per product', 1, self::REPORT_SECOND, self::ROUND_ONE_SECOND_AFTER, 'needs_fix', 'Still off.', self::MIA, self::ROUND_ONE_DECIDED_AT],
            [self::ROUND_ONE_FIRST_AREA, self::ROUND_ONE, 'Per order', 0, self::REPORT_FIRST, self::ROUND_ONE_FIRST_AFTER, null, null, null, null],
            [self::DRAFT_AREA, self::OMAR_DRAFT, 'Draft area', 0, self::ORDER_AFTER, self::DRAFT_SHOT, null, null, null, null],
            [self::OTHER_SECOND_AREA, self::OTHER_ROUND_TWO, 'Elsewhere too', 1, self::OTHER_ROUND_ONE_SHOT, self::OTHER_SECOND_SHOT, 'needs_fix', null, self::MIA, self::ORDER_DECIDED_AT],
            [self::OTHER_AREA, self::OTHER_ROUND_TWO, 'Elsewhere', 0, null, self::OTHER_SHOT, 'question', null, self::LENA, self::PRODUCT_DECIDED_AT],
            [self::TEAM_ONLY_AREA, self::TEAM_ONLY_ROUND_ONE, 'Team only', 0, null, self::TEAM_ONLY_SHOT, 'pass', null, self::LENA, self::ROUND_ONE_DECIDED_AT],
        ]);
        // rvw_round.ticket_id has no foreign key, so Beta Bakery may hold rounds on Acme Foods' ticket id.
        self::addRows(self::BETA_BAKERY, [
            [self::BAKERY_ROUND_TWO, self::TICKET, 2, 'review', self::BEN, self::ROUND_TWO_SHARED_AT, 'open'],
            [self::BAKERY_ROUND_FIVE, self::TICKET, 5, 'review', self::BEN, self::ROUND_TWO_SHARED_AT, 'open'],
        ], [
            [self::BAKERY_SHOT, self::BAKERY_ROUND_TWO, self::FIRST_MEDIA, 0, 'after', null, null, null],
            [self::BAKERY_SECOND_SHOT, self::BAKERY_ROUND_FIVE, self::FIRST_MEDIA, 0, 'after', null, null, null],
        ], [
            [self::BAKERY_PIN, self::BAKERY_SHOT, 1, '40.00', '60.00', 'Bakery', null, 'human', null, self::BEN],
        ], [
            [self::BAKERY_AREA, self::BAKERY_ROUND_TWO, 'Bakery area', 0, null, self::BAKERY_SHOT, null, null, null, null],
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

    /**
     * Round 2's areas by position, each "before" the matching "after" picture of Round 1 or none, with only the pins
     * that count on each picture, by number: no AI suggestion and no dismissed AI pin on either side.
     */
    #[Test]
    public function aRoundComesWithItsAreasByPositionBothPicturesAndOnlyThePinsThatCount(): void
    {
        $round = $this->roundFor(self::TICKET, 2, 'Mia');

        $at = static fn (string $time): \DateTimeImmutable => new \DateTimeImmutable($time);
        self::assertEquals(new ReviewRoundView(self::ROUND_TWO, 2, 'review', new UserId(self::OMAR), $at(self::ROUND_TWO_SHARED_AT), 'open', [
            new AreaView(
                self::ORDER_AREA,
                'Per order',
                0,
                new RoundScreenshotView(self::ROUND_ONE_FIRST_AFTER, self::FIRST_MEDIA, 0, 'after', null, 'Per order', 'Sum fixed.', [
                    new RoundPinView(self::ORDER_BEFORE_PIN, 1, 25.0, 75.0, 'Was cut off', null, 'human', null, new UserId(self::MIA), null),
                ]),
                1,
                new RoundScreenshotView(self::ORDER_AFTER, self::SECOND_MEDIA, 0, 'after', null, null, null, [
                    new RoundPinView(self::ORDER_AFTER_PIN, 1, 60.0, 40.0, 'Still a gap', null, 'human', null, new UserId(self::OMAR), null),
                ]),
                'pass',
                'Totals match now.',
                new UserId(self::MIA),
                $at(self::ORDER_DECIDED_AT),
            ),
            new AreaView(
                self::PRODUCT_AREA,
                'Per product',
                1,
                new RoundScreenshotView(self::ROUND_ONE_SECOND_AFTER, self::SECOND_MEDIA, 1, 'after', null, 'Per product', null, [
                    new RoundPinView(self::PRODUCT_BEFORE_KEPT_PIN, 1, 50.0, 50.0, 'Column left-aligned', null, 'ai', 'kept', new UserId(self::MIA), null),
                    new RoundPinView(self::PRODUCT_BEFORE_FIXED_PIN, 2, 40.0, 60.0, 'Planned is 2,850', 'fixed', 'human', null, new UserId(self::MIA), null),
                ]),
                1,
                new RoundScreenshotView(self::PRODUCT_AFTER, self::FIRST_MEDIA, 1, 'after', null, 'Per product', null, []),
                'question',
                null,
                new UserId(self::LENA),
                $at(self::PRODUCT_DECIDED_AT),
            ),
            new AreaView(
                self::COMBINED_AREA,
                'Combined view',
                2,
                null,
                null,
                new RoundScreenshotView(self::COMBINED_AFTER, self::FIRST_MEDIA, 2, 'after', null, null, null, []),
                null,
                null,
                null,
                null,
            ),
        ]), $round);
    }

    /**
     * Passed for the pass verdict with or without a pin to check; "N pins" counts only the "before" picture's pins that
     * count, with no verdict, needs fix or question; with no pin, the needs fix or question verdict shows, and only an
     * area with no verdict is Not reviewed.
     */
    #[Test]
    public function eachAreaStateFollowsItsVerdictAndTheCountedPinsOnItsBeforePicture(): void
    {
        $statesByRound = [];
        foreach ([[self::TICKET, 2], [self::TICKET, 1], [self::OTHER_TICKET, 2], [self::TEAM_ONLY_TICKET, 1]] as [$ticketId, $number]) {
            $round = $this->roundFor($ticketId, $number, 'Lena');
            self::assertNotNull($round);
            $statesByRound[] = array_map(static fn (AreaView $area): array => [$area->verdict, $area->state(), $area->pinCount()], $round->areas);
        }

        self::assertSame([
            [['pass', AreaState::Passed, 1], ['question', AreaState::Pins, 2], [null, AreaState::NotReviewed, 0]],
            [[null, AreaState::Pins, 3], ['needs_fix', AreaState::NeedsFix, 0]],
            [['question', AreaState::Question, 0], ['needs_fix', AreaState::Pins, 1]],
            [['pass', AreaState::Passed, 0]],
        ], $statesByRound);
    }

    #[Test]
    public function anAreaWithAVerdictOfNoKnownKindHasNoState(): void
    {
        $after = new RoundScreenshotView(self::ORDER_AFTER, self::SECOND_MEDIA, 0, 'after', null, null, null, []);
        $area = new AreaView(self::ORDER_AREA, 'Per order', 0, null, null, $after, 'done', null, new UserId(self::MIA), new \DateTimeImmutable(self::ORDER_DECIDED_AT));

        $this->expectExceptionObject(new \UnexpectedValueException('The area has the unknown verdict "done".'));

        $area->state();
    }

    /**
     * Round 1's "before" pictures are Round 0's report pictures, with their title and summary.
     */
    #[Test]
    public function roundOnesBeforePicturesAreTheReportsWithTheirTitleAndSummary(): void
    {
        $round = $this->roundFor(self::TICKET, 1, 'Omar');
        self::assertNotNull($round);

        self::assertSame([self::ROUND_ONE_FIRST_AREA, self::ROUND_ONE_SECOND_AREA], array_map(static fn (AreaView $area): string => $area->id, $round->areas));
        self::assertEquals(
            new RoundScreenshotView(self::REPORT_FIRST, self::FIRST_MEDIA, 0, null, 'looks_right', 'Checkout — the total', 'Totals counted twice.', [
                new RoundPinView(self::REPORT_PIN_ONE, 1, 0.0, 100.0, 'Prix affiché en €', 'still_wrong', 'human', null, new UserId(self::MIA), null),
                new RoundPinView(self::REPORT_KEPT_PIN_TWO, 2, 99.99, 50.0, 'Hidden here', 'fixed', 'ai', 'kept', new UserId(self::MIA), null),
                new RoundPinView(self::REPORT_PIN_THREE, 3, 12.35, 7.1, 'The total is off', null, 'human', null, new UserId(self::LENA), null),
            ]),
            $round->areas[0]->before,
        );
        self::assertEquals(
            new RoundScreenshotView(self::REPORT_SECOND, self::SECOND_MEDIA, 1, null, 'not_right', null, 'The button sits under the footer.', []),
            $round->areas[1]->before,
        );
        self::assertSame([0, 0], array_map(static fn (AreaView $area): ?int => $area->beforeRoundNumber, $round->areas));
        self::assertSame(['needs_fix', 'Still off.'], [$round->areas[1]->verdict, $round->areas[1]->note]);
    }

    /**
     * Nothing of another round, another ticket's Round 2, Omar's unshared draft or Beta Bakery's Round 2 on the same
     * ticket id comes with Round 2.
     */
    #[Test]
    public function noAreaPictureOrPinOfAnotherRoundTicketDraftOrOrganizationComesWithTheRound(): void
    {
        $round = $this->roundFor(self::TICKET, 2, 'Lena');
        self::assertNotNull($round);
        $ids = [];
        foreach ($round->areas as $area) {
            $ids[] = $area->id;
            foreach ([$area->before, $area->after] as $picture) {
                if (null !== $picture) {
                    $ids[] = $picture->id;
                    array_push($ids, ...array_map(static fn (RoundPinView $pin): string => $pin->id, $picture->pins));
                }
            }
        }

        self::assertNotSame([], $ids);
        foreach ([self::DRAFT_AREA, self::DRAFT_SHOT, self::DRAFT_PIN, self::OTHER_AREA, self::OTHER_SHOT, self::OTHER_PIN, self::OTHER_SECOND_AREA, self::OTHER_SECOND_SHOT, self::OTHER_ROUND_ONE_SHOT, self::OTHER_ROUND_ONE_PIN, self::BAKERY_AREA, self::BAKERY_SHOT, self::BAKERY_PIN, self::ROUND_ONE_FIRST_AREA, self::ROUND_ONE_SECOND_AREA] as $excludedId) {
            self::assertNotContains($excludedId, $ids);
        }
        foreach ([self::REPORT_SUGGESTED_PIN, self::REPORT_DISMISSED_PIN, self::PRODUCT_BEFORE_SUGGESTED_PIN, self::PRODUCT_BEFORE_DISMISSED_PIN, self::PRODUCT_AFTER_SUGGESTED_PIN] as $uncountedPinId) {
            self::assertNotContains($uncountedPinId, $ids);
        }
        self::assertSame(self::ROUND_TWO, $round->id);
    }

    /**
     * The team-only ticket's round, seen by its lead, is hidden from a member outside the team; the visibility check
     * stops before any round query.
     */
    #[Test]
    public function aRoundOfATicketTheViewerMayNotSeeIsNullAndCostsNoRoundQuery(): void
    {
        self::assertSame(self::TEAM_ONLY_ROUND_ONE, $this->roundFor(self::TEAM_ONLY_TICKET, 1, 'Lena')?->id);

        [$round, $tables] = $this->roundAndQueriedTablesFor(self::TEAM_ONLY_TICKET, 1, 'Noor');

        self::assertNull($round);
        self::assertSame(['tkt_ticket'], $tables);
    }

    /**
     * Beta Bakery holds a Round 5 on the ticket's id, which Acme Foods has not, and its own viewer cannot see Acme's
     * ticket at all.
     *
     * @return iterable<string, array{string, int, string}>
     */
    public static function roundsThatAreNotThere(): iterable
    {
        yield 'a number the ticket has no round with' => [self::TICKET, 9, 'Omar'];
        yield 'a number only another organization has on the ticket id' => [self::TICKET, 5, 'Omar'];
        yield 'another organization\'s ticket id, to that organization\'s viewer' => [self::TICKET, 2, 'Ben'];
        yield 'an unknown ticket' => [self::UNKNOWN_TICKET, 0, 'Lena'];
    }

    #[Test]
    #[DataProvider('roundsThatAreNotThere')]
    public function aRoundThatIsNotThereIsNull(string $ticketId, int $number, string $viewer): void
    {
        self::assertNull($this->roundFor($ticketId, $number, $viewer));
    }

    /**
     * Omar's unshared draft has an area but no number, so no number reaches it, for him or anyone; his shared draft,
     * Round 3, comes for everyone who sees the ticket, with no area and no area query.
     */
    #[Test]
    public function anUnsharedDraftHasNoNumberToReachAndASharedOneComesWithNoAreaQuery(): void
    {
        $roundIds = [];
        foreach (['Omar', 'Mia'] as $viewer) {
            foreach ([0, 1, 2, 3] as $number) {
                $roundIds[] = $this->roundFor(self::TICKET, $number, $viewer)?->id;
            }
        }
        [$sharedDraft, $tables] = $this->roundAndQueriedTablesFor(self::TICKET, 3, 'Mia');

        self::assertNotContains(null, $roundIds);
        self::assertNotContains(self::OMAR_DRAFT, $roundIds);
        self::assertNotNull($sharedDraft);
        self::assertSame([self::SHARED_DRAFT, 'draft', []], [$sharedDraft->id, $sharedDraft->kind, $sharedDraft->areas]);
        self::assertSame(['tkt_ticket', 'rvw_round'], $tables);
    }

    /**
     * Four queries besides the viewer's roles for a round with three areas, six pictures and their pins: the
     * visibility check, the round, the areas with both pictures, and the pins, one each.
     */
    #[Test]
    public function aRoundWithSeveralAreasAndPinsCostsFourQueriesBesidesTheRoles(): void
    {
        [$round, $tables] = $this->roundAndQueriedTablesFor(self::TICKET, 2, 'Mia');

        self::assertNotNull($round);
        self::assertCount(3, $round->areas);
        self::assertSame(['tkt_ticket', 'rvw_round', 'rvw_area', 'rvw_pin'], $tables);
        self::assertLessThanOrEqual(5, \count($tables));
    }

    /**
     * The team-only round's one picture holds only a dismissed AI pin, so nothing counts and no pin query is sent.
     */
    #[Test]
    public function aRoundWithNoPinThatCountsCostsNoPinQuery(): void
    {
        [$round, $tables] = $this->roundAndQueriedTablesFor(self::TEAM_ONLY_TICKET, 1, 'Lena');

        self::assertNotNull($round);
        self::assertSame([[self::TEAM_ONLY_AREA, null, []]], array_map(static fn (AreaView $area): array => [$area->id, $area->before, $area->after->pins], $round->areas));
        self::assertSame(['tkt_ticket', 'rvw_round', 'rvw_area'], $tables);
    }

    #[Test]
    public function aNegativeNumberIsNullWithNoQuery(): void
    {
        $queryCount = self::transactions()->rolledBackAs(self::ACME_FOODS, function (): int {
            $this->debugData()->reset();
            self::assertNull($this->reviewRoundQueries()->roundOf(new TicketId(self::TICKET), -1, new UserId(self::OMAR)));

            return self::queryCountOf($this->debugData());
        });

        self::assertSame(0, $queryCount);
    }

    #[Test]
    public function aReadOutsideATransactionIsRefusedBeforeAnyQuery(): void
    {
        self::tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        $this->debugData()->reset();
        try {
            $this->reviewRoundQueries()->roundOf(new TicketId(self::TICKET), 2, new UserId(self::OMAR));
            self::fail('A review round was read outside a transaction.');
        } catch (\LogicException $refusal) {
            self::assertSame('A review round is read in a transaction under the organization as tenant; none is open.', $refusal->getMessage());
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
            $this->reviewRoundQueries()->roundOf(new TicketId(self::TICKET), 2, new UserId(self::OMAR));
            self::fail('A review round was read with no tenant.');
        } catch (\LogicException $refusal) {
            self::assertSame('No organization is set for this unit of work.', $refusal->getMessage());
            // Counted before the rollback, which the debug data records as a query of its own.
            self::assertSame(0, self::queryCountOf($this->debugData()));
        } finally {
            $connection->rollBack();
        }
    }

    private function roundFor(string $ticketId, int $number, string $viewer): ?ReviewRoundView
    {
        [$userId, $organizationId] = self::VIEWERS[$viewer];

        return self::transactions()->rolledBackAs($organizationId, fn (): ?ReviewRoundView => $this->reviewRoundQueries()->roundOf(new TicketId($ticketId), $number, new UserId($userId)));
    }

    /**
     * The round and, in the order sent, the table each query besides the viewer's roles (Organization's org_ tables)
     * reads its rows from: the one whose alias the bound organization filters, never a join or an EXISTS below it.
     *
     * @return array{?ReviewRoundView, list<string>}
     */
    private function roundAndQueriedTablesFor(string $ticketId, int $number, string $viewer): array
    {
        [$userId, $organizationId] = self::VIEWERS[$viewer];

        return self::transactions()->rolledBackAs($organizationId, function () use ($ticketId, $number, $userId): array {
            $this->debugData()->reset();
            $round = $this->reviewRoundQueries()->roundOf(new TicketId($ticketId), $number, new UserId($userId));
            $tables = [];
            foreach (self::sqlOf($this->debugData()) as $sql) {
                if (1 === preg_match('/\b(FROM|JOIN) org_/', $sql)) {
                    continue;
                }
                self::assertSame(1, preg_match('/WHERE (\w+)\.organization_id = CAST\(/', $sql, $filtered), $sql);
                self::assertSame(1, preg_match('/FROM (\w+) AS '.$filtered[1].'\b/', $sql, $table), $sql);
                $tables[] = $table[1];
            }

            return [$round, $tables];
        });
    }

    /**
     * Writes the rows as the app would, under the organization as tenant.
     *
     * @param list<array{string, string, ?int, string, string, ?string, string}>                            $rounds      id, ticket, number, kind, owner, shared at, state
     * @param list<array{string, string, string, int, ?string, ?string, ?string, ?string}>                  $screenshots id, round, media, position, variant label, verdict, title, summary
     * @param list<array{string, string, int, string, string, string, ?string, string, ?string, string}>    $pins        id, screenshot, number, x, y, note, verdict, source, AI state, author
     * @param list<array{string, string, string, int, ?string, string, ?string, ?string, ?string, ?string}> $areas       id, round, name, position, before, after, verdict, note, decided by, decided at
     */
    private static function addRows(string $organizationId, array $rounds, array $screenshots, array $pins, array $areas): void
    {
        self::transactions()->committedAs($organizationId, static function () use ($organizationId, $rounds, $screenshots, $pins, $areas): void {
            foreach ($rounds as $round) {
                self::connection()->executeStatement(
                    "INSERT INTO rvw_round (organization_id, id, ticket_id, number, kind, owner_id, shared_at, state, version, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, '2027-04-01 08:00:00+00')",
                    [$organizationId, ...$round],
                );
            }
            foreach ($screenshots as $screenshot) {
                self::connection()->executeStatement(
                    "INSERT INTO rvw_screenshot (organization_id, id, round_id, media_id, position, variant_label, verdict, title, summary, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, '2027-04-01 08:00:00+00')",
                    [$organizationId, ...$screenshot],
                );
            }
            foreach ($pins as $pin) {
                self::connection()->executeStatement(
                    "INSERT INTO rvw_pin (organization_id, id, screenshot_id, number, x_pct, y_pct, note, verdict, source, ai_state, author_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '2027-04-01 08:00:00+00')",
                    [$organizationId, ...$pin],
                );
            }
            foreach ($areas as $area) {
                self::connection()->executeStatement(
                    "INSERT INTO rvw_area (organization_id, id, round_id, name, position, before_screenshot_id, after_screenshot_id, verdict, note, decided_by, decided_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '2027-04-01 08:00:00+00')",
                    [$organizationId, ...$area],
                );
            }
        });
    }

    private static function removeRows(): void
    {
        new RoundRows(self::migrator(), self::tenantContext())->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        self::shopTeamRows()->removeAllOf([self::LENA, self::OMAR, self::MIA, self::NOOR], self::ACME_FOODS, self::BETA_BAKERY);
    }

    private function reviewRoundQueries(): ReviewRoundQueries
    {
        $reviewRoundQueries = self::getContainer()->get('test.review_review_round_queries');
        \assert($reviewRoundQueries instanceof DbalReviewRoundQueries);

        return $reviewRoundQueries;
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
