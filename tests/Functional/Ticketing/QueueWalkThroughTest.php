<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ticketing;

use App\Identity\Domain\User;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Web\CreatesAcmeFoodsTeams;
use App\Tests\Functional\Web\CreatesTeams;
use App\Ticketing\UI\QueueController;
use App\Ticketing\UI\TicketQueue;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * The queue walk-through (P03.T10) on Acme Foods' project SHOP through the real firewall, voters and database: "Go
 * through them" on a step of the tickets list, Finish and Stop. The clock is fixed on 6 Nov 2026, before today: a
 * clock ahead of the login would end the session as idle.
 *
 * The Code review step holds, in list order, R2, R3 and R1 (equal priority, most recently updated first), then the
 * team-only H that only Shop's people and admins see; To do holds T, which no Code review queue may take.
 */
final class QueueWalkThroughTest extends WebTestCase
{
    use CreatesAcmeFoodsTeams;
    use CreatesTeams;
    use Factories;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }

    private const string NOW = '2026-11-06 12:00:00 UTC';
    /** A refused viewer must never get a word of it. */
    private const string HIDDEN_TITLE = 'Price rules for staff';
    private const string START = '/tickets/queue/start/list-step';
    private const string FINISH = '/tickets/queue/finish';

    private KernelBrowser $client;

    /** @var array<string, User> */
    private array $people;

    private string $shopProject;

    /** @var array<string, string> step id by group code */
    private array $steps = [];

    /** @var array<string, string> each ticket's key by its letter */
    private array $keys = [];

    private int $nextNumber = 1;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTPS' => 'on']);
        $this->client->disableReboot();
        self::getContainer()->set('clock', new MockClock(new \DateTimeImmutable(self::NOW)));
        $this->people = $this->createAcmeFoodsPeople();
        $this->shopProject = $this->addProject($this->shop, 'SHOP', 'Shop');
        $this->addSteps();
        $this->keys['T'] = $this->addTicket('todo', 'Add a gift note', '2026-11-05 13:00:00+00');
        $this->keys['R1'] = $this->addTicket('check', 'Search shows no results', '2026-11-05 10:00:00+00', 'Omar');
        $this->keys['R2'] = $this->addTicket('check', 'Cart total shows the wrong sum', '2026-11-05 12:00:00+00');
        $this->keys['H'] = $this->addTicket('check', self::HIDDEN_TITLE, '2026-11-05 09:00:00+00', null, 'team');
        $this->keys['R3'] = $this->addTicket('check', 'Coupon field takes any text', '2026-11-05 11:00:00+00', 'Omar');
    }

    protected function tearDown(): void
    {
        foreach ($this->createdOrganizationIds as $organizationId) {
            $this->committedAs(new OrganizationId($organizationId), static function (Connection $migrator) use ($organizationId): int {
                foreach (['ins_ticket_turn', 'ins_project_count_ticket', 'ins_project_counts', 'tkt_ticket', 'wf_step'] as $table) {
                    $migrator->executeStatement(\sprintf('DELETE FROM %s WHERE organization_id = ?', $table), [$organizationId]);
                }

                return 0;
            });
        }
        $this->removeCommittedProjects();
        $this->removeUsersAndOrganizations();
    }

    #[Test]
    public function goThroughThemQueuesTheStepsTicketsInListOrderAndOpensTheFirst(): void
    {
        $this->logIn($this->client, $this->people['Lena']);

        $this->goThrough('/tickets', 'check');

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects('/t/'.$this->keys['R2']);
        self::assertEquals(new TicketQueue('Code review', [$this->keys['R2'], $this->keys['R3'], $this->keys['R1'], $this->keys['H']]), $this->queue());
    }

    #[Test]
    public function aViewerOutsideTheTeamQueuesNoTeamOnlyTicket(): void
    {
        $this->logIn($this->client, $this->people['Raj']);

        $this->goThrough('/tickets', 'check');

        self::assertResponseStatusCodeSame(303);
        self::assertSame([$this->keys['R2'], $this->keys['R3'], $this->keys['R1']], $this->queue()?->keys);
    }

    #[Test]
    public function theListsFiltersPickTheQueuedTicketsAndTravelToTheTicketPage(): void
    {
        $this->logIn($this->client, $this->people['Lena']);
        $omar = $this->people['Omar']->id()->toString();

        $this->goThrough('/tickets?project=SHOP&person='.$omar, 'check');

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects('/t/'.$this->keys['R3'].'?project=SHOP&person='.$omar);
        self::assertSame([$this->keys['R3'], $this->keys['R1']], $this->queue()?->keys);
    }

    #[Test]
    public function aStepOfMoreThanAHundredTicketsQueuesItsFirstHundredAcrossPages(): void
    {
        $firstNumber = $this->addManyTickets('doing', 120);
        $this->logIn($this->client, $this->people['Lena']);

        $this->goThrough('/tickets', 'doing');

        self::assertResponseStatusCodeSame(303);
        $keys = $this->queue()->keys ?? [];
        self::assertCount(TicketQueue::MAX_KEYS, $keys);
        // Each later ticket is updated a minute later, so the newest comes first and the oldest twenty are left out.
        self::assertSame('SHOP-'.($firstNumber + 119), $keys[0]);
        self::assertSame('SHOP-'.($firstNumber + 69), $keys[50]);
        self::assertSame('SHOP-'.($firstNumber + 20), $keys[99]);
        self::assertResponseRedirects('/t/SHOP-'.($firstNumber + 119));
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function refusedTokens(): iterable
    {
        yield 'no token' => [null];
        yield 'a forged token' => ['forged'];
    }

    #[Test]
    #[DataProvider('refusedTokens')]
    public function startingNeedsTheFormsTokenAndStoresNoQueueWithout(?string $token): void
    {
        $this->logIn($this->client, $this->people['Lena']);

        $this->client->request('POST', self::START, [QueueController::STEP_FIELD => $this->steps['check'], ...self::tokenField($token)]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(QueueController::FORM_EXPIRED, $this->client->getResponse()->getContent());
        self::assertNull($this->queue());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function stepsOfNoQueue(): iterable
    {
        yield 'not a uuid' => ['SHOP-1'];
        yield 'an upper-case uuid' => ['0197A3C0-0000-7000-8000-00000000F0D0'];
        yield 'a uuid of no step' => ['0197a3c0-0000-7000-8000-00000000f0d0'];
        yield 'empty' => [''];
    }

    #[Test]
    #[DataProvider('stepsOfNoQueue')]
    public function aStepIdOfNoStepIsNotFoundAndStoresNoQueue(string $stepId): void
    {
        $this->logIn($this->client, $this->people['Lena']);

        $this->client->request('POST', self::START, ['_csrf_token' => $this->listToken(), QueueController::STEP_FIELD => $stepId]);

        self::assertResponseStatusCodeSame(404);
        self::assertNull($this->queue());
    }

    #[Test]
    public function aStepWithOnlyTicketsTheViewerMayNotSeeIsNotFoundAndNamesNoTicket(): void
    {
        $this->addTicket('wait', self::HIDDEN_TITLE, '2026-11-05 08:00:00+00', null, 'team');
        $this->logIn($this->client, $this->people['Raj']);

        $this->client->request('POST', self::START, ['_csrf_token' => $this->listToken(), QueueController::STEP_FIELD => $this->steps['wait']]);

        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString(self::HIDDEN_TITLE, (string) $this->client->getResponse()->getContent());
        self::assertNull($this->queue());
    }

    #[Test]
    public function aStepOutsideTheListsFiltersIsNotFound(): void
    {
        $this->logIn($this->client, $this->people['Lena']);

        $this->client->request('POST', self::START.'?person='.$this->people['Omar']->id()->toString(), [
            '_csrf_token' => $this->listToken(), QueueController::STEP_FIELD => $this->steps['todo'],
        ]);

        self::assertResponseStatusCodeSame(404);
        self::assertNull($this->queue());
    }

    #[Test]
    public function anUnknownStartSourceIsNotFound(): void
    {
        $this->logIn($this->client, $this->people['Lena']);

        $this->client->request('POST', '/tickets/queue/start/home-group', ['_csrf_token' => $this->listToken(), QueueController::STEP_FIELD => $this->steps['check']]);

        self::assertResponseStatusCodeSame(404);
        self::assertNull($this->queue());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function queueRoutes(): iterable
    {
        yield 'start' => [self::START];
        yield 'finish' => [self::FINISH];
        yield 'stop' => ['/t/SHOP-1/queue/stop'];
    }

    #[Test]
    #[DataProvider('queueRoutes')]
    public function anAnonymousVisitorIsSentToLogIn(string $uri): void
    {
        $this->client->request('POST', $uri, [QueueController::STEP_FIELD => $this->steps['check']]);

        self::assertResponseStatusCodeSame(302);
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    #[Test]
    public function finishEndsTheQueueAndSaysAllDoneOnHome(): void
    {
        $this->logIn($this->client, $this->people['Lena']);
        $token = $this->listToken();
        $this->goThrough('/tickets', 'check');

        $this->client->request('POST', self::FINISH, ['_csrf_token' => $token]);

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects('/');
        self::assertNull($this->queue());
        $home = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertSame('All done in Code review.', $home->filter('.toast-region [data-toast-message-value]')->attr('data-toast-message-value'));
    }

    #[Test]
    public function finishWithNoQueueLeftOpensHomeWithNoToast(): void
    {
        $this->logIn($this->client, $this->people['Lena']);

        $this->client->request('POST', self::FINISH, ['_csrf_token' => $this->listToken()]);

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects('/');
        $home = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $home->filter('.toast-region [data-toast-message-value]'));
    }

    #[Test]
    #[DataProvider('refusedTokens')]
    public function finishAndStopNeedTheFormsTokenAndKeepTheQueueWithout(?string $token): void
    {
        $this->logIn($this->client, $this->people['Lena']);
        $this->goThrough('/tickets', 'check');
        $queue = $this->queue();
        self::assertNotNull($queue);

        foreach ([self::FINISH, '/t/'.$this->keys['R2'].'/queue/stop'] as $uri) {
            $this->client->request('POST', $uri, [QueueController::BACK_FIELD => '1', ...self::tokenField($token)]);

            self::assertResponseStatusCodeSame(422);
            self::assertSame(QueueController::FORM_EXPIRED, $this->client->getResponse()->getContent());
            self::assertEquals($queue, $this->queue());
        }
    }

    #[Test]
    public function stopEndsTheQueueAndOpensTheTicketAgainInItsList(): void
    {
        $this->logIn($this->client, $this->people['Lena']);
        $token = $this->listToken();
        $this->goThrough('/tickets?project=SHOP', 'check');

        $this->client->request('POST', '/t/'.$this->keys['R3'].'/queue/stop?project=SHOP', ['_csrf_token' => $token]);

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects('/t/'.$this->keys['R3'].'?project=SHOP');
        self::assertNull($this->queue());
    }

    #[Test]
    public function stopFromBackEndsTheQueueAndOpensTheList(): void
    {
        $this->logIn($this->client, $this->people['Lena']);
        $token = $this->listToken();
        $this->goThrough('/tickets?project=SHOP', 'check');

        $this->client->request('POST', '/t/'.$this->keys['R3'].'/queue/stop?project=SHOP', ['_csrf_token' => $token, QueueController::BACK_FIELD => '1']);

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects('/tickets?project=SHOP');
        self::assertNull($this->queue());
    }

    #[Test]
    public function stopWithAListTheListRefusesOpensTheTicketWithNoList(): void
    {
        $this->logIn($this->client, $this->people['Lena']);
        $token = $this->listToken();
        $this->goThrough('/tickets', 'check');

        $this->client->request('POST', '/t/'.$this->keys['R3'].'/queue/stop?project=NOPE', ['_csrf_token' => $token]);

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects('/t/'.$this->keys['R3']);
        self::assertNull($this->queue());
    }

    #[Test]
    public function stopOnATicketTheViewerMayNotSeeLeadsToNotFoundWithNoWordOfIt(): void
    {
        $this->logIn($this->client, $this->people['Raj']);
        $token = $this->listToken();

        $this->client->request('POST', '/t/'.$this->keys['H'].'/queue/stop', ['_csrf_token' => $token]);

        self::assertResponseStatusCodeSame(303);
        $this->client->followRedirect();
        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString(self::HIDDEN_TITLE, (string) $this->client->getResponse()->getContent());
    }

    /**
     * Opens the list at $listUri and sends its "Go through them" form of the step of $groupCode.
     */
    private function goThrough(string $listUri, string $groupCode): void
    {
        $list = $this->client->request('GET', $listUri);
        self::assertResponseStatusCodeSame(200);
        $form = $list->filter('[data-group="'.$groupCode.'"] form.tickets__go-through');
        self::assertCount(1, $form, 'The step has no "Go through them" form.');
        self::assertSame('Go through them', $form->filter('button')->text());

        $this->client->submit($form->form());
    }

    /**
     * The queue form's token, read off the list as a person would get it.
     */
    private function listToken(): string
    {
        $list = $this->client->request('GET', '/tickets');
        self::assertResponseStatusCodeSame(200);

        return (string) $list->filter('form.tickets__go-through input[name="_csrf_token"]')->first()->attr('value');
    }

    /**
     * @return array<string, string> the token field, or no field for a null token
     */
    private static function tokenField(?string $token): array
    {
        return null === $token ? [] : ['_csrf_token' => $token];
    }

    private function queue(): ?TicketQueue
    {
        return TicketQueue::of($this->client->getSession() ?? throw new \LogicException('The client has no session.'));
    }

    private function addSteps(): void
    {
        $organizationId = $this->acmeFoods->toString();
        $steps = [];
        foreach (['wait' => ['Needs input', 'input'], 'check' => ['Code review', 'review'], 'doing' => ['In progress', 'progress'], 'todo' => ['To do', 'todo'], 'done' => ['Done', 'done']] as $groupCode => [$name, $coreCode]) {
            $this->steps[$groupCode] = Uuid::v7()->toRfc4122();
            $steps[] = [$this->steps[$groupCode], $name, $groupCode, $coreCode, \count($steps)];
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
     * A major bug of Shop's team in SHOP, reported by Lena.
     *
     * @return string the ticket's key
     */
    private function addTicket(string $groupCode, string $title, string $updatedAt, ?string $assignee = null, string $visibility = 'all'): string
    {
        $number = $this->nextNumber++;
        $key = 'SHOP-'.$number;
        $parameters = [
            $this->acmeFoods->toString(), Uuid::v7()->toRfc4122(), $this->shopProject, $this->shop->toString(), $number, $key, $title,
            $this->steps[$groupCode], $this->people['Lena']->id()->toString(), $visibility,
            null === $assignee ? null : $this->people[$assignee]->id()->toString(), $updatedAt,
        ];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO tkt_ticket (organization_id, id, project_id, team_id, number, key, title, description, type, priority,
                 priority_rank, step_id, reporter_id, visibility, assignee_id, components, check_reasons, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, '', 'bug', 'major', 2, ?, ?, ?, ?, '{}', '{}', TIMESTAMPTZ '2026-11-04 09:00:00+00', CAST(? AS timestamptz))",
            $parameters,
        ));

        return $key;
    }

    /**
     * Major bugs of Shop's team for everyone in one statement, each updated a minute later than the one before.
     *
     * @return int the first ticket's number
     */
    private function addManyTickets(string $groupCode, int $count): int
    {
        $firstNumber = $this->nextNumber;
        $this->nextNumber += $count;
        $parameters = [$this->acmeFoods->toString(), $this->shopProject, $this->shop->toString(), $this->steps[$groupCode], $this->people['Lena']->id()->toString(), $firstNumber, $firstNumber + $count - 1];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO tkt_ticket (organization_id, id, project_id, team_id, number, key, title, description, type, priority, priority_rank,
                 step_id, reporter_id, visibility, components, check_reasons, created_at, updated_at)
             SELECT CAST(? AS uuid), CAST('0197a3c0-0000-7000-8003-' || lpad(to_hex(number), 12, '0') AS uuid), CAST(? AS uuid), CAST(? AS uuid), number, 'SHOP-' || number, 'Checkout button is hidden', '',
                 'bug', 'major', 2, CAST(? AS uuid), CAST(? AS uuid), 'all', '{}', '{}',
                 TIMESTAMPTZ '2026-11-04 08:00:00+00', TIMESTAMPTZ '2026-11-04 08:00:00+00' + number * INTERVAL '1 minute'
             FROM generate_series(CAST(? AS int), CAST(? AS int)) AS number",
            $parameters,
        ));

        return $firstNumber;
    }
}
