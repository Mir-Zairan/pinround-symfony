<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ticketing;

use App\Identity\Infrastructure\Doctrine\DbalUserQueries;
use App\Insight\Infrastructure\Doctrine\DbalTurnUserQueries;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Tenancy\OwnConnectionTransactions;
use App\Ticketing\Api\Cursor;
use App\Ticketing\Api\ListFilter;
use App\Ticketing\Api\TicketListGroup;
use App\Ticketing\Api\TicketListRow;
use App\Ticketing\Api\TicketListStep;
use App\Ticketing\Api\TicketQueries;
use App\Ticketing\Api\TicketVisibility;
use App\Ticketing\Infrastructure\Doctrine\DbalTicketQueries;
use App\Workflow\Api\StepQueries;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The container's TicketQueries as pinround_app under row-level security, read by an admin, who may see every ticket,
 * so these tests are about grouping, order, pages and counts; the visibility leak suite covers who sees what.
 */
final class DbalTicketQueriesTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000071a1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000071a2';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-0000000071c1';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000071d1';
    private const string OPS_PROJECT = '0197a3c0-0000-7000-8000-0000000071d2';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000071b1';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000071b2';
    private const string SARA = '0197a3c0-0000-7000-8000-0000000071b3';
    private const string WAITING_STEP = '0197a3c0-0000-7000-8000-0000000071f1';
    private const string CHECKING_STEP = '0197a3c0-0000-7000-8000-0000000071f2';
    private const string DOING_STEP = '0197a3c0-0000-7000-8000-0000000071f3';
    private const string ARCHIVED_DOING_STEP = '0197a3c0-0000-7000-8000-0000000071f4';
    private const string COMPANY_TODO_STEP = '0197a3c0-0000-7000-8000-0000000071f5';
    private const string DONE_STEP = '0197a3c0-0000-7000-8000-0000000071f6';

    private int $nextNumber = 1;

    protected function setUp(): void
    {
        $this->removeRows();
        $this->shopTeamRows()->add(self::ACME_FOODS, self::SHOP_TEAM, self::SHOP_PROJECT, self::LENA, [
            self::LENA => ['Lena', 'lead', 'developer'],
            self::OMAR => ['Omar', 'member', 'developer'],
        ]);
        $this->asTenantMigrator(self::ACME_FOODS, static function (Connection $migrator): void {
            foreach ([
                [self::WAITING_STEP, null, 'Waiting on customer', 'wait', null, 'company', 0, null],
                [self::CHECKING_STEP, self::SHOP_TEAM, 'Review', 'check', null, 'team', 0, null],
                [self::DOING_STEP, null, 'In progress', 'doing', 'progress', 'core', 0, null],
                [self::ARCHIVED_DOING_STEP, null, 'Pairing', 'doing', null, 'company', 1, '2027-03-14 10:00:00+00'],
                [self::COMPANY_TODO_STEP, null, 'Triage', 'todo', null, 'company', 0, null],
                [self::DONE_STEP, null, 'Done', 'done', 'done', 'core', 0, null],
            ] as $step) {
                $migrator->executeStatement(
                    'INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position, archived_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [self::ACME_FOODS, ...$step],
                );
            }
        });
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        $this->tenantContext()->reset();
        $this->removeRows();
        parent::tearDown();
    }

    /**
     * Backlog has no ticket, so it is left out; the core steps of To do come before its company step, whatever their
     * positions, and the archived Doing step is kept.
     */
    #[Test]
    public function theGroupsComeInListOrderWithTheirStepsAndDoneOnlyWhenAsked(): void
    {
        foreach ([self::DONE_STEP, self::COMPANY_TODO_STEP, ShopTeamRows::TO_DO_STEP, self::ARCHIVED_DOING_STEP, self::DOING_STEP, self::CHECKING_STEP, self::WAITING_STEP] as $stepId) {
            $this->addTicket($stepId, 2, '2027-03-15 10:00:00+00');
        }

        $openGroups = [
            ['wait', 'Waiting', 'Someone must answer first', [['Waiting on customer', false]]],
            ['check', 'Checking', 'Being checked', [['Review', false]]],
            ['doing', 'Doing', 'Being built', [['In progress', false], ['Pairing', true]]],
            ['todo', 'To do', 'Not started', [['To do', false], ['Triage', false]]],
        ];
        self::assertSame($openGroups, self::shapeOf($this->listedAs(self::ACME_FOODS, $this->filter())));
        self::assertSame([...$openGroups, ['done', 'Done', null, [['Done', false]]]], self::shapeOf($this->listedAs(self::ACME_FOODS, $this->filter(showsDone: true))));
    }

    #[Test]
    public function rowsComeByPriorityThenMostRecentlyUpdatedThenHighestId(): void
    {
        $olderMajor = $this->addTicket(ShopTeamRows::TO_DO_STEP, 2, '2027-03-15 10:00:00+00');
        $blocker = $this->addTicket(ShopTeamRows::TO_DO_STEP, 0, '2027-03-15 09:00:00+00');
        $newerMajor = $this->addTicket(ShopTeamRows::TO_DO_STEP, 2, '2027-03-15 11:00:00+00');
        $olderMajorWithHigherId = $this->addTicket(ShopTeamRows::TO_DO_STEP, 2, '2027-03-15 10:00:00+00');

        $step = $this->listedAs(self::ACME_FOODS, $this->filter())[0]->steps[0];

        self::assertSame([$blocker, $newerMajor, $olderMajorWithHigherId, $olderMajor], self::keysOf($step));
    }

    /**
     * The 51st row of To do only says there is more; the cursor then gives To do alone, from the row after the 50th,
     * still counting the whole step. Doing has exactly 50 rows, so no more.
     */
    #[Test]
    public function aStepShowsFiftyRowsAndItsCursorGivesTheRest(): void
    {
        $toDoKeys = $this->addTickets(ShopTeamRows::TO_DO_STEP, 51);
        $doingKeys = $this->addTickets(self::DOING_STEP, 50);

        $groups = $this->listedAs(self::ACME_FOODS, $this->filter());
        [$doing, $toDo] = [$groups[0]->steps[0], $groups[1]->steps[0]];

        self::assertSame([50, false, 50, false, null], [$doing->ticketCount, $doing->isCountCapped, \count($doing->rows), $doing->hasMore(), $doing->nextCursor]);
        self::assertSame(array_reverse($doingKeys), self::keysOf($doing));
        self::assertSame([51, true], [$toDo->ticketCount, $toDo->hasMore()]);
        self::assertSame(\array_slice(array_reverse($toDoKeys), 0, TicketListStep::PAGE_SIZE), self::keysOf($toDo));

        $cursor = $toDo->nextCursor;
        self::assertNotNull($cursor);
        $nextPage = $this->listedAs(self::ACME_FOODS, $this->filter(), $cursor);

        self::assertSame([['todo', 'To do', 'Not started', [['To do', false]]]], self::shapeOf($nextPage));
        self::assertSame([51, false, [$toDoKeys[0]]], [$nextPage[0]->steps[0]->ticketCount, $nextPage[0]->steps[0]->hasMore(), self::keysOf($nextPage[0]->steps[0])]);
    }

    /**
     * To do holds one ticket past the cap, so it reports the cap and capped, on the first page and after its cursor;
     * Doing holds exactly the cap, so its count is whole.
     */
    #[Test]
    public function aStepCountsAtMostTheCapAndSaysWhenItHoldsMore(): void
    {
        $this->addManyTickets(ShopTeamRows::TO_DO_STEP, TicketListStep::COUNT_CAP + 1);
        $this->addManyTickets(self::DOING_STEP, TicketListStep::COUNT_CAP);

        $groups = $this->listedAs(self::ACME_FOODS, $this->filter());
        [$doing, $toDo] = [$groups[0]->steps[0], $groups[1]->steps[0]];

        self::assertSame([1000, false], [$doing->ticketCount, $doing->isCountCapped]);
        self::assertSame([1000, true, TicketListStep::PAGE_SIZE], [$toDo->ticketCount, $toDo->isCountCapped, \count($toDo->rows)]);

        $cursor = $toDo->nextCursor;
        self::assertNotNull($cursor);
        $nextStep = $this->listedAs(self::ACME_FOODS, $this->filter(), $cursor)[0]->steps[0];

        self::assertSame([1000, true], [$nextStep->ticketCount, $nextStep->isCountCapped]);
    }

    /**
     * The cursor's row and the one before it share the rank and updated_at, so only the id tells them apart.
     */
    #[Test]
    public function theCursorBreaksTiesOnTheId(): void
    {
        $first = $this->addTicket(ShopTeamRows::TO_DO_STEP, 3, '2027-03-15 10:00:00+00');
        $second = $this->addTicket(ShopTeamRows::TO_DO_STEP, 3, '2027-03-15 10:00:00+00');
        $third = $this->addTicket(ShopTeamRows::TO_DO_STEP, 3, '2027-03-15 10:00:00+00');
        $higherPriority = $this->addTicket(ShopTeamRows::TO_DO_STEP, 1, '2027-03-15 12:00:00+00');
        $rows = $this->listedAs(self::ACME_FOODS, $this->filter())[0]->steps[0]->rows;
        self::assertSame([$higherPriority, $third, $second, $first], array_map(static fn (TicketListRow $row): string => $row->key, $rows));

        $nextPage = $this->listedAs(self::ACME_FOODS, $this->filter(), new Cursor(ShopTeamRows::TO_DO_STEP, 3, $rows[2]->updatedAt, $rows[2]->id));

        self::assertSame([$first], self::keysOf($nextPage[0]->steps[0]));
    }

    #[Test]
    public function oneProjectLeavesOutTheTicketsOfOtherProjects(): void
    {
        $shopTicket = $this->addTicket(ShopTeamRows::TO_DO_STEP, 2, '2027-03-15 10:00:00+00');
        new TicketRows($this->migrator(), $this->tenantContext())->addKeyedProjectOf(self::ACME_FOODS, self::OPS_PROJECT, null, 'OPS', 1);
        $opsTicket = $this->addTicket(ShopTeamRows::TO_DO_STEP, 2, '2027-03-15 11:00:00+00', self::OPS_PROJECT);

        self::assertSame([$shopTicket], self::keysOf($this->listedAs(self::ACME_FOODS, $this->filter(self::SHOP_PROJECT))[0]->steps[0]));
        self::assertSame([1, [$opsTicket]], [
            $this->listedAs(self::ACME_FOODS, $this->filter(self::OPS_PROJECT))[0]->steps[0]->ticketCount,
            self::keysOf($this->listedAs(self::ACME_FOODS, $this->filter(self::OPS_PROJECT))[0]->steps[0]),
        ]);
        self::assertSame([$opsTicket, $shopTicket], self::keysOf($this->listedAs(self::ACME_FOODS, $this->filter())[0]->steps[0]));
    }

    /**
     * Omar is the assignee and Lena's is the turn; the other ticket has neither, nor a turn row.
     */
    #[Test]
    public function aRowCarriesItsColumnsAssigneeAndTurnUserWithTheirNamesInFourQueries(): void
    {
        $assigned = $this->addTicket(ShopTeamRows::TO_DO_STEP, 1, '2027-03-15 11:00:00+00');
        $unassigned = $this->addTicket(ShopTeamRows::TO_DO_STEP, 3, '2027-03-15 10:00:00+00');
        $this->asTenantMigrator(self::ACME_FOODS, static function (Connection $migrator) use ($assigned): void {
            $migrator->executeStatement("UPDATE tkt_ticket SET assignee_id = ?, release = '2027.3', type = 'story', title = 'Add a gift note' WHERE key = ?", [self::OMAR, $assigned]);
            $migrator->executeStatement(
                "INSERT INTO ins_ticket_turn (organization_id, ticket_id, team_id, project_id, grp, turn_user_id, kind, base_score, waiting_since, visibility, reporter_id)
                 SELECT organization_id, id, NULL, project_id, 'todo', ?, 'build', 10, '2027-03-15 11:00:00+00', 'all', reporter_id FROM tkt_ticket WHERE key = ?",
                [self::LENA, $assigned],
            );
        });

        $debugData = $this->debugData();
        [$groups, $queryCount] = $this->transactions()->rolledBackAs(self::ACME_FOODS, function () use ($debugData): array {
            $debugData->reset();
            $groups = $this->ticketQueries()->list($this->filter());

            return [$groups, self::queryCountOf($debugData)];
        });
        [$assignedRow, $unassignedRow] = $groups[0]->steps[0]->rows;

        self::assertSame(4, $queryCount);
        self::assertSame(
            [$assigned, 'Add a gift note', 'story', '2027.3', 'critical', self::OMAR, 'Omar', self::LENA, 'Lena', '2027-03-15 11:00:00', ShopTeamRows::TO_DO_STEP],
            [
                $assignedRow->key, $assignedRow->title, $assignedRow->type, $assignedRow->release, $assignedRow->priority,
                $assignedRow->assigneeId?->toString(), $assignedRow->assigneeName, $assignedRow->turnUserId?->toString(),
                $assignedRow->turnUserFirstName, $assignedRow->updatedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'), $assignedRow->stepId,
            ],
        );
        self::assertSame([$unassigned, null, null, null, null, null], [
            $unassignedRow->key, $unassignedRow->release, $unassignedRow->assigneeId, $unassignedRow->assigneeName, $unassignedRow->turnUserId, $unassignedRow->turnUserFirstName,
        ]);
    }

    #[Test]
    public function anOrganizationWithNoTicketGetsNoGroupInOneQuery(): void
    {
        $this->addTicket(ShopTeamRows::TO_DO_STEP, 2, '2027-03-15 10:00:00+00');
        $debugData = $this->debugData();

        [$groups, $queryCount] = $this->transactions()->rolledBackAs(self::BETA_BAKERY, function () use ($debugData): array {
            $debugData->reset();

            return [$this->ticketQueries()->list(new ListFilter(self::adminOf(self::BETA_BAKERY))), self::queryCountOf($debugData)];
        });

        self::assertSame([[], 1], [$groups, $queryCount]);
    }

    /**
     * The SQL names the organization of TenantContext, while the transaction's tenant, which row-level security uses,
     * is another one: what still hides Acme Foods' tickets from Beta Bakery's transaction is row-level security alone.
     * The steps are Acme Foods', read under Acme Foods, so the ticket query runs on them.
     */
    #[Test]
    public function rowLevelSecurityAloneHidesAnotherOrganizationsTickets(): void
    {
        $this->addTicket(ShopTeamRows::TO_DO_STEP, 2, '2027-03-15 10:00:00+00');
        self::assertNotSame([], $this->listedAs(self::ACME_FOODS, $this->filter()));
        $acmeStepQueries = self::createStub(StepQueries::class);
        $acmeStepQueries->method('listedSteps')->willReturn($this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->stepQueries()->listedSteps()));

        $groupsUnderAcmeContext = new OwnConnectionTransactions($this->entityManager()->getConnection(), $this->tenantContext())->rolledBackUnderAnotherContext(
            self::BETA_BAKERY,
            self::ACME_FOODS,
            fn (Connection $connection): array => new DbalTicketQueries(
                $connection,
                $this->tenantContext(),
                $acmeStepQueries,
                new DbalTurnUserQueries($connection, $this->tenantContext()),
                new DbalUserQueries($connection),
            )->list($this->filter()),
        );

        self::assertSame([], $groupsUnderAcmeContext);
    }

    /**
     * The description-only hit is a blocker updated last, so the priority order would list it first; the search order
     * puts the title hit first. The ticket without the word is listed without a search and left out with one.
     */
    #[Test]
    public function aSearchListsOnlyMatchesEachStepInSearchOrderWithATitleHitAboveADescriptionOnlyHit(): void
    {
        $titleHit = $this->addSearchableTicket(ShopTeamRows::TO_DO_STEP, 3, '2027-03-15 09:00:00+00', 'Refund form drops the postcode', '');
        $descriptionOnlyHit = $this->addSearchableTicket(ShopTeamRows::TO_DO_STEP, 0, '2027-03-15 11:00:00+00', 'Gift note is cut short', 'The postcode field is empty after paying.');
        $noHit = $this->addSearchableTicket(ShopTeamRows::TO_DO_STEP, 1, '2027-03-15 10:00:00+00', 'Cart total shows the wrong sum', '');
        $doingHit = $this->addSearchableTicket(self::DOING_STEP, 2, '2027-03-15 10:00:00+00', 'Postcode lookup is slow', '');
        self::assertSame([$descriptionOnlyHit, $noHit, $titleHit], self::keysOf($this->listedAs(self::ACME_FOODS, $this->filter())[1]->steps[0]));

        $groups = $this->listedAs(self::ACME_FOODS, $this->filter(searchText: 'postcode'));

        self::assertSame(
            [['doing', [$doingHit], 1], ['todo', [$titleHit, $descriptionOnlyHit], 2]],
            array_map(static fn (TicketListGroup $group): array => [$group->code, self::keysOf($group->steps[0]), $group->steps[0]->ticketCount], $groups),
        );
        self::assertSame([], $this->listedAs(self::ACME_FOODS, $this->filter(searchText: 'nothing like this')));
    }

    /**
     * 52 equal matches tie on the rank, so the function orders them by id, highest first; the best match is a minor
     * ticket updated first, which the priority order would put last. The cursor carries the 50th row's match position
     * and the next page goes on from there, the step still counting every match.
     */
    #[Test]
    public function aSearchCursorContinuesTheStepInSearchOrder(): void
    {
        $ties = $this->addTickets(ShopTeamRows::TO_DO_STEP, 52);
        $bestMatch = $this->addSearchableTicket(ShopTeamRows::TO_DO_STEP, 4, '2027-03-15 07:00:00+00', 'Checkout', '');
        $this->addSearchableTicket(ShopTeamRows::TO_DO_STEP, 0, '2027-03-15 12:00:00+00', 'Cart total shows the wrong sum', '');
        $tiesHighestIdFirst = array_reverse($ties);

        $firstPage = $this->listedAs(self::ACME_FOODS, $this->filter(searchText: 'checkout'))[0]->steps[0];
        $cursor = $firstPage->nextCursor;
        self::assertNotNull($cursor);
        $nextPage = $this->listedAs(self::ACME_FOODS, $this->filter(searchText: 'checkout'), $cursor)[0]->steps[0];

        self::assertSame([53, [$bestMatch, ...\array_slice($tiesHighestIdFirst, 0, 49)]], [$firstPage->ticketCount, self::keysOf($firstPage)]);
        self::assertSame($firstPage->rows[49]->id->toString(), $cursor->ticketId->toString());
        self::assertFalse($cursor->searchOrderValues()[0] ?? true);
        self::assertSame([53, \array_slice($tiesHighestIdFirst, 49), false], [$nextPage->ticketCount, self::keysOf($nextPage), $nextPage->hasMore()]);
    }

    /**
     * Between the first page and "Show more" a better match is added and one of the next page's ties stops matching.
     * A cursor by place among the matches would then repeat the first page's last row; one by search order values goes
     * on right after it, so each original match still shows exactly once and the one that stopped matching not at all.
     */
    #[Test]
    public function aSearchCursorNeitherRepeatsNorSkipsARowWhenTheMatchesChangeBetweenPages(): void
    {
        $ties = $this->addTickets(ShopTeamRows::TO_DO_STEP, 55);
        $tiesHighestIdFirst = array_reverse($ties);
        $firstPage = $this->listedAs(self::ACME_FOODS, $this->filter(searchText: 'checkout'))[0]->steps[0];
        $cursor = $firstPage->nextCursor;
        self::assertNotNull($cursor);
        self::assertSame(\array_slice($tiesHighestIdFirst, 0, 50), self::keysOf($firstPage));

        $this->addSearchableTicket(ShopTeamRows::TO_DO_STEP, 2, '2027-03-15 07:00:00+00', 'Checkout', '');
        $stoppedMatching = $tiesHighestIdFirst[52];
        $this->asTenantMigrator(self::ACME_FOODS, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "UPDATE tkt_ticket SET title = 'Cart total shows the wrong sum' WHERE key = ?",
            [$stoppedMatching],
        ));
        $nextPage = $this->listedAs(self::ACME_FOODS, $this->filter(searchText: 'checkout'), $cursor)[0]->steps[0];

        self::assertSame([$tiesHighestIdFirst[50], $tiesHighestIdFirst[51], $tiesHighestIdFirst[53], $tiesHighestIdFirst[54]], self::keysOf($nextPage));
        self::assertSame([55, false], [$nextPage->ticketCount, $nextPage->hasMore()]);
    }

    /**
     * Done and the Ops project hold more matches than the cap, all with higher ids than the three open Shop matches,
     * so a cap taken before the list's filters would fill up with them and list no Shop match at all.
     */
    #[Test]
    public function theSearchCapComesAfterTheListsProjectAndDoneFilters(): void
    {
        $openShopMatches = $this->addTickets(ShopTeamRows::TO_DO_STEP, 3);
        new TicketRows($this->migrator(), $this->tenantContext())->addKeyedProjectOf(self::ACME_FOODS, self::OPS_PROJECT, null, 'OPS', 1);
        $this->addManyTickets(self::DONE_STEP, TicketListStep::COUNT_CAP + 1);
        $this->addManyTickets(ShopTeamRows::TO_DO_STEP, TicketListStep::COUNT_CAP + 1, self::OPS_PROJECT);

        $groups = $this->listedAs(self::ACME_FOODS, $this->filter(self::SHOP_PROJECT, searchText: 'checkout'));

        self::assertSame([['todo', array_reverse($openShopMatches), 3, false]], array_map(
            static fn (TicketListGroup $group): array => [$group->code, self::keysOf($group->steps[0]), $group->steps[0]->ticketCount, $group->steps[0]->isCountCapped],
            $groups,
        ));
    }

    /**
     * Omar's tickets sit beside Lena's and an unassigned one in the same step; one of Omar's does not match the search.
     */
    #[Test]
    public function anAssigneeKeepsOnlyTheirTicketsWithAndWithoutASearch(): void
    {
        $omarsMatch = $this->addSearchableTicket(ShopTeamRows::TO_DO_STEP, 2, '2027-03-15 09:00:00+00', 'Checkout drops the postcode', '');
        $omarsOther = $this->addSearchableTicket(ShopTeamRows::TO_DO_STEP, 1, '2027-03-15 10:00:00+00', 'Cart total shows the wrong sum', '');
        $lenasMatch = $this->addSearchableTicket(ShopTeamRows::TO_DO_STEP, 0, '2027-03-15 11:00:00+00', 'Checkout is slow', '');
        $unassignedMatch = $this->addSearchableTicket(ShopTeamRows::TO_DO_STEP, 0, '2027-03-15 12:00:00+00', 'Checkout button is grey', '');
        $this->assign([$omarsMatch, $omarsOther], self::OMAR);
        $this->assign([$lenasMatch], self::LENA);
        self::assertSame([[$unassignedMatch, $lenasMatch, $omarsOther, $omarsMatch], 4], self::keysAndCountOf($this->listedAs(self::ACME_FOODS, $this->filter())));

        self::assertSame([[$omarsOther, $omarsMatch], 2], self::keysAndCountOf($this->listedAs(self::ACME_FOODS, $this->filter(assigneeId: self::OMAR))));
        self::assertSame([[$omarsMatch], 1], self::keysAndCountOf($this->listedAs(self::ACME_FOODS, $this->filter(searchText: 'checkout', assigneeId: self::OMAR))));
        self::assertSame([[], 0], self::keysAndCountOf($this->listedAs(self::ACME_FOODS, $this->filter(searchText: 'checkout', assigneeId: self::SARA))));
    }

    /**
     * Lena holds more matches than the cap, all with higher ids than Omar's three, so a cap taken before the assignee
     * would fill up with hers and list none of Omar's.
     */
    #[Test]
    public function theSearchCapComesAfterTheAssignee(): void
    {
        $omarsMatches = $this->addTickets(ShopTeamRows::TO_DO_STEP, 3);
        $this->assign($omarsMatches, self::OMAR);
        $this->addManyTickets(ShopTeamRows::TO_DO_STEP, TicketListStep::COUNT_CAP + 1);
        $this->asTenantMigrator(self::ACME_FOODS, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            'UPDATE tkt_ticket SET assignee_id = CAST(? AS uuid) WHERE assignee_id IS NULL',
            [self::LENA],
        ));
        self::assertSame([[TicketListStep::COUNT_CAP, true]], array_map(
            static fn (TicketListGroup $group): array => [$group->steps[0]->ticketCount, $group->steps[0]->isCountCapped],
            $this->listedAs(self::ACME_FOODS, $this->filter(searchText: 'checkout')),
        ));

        $groups = $this->listedAs(self::ACME_FOODS, $this->filter(searchText: 'checkout', assigneeId: self::OMAR));

        self::assertSame([['todo', array_reverse($omarsMatches), 3, false]], array_map(
            static fn (TicketListGroup $group): array => [$group->code, self::keysOf($group->steps[0]), $group->steps[0]->ticketCount, $group->steps[0]->isCountCapped],
            $groups,
        ));
    }

    /**
     * 1,002 matches: the function's best 1,000 by id are the two Doing matches and 998 of To do's. Doing's count is
     * marked capped too, as more of its matches could lie past the cap.
     */
    #[Test]
    public function pastTheSearchCapEveryStepsCountIsMarkedCapped(): void
    {
        $this->addManyTickets(ShopTeamRows::TO_DO_STEP, TicketListStep::COUNT_CAP);
        $doingMatches = $this->addTickets(self::DOING_STEP, 2);

        $groups = $this->listedAs(self::ACME_FOODS, $this->filter(searchText: 'checkout'));

        self::assertSame([['doing', 2, true], ['todo', 998, true]], self::codesCountsAndCapsOf($groups));
        self::assertSame(array_reverse($doingMatches), self::keysOf($groups[0]->steps[0]));
    }

    #[Test]
    public function exactlyAtTheSearchCapNoCountIsMarkedCapped(): void
    {
        $this->addManyTickets(ShopTeamRows::TO_DO_STEP, TicketListStep::COUNT_CAP - 2);
        $this->addTickets(self::DOING_STEP, 2);

        $groups = $this->listedAs(self::ACME_FOODS, $this->filter(searchText: 'checkout'));

        self::assertSame([['doing', 2, false], ['todo', 998, false]], self::codesCountsAndCapsOf($groups));
    }

    /**
     * @param list<TicketListGroup> $groups
     *
     * @return list<array{string, int, bool}> each group's code with its first step's count and whether it is capped
     */
    private static function codesCountsAndCapsOf(array $groups): array
    {
        return array_map(static fn (TicketListGroup $group): array => [$group->code, $group->steps[0]->ticketCount, $group->steps[0]->isCountCapped], $groups);
    }

    /**
     * Omar, outside the Shop team, lists only the match for everyone; Sara, an admin, lists the team-only match too,
     * so the search finds it and the visibility keeps it from Omar.
     */
    #[Test]
    public function aSearchListsOnlyTheMatchesTheViewerMaySee(): void
    {
        $teamOnly = $this->addSearchableTicket(ShopTeamRows::TO_DO_STEP, 2, '2027-03-15 10:00:00+00', 'Postcode rules for staff', '');
        $forEveryone = $this->addSearchableTicket(ShopTeamRows::TO_DO_STEP, 2, '2027-03-15 11:00:00+00', 'Postcode is cut short', '');
        $this->asTenantMigrator(self::ACME_FOODS, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "UPDATE tkt_ticket SET visibility = 'team', team_id = ? WHERE key = ?",
            [self::SHOP_TEAM, $teamOnly],
        ));
        $omar = TicketVisibility::for(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR), [], false, null);

        [$adminKeys, $adminCount] = self::keysAndCountOf($this->listedAs(self::ACME_FOODS, $this->filter(searchText: 'postcode')));

        self::assertSame([[$forEveryone], 1], self::keysAndCountOf($this->listedAs(self::ACME_FOODS, new ListFilter($omar, searchText: 'postcode'))));
        self::assertEqualsCanonicalizing([$forEveryone, $teamOnly], $adminKeys);
        self::assertSame(2, $adminCount);
    }

    /**
     * @return iterable<string, array{bool, bool}>
     */
    public static function cursorsThatDoNotFitTheSearch(): iterable
    {
        yield 'a search cursor without a search' => [false, true];
        yield 'a priority cursor with a search' => [true, false];
    }

    #[Test]
    #[DataProvider('cursorsThatDoNotFitTheSearch')]
    public function aCursorThatDoesNotFitTheSearchIsRefusedBeforeAnyQuery(bool $hasSearch, bool $hasMatchPosition): void
    {
        $this->addTicket(ShopTeamRows::TO_DO_STEP, 2, '2027-03-15 10:00:00+00');
        $cursor = new Cursor(ShopTeamRows::TO_DO_STEP, 2, new \DateTimeImmutable('2027-03-15 10:00:00 UTC'), new TicketId('0197a3c0-0000-7000-8000-000000710001'), $hasMatchPosition ? Cursor::matchPositionOf(false, '0.100000000000') : null);
        $debugData = $this->debugData();

        $refusal = $this->transactions()->rolledBackAs(self::ACME_FOODS, function () use ($debugData, $cursor, $hasSearch): array {
            $debugData->reset();
            try {
                $this->ticketQueries()->list($this->filter(searchText: $hasSearch ? 'checkout' : null), $cursor);

                return ['listed', 0];
            } catch (\InvalidArgumentException $refusal) {
                return [$refusal->getMessage(), self::queryCountOf($debugData)];
            }
        });

        self::assertSame(['A cursor has a match position exactly when the list has a search.', 0], $refusal);
    }

    #[Test]
    public function aSearchListReadsAsManyQueriesAsTheListWithout(): void
    {
        $this->addTickets(ShopTeamRows::TO_DO_STEP, 3);
        $debugData = $this->debugData();

        $queryCounts = $this->transactions()->rolledBackAs(self::ACME_FOODS, function () use ($debugData): array {
            $this->ticketQueries()->list($this->filter());
            $debugData->reset();
            $this->ticketQueries()->list($this->filter());
            $withoutSearch = self::queryCountOf($debugData);
            $debugData->reset();
            $groups = $this->ticketQueries()->list($this->filter(searchText: 'checkout'));
            self::assertCount(3, $groups[0]->steps[0]->rows);

            return [$withoutSearch, self::queryCountOf($debugData)];
        });

        // The rows and the turn users; the steps are cached by the first call, and no row has a person to name.
        self::assertSame([2, 2], $queryCounts);
    }

    #[Test]
    public function withNoTransactionItRefusesBeforeAnyQuery(): void
    {
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        $debugData = $this->debugData();
        $debugData->reset();

        try {
            $this->ticketQueries()->list($this->filter());
            self::fail('The list was read with no transaction.');
        } catch (\LogicException $refusal) {
            self::assertSame(
                ['The tickets list is read in a transaction under the organization as tenant; none is open.', 0],
                [$refusal->getMessage(), self::queryCountOf($debugData)],
            );
        }
    }

    #[Test]
    public function aViewerOfAnotherOrganizationIsRefusedBeforeAnyQuery(): void
    {
        $debugData = $this->debugData();

        $refusal = $this->transactions()->rolledBackAs(self::BETA_BAKERY, function () use ($debugData): array {
            $debugData->reset();
            try {
                $this->ticketQueries()->list($this->filter());

                return ['listed', 0];
            } catch (\LogicException $refusal) {
                return [$refusal->getMessage(), self::queryCountOf($debugData)];
            }
        });

        self::assertSame(['The tickets list is read under the viewer\'s own organization as tenant.', 0], $refusal);
    }

    private function filter(?string $projectId = null, bool $showsDone = false, ?string $searchText = null, ?string $assigneeId = null): ListFilter
    {
        return new ListFilter(self::adminOf(self::ACME_FOODS), $projectId, $showsDone, $searchText, $assigneeId);
    }

    /**
     * @param list<string> $keys
     */
    private function assign(array $keys, string $assigneeId): void
    {
        $this->asTenantMigrator(self::ACME_FOODS, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            'UPDATE tkt_ticket SET assignee_id = CAST(? AS uuid) WHERE key = ANY(CAST(? AS text[]))',
            [$assigneeId, '{'.implode(',', $keys).'}'],
        ));
    }

    /**
     * @param list<TicketListGroup> $groups
     *
     * @return array{list<string>, int} the keys of every listed row and the sum of the step counts
     */
    private static function keysAndCountOf(array $groups): array
    {
        $keys = [];
        $ticketCount = 0;
        foreach ($groups as $group) {
            foreach ($group->steps as $step) {
                $keys = [...$keys, ...self::keysOf($step)];
                $ticketCount += $step->ticketCount;
            }
        }

        return [$keys, $ticketCount];
    }

    /**
     * Like addTicket, with that title and description.
     *
     * @return string the ticket's key
     */
    private function addSearchableTicket(string $stepId, int $priorityRank, string $updatedAt, string $title, string $description): string
    {
        $key = $this->addTicket($stepId, $priorityRank, $updatedAt);
        $this->asTenantMigrator(self::ACME_FOODS, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            'UPDATE tkt_ticket SET title = ?, description = ? WHERE key = ?',
            [$title, $description, $key],
        ));

        return $key;
    }

    private static function adminOf(string $organizationId): TicketVisibility
    {
        return TicketVisibility::for(new OrganizationId($organizationId), new UserId(self::SARA), [], true, null);
    }

    /**
     * @return list<TicketListGroup>
     */
    private function listedAs(string $organizationId, ListFilter $filter, ?Cursor $cursor = null): array
    {
        return $this->transactions()->rolledBackAs($organizationId, fn (): array => $this->ticketQueries()->list($filter, $cursor));
    }

    /**
     * @param list<TicketListGroup> $groups
     *
     * @return list<array{string, string, string|null, list<array{string, bool}>}>
     */
    private static function shapeOf(array $groups): array
    {
        return array_map(static fn (TicketListGroup $group): array => [
            $group->code,
            $group->label,
            $group->hint,
            array_map(static fn (TicketListStep $step): array => [$step->name, $step->isArchived], $group->steps),
        ], $groups);
    }

    /**
     * @return list<string>
     */
    private static function keysOf(TicketListStep $step): array
    {
        return array_map(static fn (TicketListRow $row): string => $row->key, $step->rows);
    }

    /**
     * A bug of the priority with that rank, its id rising with its number, so a later ticket has the higher id.
     *
     * @return string the ticket's key
     */
    private function addTicket(string $stepId, int $priorityRank, string $updatedAt, string $projectId = self::SHOP_PROJECT): string
    {
        $number = $this->nextNumber++;
        $key = 'SHOP-'.$number;
        $this->asTenantMigrator(self::ACME_FOODS, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO tkt_ticket (organization_id, id, project_id, number, key, title, description, type, priority, priority_rank,
                 step_id, reporter_id, visibility, components, check_reasons, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 'Checkout button is hidden', '', 'bug', ?, ?, ?, ?, 'all', '{}', '{}', '2027-03-15 08:00:00+00', ?)",
            [
                self::ACME_FOODS, \sprintf('0197a3c0-0000-7000-8000-%012x', 0x710000 + $number), $projectId, $number, $key,
                ['blocker', 'critical', 'major', 'normal', 'minor'][$priorityRank], $priorityRank, $stepId, self::LENA, $updatedAt,
            ],
        ));

        return $key;
    }

    /**
     * Normal tickets a minute apart, the first the oldest.
     *
     * @return list<string> their keys, oldest first
     */
    private function addTickets(string $stepId, int $count): array
    {
        $keys = [];
        for ($index = 0; $index < $count; ++$index) {
            $keys[] = $this->addTicket($stepId, 3, new \DateTimeImmutable('2027-03-15 08:00:00 UTC')->modify(\sprintf('+%d minutes', $index))->format('Y-m-d H:i:sP'));
        }

        return $keys;
    }

    /**
     * Normal tickets in one statement, as a thousand inserts one by one would be slow; their order does not matter.
     */
    private function addManyTickets(string $stepId, int $count, string $projectId = self::SHOP_PROJECT): void
    {
        $firstNumber = $this->nextNumber;
        $this->nextNumber += $count;
        $this->asTenantMigrator(self::ACME_FOODS, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO tkt_ticket (organization_id, id, project_id, number, key, title, description, type, priority, priority_rank,
                 step_id, reporter_id, visibility, components, check_reasons, created_at, updated_at)
             SELECT CAST(? AS uuid), CAST('0197a3c0-0000-7000-8000-' || lpad(to_hex(7405568 + number), 12, '0') AS uuid), CAST(? AS uuid),
                 number, 'SHOP-' || number, 'Checkout button is hidden', '', 'bug', 'normal', 3, CAST(? AS uuid), CAST(? AS uuid), 'all',
                 '{}', '{}', TIMESTAMPTZ '2027-03-15 08:00:00+00', TIMESTAMPTZ '2027-03-15 08:00:00+00' + number * INTERVAL '1 minute'
             FROM generate_series(CAST(? AS int), CAST(? AS int)) AS number",
            [self::ACME_FOODS, $projectId, $stepId, self::LENA, $firstNumber, $firstNumber + $count - 1],
        ));
    }

    private static function queryCountOf(DebugDataHolder $debugData): int
    {
        $queryCount = 0;
        foreach ($debugData->getData() as $queriesOfOneConnection) {
            self::assertIsArray($queriesOfOneConnection);
            $queryCount += \count($queriesOfOneConnection);
        }

        return $queryCount;
    }

    /**
     * @param \Closure(Connection): mixed $work
     */
    private function asTenantMigrator(string $organizationId, \Closure $work): void
    {
        $this->tenantContext()->set(new OrganizationId($organizationId));
        try {
            $this->migrator()->transactional($work);
        } finally {
            $this->tenantContext()->clear();
        }
    }

    private function ticketQueries(): TicketQueries
    {
        $ticketQueries = self::getContainer()->get('test.ticketing_ticket_queries');
        self::assertInstanceOf(TicketQueries::class, $ticketQueries);

        return $ticketQueries;
    }

    private function stepQueries(): StepQueries
    {
        $stepQueries = self::getContainer()->get('test.workflow_step_queries');
        self::assertInstanceOf(StepQueries::class, $stepQueries);

        return $stepQueries;
    }

    private function debugData(): DebugDataHolder
    {
        return self::getContainer()->get('doctrine.debug_data_holder');
    }

    private function removeRows(): void
    {
        $this->shopTeamRows()->removeAllOf([self::LENA, self::OMAR], self::ACME_FOODS, self::BETA_BAKERY);
    }

    private function shopTeamRows(): ShopTeamRows
    {
        return new ShopTeamRows($this->migrator(), $this->tenantContext());
    }

    private function migrator(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.migrator_connection');
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), $this->tenantContext());
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }
}
