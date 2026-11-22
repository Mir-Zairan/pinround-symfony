<?php

declare(strict_types=1);

namespace App\Tests\Functional\Review\ReportDraft;

use App\Identity\Domain\User;
use App\Review\Api\ReportDraftRefused;
use App\Review\UI\ReportDraftController;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Web\CreatesAcmeFoodsTeams;
use App\Tests\Functional\Web\CreatesTeams;
use App\Tests\Functional\Web\IssuesCsrfTokens;
use App\Ticketing\Api\ReportGiveTo;
use App\Ticketing\UI\NewTicketController;
use App\Ticketing\UI\ReportWhereItGoesRules;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Uid\Uuid;

/**
 * A report draft's "Where it goes" and "When you send:" (gap list 3.3, 3.4): the same box as Report a problem, and Send
 * goes where it says. Lena leads Shop (project SHOP) and Mobile (project APP); Omar is Shop's developer and Mia a member
 * of Shop with no job, in no Mobile role. The clock is frozen on 6 Nov 2026.
 */
final class ReportDraftWhereItGoesTest extends WebTestCase
{
    use CreatesAcmeFoodsTeams;
    use CreatesTeams;
    use IssuesCsrfTokens;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }

    private const string NOW = '2026-11-06 12:00:00 UTC';
    private const string DRAFTS = '/report-drafts';
    private const string TITLE = 'Total is doubled on the cart';

    private KernelBrowser $client;

    /** @var array<string, User> */
    private array $people;

    private string $shopProject;
    private string $appProject;
    private string $todoStepId;
    private string $backlogStepId;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTPS' => 'on']);
        $this->client->disableReboot();
        self::getContainer()->set('clock', new MockClock(new \DateTimeImmutable(self::NOW)));
        $this->people = $this->createAcmeFoodsPeople('member');
        $this->shopProject = $this->addProject($this->shop, 'SHOP', 'Shop');
        $this->appProject = $this->addProject($this->mobile, 'APP', 'Mobile app');
        $this->todoStepId = Uuid::v7()->toRfc4122();
        $this->backlogStepId = Uuid::v7()->toRfc4122();
        $parameters = [$this->acmeFoods->toString(), $this->backlogStepId, $this->todoStepId, $this->shop->toString(), $this->people['Omar']->id()->toString()];
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($parameters): int {
            $migrator->executeStatement("UPDATE org_team_member SET job = 'developer' WHERE team_id = ? AND user_id = ?", [$parameters[3], $parameters[4]]);
            $migrator->executeStatement("INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, 'Backlog', 'todo', 'backlog', 'core', 0)", [$parameters[0], $parameters[1]]);
            $migrator->executeStatement("INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, 'To do', 'todo', 'todo', 'core', 1)", [$parameters[0], $parameters[2]]);

            return 0;
        });
    }

    protected function tearDown(): void
    {
        foreach ($this->createdOrganizationIds as $organizationId) {
            $this->committedAs(new OrganizationId($organizationId), static function (Connection $migrator) use ($organizationId): int {
                $migrator->executeStatement('DELETE FROM rvw_report_draft WHERE organization_id = ?', [$organizationId]);
                $migrator->executeStatement('DELETE FROM tkt_ticket WHERE organization_id = ?', [$organizationId]);

                return 0;
            });
        }
        $this->removeCommittedProjects();
        $this->removeUsersAndOrganizations();
    }

    #[Test]
    public function theDraftPageShowsWhereItGoesStartingAtTheDraftsProjectAndTheSendSentence(): void
    {
        $draft = $this->addDraft('Mia', 'SHOP');
        $this->logIn($this->client, $this->people['Mia']);

        $page = $this->client->request('GET', self::DRAFTS.'/'.$draft);

        self::assertResponseStatusCodeSame(200);
        $form = $page->filter('form.report-draft__form');
        self::assertSame('Where it goes', $form->filter('.where-it-goes > legend')->text());
        self::assertSame(['Team', 'Give it to', 'Project', 'Project', 'Project', 'Type', 'Priority'], $form->filter('.where-it-goes legend.report__label')->each(static fn (Crawler $legend): string => $legend->text()));
        self::assertSame([$this->shop->toString(), '', $this->shopProject, 'bug', 'normal'], $this->checkedValuesOf($form), 'Shop, the team decides, SHOP, Bug and Normal.');
        self::assertSame('When you send: It becomes ticket SHOP-1 right away. Lena takes a quick look, because little detail and no pins.', $form->filter('.report__preview')->text());
    }

    #[Test]
    public function sendWithAPersonPickedLandsInToDoWithTheTypeAndPriorityPicked(): void
    {
        $draft = $this->addDraft('Mia', 'SHOP');
        $this->logIn($this->client, $this->people['Mia']);

        $this->client->request('POST', self::DRAFTS.'/'.$draft.'/send', [
            ...$this->sendFields($draft),
            'team_id' => $this->shop->toString(),
            'project_id' => $this->shopProject,
            'assignee_id' => $this->people['Omar']->id()->toString(),
            'type' => 'story',
            'priority' => 'major',
        ]);

        self::assertResponseStatusCodeSame(303);
        self::assertSame('/t/SHOP-1', $this->client->getResponse()->headers->get('Location'));
        self::assertSame([$this->people['Omar']->id()->toString(), $this->todoStepId, 'story', 'major'], array_values($this->rowOf('SELECT assignee_id, step_id, type, priority FROM tkt_ticket WHERE key = ?', ['SHOP-1'])));
        self::assertSame('{info}', $this->rowOf('SELECT check_reasons FROM tkt_ticket WHERE key = ?', ['SHOP-1'])['check_reasons'], 'The quick look the page promised.');
    }

    #[Test]
    public function sendWithTheTeamLandsInBacklog(): void
    {
        $draft = $this->addDraft('Mia', 'SHOP');
        $this->logIn($this->client, $this->people['Mia']);

        $this->client->request('POST', self::DRAFTS.'/'.$draft.'/send', [...$this->sendFields($draft), 'team_id' => $this->shop->toString(), 'project_id' => $this->shopProject, 'assignee_id' => '']);

        self::assertResponseStatusCodeSame(303);
        self::assertSame([null, $this->backlogStepId, 'bug', 'normal'], array_values($this->rowOf('SELECT assignee_id, step_id, type, priority FROM tkt_ticket WHERE key = ?', ['SHOP-1'])));
    }

    /**
     * A SHOP draft sent to Mobile's project becomes an APP ticket of Mobile; SHOP gets none.
     */
    #[Test]
    public function sendToAnotherTeamsProjectMakesTheTicketThere(): void
    {
        $draft = $this->addDraft('Mia', 'SHOP');
        $this->logIn($this->client, $this->people['Mia']);

        $this->client->request('POST', self::DRAFTS.'/'.$draft.'/send', [...$this->sendFields($draft), 'team_id' => $this->mobile->toString(), 'project_id' => $this->appProject, 'assignee_id' => '']);

        self::assertResponseStatusCodeSame(303);
        self::assertSame('/t/APP-1', $this->client->getResponse()->headers->get('Location'));
        self::assertSame([$this->mobile->toString(), $this->appProject, null], array_values($this->rowOf('SELECT team_id, project_id, assignee_id FROM tkt_ticket WHERE key = ?', ['APP-1'])));
        self::assertSame(['APP-1'], $this->ticketKeys());
    }

    /**
     * Each refusal shows the picks as posted, keeps the draft and makes no ticket.
     */
    #[Test]
    public function sendWithAPickReportAProblemRefusesChangesNothing(): void
    {
        $draft = $this->addDraft('Mia', 'SHOP');
        $this->logIn($this->client, $this->people['Mia']);
        $shop = $this->shop->toString();
        $refusals = [
            'a project of another team' => [['team_id' => $shop, 'project_id' => $this->appProject], ReportWhereItGoesRules::PROJECT_UNKNOWN],
            'no team' => [['team_id' => Uuid::v7()->toRfc4122(), 'project_id' => $this->shopProject], ReportWhereItGoesRules::TEAM_UNKNOWN],
            'a type that is none' => [['team_id' => $shop, 'project_id' => $this->shopProject, 'type' => 'epic'], NewTicketController::TYPE_UNKNOWN],
            'a priority that is none' => [['team_id' => $shop, 'project_id' => $this->shopProject, 'priority' => 'urgent'], NewTicketController::PRIORITY_UNKNOWN],
            'a person in another team' => [['team_id' => $this->mobile->toString(), 'project_id' => $this->appProject, 'assignee_id' => $this->people['Omar']->id()->toString()], ReportGiveTo::NOT_OFFERED],
        ];

        foreach ($refusals as $case => [$picks, $message]) {
            $page = $this->client->request('POST', self::DRAFTS.'/'.$draft.'/send', [...$this->sendFields($draft), ...$picks]);

            self::assertResponseStatusCodeSame(422, $case);
            self::assertSame($message, $page->filter('#report-draft-error')->text(), $case);
            self::assertSame([self::TITLE, 1], array_values($this->rowOf('SELECT title, version FROM rvw_report_draft WHERE id = ?', [$draft])), $case);
            self::assertSame([], $this->ticketKeys(), $case);
        }
    }

    #[Test]
    public function thePreviewNamesThePersonPickedAndAnswersOnlyTheOwner(): void
    {
        $draft = $this->addDraft('Mia', 'SHOP');
        $this->logIn($this->client, $this->people['Mia']);
        $fields = ['title' => self::TITLE, 'detail' => '', 'team_id' => $this->shop->toString(), 'project_id' => $this->shopProject, 'assignee_id' => $this->people['Omar']->id()->toString()];

        $this->client->request('POST', self::DRAFTS.'/'.$draft.'/preview', $fields);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['preview' => 'It becomes ticket SHOP-1 right away. Lena takes a quick look, because little detail and no pins. Omar can start already.'], json_decode((string) $this->client->getResponse()->getContent(), true));

        $this->logIn($this->client, $this->people['Omar']);
        $this->client->request('POST', self::DRAFTS.'/'.$draft.'/preview', $fields);

        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString(ReportDraftRefused::NOT_FOUND, (string) $this->client->getResponse()->getContent());
    }

    /**
     * @return list<string> the values of the checked radios, in page order
     */
    private function checkedValuesOf(Crawler $form): array
    {
        $values = $form->filter('.where-it-goes input[type=radio][checked]')->each(static fn (Crawler $radio): string => (string) $radio->attr('value'));
        self::assertNotSame([], $values);

        return $values;
    }

    /**
     * @return string the draft's id
     */
    private function addDraft(string $owner, string $projectKey): string
    {
        $draftId = Uuid::v7()->toRfc4122();
        $parameters = [$this->acmeFoods->toString(), $draftId, $this->people[$owner]->id()->toString(), $projectKey, self::TITLE];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO rvw_report_draft (organization_id, id, owner_id, project_key, title, detail, screenshots, version, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, '', '[]'::jsonb, 1, '2026-11-05 07:00:00+00', '2026-11-05 08:00:00+00')",
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
