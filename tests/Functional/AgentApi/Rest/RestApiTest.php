<?php

declare(strict_types=1);

namespace App\Tests\Functional\AgentApi\Rest;

use App\AgentApi\Infrastructure\Mcp\McpRequestGuard;
use App\AgentApi\Infrastructure\Mcp\Tool\AddNotebookScreenshot;
use App\AgentApi\Infrastructure\Mcp\Tool\AddReportScreenshot;
use App\AgentApi\Infrastructure\Mcp\Tool\AddScreenshot;
use App\AgentApi\Infrastructure\Mcp\Tool\CreateNotebookUpload;
use App\AgentApi\Infrastructure\Mcp\Tool\GetNotebook;
use App\AgentApi\Infrastructure\Mcp\Tool\ListDraftScreenshots;
use App\AgentApi\Infrastructure\Mcp\Tool\ListMyNotebooks;
use App\AgentApi\Infrastructure\Mcp\Tool\ListMyReportDrafts;
use App\AgentApi\Infrastructure\Mcp\ToolCallAuditor;
use App\AgentApi\Infrastructure\Mcp\ToolCallLimiter;
use App\AgentApi\Infrastructure\Mcp\ToolRefusal;
use App\AgentApi\Infrastructure\Mcp\UntrustedText;
use App\AgentApi\Infrastructure\Rest\RestRequestGuard;
use App\AgentApi\Infrastructure\Rest\RestToolController;
use App\AgentApi\Infrastructure\Rest\StoredAnswer;
use App\Identity\Api\ApiTokenAccess;
use App\Identity\Domain\ApiTokenScope;
use App\Identity\Domain\User;
use App\Media\Api\MediaImageStatus;
use App\Media\Application\SignedVariantUrls;
use App\Review\Api\Notebooks;
use App\Review\Api\ReportDraftRefused;
use App\Review\Api\ReportDrafts;
use App\Review\Domain\ScreenshotTriesFull;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Functional\AgentApi\Tool\CallsNotebookTools;
use App\Tests\Functional\AgentApi\Tool\SeedsRetakeTargets;
use App\Tests\Functional\Identity\IssuesApiTokens;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Review\RecordingSignedUrls;
use App\Tests\Functional\Review\SeedsTicketRounds;
use App\Tests\Functional\Web\CreatesAcmeFoodsTeams;
use App\Tests\Functional\Web\CreatesTeams;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
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
    use CallsNotebookTools;
    use CreatesAcmeFoodsTeams;
    use CreatesTeams;
    use Factories;
    use IssuesApiTokens;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }
    use SeedsRetakeTargets;
    use SeedsTicketRounds;

    private const string NOW = '2026-11-10 09:00:20 UTC';
    private const string EXTENSION_ORIGIN = 'chrome-extension://pinroundtestextensionidaaaaaaaaa';
    private const string SHA256 = '9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08';
    private const int CALLS_PER_TOKEN_MINUTE = 60;
    private const string LENAS_NEWER_NOTEBOOK = '0197a3c0-0000-7000-8000-0000000b0001';
    private const string LENAS_OLDER_NOTEBOOK = '0197a3c0-0000-7000-8000-0000000b0002';
    private const string OMARS_NOTEBOOK = '0197a3c0-0000-7000-8000-0000000c0001';
    private const string LENAS_NOTEBOOK_IN_BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000f0001';
    /** With the notebook's number. */
    private const string LENAS_PAGED_NOTEBOOK = '0197a3c0-0000-7000-8000-0000000a%04d';
    private const string READY_MEDIA = '0197a3c0-0000-7000-8000-00000000d001';
    private const string LENAS_NEWER_REPORT_DRAFT = '0197a3c0-0000-7000-8000-0000000d0001';
    private const string LENAS_OLDER_REPORT_DRAFT = '0197a3c0-0000-7000-8000-0000000d0002';
    private const string OMARS_REPORT_DRAFT = '0197a3c0-0000-7000-8000-0000000d0003';
    private const string LENAS_REPORT_DRAFT_IN_BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000d0004';
    /** With the draft's number. */
    private const string LENAS_PAGED_REPORT_DRAFT = '0197a3c0-0000-7000-8000-0000000e%04d';
    /** The input schema's problems, as ToolCallGate names them. */
    private const string EMPTY_CURSOR_PROBLEM = '/cursor: String must be at least 1 characters long.';
    private const string LONG_CURSOR_PROBLEM = '/cursor: String must not be longer than 100 characters.';
    private const string LIST_CURSOR_PROBLEM = '/cursor: Invalid type. Expected `string`, but received `array`.';
    /** Acme Foods' rounds, screenshots with their chain, pins and pictures, as stored. */
    private const string ROUND_STATE_QUERY = "SELECT 'screenshot', id::text, round_id::text || ' ' || position || ' ' || coalesce(retake_of::text, '-') FROM rvw_screenshot"
        ." UNION ALL SELECT 'round', id::text, state || ' v' || version FROM rvw_round"
        ." UNION ALL SELECT 'pin', id::text, screenshot_id::text FROM rvw_pin"
        ." UNION ALL SELECT 'media', id::text, status FROM med_object ORDER BY 1, 2";

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

    /**
     * The extension learns a token cannot make reports from this refusal alone, so it must come before any upload
     * intent: the token holds every other write scope the popup uses.
     */
    #[Test]
    public function aReportUploadWithoutReportsWriteIsForbiddenAndMakesNoUploadIntent(): void
    {
        $upload = ['project' => 'SHOP', 'mime' => 'image/png', 'bytes' => 245_760, 'sha256' => self::SHA256];
        [, $withoutReportsWrite] = $this->issueApiToken($this->people['Mia'], $this->acmeFoods, scopes: [ApiTokenScope::Read, ApiTokenScope::RoundsWrite, ApiTokenScope::NotebooksWrite]);

        $this->request($withoutReportsWrite, 'POST', '/api/v1/uploads', $upload);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(['error' => \sprintf(ToolRefusal::MISSING_SCOPE, 'reports:write'), 'outcome' => 'missing_scope'], $this->json());
        self::assertSame(0, $this->rowCountOf('med_upload_intent'));

        [, $withReportsWrite] = $this->issueApiToken($this->people['Mia'], $this->acmeFoods, scopes: [ApiTokenScope::Read, ApiTokenScope::ReportsWrite]);
        $this->request($withReportsWrite, 'POST', '/api/v1/uploads', $upload);

        self::assertResponseStatusCodeSame(201);
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
        self::assertEqualsCanonicalizing([[$tokenId, 'not_found'], [$tokenId, 'ok']], $this->toolAuditRowsOf(AddScreenshot::NAME));
    }

    /**
     * @return iterable<string, array{'mias draft'|'shared round'|'beta bakery'|'unknown'|'malformed'|'trailing newline'}>
     */
    public static function retakesOfNoScreenshotOfTheDraft(): iterable
    {
        yield 'a screenshot of Mia\'s draft' => ['mias draft'];
        yield 'a screenshot of Omar\'s shared round' => ['shared round'];
        yield 'a screenshot of Omar\'s draft in Beta Bakery' => ['beta bakery'];
        yield 'an unknown id' => ['unknown'];
        yield 'a malformed id' => ['malformed'];
        yield 'the first try\'s id with a trailing newline' => ['trailing newline'];
    }

    /**
     * add_screenshot's not found, as for an unknown id: the answer never tells another person's draft, another round
     * or another organization apart from it, and the picture stays out of every round.
     *
     * @param 'mias draft'|'shared round'|'beta bakery'|'unknown'|'malformed'|'trailing newline' $retaken
     */
    #[Test]
    #[DataProvider('retakesOfNoScreenshotOfTheDraft')]
    public function aDraftScreenshotRetakingNoScreenshotOfTheCallersDraftIsNotFoundAndNothingChanges(string $retaken): void
    {
        $this->stepId = $this->addTodoStep();
        $ticketId = $this->addTicket('SHOP-1');
        $omarsDraft = $this->addRound($ticketId, null, 'draft', 'Omar', null);
        $omarsFirstShot = $this->addScreenshot($omarsDraft, $this->addMedia('ready', 'Omar'), 0, 'Before', null);
        $retakeOf = match ($retaken) {
            'mias draft' => $this->addScreenshot($this->addRound($ticketId, null, 'draft', 'Mia', null), $this->addMedia('ready', 'Mia'), 0, 'Hers', null),
            'shared round' => $this->addScreenshot($this->addRound($ticketId, 1, 'draft', 'Omar', '2026-11-05 09:00:00+00'), $this->addMedia('ready', 'Omar'), 0, 'Shared', null),
            'beta bakery' => $this->addOtherOrganizationsDraftScreenshot($this->createOrganization('Beta Bakery'), 'Omar'),
            'unknown' => Uuid::v7()->toRfc4122(),
            'malformed' => 'not-a-screenshot-id',
            'trailing newline' => $omarsFirstShot."\n",
        };
        $uploadId = $this->addReadyUpload('Omar', 'round', $omarsDraft);
        [$tokenId, $token] = $this->issueApiToken($this->people['Omar'], $this->acmeFoods);
        $stateBefore = $this->rowsOf(self::ROUND_STATE_QUERY);
        self::assertContains(['screenshot', $omarsFirstShot, $omarsDraft.' 0 -'], $stateBefore);

        $this->request($token, 'POST', '/api/v1/tickets/SHOP-1/draft/screenshots', ['upload_id' => $uploadId, 'title' => 'Cart total', 'retake_of' => $retakeOf]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(['error' => ToolRefusal::NOT_FOUND, 'outcome' => 'not_found'], $this->json());
        self::assertSame($stateBefore, $this->rowsOf(self::ROUND_STATE_QUERY));
        self::assertSame([[$tokenId, 'not_found']], $this->toolAuditRowsOf(AddScreenshot::NAME));
    }

    /**
     * Four tries are seeded: a retake of the middle one is the fifth, with retake_of the chain's first try, and a sixth
     * is add_screenshot's plain refusal with the cap's reason.
     */
    #[Test]
    public function aFifthTryIsAddedAndASixthIsRefusedWith422AndNothingChanges(): void
    {
        $this->stepId = $this->addTodoStep();
        $omarsDraft = $this->addRound($this->addTicket('SHOP-1'), null, 'draft', 'Omar', null);
        $firstTry = $this->addScreenshot($omarsDraft, $this->addMedia('ready', 'Omar'), 0, 'Before', null);
        $this->addRetake($omarsDraft, $firstTry, 1, 'Omar');
        $thirdTry = $this->addRetake($omarsDraft, $firstTry, 2, 'Omar');
        $this->addRetake($omarsDraft, $firstTry, 3, 'Omar');
        [$tokenId, $token] = $this->issueApiToken($this->people['Omar'], $this->acmeFoods);

        $this->request($token, 'POST', '/api/v1/tickets/SHOP-1/draft/screenshots', ['upload_id' => $this->addReadyUpload('Omar', 'round', $omarsDraft), 'retake_of' => $thirdTry]);

        self::assertResponseStatusCodeSame(200);
        $fifthTry = $this->json()['screenshot_id'] ?? null;
        self::assertIsString($fifthTry);
        self::assertSame([$fifthTry, 4, $firstTry], $this->rowsOf(\sprintf("SELECT id, position, retake_of FROM rvw_screenshot WHERE round_id = '%s' ORDER BY position DESC LIMIT 1", $omarsDraft))[0] ?? null);
        $sixthUpload = $this->addReadyUpload('Omar', 'round', $omarsDraft);
        $stateBefore = $this->rowsOf(self::ROUND_STATE_QUERY);
        self::assertCount(5, array_filter($stateBefore, static fn (array $row): bool => 'screenshot' === $row[0]));

        $this->request($token, 'POST', '/api/v1/tickets/SHOP-1/draft/screenshots', ['upload_id' => $sixthUpload, 'retake_of' => $firstTry]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => ScreenshotTriesFull::REASON, 'outcome' => 'refused'], $this->json());
        self::assertSame($stateBefore, $this->rowsOf(self::ROUND_STATE_QUERY));
        self::assertEqualsCanonicalizing([[$tokenId, 'ok'], [$tokenId, 'refused']], $this->toolAuditRowsOf(AddScreenshot::NAME));
    }

    /**
     * Acme Foods' SHOP-2 has Omar's draft, with a retake of its second screenshot, and Mia's; Beta Bakery's SHOP-1, a key
     * Acme Foods has no ticket with, has Omar's draft there. Each person reads only their own draft; the other
     * organization's ticket and an unknown key are 404 with the tool's not_found row, and no token is 401 with none.
     */
    #[Test]
    public function theDraftScreenshotsListTheCallersOwnDraftAndAnotherOrganizationsTicketIsNotFound(): void
    {
        $this->stepId = $this->addTodoStep();
        $ticketId = $this->addTicket('SHOP-2', number: 2);
        $omarsDraft = $this->addRound($ticketId, null, 'draft', 'Omar', null);
        $cartShot = $this->addScreenshot($omarsDraft, $this->addMedia('ready', 'Omar'), 1, 'Cart total', null);
        $couponShot = $this->addScreenshot($omarsDraft, $this->addMedia('ready', 'Omar'), 0, 'Coupon', null);
        $cartRetake = $this->addRetake($omarsDraft, $cartShot, 2, 'Omar', 'Cart total <again>');
        $miasShot = $this->addScreenshot($this->addRound($ticketId, null, 'draft', 'Mia', null), $this->addMedia('ready', 'Mia'), 0, 'Mia private title', null);
        $betaBakery = $this->createOrganization('Beta Bakery');
        $this->addMembership($this->people['Omar'], $betaBakery);
        $betaBakeryShot = $this->addOtherOrganizationsDraftScreenshot($betaBakery, 'Omar');
        [$omarsTokenId, $omarsToken] = $this->issueApiToken($this->people['Omar'], $this->acmeFoods);
        [$miasTokenId, $miasToken] = $this->issueApiToken($this->people['Mia'], $this->acmeFoods);
        $notFound = ['error' => ToolRefusal::NOT_FOUND, 'outcome' => 'not_found'];

        $this->client->request('GET', '/api/v1/tickets/SHOP-2/draft/screenshots');
        self::assertResponseStatusCodeSame(401);
        self::assertSame(0, $this->rowCountOf('aud_event'));

        $this->request($omarsToken, 'GET', '/api/v1/tickets/SHOP-2/draft/screenshots');

        self::assertResponseStatusCodeSame(200);
        self::assertSame([
            'untrusted_text_notice' => UntrustedText::NOTICE,
            'screenshots' => [
                ['screenshot_id' => $couponShot, 'number' => 1, 'title' => '<untrusted_text>Coupon</untrusted_text>', 'try_count' => 1],
                ['screenshot_id' => $cartShot, 'number' => 2, 'title' => '<untrusted_text>Cart total &lt;again&gt;</untrusted_text>', 'try_count' => 2],
            ],
        ], $this->json());
        foreach ([$cartRetake, $miasShot, 'Mia private', $betaBakeryShot, 'Bread shelf'] as $notListed) {
            self::assertStringNotContainsString($notListed, (string) $this->client->getResponse()->getContent());
        }

        $this->request($miasToken, 'GET', '/api/v1/tickets/SHOP-2/draft/screenshots');

        self::assertResponseStatusCodeSame(200);
        self::assertSame([['screenshot_id' => $miasShot, 'number' => 1, 'title' => '<untrusted_text>Mia private title</untrusted_text>', 'try_count' => 1]], $this->json()['screenshots'] ?? null);
        foreach ([$couponShot, $cartShot, $cartRetake, 'Coupon', 'Cart total'] as $omarsText) {
            self::assertStringNotContainsString($omarsText, (string) $this->client->getResponse()->getContent());
        }

        foreach (['SHOP-1', 'SHOP-404'] as $key) {
            $this->request($omarsToken, 'GET', '/api/v1/tickets/'.$key.'/draft/screenshots');
            self::assertResponseStatusCodeSame(404, $key);
            self::assertSame($notFound, $this->json());
        }

        self::assertEqualsCanonicalizing([[$omarsTokenId, 'ok'], [$miasTokenId, 'ok'], [$omarsTokenId, 'not_found'], [$omarsTokenId, 'not_found']], $this->toolAuditRowsOf(ListDraftScreenshots::NAME));
        self::assertSame([], $this->toolRowsOf($betaBakery));
    }

    /**
     * Lena makes a notebook twice with one Idempotency-Key, begins an upload for it and adds a picture, then sends the
     * add again without a key, as after a lost answer: one notebook, the picture in it once, and no ticket.
     */
    #[Test]
    public function theOwnerMakesANotebookOnceForOneIdempotencyKeyUploadsForItAndAddsAPictureWithoutATicket(): void
    {
        [$tokenId, $token] = $this->issueApiToken($this->people['Lena'], $this->acmeFoods, scopes: [ApiTokenScope::NotebooksWrite]);

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
        self::assertSame([[$notebookId, 'Coupon questions', 1, 2]], $this->rowsOf('SELECT id, title, jsonb_array_length(screenshots), version FROM rvw_notebook'));

        $this->request($token, 'POST', '/api/v1/notebooks/'.$notebookId.'/screenshots', ['upload_id' => $uploadId, 'title' => 'Cart total']);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['notebook_id' => $notebookId, 'media_id' => $uploadId, 'status' => AddNotebookScreenshot::ALREADY_IN_NOTEBOOK, 'screenshot_count' => 1], $this->json());
        self::assertNull($this->client->getResponse()->headers->get(RestToolController::REPLAYED_HEADER), 'Sent without a key, it ran again.');
        self::assertSame([[$notebookId, 'Coupon questions', 1, 2]], $this->rowsOf('SELECT id, title, jsonb_array_length(screenshots), version FROM rvw_notebook'));
        self::assertSame([[$tokenId, 'ok'], [$tokenId, 'ok']], $this->toolAuditRowsOf(AddNotebookScreenshot::NAME));
        self::assertSame(0, $this->rowCountOf('tkt_ticket'));
    }

    /**
     * Omar and Sara hold notebooks:write, so only the owner rule can refuse them, and Lena's Beta Bakery token only the
     * tenant; an unknown and a malformed id are 404 too, never 500. Lena's token without notebooks:write is 403 on each route. Nothing changes.
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
        $betaBakery = $this->createOrganization('Beta Bakery');
        $this->addMembership($this->people['Lena'], $betaBakery);
        $notebooksBefore = $this->rowsOf('SELECT id, owner_id, title, screenshots::text, version FROM rvw_notebook');
        self::assertCount(1, $notebooksBefore);
        $intentsBefore = $this->rowsOf('SELECT id, used_at FROM med_upload_intent ORDER BY id');

        $callers = [
            'Omar' => $this->issueApiToken($this->people['Omar'], $this->acmeFoods, scopes: [ApiTokenScope::NotebooksWrite])[1],
            'Sara' => $this->issueApiToken($this->people['Sara'], $this->acmeFoods, scopes: [ApiTokenScope::NotebooksWrite])[1],
            'Lena of Beta Bakery' => $this->issueApiToken($this->people['Lena'], $betaBakery, scopes: [ApiTokenScope::NotebooksWrite])[1],
        ];
        foreach ($callers as $person => $token) {
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

    /**
     * Lena's extension makes a report draft with one picture, then adds a second one twice with one Idempotency-Key:
     * the draft holds both in order, the second answer is the first replayed, and no ticket is made.
     */
    #[Test]
    public function theOwnerAddsASecondPictureToHerReportDraftOnceForOneIdempotencyKeyWithoutATicket(): void
    {
        [$tokenId, $token] = $this->issueApiToken($this->people['Lena'], $this->acmeFoods, scopes: [ApiTokenScope::ReportsWrite]);
        $firstPicture = $this->addReadyUpload('Lena', 'new_report', 'SHOP');
        $this->request($token, 'POST', '/api/v1/reports', ['project' => 'SHOP', 'title' => 'Total is doubled', 'screenshots' => [['upload_id' => $firstPicture]]]);
        self::assertResponseStatusCodeSame(201);
        $draftId = $this->json()['draft_id'] ?? null;
        self::assertIsString($draftId);
        $secondPicture = $this->addReadyUpload('Lena', 'new_report', 'SHOP');
        $body = ['upload_id' => $secondPicture, 'title' => 'Cart total', 'pins' => [['x_pct' => 10, 'y_pct' => 20, 'note' => 'Doubled']]];

        $this->request($token, 'POST', '/api/v1/reports/'.$draftId.'/screenshots', $body, ['HTTP_IDEMPOTENCY_KEY' => 'shot-2']);
        self::assertResponseStatusCodeSame(200);
        $first = $this->json();
        self::assertSame(['draft_id' => $draftId, 'media_id' => $secondPicture, 'status' => AddReportScreenshot::ADDED, 'screenshot_count' => 2], $first);
        $this->request($token, 'POST', '/api/v1/reports/'.$draftId.'/screenshots', $body, ['HTTP_IDEMPOTENCY_KEY' => 'shot-2']);
        self::assertResponseStatusCodeSame(200);
        self::assertSame($first, $this->json());
        self::assertSame('true', $this->client->getResponse()->headers->get(RestToolController::REPLAYED_HEADER));

        self::assertSame([[$draftId, $firstPicture, $secondPicture, 'Cart total', 2]], $this->rowsOf(
            "SELECT id, screenshots->0->>'media_id', screenshots->1->>'media_id', screenshots->1->>'title', version FROM rvw_report_draft",
        ));
        self::assertSame([[$tokenId, 'ok']], $this->toolAuditRowsOf(AddReportScreenshot::NAME), 'The replay runs no tool.');
        self::assertSame(0, $this->rowCountOf('tkt_ticket'));
    }

    /**
     * The draft holds the ten pictures create_report allows; the eleventh is Review's plain refusal, 422.
     */
    #[Test]
    public function anEleventhPictureForAReportDraftIsRefusedAndTheDraftKeepsTen(): void
    {
        [, $token] = $this->issueApiToken($this->people['Lena'], $this->acmeFoods, scopes: [ApiTokenScope::ReportsWrite]);
        $screenshots = array_map(fn (int $number): array => ['upload_id' => $this->addReadyUpload('Lena', 'new_report', 'SHOP')], range(1, 10));
        $this->request($token, 'POST', '/api/v1/reports', ['project' => 'SHOP', 'title' => 'Total is doubled', 'screenshots' => $screenshots]);
        self::assertResponseStatusCodeSame(201);
        $draftId = $this->json()['draft_id'] ?? null;
        self::assertIsString($draftId);
        $eleventh = $this->addReadyUpload('Lena', 'new_report', 'SHOP');
        $draftsBefore = $this->rowsOf('SELECT id, screenshots::text, version FROM rvw_report_draft');

        $this->request($token, 'POST', '/api/v1/reports/'.$draftId.'/screenshots', ['upload_id' => $eleventh]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => ReportDraftRefused::TOO_MANY_SCREENSHOTS, 'outcome' => 'refused'], $this->json());
        self::assertSame($draftsBefore, $this->rowsOf('SELECT id, screenshots::text, version FROM rvw_report_draft'));
        self::assertSame([[10]], $this->rowsOf('SELECT jsonb_array_length(screenshots) FROM rvw_report_draft'));
    }

    /**
     * Omar and Sara hold reports:write, each with an upload of their own for SHOP, so only the owner rule can refuse
     * them, and Lena's Beta Bakery token only the tenant; an unknown and a malformed id are 404 too, never 500. Lena's
     * token without reports:write is 403. Nothing changes and no ticket is made.
     */
    #[Test]
    public function anotherPersonsReportDraftAnotherOrganizationAnUnknownIdAndATokenWithoutReportsWriteAreRefusedAndNothingChanges(): void
    {
        [, $lenasToken] = $this->issueApiToken($this->people['Lena'], $this->acmeFoods, scopes: [ApiTokenScope::ReportsWrite]);
        $this->request($lenasToken, 'POST', '/api/v1/reports', ['project' => 'SHOP', 'title' => 'Total is doubled']);
        self::assertResponseStatusCodeSame(201);
        $lenasDraft = $this->json()['draft_id'] ?? null;
        self::assertIsString($lenasDraft);
        $betaBakery = $this->createOrganization('Beta Bakery');
        $this->addMembership($this->people['Lena'], $betaBakery);
        $lenasUpload = $this->addReadyUpload('Lena', 'new_report', 'SHOP');
        $draftsBefore = $this->rowsOf('SELECT id, owner_id, screenshots::text, version FROM rvw_report_draft');
        self::assertCount(1, $draftsBefore);

        $callers = [
            [$this->issueApiToken($this->people['Omar'], $this->acmeFoods, scopes: [ApiTokenScope::ReportsWrite])[1], $this->addReadyUpload('Omar', 'new_report', 'SHOP')],
            [$this->issueApiToken($this->people['Sara'], $this->acmeFoods, scopes: [ApiTokenScope::ReportsWrite])[1], $this->addReadyUpload('Sara', 'new_report', 'SHOP')],
            [$this->issueApiToken($this->people['Lena'], $betaBakery, scopes: [ApiTokenScope::ReportsWrite])[1], $lenasUpload],
        ];
        foreach ($callers as [$token, $uploadId]) {
            $this->request($token, 'POST', '/api/v1/reports/'.$lenasDraft.'/screenshots', ['upload_id' => $uploadId]);
            self::assertResponseStatusCodeSame(404);
            self::assertSame(['error' => ToolRefusal::NOT_FOUND, 'outcome' => 'not_found'], $this->json());
        }
        foreach (['0197a3c0-0000-7000-8000-0000000f9999', 'not-a-uuid'] as $draftId) {
            $this->request($lenasToken, 'POST', '/api/v1/reports/'.$draftId.'/screenshots', ['upload_id' => $lenasUpload]);
            self::assertResponseStatusCodeSame(404, $draftId);
            self::assertSame(['error' => ToolRefusal::NOT_FOUND, 'outcome' => 'not_found'], $this->json());
        }

        [, $readToken] = $this->issueApiToken($this->people['Lena'], $this->acmeFoods, scopes: [ApiTokenScope::Read, ApiTokenScope::RoundsWrite, ApiTokenScope::NotebooksWrite]);
        $this->request($readToken, 'POST', '/api/v1/reports/'.$lenasDraft.'/screenshots', ['upload_id' => $lenasUpload]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(['error' => \sprintf(ToolRefusal::MISSING_SCOPE, 'reports:write'), 'outcome' => 'missing_scope'], $this->json());

        self::assertSame($draftsBefore, $this->rowsOf('SELECT id, owner_id, screenshots::text, version FROM rvw_report_draft'));
        self::assertSame(0, $this->rowCountOf('tkt_ticket'));
    }

    /**
     * Omar's draft in Acme Foods and Lena's in Beta Bakery changed after both of hers: the list leaves out the first by
     * its owner and the second by the token's organization. Her older draft is inserted first and has the higher id,
     * so neither insertion nor id order gives the answer. Omar's own token lists only his draft, and adding a picture to
     * Lena's draft with it is refused as not found. No token is 401 with no audit row; a token without read is 403.
     */
    #[Test]
    public function myReportDraftsListsOnlyTheCallersDraftsOfTheTokensOrganizationLastChangedFirst(): void
    {
        $betaBakery = $this->createOrganization('Beta Bakery');
        $this->addMembership($this->people['Lena'], $betaBakery);
        $twoScreenshots = [['media_id' => self::READY_MEDIA, 'title' => null, 'summary' => null, 'pins' => []], ['media_id' => self::READY_MEDIA, 'title' => null, 'summary' => null, 'pins' => []]];
        $this->addReportDraft($this->acmeFoods, 'Lena', self::LENAS_OLDER_REPORT_DRAFT, 'Coupon is ignored', '2026-11-05 08:00:00+00', $twoScreenshots);
        $this->addReportDraft($this->acmeFoods, 'Lena', self::LENAS_NEWER_REPORT_DRAFT, 'Total & <b>', '2026-11-06 09:00:00.5+00');
        $this->addReportDraft($this->acmeFoods, 'Omar', self::OMARS_REPORT_DRAFT, 'Omar own draft', '2026-11-07 10:00:00+00');
        $this->addReportDraft($betaBakery, 'Lena', self::LENAS_REPORT_DRAFT_IN_BETA_BAKERY, 'Beta Bakery draft', '2026-11-08 11:00:00+00');
        [$lenasTokenId, $lenasToken] = $this->issueApiToken($this->people['Lena'], $this->acmeFoods);
        [$omarsTokenId, $omarsToken] = $this->issueApiToken($this->people['Omar'], $this->acmeFoods, scopes: [ApiTokenScope::Read, ApiTokenScope::ReportsWrite]);
        $auditRowsBefore = $this->rowCountOf('aud_event');

        $this->client->request('GET', '/api/v1/reports');
        self::assertResponseStatusCodeSame(401);
        self::assertSame($auditRowsBefore, $this->rowCountOf('aud_event'));

        $this->request($lenasToken, 'GET', '/api/v1/reports');
        self::assertResponseStatusCodeSame(200);
        self::assertSame([
            'untrusted_text_notice' => UntrustedText::NOTICE,
            'report_drafts' => [
                ['draft_id' => self::LENAS_NEWER_REPORT_DRAFT, 'project' => 'SHOP', 'title' => '<untrusted_text>Total &amp; &lt;b&gt;</untrusted_text>', 'screenshot_count' => 0, 'updated_at' => '2026-11-06T09:00:00+00:00', 'url' => 'http://localhost/report-drafts/'.self::LENAS_NEWER_REPORT_DRAFT],
                ['draft_id' => self::LENAS_OLDER_REPORT_DRAFT, 'project' => 'SHOP', 'title' => '<untrusted_text>Coupon is ignored</untrusted_text>', 'screenshot_count' => 2, 'updated_at' => '2026-11-05T08:00:00+00:00', 'url' => 'http://localhost/report-drafts/'.self::LENAS_OLDER_REPORT_DRAFT],
            ],
            'has_more' => false,
        ], $this->json());

        $this->request($omarsToken, 'GET', '/api/v1/reports');
        self::assertResponseStatusCodeSame(200);
        $omarsAnswer = $this->json();
        self::assertIsArray($omarsAnswer['report_drafts'] ?? null);
        self::assertSame([self::OMARS_REPORT_DRAFT], array_column($omarsAnswer['report_drafts'], 'draft_id'));
        $draftsBefore = $this->rowsOf('SELECT id, owner_id, screenshots::text, version FROM rvw_report_draft ORDER BY id');
        $this->request($omarsToken, 'POST', '/api/v1/reports/'.self::LENAS_NEWER_REPORT_DRAFT.'/screenshots', ['upload_id' => $this->addReadyUpload('Omar', 'new_report', 'SHOP')]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(['error' => ToolRefusal::NOT_FOUND, 'outcome' => 'not_found'], $this->json());
        self::assertSame($draftsBefore, $this->rowsOf('SELECT id, owner_id, screenshots::text, version FROM rvw_report_draft ORDER BY id'));

        [$writeOnlyTokenId, $writeOnlyToken] = $this->issueApiToken($this->people['Lena'], $this->acmeFoods, scopes: [ApiTokenScope::ReportsWrite]);
        $this->request($writeOnlyToken, 'GET', '/api/v1/reports');
        self::assertResponseStatusCodeSame(403);
        self::assertSame(['error' => \sprintf(ToolRefusal::MISSING_SCOPE, 'read'), 'outcome' => 'missing_scope'], $this->json());

        self::assertEqualsCanonicalizing([[$lenasTokenId, 'ok'], [$omarsTokenId, 'ok'], [$writeOnlyTokenId, 'missing_scope']], $this->toolAuditRowsOf(ListMyReportDrafts::NAME));
        self::assertSame(0, $this->rowCountOf('tkt_ticket'));
    }

    /**
     * One draft more than the list holds, changed n minutes after the first and inserted odd numbers up, then even ones
     * down: the list is the newest twenty and says more exist.
     */
    #[Test]
    public function myReportDraftsHoldsAtMostTwentyAndSaysWhenMoreExist(): void
    {
        $count = ReportDrafts::DRAFTS_PER_PAGE + 1;
        foreach ([...range(1, $count, 2), ...array_reverse(range(2, $count, 2))] as $number) {
            $updatedAt = new \DateTimeImmutable('2026-11-05 08:00:00+00')->modify(\sprintf('+%d minutes', $number))->format('Y-m-d H:i:sP');
            $this->addReportDraft($this->acmeFoods, 'Lena', \sprintf(self::LENAS_PAGED_REPORT_DRAFT, $number), 'Draft '.$number, $updatedAt);
        }
        [, $token] = $this->issueApiToken($this->people['Lena'], $this->acmeFoods);

        $this->request($token, 'GET', '/api/v1/reports');

        self::assertResponseStatusCodeSame(200);
        $answer = $this->json();
        self::assertIsArray($answer['report_drafts'] ?? null);
        self::assertSame(array_map(static fn (int $number): string => \sprintf(self::LENAS_PAGED_REPORT_DRAFT, $number), range($count, 2)), array_column($answer['report_drafts'], 'draft_id'));
        self::assertTrue($answer['has_more'] ?? null);
    }

    /**
     * Omar's notebook in Acme Foods and Lena's in Beta Bakery were used after both of hers: the list leaves out the
     * first by its owner and the second by the token's organization. Her older notebook is inserted first and has the
     * higher id, so neither insertion nor id order gives the answer. No token is 401 with no audit row, and a token
     * without read is 403.
     */
    #[Test]
    public function myNotebooksListsOnlyTheCallersNotebooksOfTheTokensOrganizationLastUsedFirst(): void
    {
        $betaBakery = $this->createOrganization('Beta Bakery');
        $this->addMembership($this->people['Lena'], $betaBakery);
        $this->addNotebook($this->acmeFoods, $this->people['Lena'], self::LENAS_OLDER_NOTEBOOK, 'Coupon questions', '2026-11-05 08:00:00+00');
        $this->addNotebook($this->acmeFoods, $this->people['Lena'], self::LENAS_NEWER_NOTEBOOK, 'Checkout & <b>', '2026-11-06 09:00:00+00');
        $this->addNotebook($this->acmeFoods, $this->people['Omar'], self::OMARS_NOTEBOOK, 'Omar own notebook', '2026-11-07 10:00:00+00');
        $this->addNotebook($betaBakery, $this->people['Lena'], self::LENAS_NOTEBOOK_IN_BETA_BAKERY, 'Beta Bakery notebook', '2026-11-08 11:00:00+00');
        [$lenasTokenId, $lenasToken] = $this->issueApiToken($this->people['Lena'], $this->acmeFoods);
        [$omarsTokenId, $omarsToken] = $this->issueApiToken($this->people['Omar'], $this->acmeFoods);
        $auditRowsBefore = $this->rowCountOf('aud_event');

        $this->client->request('GET', '/api/v1/notebooks');
        self::assertResponseStatusCodeSame(401);
        self::assertSame($auditRowsBefore, $this->rowCountOf('aud_event'));

        $this->request($lenasToken, 'GET', '/api/v1/notebooks');
        self::assertResponseStatusCodeSame(200);
        self::assertSame([
            'untrusted_text_notice' => UntrustedText::NOTICE,
            'notebooks' => [
                ['notebook_id' => self::LENAS_NEWER_NOTEBOOK, 'title' => '<untrusted_text>Checkout &amp; &lt;b&gt;</untrusted_text>', 'screenshot_count' => 0, 'scenario_count' => 0, 'last_used_at' => '2026-11-06T09:00:00+00:00'],
                ['notebook_id' => self::LENAS_OLDER_NOTEBOOK, 'title' => '<untrusted_text>Coupon questions</untrusted_text>', 'screenshot_count' => 0, 'scenario_count' => 0, 'last_used_at' => '2026-11-05T08:00:00+00:00'],
            ],
            'next_cursor' => null,
        ], $this->json());

        $this->request($omarsToken, 'GET', '/api/v1/notebooks');
        self::assertResponseStatusCodeSame(200);
        self::assertSame([self::OMARS_NOTEBOOK], self::notebookIdsOf($this->json()));

        [$writeOnlyTokenId, $writeOnlyToken] = $this->issueApiToken($this->people['Lena'], $this->acmeFoods, scopes: [ApiTokenScope::NotebooksWrite]);
        $this->request($writeOnlyToken, 'GET', '/api/v1/notebooks');
        self::assertResponseStatusCodeSame(403);
        self::assertSame(['error' => \sprintf(ToolRefusal::MISSING_SCOPE, 'read'), 'outcome' => 'missing_scope'], $this->json());

        self::assertEqualsCanonicalizing([[$lenasTokenId, 'ok'], [$omarsTokenId, 'ok'], [$writeOnlyTokenId, 'missing_scope']], $this->toolAuditRowsOf(ListMyNotebooks::NAME));
        self::assertSame(3, $this->rowCountOf('rvw_notebook'));
        self::assertSame(0, $this->rowCountOf('tkt_ticket'));
    }

    /**
     * One notebook more than a page, inserted odd numbers up, then even ones down; notebook n was used n minutes after
     * the first. The cursor carries a "+", which the query string must keep.
     */
    #[Test]
    public function theCursorOfAFullPageGivesTheNextPage(): void
    {
        $count = Notebooks::NOTEBOOKS_PER_PAGE + 1;
        foreach ([...range(1, $count, 2), ...array_reverse(range(2, $count, 2))] as $number) {
            $usedAt = new \DateTimeImmutable('2026-11-05 08:00:00+00')->modify(\sprintf('+%d minutes', $number))->format('Y-m-d H:i:sP');
            $this->addNotebook($this->acmeFoods, $this->people['Lena'], \sprintf(self::LENAS_PAGED_NOTEBOOK, $number), 'Notebook '.$number, $usedAt);
        }
        [$tokenId, $token] = $this->issueApiToken($this->people['Lena'], $this->acmeFoods);

        $this->request($token, 'GET', '/api/v1/notebooks');
        self::assertResponseStatusCodeSame(200);
        $first = $this->json();
        self::assertSame(array_map(static fn (int $number): string => \sprintf(self::LENAS_PAGED_NOTEBOOK, $number), range($count, 2)), self::notebookIdsOf($first));
        self::assertIsString($first['next_cursor'] ?? null);

        $this->request($token, 'GET', '/api/v1/notebooks?'.http_build_query(['cursor' => $first['next_cursor']]));
        self::assertResponseStatusCodeSame(200);
        $second = $this->json();
        self::assertSame([\sprintf(self::LENAS_PAGED_NOTEBOOK, 1)], self::notebookIdsOf($second));
        self::assertArrayHasKey('next_cursor', $second);
        self::assertNull($second['next_cursor'], 'Nothing follows notebook 1.');

        self::assertSame([[$tokenId, 'ok'], [$tokenId, 'ok']], $this->toolAuditRowsOf(ListMyNotebooks::NAME));
        self::assertSame($count, $this->rowCountOf('rvw_notebook'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}> the query and the problem the refusal names
     */
    public static function cursorsTheToolRefuses(): iterable
    {
        yield 'one no page gave' => [['cursor' => 'next'], ListMyNotebooks::INVALID_CURSOR];
        yield 'an empty one' => [['cursor' => ''], self::EMPTY_CURSOR_PROBLEM];
        yield 'one over 100 characters' => [['cursor' => str_repeat('a', 101)], self::LONG_CURSOR_PROBLEM];
        yield 'a list' => [['cursor' => ['next']], self::LIST_CURSOR_PROBLEM];
    }

    /**
     * @param array<string, mixed> $query
     */
    #[Test]
    #[DataProvider('cursorsTheToolRefuses')]
    public function aCursorTheToolRefusesIsInvalidInputAndListsNothing(array $query, string $problem): void
    {
        $this->addNotebook($this->acmeFoods, $this->people['Lena'], self::LENAS_OLDER_NOTEBOOK, 'Coupon questions', '2026-11-05 08:00:00+00');
        [$tokenId, $token] = $this->issueApiToken($this->people['Lena'], $this->acmeFoods);

        $this->request($token, 'GET', '/api/v1/notebooks?'.http_build_query($query));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => \sprintf(ToolRefusal::INVALID_INPUT, $problem), 'outcome' => 'invalid_input'], $this->json());
        self::assertSame([[$tokenId, 'invalid_input']], $this->toolAuditRowsOf(ListMyNotebooks::NAME));
        self::assertSame(1, $this->rowCountOf('rvw_notebook'));
        self::assertSame(0, $this->rowCountOf('tkt_ticket'));
    }

    /**
     * The scenario's title holds "&", "<" and characters of two scripts: it comes back between the markers, escaped as
     * the tool escapes it. Reading the notebook is no use of it.
     */
    #[Test]
    public function theOwnerGetsHerNotebookWithEachTextWrappedAsTheToolAnswers(): void
    {
        self::getContainer()->set(SignedVariantUrls::class, new RecordingSignedUrls(self::getContainer()->get(TenantContext::class), [[$this->acmeFoods->toString(), self::READY_MEDIA, MediaImageStatus::Ready]]));
        $screenshot = ['media_id' => self::READY_MEDIA, 'title' => 'Cart total', 'summary' => null, 'pins' => [['x_pct' => 10.5, 'y_pct' => 20, 'note' => 'Doubled']]];
        $scenario = [
            'title' => 'Total < 0 & prix affiché 価格',
            'why' => 'CartTotal::apply() lets it go below 0.',
            'setup' => 'One SHOP coupon.',
            'start_url' => 'http://localhost:8000/cart',
            'steps' => [['type' => 'go_to', 'say' => 'Open the cart.', 'url' => 'http://localhost:8000/cart?coupon=1&x=2']],
        ];
        $this->addNotebook($this->acmeFoods, $this->people['Lena'], self::LENAS_OLDER_NOTEBOOK, 'Coupon questions', '2026-11-05 08:00:00.5+00', [$screenshot], [$scenario], 3);
        [$tokenId, $token] = $this->issueApiToken($this->people['Lena'], $this->acmeFoods);
        $notebookQuery = 'SELECT id, version, last_used_at::text FROM rvw_notebook';
        $notebookBefore = $this->rowsOf($notebookQuery);
        self::assertCount(1, $notebookBefore);

        $this->request($token, 'GET', '/api/v1/notebooks/'.self::LENAS_OLDER_NOTEBOOK);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([
            'untrusted_text_notice' => UntrustedText::NOTICE,
            'notebook_id' => self::LENAS_OLDER_NOTEBOOK,
            'title' => '<untrusted_text>Coupon questions</untrusted_text>',
            'version' => 3,
            'last_used_at' => '2026-11-05T08:00:00+00:00',
            'screenshots' => [[
                'media_id' => self::READY_MEDIA,
                'position' => 0,
                'image' => ['status' => 'ready', 'url' => 'https://storage.test/view/'.self::READY_MEDIA.'?at=2026-11-10T09:00:00+00:00'],
                'title' => '<untrusted_text>Cart total</untrusted_text>',
                'summary' => null,
                'pins' => [['number' => 1, 'x_pct' => 10.5, 'y_pct' => 20, 'note' => '<untrusted_text>Doubled</untrusted_text>']],
            ]],
            'scenarios' => [[
                'title' => '<untrusted_text>Total &lt; 0 &amp; prix affiché 価格</untrusted_text>',
                'why' => '<untrusted_text>CartTotal::apply() lets it go below 0.</untrusted_text>',
                'setup' => '<untrusted_text>One SHOP coupon.</untrusted_text>',
                'start_url' => '<untrusted_text>http://localhost:8000/cart</untrusted_text>',
                'steps' => [['type' => 'go_to', 'say' => '<untrusted_text>Open the cart.</untrusted_text>', 'url' => '<untrusted_text>http://localhost:8000/cart?coupon=1&amp;x=2</untrusted_text>']],
            ]],
        ], $this->json());
        self::assertSame([[$tokenId, 'ok']], $this->toolAuditRowsOf(GetNotebook::NAME));
        self::assertSame($notebookBefore, $this->rowsOf($notebookQuery));
        self::assertSame(0, $this->rowCountOf('tkt_ticket'));
    }

    /**
     * Omar and Sara hold read, so only the owner rule can refuse them, and Lena's Beta Bakery token only the tenant;
     * an unknown, a malformed and an over-long id are 404 too, never 500. No token is 401 with no audit row.
     */
    #[Test]
    public function anotherPersonsNotebookAnotherOrganizationAndAnUnknownIdAreNotFoundAndNothingChanges(): void
    {
        $this->addNotebook($this->acmeFoods, $this->people['Lena'], self::LENAS_OLDER_NOTEBOOK, 'Coupon questions', '2026-11-05 08:00:00+00');
        $betaBakery = $this->createOrganization('Beta Bakery');
        $this->addMembership($this->people['Lena'], $betaBakery);
        $notebookQuery = 'SELECT id, owner_id, title, version, last_used_at::text FROM rvw_notebook';
        $notebooksBefore = $this->rowsOf($notebookQuery);
        self::assertCount(1, $notebooksBefore);
        $notFound = ['error' => ToolRefusal::NOT_FOUND, 'outcome' => 'not_found'];

        $this->client->request('GET', '/api/v1/notebooks/'.self::LENAS_OLDER_NOTEBOOK);
        self::assertResponseStatusCodeSame(401);
        self::assertSame(0, $this->rowCountOf('aud_event'));

        [$omarsTokenId, $omarsToken] = $this->issueApiToken($this->people['Omar'], $this->acmeFoods);
        [$sarasTokenId, $sarasToken] = $this->issueApiToken($this->people['Sara'], $this->acmeFoods);
        [$betaTokenId, $betaToken] = $this->issueApiToken($this->people['Lena'], $betaBakery);
        foreach (['Omar' => $omarsToken, 'Sara' => $sarasToken, 'Lena of Beta Bakery' => $betaToken] as $person => $token) {
            $this->request($token, 'GET', '/api/v1/notebooks/'.self::LENAS_OLDER_NOTEBOOK);
            self::assertResponseStatusCodeSame(404, $person);
            self::assertSame($notFound, $this->json());
        }
        [$lenasTokenId, $lenasToken] = $this->issueApiToken($this->people['Lena'], $this->acmeFoods);
        foreach (['0197a3c0-0000-7000-8000-0000000b9999', 'not-a-notebook', self::LENAS_OLDER_NOTEBOOK.str_repeat('0', 29)] as $notebookId) {
            $this->request($lenasToken, 'GET', '/api/v1/notebooks/'.$notebookId);
            self::assertResponseStatusCodeSame(404, $notebookId);
            self::assertSame($notFound, $this->json());
        }

        $notFoundRows = [[$omarsTokenId, 'not_found'], [$sarasTokenId, 'not_found'], [$lenasTokenId, 'not_found'], [$lenasTokenId, 'not_found'], [$lenasTokenId, 'not_found']];
        self::assertEqualsCanonicalizing($notFoundRows, $this->toolAuditRowsOf(GetNotebook::NAME));
        self::assertSame([[$betaTokenId, ToolCallAuditor::TOOL_TARGET_TYPE, null, GetNotebook::NAME, 'not_found']], $this->toolRowsOf($betaBakery));
        self::assertSame($notebooksBefore, $this->rowsOf($notebookQuery));
        self::assertSame(0, $this->rowCountOf('tkt_ticket'));
    }

    /**
     * @return iterable<string, array{string, string, string, string, string}> the method, the path with %s for the id,
     *                                                                         the tool it runs, its scope, and whether
     *                                                                         its body names an upload, a file or
     *                                                                         nothing
     */
    public static function routesWithAnIdInThePath(): iterable
    {
        yield 'report screenshots' => ['POST', '/api/v1/reports/%s/screenshots', AddReportScreenshot::NAME, 'reports:write', 'upload'];
        yield 'notebook' => ['GET', '/api/v1/notebooks/%s', GetNotebook::NAME, 'read', 'none'];
        yield 'notebook uploads' => ['POST', '/api/v1/notebooks/%s/uploads', CreateNotebookUpload::NAME, 'notebooks:write', 'file'];
        yield 'notebook screenshots' => ['POST', '/api/v1/notebooks/%s/screenshots', AddNotebookScreenshot::NAME, 'notebooks:write', 'upload'];
        yield 'draft round screenshots' => ['POST', '/api/v1/tickets/%s/draft/screenshots', AddScreenshot::NAME, 'rounds:write', 'upload'];
        yield 'my draft screenshots' => ['GET', '/api/v1/tickets/%s/draft/screenshots', ListDraftScreenshots::NAME, 'read', 'none'];
    }

    /**
     * An id over the tool's 64 characters names nothing: not found, as v1.yaml promises, not the schema's 422, with
     * the tool's not_found audit row. A token with every scope but the route's still gets 403 first. Nothing changes.
     */
    #[Test]
    #[DataProvider('routesWithAnIdInThePath')]
    public function aPathIdOverSixtyFourCharactersIsNotFoundAndNothingChanges(string $method, string $pathFormat, string $toolName, string $scope, string $bodyKind): void
    {
        [$tokenId, $token] = $this->issueApiToken($this->people['Lena'], $this->acmeFoods, scopes: [ApiTokenScope::Read, ApiTokenScope::ReportsWrite, ApiTokenScope::NotebooksWrite, ApiTokenScope::RoundsWrite]);
        $this->request($token, 'POST', '/api/v1/reports', ['project' => 'SHOP', 'title' => 'Total is doubled']);
        self::assertResponseStatusCodeSame(201);
        $this->request($token, 'POST', '/api/v1/notebooks', ['title' => 'Coupon questions']);
        self::assertResponseStatusCodeSame(201);
        $body = match ($bodyKind) {
            'file' => ['mime' => 'image/png', 'bytes' => 245_760, 'sha256' => self::SHA256],
            'upload' => ['upload_id' => $this->addReadyUpload('Lena', 'new_report', 'SHOP')],
            default => null,
        };
        $stateQuery = "SELECT 'draft', id, screenshots::text || ' v' || version FROM rvw_report_draft UNION ALL SELECT 'notebook', id, screenshots::text || ' v' || version FROM rvw_notebook UNION ALL SELECT 'intent', id, target_kind || ' ' || coalesce(used_at::text, 'unused') FROM med_upload_intent ORDER BY 1, 2";
        $stateBefore = $this->rowsOf($stateQuery);
        self::assertCount('upload' === $bodyKind ? 3 : 2, $stateBefore);
        $pathId = '0197a3c0-0000-7000-8000-0000000f9999'.str_repeat('0', 29); // 65 characters

        $this->request($token, $method, \sprintf($pathFormat, $pathId), $body);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(['error' => ToolRefusal::NOT_FOUND, 'outcome' => 'not_found'], $this->json());

        $everyOtherScope = array_values(array_filter(ApiTokenScope::cases(), static fn (ApiTokenScope $other): bool => $other->value !== $scope));
        [$unscopedTokenId, $unscopedToken] = $this->issueApiToken($this->people['Lena'], $this->acmeFoods, scopes: $everyOtherScope);
        $this->request($unscopedToken, $method, \sprintf($pathFormat, $pathId), $body);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(['error' => \sprintf(ToolRefusal::MISSING_SCOPE, $scope), 'outcome' => 'missing_scope'], $this->json());
        self::assertEqualsCanonicalizing([[$tokenId, 'not_found'], [$unscopedTokenId, 'missing_scope']], $this->toolAuditRowsOf($toolName));
        self::assertSame($stateBefore, $this->rowsOf($stateQuery));
        self::assertSame(0, $this->rowCountOf('tkt_ticket'));
    }

    /**
     * @param array<mixed> $answer
     *
     * @return list<mixed> the listed notebooks' ids, in the answer's order; fails on an empty list
     */
    private static function notebookIdsOf(array $answer): array
    {
        $notebooks = $answer['notebooks'] ?? null;
        self::assertIsArray($notebooks);
        self::assertNotSame([], $notebooks);

        return array_values(array_map(static function (mixed $notebook): mixed {
            self::assertIsArray($notebook);

            return $notebook['notebook_id'] ?? null;
        }, $notebooks));
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
     * @param list<array<string, mixed>> $screenshots {media_id, title, summary, pins} each
     */
    private function addReportDraft(OrganizationId $organizationId, string $owner, string $draftId, string $title, string $updatedAt, array $screenshots = []): void
    {
        $parameters = [$organizationId->toString(), $draftId, $this->people[$owner]->id()->toString(), $title, json_encode($screenshots, \JSON_THROW_ON_ERROR), $updatedAt];
        $this->committedAs($organizationId, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO rvw_report_draft (organization_id, id, owner_id, project_key, title, detail, screenshots, version, created_at, updated_at)
             VALUES (?, ?, ?, 'SHOP', ?, '', CAST(? AS jsonb), 1, '2026-11-01 08:00:00+00', ?)",
            $parameters,
        ));
    }

    /**
     * @return list<list<mixed>> each call's token and outcome of the tool named $toolName, oldest first
     */
    private function toolAuditRowsOf(string $toolName): array
    {
        return array_map(static function (array $row): array {
            self::assertIsString($row[1]);
            $data = json_decode($row[1], true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($data);

            return [$row[0], $data['outcome'] ?? null];
        }, $this->rowsOf(\sprintf("SELECT actor_token_id, data::text FROM aud_event WHERE data->>'tool' = '%s' ORDER BY at, id", $toolName)));
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
