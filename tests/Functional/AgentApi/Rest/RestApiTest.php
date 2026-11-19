<?php

declare(strict_types=1);

namespace App\Tests\Functional\AgentApi\Rest;

use App\AgentApi\Infrastructure\Mcp\McpRequestGuard;
use App\AgentApi\Infrastructure\Mcp\Tool\AddNotebookScreenshot;
use App\AgentApi\Infrastructure\Mcp\Tool\AddScreenshot;
use App\AgentApi\Infrastructure\Mcp\ToolCallLimiter;
use App\AgentApi\Infrastructure\Mcp\ToolRefusal;
use App\AgentApi\Infrastructure\Rest\RestRequestGuard;
use App\AgentApi\Infrastructure\Rest\RestToolController;
use App\AgentApi\Infrastructure\Rest\StoredAnswer;
use App\Identity\Api\ApiTokenAccess;
use App\Identity\Domain\ApiTokenScope;
use App\Identity\Domain\User;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Functional\Identity\IssuesApiTokens;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Review\SeedsTicketRounds;
use App\Tests\Functional\Web\CreatesAcmeFoodsTeams;
use App\Tests\Functional\Web\CreatesTeams;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * REST /api/v1 end to end (P09.T7): the api_token firewall, RestRequestGuard, the tools behind ToolCallGate and the
 * Idempotency-Key store. Mia has no role on Shop, as anyone may report (ADR-0038). .env.test allows one extension
 * origin. The clock is fixed on 10 Nov 2026, a day aud_event has a partition for and not today, 40 seconds before the
 * clock's next minute.
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
    use SeedsTicketRounds;

    private const string NOW = '2026-11-10 09:00:20 UTC';
    private const string EXTENSION_ORIGIN = 'chrome-extension://pinroundtestextensionidaaaaaaaaa';
    private const string SHA256 = '9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08';
    private const int CALLS_PER_TOKEN_MINUTE = 60;

    private KernelBrowser $client;

    /** @var array<string, User> */
    private array $people;

    private string $shopProject;
    private string $stepId;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['REMOTE_ADDR' => \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254))]);
        $this->client->disableReboot();
        self::getContainer()->set('clock', new MockClock(self::NOW));
        $this->people = $this->createAcmeFoodsPeople(null);
        $this->shopProject = $this->addProject($this->shop, 'SHOP', 'Shop');
    }

    protected function tearDown(): void
    {
        $this->removeSeededRounds();
        foreach ($this->createdOrganizationIds as $organizationId) {
            $this->committedAs(new OrganizationId($organizationId), static function (Connection $migrator) use ($organizationId): int {
                foreach (['aud_event', 'api_idempotency_key', 'rvw_report_draft', 'rvw_notebook', 'med_object', 'med_upload_intent'] as $table) {
                    $migrator->executeStatement(\sprintf('DELETE FROM %s WHERE organization_id = ?', $table), [$organizationId]);
                }
                $migrator->executeStatement("DELETE FROM messenger_outbox WHERE queue_name = 'outbox' AND position(? in body) > 0", [$organizationId]);

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

    /**
     * The extension's sign-out (P10.T8): the token revokes itself and stops working at once; another token of the same
     * person keeps working.
     */
    #[Test]
    public function aTokenRevokesItselfAndTheNextCallIsRefused(): void
    {
        [$tokenId, $token] = $this->issueApiToken($this->people['Mia'], $this->acmeFoods);
        [, $otherToken] = $this->issueApiToken($this->people['Mia'], $this->acmeFoods);

        $this->request($token, 'POST', '/api/v1/me/token/revoke');
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['revoked' => true], $this->json());
        self::assertSame('2026-11-10 09:00:20', $this->usageOf($tokenId, $this->acmeFoods)['revoked_at']);

        $this->request($token, 'GET', '/api/v1/me/tickets');
        self::assertResponseStatusCodeSame(401);
        $this->request($otherToken, 'GET', '/api/v1/me/tickets');
        self::assertResponseStatusCodeSame(200);
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
     * Each carries a live token with reports:write and a report that would make a draft, so only the guard can refuse
     * it; absolute, as the browser resolves a relative path against the host of the request before.
     */
    #[Test]
    public function anUnknownHostOrABodyOverOneMegabyteIsRefusedAndMakesNoDraftAndNoAuditRow(): void
    {
        [$tokenId, $token] = $this->issueApiToken($this->people['Mia'], $this->acmeFoods, scopes: [ApiTokenScope::ReportsWrite]);
        $report = json_encode(['project' => 'SHOP', 'title' => 'Total is doubled'], \JSON_THROW_ON_ERROR);
        // Spaces after the object keep it valid JSON, so the report would be made if the cap let it through.
        $overTheCap = str_pad($report, McpRequestGuard::MAX_BODY_BYTES + 1);
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token];

        $this->client->request('POST', 'http://rebound.example.net/api/v1/reports', server: $server, content: $report);
        self::assertResponseStatusCodeSame(400);
        self::assertSame(['error' => RestRequestGuard::UNKNOWN_HOST], $this->json());

        $this->client->request('POST', 'http://localhost/api/v1/reports', server: $server, content: $overTheCap);
        self::assertFalse($this->client->getRequest()->headers->has('Content-Length'), 'the browser set a Content-Length of its own');
        self::assertResponseStatusCodeSame(413);
        self::assertSame(['error' => RestRequestGuard::BODY_TOO_LARGE], $this->json());

        self::assertSame(0, $this->rowCountOf('rvw_report_draft'));
        self::assertSame(0, $this->rowCountOf('aud_event'));
        self::assertSame(['last_used_at' => null, 'revoked_at' => null], $this->usageOf($tokenId, $this->acmeFoods));
    }

    /**
     * The first 59 calls of the minute are spent on the limiter itself; the 60th, a real one, still runs.
     */
    #[Test]
    public function theSixtyFirstCallOfAMinuteGets429WithRetryAfterAndMakesNoDraftAndNoAuditRow(): void
    {
        [$tokenId, $token] = $this->issueApiToken($this->people['Mia'], $this->acmeFoods, scopes: [ApiTokenScope::Read, ApiTokenScope::ReportsWrite]);
        $toolCallLimiter = self::getContainer()->get(ToolCallLimiter::class);
        $miasToken = new ApiTokenAccess($tokenId, $this->acmeFoods, ['read', 'reports:write'], new \DateTimeImmutable('2026-12-10 09:00:00 UTC'));
        for ($call = 1; $call < self::CALLS_PER_TOKEN_MINUTE; ++$call) {
            $toolCallLimiter->consume($miasToken);
        }
        $this->request($token, 'GET', '/api/v1/me/tickets');
        self::assertResponseStatusCodeSame(200);
        $auditRowsBefore = $this->rowCountOf('aud_event');

        $this->request($token, 'POST', '/api/v1/reports', ['project' => 'SHOP', 'title' => 'Total is doubled']);

        self::assertResponseStatusCodeSame(429);
        self::assertSame(['error' => ToolCallLimiter::TOKEN_TOO_FAST], $this->json());
        self::assertResponseHeaderSame('Retry-After', '40');
        self::assertSame(0, $this->rowCountOf('rvw_report_draft'));
        self::assertSame($auditRowsBefore, $this->rowCountOf('aud_event'));
    }

    #[Test]
    public function aDraftScreenshotGoesIntoMiasDraftRoundAndAnUnknownKeyIsNotFound(): void
    {
        $this->stepId = $this->addTodoStep();
        $ticketId = $this->addTicket('SHOP-1');
        $miasDraft = $this->addRound($ticketId, null, 'draft', 'Mia', null);
        $uploadId = $this->addReadyUpload('Mia', 'round', $miasDraft);
        [$tokenId, $token] = $this->issueApiToken($this->people['Mia'], $this->acmeFoods);

        $this->request($token, 'POST', '/api/v1/tickets/SHOP-404/draft/screenshots', ['upload_id' => $uploadId, 'title' => 'Cart total']);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(['error' => ToolRefusal::NOT_FOUND, 'outcome' => 'not_found'], $this->json());
        self::assertSame([], $this->screenshotRowsOf($miasDraft));

        $this->request($token, 'POST', '/api/v1/tickets/SHOP-1/draft/screenshots', ['upload_id' => $uploadId, 'title' => 'Cart total']);

        self::assertResponseStatusCodeSame(200);
        $screenshots = $this->screenshotRowsOf($miasDraft);
        self::assertCount(1, $screenshots);
        self::assertSame(['screenshot_id' => $screenshots[0]['id'], 'round_id' => $miasDraft, 'status' => AddScreenshot::ADDED, 'pin_count' => 0], $this->json());
        self::assertSame([0, 'Cart total'], [$screenshots[0]['position'], $screenshots[0]['title']]);
        // Both calls share the frozen clock's time, so their rows tie on `at` and their order is not defined.
        self::assertEqualsCanonicalizing([[$tokenId, 'not_found'], [$tokenId, 'ok']], $this->addScreenshotAuditRows());
    }

    /**
     * Lena makes a notebook twice with one Idempotency-Key, begins an upload for it and adds a picture: one notebook,
     * the picture in it, and no ticket.
     */
    #[Test]
    public function theOwnerMakesANotebookOnceForOneIdempotencyKeyUploadsForItAndAddsAPictureWithoutATicket(): void
    {
        [, $token] = $this->issueApiToken($this->people['Lena'], $this->acmeFoods, scopes: [ApiTokenScope::NotebooksWrite]);

        $this->request($token, 'POST', '/api/v1/notebooks', ['title' => 'Coupon questions'], ['HTTP_IDEMPOTENCY_KEY' => 'notebook-1']);
        self::assertResponseStatusCodeSame(201);
        $first = $this->json();
        $notebookId = $first['notebook_id'] ?? null;
        self::assertIsString($notebookId);
        $this->request($token, 'POST', '/api/v1/notebooks', ['title' => 'Coupon questions'], ['HTTP_IDEMPOTENCY_KEY' => 'notebook-1']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame($first, $this->json());
        self::assertSame('true', $this->client->getResponse()->headers->get(RestToolController::REPLAYED_HEADER));
        self::assertSame([[$notebookId, 'Coupon questions', 0]], $this->rowsOf('SELECT id, title, jsonb_array_length(screenshots) FROM rvw_notebook'));

        $this->request($token, 'POST', '/api/v1/notebooks/'.$notebookId.'/uploads', ['mime' => 'image/png', 'bytes' => 245_760, 'sha256' => self::SHA256]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(['upload_id', 'put_url', 'headers', 'expires_at'], array_keys($this->json()));
        self::assertSame([['notebook', $notebookId]], $this->rowsOf('SELECT target_kind, target_id FROM med_upload_intent'));

        $uploadId = $this->addReadyUpload('Lena', 'notebook', $notebookId);
        $this->request($token, 'POST', '/api/v1/notebooks/'.$notebookId.'/screenshots', ['upload_id' => $uploadId, 'title' => 'Cart total']);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['notebook_id' => $notebookId, 'media_id' => $uploadId, 'status' => AddNotebookScreenshot::ADDED, 'screenshot_count' => 1], $this->json());
        self::assertSame([[$notebookId, 'Coupon questions', 1]], $this->rowsOf('SELECT id, title, jsonb_array_length(screenshots) FROM rvw_notebook'));
        self::assertSame(0, $this->rowCountOf('tkt_ticket'));
    }

    /**
     * Omar and Sara hold notebooks:write, so only the owner rule can refuse them; an unknown and a malformed id are
     * 404 too, never 500. Lena's token without notebooks:write is 403 on each route. Nothing changes.
     */
    #[Test]
    public function anotherPersonsNotebookAnUnknownIdAndATokenWithoutNotebooksWriteAreRefusedAndNothingChanges(): void
    {
        [, $lenasToken] = $this->issueApiToken($this->people['Lena'], $this->acmeFoods, scopes: [ApiTokenScope::NotebooksWrite]);
        $this->request($lenasToken, 'POST', '/api/v1/notebooks', ['title' => 'Coupon questions']);
        self::assertResponseStatusCodeSame(201);
        $lenasNotebook = $this->json()['notebook_id'] ?? null;
        self::assertIsString($lenasNotebook);
        $lenasUpload = $this->addReadyUpload('Lena', 'notebook', $lenasNotebook);
        $file = ['mime' => 'image/png', 'bytes' => 245_760, 'sha256' => self::SHA256];
        $notebooksBefore = $this->rowsOf('SELECT id, owner_id, title, screenshots::text, version FROM rvw_notebook');
        $intentsBefore = $this->rowsOf('SELECT id, used_at FROM med_upload_intent ORDER BY id');

        foreach (['Omar', 'Sara'] as $person) {
            [, $token] = $this->issueApiToken($this->people[$person], $this->acmeFoods, scopes: [ApiTokenScope::NotebooksWrite]);
            foreach ([$lenasNotebook, '0197a3c0-0000-7000-8000-0000000b9999', 'not-a-notebook'] as $notebookId) {
                $this->request($token, 'POST', '/api/v1/notebooks/'.$notebookId.'/uploads', $file);
                self::assertResponseStatusCodeSame(404, $person.' '.$notebookId);
                self::assertSame(['error' => ToolRefusal::NOT_FOUND, 'outcome' => 'not_found'], $this->json());
                $this->request($token, 'POST', '/api/v1/notebooks/'.$notebookId.'/screenshots', ['upload_id' => $lenasUpload]);
                self::assertResponseStatusCodeSame(404, $person.' '.$notebookId);
                self::assertSame(['error' => ToolRefusal::NOT_FOUND, 'outcome' => 'not_found'], $this->json());
            }
        }

        [, $readToken] = $this->issueApiToken($this->people['Lena'], $this->acmeFoods, scopes: [ApiTokenScope::Read, ApiTokenScope::RoundsWrite, ApiTokenScope::ReportsWrite]);
        $missingScope = ['error' => \sprintf(ToolRefusal::MISSING_SCOPE, 'notebooks:write'), 'outcome' => 'missing_scope'];
        foreach ([['/api/v1/notebooks', ['title' => 'Another']], ['/api/v1/notebooks/'.$lenasNotebook.'/uploads', $file], ['/api/v1/notebooks/'.$lenasNotebook.'/screenshots', ['upload_id' => $lenasUpload]]] as [$path, $body]) {
            $this->request($readToken, 'POST', $path, $body);
            self::assertResponseStatusCodeSame(403, $path);
            self::assertSame($missingScope, $this->json());
        }

        self::assertSame($notebooksBefore, $this->rowsOf('SELECT id, owner_id, title, screenshots::text, version FROM rvw_notebook'));
        self::assertSame($intentsBefore, $this->rowsOf('SELECT id, used_at FROM med_upload_intent ORDER BY id'));
        self::assertSame(0, $this->rowCountOf('tkt_ticket'));
    }

    private function addTodoStep(): string
    {
        $stepId = Uuid::v7()->toRfc4122();
        $parameters = [$this->acmeFoods->toString(), $stepId];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, 'To do', 'todo', 'todo', 'core', 1)",
            $parameters,
        ));

        return $stepId;
    }

    /**
     * An upload $person began for the target and completed, with the ready picture its completion and processing made
     * under the intent's id, as Media stores them.
     *
     * @return string the intent's id, which is the picture's
     */
    private function addReadyUpload(string $person, string $targetKind, string $targetId): string
    {
        $intentId = Uuid::v7()->toRfc4122();
        $organizationId = $this->acmeFoods->toString();
        $uploader = $this->people[$person]->id()->toString();
        $storageKey = \sprintf('uploads/%s/%s', $organizationId, $intentId);
        $variants = json_encode(['view' => 'media/'.$intentId.'/view.webp', 'thumb' => 'media/'.$intentId.'/thumb.webp'], \JSON_THROW_ON_ERROR);
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($organizationId, $intentId, $uploader, $targetKind, $targetId, $storageKey, $variants): int|string {
            $migrator->executeStatement(
                "INSERT INTO med_upload_intent (organization_id, id, user_id, purpose, target_kind, target_id, max_bytes, mime_allowed, storage_key, expires_at, used_at)
                 VALUES (?, ?, ?, 'screenshot', ?, ?, 245760, '{image/png}', ?, TIMESTAMPTZ '2026-11-11 09:00:00+00', TIMESTAMPTZ '2026-11-10 09:00:05+00')",
                [$organizationId, $intentId, $uploader, $targetKind, $targetId, $storageKey],
            );

            return $migrator->executeStatement(
                "INSERT INTO med_object (organization_id, id, storage_key, mime, bytes, uploaded_by, created_at, status, reject_reason, sha256, width, height, variants)
                 VALUES (?, ?, ?, 'image/png', 2048, ?, TIMESTAMPTZ '2026-11-10 09:00:05+00', 'ready', NULL, ?, 1280, 800, CAST(? AS jsonb))",
                [$organizationId, $intentId, $storageKey, $uploader, hash('sha256', $intentId), $variants],
            );
        });

        return $intentId;
    }

    /**
     * @return list<list<mixed>> each add_screenshot call's token and outcome, oldest first
     */
    private function addScreenshotAuditRows(): array
    {
        return array_map(static function (array $row): array {
            self::assertIsString($row[1]);
            $data = json_decode($row[1], true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($data);

            return [$row[0], $data['outcome'] ?? null];
        }, $this->rowsOf(\sprintf("SELECT actor_token_id, data::text FROM aud_event WHERE data->>'tool' = '%s' ORDER BY at, id", AddScreenshot::NAME)));
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
