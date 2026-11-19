<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ticketing;

use App\Identity\Domain\User;
use App\Review\UI\Component\RoundsSection;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Web\CreatesAcmeFoodsTeams;
use App\Tests\Functional\Web\CreatesTeams;
use App\Tests\Functional\Web\IssuesCsrfTokens;
use App\Ticketing\Infrastructure\Scheduler\AddTicketEventPartitions;
use App\Ticketing\UI\ReportController;
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
 * Report a problem at /report (P06.T1, report-a-problem.md). Lena leads Shop and Mobile; Omar is a Shop developer; Mia
 * is Shop's tester; Raj is a manager in no team; Sara an admin. Shop owns SHOP and WEB, Mobile owns APP; Sara is the
 * triager of tickets with no team. The clock is frozen on 6 Nov 2026.
 */
final class ReportControllerTest extends WebTestCase
{
    use CreatesAcmeFoodsTeams;
    use CreatesTeams;
    use Factories;
    use IssuesCsrfTokens;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }

    private const string NOW = '2026-11-06 12:00:00 UTC';
    private const string REPORT = '/report';
    /** 40 characters: no "little detail" reason. */
    private const string ENOUGH_DETAIL = 'The cart adds the coupon twice at pay.!!';
    private const array TURBO_FRAME_HEADERS = ['HTTP_TURBO_FRAME' => RoundsSection::FRAME_ID];

    private KernelBrowser $client;

    /** @var array<string, User> */
    private array $people;
    private string $shopProject;
    private string $appProject;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTPS' => 'on']);
        $this->client->disableReboot();
        $clock = new MockClock(new \DateTimeImmutable(self::NOW));
        self::getContainer()->set('clock', $clock);
        new AddTicketEventPartitions(self::getContainer()->get('doctrine.dbal.migrator_connection'), $clock)();
        $this->people = $this->createAcmeFoodsPeople('member');
        $organizationId = $this->acmeFoods->toString();
        $shopId = $this->shop->toString();
        $omarId = $this->people['Omar']->id()->toString();
        $miaId = $this->people['Mia']->id()->toString();
        $saraId = $this->people['Sara']->id()->toString();
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($organizationId, $shopId, $omarId, $miaId, $saraId): int {
            $migrator->executeStatement("UPDATE org_team_member SET job = 'developer' WHERE team_id = ? AND user_id = ?", [$shopId, $omarId]);
            $migrator->executeStatement("UPDATE org_team_member SET job = 'tester' WHERE team_id = ? AND user_id = ?", [$shopId, $miaId]);
            $migrator->executeStatement("INSERT INTO org_settings (organization_id, data, created_at) VALUES (?, jsonb_build_object('triager_id', ?::text), '2026-11-01 10:00:00+00')", [$organizationId, $saraId]);
            foreach ([['Backlog', 'backlog', 0], ['To do', 'todo', 1]] as [$name, $coreCode, $position]) {
                $migrator->executeStatement("INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, ?, 'todo', ?, 'core', ?)", [$organizationId, Uuid::v7()->toRfc4122(), $name, $coreCode, $position]);
            }

            return 0;
        });
        $this->shopProject = $this->addProject($this->shop, 'SHOP', 'Shop web');
        $this->addProject($this->shop, 'WEB', 'Website');
        $this->appProject = $this->addProject($this->mobile, 'APP', 'Mobile app');
    }

    protected function tearDown(): void
    {
        foreach ($this->createdOrganizationIds as $organizationId) {
            $this->committedAs(new OrganizationId($organizationId), static function (Connection $migrator) use ($organizationId): int {
                foreach (['rvw_round', 'med_object', 'tkt_ticket_event', 'ins_ticket_turn', 'ins_project_count_ticket', 'ins_project_counts', 'tkt_ticket', 'wf_step', 'org_settings'] as $table) {
                    $migrator->executeStatement(\sprintf('DELETE FROM %s WHERE organization_id = ?', $table), [$organizationId]);
                }
                $migrator->executeStatement("DELETE FROM messenger_outbox WHERE queue_name = 'outbox' AND position(? in body) > 0", [$organizationId]);

                return 0;
            });
        }
        $this->removeCommittedProjects();
        $this->removeUsersAndOrganizations();
    }

    #[Test]
    public function theFormShowsTheReportersTeamItsPeopleWithoutTestersAndItsProjects(): void
    {
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', self::REPORT);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Report a problem', $page->filter('h1')->text());
        self::assertSame(['Shop', 'Mobile', 'Not sure'], $this->chipsOf($page, 'team_id'), 'The reporter\'s own team first.');
        self::assertSame('Shop', $this->checkedChipOf($page, 'team_id'));
        $shopGiveTo = $page->filter('[data-report-form-target="giveTo"][data-team="'.$this->shop->toString().'"]');
        self::assertCount(1, $shopGiveTo);
        self::assertNull($shopGiveTo->attr('hidden'));
        self::assertSame(['The team (Lena decides)', 'Lena', 'Omar (you)'], $shopGiveTo->filter('label')->each(static fn (Crawler $chip): string => trim($chip->text())), 'Mia is the tester, so she is not offered; Lena and Omar have no tickets, so by name.');
        self::assertSame(['SHOP', 'WEB'], $page->filter('[data-report-form-target="projects"][data-team="'.$this->shop->toString().'"] label')->each(static fn (Crawler $chip): string => trim($chip->text())));
        self::assertNotNull($page->filter('[data-report-form-target="projects"][data-team="none"]')->attr('hidden'), 'Not sure\'s projects stay hidden until it is picked.');
        self::assertSame(['Bug', 'Story', 'Task'], $this->chipsOf($page, 'type'));
        self::assertSame(['Critical', 'Major', 'Normal', 'Minor'], $this->chipsOf($page, 'priority'));
        self::assertSame(['Bug', 'Normal'], [$this->checkedChipOf($page, 'type'), $this->checkedChipOf($page, 'priority')]);
        self::assertSame('Capture with pinround', $page->filter('.screenshot-report__capture summary')->text());
        self::assertSame('Report a problem', $page->filter('header a.btn-tonal')->text());
    }

    /**
     * ADR-0038: Report a problem is open to every role. Raj is in no team, so the form starts on "Not sure", with no
     * "Give it to".
     */
    #[Test]
    public function aManagerInNoTeamReportsWithNotSureByDefault(): void
    {
        $this->logIn($this->client, $this->people['Raj']);

        $page = $this->client->request('GET', self::REPORT);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Not sure', $this->checkedChipOf($page, 'team_id'));
        self::assertCount(0, $page->filter('[data-report-form-target="giveTo"]'));
        self::assertNull($page->filter('[data-report-form-target="projects"][data-team="none"]')->attr('hidden'));
    }

    #[Test]
    public function aReportWithAScreenshotAndTwoPinsBecomesATicketWithRoundZeroAndNeedsNoCheck(): void
    {
        $picture = $this->addReadyPicture('Mia');
        $this->logIn($this->client, $this->people['Mia']);

        $this->postReport([
            'screenshot_media_id' => $picture,
            'screenshot_title' => 'Case B',
            'pins' => [1 => ['x' => '10.5', 'y' => '20', 'note' => 'Total'], 2 => ['x' => '60', 'y' => '70', 'note' => 'Coupon']],
        ]);

        self::assertSame(\sprintf(ReportController::CREATED_UNCHECKED, 'SHOP-1'), $this->toastAfterTheRedirect('/t/SHOP-1'));
        self::assertSame(['Total is counted twice', $this->shop->toString(), 'all', '{}', null, 'backlog', $this->people['Mia']->id()->toString()], $this->ticketRow('SHOP-1'));
        $frame = $this->client->request('GET', '/t/SHOP-1/rounds', [], [], self::TURBO_FRAME_HEADERS);
        self::assertSame(['Round 0 · Report · by Mia · 2 pins'], $frame->filter('.round-card__headline')->each(static fn (Crawler $headline): string => $headline->text()));
    }

    #[Test]
    public function aPickedPersonGetsItInToDo(): void
    {
        $this->logIn($this->client, $this->people['Lena']);

        $this->postReport(['assignee_id' => $this->people['Omar']->id()->toString()]);

        self::assertSame(\sprintf(ReportController::CREATED_UNCHECKED, 'SHOP-1'), $this->toastAfterTheRedirect('/t/SHOP-1'));
        self::assertSame([$this->people['Omar']->id()->toString(), 'todo'], \array_slice($this->ticketRow('SHOP-1'), 4, 2));
    }

    #[Test]
    public function notSureMakesATicketWithNoTeamThatTheTriagerChecks(): void
    {
        $this->logIn($this->client, $this->people['Raj']);

        $this->postReport(['team_id' => 'none', 'project_id' => $this->appProject]);

        self::assertSame(\sprintf(ReportController::CREATED_CHECKED, 'APP-1', 'Sara'), $this->toastAfterTheRedirect('/t/APP-1'));
        self::assertSame([null, 'all', '{team}', null, 'backlog'], \array_slice($this->ticketRow('APP-1'), 1, 5));
    }

    #[Test]
    public function aSecurityWordMakesItTeamOnlyAndRiskyAndShortDetailWithoutPinsIsLittleInfo(): void
    {
        $this->logIn($this->client, $this->people['Omar']);

        $this->postReport(['title' => 'Password shown in the address bar', 'description' => 'Short.']);

        self::assertSame(\sprintf(ReportController::CREATED_CHECKED, 'SHOP-1', 'Lena'), $this->toastAfterTheRedirect('/t/SHOP-1'));
        self::assertSame(['team', '{info,risk}'], \array_slice($this->ticketRow('SHOP-1'), 2, 2));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function refusedReports(): iterable
    {
        yield 'no title' => [['title' => '   '], 'title', ReportController::TITLE_MISSING];
        yield 'a project of another team' => [['project_id' => 'APP'], 'project', ReportController::PROJECT_UNKNOWN];
        yield 'a tester, who is not offered' => [['assignee_id' => 'Mia'], 'assignee', ReportController::ASSIGNEE_UNKNOWN];
        yield 'a person for Not sure' => [['team_id' => 'none', 'project_id' => 'APP', 'assignee_id' => 'Omar'], 'assignee', ReportController::ASSIGNEE_UNKNOWN];
        yield 'a team that is not offered' => [['team_id' => '0197a3c0-0000-7000-8000-00000000dead'], 'team', ReportController::TEAM_UNKNOWN];
        yield 'a picture summary of 601 characters' => [['screenshot_media_id' => 'ready', 'screenshot_summary' => str_repeat('s', 601)], 'screenshot', 'A picture summary holds at most 600 characters.'];
    }

    /**
     * @param array<string, mixed> $overrides "APP" stands for the APP project's id, a first name for that person's id,
     *                                        "ready" for a ready picture of Omar's
     */
    #[Test]
    #[DataProvider('refusedReports')]
    public function aRefusedReportShowsWhyKeepsTheTextAndMakesNoTicket(array $overrides, string $field, string $message): void
    {
        $this->logIn($this->client, $this->people['Omar']);
        $overrides = array_map(fn (mixed $value): mixed => match (true) {
            'APP' === $value => $this->appProject,
            'ready' === $value => $this->addReadyPicture('Omar'),
            \is_string($value) && isset($this->people[$value]) => $this->people[$value]->id()->toString(),
            default => $value,
        }, $overrides);

        $page = $this->postReport(['description' => 'Kept as typed', ...$overrides]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame($message, $page->filter('#report-'.$field.'-error, #screenshot-error')->text());
        self::assertSame('Kept as typed', $page->filter('textarea[name="description"]')->text());
        self::assertSame(0, $this->ticketCount());
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function postReport(array $overrides = []): Crawler
    {
        return $this->client->request('POST', self::REPORT, [
            ReportController::CSRF_FIELD => $this->csrfTokenFor($this->client, ReportController::CSRF_ID),
            'title' => 'Total is counted twice',
            'description' => self::ENOUGH_DETAIL,
            'team_id' => $this->shop->toString(),
            'project_id' => $this->shopProject,
            'assignee_id' => '',
            'type' => 'bug',
            'priority' => 'normal',
            'screenshot_media_id' => '',
            ...$overrides,
        ]);
    }

    private function toastAfterTheRedirect(string $location): string
    {
        self::assertResponseStatusCodeSame(302);
        self::assertSame($location, $this->client->getResponse()->headers->get('Location'));
        $page = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);

        return (string) $page->filter('.toast-region [data-toast-message-value]')->attr('data-toast-message-value');
    }

    /**
     * @return list<string>
     */
    private function chipsOf(Crawler $page, string $name): array
    {
        return $page->filter('input[name="'.$name.'"]')->each(static fn (Crawler $radio): string => trim($radio->closest('label')?->text() ?? ''));
    }

    private function checkedChipOf(Crawler $page, string $name): string
    {
        return trim($page->filter('input[name="'.$name.'"][checked]')->closest('label')?->text() ?? '');
    }

    /**
     * @return list<mixed> title, team id, visibility, check reasons, assignee id, the step's core code, reporter id
     */
    private function ticketRow(string $key): array
    {
        $row = null;
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($key, &$row): int {
            $row = $migrator->fetchNumeric(
                'SELECT t.title, t.team_id, t.visibility, t.check_reasons::text, t.assignee_id, s.core_code, t.reporter_id FROM tkt_ticket t JOIN wf_step s ON s.organization_id = t.organization_id AND s.id = t.step_id WHERE t.key = ?',
                [$key],
            );

            return 0;
        });
        self::assertIsArray($row, 'no ticket '.$key);

        return $row;
    }

    private function ticketCount(): int
    {
        $count = null;
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use (&$count): int {
            $count = $migrator->fetchOne('SELECT count(*) FROM tkt_ticket');

            return 0;
        });
        self::assertIsInt($count);

        return $count;
    }

    private function addReadyPicture(string $uploader): string
    {
        $mediaId = Uuid::v7()->toRfc4122();
        $parameters = [$this->acmeFoods->toString(), $mediaId, 'uploads/'.$mediaId, $this->people[$uploader]->id()->toString(), hash('sha256', $mediaId), json_encode(['view' => 'media/'.$mediaId.'/view.webp', 'thumb' => 'media/'.$mediaId.'/thumb.webp'], \JSON_THROW_ON_ERROR)];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO med_object (organization_id, id, storage_key, mime, bytes, uploaded_by, created_at, status, reject_reason, sha256, width, height, variants)
             VALUES (?, ?, ?, 'image/png', 2048, ?, '2026-11-05 08:00:00+00', 'ready', NULL, ?, 1280, 800, CAST(? AS jsonb))",
            $parameters,
        ));

        return $mediaId;
    }
}
