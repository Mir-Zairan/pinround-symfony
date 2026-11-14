<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use App\Identity\Domain\User;
use App\Notification\UI\Controller\PingController;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantNotSet;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Infrastructure\Http\RecordingCsrfTokenStorage;
use App\SharedKernel\Infrastructure\Tracing\CurrentSpan;
use App\Tests\Fixtures\Entity\TenantProbe;
use App\Tests\Fixtures\Tracing\ExportedSpans;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Organization\SendsInvitationLinks;
use App\Ticketing\UI\MoveToasts;
use App\Ticketing\UI\TicketMoveController;
use App\Ticketing\UI\TicketPageParts;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use OpenTelemetry\API\Trace\SpanContextValidator;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * In worker mode (ADR-0008) one kernel handles request after request; disableReboot() keeps the browser on one
 * kernel the same way. The kernel's services_resetter, run when the next main request starts, must leave it no
 * tenant and an open entity manager, through the test-only endpoints in config/routes/test_fixtures.yaml.
 */
final class WorkerResetTest extends WebTestCase
{
    use CreatesAcmeFoodsTeams;
    use CreatesTeams;
    use Factories;
    use IssuesCsrfTokens;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }
    use SendsInvitationLinks;

    private const string ORG_A = '0197a3c0-0000-7000-8000-00000000000a';
    /** Before today: a clock ahead of the login would end the session as idle. */
    private const string NOW = '2026-11-06 12:00:00 UTC';
    private const int SHOP_1_VERSION = 3;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $this->client->disableReboot();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdOrganizationIds as $organizationId) {
            $this->committedAs(new OrganizationId($organizationId), static function (Connection $migrator) use ($organizationId): int {
                foreach (['ntf_ping', 'tkt_ticket', 'wf_step'] as $table) {
                    $migrator->executeStatement(\sprintf('DELETE FROM %s WHERE organization_id = ?', $table), [$organizationId]);
                }
                $migrator->executeStatement("DELETE FROM messenger_outbox WHERE queue_name = 'outbox' AND position(? in body) > 0", [$organizationId]);

                return 0;
            });
            // The state Valkey never evicts, so each test's ping slots go with its organizations.
            $heldSlots = $this->stateValkey()->keys('ping:'.$organizationId.':*');
            if (\is_array($heldSlots) && [] !== $heldSlots) {
                $this->stateValkey()->del($heldSlots);
            }
        }
        $this->removeCommittedProjects();
        $this->removeUsersAndOrganizations();
    }

    #[Test]
    public function noLoginSurvivesIntoTheNextRequestWithoutTheSessionCookie(): void
    {
        $this->logIn($this->client, $this->createUserWithPassword('Lena Berg'));
        $this->client->request('GET', '/');
        self::assertResponseStatusCodeSame(200);

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/');

        self::assertResponseRedirects('/login', 302);
    }

    #[Test]
    public function theNextRequestHasNoStoredCsrfTokenNotedAfterARequestStoredOne(): void
    {
        $this->logIn($this->client, $this->createUserWithPassword('Lena Berg'));
        $this->client->request('GET', '/');
        self::assertResponseStatusCodeSame(200);
        $storage = $this->client->getContainer()->get('security.csrf.token_storage');
        self::assertInstanceOf(RecordingCsrfTokenStorage::class, $storage);
        self::assertTrue($storage->hasStoredToken(), 'the page stored no token, so this test proves nothing');

        $this->client->request('GET', '/_test/worker-reset');

        self::assertResponseStatusCodeSame(200);
        self::assertFalse($storage->hasStoredToken());
    }

    #[Test]
    public function theNextRequestHasNoTenantAfterARequestThatEndedNormally(): void
    {
        $this->client->request('POST', '/_test/worker-reset/'.self::ORG_A);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['organizationId' => self::ORG_A, 'isEntityManagerOpen' => true], $this->responseJson());

        $this->client->request('GET', '/_test/worker-reset');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['organizationId' => null, 'isEntityManagerOpen' => true], $this->responseJson());
    }

    #[Test]
    public function theNextRequestHasNoTenantAndAnOpenEntityManagerAfterARequestThatThrew(): void
    {
        $this->client->request('POST', '/_test/worker-reset/'.self::ORG_A.'/fail');
        self::assertResponseStatusCodeSame(500);
        self::assertSame(self::ORG_A, $this->tenantContext()->get()?->toString());
        self::assertFalse($this->entityManager()->isOpen());

        $this->client->request('GET', '/_test/worker-reset');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['organizationId' => null, 'isEntityManagerOpen' => true], $this->responseJson());
    }

    #[Test]
    public function theNextRequestHasNoTenantAndAnOpenEntityManagerAfterARefusedInvitationJoin(): void
    {
        $this->client->setServerParameter('HTTPS', 'on');
        $link = $this->invite($this->createOrganization('Acme Foods'), 'mia.'.bin2hex(random_bytes(4)).'@acme-foods.example');
        $this->client->request('GET', (string) parse_url($link, \PHP_URL_PATH));
        self::assertResponseRedirects('/invite', 302);
        $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);

        $this->client->submitForm('Join', ['name' => 'Mia Lund', 'password' => 'elevenchars']);
        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->tenantContext()->get());
        self::assertFalse($this->entityManager()->isOpen(), 'the refused accept no longer closes the entity manager, so this test proves nothing');

        $this->client->request('GET', '/_test/worker-reset');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['organizationId' => null, 'isEntityManagerOpen' => true], $this->responseJson());
    }

    /**
     * Closing the connection ends its transaction without a ROLLBACK reaching the driver, so only the reset can tell
     * AfterCommitTagInvalidation the transaction is over; until it does, an invalidation outside a transaction waits for
     * a commit.
     */
    #[Test]
    public function theNextRequestInvalidatesTheWorkflowCacheAtOnceAfterARequestClosedTheConnectionInsideATransaction(): void
    {
        $this->client->request('POST', '/_test/worker-reset/workflow-cache/'.self::ORG_A.'/close-then-fail');
        self::assertResponseStatusCodeSame(500);
        self::assertFalse($this->client->getContainer()->get('doctrine.dbal.default_connection')->isConnected());

        $this->client->request('POST', '/_test/worker-reset/workflow-cache/'.self::ORG_A.'/invalidate');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['entry' => 'gone after the invalidation'], $this->responseJson());
    }

    /**
     * The same for the team people cache, which shares the transaction bookkeeping: a tenant left behind would also let
     * the next request cache a read with no transaction open, under the failed request's organization.
     */
    #[Test]
    public function theNextRequestInvalidatesTheTeamPeopleCacheAtOnceAndCachesNothingAfterARequestClosedTheConnectionInsideATransaction(): void
    {
        $this->client->request('POST', '/_test/worker-reset/team-people-cache/'.self::ORG_A.'/close-then-fail');
        self::assertResponseStatusCodeSame(500);
        self::assertFalse($this->client->getContainer()->get('doctrine.dbal.default_connection')->isConnected());

        $this->client->request('POST', '/_test/worker-reset/team-people-cache/invalidate');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['entry' => 'gone after the invalidation', 'loads' => 2], $this->responseJson());
    }

    /**
     * A stale move rolls back and closes the entity manager; the Turbo answer's re-read resets it inside the request.
     * The rebuilt manager must still leave the next request no tenant, and its tenant filter on.
     */
    #[Test]
    public function theNextRequestHasNoTenantAndATenantFilteredEntityManagerAfterAMoveReopenedItForItsStream(): void
    {
        $this->client->setServerParameter('HTTPS', 'on');
        self::getContainer()->set('clock', new MockClock(new \DateTimeImmutable(self::NOW)));
        $people = $this->createAcmeFoodsPeople();
        $this->addCoreTodoStepAndShop1($people['Lena'], $people['Omar']);
        $ticketBefore = $this->shop1Row();
        $this->logIn($this->client, $people['Lena']);

        $this->client->request(
            'POST',
            '/t/SHOP-1/move/put_on_hold',
            [
                TicketMoveController::CSRF_FIELD => $this->csrfTokenFor($this->client, TicketMoveController::csrfIdOf('SHOP-1')),
                TicketMoveController::VERSION_FIELD => (string) (self::SHOP_1_VERSION - 1),
            ],
            [],
            ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml'],
        );

        self::assertResponseStatusCodeSame(422);
        self::assertSame(MoveToasts::CHANGED_MEANWHILE, $this->streamedToast());
        self::assertSame(
            [TicketPageParts::STATE_ID => 1, TicketPageParts::NEXT_STEP_ID => 1],
            [TicketPageParts::STATE_ID => $this->replacedPartCount(TicketPageParts::STATE_ID), TicketPageParts::NEXT_STEP_ID => $this->replacedPartCount(TicketPageParts::NEXT_STEP_ID)],
        );
        self::assertTrue($this->entityManager()->isOpen());
        self::assertSame($this->acmeFoods->toString(), $this->tenantContext()->get()?->toString());

        // Lena's session would enter Acme Foods again; the next request is someone else's.
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/_test/worker-reset');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['organizationId' => null, 'isEntityManagerOpen' => true], $this->responseJson());
        // Read only now: committedAs resets the tenant itself.
        self::assertSame($ticketBefore, $this->shop1Row());
        $this->expectException(TenantNotSet::class);
        $this->entityManager()->createQuery('SELECT p FROM '.TenantProbe::class.' p')->getSQL();
    }

    /**
     * PingedState keeps the slots it read for the rest of a request; the next request must read them again, or Lena
     * would stay pinged on Omar's page after her slot has ended.
     */
    #[Test]
    public function theNextRequestShowsThePingButtonAgainAfterAnEarlierRequestShowedTheSlotHeld(): void
    {
        $this->client->setServerParameter('HTTPS', 'on');
        $clock = new MockClock(new \DateTimeImmutable(self::NOW));
        self::getContainer()->set('clock', $clock);
        $people = $this->createAcmeFoodsPeople();
        $this->addCoreTodoStepAndShop1($people['Lena'], $people['Omar']);
        $lenaId = $people['Lena']->id()->toString();
        $shop1Id = $this->shop1Row()['id'];
        self::assertIsString($shop1Id);
        $slotKey = \sprintf('ping:%s:%s:%s:%s', $this->acmeFoods->toString(), $people['Omar']->id()->toString(), $lenaId, $shop1Id);
        $this->logIn($this->client, $people['Omar']);
        $this->client->request(
            'POST',
            '/t/SHOP-1/ping/'.$lenaId,
            [
                PingController::CSRF_FIELD => $this->csrfTokenFor($this->client, PingController::csrfIdOf('SHOP-1')),
                PingController::MESSAGE_FIELD => 'Hi Lena, could you look at SHOP-1?',
            ],
            [],
            ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml'],
        );
        self::assertResponseStatusCodeSame(200);
        $clock->modify('+90 minutes');

        $page = $this->client->request('GET', '/t/SHOP-1');
        self::assertResponseStatusCodeSame(200);
        self::assertSame('Pinged · again in 3 h', trim($page->filter('#ping-'.$lenaId)->text()));

        // Valkey ends the slot by its own clock, not the frozen one, so the test ends it by hand.
        self::assertSame(1, $this->stateValkey()->del($slotKey));
        $clock->modify('+3 hours');
        $page = $this->client->request('GET', '/t/SHOP-1');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Ping Lena'], $page->filter('#ping-'.$lenaId.' .dialog__title')->each(static fn (Crawler $title): string => trim($title->text())));
    }

    #[Test]
    public function theNextRequestStartsANewTraceWithNoParentAfterARequestThatThrew(): void
    {
        $exporter = $this->client->getContainer()->get('tracing.in_memory_exporter');
        self::assertInstanceOf(InMemoryExporter::class, $exporter);

        $this->client->request('GET', '/_test/tracing/fail');
        self::assertResponseStatusCodeSame(500);
        $this->client->request('GET', '/_test/tracing');
        self::assertResponseStatusCodeSame(200);

        $roots = array_values(array_filter(
            ExportedSpans::of($exporter),
            static fn (SpanDataInterface $span): bool => !SpanContextValidator::isValidSpanId($span->getParentSpanId()),
        ));
        self::assertCount(2, $roots);
        self::assertSame('HTTP GET test_tracing_fail', $roots[0]->getName());
        self::assertSame('HTTP GET test_tracing_respond', $roots[1]->getName());
        self::assertNotSame($roots[0]->getTraceId(), $roots[1]->getTraceId());
        self::assertNull($this->client->getContainer()->get(CurrentSpan::class)->span());
    }

    private function tenantContext(): TenantContext
    {
        return $this->client->getContainer()->get(TenantContext::class);
    }

    private function entityManager(): EntityManagerInterface
    {
        return $this->client->getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private function stateValkey(): \Redis
    {
        return self::getContainer()->get('state_valkey.connection');
    }

    private function responseJson(): mixed
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    private function streamedToast(): string
    {
        self::assertSame(1, preg_match(
            '#<turbo-stream action="update" target="toast-region"><template>(.*?)</template></turbo-stream>#s',
            (string) $this->client->getResponse()->getContent(),
            $match,
        ));
        $toast = new Crawler('<!DOCTYPE html><html><body>'.$match[1].'</body></html>')->filter('.toast[data-controller="toast"]');
        self::assertCount(1, $toast);

        return (string) $toast->attr('data-toast-message-value');
    }

    /**
     * @return int how many elements of the id the stream's replace of that id carries, 0 when there is no such replace
     */
    private function replacedPartCount(string $partId): int
    {
        if (1 !== preg_match(
            '#<turbo-stream action="replace" target="'.preg_quote($partId, '#').'"><template>(.*?)</template></turbo-stream>#s',
            (string) $this->client->getResponse()->getContent(),
            $match,
        )) {
            return 0;
        }

        return new Crawler('<!DOCTYPE html><html><body>'.$match[1].'</body></html>')->filter('#'.$partId)->count();
    }

    /**
     * Acme Foods' core To do step, and Shop's SHOP-1 in it at SHOP_1_VERSION, reported by Lena and assigned to Omar.
     */
    private function addCoreTodoStepAndShop1(User $reporter, User $assignee): void
    {
        $organizationId = $this->acmeFoods->toString();
        $todoStepId = Uuid::v7()->toRfc4122();
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, 'To do', 'todo', 'todo', 'core', 1)",
            [$organizationId, $todoStepId],
        ));
        $shopProjectId = $this->addProject($this->shop, 'SHOP', 'Shop');
        $reporterId = $reporter->id()->toString();
        $assigneeId = $assignee->id()->toString();
        $shopTeamId = $this->shop->toString();
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO tkt_ticket (organization_id, id, project_id, team_id, number, key, title, description, type, priority, priority_rank,
                 step_id, reporter_id, assignee_id, reviewer_id, waiting_on_user_id, visibility, version, components, check_reasons, created_at, updated_at)
             VALUES (?, ?, ?, ?, 1, 'SHOP-1', 'Cart total shows the wrong sum', '', 'bug', 'major', 2, ?, ?, ?, NULL, NULL, 'all', ?, '{}', '{}',
                 TIMESTAMPTZ '2026-11-04 10:00:00+00', TIMESTAMPTZ '2026-11-05 10:00:00+00')",
            [$organizationId, Uuid::v7()->toRfc4122(), $shopProjectId, $shopTeamId, $todoStepId, $reporterId, $assigneeId, self::SHOP_1_VERSION],
        ));
    }

    /**
     * @return array<string, mixed> SHOP-1's columns, and the count of Acme Foods' ticket history rows
     */
    private function shop1Row(): array
    {
        $organizationId = $this->acmeFoods->toString();
        $row = [];
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($organizationId, &$row): int {
            $row = $migrator->fetchAssociative(
                "SELECT t.*, (SELECT count(*) FROM tkt_ticket_event e WHERE e.organization_id = t.organization_id) AS events
                 FROM tkt_ticket t WHERE t.organization_id = ? AND t.key = 'SHOP-1'",
                [$organizationId],
            );

            return 0;
        });
        self::assertIsArray($row);

        return $row;
    }
}
