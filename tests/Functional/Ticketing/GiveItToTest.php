<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ticketing;

use App\Identity\Domain\User;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Web\CreatesAcmeFoodsTeams;
use App\Tests\Functional\Web\CreatesTeams;
use App\Ticketing\Infrastructure\Scheduler\AddTicketEventPartitions;
use App\Ticketing\UI\MoveToasts;
use App\Ticketing\UI\TicketMoveController;
use App\Web\UI\Component\PersonPickerMode;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Uid\Uuid;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Zenstruck\Foundry\Test\Factories;

/**
 * The lead's "Give it to…" on the ticket page: the button and its person picker in assign mode, through the real
 * firewall, voters and database. Shop is led by Lena with Omar a member and Mia a viewer; Raj is a Manager and Sara an
 * Admin of no team. The clock is fixed on 9 Nov 2026, before today: a clock ahead of the login would end the session.
 */
final class GiveItToTest extends WebTestCase
{
    use CreatesAcmeFoodsTeams;
    use CreatesTeams;
    use Factories;
    use InteractsWithLiveComponents;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }

    private const string NOW = '2026-11-09 08:30:00 UTC';
    private const string GIVE_FORM = '#give-it-to-form';
    private const string TRIGGER = '.next-step-box [data-person-picker-trigger]';

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
        $this->addCoreSteps();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdOrganizationIds as $organizationId) {
            $this->committedAs(new OrganizationId($organizationId), static function (Connection $migrator) use ($organizationId): int {
                foreach (['tkt_ticket_event', 'ins_ticket_turn', 'ins_project_count_ticket', 'ins_project_counts', 'tkt_ticket', 'wf_step'] as $table) {
                    $migrator->executeStatement(\sprintf('DELETE FROM %s WHERE organization_id = ?', $table), [$organizationId]);
                }
                $migrator->executeStatement("DELETE FROM messenger_outbox WHERE queue_name = 'outbox' AND position(? in body) > 0", [$organizationId]);

                return 0;
            });
        }
        $this->removeCommittedProjects();
        $this->removeUsersAndOrganizations();
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function giveButtonOfEachRole(): iterable
    {
        yield 'Lena, the team\'s lead' => ['Lena', 'Give it to…'];
        yield 'Omar, a member' => ['Omar', null];
        yield 'Mia, a viewer of the team' => ['Mia', null];
        yield 'Raj, a manager with no team role' => ['Raj', null];
        yield 'Sara, an admin with no team role' => ['Sara', null];
    }

    #[Test]
    #[DataProvider('giveButtonOfEachRole')]
    public function onlyTheLeadIsOfferedGiveItToOnABacklogTicket(string $viewer, ?string $expectedLabel): void
    {
        $key = $this->addTicket('Add a gift note', 'backlog');
        $this->logIn($this->client, $this->people[$viewer]);

        $page = $this->client->request('GET', '/t/'.$key);

        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $page->filter('.next-step-box'));
        if (null === $expectedLabel) {
            self::assertCount(0, $page->filter(self::GIVE_FORM.', '.self::TRIGGER.', dialog#give-it-to-picker'));

            return;
        }
        self::assertSame([$expectedLabel], $page->filter(self::TRIGGER)->each(static fn (Crawler $trigger): string => $trigger->text()));
        self::assertSame('Give '.$key.' to…', $page->filter('dialog#give-it-to-picker #give-it-to-picker-title')->text());
        self::assertSame('/t/'.$key.'/assign', $page->filter(self::GIVE_FORM)->attr('action'));
    }

    #[Test]
    public function theLeadIsOfferedGiveItToSomeoneElseOnAnAssignedToDoTicket(): void
    {
        $key = $this->addTicket('Cart total shows the wrong sum', 'todo', 'Omar');
        $this->logIn($this->client, $this->people['Lena']);

        $page = $this->client->request('GET', '/t/'.$key);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Give it to someone else…'], $page->filter(self::TRIGGER)->each(static fn (Crawler $trigger): string => $trigger->text()));
    }

    /**
     * Lena has two tickets waiting on her and Omar one, so Omar comes first although Lena's name sorts before his; Mia,
     * a viewer, is not listed.
     */
    #[Test]
    public function theOpenedPickerListsTheTeamFewestWaitingFirstAndARowSubmitsTheGiveForm(): void
    {
        $key = $this->addTicket('Add a gift note', 'backlog');
        $this->addTicketWaitingOn('Lena');
        $this->addTicketWaitingOn('Lena');
        $this->addTicketWaitingOn('Omar');
        $this->logIn($this->client, $this->people['Lena']);
        $page = $this->client->request('GET', '/t/'.$key);
        self::assertResponseStatusCodeSame(200);
        $search = $page->filter('dialog#give-it-to-picker [data-live-name-value="PersonPickerSearch"]');
        $props = json_decode((string) $search->attr('data-live-props-value'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($props);

        self::assertSame('assign', $props['mode'] ?? null);

        $picker = $this->createLiveComponent('PersonPickerSearch', ['mode' => PersonPickerMode::Assign, 'team' => $props['team'], 'pickForm' => $props['pickForm'], 'pickField' => $props['pickField']], $this->client);
        $picker->call('open');

        self::assertSame(200, $picker->response()->getStatusCode());
        $rows = $picker->render()->crawler()->filter('.pick');
        self::assertSame(
            [['Omar', '1 waiting'], ['Lena', '2 waiting']],
            $rows->each(static fn (Crawler $row): array => [$row->filter('.pick__name')->text(), $row->filter('.pick__side')->text()]),
        );
        $omarsRow = $rows->first();
        self::assertSame(
            ['submit', 'give-it-to-form', TicketMoveController::ASSIGNEE_FIELD, $this->people['Omar']->id()->toString()],
            [$omarsRow->attr('type'), $omarsRow->attr('form'), $omarsRow->attr('name'), $omarsRow->attr('value')],
        );
    }

    #[Test]
    public function submittingTheGiveFormWithAPickedPersonGivesTheTicket(): void
    {
        new AddTicketEventPartitions($this->migrator(), new MockClock(new \DateTimeImmutable(self::NOW)))();
        $key = $this->addTicket('Add a gift note', 'backlog');
        $this->logIn($this->client, $this->people['Lena']);
        $page = $this->client->request('GET', '/t/'.$key);
        self::assertResponseStatusCodeSame(200);
        $giveForm = $page->filter(self::GIVE_FORM)->form();
        self::assertSame([$this->stepsByCore['backlog'], null, 1], $this->stepAssigneeAndVersionOf($key));

        $this->client->request('POST', $giveForm->getUri(), [...$giveForm->getValues(), TicketMoveController::ASSIGNEE_FIELD => $this->people['Omar']->id()->toString()]);

        self::assertResponseStatusCodeSame(303);
        self::assertSame([$this->stepsByCore['todo'], $this->people['Omar']->id()->toString(), 2], $this->stepAssigneeAndVersionOf($key));
        $page = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertSame(MoveToasts::givenTo('Omar'), $page->filter('.toast-region [data-toast-message-value]')->attr('data-toast-message-value'));
        self::assertSame('Given to Omar.', MoveToasts::givenTo('Omar'));
    }

    /**
     * @return array{mixed, mixed, int}
     */
    private function stepAssigneeAndVersionOf(string $key): array
    {
        $row = [];
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($key, &$row): int {
            $row = $migrator->fetchAssociative('SELECT step_id, assignee_id, version FROM tkt_ticket WHERE key = ?', [$key]);

            return 0;
        });
        self::assertIsArray($row);
        self::assertIsInt($row['version']);

        return [$row['step_id'], $row['assignee_id'], $row['version']];
    }

    /**
     * An open To do ticket whose turn row, as Insight's turn projector keeps it, waits on $person.
     */
    private function addTicketWaitingOn(string $person): void
    {
        $key = $this->addTicket('Waiting on '.$person, 'todo', $person);
        $this->committedAs($this->acmeFoods, fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO ins_ticket_turn (organization_id, ticket_id, team_id, project_id, grp, turn_user_id, turn_label, kind, base_score, waiting_since, visibility, reporter_id, assignee_id)
             SELECT organization_id, id, team_id, project_id, 'todo', ?, NULL, 'build', 100, TIMESTAMPTZ '2026-11-05 10:00:00+00', visibility, reporter_id, assignee_id
             FROM tkt_ticket WHERE key = ?",
            [$this->people[$person]->id()->toString(), $key],
        ));
    }

    private function addCoreSteps(): void
    {
        $organizationId = $this->acmeFoods->toString();
        $steps = [];
        foreach ([
            ['Backlog', 'todo', 'backlog'], ['To do', 'todo', 'todo'], ['In progress', 'doing', 'progress'], ['Code review', 'check', 'review'],
            ['Testing', 'check', 'test'], ['Needs input', 'wait', 'input'], ['On hold', 'wait', 'hold'], ['Done', 'done', 'done'],
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
     * A normal bug of Shop's team in project SHOP, for everyone, reported by Lena on 5 Nov.
     *
     * @return string the ticket's key
     */
    private function addTicket(string $title, string $coreCode, ?string $assignee = null): string
    {
        $number = $this->nextNumber++;
        $key = 'SHOP-'.$number;
        $parameters = [
            $this->acmeFoods->toString(), Uuid::v7()->toRfc4122(), $this->shopProject, $this->shop->toString(), $number, $key, $title,
            $this->stepsByCore[$coreCode], $this->people['Lena']->id()->toString(), null === $assignee ? null : $this->people[$assignee]->id()->toString(),
        ];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO tkt_ticket (organization_id, id, project_id, team_id, number, key, title, description, type, priority, priority_rank,
                 step_id, reporter_id, visibility, assignee_id, components, check_reasons, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, '', 'bug', 'normal', 3, ?, ?, 'all', ?, '{}', '{}', TIMESTAMPTZ '2026-11-05 10:00:00+00', TIMESTAMPTZ '2026-11-05 10:00:00+00')",
            $parameters,
        ));

        return $key;
    }
}
