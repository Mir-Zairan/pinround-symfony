<?php

declare(strict_types=1);

namespace App\Tests\Integration\Visibility;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantNotSet;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\AssigneeTicketCounts;
use App\Ticketing\Api\TicketVisibilities;
use App\Ticketing\Api\TicketVisibility;
use App\Web\UI\Component\PersonPickerMode;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * The person picker's "N open" (AssigneeTicketCounts), read as the picker does: the viewer's visibility from
 * TicketVisibilities::of() under their own organization as tenant. Both cast tickets are assigned to Noor, so her count
 * is exactly the tickets the viewer may see; the no-team ticket's step is added as a To do step, so both are open.
 * The assign picker's "N waiting" (WaitingTurnCounts) is read through the rendered picker: the team-only ticket's turn
 * is Lena's.
 */
final class PersonPickerCountsLeakTest extends VisibilityLeakCast
{
    use InteractsWithTwigComponents;

    /** The step the cast's no-team ticket is in, which the cast itself does not add. */
    private const string NO_TEAM_TICKET_STEP = '0197a3c0-0000-7000-8000-00000000f0d0';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->set(new OrganizationId(self::ACME_FOODS));
        try {
            self::migrator()->transactional(static function (Connection $tenantMigrator): void {
                $tenantMigrator->executeStatement(
                    "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, 'Triage', 'todo', NULL, 'company', 0)",
                    [self::ACME_FOODS, self::NO_TEAM_TICKET_STEP],
                );
                $tenantMigrator->executeStatement(
                    'UPDATE tkt_ticket SET assignee_id = ? WHERE id IN (?, ?)',
                    [self::VIEWERS['Noor'][0], self::teamOnlyTicketId(), self::NO_TEAM_TICKET],
                );
                $updated = $tenantMigrator->executeStatement(
                    "UPDATE ins_ticket_turn SET turn_user_id = ?, turn_label = NULL, kind = 'build', grp = 'doing', waiting_since = '2027-03-01 09:00:00+00' WHERE ticket_id = ?",
                    [self::VIEWERS['Lena'][0], self::teamOnlyTicketId()],
                );
                if (1 !== $updated) {
                    throw new \LogicException('The cast\'s team-only ticket has no turn row to wait on Lena.');
                }
            });
        } finally {
            $tenantContext->clear();
        }
        self::ensureKernelShutdown();
    }

    /**
     * Noor sees both as their assignee, so she is left out: her count is not a check of the predicate.
     *
     * @return iterable<string, array{string, bool, bool}>
     */
    public static function viewersOtherThanTheAssignee(): iterable
    {
        foreach (self::viewers() as $case => $viewer) {
            if ('Noor' !== $viewer[0]) {
                yield $case => $viewer;
            }
        }
    }

    #[Test]
    #[DataProvider('viewersOtherThanTheAssignee')]
    public function theCountNeverIncludesATicketTheViewerMayNotSee(string $viewer, bool $seesTeamOnly, bool $seesNoTeamTicket): void
    {
        $expectedCount = (int) $seesTeamOnly + (int) $seesNoTeamTicket;

        $counts = self::readCountsAs($viewer);

        self::assertSame([self::VIEWERS['Noor'][0] => $expectedCount, self::VIEWERS['Lena'][0] => 0], $counts, \sprintf('%s counts the wrong tickets.', $viewer));
    }

    /**
     * The expectations written out, so a change to the provider cannot hide one: an admin counts both, the triager
     * only the no-team one, the outside reporter only hers, an outsider and another organization's admin none.
     */
    #[Test]
    public function eachViewerCountsExactlyTheTicketsTheirRoleLetsThemSee(): void
    {
        $noor = self::VIEWERS['Noor'][0];

        self::assertSame(
            [2, 1, 1, 1, 0, 0],
            [self::readCountsAs('Sara')[$noor], self::readCountsAs('Omar')[$noor], self::readCountsAs('Mia')[$noor], self::readCountsAs('Lena')[$noor], self::readCountsAs('Raj')[$noor], self::readCountsAs('Ben')[$noor]],
        );
    }

    /**
     * Each Acme Foods viewer and whether they may see the team-only ticket. Ben is of another organization, whose
     * tenant holds none of the team's people, so he is left out.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function acmeFoodsViewers(): iterable
    {
        foreach (self::viewers() as $case => [$viewer, $seesTeamOnly]) {
            if ('Ben' !== $viewer) {
                yield $case => [$viewer, $seesTeamOnly];
            }
        }
    }

    /**
     * The "Give it to…" picker as each viewer's request renders it: the team-only ticket waits on Lena, the team's only
     * person, so her "N waiting" is exactly whether the viewer may see that ticket.
     */
    #[Test]
    #[DataProvider('acmeFoodsViewers')]
    public function theAssignPickersWaitingCountNeverIncludesATicketTheViewerMayNotSee(string $viewer, bool $seesTeamOnly): void
    {
        $rows = self::asViewer($viewer, function (TokenInterface $token): array {
            $tokenStorage = self::getContainer()->get('security.token_storage');
            $tokenStorage->setToken($token);
            try {
                $picker = new Crawler((string) $this->renderTwigComponent('PersonPickerSearch', [
                    'mode' => PersonPickerMode::Assign,
                    'team' => self::SHOP_TEAM,
                    'pickForm' => 'give-it-to-form',
                    'pickField' => 'assignee',
                    'isOpened' => true,
                ]));
            } finally {
                $tokenStorage->setToken(null);
            }

            return $picker->filter('.pick')->each(static fn (Crawler $row): array => [$row->filter('.pick__name')->text(), $row->filter('.pick__side')->text()]);
        });

        self::assertSame([['Lena', $seesTeamOnly ? '1 waiting' : '0 waiting']], $rows, \sprintf('%s counts the wrong tickets.', $viewer));
    }

    #[Test]
    public function noPersonIsCountedWithoutAQuery(): void
    {
        self::assertSame([], self::asViewer('Lena', static fn (): array => self::assigneeTicketCounts()->openTicketsAssignedTo(
            self::ticketVisibilities()->of(new OrganizationId(self::ACME_FOODS), new UserId(self::VIEWERS['Lena'][0])),
            [],
            null,
        )));
    }

    /**
     * Noor's two tickets are in two projects: a project's count holds only its own ticket, as the list of that project
     * filtered by her shows.
     */
    #[Test]
    public function aProjectsCountHoldsOnlyThatProjectsTickets(): void
    {
        $noor = self::VIEWERS['Noor'][0];

        self::assertSame(
            [2, 1, 1],
            [self::readCountsAs('Sara')[$noor], self::readCountsAs('Sara', self::SHOP_PROJECT)[$noor], self::readCountsAs('Sara', self::NO_TEAM_PROJECT)[$noor]],
        );
        self::assertSame(0, self::readCountsAs('Omar', self::SHOP_PROJECT)[$noor], 'The triager may not see the team-only ticket of SHOP.');
    }

    #[Test]
    public function aCountOutsideATransactionIsRefusedBeforeAnyQuery(): void
    {
        $lena = self::visibilityOf('Lena');
        self::tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        try {
            self::assertFalse(self::connection()->isTransactionActive());
            $this->assertRefusedBeforeAnyQuery(
                static fn (): array => self::assigneeTicketCounts()->openTicketsAssignedTo($lena, [new UserId(self::VIEWERS['Noor'][0])], null),
                \LogicException::class,
                'Open tickets are counted in a transaction under the organization as tenant; none is open.',
            );
        } finally {
            self::tenantContext()->clear();
        }
    }

    #[Test]
    public function aCountWithoutATenantIsRefusedBeforeAnyQuery(): void
    {
        $lena = self::visibilityOf('Lena');
        self::connection()->beginTransaction();
        try {
            self::assertNull(self::tenantContext()->get());
            $this->assertRefusedBeforeAnyQuery(
                static fn (): array => self::assigneeTicketCounts()->openTicketsAssignedTo($lena, [new UserId(self::VIEWERS['Noor'][0])], null),
                TenantNotSet::class,
                'No organization is set for this unit of work.',
            );
        } finally {
            self::connection()->rollBack();
        }
    }

    /**
     * Lena's visibility, read under Acme Foods, used while Beta Bakery is the tenant: the tenant's rows are not hers to
     * count by.
     */
    #[Test]
    public function aCountForAViewerOfAnotherOrganizationThanTheTenantIsRefusedBeforeAnyQuery(): void
    {
        $lena = self::visibilityOf('Lena');

        self::asViewer('Ben', function () use ($lena): void {
            $this->assertRefusedBeforeAnyQuery(
                static fn (): array => self::assigneeTicketCounts()->openTicketsAssignedTo($lena, [new UserId(self::VIEWERS['Noor'][0])], null),
                \LogicException::class,
                'Open tickets are counted under the viewer\'s own organization as tenant.',
            );
        });
    }

    #[Test]
    public function aProjectIdThatIsNoUuidIsRefusedBeforeAnyQuery(): void
    {
        self::asViewer('Lena', function (): void {
            $lena = self::visibilityOf('Lena');
            $this->assertRefusedBeforeAnyQuery(
                static fn (): array => self::assigneeTicketCounts()->openTicketsAssignedTo($lena, [new UserId(self::VIEWERS['Noor'][0])], 'SHOP'),
                \InvalidArgumentException::class,
                'Open tickets are counted in a project given by its canonical lowercase uuid.',
            );
        });
    }

    /**
     * @param \Closure(): array<string, int> $count
     * @param class-string<\Throwable>       $refusal
     */
    private function assertRefusedBeforeAnyQuery(\Closure $count, string $refusal, string $message): void
    {
        $debugData = self::getContainer()->get('doctrine.debug_data_holder');
        $debugData->reset();
        try {
            $count();
            self::fail('The count must be refused.');
        } catch (\LogicException|\InvalidArgumentException $exception) {
            self::assertSame([$refusal, $message], [$exception::class, $exception->getMessage()]);
        }
        self::assertSame([], array_merge(...array_values($debugData->getData())), 'No query ran before the refusal.');
    }

    /**
     * @return array<string, int> Noor's and Lena's open counts, as the viewer reads them in a request
     */
    private static function readCountsAs(string $viewer, ?string $projectId = null): array
    {
        return self::asViewer($viewer, static fn (): array => self::assigneeTicketCounts()->openTicketsAssignedTo(
            self::visibilityOf($viewer),
            [new UserId(self::VIEWERS['Noor'][0]), new UserId(self::VIEWERS['Lena'][0])],
            $projectId,
        ));
    }

    /**
     * The viewer's visibility as TicketVisibilities::of() reads it under their own organization.
     */
    private static function visibilityOf(string $viewer): TicketVisibility
    {
        [$userId, $organizationId] = self::VIEWERS[$viewer];
        if (self::connection()->isTransactionActive()) {
            return self::ticketVisibilities()->of(new OrganizationId($organizationId), new UserId($userId));
        }

        return self::asViewer($viewer, static fn (): TicketVisibility => self::ticketVisibilities()->of(new OrganizationId($organizationId), new UserId($userId)));
    }

    private static function assigneeTicketCounts(): AssigneeTicketCounts
    {
        $counts = self::getContainer()->get('test.ticketing_assignee_ticket_counts');
        \assert($counts instanceof AssigneeTicketCounts);

        return $counts;
    }

    private static function ticketVisibilities(): TicketVisibilities
    {
        $ticketVisibilities = self::getContainer()->get('test.ticketing_ticket_visibilities');
        \assert($ticketVisibilities instanceof TicketVisibilities);

        return $ticketVisibilities;
    }
}
