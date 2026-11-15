<?php

declare(strict_types=1);

namespace App\Tests\Functional\SharedKernel;

use App\Identity\Infrastructure\Demo\SeedDemoCrowdPeople;
use App\Organization\Api\DemoOrganizations;
use App\Organization\Infrastructure\Demo\SeedDemoCrowdTeam;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Infrastructure\Console\DemoSeedRefused;
use App\SharedKernel\Infrastructure\Console\DemoSeedStep;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Ticketing\Infrastructure\Demo\SeedDemoCrowdTickets;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The dev-only crowd steps of app:demo:seed, built here by hand with a crowd of CROWD_SIZE (the test environment does
 * not register them). The seed commits, so each test removes the demo and crowd rows it made.
 */
final class DemoSeedCrowdStepsTest extends KernelTestCase
{
    /** Holds Person0040 and Person0042, whom the Crowd tickets are assigned to. */
    private const int CROWD_SIZE = 45;
    private const string MOVED_EMAIL_PREFIX = 'moved-';

    private string|false $passwordBefore;

    protected function setUp(): void
    {
        $this->passwordBefore = getenv('DEMO_PASSWORD');
        putenv('DEMO_PASSWORD=a-demo-password-for-tests');
        self::bootKernel();
        $this->removeSeededRows();
        // Inside the tkt_ticket_event partitions, which the migrations made only through 2027-12.
        self::getContainer()->set('clock', new MockClock(new \DateTimeImmutable('2027-03-15 10:00:00 UTC')));
    }

    protected function tearDown(): void
    {
        if (!self::$booted) {
            self::bootKernel();
        }
        $this->removeSeededRows();
        false === $this->passwordBefore ? putenv('DEMO_PASSWORD') : putenv('DEMO_PASSWORD='.$this->passwordBefore);
        parent::tearDown();
    }

    #[Test]
    public function eachCrowdStepRunTwiceAddsNothingTheSecondTime(): void
    {
        $this->runDemoSeed();
        $betaBakery = $this->betaBakeryId();

        $steps = [
            [$this->crowdPeople(), 'Crowd people: created 45, kept 0.', 'Crowd people: created 0, kept 45.'],
            [$this->crowdTeam(), 'Crowd team: added 45 people, kept 0.', 'Crowd team: added 0 people, kept 45.'],
            [$this->crowdTickets(), 'Crowd tickets: created 4, kept 0.', 'Crowd tickets: created 0, kept 4.'],
        ];
        foreach ($steps as [$step, $firstReport, $secondReport]) {
            $before = $this->crowdState($betaBakery);
            self::assertSame($firstReport, $step->seed());
            $afterFirstRun = $this->crowdState($betaBakery);
            self::assertNotSame($before, $afterFirstRun, $firstReport);

            self::assertSame($secondReport, $step->seed());
            self::assertSame($afterFirstRun, $this->crowdState($betaBakery), $secondReport);
        }

        [$crowdPeople, $members, , , $tickets, , $outbox] = $this->crowdState($betaBakery);
        self::assertSame(self::CROWD_SIZE, $crowdPeople);
        // Ben leads the team; each crowd person joined as a member, with one event each.
        self::assertSame(['lead' => 1, 'member' => self::CROWD_SIZE], $members);
        self::assertSame(
            ['Add a cleaning slot to the oven plan|Ben', 'Flour order form drops the last line|Person0040', 'Oven timer drifts after an hour|Person0042', 'Proofing log misses the night reading|Person0042'],
            $tickets,
        );
        self::assertGreaterThan(self::CROWD_SIZE, $outbox);
    }

    #[Test]
    public function theTeamAndTicketStepsRefuseWithoutBetaBakeryAndWriteNothing(): void
    {
        $this->crowdPeople()->seed();
        $outboxBefore = $this->outboxCount();

        $this->assertRefuses($this->crowdTeam(), 'The demo organization Beta Bakery does not exist yet, so the Crowd team cannot be made.');
        $this->assertRefuses($this->crowdTickets(), 'The demo organization Beta Bakery does not exist yet, so the Crowd tickets cannot be made.');

        self::assertNull(self::getContainer()->get(DemoOrganizations::class)->idBySlug('beta-bakery'));
        self::assertSame($outboxBefore, $this->outboxCount());
    }

    /**
     * DemoOrganizations finds Beta Bakery through Ben, its first admin, so without him the organization is not found.
     */
    #[Test]
    public function theTeamAndTicketStepsRefuseWithoutBenAndWriteNothing(): void
    {
        $this->runDemoSeed();
        $this->crowdPeople()->seed();
        $betaBakery = $this->betaBakeryId();
        $this->moveEmail('ben@beta-bakery.example');
        $before = $this->crowdState($betaBakery);

        $this->assertRefuses($this->crowdTeam(), 'The demo organization Beta Bakery does not exist yet, so the Crowd team cannot be made.');
        $this->assertRefuses($this->crowdTickets(), 'The demo organization Beta Bakery does not exist yet, so the Crowd tickets cannot be made.');

        self::assertSame($before, $this->crowdState($betaBakery));
    }

    #[Test]
    public function theTeamStepRefusesWhenACrowdPersonIsMissingAndWritesNothing(): void
    {
        $this->runDemoSeed();
        $this->crowdPeople()->seed();
        $betaBakery = $this->betaBakeryId();
        $this->moveEmail('person0045@beta-bakery.example');
        $before = $this->crowdState($betaBakery);

        $this->assertRefuses($this->crowdTeam(), 'The crowd person person0045@beta-bakery.example does not exist yet, so the Crowd team cannot be made.');

        self::assertSame($before, $this->crowdState($betaBakery));
        self::assertSame(0, $this->underBetaBakery($betaBakery, fn (): mixed => $this->connection()->fetchOne("SELECT count(*) FROM org_team WHERE name = 'Crowd'")));
    }

    #[Test]
    public function theTicketStepRefusesWithoutTheCrowdTeamAndWritesNothing(): void
    {
        $this->runDemoSeed();
        $this->crowdPeople()->seed();
        $betaBakery = $this->betaBakeryId();
        $before = $this->crowdState($betaBakery);

        $this->assertRefuses($this->crowdTickets(), 'Beta Bakery has no team Crowd yet, so the Crowd tickets cannot be made.');

        self::assertSame($before, $this->crowdState($betaBakery));
        self::assertSame(0, $this->underBetaBakery($betaBakery, fn (): mixed => $this->connection()->fetchOne('SELECT count(*) FROM tkt_project')));
    }

    #[Test]
    public function theTicketStepRefusesWhenAnAssigneeIsMissingAndKeepsNoProjectOrTicket(): void
    {
        $this->runDemoSeed();
        $this->crowdPeople()->seed();
        $this->crowdTeam()->seed();
        $betaBakery = $this->betaBakeryId();
        $this->moveEmail('person0042@beta-bakery.example');
        $before = $this->crowdState($betaBakery);

        $this->assertRefuses($this->crowdTickets(), 'The person person0042@beta-bakery.example does not exist yet, so the Crowd tickets cannot be made.');

        self::assertSame($before, $this->crowdState($betaBakery));
        self::assertSame(0, $this->underBetaBakery($betaBakery, fn (): mixed => $this->connection()->fetchOne('SELECT count(*) FROM tkt_project')));
    }

    /**
     * @param non-empty-string $reason
     */
    private function assertRefuses(DemoSeedStep $step, string $reason): void
    {
        try {
            $step->seed();
            self::fail('The step ran without its prerequisites: '.$reason);
        } catch (DemoSeedRefused $refused) {
            self::assertStringStartsWith($reason, $refused->getMessage());
        }
    }

    private function crowdPeople(): SeedDemoCrowdPeople
    {
        return new DemoCrowdSteps(self::getContainer(), self::CROWD_SIZE)->people();
    }

    private function crowdTeam(): SeedDemoCrowdTeam
    {
        return new DemoCrowdSteps(self::getContainer(), self::CROWD_SIZE)->team();
    }

    private function crowdTickets(): SeedDemoCrowdTickets
    {
        return new DemoCrowdSteps(self::getContainer(), self::CROWD_SIZE)->tickets();
    }

    private function runDemoSeed(): void
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester(new Application($kernel)->find('app:demo:seed'));
        $tester->execute([]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
    }

    /**
     * The crowd people; the Crowd team's members by role; Beta Bakery's memberships, teams, CROWD tickets with their
     * assignee and ticket events; the outbox messages.
     *
     * @return array{int, array<array-key, mixed>, list<mixed>, list<mixed>, list<mixed>, int, int}
     */
    private function crowdState(string $betaBakery): array
    {
        $crowdPeople = $this->connection()->fetchOne("SELECT count(*) FROM idn_user WHERE email LIKE 'person%@beta-bakery.example'");
        self::assertIsInt($crowdPeople);

        return $this->underBetaBakery($betaBakery, fn (): array => [
            $crowdPeople,
            $this->connection()->fetchAllKeyValue(
                "SELECT tm.team_role, count(*) FROM org_team_member tm JOIN org_team t ON t.organization_id = tm.organization_id AND t.id = tm.team_id WHERE t.name = 'Crowd' GROUP BY tm.team_role ORDER BY tm.team_role",
            ),
            $this->connection()->fetchFirstColumn('SELECT user_id::text || org_roles::text FROM org_membership ORDER BY user_id'),
            $this->connection()->fetchFirstColumn('SELECT id::text || name FROM org_team ORDER BY id'),
            $this->connection()->fetchFirstColumn(
                "SELECT t.title || '|' || u.name FROM tkt_ticket t JOIN tkt_project p ON p.organization_id = t.organization_id AND p.id = t.project_id
                 JOIN idn_user u ON u.id = t.assignee_id WHERE p.key = 'CROWD' ORDER BY t.title",
            ),
            $this->intOf($this->connection()->fetchOne('SELECT count(*) FROM tkt_ticket_event')),
            $this->outboxCount(),
        ]);
    }

    /**
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    private function underBetaBakery(string $betaBakery, \Closure $work): mixed
    {
        return new TenantTransactions(self::getContainer()->get('doctrine.orm.default_entity_manager'), self::getContainer()->get(TenantContext::class))
            ->rolledBackAs($betaBakery, $work);
    }

    private function betaBakeryId(): string
    {
        $id = self::getContainer()->get(DemoOrganizations::class)->idBySlug('beta-bakery');
        self::assertNotNull($id);

        return $id->toString();
    }

    /**
     * Hides a person from the steps' lookups by email; removeSeededRows still finds them.
     */
    private function moveEmail(string $email): void
    {
        self::getContainer()->get('doctrine.dbal.migrator_connection')
            ->executeStatement('UPDATE idn_user SET email = ? WHERE email = ?', [self::MOVED_EMAIL_PREFIX.$email, $email]);
    }

    private function outboxCount(): int
    {
        return $this->intOf($this->connection()->fetchOne("SELECT count(*) FROM messenger_outbox WHERE queue_name = 'outbox'"));
    }

    private function intOf(mixed $count): int
    {
        self::assertIsInt($count);

        return $count;
    }

    private function removeSeededRows(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        $emails = ['ben@beta-bakery.example', 'lena@acme-foods.example', 'mia@acme-foods.example', 'omar@acme-foods.example', 'raj@acme-foods.example', 'sara@acme-foods.example'];
        for ($number = 1; $number <= self::CROWD_SIZE; ++$number) {
            $emails[] = \sprintf('person%04d@beta-bakery.example', $number);
        }
        $emails = [...$emails, ...array_map(static fn (string $email): string => self::MOVED_EMAIL_PREFIX.$email, $emails)];

        new DemoSeedRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), $this->connection(), self::getContainer()->get(TenantContext::class))
            ->removePeopleAndTheirOrganizations($emails);
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager')->getConnection();
    }
}
