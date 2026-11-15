<?php

declare(strict_types=1);

namespace App\Tests\Functional\Notification;

use App\Notification\Infrastructure\Cache\BellCountCache;
use App\Notification\Infrastructure\Demo\SeedDemoBells;
use App\Notification\Infrastructure\Doctrine\DbalBellQueries;
use App\Organization\Api\DemoOrganizations;
use App\Organization\Api\TeamPeopleQueries;
use App\Organization\Api\TeamQueries;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Infrastructure\Console\DemoSeedRefused;
use App\Tests\Functional\SharedKernel\DemoCrowdSteps;
use App\Tests\Functional\SharedKernel\DemoSeedRows;
use App\Tests\Integration\Notification\NotificationRows;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Ticketing\Api\TicketQueries;
use App\Ticketing\Api\TicketVisibilities;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * SeedDemoBells on the test database, after app:demo:seed and the crowd steps (DemoCrowdSteps; the test environment
 * registers neither those nor this dev-only step). The seed commits, so each test removes the demo rows it made.
 */
final class SeedDemoBellsTest extends KernelTestCase
{
    private const int CROWD_SIZE = 45;
    private const int BULK_SENDERS = 30;
    /** Omar's groups: each of these SHOP tickets from Lena and from Mia. */
    private const int OMAR_TICKETS = 30;
    private const string NOW = '2027-03-15 10:00:00+00';
    private const string EARLIER_DONE_AT = '2027-03-14 08:00:00+00';

    private string|false $passwordBefore;

    private int $rowSequence = 0;

    protected function setUp(): void
    {
        $this->passwordBefore = getenv('DEMO_PASSWORD');
        putenv('DEMO_PASSWORD=a-demo-password-for-tests');
        self::bootKernel();
        $this->removeSeededRows();
        // Inside the ntf_notification and tkt_ticket_event partitions the migrations made, and not today.
        self::getContainer()->set('clock', new MockClock(new \DateTimeImmutable(self::NOW)));
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
    public function omarKeepsOnlyHisNewestGroupsAndPerson0042GetsMoreGroupsThanTheBellShowsOnce(): void
    {
        $this->seedEverythingButTheBells();
        $acmeFoods = $this->organizationIdOf('acme-foods');
        $betaBakery = $this->organizationIdOf('beta-bakery');
        $expectedKept = $this->giveOmarABigBell($acmeFoods);
        $lenasOpenRows = $this->openRowsOf($acmeFoods, 'lena@acme-foods.example');
        self::assertCount(1, $lenasOpenRows);

        self::assertSame('Bells: finished 50 of Omar\'s notifications, gave Person0042 60 new ones.', $this->seedDemoBells()->seed());

        $omarsOpenGroups = $this->openGroupsOf($acmeFoods, 'omar@acme-foods.example');
        self::assertCount(SeedDemoBells::KEPT_GROUPS, $omarsOpenGroups);
        self::assertEqualsCanonicalizing($expectedKept, $omarsOpenGroups);
        self::assertSame([self::EARLIER_DONE_AT], $this->doneAtOfRowsFinishedBefore($acmeFoods));
        self::assertSame($lenasOpenRows, $this->openRowsOf($acmeFoods, 'lena@acme-foods.example'));
        self::assertSame(50, $this->rowsDoneAt($acmeFoods, self::NOW));

        $bulkGroups = $this->openGroupsOf($betaBakery, 'person0042@beta-bakery.example');
        self::assertCount(self::BULK_SENDERS * 2, $bulkGroups);
        self::assertGreaterThan(DbalBellQueries::MAX_BELL_GROUPS, \count($bulkGroups));
        $senders = array_values(array_unique(array_map(static fn (string $group): string => explode('|', $group)[0], $bulkGroups)));
        sort($senders);
        // The first BULK_SENDERS crowd members by user id, which are Person0001 on as they were made in that order; never
        // Ben, who leads the team, or Person0042 herself.
        self::assertSame(array_map(static fn (int $number): string => \sprintf('Person%04d', $number), range(1, self::BULK_SENDERS)), $senders);
        self::assertSame(['CROWD-1', 'CROWD-2'], $this->ticketKeysOf($betaBakery, 'person0042@beta-bakery.example'));

        $acmeFoodsAfterFirstRun = $this->allRowsOf($acmeFoods);
        $betaBakeryAfterFirstRun = $this->allRowsOf($betaBakery);
        self::assertSame('Bells: finished 0 of Omar\'s notifications, gave Person0042 0 new ones.', $this->seedDemoBells()->seed());
        self::assertSame($acmeFoodsAfterFirstRun, $this->allRowsOf($acmeFoods));
        self::assertSame($betaBakeryAfterFirstRun, $this->allRowsOf($betaBakery));
    }

    #[Test]
    public function itRefusesWithoutPerson0042sTicketsAndLeavesOmarsBellAsItWas(): void
    {
        $this->runDemoSeed();
        $crowdSteps = new DemoCrowdSteps(self::getContainer(), self::CROWD_SIZE);
        $crowdSteps->people()->seed();
        $crowdSteps->team()->seed();
        $acmeFoods = $this->organizationIdOf('acme-foods');
        $this->giveOmarABigBell($acmeFoods);
        $before = $this->allRowsOf($acmeFoods);
        self::assertNotEmpty($before);

        try {
            $this->seedDemoBells()->seed();
            self::fail('The bells were seeded with no ticket given to Person0042.');
        } catch (DemoSeedRefused $refused) {
            self::assertSame('No ticket is given to Person0042 yet, so the bells cannot be seeded. Seed the Crowd tickets first.', $refused->getMessage());
        }

        self::assertSame($before, $this->allRowsOf($acmeFoods));
        self::assertSame([], $this->allRowsOf($this->organizationIdOf('beta-bakery')));
    }

    private function seedEverythingButTheBells(): void
    {
        $this->runDemoSeed();
        $crowdSteps = new DemoCrowdSteps(self::getContainer(), self::CROWD_SIZE);
        $crowdSteps->people()->seed();
        $crowdSteps->team()->seed();
        $crowdSteps->tickets()->seed();
    }

    /**
     * Gives Omar one open row from Lena and one from Mia on each of OMAR_TICKETS SHOP tickets, written in an order that is
     * not their age, and a newer second row in the oldest group, so that group is among the newest by its newest row.
     * Adds a row of his finished earlier and an open one of Lena's, which the step must leave as they are.
     *
     * @return list<string> the KEPT_GROUPS newest groups as "sender name|ticket key"
     */
    private function giveOmarABigBell(string $acmeFoods): array
    {
        $omar = $this->userIdOf('omar@acme-foods.example');
        $senders = ['Lena' => $this->userIdOf('lena@acme-foods.example'), 'Mia' => $this->userIdOf('mia@acme-foods.example')];
        $tickets = $this->underTenant($acmeFoods, fn (): array => self::stringMapOf($this->connection()->fetchAllKeyValue(
            "SELECT key, id::text FROM tkt_ticket WHERE key LIKE 'SHOP-%' ORDER BY number LIMIT ".self::OMAR_TICKETS,
        )));
        self::assertCount(self::OMAR_TICKETS, $tickets);

        $groupCount = self::OMAR_TICKETS * \count($senders);
        $rows = [];
        $minutesAgoByGroup = [];
        $group = 0;
        foreach ($tickets as $key => $ticketId) {
            foreach ($senders as $name => $senderId) {
                // 37 shares no factor with 60, so every group gets its own age and the newest are spread over the list.
                $minutesAgo = ($group * 37) % $groupCount + 2;
                $minutesAgoByGroup[$name.'|'.$key] = $minutesAgo;
                $rows[] = $this->rowOf($acmeFoods, $omar, $senderId, $ticketId, $name, $key, $minutesAgo);
                ++$group;
            }
        }
        arsort($minutesAgoByGroup);
        $oldestGroup = array_key_first($minutesAgoByGroup);
        [$oldestSender, $oldestKey] = explode('|', $oldestGroup);
        $rows[] = $this->rowOf($acmeFoods, $omar, $senders[$oldestSender], $tickets[$oldestKey], $oldestSender, $oldestKey, 1);
        $minutesAgoByGroup[$oldestGroup] = 1;
        $finishedRow = $this->rowOf($acmeFoods, $omar, $senders['Lena'], $tickets[array_key_last($tickets)], 'Lena', array_key_last($tickets), 600);
        $rows[] = ['done_at' => self::EARLIER_DONE_AT] + $finishedRow;
        $rows[] = $this->rowOf($acmeFoods, $senders['Lena'], $omar, $tickets[array_key_first($tickets)], 'Omar', array_key_first($tickets), 700);
        new NotificationRows($this->migrator(), $this->tenantContext())->addOf($acmeFoods, 'ntf_notification', $rows);

        asort($minutesAgoByGroup);

        return \array_slice(array_keys($minutesAgoByGroup), 0, SeedDemoBells::KEPT_GROUPS);
    }

    /**
     * @return array<string, mixed>
     */
    private function rowOf(string $organizationId, string $recipientId, string $senderId, string $ticketId, string $senderName, string $ticketKey, int $minutesAgo): array
    {
        ++$this->rowSequence;

        return [
            'organization_id' => $organizationId,
            'id' => \sprintf('0197a3c0-0000-7000-800e-%012x', $this->rowSequence),
            'user_id' => $recipientId,
            'kind' => 'question',
            'actor_id' => $senderId,
            'ticket_id' => $ticketId,
            'data' => json_encode(['actorName' => $senderName, 'ticketKey' => $ticketKey], \JSON_THROW_ON_ERROR),
            'created_at' => new \DateTimeImmutable(self::NOW)->modify(\sprintf('-%d minutes', $minutesAgo))->format('Y-m-d H:i:sP'),
        ];
    }

    private function seedDemoBells(): SeedDemoBells
    {
        $container = self::getContainer();

        return new SeedDemoBells(
            $container->get(DemoOrganizations::class),
            $container->get(TeamQueries::class),
            $container->get(TeamPeopleQueries::class),
            $container->get(TicketVisibilities::class),
            $container->get(TicketQueries::class),
            $container->get(BellCountCache::class),
            $this->tenantContext(),
            $this->connection(),
            $container->get('clock'),
            self::BULK_SENDERS,
        );
    }

    /**
     * @return list<string> the person's open groups as "sender name|ticket key", in no order
     */
    private function openGroupsOf(string $organizationId, string $email): array
    {
        return $this->underTenant($organizationId, fn (): array => self::stringsOf($this->connection()->fetchFirstColumn(
            "SELECT DISTINCT (data->>'actorName') || '|' || (data->>'ticketKey') FROM ntf_notification WHERE user_id = ? AND done_at IS NULL",
            [$this->userIdOf($email)],
        )));
    }

    /**
     * @return list<string>
     */
    private function ticketKeysOf(string $organizationId, string $email): array
    {
        return $this->underTenant($organizationId, fn (): array => self::stringsOf($this->connection()->fetchFirstColumn(
            "SELECT DISTINCT data->>'ticketKey' FROM ntf_notification WHERE user_id = ? ORDER BY 1",
            [$this->userIdOf($email)],
        )));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function openRowsOf(string $organizationId, string $email): array
    {
        return $this->underTenant($organizationId, fn (): array => $this->connection()->fetchAllAssociative(
            'SELECT id::text, done_at::text FROM ntf_notification WHERE user_id = ? AND done_at IS NULL ORDER BY id',
            [$this->userIdOf($email)],
        ));
    }

    /**
     * @return list<string>
     */
    private function doneAtOfRowsFinishedBefore(string $organizationId): array
    {
        return $this->underTenant($organizationId, fn (): array => self::stringsOf($this->connection()->fetchFirstColumn(
            "SELECT to_char(done_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') || '+00' FROM ntf_notification WHERE done_at < ?",
            [self::NOW],
        )));
    }

    private function rowsDoneAt(string $organizationId, string $at): int
    {
        $count = $this->underTenant($organizationId, fn (): mixed => $this->connection()->fetchOne('SELECT count(*) FROM ntf_notification WHERE done_at = ?', [$at]));
        self::assertIsInt($count);

        return $count;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function allRowsOf(string $organizationId): array
    {
        return $this->underTenant($organizationId, fn (): array => $this->connection()->fetchAllAssociative(
            'SELECT id::text, user_id::text, actor_id::text, ticket_id::text, kind, data::text, created_at::text, done_at::text FROM ntf_notification ORDER BY id',
        ));
    }

    /**
     * @param list<mixed> $values
     *
     * @return list<string>
     */
    private static function stringsOf(array $values): array
    {
        return array_map(static function (mixed $value): string {
            self::assertIsString($value);

            return $value;
        }, $values);
    }

    /**
     * @param array<mixed> $pairs
     *
     * @return array<string, string>
     */
    private static function stringMapOf(array $pairs): array
    {
        $strings = [];
        foreach ($pairs as $key => $value) {
            self::assertIsString($key);
            self::assertIsString($value);
            $strings[$key] = $value;
        }

        return $strings;
    }

    private function runDemoSeed(): void
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester(new Application($kernel)->find('app:demo:seed'));
        $tester->execute([]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
    }

    private function organizationIdOf(string $slug): string
    {
        $id = self::getContainer()->get(DemoOrganizations::class)->idBySlug($slug);
        self::assertNotNull($id);

        return $id->toString();
    }

    private function userIdOf(string $email): string
    {
        $id = $this->migrator()->fetchOne('SELECT id::text FROM idn_user WHERE email = ?', [$email]);
        self::assertIsString($id);

        return $id;
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
        return new TenantTransactions(self::getContainer()->get('doctrine.orm.default_entity_manager'), $this->tenantContext())
            ->rolledBackAs($organizationId, $work);
    }

    private function removeSeededRows(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        $emails = ['ben@beta-bakery.example', 'lena@acme-foods.example', 'mia@acme-foods.example', 'omar@acme-foods.example', 'raj@acme-foods.example', 'sara@acme-foods.example'];
        for ($number = 1; $number <= self::CROWD_SIZE; ++$number) {
            $emails[] = \sprintf('person%04d@beta-bakery.example', $number);
        }
        new DemoSeedRows($this->migrator(), $this->connection(), $this->tenantContext())->removePeopleAndTheirOrganizations($emails);
    }

    private function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }

    private function migrator(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.migrator_connection');
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager')->getConnection();
    }
}
