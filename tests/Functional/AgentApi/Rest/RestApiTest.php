<?php

declare(strict_types=1);

namespace App\Tests\Functional\AgentApi\Rest;

use App\AgentApi\Infrastructure\Mcp\ToolRefusal;
use App\AgentApi\Infrastructure\Rest\RestRequestGuard;
use App\AgentApi\Infrastructure\Rest\RestToolController;
use App\AgentApi\Infrastructure\Rest\StoredAnswer;
use App\Identity\Domain\ApiTokenScope;
use App\Identity\Domain\User;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Functional\Identity\IssuesApiTokens;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Web\CreatesAcmeFoodsTeams;
use App\Tests\Functional\Web\CreatesTeams;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Zenstruck\Foundry\Test\Factories;

/**
 * REST /api/v1 end to end (P09.T7): the api_token firewall, RestRequestGuard, the tools behind ToolCallGate and the
 * Idempotency-Key store. Mia has no role on Shop, as anyone may report (ADR-0038). .env.test allows one extension
 * origin. The clock is fixed on 10 Nov 2026, a day aud_event has a partition for and not today.
 */
final class RestApiTest extends WebTestCase
{
    use CreatesAcmeFoodsTeams;
    use CreatesTeams;
    use Factories;
    use IssuesApiTokens;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }

    private const string NOW = '2026-11-10 09:00:20 UTC';
    private const string EXTENSION_ORIGIN = 'chrome-extension://pinroundtestextensionidaaaaaaaaa';
    private const string SHA256 = '9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08';

    private KernelBrowser $client;

    /** @var array<string, User> */
    private array $people;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['REMOTE_ADDR' => \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254))]);
        $this->client->disableReboot();
        self::getContainer()->set('clock', new MockClock(self::NOW));
        $this->people = $this->createAcmeFoodsPeople(null);
        $this->addProject($this->shop, 'SHOP', 'Shop');
    }

    protected function tearDown(): void
    {
        foreach ($this->createdOrganizationIds as $organizationId) {
            $this->committedAs(new OrganizationId($organizationId), static function (Connection $migrator) use ($organizationId): int {
                foreach (['aud_event', 'api_idempotency_key', 'rvw_report_draft', 'med_object', 'med_upload_intent'] as $table) {
                    $migrator->executeStatement(\sprintf('DELETE FROM %s WHERE organization_id = ?', $table), [$organizationId]);
                }

                return 0;
            });
        }
        $this->removeApiTokens();
        $this->removeCommittedProjects();
        $this->removeUsersAndOrganizations();
    }

    #[Test]
    public function myTicketsNeedsALiveTokenAndAnswersTheToolsList(): void
    {
        $this->client->request('GET', '/api/v1/me/tickets');
        self::assertResponseStatusCodeSame(401);

        [, $token] = $this->issueApiToken($this->people['Mia'], $this->acmeFoods);
        $this->request($token, 'GET', '/api/v1/me/tickets');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['tickets' => [], 'has_more' => false], array_intersect_key($this->json(), ['tickets' => true, 'has_more' => true]));
    }

    #[Test]
    public function aReportWithoutReportsWriteIsForbiddenAndWithItMakesADraftAndNoTicket(): void
    {
        [, $claudeCodeToken] = $this->issueApiToken($this->people['Mia'], $this->acmeFoods);
        $this->request($claudeCodeToken, 'POST', '/api/v1/reports', ['project' => 'SHOP', 'title' => 'Total is doubled']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(['error' => \sprintf(ToolRefusal::MISSING_SCOPE, 'reports:write'), 'outcome' => 'missing_scope'], $this->json());
        self::assertSame(0, $this->rowCountOf('rvw_report_draft'));

        [, $token] = $this->issueApiToken($this->people['Mia'], $this->acmeFoods, scopes: [ApiTokenScope::ReportsWrite]);
        $this->request($token, 'POST', '/api/v1/reports', ['project' => 'SHOP', 'title' => 'Total is doubled']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(['draft', 0], [$this->json()['status'] ?? null, $this->json()['screenshot_count'] ?? null]);
        self::assertSame(1, $this->rowCountOf('rvw_report_draft'));
        self::assertSame(0, $this->rowCountOf('tkt_ticket'));

        $this->request($token, 'POST', '/api/v1/reports', ['project' => 'NOPE', 'title' => 'Total is doubled']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('not_found', $this->json()['outcome'] ?? null);
    }

    #[Test]
    public function theSameIdempotencyKeyReplaysTheFirstAnswerAndIsRefusedForAnotherRequest(): void
    {
        [, $token] = $this->issueApiToken($this->people['Mia'], $this->acmeFoods, scopes: [ApiTokenScope::ReportsWrite]);
        $report = ['project' => 'SHOP', 'title' => 'Total is doubled'];

        $this->request($token, 'POST', '/api/v1/reports', $report, ['HTTP_IDEMPOTENCY_KEY' => 'report-1']);
        self::assertResponseStatusCodeSame(201);
        $first = $this->json();
        self::assertNull($this->client->getResponse()->headers->get(RestToolController::REPLAYED_HEADER));

        $this->request($token, 'POST', '/api/v1/reports', $report, ['HTTP_IDEMPOTENCY_KEY' => 'report-1']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame($first, $this->json());
        self::assertSame('true', $this->client->getResponse()->headers->get(RestToolController::REPLAYED_HEADER));
        self::assertSame(1, $this->rowCountOf('rvw_report_draft'));

        $this->request($token, 'POST', '/api/v1/reports', [...$report, 'title' => 'Another report'], ['HTTP_IDEMPOTENCY_KEY' => 'report-1']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => StoredAnswer::STORED_FOR_ANOTHER_REQUEST], $this->json());
        self::assertSame(1, $this->rowCountOf('rvw_report_draft'));

        $this->request($token, 'POST', '/api/v1/reports', $report, ['HTTP_IDEMPOTENCY_KEY' => "report-2\n"]);
        self::assertResponseStatusCodeSame(400);
        self::assertSame(['error' => RestToolController::INVALID_IDEMPOTENCY_KEY], $this->json());
        self::assertSame(1, $this->rowCountOf('rvw_report_draft'));
    }

    #[Test]
    public function anUploadNamesExactlyOneTargetAndAProjectBeginsAReportUpload(): void
    {
        [, $token] = $this->issueApiToken($this->people['Mia'], $this->acmeFoods, scopes: [ApiTokenScope::ReportsWrite, ApiTokenScope::RoundsWrite]);
        $file = ['mime' => 'image/png', 'bytes' => 245_760, 'sha256' => self::SHA256];

        $this->request($token, 'POST', '/api/v1/uploads', [...$file, 'ticket' => 'SHOP-1', 'project' => 'SHOP']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => RestToolController::UPLOAD_NEEDS_ONE_TARGET], $this->json());

        $this->request($token, 'POST', '/api/v1/uploads', [...$file, 'project' => 'SHOP']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(['upload_id', 'put_url', 'headers', 'expires_at'], array_keys($this->json()));
        self::assertSame([['new_report', 'SHOP']], $this->rowsOf('SELECT target_kind, target_id FROM med_upload_intent'));
    }

    #[Test]
    public function onlyTheExtensionsOriginGetsCorsAndAnyOtherBrowserOriginIsRefused(): void
    {
        [, $token] = $this->issueApiToken($this->people['Mia'], $this->acmeFoods);

        $this->client->request('OPTIONS', '/api/v1/reports', server: ['HTTP_ORIGIN' => self::EXTENSION_ORIGIN, 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST']);
        self::assertResponseStatusCodeSame(204);
        $headers = $this->client->getResponse()->headers;
        self::assertSame([self::EXTENSION_ORIGIN, 'GET, POST', RestRequestGuard::ALLOWED_HEADERS], [$headers->get('Access-Control-Allow-Origin'), $headers->get('Access-Control-Allow-Methods'), $headers->get('Access-Control-Allow-Headers')]);

        $this->request($token, 'GET', '/api/v1/me/tickets', server: ['HTTP_ORIGIN' => self::EXTENSION_ORIGIN]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(self::EXTENSION_ORIGIN, $this->client->getResponse()->headers->get('Access-Control-Allow-Origin'));

        foreach (['https://evil.example', 'chrome-extension://anotherextensionidaaaaaaaaaaaaa', 'null'] as $origin) {
            $this->request($token, 'GET', '/api/v1/me/tickets', server: ['HTTP_ORIGIN' => $origin]);
            self::assertResponseStatusCodeSame(403, $origin);
            self::assertSame(['error' => RestRequestGuard::FOREIGN_ORIGIN], $this->json());
            self::assertNull($this->client->getResponse()->headers->get('Access-Control-Allow-Origin'), $origin);
            $this->client->request('OPTIONS', '/api/v1/reports', server: ['HTTP_ORIGIN' => $origin]);
            self::assertResponseStatusCodeSame(403, $origin);
        }
    }

    #[Test]
    public function aBodyThatIsNoJsonObjectIsABadRequestAndRunsNothing(): void
    {
        [, $token] = $this->issueApiToken($this->people['Mia'], $this->acmeFoods, scopes: [ApiTokenScope::ReportsWrite]);

        foreach (['not json', '[1, 2]', '"text"'] as $body) {
            $this->client->request('POST', '/api/v1/reports', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token], content: $body);
            self::assertResponseStatusCodeSame(400, $body);
            self::assertSame(['error' => RestToolController::INVALID_BODY], $this->json());
        }
        self::assertSame(0, $this->rowCountOf('rvw_report_draft'));
        self::assertSame(0, $this->rowCountOf('aud_event'));
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string>     $server
     */
    private function request(string $token, string $method, string $path, ?array $body = null, array $server = []): void
    {
        $this->client->request($method, $path, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            ...$server,
        ], content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<mixed>
     */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function rowCountOf(string $table): int
    {
        $count = $this->rowsOf(\sprintf('SELECT count(*) FROM %s', $table))[0][0] ?? null;
        self::assertIsInt($count);

        return $count;
    }

    /**
     * @return list<list<mixed>>
     */
    private function rowsOf(string $sql): array
    {
        $rows = [];
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($sql, &$rows): int {
            $rows = $migrator->fetchAllNumeric($sql);

            return 0;
        });

        return $rows;
    }
}
