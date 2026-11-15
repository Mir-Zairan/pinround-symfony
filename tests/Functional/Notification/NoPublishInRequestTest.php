<?php

declare(strict_types=1);

namespace App\Tests\Functional\Notification;

use App\Conversation\UI\ThreadController;
use App\Identity\Domain\User;
use App\Notification\Infrastructure\Mercure\RefreshBellHandler;
use App\Notification\Infrastructure\Mercure\RefreshTicketPagesHandler;
use App\Notification\UI\Controller\PingController;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Web\CreatesAcmeFoodsTeams;
use App\Tests\Functional\Web\CreatesTeams;
use App\Tests\Functional\Web\IssuesCsrfTokens;
use App\Ticketing\Infrastructure\Scheduler\AddTicketEventPartitions;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mercure\Debug\TraceableHub;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * The thread's comment, question and answer and the ticket page's ping, on Acme Foods' SHOP-1 (reported by Lena,
 * assigned to Omar) through the real firewall, voters, session and database: each request writes its event to the
 * outbox and publishes nothing, neither a ticket page refresh nor a bell push; worker-notify does that. The clock is
 * fixed on 6 Nov 2026, before today.
 */
final class NoPublishInRequestTest extends WebTestCase
{
    use CreatesAcmeFoodsTeams;
    use CreatesTeams;
    use Factories;
    use IssuesCsrfTokens;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }

    private const string NOW = '2026-11-06 12:00:00 UTC';
    private const string KEY = 'SHOP-1';

    private KernelBrowser $client;

    /** @var array<string, User> */
    private array $people;

    private string $shopProject;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTPS' => 'on']);
        $this->client->disableReboot();
        $clock = new MockClock(new \DateTimeImmutable(self::NOW));
        self::getContainer()->set('clock', $clock);
        new AddTicketEventPartitions($this->migrator(), $clock)();
        $this->people = $this->createAcmeFoodsPeople();
        $this->shopProject = $this->addProject($this->shop, 'SHOP', 'Shop');
        $this->addTicket();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdOrganizationIds as $organizationId) {
            $heldSlots = $this->stateValkey()->keys('ping:'.$organizationId.':*');
            if (\is_array($heldSlots) && [] !== $heldSlots) {
                $this->stateValkey()->del($heldSlots);
            }
            $this->committedAs(new OrganizationId($organizationId), static function (Connection $migrator) use ($organizationId): int {
                foreach (['ntf_ping', 'cnv_reply', 'cnv_message', 'tkt_ticket_event', 'ins_ticket_turn', 'ins_project_count_ticket', 'ins_project_counts', 'tkt_ticket', 'wf_step', 'wf_rule_set'] as $table) {
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
     * Each check is read before the next request: a request resets the hub's trace and the in-memory transport.
     */
    #[Test]
    public function aCommentAQuestionItsAnswerAndAPingPublishNothingInTheRequestOnlyTheirEventsToTheOutbox(): void
    {
        $lena = $this->people['Lena']->id()->toString();
        $omar = $this->people['Omar']->id()->toString();

        $this->logIn($this->client, $this->people['Lena']);
        $this->client->request('POST', '/t/'.self::KEY.'/comments', $this->threadFields('The sum is wrong on the cart page.'));
        $this->assertNothingIsPublishedAndTheOutboxHolds(1);

        $this->logIn($this->client, $this->people['Omar']);
        $this->client->request('POST', '/t/'.self::KEY.'/questions', [...$this->threadFields('Which build shows the wrong sum?'), ThreadController::ASKED_USER_FIELD => $lena]);
        $this->assertNothingIsPublishedAndTheOutboxHolds(2);

        $this->logIn($this->client, $this->people['Lena']);
        $this->client->request('POST', '/t/'.self::KEY.'/messages/'.$this->openQuestionId().'/answer', $this->threadFields('Build 42.'));
        $this->assertNothingIsPublishedAndTheOutboxHolds(3);

        $this->client->request('POST', '/t/'.self::KEY.'/ping/'.$omar, [
            PingController::CSRF_FIELD => $this->csrfTokenFor($this->client, PingController::csrfIdOf(self::KEY)),
            PingController::MESSAGE_FIELD => 'Hi Omar, could you look at SHOP-1?',
        ]);
        $this->assertNothingIsPublishedAndTheOutboxHolds(4);

        // The trace is of the hub both push handlers publish to, so its empty list is not one nobody writes to.
        foreach ([RefreshTicketPagesHandler::class, RefreshBellHandler::class] as $handlerClass) {
            self::assertSame($this->tracedHub(), new \ReflectionProperty($handlerClass, 'hub')->getValue(self::getContainer()->get($handlerClass)), $handlerClass);
        }
    }

    private function assertNothingIsPublishedAndTheOutboxHolds(int $expectedOutboxCount): void
    {
        self::assertResponseStatusCodeSame(303);
        self::assertSame([], $this->tracedHub()->getMessages(), 'pushes are published by worker-notify, never by the request');
        self::assertSame([], $this->asyncNotify()->getSent(), 'the request sends nothing to async_notify; the relay does, from the outbox');
        self::assertSame($expectedOutboxCount, $this->outboxCount());
    }

    /**
     * @return array<string, string>
     */
    private function threadFields(string $body): array
    {
        return [
            ThreadController::CSRF_FIELD => $this->csrfTokenFor($this->client, ThreadController::csrfIdOf(self::KEY)),
            ThreadController::BODY_FIELD => $body,
        ];
    }

    /** The test env's default hub: kernel.debug wraps it in the bundle's trace for the profiler. */
    private function tracedHub(): TraceableHub
    {
        return self::getContainer()->get('mercure.hub.default.traceable');
    }

    private function asyncNotify(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async_notify');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function stateValkey(): \Redis
    {
        return self::getContainer()->get('state_valkey.connection');
    }

    /**
     * A bug of Shop's team in To do, reported by Lena and assigned to Omar.
     */
    private function addTicket(): void
    {
        $stepId = Uuid::v7()->toRfc4122();
        $parameters = [
            $this->acmeFoods->toString(), $stepId,
            $this->acmeFoods->toString(), Uuid::v7()->toRfc4122(), $this->shopProject, $this->shop->toString(), $stepId,
            $this->people['Lena']->id()->toString(), $this->people['Omar']->id()->toString(),
        ];
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($parameters): int {
            $migrator->executeStatement(
                "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, 'To do', 'todo', 'todo', 'core', 1)",
                \array_slice($parameters, 0, 2),
            );

            return (int) $migrator->executeStatement(
                "INSERT INTO tkt_ticket (organization_id, id, project_id, team_id, number, key, title, description, type, priority, priority_rank,
                     step_id, reporter_id, assignee_id, visibility, version, components, check_reasons, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 1, 'SHOP-1', 'Cart total shows the wrong sum', '', 'bug', 'major', 2, ?, ?, ?, 'all', 3, '{}', '{}',
                     TIMESTAMPTZ '2026-11-04 10:00:00+00', TIMESTAMPTZ '2026-11-05 10:00:00+00')",
                \array_slice($parameters, 2),
            );
        });
    }

    private function openQuestionId(): string
    {
        $organizationId = $this->acmeFoods->toString();
        $questionId = null;
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($organizationId, &$questionId): int {
            $questionId = $migrator->fetchOne(
                "SELECT id::text FROM cnv_message WHERE organization_id = ? AND kind = 'question' AND resolved_at IS NULL",
                [$organizationId],
            );

            return 0;
        });
        self::assertIsString($questionId);

        return $questionId;
    }

    private function outboxCount(): int
    {
        $organizationId = $this->acmeFoods->toString();
        $count = 0;
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($organizationId, &$count): int {
            $outboxRows = $migrator->fetchOne("SELECT count(*) FROM messenger_outbox WHERE queue_name = 'outbox' AND position(? in body) > 0", [$organizationId]);
            self::assertIsNumeric($outboxRows);
            $count = (int) $outboxRows;

            return 0;
        });

        return $count;
    }
}
