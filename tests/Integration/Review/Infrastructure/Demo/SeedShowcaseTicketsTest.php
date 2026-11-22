<?php

declare(strict_types=1);

namespace App\Tests\Integration\Review\Infrastructure\Demo;

use App\Media\Application\Processing\MediaFiles;
use App\Review\Infrastructure\Demo\SeedShowcaseTickets;
use App\SharedKernel\Application\TenantContext;
use App\Tests\Functional\SharedKernel\DemoSeedRows;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Unit\Media\Application\Processing\InMemoryMediaFiles;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Runs app:demo:seed on the test database, whose steps before SeedShowcaseTickets make the people, Acme Foods, its Shop
 * team and project SHOP, and reads what the showcase step left. The seed commits, so each test removes the demo rows.
 * The clock stands at 2027-03-15 10:00 UTC; the showcase's steps are dated days before it.
 */
final class SeedShowcaseTicketsTest extends KernelTestCase
{
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
        putenv('DEMO_PASSWORD=a-demo-password-for-tests');
        self::bootKernel();
        self::getContainer()->set('clock', new MockClock(new \DateTimeImmutable('2027-03-15 10:00:00 UTC')));
        $this->removeDemoRows();
    }

    protected function tearDown(): void
    {
        $this->removeDemoRows();
        if (false === $this->passwordBefore) {
            putenv('DEMO_PASSWORD');
        } else {
            putenv('DEMO_PASSWORD='.$this->passwordBefore);
        }
        parent::tearDown();
    }

    #[Test]
    public function eachShowcaseTicketTellsItsStoryInItsStepRoundsVerdictsAndTurn(): void
    {
        $seed = $this->runSeed();

        self::assertSame(Command::SUCCESS, $seed->getStatusCode(), $seed->getDisplay());
        self::assertStringContainsString('Showcase tickets: created 3, kept 0.', $seed->getDisplay());
        self::assertSame(
            [
                SeedShowcaseTickets::TOTAL_TITLE => ['test', 'Omar', 'Mia', 'Mia'],
                SeedShowcaseTickets::ORDERS_TITLE => ['progress', 'Omar', 'Mia', 'Omar'],
                SeedShowcaseTickets::SEARCH_TITLE => ['done', 'Omar', 'Mia', null],
            ],
            $this->ticketFacts(),
        );
        self::assertSame(
            [
                // Round 0 is Mia's report, then the checklist made from its pins with no number, round 1 Omar's fix, which the
                // reviewer's "Approve code" closed. On the orders ticket, Mia's "Needs changes" shared her findings.
                SeedShowcaseTickets::TOTAL_TITLE => ['0 report open Mia: -', '- checklist open Mia: -', '1 draft closed Omar: looks_right'],
                SeedShowcaseTickets::ORDERS_TITLE => ['0 report open Mia: -', '- checklist open Mia: -', '1 draft closed Omar: looks_right', '2 draft open Mia: not_right'],
                SeedShowcaseTickets::SEARCH_TITLE => ['0 report open Mia: -', '- checklist open Mia: -', '1 draft closed Omar: looks_right'],
            ],
            $this->roundFacts(),
        );
        self::assertSame(
            [
                SeedShowcaseTickets::TOTAL_TITLE => ['fixed'],
                // The report's item passed; Mia's finding is the open item Omar works on next.
                SeedShowcaseTickets::ORDERS_TITLE => ['ok', 'open: '.SeedShowcaseTickets::ORDERS_FINDING],
                SeedShowcaseTickets::SEARCH_TITLE => ['ok'],
            ],
            $this->checklistFacts(),
        );
    }

    #[Test]
    public function everyShowcasePictureIsReadyWithItsViewAndThumbStored(): void
    {
        $this->runSeed();

        $pictures = $this->underAcmeFoods(fn (): array => $this->connection()->fetchAllNumeric(
            'SELECT m.status, m.width, m.height, m.variants::text FROM med_object m JOIN rvw_screenshot s ON s.organization_id = m.organization_id AND s.media_id = m.id',
        ));
        // Round 0 and the shared round of each ticket, Mia's findings and the checklist rounds' copies of the reports.
        self::assertNotEmpty($pictures);
        $bucket = self::getContainer()->get('test.media_seed_files');
        self::assertInstanceOf(InMemoryMediaFiles::class, $bucket);
        foreach ($pictures as [$status, $width, $height, $variants]) {
            self::assertSame(['ready', 1024, 640], [$status, $width, $height]);
            self::assertIsString($variants);
            $keys = json_decode($variants, true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($keys);
            self::assertEqualsCanonicalizing(['thumb', 'view'], array_keys($keys));
            foreach ($keys as $key) {
                self::assertIsString($key);
                self::assertTrue($bucket->has($key), $key);
                self::assertSame('image/webp', $bucket->mimeTypeOf($key));
            }
        }
    }

    #[Test]
    public function aSecondRunKeepsTheShowcaseTicketsAsTheyAre(): void
    {
        $this->runSeed();
        $afterFirstRun = [$this->ticketFacts(), $this->roundFacts(), $this->checklistFacts(), $this->rowCountOf('med_object'), $this->rowCountOf('tkt_ticket_event')];

        $second = $this->runSeed();

        self::assertSame(Command::SUCCESS, $second->getStatusCode(), $second->getDisplay());
        self::assertStringContainsString('Showcase tickets: created 0, kept 3.', $second->getDisplay());
        self::assertSame($afterFirstRun, [$this->ticketFacts(), $this->roundFacts(), $this->checklistFacts(), $this->rowCountOf('med_object'), $this->rowCountOf('tkt_ticket_event')]);
    }

    #[Test]
    public function aRunThatFailsPartwayLeavesNoShowcaseTicketAndTheNextRunMakesAllThree(): void
    {
        $bucket = new class(new InMemoryMediaFiles()) implements MediaFiles {
            /** the 1-based number of the write() that fails, or null for a bucket that works */
            private ?int $failingWrite = null;

            private int $writes = 0;

            public function __construct(private readonly InMemoryMediaFiles $files)
            {
            }

            public function sizeOf(string $key): ?int
            {
                return $this->files->sizeOf($key);
            }

            public function readUpTo(string $key, int $maxBytes): string
            {
                return $this->files->readUpTo($key, $maxBytes);
            }

            public function write(string $key, string $bytes, string $mime): void
            {
                if (++$this->writes === $this->failingWrite) {
                    throw new \RuntimeException('The storage cannot be written.');
                }
                $this->files->write($key, $bytes, $mime);
            }

            public function delete(string $key): void
            {
                $this->files->delete($key);
            }

            public function failAtWrite(?int $number): void
            {
                $this->failingWrite = $number;
                $this->writes = 0;
            }
        };
        self::getContainer()->set('test.media_seed_files', $bucket);
        // The first run fails at the search ticket's first picture, so only the steps before the showcase have
        // committed: the counts to compare with.
        $bucket->failAtWrite(1);
        $this->runFailingSeed();
        $before = [$this->rowCountOf('med_object'), $this->rowCountOf('tkt_ticket_event')];

        // Each picture writes a view and a thumb: writes 1 to 4 are the search ticket's report and fix, so write 5 is
        // the total ticket's report, after the search ticket is complete.
        $bucket->failAtWrite(5);
        $refusal = $this->runFailingSeed();

        self::assertSame('The storage cannot be written.', $refusal->getMessage());
        self::assertSame([], $this->ticketFacts());
        self::assertSame($before, [$this->rowCountOf('med_object'), $this->rowCountOf('tkt_ticket_event')]);

        $bucket->failAtWrite(null);
        $rerun = $this->runSeed();

        self::assertSame(Command::SUCCESS, $rerun->getStatusCode(), $rerun->getDisplay());
        self::assertStringContainsString('Showcase tickets: created 3, kept 0.', $rerun->getDisplay());
        self::assertEqualsCanonicalizing(self::titles(), array_keys($this->ticketFacts()));
    }

    #[Test]
    public function aShowcaseTicketWhoseTitleIsTakenIsKeptAndTheOthersAreMade(): void
    {
        $this->runSeed();
        $this->committedUnderAcmeFoods(fn (): int|string => $this->connection()->executeStatement(
            "UPDATE tkt_ticket SET title = title || ' (renamed)' WHERE title IN (?, ?)",
            [SeedShowcaseTickets::TOTAL_TITLE, SeedShowcaseTickets::ORDERS_TITLE],
        ));
        $searchTicketBefore = $this->ticketFacts()[SeedShowcaseTickets::SEARCH_TITLE] ?? null;
        $searchRoundsBefore = $this->roundFacts()[SeedShowcaseTickets::SEARCH_TITLE] ?? null;

        $second = $this->runSeed();

        self::assertStringContainsString('Showcase tickets: created 2, kept 1.', $second->getDisplay());
        self::assertSame(['done', 'Omar', 'Mia', null], $searchTicketBefore);
        self::assertNotNull($searchRoundsBefore);
        self::assertSame($searchTicketBefore, $this->ticketFacts()[SeedShowcaseTickets::SEARCH_TITLE] ?? null);
        self::assertSame($searchRoundsBefore, $this->roundFacts()[SeedShowcaseTickets::SEARCH_TITLE] ?? null);
        self::assertSame(
            [SeedShowcaseTickets::TOTAL_TITLE, SeedShowcaseTickets::ORDERS_TITLE, SeedShowcaseTickets::SEARCH_TITLE],
            $this->underAcmeFoods(fn (): array => $this->connection()->fetchFirstColumn('SELECT title FROM tkt_ticket WHERE title IN (?, ?, ?) ORDER BY title', self::titles())),
        );
    }

    /**
     * @return array<string, list<string|null>> each showcase ticket's core step, assignee, reporter and whose turn it is
     */
    private function ticketFacts(): array
    {
        return $this->underAcmeFoods(fn (): array => $this->keyedRows(
            'SELECT t.title, s.core_code, a.name, r.name, w.name FROM tkt_ticket t
             JOIN wf_step s ON s.organization_id = t.organization_id AND s.id = t.step_id
             JOIN idn_user a ON a.id = t.assignee_id JOIN idn_user r ON r.id = t.reporter_id
             LEFT JOIN ins_ticket_turn i ON i.organization_id = t.organization_id AND i.ticket_id = t.id
             LEFT JOIN idn_user w ON w.id = i.turn_user_id
             WHERE t.title IN (?, ?, ?) ORDER BY t.title',
        ));
    }

    /**
     * @return array<string, list<string>> each ticket's rounds by number, the checklist ("-", no number) after Round 0: kind, state, owner and screenshot verdicts
     */
    private function roundFacts(): array
    {
        return $this->underAcmeFoods(fn (): array => $this->groupedRows(
            "SELECT t.title, coalesce(r.number::text, '-') || ' ' || r.kind || ' ' || r.state || ' ' || u.name || ': '
                    || coalesce((SELECT string_agg(coalesce(sc.verdict, '-'), ',' ORDER BY sc.position) FROM rvw_screenshot sc
                                 WHERE sc.organization_id = r.organization_id AND sc.round_id = r.id AND sc.verdict IS NOT NULL), '-')
             FROM rvw_round r JOIN tkt_ticket t ON t.organization_id = r.organization_id AND t.id = r.ticket_id
             JOIN idn_user u ON u.id = r.owner_id
             WHERE t.title IN (?, ?, ?) ORDER BY t.title, coalesce(r.number, 0), r.kind DESC",
        ));
    }

    /**
     * @return array<string, list<string>> each ticket's checklist items in order: the state, and the note of an open one
     */
    private function checklistFacts(): array
    {
        return $this->underAcmeFoods(fn (): array => $this->groupedRows(
            "SELECT t.title, i.state || CASE WHEN i.state = 'open' THEN ': ' || p.note ELSE '' END
             FROM rvw_check_item i JOIN rvw_pin p ON p.organization_id = i.organization_id AND p.id = i.pin_id
             JOIN rvw_round r ON r.organization_id = i.organization_id AND r.id = i.round_id
             JOIN tkt_ticket t ON t.organization_id = r.organization_id AND t.id = r.ticket_id
             WHERE t.title IN (?, ?, ?) ORDER BY t.title, i.created_at, p.number",
        ));
    }

    /**
     * @return array<string, list<string|null>>
     */
    private function keyedRows(string $sql): array
    {
        $rows = [];
        foreach ($this->connection()->fetchAllNumeric($sql, self::titles()) as $row) {
            $title = array_shift($row);
            self::assertIsString($title);
            $rows[$title] = array_map(static fn (mixed $value): ?string => null === $value ? null : (\is_string($value) ? $value : throw new \UnexpectedValueException('A text column was expected.')), $row);
        }

        return $rows;
    }

    /**
     * @return array<string, list<string>>
     */
    private function groupedRows(string $sql): array
    {
        $rows = [];
        foreach ($this->connection()->fetchAllNumeric($sql, self::titles()) as [$title, $fact]) {
            self::assertIsString($title);
            self::assertIsString($fact);
            $rows[$title][] = $fact;
        }

        return $rows;
    }

    private function rowCountOf(string $table): mixed
    {
        return $this->underAcmeFoods(fn (): mixed => $this->connection()->fetchOne('SELECT count(*) FROM '.$table));
    }

    /**
     * @return list<string>
     */
    private static function titles(): array
    {
        return [SeedShowcaseTickets::SEARCH_TITLE, SeedShowcaseTickets::TOTAL_TITLE, SeedShowcaseTickets::ORDERS_TITLE];
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
     * Runs the seed expecting the showcase step to throw, and resets the entity manager its failed transaction closed.
     */
    private function runFailingSeed(): \RuntimeException
    {
        try {
            $this->runSeed();
        } catch (\RuntimeException $failure) {
            self::getContainer()->get('doctrine')->resetManager();

            return $failure;
        }
        self::fail('The seed was expected to fail at a picture.');
    }

    /**
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    private function underAcmeFoods(\Closure $work): mixed
    {
        return $this->tenantTransactions()->rolledBackAs($this->acmeFoods(), $work);
    }

    /**
     * @param \Closure(): mixed $work
     */
    private function committedUnderAcmeFoods(\Closure $work): void
    {
        $this->tenantTransactions()->committedAs($this->acmeFoods(), $work);
    }

    private function acmeFoods(): string
    {
        $organizationId = $this->connection()->fetchOne("SELECT organization_id FROM resolve_user_orgs((SELECT id FROM idn_user WHERE email = 'sara@acme-foods.example'))");
        self::assertIsString($organizationId);

        return $organizationId;
    }

    private function tenantTransactions(): TenantTransactions
    {
        return new TenantTransactions(self::getContainer()->get('doctrine.orm.default_entity_manager'), self::getContainer()->get(TenantContext::class));
    }

    private function removeDemoRows(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        new DemoSeedRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), $this->connection(), self::getContainer()->get(TenantContext::class))
            ->removePeopleAndTheirOrganizations(self::EMAILS);
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager')->getConnection();
    }
}
