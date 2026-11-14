<?php

declare(strict_types=1);

namespace App\Tests\Functional\Notification;

use App\Identity\Domain\User;
use App\Notification\Infrastructure\Mercure\RealtimeTopics;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Web\CreatesAcmeFoodsTeams;
use App\Tests\Functional\Web\CreatesTeams;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * The shell's one live updates stream per page (realtime-and-notifications.md): the viewer's own topic on every page,
 * and on a ticket page the ticket's topic too, never a second stream. The clock is fixed on 6 Nov 2026, before today: a
 * clock ahead of the login would end the session as idle.
 */
final class LiveUpdatesPlacementTest extends WebTestCase
{
    use CreatesAcmeFoodsTeams;
    use CreatesTeams;
    use Factories;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }

    private const string NOW = '2026-11-06 12:00:00 UTC';
    private const string LIVE_UPDATES = '[data-controller="live-updates"]';

    private KernelBrowser $client;

    /** @var array<string, User> */
    private array $people;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTPS' => 'on']);
        $this->client->disableReboot();
        self::getContainer()->set('clock', new MockClock(new \DateTimeImmutable(self::NOW)));
        $this->people = $this->createAcmeFoodsPeople();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdOrganizationIds as $organizationId) {
            $this->committedAs(new OrganizationId($organizationId), static function (Connection $migrator) use ($organizationId): int {
                foreach (['tkt_ticket_event', 'ins_ticket_turn', 'ins_project_count_ticket', 'ins_project_counts', 'tkt_ticket', 'wf_step'] as $table) {
                    $migrator->executeStatement(\sprintf('DELETE FROM %s WHERE organization_id = ?', $table), [$organizationId]);
                }

                return 0;
            });
        }
        $this->removeCommittedProjects();
        $this->removeUsersAndOrganizations();
    }

    #[Test]
    public function aMembersHomePageFollowsOnlyTheirOwnTopicInOneStream(): void
    {
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', '/');

        self::assertResponseStatusCodeSame(200);
        $liveUpdates = $page->filter(self::LIVE_UPDATES);
        self::assertCount(1, $liveUpdates);
        self::assertSame('[]', $liveUpdates->attr('data-live-updates-ticket-ids-value'));
        $subscribeUrl = $this->subscribeUrlOf($liveUpdates);
        self::assertStringContainsString('match='.rawurlencode(RealtimeTopics::ofUser($this->acmeFoods, $this->people['Omar']->id())), $subscribeUrl);
        self::assertSame(0, substr_count($subscribeUrl, rawurlencode('/t/')));
    }

    #[Test]
    public function aTicketPageFollowsItsTicketInTheShellsOneStream(): void
    {
        $ticketId = $this->addTicket();
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', '/t/SHOP-1');

        self::assertResponseStatusCodeSame(200);
        $liveUpdates = $page->filter(self::LIVE_UPDATES);
        self::assertCount(1, $liveUpdates);
        self::assertSame('["'.$ticketId.'"]', $liveUpdates->attr('data-live-updates-ticket-ids-value'));
        $subscribeUrl = $this->subscribeUrlOf($liveUpdates);
        self::assertStringContainsString('match='.rawurlencode(RealtimeTopics::ofUser($this->acmeFoods, $this->people['Omar']->id())), $subscribeUrl);
        self::assertStringContainsString('match='.rawurlencode(RealtimeTopics::ofTicket($this->acmeFoods, new TicketId($ticketId))), $subscribeUrl);
        self::assertSame(1, substr_count($subscribeUrl, rawurlencode('/t/')));
    }

    #[Test]
    public function aSignedOutPageHasNoStream(): void
    {
        $page = $this->client->request('GET', '/login');

        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $page->filter('main#main'));
        self::assertCount(0, $page->filter(self::LIVE_UPDATES));
    }

    private function subscribeUrlOf(Crawler $liveUpdates): string
    {
        return (string) $liveUpdates->attr('data-live-updates-subscribe-url-value');
    }

    /**
     * SHOP-1, a bug of Shop's team for everyone in To do, with the core steps the ticket page reads.
     *
     * @return string the ticket's id
     */
    private function addTicket(): string
    {
        $organizationId = $this->acmeFoods->toString();
        $projectId = $this->addProject($this->shop, 'SHOP', 'Shop');
        $ticketId = Uuid::v7()->toRfc4122();
        $reporterId = $this->people['Lena']->id()->toString();
        $teamId = $this->shop->toString();
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($organizationId, $projectId, $ticketId, $reporterId, $teamId): int {
            $todoStepId = null;
            foreach ([['To do', 'todo', 'todo'], ['In progress', 'doing', 'progress'], ['Code review', 'check', 'review'], ['Needs input', 'wait', 'input'], ['Done', 'done', 'done']] as $position => [$name, $groupCode, $coreCode]) {
                $stepId = Uuid::v7()->toRfc4122();
                $todoStepId ??= $stepId;
                $migrator->executeStatement(
                    "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, ?, ?, ?, 'core', ?)",
                    [$organizationId, $stepId, $name, $groupCode, $coreCode, $position],
                );
            }
            $migrator->executeStatement(
                "INSERT INTO tkt_ticket (organization_id, id, project_id, team_id, number, key, title, description, type, release, priority,
                     priority_rank, step_id, reporter_id, visibility, assignee_id, parent_id, components, check_reasons, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 1, 'SHOP-1', 'Add a gift note', 'A field for a short note.', 'bug', NULL, 'major', 2, ?, ?, 'all',
                     NULL, NULL, '{}', '{}', '2026-11-04 23:30:00+00', '2026-11-05 09:00:00+00')",
                [$organizationId, $ticketId, $projectId, $teamId, $todoStepId, $reporterId],
            );

            return 0;
        });

        return $ticketId;
    }
}
