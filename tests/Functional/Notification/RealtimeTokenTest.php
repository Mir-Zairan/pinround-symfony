<?php

declare(strict_types=1);

namespace App\Tests\Functional\Notification;

use App\Identity\Domain\User;
use App\Notification\Infrastructure\Mercure\RealtimeTopics;
use App\Notification\UI\Controller\RealtimeTokenController;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TicketId;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Web\CreatesAcmeFoodsTeams;
use App\Tests\Functional\Web\CreatesTeams;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * POST /realtime/token through the real firewall, voters, database and Mercure token factory. The clock is fixed on
 * 6 Nov 2026, before today: a clock ahead of the login would end the session as idle, and a token stamped from the wall
 * clock would not match.
 */
final class RealtimeTokenTest extends WebTestCase
{
    use CreatesAcmeFoodsTeams;
    use CreatesTeams;
    use Factories;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }

    private const string URL = '/realtime/token';
    private const string NOW = '2026-11-06 12:00:00 UTC';
    private const string COOKIE = '__Secure-mercure_access_token';
    /** The title and key a token must never carry. */
    private const string SHOP_TITLE = 'Price rules for staff';

    private KernelBrowser $client;

    /** @var array<string, User> */
    private array $people;

    private string $shopTicketId;

    private string $mobileTeamOnlyTicketId;

    private string $betaBakeryTicketId;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTPS' => 'on']);
        $this->client->disableReboot();
        self::getContainer()->set('clock', new MockClock(new \DateTimeImmutable(self::NOW)));
        $this->people = $this->createAcmeFoodsPeople();
        $acmeStep = $this->addStep($this->acmeFoods);
        $this->shopTicketId = $this->addTicket($this->acmeFoods, $this->addProject($this->shop, 'SHOP', 'Shop'), $this->shop, $acmeStep, 'SHOP-1', 'all');
        $this->mobileTeamOnlyTicketId = $this->addTicket($this->acmeFoods, $this->addProject($this->mobile, 'APP', 'App'), $this->mobile, $acmeStep, 'APP-1', 'team');
        $betaBakery = $this->createOrganization('Beta Bakery');
        $this->betaBakeryTicketId = $this->addTicket($betaBakery, $this->addProject(null, 'SHOP', 'Shop', $betaBakery), null, $this->addStep($betaBakery), 'SHOP-1', 'all');
    }

    protected function tearDown(): void
    {
        foreach ($this->createdOrganizationIds as $organizationId) {
            $this->committedAs(new OrganizationId($organizationId), static function (Connection $migrator) use ($organizationId): int {
                foreach (['tkt_ticket', 'wf_step'] as $table) {
                    $migrator->executeStatement(\sprintf('DELETE FROM %s WHERE organization_id = ?', $table), [$organizationId]);
                }

                return 0;
            });
        }
        $this->removeCommittedProjects();
        $this->removeUsersAndOrganizations();
    }

    #[Test]
    public function aMemberGetsACookieForTheirOwnTopicAndTheTicketTheyMaySee(): void
    {
        $this->logIn($this->client, $this->people['Omar']);

        $this->client->request('POST', self::URL, [RealtimeTokenController::TICKET_IDS => [$this->shopTicketId]]);

        self::assertResponseStatusCodeSame(204);
        $cookie = $this->subscriberCookie() ?? self::fail('No subscriber cookie was set.');
        self::assertSame([$this->userTopicOf('Omar'), $this->ticketTopicOf($this->shopTicketId)], $this->topicsOf($cookie));
        self::assertStringNotContainsString(self::SHOP_TITLE, $this->payloadJsonOf($cookie));
        self::assertStringNotContainsString('SHOP-1', $this->payloadJsonOf($cookie));
        self::assertSame(['/.well-known/mercure', true, true, Cookie::SAMESITE_STRICT], [$cookie->getPath(), $cookie->isSecure(), $cookie->isHttpOnly(), $cookie->getSameSite()]);
    }

    #[Test]
    public function theTokenIsIssuedByTheAppClockAndRunsOutTenMinutesLater(): void
    {
        $this->logIn($this->client, $this->people['Omar']);

        $this->client->request('POST', self::URL);

        self::assertResponseStatusCodeSame(204);
        $cookie = $this->subscriberCookie() ?? self::fail('No subscriber cookie was set.');
        $issuedAt = new \DateTimeImmutable(self::NOW)->getTimestamp();
        $claims = $this->claimsOf($cookie);
        self::assertSame([$issuedAt, $issuedAt + 600], [$claims['iat'] ?? null, $claims['exp'] ?? null]);
        self::assertSame($issuedAt + 600, $cookie->getExpiresTime());
        self::assertSame([$this->userTopicOf('Omar')], $this->topicsOf($cookie));
    }

    #[Test]
    public function anotherOrganizationsHiddenUnknownAndMalformedTicketsAreLeftOut(): void
    {
        $this->logIn($this->client, $this->people['Omar']);

        $this->client->request('POST', self::URL, [RealtimeTokenController::TICKET_IDS => [
            $this->betaBakeryTicketId,
            $this->mobileTeamOnlyTicketId,
            Uuid::v7()->toRfc4122(),
            'not-a-ticket-id',
            ['nested' => $this->shopTicketId],
            strtoupper($this->shopTicketId),
            $this->shopTicketId,
        ]]);

        self::assertResponseStatusCodeSame(204);
        $cookie = $this->subscriberCookie() ?? self::fail('No subscriber cookie was set.');
        self::assertSame([$this->userTopicOf('Omar'), $this->ticketTopicOf($this->shopTicketId)], $this->topicsOf($cookie));
    }

    #[Test]
    public function theLeadOfTheTeamGetsItsTeamOnlyTicket(): void
    {
        $this->logIn($this->client, $this->people['Lena']);

        $this->client->request('POST', self::URL, [RealtimeTokenController::TICKET_IDS => [$this->mobileTeamOnlyTicketId, $this->betaBakeryTicketId]]);

        self::assertResponseStatusCodeSame(204);
        $cookie = $this->subscriberCookie() ?? self::fail('No subscriber cookie was set.');
        self::assertSame([$this->userTopicOf('Lena'), $this->ticketTopicOf($this->mobileTeamOnlyTicketId)], $this->topicsOf($cookie));
    }

    #[Test]
    public function moreTicketsThanTheCapAreRefusedWithNoCookie(): void
    {
        $this->logIn($this->client, $this->people['Omar']);
        $ticketIds = array_fill(0, RealtimeTokenController::MAX_TICKETS, $this->shopTicketId);

        $this->client->request('POST', self::URL, [RealtimeTokenController::TICKET_IDS => [...$ticketIds, $this->shopTicketId]]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('Ask for live updates of at most 10 tickets at a time.', $this->client->getResponse()->getContent());
        self::assertNull($this->subscriberCookie());
    }

    #[Test]
    public function asManyTicketsAsTheCapAreAccepted(): void
    {
        $this->logIn($this->client, $this->people['Omar']);

        $this->client->request('POST', self::URL, [RealtimeTokenController::TICKET_IDS => array_fill(0, RealtimeTokenController::MAX_TICKETS, $this->shopTicketId)]);

        self::assertResponseStatusCodeSame(204);
        $cookie = $this->subscriberCookie() ?? self::fail('No subscriber cookie was set.');
        self::assertSame([$this->userTopicOf('Omar'), $this->ticketTopicOf($this->shopTicketId)], $this->topicsOf($cookie));
    }

    #[Test]
    public function ticketIdsThatAreNoListAreABadRequestWithNoCookie(): void
    {
        $this->logIn($this->client, $this->people['Omar']);

        $this->client->request('POST', self::URL, [RealtimeTokenController::TICKET_IDS => $this->shopTicketId]);

        self::assertResponseStatusCodeSame(400);
        self::assertNull($this->subscriberCookie());
    }

    #[Test]
    public function aLoggedOutVisitorIsSentToLogInWithNoCookie(): void
    {
        $this->client->request('POST', self::URL, [RealtimeTokenController::TICKET_IDS => [$this->shopTicketId]]);

        self::assertResponseRedirects('/login', 302);
        self::assertNull($this->subscriberCookie());
    }

    #[Test]
    public function aMemberRemovedFromTheOrganizationGetsNoCookie(): void
    {
        $this->logIn($this->client, $this->people['Omar']);
        $this->client->request('POST', self::URL);
        self::assertResponseStatusCodeSame(204);

        $this->removeMembership($this->people['Omar'], $this->acmeFoods);
        $this->client->request('POST', self::URL, [RealtimeTokenController::TICKET_IDS => [$this->shopTicketId]]);

        self::assertResponseRedirects('/org/choose', 302);
        self::assertNull($this->subscriberCookie());
    }

    private function subscriberCookie(): ?Cookie
    {
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            if (self::COOKIE === $cookie->getName()) {
                return $cookie;
            }
        }

        return null;
    }

    private function payloadJsonOf(Cookie $cookie): string
    {
        $parts = explode('.', (string) $cookie->getValue());
        self::assertCount(3, $parts);

        return (string) base64_decode(strtr($parts[1], '-_', '+/'), true);
    }

    /**
     * @return array<mixed>
     */
    private function claimsOf(Cookie $cookie): array
    {
        $claims = json_decode($this->payloadJsonOf($cookie), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($claims);

        return $claims;
    }

    /**
     * @return list<string> the subscribe topics, in the order the token lists them
     */
    private function topicsOf(Cookie $cookie): array
    {
        $details = $this->claimsOf($cookie)['authorization_details'] ?? null;
        self::assertIsArray($details);
        self::assertCount(1, $details);
        $detail = $details[0];
        self::assertIsArray($detail);
        self::assertSame(['subscribe'], $detail['actions'] ?? null);
        $topics = $detail['topics'] ?? null;
        self::assertIsArray($topics);

        return array_values(array_map(static fn (mixed $topic): string => \is_array($topic) && \is_string($topic['match'] ?? null) ? $topic['match'] : '', $topics));
    }

    private function userTopicOf(string $person): string
    {
        return RealtimeTopics::ofUser($this->acmeFoods, $this->people[$person]->id());
    }

    private function ticketTopicOf(string $ticketId): string
    {
        return RealtimeTopics::ofTicket($this->acmeFoods, new TicketId($ticketId));
    }

    private function addStep(OrganizationId $organizationId): string
    {
        $stepId = Uuid::v7()->toRfc4122();
        $this->committedAs($organizationId, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, 'To do', 'todo', 'todo', 'core', 0)",
            [$organizationId->toString(), $stepId],
        ));

        return $stepId;
    }

    /**
     * A major bug reported by Lena, titled SHOP_TITLE.
     *
     * @param 'all'|'team' $visibility
     *
     * @return string the ticket's id
     */
    private function addTicket(OrganizationId $organizationId, string $projectId, ?TeamId $teamId, string $stepId, string $key, string $visibility): string
    {
        $ticketId = Uuid::v7()->toRfc4122();
        $this->committedAs($organizationId, fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO tkt_ticket (organization_id, id, project_id, team_id, number, key, title, description, type, release, priority,
                 priority_rank, step_id, reporter_id, visibility, assignee_id, parent_id, components, check_reasons, created_at, updated_at)
             VALUES (?, ?, ?, ?, 1, ?, ?, 'Steps are in the title.', 'bug', NULL, 'major', 2, ?, ?, ?, NULL, NULL, '{}', '{}',
                 '2026-11-04 23:30:00+00', '2026-11-05 09:00:00+00')",
            [$organizationId->toString(), $ticketId, $projectId, $teamId?->toString(), $key, self::SHOP_TITLE, $stepId, $this->people['Lena']->id()->toString(), $visibility],
        ));

        return $ticketId;
    }
}
