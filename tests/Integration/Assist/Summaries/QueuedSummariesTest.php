<?php

declare(strict_types=1);

namespace App\Tests\Integration\Assist\Summaries;

use App\Assist\Api\Summaries;
use App\Assist\Api\SummaryRequestRefused;
use App\Assist\Api\SummaryRequestView;
use App\Assist\Application\Gateway\AiTaskAdmission;
use App\Assist\Application\Summaries\GenerateSummary;
use App\Assist\Application\Summaries\QueuedSummaries;
use App\Assist\Application\Summaries\SummaryJobQueue;
use App\Assist\Application\Summaries\SummaryRequesters;
use App\Assist\Application\Summaries\SummaryRequests;
use App\Assist\Infrastructure\Platform\FakeProvider;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\SharedKernel\Infrastructure\Messenger\TenantStamp;
use App\Tests\Factory\Ticketing\TicketFactory;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Ticketing\ShopTeamRows;
use App\Tests\Integration\Ticketing\TicketRows;
use App\Ticketing\Api\TicketCommands;
use App\Ticketing\Api\TicketQueries;
use App\Ticketing\Api\TicketVisibilities;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * "Summarize with AI" as the container wires it, as pinround_app in a transaction under Acme Foods that each test rolls
 * back, with the job queue on the in-memory async_ai transport and the request state on the test Valkey. Lena leads
 * the Shop team that owns SHOP; Omar is in Acme Foods but in no team, so he sees the checkout ticket for everyone but
 * not the team-only one; Mia has an account but is no member. Beta Bakery has a ticket of its own. The clock is frozen
 * in March 2027.
 */
final class QueuedSummariesTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000c7a01';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000c7a02';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-0000000c7a21';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000c7a41';
    private const string BETA_PROJECT = '0197a3c0-0000-7000-8000-0000000c7a42';
    private const string BETA_TICKET = '0197a3c0-0000-7000-8000-0000000c7a32';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000c7a11';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000c7a12';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000c7a13';
    private const string NOW = '2027-03-15 10:00:00 UTC';
    private const string AI_ON_IN_THE_CLOUD = '{"on": true, "where": "cloud"}';

    private static ?TicketId $checkoutTicketId = null;
    private static ?TicketId $teamOnlyTicketId = null;

    public static function setUpBeforeClass(): void
    {
        self::removeRows();
        self::shopTeamRows()->add(self::ACME_FOODS, self::SHOP_TEAM, self::SHOP_PROJECT, self::LENA, [self::LENA => ['Lena', 'lead', 'developer']]);
        self::shopTeamRows()->addUser(self::OMAR, 'Omar');
        self::shopTeamRows()->addUser(self::MIA, 'Mia');
        self::transactions()->committedAs(self::ACME_FOODS, static function (): void {
            self::connection()->executeStatement(
                "INSERT INTO org_membership (organization_id, user_id, org_roles, joined_at) VALUES (?, ?, '{}', '2027-03-15 10:00:00+00')",
                [self::ACME_FOODS, self::OMAR],
            );
            self::connection()->executeStatement(
                "INSERT INTO org_settings (organization_id, data, created_at) VALUES (?, '{}'::jsonb, '2027-03-15 10:00:00+00')",
                [self::ACME_FOODS],
            );
        });

        $ticketCommands = self::getContainer()->get('test.ticketing_ticket_commands');
        \assert($ticketCommands instanceof TicketCommands);
        $tickets = new TicketFactory($ticketCommands, self::transactions(), self::connection());
        self::$checkoutTicketId = $tickets->createOne(self::ACME_FOODS, self::SHOP_PROJECT, self::LENA, ['title' => 'Checkout fails', 'description' => 'Pay does nothing.']);
        $teamOnlyTicketId = $tickets->createOne(self::ACME_FOODS, self::SHOP_PROJECT, self::LENA, ['title' => 'Card numbers in the log', 'description' => 'Card numbers show.']);
        self::$teamOnlyTicketId = $teamOnlyTicketId;
        // No command restricts a ticket yet; the turn row copies the column.
        self::tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        try {
            self::migrator()->transactional(static function (Connection $migrator) use ($teamOnlyTicketId): void {
                $migrator->executeStatement("UPDATE tkt_ticket SET visibility = 'team' WHERE id = ?", [$teamOnlyTicketId->toString()]);
                $migrator->executeStatement("UPDATE ins_ticket_turn SET visibility = 'team' WHERE ticket_id = ?", [$teamOnlyTicketId->toString()]);
            });
        } finally {
            self::tenantContext()->clear();
        }
        new TicketRows(self::migrator(), self::tenantContext())->addOf(self::BETA_BAKERY, self::BETA_TICKET, self::BETA_PROJECT, self::LENA, 7);
        self::ensureKernelShutdown();
    }

    public static function tearDownAfterClass(): void
    {
        self::removeRows();
        self::$checkoutTicketId = null;
        self::$teamOnlyTicketId = null;
        self::ensureKernelShutdown();
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        self::mockTime(self::NOW);
        $this->forgetRequestStates();
    }

    protected function tearDown(): void
    {
        $this->forgetRequestStates();
        self::getContainer()->get('doctrine')->resetManager();
        self::tenantContext()->reset();
        parent::tearDown();
    }

    #[Test]
    public function aTicketOmarMaySeeQueuesOneGenerateSummaryOnAsyncAiAndMarksItQueued(): void
    {
        $this->requestAs(self::OMAR, self::AI_ON_IN_THE_CLOUD, self::checkoutTicketId());

        $sent = $this->asyncAi()->getSent();
        self::assertCount(1, $sent);
        self::assertEquals(new GenerateSummary(self::ACME_FOODS, self::checkoutTicketId()->toString(), self::OMAR), $sent[0]->getMessage());
        self::assertEquals(new TenantStamp(new OrganizationId(self::ACME_FOODS)), $sent[0]->last(TenantStamp::class));
        $queued = $this->stateOf(self::checkoutTicketId());
        self::assertNotNull($queued);
        self::assertSame([SummaryRequestView::QUEUED, self::OMAR, '2027-03-15 10:00:00', null], self::fieldsOf($queued));
        self::assertSame([], $this->fakeProvider()->calls(), 'Queueing asked the AI.');
    }

    #[Test]
    public function aSecondRequestWhileOneIsQueuedIsRefusedAndQueuesNothing(): void
    {
        $this->requestAs(self::OMAR, self::AI_ON_IN_THE_CLOUD, self::checkoutTicketId());

        try {
            $this->requestAs(self::LENA, self::AI_ON_IN_THE_CLOUD, self::checkoutTicketId());
            self::fail('A second summary of the ticket was queued.');
        } catch (SummaryRequestRefused $refusal) {
            self::assertSame([SummaryRequestRefused::ALREADY_QUEUED, 'A summary is already on its way.'], [$refusal->refusalCode, $refusal->getMessage()]);
        }

        self::assertCount(1, $this->asyncAi()->getSent());
        $queued = $this->stateOf(self::checkoutTicketId());
        self::assertNotNull($queued);
        self::assertSame(self::OMAR, $queued->requesterId->toString(), 'The refused request replaced the queued one.');
    }

    /**
     * @return iterable<string, array{string, string, string, string, string}>
     */
    public static function refusedRequests(): iterable
    {
        yield 'a team-only ticket of a team Omar is not in' => [self::OMAR, self::AI_ON_IN_THE_CLOUD, 'team-only', 'cannot_see_ticket', 'AI help works only on tickets you can see.'];
        yield 'another organization\'s ticket' => [self::OMAR, self::AI_ON_IN_THE_CLOUD, 'beta', 'cannot_see_ticket', 'AI help works only on tickets you can see.'];
        yield 'a person who is no member' => [self::MIA, self::AI_ON_IN_THE_CLOUD, 'checkout', 'cannot_see_ticket', 'AI help works only on tickets you can see.'];
        yield 'AI off' => [self::OMAR, '{"on": false}', 'checkout', 'ai_off', 'Your admin turned AI help off.'];
        yield 'leads only, Omar leads no team' => [self::OMAR, '{"on": true, "where": "cloud", "who": "leads_only"}', 'checkout', 'leads_only', 'Your admin allows AI help for leads only.'];
        yield 'a team-only ticket kept from cloud AI' => [self::LENA, self::AI_ON_IN_THE_CLOUD, 'team-only', 'restricted_ticket_to_cloud', 'Your admin keeps team-only tickets away from cloud AI.'];
    }

    #[Test]
    #[DataProvider('refusedRequests')]
    public function aRequestThePolicyRefusesSaysWhyAndQueuesAndKeepsNothing(string $requesterId, string $aiJson, string $ticket, string $expectedCode, string $expectedSentence): void
    {
        $ticketId = self::ticketIdOf($ticket);

        try {
            $this->requestAs($requesterId, $aiJson, $ticketId);
            self::fail('A request the policy refuses was queued.');
        } catch (SummaryRequestRefused $refusal) {
            self::assertSame([$expectedCode, $expectedSentence], [$refusal->refusalCode, $refusal->getMessage()]);
        }

        self::assertSame([], $this->asyncAi()->getSent());
        self::assertNull($this->stateOf($ticketId));
        self::assertNull($this->requests()->latestOf(new OrganizationId(self::BETA_BAKERY), $ticketId));
    }

    #[Test]
    public function aRequestTheAdminLetsThroughForATeamOnlyTicketIsQueued(): void
    {
        $this->requestAs(self::LENA, '{"on": true, "where": "cloud", "restricted_to_cloud": true}', self::teamOnlyTicketId());

        self::assertCount(1, $this->asyncAi()->getSent());
        self::assertNotNull($this->stateOf(self::teamOnlyTicketId()));
    }

    #[Test]
    public function aQueueThatFailsReleasesTheClaimSoThePersonMayTryAgain(): void
    {
        $queueFailure = new \RuntimeException('The queue cannot be reached.');
        $failingQueue = new readonly class($queueFailure) implements SummaryJobQueue {
            public function __construct(private \RuntimeException $queueFailure)
            {
            }

            public function queue(GenerateSummary $generateSummary): void
            {
                throw $this->queueFailure;
            }
        };
        $summaries = $this->summariesWith($failingQueue);

        try {
            $this->requestAs(self::OMAR, self::AI_ON_IN_THE_CLOUD, self::checkoutTicketId(), $summaries);
            self::fail('The queue failure was swallowed.');
        } catch (\RuntimeException $failure) {
            self::assertSame($queueFailure, $failure);
        }

        self::assertNull($this->stateOf(self::checkoutTicketId()), 'The claim stayed, so the button would stay blocked for an hour.');
        $this->requestAs(self::OMAR, self::AI_ON_IN_THE_CLOUD, self::checkoutTicketId());
        self::assertCount(1, $this->asyncAi()->getSent());
    }

    #[Test]
    public function theLatestRequestIsShownToEveryMemberWhoMaySeeTheTicket(): void
    {
        $this->requestAs(self::OMAR, self::AI_ON_IN_THE_CLOUD, self::checkoutTicketId());

        foreach ([self::OMAR, self::LENA] as $viewerId) {
            $latest = $this->latestRequestAs($viewerId, self::checkoutTicketId());
            self::assertNotNull($latest, $viewerId);
            self::assertSame([SummaryRequestView::QUEUED, self::OMAR, '2027-03-15 10:00:00', null], self::fieldsOf($latest));
        }
    }

    #[Test]
    public function aPersonWhoMayNotSeeTheTicketGetsNoRequestEvenWhenOneExists(): void
    {
        self::assertTrue($this->requests()->claim(new OrganizationId(self::ACME_FOODS), self::teamOnlyTicketId(), new UserId(self::LENA)));
        $this->requests()->recordFailure(new OrganizationId(self::ACME_FOODS), self::checkoutTicketId(), new UserId(self::OMAR), 'The AI answer failed. Try again.');

        self::assertNotNull($this->latestRequestAs(self::LENA, self::teamOnlyTicketId()), 'Lena leads Shop, so the state proves nothing if she gets none.');
        self::assertNull($this->latestRequestAs(self::OMAR, self::teamOnlyTicketId()));
        self::assertNull($this->latestRequestAs(self::MIA, self::checkoutTicketId()), 'Mia is no member of Acme Foods.');
    }

    #[Test]
    public function aTicketWithNoRequestHasNoLatestRequest(): void
    {
        self::assertNull($this->latestRequestAs(self::LENA, self::checkoutTicketId()));
    }

    /**
     * Stores data.ai and asks for the summary in one transaction under Acme Foods that is rolled back; the job and the
     * request state stay, as they are not in the database.
     */
    private function requestAs(string $requesterId, string $aiJson, TicketId $ticketId, ?Summaries $summaries = null): void
    {
        $summaries ??= $this->summaries();
        self::transactions()->rolledBackAs(self::ACME_FOODS, static function () use ($summaries, $requesterId, $aiJson, $ticketId): void {
            self::connection()->executeStatement("UPDATE org_settings SET data = jsonb_set(data, '{ai}', ?::jsonb) WHERE organization_id = ?", [$aiJson, self::ACME_FOODS]);
            $summaries->request($ticketId, new UserId($requesterId), new OrganizationId(self::ACME_FOODS));
        });
    }

    private function latestRequestAs(string $viewerId, TicketId $ticketId): ?SummaryRequestView
    {
        return self::transactions()->rolledBackAs(self::ACME_FOODS, fn (): ?SummaryRequestView => $this->summaries()->latestRequestOf($ticketId, new UserId($viewerId), new OrganizationId(self::ACME_FOODS)));
    }

    private function stateOf(TicketId $ticketId): ?SummaryRequestView
    {
        return $this->requests()->latestOf(new OrganizationId(self::ACME_FOODS), $ticketId);
    }

    /**
     * @return array{string, string, string, ?string} status, requester, time in UTC, sentence
     */
    private static function fieldsOf(SummaryRequestView $view): array
    {
        return [$view->status, $view->requesterId->toString(), $view->at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'), $view->sentence];
    }

    private function summaries(): Summaries
    {
        $summaries = self::getContainer()->get('test.assist_summaries');
        \assert($summaries instanceof Summaries);

        return $summaries;
    }

    private function summariesWith(SummaryJobQueue $summaryJobQueue): QueuedSummaries
    {
        $summaryRequesters = self::getContainer()->get('test.assist_summary_requesters');
        \assert($summaryRequesters instanceof SummaryRequesters);
        $aiTaskAdmission = self::getContainer()->get('test.assist_ai_task_admission');
        \assert($aiTaskAdmission instanceof AiTaskAdmission);
        $ticketVisibilities = self::getContainer()->get('test.ticketing_ticket_visibilities');
        \assert($ticketVisibilities instanceof TicketVisibilities);
        $ticketQueries = self::getContainer()->get('test.ticketing_ticket_queries');
        \assert($ticketQueries instanceof TicketQueries);

        return new QueuedSummaries($summaryRequesters, $aiTaskAdmission, $this->requests(), $summaryJobQueue, $ticketVisibilities, $ticketQueries);
    }

    private function requests(): SummaryRequests
    {
        $requests = self::getContainer()->get('test.assist_summary_requests');
        \assert($requests instanceof SummaryRequests);

        return $requests;
    }

    private function asyncAi(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async_ai');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function fakeProvider(): FakeProvider
    {
        return self::getContainer()->get(FakeProvider::class);
    }

    private function forgetRequestStates(): void
    {
        $stateValkey = self::getContainer()->get('state_valkey.connection');
        foreach ([self::ACME_FOODS, self::BETA_BAKERY] as $organizationId) {
            $keys = $stateValkey->keys('summary_request:'.$organizationId.':*');
            if (\is_array($keys) && [] !== $keys) {
                $stateValkey->del($keys);
            }
        }
    }

    private static function ticketIdOf(string $ticket): TicketId
    {
        return match ($ticket) {
            'checkout' => self::checkoutTicketId(),
            'team-only' => self::teamOnlyTicketId(),
            'beta' => new TicketId(self::BETA_TICKET),
            default => throw new \LogicException('No ticket is called '.$ticket.'.'),
        };
    }

    private static function checkoutTicketId(): TicketId
    {
        return self::$checkoutTicketId ?? throw new \LogicException('The tickets are made before each class.');
    }

    private static function teamOnlyTicketId(): TicketId
    {
        return self::$teamOnlyTicketId ?? throw new \LogicException('The tickets are made before each class.');
    }

    private static function removeRows(): void
    {
        self::shopTeamRows()->removeAllOf([self::LENA, self::OMAR, self::MIA], self::ACME_FOODS, self::BETA_BAKERY);
    }

    private static function shopTeamRows(): ShopTeamRows
    {
        return new ShopTeamRows(self::migrator(), self::tenantContext());
    }

    private static function transactions(): TenantTransactions
    {
        return new TenantTransactions(self::entityManager(), self::tenantContext());
    }

    private static function connection(): Connection
    {
        return self::entityManager()->getConnection();
    }

    private static function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private static function migrator(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.migrator_connection');
    }

    private static function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }
}
