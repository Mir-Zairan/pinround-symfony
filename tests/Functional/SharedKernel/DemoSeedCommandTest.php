<?php

declare(strict_types=1);

namespace App\Tests\Functional\SharedKernel;

use App\Identity\Domain\Email;
use App\Organization\Api\DemoOrganizations;
use App\Organization\Infrastructure\Demo\SeedDemoOrganizations;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantSwitchRefused;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Infrastructure\Console\DemoSeedCommand;
use App\SharedKernel\Infrastructure\Console\DemoSeedRefused;
use App\Tests\Factory\Identity\UserFactory;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Ticketing\ProjectRows;
use App\Tests\Integration\Ticketing\TicketRows;
use App\Ticketing\Infrastructure\Demo\SeedDemoProjects;
use App\Ticketing\Infrastructure\Demo\SeedDemoTickets;
use App\Workflow\Domain\CoreStep;
use App\Workflow\Domain\RuleSet;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\Attribute\When;
use Zenstruck\Foundry\Test\Factories;

/**
 * Runs app:demo:seed on the test database. The organization step sets the tenant, which needs no transaction to be
 * open, so the seed commits and each test removes the demo rows it made.
 */
final class DemoSeedCommandTest extends KernelTestCase
{
    use Factories;

    private const string PASSWORD = 'a-demo-password-for-tests';

    private const array EMAILS = [
        'ben@beta-bakery.example',
        'lena@acme-foods.example',
        'mia@acme-foods.example',
        'omar@acme-foods.example',
        'raj@acme-foods.example',
        'sara@acme-foods.example',
    ];

    private string|false $passwordBefore;

    protected function setUp(): void
    {
        $this->passwordBefore = getenv('DEMO_PASSWORD');
        self::bootKernel();
        $this->removeDemoRows();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (!self::$booted) {
            self::bootKernel();
        }
        $this->removeDemoRows();
        false === $this->passwordBefore ? putenv('DEMO_PASSWORD') : putenv('DEMO_PASSWORD='.$this->passwordBefore);
        parent::tearDown();
    }

    #[Test]
    public function itCreatesTheSixPeopleWithACredentialThatVerifiesAgainstDemoPassword(): void
    {
        $this->startWithPassword(self::PASSWORD);

        $tester = $this->runSeed();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(self::EMAILS, $this->emails());
        $hashes = $this->credentialHashes();
        self::assertCount(6, $hashes);
        foreach ($hashes as $hash) {
            self::assertIsString($hash);
            self::assertTrue(password_verify(self::PASSWORD, $hash));
            self::assertStringStartsWith('$argon2id$', $hash);
        }
        self::assertStringNotContainsString(self::PASSWORD, $tester->getDisplay());
    }

    #[Test]
    public function aSecondRunCreatesNoDuplicatesAndKeepsTheFirstPasswords(): void
    {
        $this->startWithPassword(self::PASSWORD);
        $this->runSeed();
        // Argon2id salts every hash, so an unchanged hash proves the credential was not written again.
        $hashesAfterFirstRun = $this->credentialHashes();

        $second = $this->runSeed();

        self::assertSame(Command::SUCCESS, $second->getStatusCode());
        self::assertSame(self::EMAILS, $this->emails());
        self::assertSame($hashesAfterFirstRun, $this->credentialHashes());
        self::assertStringContainsString('created 0', $second->getDisplay());
    }

    #[Test]
    public function aPersonWithoutACredentialGetsOneWithoutANewUser(): void
    {
        $this->startWithPassword(self::PASSWORD);
        $omar = UserFactory::createOne(['email' => new Email('omar@acme-foods.example'), 'name' => 'Omar']);

        $tester = $this->runSeed();

        self::assertStringContainsString('created 5 (Lena, Mia, Raj, Sara, Ben), added a password to 1 (Omar), kept 0 ()', $tester->getDisplay());
        self::assertSame(self::EMAILS, $this->emails());
        self::assertCount(6, $this->credentialHashes());
        self::assertSame(
            $omar->id()->toString(),
            $this->connection()->fetchOne("SELECT id FROM idn_user WHERE email = 'omar@acme-foods.example'"),
        );
    }

    #[Test]
    public function itCreatesEveryRoleCombinationOfAcmeFoodsWithSaraAsTheTriager(): void
    {
        $this->startWithPassword(self::PASSWORD);

        $this->runSeed();

        $acmeFoods = $this->organizationIdOf('sara@acme-foods.example');
        self::assertSame(
            ['lena@acme-foods.example' => '{admin}', 'mia@acme-foods.example' => '{}', 'omar@acme-foods.example' => '{}', 'raj@acme-foods.example' => '{manager}', 'sara@acme-foods.example' => '{admin}'],
            $this->underTenant($acmeFoods, fn (): array => $this->connection()->fetchAllKeyValue(
                'SELECT u.email, m.org_roles FROM org_membership m JOIN idn_user u ON u.id = m.user_id ORDER BY u.email',
            )),
        );
        self::assertSame(
            [
                'Mobile|lena@acme-foods.example|lead|developer',
                'Mobile|omar@acme-foods.example|member|developer',
                'Shop|lena@acme-foods.example|lead|developer',
                'Shop|mia@acme-foods.example|member|tester',
                'Shop|omar@acme-foods.example|member|developer',
                'Shop|raj@acme-foods.example|viewer|other',
            ],
            $this->underTenant($acmeFoods, fn (): array => $this->connection()->fetchFirstColumn(
                "SELECT t.name || '|' || u.email || '|' || tm.team_role || '|' || tm.job FROM org_team_member tm"
                .' JOIN org_team t ON t.organization_id = tm.organization_id AND t.id = tm.team_id JOIN idn_user u ON u.id = tm.user_id ORDER BY 1',
            )),
        );
        self::assertSame(
            [$this->userIdOf('sara@acme-foods.example')],
            $this->underTenant($acmeFoods, fn (): array => $this->connection()->fetchFirstColumn("SELECT data->>'triager_id' FROM org_settings")),
        );
    }

    #[Test]
    public function betaBakeryHoldsOnlyBenWithHisTeamOvensUnderThePolicyTeamAndItsRowsAreInvisibleUnderAcmeFoods(): void
    {
        $this->startWithPassword(self::PASSWORD);

        $this->runSeed();

        $betaBakery = $this->organizationIdOf('ben@beta-bakery.example');
        $acmeFoods = $this->organizationIdOf('sara@acme-foods.example');
        self::assertNotSame($acmeFoods, $betaBakery);
        self::assertSame(
            ['ben@beta-bakery.example' => '{admin}'],
            $this->underTenant($betaBakery, fn (): array => $this->connection()->fetchAllKeyValue(
                'SELECT u.email, m.org_roles FROM org_membership m JOIN idn_user u ON u.id = m.user_id',
            )),
        );
        self::assertSame(
            ['Ovens|ben@beta-bakery.example|lead'],
            $this->underTenant($betaBakery, fn (): array => $this->connection()->fetchFirstColumn(
                "SELECT t.name || '|' || u.email || '|' || tm.team_role FROM org_team_member tm"
                .' JOIN org_team t ON t.organization_id = tm.organization_id AND t.id = tm.team_id JOIN idn_user u ON u.id = tm.user_id',
            )),
        );
        self::assertSame(
            ['team'],
            $this->underTenant($betaBakery, fn (): array => $this->connection()->fetchFirstColumn("SELECT data->>'step_policy' FROM org_settings")),
        );
        self::assertSame(
            ['extend'],
            $this->underTenant($acmeFoods, fn (): array => $this->connection()->fetchFirstColumn("SELECT data->>'step_policy' FROM org_settings")),
        );
        self::assertSame(
            [],
            $this->underTenant($acmeFoods, fn (): array => $this->connection()->fetchFirstColumn(
                'SELECT organization_id FROM org_membership WHERE organization_id = ?',
                [$betaBakery],
            )),
        );
        self::assertSame(
            ['Acme Foods'],
            $this->underTenant($acmeFoods, fn (): array => $this->connection()->fetchFirstColumn('SELECT name FROM org_organization')),
        );
    }

    #[Test]
    public function bothDemoOrganizationsGetTheCoreStepsAndTheDefaultRuleSet(): void
    {
        $this->startWithPassword(self::PASSWORD);

        $this->runSeed();

        $defaults = RuleSet::defaults();
        foreach (['sara@acme-foods.example', 'ben@beta-bakery.example'] as $email) {
            self::assertSame(
                [
                    array_map(static fn (CoreStep $coreStep): string => $coreStep->value, CoreStep::cases()),
                    [['tester_moves_done' => $defaults->testerMovesDone, 'no_self_review' => $defaults->noSelfReview, 'review_before_test' => $defaults->reviewBeforeTest]],
                ],
                $this->underTenant($this->organizationIdOf($email), fn (): array => [
                    $this->connection()->fetchFirstColumn("SELECT core_code FROM wf_step WHERE origin = 'core' AND team_id IS NULL ORDER BY position"),
                    $this->connection()->fetchAllAssociative('SELECT tester_moves_done, no_self_review, review_before_test FROM wf_rule_set WHERE team_id IS NULL'),
                ]),
                $email,
            );
        }
    }

    #[Test]
    public function lenaAndOmarEachBelongToExactlyOneOrganization(): void
    {
        $this->startWithPassword(self::PASSWORD);

        $this->runSeed();

        foreach (['lena@acme-foods.example', 'omar@acme-foods.example'] as $email) {
            self::assertSame(
                [$this->organizationIdOf('sara@acme-foods.example')],
                $this->organizationIdsOf($email),
                $email,
            );
        }
    }

    #[Test]
    public function aSecondRunOfTheOrganizationStepAddsNothingAndChangesNothing(): void
    {
        $this->startWithPassword(self::PASSWORD);
        $first = $this->runSeed();
        self::assertStringContainsString('Organizations: created 2, kept 0; Members: created 6, kept 0; Teams: created 3, kept 0; Team members: created 4, kept 0', $first->getDisplay());
        $rowsAfterFirstRun = $this->organizationRows();
        $outboxAfterFirstRun = $this->outboxCount();
        $acmeFoods = $this->organizationIdOf('sara@acme-foods.example');
        // A change made after the seed must survive the next one: Mia becomes a Viewer of Shop.
        $this->committedUnderTenant($acmeFoods, function (): void {
            $this->connection()->executeStatement("UPDATE org_team_member SET team_role = 'viewer' WHERE user_id = (SELECT id FROM idn_user WHERE email = 'mia@acme-foods.example')");
        });
        $this->committedUnderTenant($this->organizationIdOf('ben@beta-bakery.example'), function (): void {
            $this->connection()->executeStatement("UPDATE org_settings SET data = jsonb_set(data, '{step_policy}', '\"locked\"')");
        });
        $rowsAfterChange = $this->organizationRows();

        $second = $this->runSeed();

        self::assertSame(Command::SUCCESS, $second->getStatusCode());
        self::assertStringContainsString('Organizations: created 0, kept 2; Members: created 0, kept 6; Teams: created 0, kept 3; Team members: created 0, kept 4', $second->getDisplay());
        self::assertSame($rowsAfterChange, $this->organizationRows());
        self::assertNotSame($rowsAfterFirstRun, $rowsAfterChange);
        self::assertSame(
            ['locked'],
            $this->underTenant($this->organizationIdOf('ben@beta-bakery.example'), fn (): array => $this->connection()->fetchFirstColumn("SELECT data->>'step_policy' FROM org_settings")),
        );
        self::assertSame($outboxAfterFirstRun, $this->outboxCount());
    }

    #[Test]
    public function aRunAfterAnOrganizationWasLostCreatesOnlyWhatIsMissing(): void
    {
        $this->startWithPassword(self::PASSWORD);
        $this->runSeed();
        $this->removeOrganizationOf('ben@beta-bakery.example');

        $second = $this->runSeed();

        self::assertStringContainsString('Organizations: created 1, kept 1; Members: created 1, kept 5; Teams: created 1, kept 2; Team members: created 0, kept 4', $second->getDisplay());
        self::assertCount(1, $this->organizationIdsOf('ben@beta-bakery.example'));
        self::assertSame(
            ['team'],
            $this->underTenant($this->organizationIdOf('ben@beta-bakery.example'), fn (): array => $this->connection()->fetchFirstColumn("SELECT data->>'step_policy' FROM org_settings")),
        );
    }

    #[Test]
    public function itCreatesProjectShopOwnedByTheShopTeamOfAcmeFoodsOnce(): void
    {
        $this->startWithPassword(self::PASSWORD);
        $first = $this->runSeed();
        $acmeFoods = $this->organizationIdOf('sara@acme-foods.example');
        $projectsAfterFirstRun = $this->underTenant($acmeFoods, fn (): array => $this->connection()->fetchAllAssociative(
            'SELECT p.id, p.key, p.name, t.name AS team FROM tkt_project p JOIN org_team t ON t.organization_id = p.organization_id AND t.id = p.team_id',
        ));
        $unlinkedAfterFirstRun = $this->underTenant($acmeFoods, fn (): array => $this->connection()->fetchAllAssociative(
            'SELECT id, key, name, team_id FROM tkt_project WHERE team_id IS NULL ORDER BY key',
        ));

        $second = $this->runSeed();

        self::assertCount(2, $unlinkedAfterFirstRun);
        self::assertSame(
            [['APP', 'Acme app', null], ['DOCS', 'Acme docs', null]],
            array_map(static fn (array $row): array => [$row['key'], $row['name'], $row['team_id']], $unlinkedAfterFirstRun),
        );
        self::assertSame($unlinkedAfterFirstRun, $this->underTenant($acmeFoods, fn (): array => $this->connection()->fetchAllAssociative(
            'SELECT id, key, name, team_id FROM tkt_project WHERE team_id IS NULL ORDER BY key',
        )));
        self::assertStringContainsString('Projects: created 3, kept 0.', $first->getDisplay());
        self::assertCount(1, $projectsAfterFirstRun);
        self::assertSame(['SHOP', 'Shop', 'Shop'], [$projectsAfterFirstRun[0]['key'], $projectsAfterFirstRun[0]['name'], $projectsAfterFirstRun[0]['team']]);
        self::assertSame(Command::SUCCESS, $second->getStatusCode());
        self::assertStringContainsString('Projects: created 0, kept 3.', $second->getDisplay());
        self::assertSame($projectsAfterFirstRun, $this->underTenant($acmeFoods, fn (): array => $this->connection()->fetchAllAssociative(
            'SELECT p.id, p.key, p.name, t.name AS team FROM tkt_project p JOIN org_team t ON t.organization_id = p.organization_id AND t.id = p.team_id',
        )));
        self::assertSame(
            0,
            $this->underTenant($this->organizationIdOf('ben@beta-bakery.example'), fn (): mixed => $this->connection()->fetchOne('SELECT count(*) FROM tkt_project')),
        );
    }

    #[Test]
    public function itCreatesFortyOneShopTicketsInEveryCoreStepWithTheirEventAndTurnRowsAndASecondRunAddsNone(): void
    {
        $this->startWithPassword(self::PASSWORD);
        $first = $this->runSeed();
        $acmeFoods = $this->organizationIdOf('sara@acme-foods.example');
        $omar = $this->userIdOf('omar@acme-foods.example');
        $ticketFacts = fn (): array => $this->underTenant($acmeFoods, fn (): array => [
            $this->connection()->fetchFirstColumn(
                'SELECT s.core_code FROM tkt_ticket t JOIN wf_step s ON s.organization_id = t.organization_id AND s.id = t.step_id GROUP BY s.core_code, s.position ORDER BY s.position',
            ),
            $this->connection()->fetchOne("SELECT count(*) FROM tkt_ticket WHERE key LIKE 'SHOP-%'"),
            $this->connection()->fetchOne('SELECT count(DISTINCT type) FROM tkt_ticket'),
            $this->connection()->fetchOne('SELECT count(DISTINCT priority) FROM tkt_ticket'),
            $this->connection()->fetchOne('SELECT count(*) FROM tkt_ticket WHERE assignee_id = ?', [$omar]),
            $this->connection()->fetchOne("SELECT count(*) FROM tkt_ticket WHERE visibility = 'team'"),
            $this->connection()->fetchOne('SELECT count(*) FROM tkt_ticket_event'),
            $this->connection()->fetchOne('SELECT count(*) FROM ins_ticket_turn'),
            $this->connection()->fetchOne('SELECT count(*) FROM tkt_ticket WHERE closed_at IS NOT NULL'),
            $this->connection()->fetchAllNumeric(
                "SELECT c.visibility, c.open_count, c.done_count FROM ins_project_counts c
                 JOIN tkt_project p ON p.organization_id = c.organization_id AND p.id = c.project_id WHERE p.key = 'SHOP' ORDER BY c.visibility",
            ),
            $this->connection()->fetchAllNumeric(
                "SELECT t.visibility, count(*) FILTER (WHERE s.grp <> 'done'), count(*) FILTER (WHERE s.grp = 'done') FROM tkt_ticket t
                 JOIN wf_step s ON s.organization_id = t.organization_id AND s.id = t.step_id
                 JOIN tkt_project p ON p.organization_id = t.organization_id AND p.id = t.project_id WHERE p.key = 'SHOP' GROUP BY t.visibility ORDER BY t.visibility",
            ),
        ]);
        $afterFirstRun = $ticketFacts();

        $second = $this->runSeed();

        self::assertSame(Command::SUCCESS, $first->getStatusCode());
        self::assertStringContainsString('Tickets: created 41, kept 0.', $first->getDisplay());
        self::assertSame(['backlog', 'todo', 'progress', 'review', 'test', 'input', 'hold', 'done'], $afterFirstRun[0]);
        // The 41 tickets and SeedShowcaseTickets' three, all bugs, so the types and priorities are SeedDemoTickets' own.
        self::assertSame([44, 3, 5], \array_slice($afterFirstRun, 1, 3));
        self::assertGreaterThan(0, $afterFirstRun[4]);
        self::assertLessThan(41, $afterFirstRun[4]);
        self::assertGreaterThan(0, $afterFirstRun[5]);
        self::assertLessThan(41, $afterFirstRun[5]);
        // The six done tickets (five and the showcase's search ticket) are nobody's turn, so they hold no turn row. The
        // showcase tickets were reported and then moved 11 times through TicketCommands, each with its history row.
        self::assertSame([55, 38, 6], \array_slice($afterFirstRun, 6, 3));
        // The rail's stored counts follow the seed's raw step and visibility moves: both visibilities, six done.
        self::assertCount(2, $afterFirstRun[10]);
        self::assertSame(6, array_sum(array_column($afterFirstRun[10], 2)));
        self::assertSame($afterFirstRun[10], $afterFirstRun[9]);
        self::assertStringContainsString('Tickets: created 0, kept 41.', $second->getDisplay());
        self::assertSame($afterFirstRun, $ticketFacts());
        $longAskLength = $this->underTenant($acmeFoods, fn (): mixed => $this->connection()->fetchOne('SELECT length(description) FROM tkt_ticket WHERE title = ?', [SeedDemoTickets::LONG_ASK_TITLE]));
        self::assertGreaterThan(700, $longAskLength);
        $inputLabels = $this->underTenant($acmeFoods, fn (): array => $this->connection()->fetchFirstColumn(
            "SELECT DISTINCT t.waiting_on_label FROM tkt_ticket t JOIN wf_step s ON s.organization_id = t.organization_id AND s.id = t.step_id WHERE s.core_code = 'input'",
        ));
        self::assertSame(['Customer'], $inputLabels, 'A waiting label says who, as workflow-engine.md describes it.');
    }

    /**
     * The clock stands at 2027-03-15 10:00 UTC (startWithPassword), so the ages are hours to five weeks before it.
     */
    #[Test]
    public function theSeededTicketsGetVariedAgesFromTheClockAndTheirTurnsWaitAsLongAsTheyLastMoved(): void
    {
        $this->startWithPassword(self::PASSWORD);
        $this->runSeed();
        $acmeFoods = $this->organizationIdOf('sara@acme-foods.example');
        $seeded = 't.title = ANY(CAST(:titles AS text[]))';
        $titles = '{'.implode(',', array_map(static fn (string $title): string => '"'.str_replace('"', '\\"', $title).'"', SeedDemoTickets::titles())).'}';

        [$ages, $named, $turns] = $this->underTenant($acmeFoods, fn (): array => [
            $this->connection()->fetchAssociative(
                "SELECT count(*) AS tickets, count(DISTINCT t.updated_at) AS distinct_moves, min(t.updated_at) AS oldest, max(t.updated_at) AS newest,
                 count(*) FILTER (WHERE t.created_at >= t.updated_at) AS made_after_move,
                 count(*) FILTER (WHERE s.grp = 'done' AND t.closed_at IS DISTINCT FROM t.updated_at) AS done_closed_elsewhere
                 FROM tkt_ticket t JOIN wf_step s ON s.organization_id = t.organization_id AND s.id = t.step_id WHERE {$seeded}",
                ['titles' => $titles],
            ),
            $this->connection()->fetchAllNumeric(
                "SELECT t.title, to_char(t.created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI'), to_char(t.updated_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI')
                 FROM tkt_ticket t WHERE t.title IN ('Search box loses focus after typing', 'Price rounds wrong in the cart') ORDER BY t.title",
            ),
            $this->connection()->fetchAssociative(
                "SELECT count(*) AS turns, count(DISTINCT turn.waiting_since) AS distinct_waits,
                 count(*) FILTER (WHERE turn.waiting_since IS DISTINCT FROM t.updated_at) AS waits_not_from_the_move
                 FROM ins_ticket_turn turn JOIN tkt_ticket t ON t.organization_id = turn.organization_id AND t.id = turn.ticket_id WHERE {$seeded}",
                ['titles' => $titles],
            ),
        ]);

        self::assertIsArray($ages);
        self::assertSame(41, $ages['tickets']);
        self::assertSame(16, $ages['distinct_moves'], 'every one of the 16 waits is used');
        self::assertSame('2027-02-07 14:00:00+00', $ages['oldest'], '860 hours before the clock');
        self::assertSame('2027-03-15 08:00:00+00', $ages['newest'], 'two hours before the clock');
        self::assertSame(0, $ages['made_after_move']);
        self::assertSame(0, $ages['done_closed_elsewhere']);
        // Ticket 0 waits WAIT_HOURS[0] = 2 h and was made a day before; ticket 1 waits WAIT_HOURS[7] = 140 h, made two days before.
        self::assertSame([
            ['Price rounds wrong in the cart', '2027-03-07 14:00', '2027-03-09 14:00'],
            ['Search box loses focus after typing', '2027-03-14 08:00', '2027-03-15 08:00'],
        ], $named);
        self::assertIsArray($turns);
        self::assertGreaterThan(20, $turns['turns']);
        self::assertGreaterThan(10, $turns['distinct_waits']);
        self::assertSame(0, $turns['waits_not_from_the_move']);
    }

    #[Test]
    public function theProjectStepRefusesWhenTheShopTeamIsMissing(): void
    {
        $this->startWithPassword(self::PASSWORD);
        $this->runSeed();
        $acmeFoods = $this->organizationIdOf('sara@acme-foods.example');
        self::getContainer()->get('doctrine')->resetManager();
        $this->ticketRows()->removeAllOf($acmeFoods);
        $this->projectRows()->removeAllOf($acmeFoods);
        $this->committedUnderTenant($acmeFoods, function (): void {
            $this->connection()->executeStatement("UPDATE org_team SET name = 'Webshop' WHERE name = 'Shop'");
        });
        $step = self::getContainer()->get(SeedDemoProjects::class);

        $this->expectException(DemoSeedRefused::class);
        $this->expectExceptionMessageIsOrContains('acme-foods has no team Shop yet');

        $step->seed();
    }

    #[Test]
    public function theProjectStepRefusesWhenAcmeFoodsIsMissing(): void
    {
        $this->startWithPassword(self::PASSWORD);
        $step = self::getContainer()->get(SeedDemoProjects::class);

        $this->expectException(DemoSeedRefused::class);
        $this->expectExceptionMessageIsOrContains('The demo organization acme-foods does not exist yet');

        $step->seed();
    }

    #[Test]
    public function demoOrganizationsFindsEachSeededOrganizationBySlugAndNothingElse(): void
    {
        $this->startWithPassword(self::PASSWORD);
        $demoOrganizations = self::getContainer()->get(DemoOrganizations::class);
        self::assertNull($demoOrganizations->idBySlug('acme-foods'));
        $this->runSeed();

        self::assertSame($this->organizationIdOf('sara@acme-foods.example'), $demoOrganizations->idBySlug('acme-foods')?->toString());
        self::assertSame($this->organizationIdOf('ben@beta-bakery.example'), $demoOrganizations->idBySlug('beta-bakery')?->toString());
        self::assertNull($demoOrganizations->idBySlug('gamma-grocers'));
    }

    #[Test]
    public function demoOrganizationsPutsTheTenantBackAsItWas(): void
    {
        $this->startWithPassword(self::PASSWORD);
        $this->runSeed();
        $betaBakery = new OrganizationId($this->organizationIdOf('ben@beta-bakery.example'));
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->set($betaBakery);

        try {
            $acmeFoods = self::getContainer()->get(DemoOrganizations::class)->idBySlug('acme-foods');
            self::assertSame($betaBakery->toString(), $tenantContext->get()?->toString());
        } finally {
            $tenantContext->clear();
        }
        self::assertSame($this->organizationIdOf('sara@acme-foods.example'), $acmeFoods?->toString());
    }

    #[Test]
    public function demoOrganizationsAndTheProjectStepRefuseInsideAnOpenTransaction(): void
    {
        $this->startWithPassword(self::PASSWORD);
        $this->runSeed();
        $connection = $this->connection();
        $refusals = [];

        $connection->beginTransaction();
        try {
            foreach ([
                'idBySlug' => static fn (): mixed => self::getContainer()->get(DemoOrganizations::class)->idBySlug('acme-foods'),
                'seed' => static fn (): mixed => self::getContainer()->get(SeedDemoProjects::class)->seed(),
            ] as $call => $work) {
                try {
                    $work();
                } catch (TenantSwitchRefused) {
                    $refusals[] = $call;
                }
            }
        } finally {
            $connection->rollBack();
        }

        self::assertSame(['idBySlug', 'seed'], $refusals);
    }

    #[Test]
    public function theOrganizationStepRefusesWhenAPersonIsMissing(): void
    {
        $this->startWithPassword(self::PASSWORD);
        $step = self::getContainer()->get(SeedDemoOrganizations::class);

        $this->expectException(DemoSeedRefused::class);
        $this->expectExceptionMessageIsOrContains('does not exist yet');

        $step->seed();
    }

    #[Test]
    public function itRefusesWithAClearMessageWhenDemoPasswordIsMissing(): void
    {
        $this->startWithPassword(null);

        $tester = $this->runSeed();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('DEMO_PASSWORD is not set', $tester->getDisplay());
        self::assertSame([], $this->emails());
    }

    #[Test]
    public function itRefusesWithAClearMessageWhenDemoPasswordHasFewerThanTwelveCharacters(): void
    {
        $this->startWithPassword('elevenchars');

        $tester = $this->runSeed();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('DEMO_PASSWORD needs at least 12 characters', $tester->getDisplay());
        self::assertStringNotContainsString('elevenchars', $tester->getDisplay());
        self::assertSame([], $this->emails());
    }

    #[Test]
    public function itIsRegisteredInTestAndNotInProd(): void
    {
        $registered = new Application(self::bootKernel())->find('app:demo:seed');
        $attributes = new \ReflectionClass(DemoSeedCommand::class)->getAttributes(When::class);

        $environments = array_map(static fn (\ReflectionAttribute $attribute): string => $attribute->newInstance()->env, $attributes);
        sort($environments);

        self::assertSame('app:demo:seed', $registered->getName());
        self::assertSame(['dev', 'test'], $environments);
    }

    /**
     * Boots a fresh kernel, because the container keeps the DEMO_PASSWORD it first resolved. Its clock stands inside
     * the tkt_ticket_event partitions, which the migrations made only through 2027-12, for the seeded tickets' history.
     */
    private function startWithPassword(?string $password): void
    {
        null === $password ? putenv('DEMO_PASSWORD') : putenv('DEMO_PASSWORD='.$password);
        self::bootKernel();
        self::getContainer()->set('clock', new MockClock(new \DateTimeImmutable('2027-03-15 10:00:00 UTC')));
    }

    private function runSeed(): CommandTester
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester(new Application($kernel)->find('app:demo:seed'));
        $tester->execute([]);

        return $tester;
    }

    /**
     * @return array<array-key, mixed> password hash by user id, for the demo people only
     */
    private function credentialHashes(): array
    {
        return $this->connection()->fetchAllKeyValue(
            'SELECT c.user_id, c.password_hash FROM idn_credential c JOIN idn_user u ON u.id = c.user_id WHERE u.email IN (?, ?, ?, ?, ?, ?) ORDER BY c.user_id',
            self::EMAILS,
        );
    }

    /**
     * @return list<string>
     */
    private function emails(): array
    {
        $emails = [];
        foreach ($this->connection()->fetchFirstColumn('SELECT email FROM idn_user WHERE email IN (?, ?, ?, ?, ?, ?) ORDER BY email', self::EMAILS) as $email) {
            self::assertIsString($email);
            $emails[] = $email;
        }

        return $emails;
    }

    /**
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    private function underTenant(string $organizationId, \Closure $work): mixed
    {
        $entityManager = self::getContainer()->get('doctrine.orm.default_entity_manager');

        return new TenantTransactions($entityManager, self::getContainer()->get(TenantContext::class))->rolledBackAs($organizationId, $work);
    }

    /**
     * @param \Closure(): mixed $work
     */
    private function committedUnderTenant(string $organizationId, \Closure $work): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.default_entity_manager');

        new TenantTransactions($entityManager, self::getContainer()->get(TenantContext::class))->committedAs($organizationId, $work);
    }

    private function userIdOf(string $email): string
    {
        $id = $this->connection()->fetchOne('SELECT id FROM idn_user WHERE email = ?', [$email]);
        self::assertIsString($id, $email);

        return $id;
    }

    /**
     * @return list<string>
     */
    private function organizationIdsOf(string $email): array
    {
        $ids = [];
        foreach ($this->connection()->fetchFirstColumn('SELECT organization_id FROM resolve_user_orgs(?)', [$this->userIdOf($email)]) as $id) {
            self::assertIsString($id);
            $ids[] = $id;
        }

        return $ids;
    }

    private function organizationIdOf(string $email): string
    {
        $ids = $this->organizationIdsOf($email);
        self::assertCount(1, $ids, $email);

        return $ids[0];
    }

    /**
     * @return list<string> every membership and team member row of both demo organizations, as text
     */
    private function organizationRows(): array
    {
        $rows = [];
        foreach (['sara@acme-foods.example', 'ben@beta-bakery.example'] as $email) {
            $rows[] = $this->underTenant($this->organizationIdOf($email), fn (): string => json_encode([
                $this->connection()->fetchAllAssociative('SELECT * FROM org_membership ORDER BY user_id'),
                $this->connection()->fetchAllAssociative('SELECT * FROM org_team_member ORDER BY team_id, user_id'),
                $this->connection()->fetchAllAssociative('SELECT * FROM org_team ORDER BY id'),
                $this->connection()->fetchAllAssociative('SELECT * FROM org_settings'),
            ], \JSON_THROW_ON_ERROR));
        }

        return $rows;
    }

    private function outboxCount(): int
    {
        $count = $this->connection()->fetchOne("SELECT count(*) FROM messenger_outbox WHERE queue_name = 'outbox'");
        self::assertIsInt($count);

        return $count;
    }

    private function removeOrganizationOf(string $email): void
    {
        $this->demoSeedRows()->removeOrganizations(...$this->organizationIdsOf($email));
    }

    private function removeDemoRows(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        $this->demoSeedRows()->removePeopleAndTheirOrganizations(self::EMAILS);
    }

    private function demoSeedRows(): DemoSeedRows
    {
        return new DemoSeedRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), $this->connection(), self::getContainer()->get(TenantContext::class));
    }

    private function ticketRows(): TicketRows
    {
        return new TicketRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), self::getContainer()->get(TenantContext::class));
    }

    private function projectRows(): ProjectRows
    {
        return new ProjectRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), self::getContainer()->get(TenantContext::class));
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager')->getConnection();
    }
}
