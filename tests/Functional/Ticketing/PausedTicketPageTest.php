<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ticketing;

use App\Identity\Domain\User;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Web\CreatesAcmeFoodsTeams;
use App\Tests\Functional\Web\CreatesTeams;
use App\Ticketing\UI\TicketPageParts;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * The ticket page of a ticket an open question pauses, on Acme Foods' project SHOP through the real firewall, voters
 * and database. The ticket is In progress with Omar; the pause is seeded on tkt_ticket and on Insight's saved turn as
 * Ticketing's TicketPauses and the turn projector leave them. The clock is fixed on 6 Nov 2026, before today.
 */
final class PausedTicketPageTest extends WebTestCase
{
    use CreatesAcmeFoodsTeams;
    use CreatesTeams;
    use Factories;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }

    private const string NOW = '2026-11-06 12:00:00 UTC';
    private const string SHELL = '/_test/menu/shell';
    private const string PAUSE_NOTE = 'Paused until Lena answers the question below.';
    /** A user id with no user row, as for an asked person who has since been removed. */
    private const string GONE_USER_ID = '0190a000-0000-7000-8000-0000000000ee';

    private KernelBrowser $client;

    /** @var array<string, User> */
    private array $people;

    private string $shopProject;

    /** @var array<string, string> step id by core code */
    private array $stepsByCore = [];

    private int $nextNumber = 1;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTPS' => 'on']);
        $this->client->disableReboot();
        self::getContainer()->set('clock', new MockClock(new \DateTimeImmutable(self::NOW)));
        $this->people = $this->createAcmeFoodsPeople();
        $this->shopProject = $this->addProject($this->shop, 'SHOP', 'Shop');
        $this->addSteps();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdOrganizationIds as $organizationId) {
            $this->committedAs(new OrganizationId($organizationId), static function (Connection $migrator) use ($organizationId): int {
                foreach (['ins_ticket_turn', 'tkt_ticket', 'wf_step'] as $table) {
                    $migrator->executeStatement(\sprintf('DELETE FROM %s WHERE organization_id = ?', $table), [$organizationId]);
                }

                return 0;
            });
        }
        $this->removeCommittedProjects();
        $this->removeUsersAndOrganizations();
    }

    #[Test]
    public function thePillNamesTheAskedPersonInTheWaitingColoursAndNowNamesTheAsker(): void
    {
        $key = $this->addInProgressTicket();
        $this->pause($key, asked: 'Lena', asker: 'Omar');
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', '/t/'.$key);

        self::assertResponseStatusCodeSame(200);
        $pill = $page->filter('#'.TicketPageParts::STATE_ID.' .pill');
        self::assertSame('Waiting · question to Lena', $pill->text());
        self::assertSame('pill pill-waiting', $pill->attr('class'));
        self::assertSame('Now: Lena answers Omar’s question', $page->filter('.ticket__now')->text());
        // The step stays In progress while paused: the answer sends the ticket back to it.
        self::assertSame('Next: it goes back to In progress', $page->filter('.ticket__next')->text());
        self::assertSame($this->stepsByCore['progress'], $this->stepIdOf($key));
    }

    #[Test]
    public function theLeadWhoIsAskedIsToldToAnswerAndGetsNoVerbsNoMoveListAndThePauseNote(): void
    {
        $key = $this->addInProgressTicket();
        $this->pause($key, asked: 'Lena', asker: 'Omar');
        $this->logIn($this->client, $this->people['Lena']);

        $page = $this->client->request('GET', '/t/'.$key);

        self::assertResponseStatusCodeSame(200);
        $box = $this->nextStepBoxOf($page);
        self::assertSame('Answer Omar’s question below.', $box->filter('.next-step-box__sentence')->text());
        self::assertSame(self::PAUSE_NOTE, $box->filter('.next-step-box__rule-note')->text());
        self::assertCount(0, $box->filter('.next-step-box__verbs'));
        self::assertCount(0, $box->filter('details.next-step-box__move-to'));
        self::assertCount(0, $page->filter('form[action*="/move"]'));
        self::assertCount(0, $page->filter('dialog[id^="needs-input-"]'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function viewersWhoWaitForTheAnswer(): iterable
    {
        yield 'Omar, who asked and gets the answer' => ['Omar', 'Wait for Lena’s answer. You get one notification.'];
        yield 'Mia, a viewer of the team' => ['Mia', 'Wait for Lena’s answer.'];
        yield 'Sara, an admin with no team role' => ['Sara', 'Wait for Lena’s answer.'];
    }

    #[Test]
    #[DataProvider('viewersWhoWaitForTheAnswer')]
    public function everyoneElseWaitsForTheAnswerWithNoMoveVerbs(string $viewer, string $expectedSentence): void
    {
        $key = $this->addInProgressTicket();
        $this->pause($key, asked: 'Lena', asker: 'Omar');
        $this->logIn($this->client, $this->people[$viewer]);

        $page = $this->client->request('GET', '/t/'.$key);

        self::assertResponseStatusCodeSame(200);
        $box = $this->nextStepBoxOf($page);
        self::assertSame($expectedSentence, $box->filter('.next-step-box__sentence')->text());
        self::assertCount(0, $box->filter('.next-step-box__verbs'));
        self::assertCount(0, $box->filter('details.next-step-box__move-to'));
        self::assertCount(0, $page->filter('form[action*="/move"]'));
    }

    /**
     * Sara, an admin of no team, asked Omar: her name is not among the ticket's people, so it comes with the names the
     * page reads anyway, and she still gets no move verb.
     */
    #[Test]
    public function anAdminWithNoTeamRoleWhoAskedIsNamedAndStillGetsNoMoveVerbs(): void
    {
        $key = $this->addInProgressTicket();
        $this->pause($key, asked: 'Omar', asker: 'Sara');
        $this->logIn($this->client, $this->people['Sara']);

        $page = $this->client->request('GET', '/t/'.$key);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Waiting · question to Omar', $page->filter('#'.TicketPageParts::STATE_ID.' .pill')->text());
        self::assertSame('Now: Omar answers Sara’s question', $page->filter('.ticket__now')->text());
        $box = $this->nextStepBoxOf($page);
        self::assertSame('Wait for Omar’s answer. You get one notification.', $box->filter('.next-step-box__sentence')->text());
        self::assertCount(0, $box->filter('.next-step-box__verbs'));
        self::assertCount(0, $page->filter('form[action*="/move"]'));
    }

    #[Test]
    public function theAskedPersonWithNoTeamRoleIsToldToAnswer(): void
    {
        $key = $this->addInProgressTicket();
        $this->pause($key, asked: 'Sara', asker: 'Omar');
        $this->logIn($this->client, $this->people['Sara']);

        $page = $this->client->request('GET', '/t/'.$key);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Answer Omar’s question below.', $this->nextStepBoxOf($page)->filter('.next-step-box__sentence')->text());
        self::assertCount(0, $page->filter('form[action*="/move"]'));
    }

    /**
     * The asked person's user row is gone, so the page has no name for them and the pill says so instead of failing.
     */
    #[Test]
    public function thePillSaysAnUnknownPersonWhenTheAskedPersonHasNoName(): void
    {
        $key = $this->addInProgressTicket();
        $this->pauseWith($key, self::GONE_USER_ID, $this->people['Omar']->id()->toString());
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', '/t/'.$key);

        self::assertResponseStatusCodeSame(200);
        $pill = $page->filter('#'.TicketPageParts::STATE_ID.' .pill');
        self::assertSame('Waiting · question to an unknown person', $pill->text());
        self::assertSame('pill pill-waiting', $pill->attr('class'));
        self::assertSame('Wait for an unknown person’s answer. You get one notification.', $this->nextStepBoxOf($page)->filter('.next-step-box__sentence')->text());
    }

    /**
     * @return iterable<string, array{string, string, string, string, int, int}>
     */
    public static function pausesAndTheirQueryCounts(): iterable
    {
        yield 'Omar asks Lena, both among the ticket\'s people: no names query either way' => ['Omar', 'Lena', 'Omar', 'Now: Lena answers Omar’s question', 6, 6];
        yield 'Sara, not among the ticket\'s people, asks Lena: the pause adds the one names query' => ['Omar', 'Lena', 'Sara', 'Now: Lena answers Sara’s question', 6, 7];
        yield 'Sara asks Raj, whose turn already needs the names query: Sara comes in the same one' => ['Raj', 'Raj', 'Sara', 'Now: Raj answers Sara’s question', 7, 7];
    }

    /**
     * Mia has no move list paused or not, so both counts read the same parts. Beyond the shell, with the steps, the
     * rules and the team's people cached: the settings, the ticket with its people's names, What happened's moves, the
     * saved turn with the waiting turns and the ticket's place in the plain open list; and one batch for the names of
     * the turn person and the asker when the ticket does not hold them. The ticket page's budget is 10.
     */
    #[Test]
    #[DataProvider('pausesAndTheirQueryCounts')]
    public function aPausedPageReadsTheQueriesOfTheSamePageUnpausedAndAtMostTheOneNamesQuery(
        string $turnPerson,
        string $asked,
        string $asker,
        string $expectedNowLine,
        int $expectedUnpausedQueryCount,
        int $expectedPausedQueryCount,
    ): void {
        $key = $this->addInProgressTicket();
        $this->addTurnRow($key, $this->people[$turnPerson]->id()->toString(), 'Omar' === $turnPerson ? 'build' : 'decide');
        $this->logIn($this->client, $this->people['Mia']);
        $this->queryCountOf(self::SHELL);
        $shellQueryCount = $this->queryCountOf(self::SHELL);
        $this->queryCountOf('/t/'.$key);
        $unpausedQueryCount = $this->queryCountOf('/t/'.$key) - $shellQueryCount;
        $unpausedPage = $this->client->getCrawler();
        self::assertSame('In progress', $unpausedPage->filter('#'.TicketPageParts::STATE_ID.' .pill')->text());
        self::assertCount(0, $unpausedPage->filter('details.next-step-box__move-to, dialog:not([id^="ping-"])'));

        $this->pause($key, asked: $asked, asker: $asker);
        $pausedQueryCount = $this->queryCountOf('/t/'.$key) - $shellQueryCount;

        $pausedPage = $this->client->getCrawler();
        self::assertSame('Waiting · question to '.$asked, $pausedPage->filter('#'.TicketPageParts::STATE_ID.' .pill')->text());
        self::assertSame($expectedNowLine, $pausedPage->filter('.ticket__now')->text());
        self::assertCount(0, $pausedPage->filter('details.next-step-box__move-to, dialog:not([id^="ping-"])'));
        self::assertSame($expectedUnpausedQueryCount, $unpausedQueryCount);
        self::assertSame($expectedPausedQueryCount, $pausedQueryCount);
    }

    private function nextStepBoxOf(Crawler $page): Crawler
    {
        $box = $page->filter('.ticket__columns > .ticket__next-step > .next-step-box');
        self::assertCount(1, $box);

        return $box;
    }

    /**
     * The pause as TicketPauses saves it and the turn projector's row for the asked person, kind answer.
     */
    private function pause(string $ticketKey, string $asked, string $asker): void
    {
        $this->pauseWith($ticketKey, $this->people[$asked]->id()->toString(), $this->people[$asker]->id()->toString());
    }

    private function pauseWith(string $ticketKey, string $askedUserId, string $askerId): void
    {
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            'UPDATE tkt_ticket SET asked_user_id = ?, asker_id = ? WHERE key = ?',
            [$askedUserId, $askerId, $ticketKey],
        ));
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            'DELETE FROM ins_ticket_turn WHERE ticket_id = (SELECT id FROM tkt_ticket WHERE key = ?)',
            [$ticketKey],
        ));
        $this->addTurnRow($ticketKey, $askedUserId, 'answer');
    }

    private function addTurnRow(string $ticketKey, string $turnUserId, string $kind): void
    {
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO ins_ticket_turn (organization_id, ticket_id, team_id, project_id, grp, turn_user_id, turn_label, kind, base_score, waiting_since, visibility, reporter_id, assignee_id)
             SELECT organization_id, id, team_id, project_id, 'doing', ?, NULL, ?, 100, TIMESTAMPTZ '2026-11-05 10:00:00+00', visibility, reporter_id, assignee_id
             FROM tkt_ticket WHERE key = ?",
            [$turnUserId, $kind, $ticketKey],
        ));
    }

    private function queryCountOf(string $uri): int
    {
        $debugData = self::getContainer()->get('doctrine.debug_data_holder');
        $debugData->reset();
        $this->client->request('GET', $uri);
        self::assertResponseStatusCodeSame(200);

        $queryCount = 0;
        foreach ($debugData->getData() as $queriesOfOneConnection) {
            $queryCount += \count($queriesOfOneConnection);
        }

        return $queryCount;
    }

    private function stepIdOf(string $key): string
    {
        $stepId = null;
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($key, &$stepId): int {
            $stepId = $migrator->fetchOne('SELECT step_id FROM tkt_ticket WHERE key = ?', [$key]);

            return 0;
        });
        self::assertIsString($stepId);

        return $stepId;
    }

    private function addSteps(): void
    {
        $organizationId = $this->acmeFoods->toString();
        $steps = [];
        foreach ([
            ['Needs input', 'wait', 'input'], ['Code review', 'check', 'review'], ['In progress', 'doing', 'progress'], ['To do', 'todo', 'todo'],
            ['Done', 'done', 'done'], ['Backlog', 'todo', 'backlog'], ['Testing', 'check', 'test'], ['On hold', 'wait', 'hold'],
        ] as [$name, $groupCode, $coreCode]) {
            $stepId = Uuid::v7()->toRfc4122();
            $this->stepsByCore[$coreCode] = $stepId;
            $steps[] = [$stepId, $name, $groupCode, $coreCode, \count($steps)];
        }
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($organizationId, $steps): int {
            foreach ($steps as $step) {
                $migrator->executeStatement(
                    "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, ?, ?, ?, 'core', ?)",
                    [$organizationId, ...$step],
                );
            }

            return 0;
        });
    }

    /**
     * A normal bug of Shop's team for everyone, reported by Lena and In progress with Omar.
     *
     * @return string the ticket's key
     */
    private function addInProgressTicket(): string
    {
        $number = $this->nextNumber++;
        $key = 'SHOP-'.$number;
        $this->committedAs($this->acmeFoods, fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO tkt_ticket (organization_id, id, project_id, team_id, number, key, title, description, type, priority,
                 priority_rank, step_id, reporter_id, visibility, assignee_id, components, check_reasons, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, 'Cart total shows the wrong sum', '', 'bug', 'normal', 3, ?, ?, 'all', ?, '{}', '{}',
                 TIMESTAMPTZ '2026-11-04 10:00:00+00', TIMESTAMPTZ '2026-11-05 10:00:00+00')",
            [
                $this->acmeFoods->toString(), Uuid::v7()->toRfc4122(), $this->shopProject, $this->shop->toString(), $number, $key,
                $this->stepsByCore['progress'], $this->people['Lena']->id()->toString(), $this->people['Omar']->id()->toString(),
            ],
        ));

        return $key;
    }
}
