<?php

declare(strict_types=1);

namespace App\Tests\Functional\Review\ReportDraft;

use App\Identity\Domain\User;
use App\Review\Api\ReportDraftRefused;
use App\Review\UI\ReportDraftController;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Review\SeedsTicketRounds;
use App\Tests\Functional\Web\CreatesAcmeFoodsTeams;
use App\Tests\Functional\Web\CreatesTeams;
use App\Tests\Functional\Web\IssuesCsrfTokens;
use App\Ticketing\Api\ReportGiveTo;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * "Give it to" on a report draft's Send (P14.T9, FR-INT-01): the same choice as Report a problem. Lena leads Shop and
 * Mobile; Omar is Shop's developer and Mia its tester; Raj is a manager and Sara an admin, of no team. Lena has one open
 * ticket and Omar none, so the least loaded comes first and the order differs from the names'. The clock is frozen on
 * 6 Nov 2026.
 */
final class ReportDraftGiveItToTest extends WebTestCase
{
    use CreatesAcmeFoodsTeams;
    use CreatesTeams;
    use Factories;
    use IssuesCsrfTokens;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }
    use SeedsTicketRounds;

    private const string NOW = '2026-11-06 12:00:00 UTC';
    private const string DRAFTS = '/report-drafts';
    private const string TITLE = 'Total is doubled on the cart';

    private KernelBrowser $client;

    /** @var array<string, User> */
    private array $people;

    /** Read by SeedsTicketRounds. */
    protected string $shopProject;
    /** The To do step, read by SeedsTicketRounds. */
    private string $stepId;
    private string $backlogStepId;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTPS' => 'on']);
        $this->client->disableReboot();
        self::getContainer()->set('clock', new MockClock(new \DateTimeImmutable(self::NOW)));
        $this->people = $this->createAcmeFoodsPeople('member');
        $this->shopProject = $this->addProject($this->shop, 'SHOP', 'Shop');
        $this->stepId = Uuid::v7()->toRfc4122();
        $this->backlogStepId = Uuid::v7()->toRfc4122();
        $parameters = [
            'organizationId' => $this->acmeFoods->toString(),
            'shop' => $this->shop->toString(),
            'project' => $this->shopProject,
            'omar' => $this->people['Omar']->id()->toString(),
            'mia' => $this->people['Mia']->id()->toString(),
            'lena' => $this->people['Lena']->id()->toString(),
            'todo' => $this->stepId,
            'backlog' => $this->backlogStepId,
            'lenasTicket' => Uuid::v7()->toRfc4122(),
        ];
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($parameters): int {
            $migrator->executeStatement("UPDATE org_team_member SET job = 'developer' WHERE team_id = ? AND user_id = ?", [$parameters['shop'], $parameters['omar']]);
            $migrator->executeStatement("UPDATE org_team_member SET job = 'tester' WHERE team_id = ? AND user_id = ?", [$parameters['shop'], $parameters['mia']]);
            $migrator->executeStatement("INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, 'Backlog', 'todo', 'backlog', 'core', 0)", [$parameters['organizationId'], $parameters['backlog']]);
            $migrator->executeStatement("INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, 'To do', 'todo', 'todo', 'core', 1)", [$parameters['organizationId'], $parameters['todo']]);
            $migrator->executeStatement(
                "INSERT INTO tkt_ticket (organization_id, id, project_id, team_id, number, key, title, description, type, priority, priority_rank,
                     step_id, reporter_id, assignee_id, visibility, version, components, check_reasons, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 90, 'SHOP-90', 'Checkout button does nothing', '', 'bug', 'normal', 3, ?, ?, ?, 'all', 1, '{}', '{}',
                     TIMESTAMPTZ '2026-11-04 10:00:00+00', TIMESTAMPTZ '2026-11-05 10:00:00+00')",
                [$parameters['organizationId'], $parameters['lenasTicket'], $parameters['project'], $parameters['shop'], $parameters['todo'], $parameters['mia'], $parameters['lena']],
            );

            return 0;
        });
    }

    protected function tearDown(): void
    {
        foreach ($this->createdOrganizationIds as $organizationId) {
            $this->committedAs(new OrganizationId($organizationId), static fn (Connection $migrator): int|string => $migrator->executeStatement('DELETE FROM rvw_report_draft WHERE organization_id = ?', [$organizationId]));
        }
        $this->removeSeededRounds();
        $this->removeCommittedProjects();
        $this->removeUsersAndOrganizations();
    }

    #[Test]
    public function theDraftPageOffersTheSamePeopleAsReportAProblem(): void
    {
        $draft = $this->addDraft('Mia');
        $this->logIn($this->client, $this->people['Mia']);

        $reportPage = $this->client->request('GET', '/report');
        self::assertResponseStatusCodeSame(200);
        $reportChoices = $this->giveToChoicesOf($reportPage->filter('[data-report-form-target="giveTo"][data-team="'.$this->shop->toString().'"]'));

        $draftPage = $this->client->request('GET', self::DRAFTS.'/'.$draft);
        self::assertResponseStatusCodeSame(200);
        $giveTo = $draftPage->filter('form.login__form fieldset');
        self::assertSame('Give it to', $giveTo->filter('legend')->text());
        $draftChoices = $this->giveToChoicesOf($giveTo);

        $omarId = $this->people['Omar']->id()->toString();
        $lenaId = $this->people['Lena']->id()->toString();
        self::assertSame([['', 'The team (Lena decides)'], [$omarId, 'Omar'], [$lenaId, 'Lena']], $draftChoices, 'Mia is the tester, so she is not offered; Omar has fewer open tickets than Lena.');
        self::assertSame($reportChoices, $draftChoices);
        self::assertSame([''], $giveTo->filter('input[checked]')->each(static fn (Crawler $radio): string => (string) $radio->attr('value')), 'The team is the default.');
    }

    /**
     * Raj is in no team, so Report a problem gives him no "Give it to", and nor does his draft's page.
     */
    #[Test]
    public function aPersonInNoTeamOfTheProjectSeesNoGiveItTo(): void
    {
        $draft = $this->addDraft('Raj');
        $this->logIn($this->client, $this->people['Raj']);

        $page = $this->client->request('GET', self::DRAFTS.'/'.$draft);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(0, $page->filter('input[name='.ReportDraftController::ASSIGNEE_FIELD.']')->count());
    }

    #[Test]
    public function sendWithANamedDeveloperMakesThemTheAssigneeInToDo(): void
    {
        $draft = $this->addDraft('Mia');
        $this->logIn($this->client, $this->people['Mia']);

        $this->client->request('POST', self::DRAFTS.'/'.$draft.'/send', [...$this->sendFields($draft), ReportDraftController::ASSIGNEE_FIELD => $this->people['Omar']->id()->toString()]);

        self::assertResponseStatusCodeSame(303);
        self::assertSame('/t/SHOP-1', $this->client->getResponse()->headers->get('Location'));
        self::assertSame([$this->people['Omar']->id()->toString(), $this->stepId, $this->people['Mia']->id()->toString()], array_values($this->rowOf('SELECT assignee_id, step_id, reporter_id FROM tkt_ticket WHERE key = ?', ['SHOP-1'])));
        self::assertSame(0, $this->rowCountOf('SELECT count(*) FROM rvw_report_draft'));
    }

    #[Test]
    public function sendWithTheTeamLeavesItUnassignedInBacklogForTheLead(): void
    {
        $draft = $this->addDraft('Mia');
        $this->logIn($this->client, $this->people['Mia']);

        $this->client->request('POST', self::DRAFTS.'/'.$draft.'/send', [...$this->sendFields($draft), ReportDraftController::ASSIGNEE_FIELD => '']);

        self::assertResponseStatusCodeSame(303);
        self::assertSame('/t/SHOP-1', $this->client->getResponse()->headers->get('Location'));
        self::assertSame([null, $this->backlogStepId], array_values($this->rowOf('SELECT assignee_id, step_id FROM tkt_ticket WHERE key = ?', ['SHOP-1'])));
        self::assertSame($this->people['Lena']->id()->toString(), $this->rowOf('SELECT turn.turn_user_id FROM ins_ticket_turn turn JOIN tkt_ticket t ON t.organization_id = turn.organization_id AND t.id = turn.ticket_id WHERE t.key = ?', ['SHOP-1'])['turn_user_id']);
    }

    /**
     * Raj is in no team; Mia is Shop's tester, whom "Give it to" never offers; the third is no person's id. Each Send
     * posts an edited title: the refusal shows the typed title, saves none of it and makes no ticket.
     */
    #[Test]
    public function sendToAPersonGiveItToDoesNotOfferIsRefusedAndChangesNothing(): void
    {
        $draft = $this->addDraft('Mia');
        $this->logIn($this->client, $this->people['Mia']);
        $notOffered = ['outside the team' => $this->people['Raj']->id()->toString(), 'a tester' => $this->people['Mia']->id()->toString(), 'no person' => 'not-a-uuid'];

        foreach ($notOffered as $case => $assigneeId) {
            $page = $this->client->request('POST', self::DRAFTS.'/'.$draft.'/send', [...$this->sendFields($draft), 'title' => 'Edited before Send', ReportDraftController::ASSIGNEE_FIELD => $assigneeId]);

            self::assertResponseStatusCodeSame(422, $case);
            self::assertSame(ReportGiveTo::NOT_OFFERED, $page->filter('#report-draft-error')->text(), $case);
            self::assertSame('Edited before Send', $page->filter('input[name=title]')->attr('value'), $case);
            self::assertSame([''], $page->filter('input[name='.ReportDraftController::ASSIGNEE_FIELD.'][checked]')->each(static fn (Crawler $radio): string => (string) $radio->attr('value')), $case);
            self::assertSame([self::TITLE, 1], array_values($this->rowOf('SELECT title, version FROM rvw_report_draft WHERE id = ?', [$draft])), $case);
            self::assertSame(['SHOP-90'], $this->ticketKeys(), $case);
        }
    }

    /**
     * A person in no team of the project gives the report to nobody but the team, even when the person they name is
     * in it.
     */
    #[Test]
    public function aSenderInNoTeamOfTheProjectCannotNameAPersonInIt(): void
    {
        $draft = $this->addDraft('Raj');
        $this->logIn($this->client, $this->people['Raj']);

        $page = $this->client->request('POST', self::DRAFTS.'/'.$draft.'/send', [...$this->sendFields($draft), ReportDraftController::ASSIGNEE_FIELD => $this->people['Omar']->id()->toString()]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(ReportGiveTo::NOT_OFFERED, $page->filter('#report-draft-error')->text());
        self::assertSame([self::TITLE, 1], array_values($this->rowOf('SELECT title, version FROM rvw_report_draft WHERE id = ?', [$draft])));
        self::assertSame(['SHOP-90'], $this->ticketKeys());
    }

    /**
     * Omar leaves Shop after Mia's page filled the team people cache, which still offers him: Ticketing's own check on
     * the team refuses him, and Send answers as for any person not offered.
     */
    #[Test]
    public function sendToAPersonWhoLeftTheTeamSinceThePageWasShownIsRefusedAndChangesNothing(): void
    {
        $draft = $this->addDraft('Mia');
        $this->logIn($this->client, $this->people['Mia']);
        $omarId = $this->people['Omar']->id()->toString();
        $page = $this->client->request('GET', self::DRAFTS.'/'.$draft);
        self::assertResponseStatusCodeSame(200);
        self::assertContains([$omarId, 'Omar'], $this->giveToChoicesOf($page->filter('form.login__form fieldset')), 'Omar is offered before he leaves.');
        $parameters = [$this->shop->toString(), $omarId];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement('DELETE FROM org_team_member WHERE team_id = ? AND user_id = ?', $parameters));

        $page = $this->client->request('POST', self::DRAFTS.'/'.$draft.'/send', [...$this->sendFields($draft), 'title' => 'Edited before Send', ReportDraftController::ASSIGNEE_FIELD => $omarId]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(ReportGiveTo::NOT_OFFERED, $page->filter('#report-draft-error')->text());
        self::assertSame('Edited before Send', $page->filter('input[name=title]')->attr('value'));
        self::assertSame([self::TITLE, 1, $this->people['Mia']->id()->toString()], array_values($this->rowOf('SELECT title, version, owner_id FROM rvw_report_draft WHERE id = ?', [$draft])));
        self::assertSame(['SHOP-90'], $this->ticketKeys());
    }

    #[Test]
    public function nobodyButTheOwnerSendsTheDraftToAPerson(): void
    {
        $draft = $this->addDraft('Mia');

        foreach (['Omar', 'Lena'] as $person) {
            $this->logIn($this->client, $this->people[$person]);
            $this->client->request('POST', self::DRAFTS.'/'.$draft.'/send', [...$this->sendFields($draft), ReportDraftController::ASSIGNEE_FIELD => $this->people['Omar']->id()->toString()]);

            self::assertResponseStatusCodeSame(404, $person);
            self::assertStringContainsString(ReportDraftRefused::NOT_FOUND, (string) $this->client->getResponse()->getContent(), $person);
        }
        self::assertSame([self::TITLE, 1], array_values($this->rowOf('SELECT title, version FROM rvw_report_draft WHERE id = ?', [$draft])));
        self::assertSame(['SHOP-90'], $this->ticketKeys());
    }

    /**
     * @return list<array{string, string}> each radio's value and its label's text
     */
    private function giveToChoicesOf(Crawler $giveTo): array
    {
        self::assertCount(1, $giveTo);
        $choices = $giveTo->filter('label')->each(static fn (Crawler $chip): array => [(string) $chip->filter('input')->attr('value'), trim($chip->text())]);
        self::assertNotSame([], $choices);

        return $choices;
    }

    /**
     * @return string the draft's id
     */
    private function addDraft(string $owner): string
    {
        $draftId = Uuid::v7()->toRfc4122();
        $parameters = [$this->acmeFoods->toString(), $draftId, $this->people[$owner]->id()->toString(), self::TITLE];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO rvw_report_draft (organization_id, id, owner_id, project_key, title, detail, screenshots, version, created_at, updated_at)
             VALUES (?, ?, ?, 'SHOP', ?, '', '[]'::jsonb, 1, '2026-11-05 07:00:00+00', '2026-11-05 08:00:00+00')",
            $parameters,
        ));

        return $draftId;
    }

    /**
     * @return array<string, string>
     */
    private function sendFields(string $draftId): array
    {
        return [
            ReportDraftController::CSRF_FIELD => $this->csrfTokenFor($this->client, ReportDraftController::csrfIdOf($draftId)),
            ReportDraftController::VERSION_FIELD => '1',
            'title' => self::TITLE,
        ];
    }

    /**
     * @return list<mixed> every ticket's key, in key order
     */
    private function ticketKeys(): array
    {
        $keys = [];
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use (&$keys): int {
            $keys = $migrator->fetchFirstColumn('SELECT key FROM tkt_ticket ORDER BY key');

            return 0;
        });

        return $keys;
    }

    private function rowCountOf(string $sql): int
    {
        $count = null;
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($sql, &$count): int {
            $count = $migrator->fetchOne($sql);

            return 0;
        });
        self::assertIsInt($count);

        return $count;
    }

    /**
     * @param list<string> $parameters
     *
     * @return array<string, mixed>
     */
    private function rowOf(string $sql, array $parameters): array
    {
        $row = [];
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($sql, $parameters, &$row): int {
            $row = $migrator->fetchAssociative($sql, $parameters);

            return 0;
        });
        self::assertIsArray($row);

        return $row;
    }
}
