<?php

declare(strict_types=1);

namespace App\Tests\Functional\Review;

use App\Identity\Domain\User;
use App\Notification\Infrastructure\Mercure\RealtimeTopics;
use App\Review\Api\ChecklistCommands;
use App\Review\UI\Controller\VerdictController;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TicketId;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Web\CreatesAcmeFoodsTeams;
use App\Tests\Functional\Web\CreatesTeams;
use App\Tests\Functional\Web\IssuesCsrfTokens;
use App\Tests\Integration\Messaging\RunsTheWorkerUntilIdle;
use App\Tests\Integration\Organization\TenantTransactions;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * The checklist and verdict changes of Acme Foods' SHOP-1 in its What happened (P05.T7): Mia's verdicts in round 2 and
 * Omar's Mark fixed on the checklist go through the outbox and worker-notify into the ticket's history and back out as
 * plain sentences on the ticket page. Round 0 is the report with pins 1 and 2, round 1 its checklist, round 2 a review
 * of the report's picture as the area "Per product". The clock is fixed on 6 Nov 2026, before today.
 */
final class ReviewChangesInWhatHappenedTest extends WebTestCase
{
    use CreatesAcmeFoodsTeams;
    use CreatesTeams;
    use Factories;
    use IssuesCsrfTokens;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }
    use RunsTheWorkerUntilIdle;
    use SeedsTicketRounds;

    private const string NOW = '2026-11-06 12:00:00 UTC';
    private const string SHARED_EARLIER = '2026-11-05 09:00:00+00';
    private const string TICKET_PAGE = '/t/SHOP-1';
    private const array TURBO_FORM_HEADERS = ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml'];

    private KernelBrowser $client;

    private MockClock $clock;

    /** @var array<string, User> */
    private array $people;

    private string $shopProject;
    private string $stepId;
    private string $ticketId;
    /** @var array<int, string> the report's pins, by number */
    private array $pins = [];
    private string $checkItem;
    private string $perProduct;

    /** @var list<Update> */
    private array $published = [];

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTPS' => 'on']);
        $this->client->disableReboot();
        $this->clock = new MockClock(new \DateTimeImmutable(self::NOW));
        self::getContainer()->set('clock', $this->clock);
        self::getContainer()->set('mercure.hub.default.traceable', new MockHub(
            'https://localhost/.well-known/mercure',
            new StaticTokenProvider('publisher-token'),
            function (Update $update): string {
                $this->published[] = $update;

                return 'urn:uuid:published';
            },
        ));
        $this->people = $this->createAcmeFoodsPeople('member');
        $organizationId = $this->acmeFoods->toString();
        $miaId = $this->people['Mia']->id()->toString();
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "UPDATE org_team_member SET job = 'tester' WHERE organization_id = ? AND user_id = ?",
            [$organizationId, $miaId],
        ));
        $this->shopProject = $this->addProject($this->shop, 'SHOP', 'Shop');
        $this->stepId = Uuid::v7()->toRfc4122();
        $stepId = $this->stepId;
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, 'To do', 'todo', 'todo', 'core', 1)",
            [$organizationId, $stepId],
        ));
        $this->seedReportChecklistAndReview();
        // Only the changes below are relayed, not the events of setting up the teams.
        $this->connection()->executeStatement("DELETE FROM messenger_outbox WHERE queue_name = 'outbox'");
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('state_valkey.connection')->del('realtime-refresh:'.$this->ticketId, 'realtime-refresh-trailing:'.$this->ticketId);
        $this->connection()->executeStatement("DELETE FROM messenger_outbox WHERE queue_name = 'outbox'");
        $this->removeSeededRounds();
        $this->removeCommittedProjects();
        $this->removeUsersAndOrganizations();
    }

    #[Test]
    public function verdictsAndAChecklistChangeShowInWhatHappenedOnceWorkerNotifyHasHandledThem(): void
    {
        $this->logIn($this->client, $this->people['Mia']);
        $this->postAsTurbo('/t/SHOP-1/rounds/2/pins/'.$this->pins[2].'/verdict', [VerdictController::VERDICT_FIELD => 'still_wrong']);
        $this->clock->modify('+1 minute');
        $this->postAsTurbo('/t/SHOP-1/rounds/2/areas/'.$this->perProduct.'/verdict', [VerdictController::VERDICT_FIELD => 'needs_fix', VerdictController::NOTE_FIELD => 'Sum is off by a cent']);
        $this->clock->modify('+1 minute');
        $this->transactions()->committedAs($this->acmeFoods->toString(), fn () => $this->checklistCommands()->markFixed($this->checkItem, $this->people['Omar']->id(), $this->clock->now()));

        self::assertSame(['Created by Mia'], $this->activitySentences(), 'Nothing shows until worker-notify has handled the events.');

        self::runTheWorkerUntilIdle('outbox');
        self::runTheWorkerUntilIdle('async_notify');

        self::assertSame(
            [
                'Omar set pin 1 on the checklist to Fixed',
                'Mia gave area Per product: Needs fix',
                'Mia set pin 2 in area Per product to Still wrong',
                'Created by Mia',
            ],
            $this->activitySentences(),
        );
        self::assertStringNotContainsString('Sum is off', (string) $this->client->getResponse()->getContent(), 'The area note is not in What happened.');
        self::assertNotSame([], $this->published, 'Open pages of the ticket are told to refresh.');
        $ticketTopic = RealtimeTopics::ofTicket($this->acmeFoods, new TicketId($this->ticketId));
        foreach ($this->published as $update) {
            self::assertSame([$ticketTopic], $update->getTopics());
        }
    }

    /**
     * The history is one query whatever it holds: three review changes by people the ticket does not name read no more
     * than one does. Both pages name someone outside the ticket, so both read the names of the movers.
     */
    #[Test]
    public function moreReviewChangesInWhatHappenedReadNoMoreQueries(): void
    {
        $this->addHistoryRow('Sara', 'area_verdict_saved', ['outcome' => 'pass', 'pin_number' => null, 'area_name' => 'Per product'], '2026-11-06 10:00:00+00');
        $this->logIn($this->client, $this->people['Lena']);
        $this->queryCountOf(self::TICKET_PAGE);
        $oneChangeQueryCount = $this->queryCountOf(self::TICKET_PAGE);
        self::assertSame(['Sara gave area Per product: Pass', 'Created by Mia'], $this->activitySentencesOf($this->client->getCrawler()));

        $this->addHistoryRow('Raj', 'pin_verdict_set', ['outcome' => 'fixed', 'pin_number' => 2, 'area_name' => 'Per product'], '2026-11-06 10:05:00+00');
        $this->addHistoryRow('Lena', 'check_item_changed', ['outcome' => 'ok', 'pin_number' => 1, 'area_name' => null], '2026-11-06 10:10:00+00');
        $this->queryCountOf(self::TICKET_PAGE);
        $threeChangesQueryCount = $this->queryCountOf(self::TICKET_PAGE);

        self::assertSame(
            ['Lena set pin 1 on the checklist to Checked OK', 'Raj set pin 2 in area Per product to Fixed', 'Sara gave area Per product: Pass', 'Created by Mia'],
            $this->activitySentencesOf($this->client->getCrawler()),
        );
        self::assertSame($oneChangeQueryCount, $threeChangesQueryCount);
    }

    private function seedReportChecklistAndReview(): void
    {
        $this->ticketId = $this->addTicket('SHOP-1');
        $report = $this->addRound($this->ticketId, 0, 'report', 'Mia', self::SHARED_EARLIER);
        $reportShot = $this->addScreenshot($report, $this->addMedia('ready'), 0, 'Product list', null);
        $this->pins[1] = $this->addPin($reportShot, 1, '10.00', '10.00', 'Price cut off', 'Mia');
        $this->pins[2] = $this->addPin($reportShot, 2, '20.00', '30.00', 'Total is wrong', 'Mia');
        $checklist = $this->addRound($this->ticketId, 1, 'checklist', 'Mia', self::SHARED_EARLIER);
        $this->checkItem = Uuid::v7()->toRfc4122();
        $parameters = [$this->acmeFoods->toString(), $this->checkItem, $checklist, $this->pins[1]];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO rvw_check_item (organization_id, id, round_id, pin_id, state, created_at) VALUES (?, ?, ?, ?, 'open', '2026-11-05 09:00:00+00')",
            $parameters,
        ));
        $review = $this->addRound($this->ticketId, 2, 'review', 'Omar', '2026-11-06 09:00:00+00');
        $afterShot = $this->addScreenshot($review, $this->addMedia('ready'), 0, 'Product list after', null);
        $this->perProduct = $this->addArea($review, 'Per product', 0, $reportShot, $afterShot, null, null);
    }

    /**
     * A review change row as RecordReviewChangesHandler writes it, with no steps.
     *
     * @param array{outcome: string, pin_number: int|null, area_name: string|null} $payload
     */
    private function addHistoryRow(string $actor, string $type, array $payload, string $at): void
    {
        $parameters = [Uuid::v7()->toRfc4122(), $this->acmeFoods->toString(), $this->ticketId, $this->people[$actor]->id()->toString(), $type, json_encode($payload, \JSON_THROW_ON_ERROR), $at];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            'INSERT INTO tkt_ticket_event (id, organization_id, ticket_id, actor_id, type, from_step, to_step, payload, at)
             VALUES (?, ?, ?, ?, ?, NULL, NULL, CAST(? AS jsonb), CAST(? AS timestamptz))',
            $parameters,
        ));
    }

    /**
     * @param array<string, string> $fields
     */
    private function postAsTurbo(string $url, array $fields): void
    {
        $token = $this->csrfTokenFor($this->client, VerdictController::csrfIdOf('SHOP-1'));
        $this->client->request('POST', $url, [VerdictController::CSRF_FIELD => $token, ...$fields], [], self::TURBO_FORM_HEADERS);
        self::assertResponseStatusCodeSame(200);
    }

    /**
     * @return list<string> What happened's sentences on the ticket page, newest first
     */
    private function activitySentences(): array
    {
        $page = $this->client->request('GET', self::TICKET_PAGE);
        self::assertResponseStatusCodeSame(200);

        return $this->activitySentencesOf($page);
    }

    /**
     * @return list<string>
     */
    private function activitySentencesOf(Crawler $page): array
    {
        return $page->filter('.activity__sentence')->each(static fn (Crawler $sentence): string => $sentence->text());
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

    private function checklistCommands(): ChecklistCommands
    {
        $commands = self::getContainer()->get('test.review_checklist_commands');
        self::assertInstanceOf(ChecklistCommands::class, $commands);

        return $commands;
    }

    private function connection(): Connection
    {
        return $this->entityManager()->getConnection();
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), self::getContainer()->get(TenantContext::class));
    }
}
