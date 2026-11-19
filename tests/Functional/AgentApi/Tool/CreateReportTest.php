<?php

declare(strict_types=1);

namespace App\Tests\Functional\AgentApi\Tool;

use App\AgentApi\Infrastructure\Mcp\Tool\CreateReport;
use App\AgentApi\Infrastructure\Mcp\Tool\CreateReportUpload;
use App\AgentApi\Infrastructure\Mcp\ToolCallAuditor;
use App\AgentApi\Infrastructure\Mcp\ToolRefusal;
use App\Identity\Domain\ApiTokenScope;
use App\Identity\Domain\User;
use App\Review\Api\ReportDraftRefused;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Functional\Identity\IssuesApiTokens;
use App\Tests\Functional\Identity\LogsInUsers;
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
 * create_report_upload and create_report through POST /mcp end to end (P09.T9, FR-MCP-12, ADR-0039): the api_token
 * firewall, the scope, the gate, the new_report upload target, Media's uploads and Review's report drafts. Mia has no
 * role on Shop, as anyone may report (ADR-0038). Shop owns SHOP; nobody owns LOOSE. The clock is fixed on 10 Nov 2026,
 * a day aud_event has a partition for and not today.
 */
final class CreateReportTest extends WebTestCase
{
    use CreatesAcmeFoodsTeams;
    use CreatesTeams;
    use Factories;
    use IssuesApiTokens;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }

    private const string MCP = '/mcp';
    private const string NOW = '2026-11-10 09:00:20 UTC';
    private const string PROTOCOL_VERSION = '2025-06-18';
    private const string SHA256 = '9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08';

    private KernelBrowser $client;

    /** @var array<string, User> */
    private array $people;
    private string $shopProject;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['REMOTE_ADDR' => \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254))]);
        $this->client->disableReboot();
        self::getContainer()->set('clock', new MockClock(self::NOW));
        $this->people = $this->createAcmeFoodsPeople(null);
        $this->shopProject = $this->addProject($this->shop, 'SHOP', 'Shop');
        $this->addProject(null, 'LOOSE', 'Loose ends');
    }

    protected function tearDown(): void
    {
        foreach ($this->createdOrganizationIds as $organizationId) {
            $this->committedAs(new OrganizationId($organizationId), static function (Connection $migrator) use ($organizationId): int {
                foreach (['aud_event', 'rvw_report_draft', 'med_object', 'med_upload_intent'] as $table) {
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
    public function aReportUploadIsBegunForTheProjectsNewReportAndAuditedByTheProjectsId(): void
    {
        [$tokenId, $token] = $this->issueApiToken($this->people['Mia'], $this->acmeFoods, scopes: [ApiTokenScope::Read, ApiTokenScope::ReportsWrite]);

        $this->callTool($token, CreateReportUpload::NAME, ['project' => 'SHOP', 'mime' => 'image/png', 'bytes' => 245_760, 'sha256' => self::SHA256]);

        self::assertResponseStatusCodeSame(200);
        self::assertFalse($this->responseValueAt('result', 'isError'));
        $intentId = $this->responseValueAt('result', 'structuredContent', 'upload_id');
        self::assertIsString($intentId);
        self::assertSame([[$intentId, $this->people['Mia']->id()->toString(), 'new_report', 'SHOP', null]], $this->rowsOf('SELECT id, user_id, target_kind, target_id, used_at FROM med_upload_intent ORDER BY id'));
        self::assertSame([['token', $tokenId, 'project', $this->shopProject, CreateReportUpload::NAME, 'ok']], $this->toolRows());
    }

    /**
     * The fields that differ from a PNG screenshot of 240 KB, and Media's plain message for them.
     *
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function filesRefusedBeforeAnyUrl(): iterable
    {
        yield 'a GIF' => [['mime' => 'image/gif'], 'This file is not a PNG, JPEG or WebP screenshot.'];
        yield 'a file one byte over 15 MB' => [['bytes' => 15 * 1024 * 1024 + 1], 'This file is empty or bigger than 15 MB. Pick a screenshot of up to 15 MB.'];
    }

    /**
     * @param array<string, mixed> $fields
     */
    #[Test]
    #[DataProvider('filesRefusedBeforeAnyUrl')]
    public function aReportUploadOfTheWrongTypeOrSizeIsRefusedWithMediasMessageAndBeginsNothing(array $fields, string $message): void
    {
        [$tokenId, $token] = $this->issueApiToken($this->people['Mia'], $this->acmeFoods, scopes: [ApiTokenScope::ReportsWrite]);

        $this->callTool($token, CreateReportUpload::NAME, [...['project' => 'SHOP', 'mime' => 'image/png', 'bytes' => 245_760, 'sha256' => self::SHA256], ...$fields]);

        $this->assertRefused($message);
        self::assertSame([], $this->rowsOf('SELECT id FROM med_upload_intent'));
        self::assertSame([['token', $tokenId, 'project', $this->shopProject, CreateReportUpload::NAME, 'refused']], $this->toolRows());
    }

    #[Test]
    public function createReportMakesAPrivateDraftFromTheUploadsAndNoTicket(): void
    {
        [$tokenId, $token] = $this->issueApiToken($this->people['Mia'], $this->acmeFoods, scopes: [ApiTokenScope::Read, ApiTokenScope::ReportsWrite]);
        $first = $this->addIntent('Mia', 'new_report', 'SHOP');
        $second = $this->addIntent('Mia', 'new_report', 'SHOP');

        $this->callTool($token, CreateReport::NAME, [
            'project' => 'SHOP',
            'title' => 'Total is doubled on the cart',
            'detail' => "1. Add two items\n2. Open the cart",
            'screenshots' => [
                ['upload_id' => $first, 'title' => 'Case B', 'summary' => 'The total shows twice the sum.', 'pins' => [['x_pct' => 10.5, 'y_pct' => 20, 'note' => 'Total']]],
                ['upload_id' => $second],
            ],
        ]);

        self::assertResponseStatusCodeSame(200);
        self::assertFalse($this->responseValueAt('result', 'isError'));
        $answer = $this->responseValueAt('result', 'structuredContent');
        self::assertIsArray($answer);
        self::assertSame(['draft_id', 'url', 'screenshot_count', 'status', 'message'], array_keys($answer));
        self::assertIsString($answer['draft_id']);
        self::assertSame('http://localhost/report-drafts/'.$answer['draft_id'], $answer['url']);
        self::assertSame([2, 'draft', CreateReport::NEXT_STEP], [$answer['screenshot_count'], $answer['status'], $answer['message']]);

        $drafts = $this->rowsOf('SELECT id, owner_id, project_key, title, detail, screenshots::text FROM rvw_report_draft');
        self::assertCount(1, $drafts);
        self::assertSame([$answer['draft_id'], $this->people['Mia']->id()->toString(), 'SHOP', 'Total is doubled on the cart', "1. Add two items\n2. Open the cart"], \array_slice($drafts[0], 0, 5));
        self::assertIsString($drafts[0][5]);
        // jsonb keeps an object's keys in its own order, so the keys are compared as a set.
        self::assertEquals(
            [
                ['media_id' => $first, 'title' => 'Case B', 'summary' => 'The total shows twice the sum.', 'pins' => [['x_pct' => 10.5, 'y_pct' => 20.0, 'note' => 'Total']]],
                ['media_id' => $second, 'title' => null, 'summary' => null, 'pins' => []],
            ],
            json_decode($drafts[0][5], true, flags: \JSON_THROW_ON_ERROR),
        );
        self::assertSame([[$first, 'pending'], [$second, 'pending']], $this->rowsOf('SELECT id, status FROM med_object ORDER BY id'), 'The uploads are finished; the worker processes them.');
        self::assertSame([], $this->rowsOf('SELECT id FROM tkt_ticket'));
        self::assertSame([['token', $tokenId, 'project', $this->shopProject, CreateReport::NAME, 'ok']], $this->toolRows());
        $this->assertNoAuditRowHolds(['Total is doubled', 'Case B', 'twice the sum']);
    }

    /**
     * ADR-0039: reports:write is not among the scopes a token made for Claude Code starts with.
     */
    #[Test]
    public function aTokenWithoutReportsWriteIsRefusedByBothToolsAndNothingIsMade(): void
    {
        [, $token] = $this->issueApiToken($this->people['Mia'], $this->acmeFoods);
        $upload = $this->addIntent('Mia', 'new_report', 'SHOP');

        $this->callTool($token, CreateReportUpload::NAME, ['project' => 'SHOP', 'mime' => 'image/png', 'bytes' => 245_760, 'sha256' => self::SHA256]);
        $this->assertRefused(\sprintf(ToolRefusal::MISSING_SCOPE, 'reports:write'));
        $this->callTool($token, CreateReport::NAME, ['project' => 'SHOP', 'title' => 'Total is doubled', 'screenshots' => [['upload_id' => $upload]]]);
        $this->assertRefused(\sprintf(ToolRefusal::MISSING_SCOPE, 'reports:write'));

        self::assertSame([], $this->rowsOf('SELECT id FROM rvw_report_draft'));
        self::assertSame([[$upload, null]], $this->rowsOf('SELECT id, used_at FROM med_upload_intent'));
    }

    #[Test]
    public function anUnknownKeyAProjectNoTeamOwnsAndAnotherOrganizationsProjectAreNotFound(): void
    {
        [, $token] = $this->issueApiToken($this->people['Mia'], $this->acmeFoods, scopes: [ApiTokenScope::ReportsWrite]);
        $betaBakery = $this->createOrganization('Beta Bakery');
        $this->addProject($this->createTeam($betaBakery, 'Bakery', $this->people['Sara']), 'BAKE', 'Bakery', $betaBakery);

        foreach (['NOPE', 'LOOSE', 'BAKE', 'shop'] as $key) {
            $this->callTool($token, CreateReport::NAME, ['project' => $key, 'title' => 'Total is doubled']);
            $this->assertRefused(ToolRefusal::NOT_FOUND);
            $this->callTool($token, CreateReportUpload::NAME, ['project' => $key, 'mime' => 'image/png', 'bytes' => 245_760, 'sha256' => self::SHA256]);
            $this->assertRefused(ToolRefusal::NOT_FOUND);
        }

        self::assertSame([], $this->rowsOf('SELECT id FROM rvw_report_draft'));
        self::assertSame([], $this->rowsOf('SELECT id FROM med_upload_intent'));
    }

    #[Test]
    public function anUploadBegunForAnotherTargetOrByAnotherPersonIsNotFoundAndLeftUnused(): void
    {
        [, $token] = $this->issueApiToken($this->people['Mia'], $this->acmeFoods, scopes: [ApiTokenScope::ReportsWrite]);
        $otherProjects = $this->addIntent('Mia', 'new_report', 'MOBILE');
        $aRounds = $this->addIntent('Mia', 'round', Uuid::v7()->toRfc4122());
        $omars = $this->addIntent('Omar', 'new_report', 'SHOP');

        foreach ([$otherProjects, $aRounds, $omars, 'not-an-id'] as $upload) {
            $this->callTool($token, CreateReport::NAME, ['project' => 'SHOP', 'title' => 'Total is doubled', 'screenshots' => [['upload_id' => $upload]]]);
            $this->assertRefused(ToolRefusal::NOT_FOUND);
        }

        self::assertSame([], $this->rowsOf('SELECT id FROM rvw_report_draft'));
        self::assertSame([], $this->rowsOf('SELECT id FROM med_upload_intent WHERE used_at IS NOT NULL'));
    }

    #[Test]
    public function contentReportDraftsRefusesIsAPlainRefusalAndTheUploadStaysUnused(): void
    {
        [, $token] = $this->issueApiToken($this->people['Mia'], $this->acmeFoods, scopes: [ApiTokenScope::ReportsWrite]);
        $upload = $this->addIntent('Mia', 'new_report', 'SHOP');

        $this->callTool($token, CreateReport::NAME, ['project' => 'SHOP', 'title' => " \n ", 'screenshots' => [['upload_id' => $upload]]]);

        $this->assertRefused(ReportDraftRefused::TITLE_BLANK);
        self::assertSame([], $this->rowsOf('SELECT id FROM rvw_report_draft'));
        self::assertSame([[$upload, null]], $this->rowsOf('SELECT id, used_at FROM med_upload_intent'), 'The savepoint takes the finished upload back.');
    }

    private function addIntent(string $person, string $targetKind, string $targetId): string
    {
        $intentId = Uuid::v7()->toRfc4122();
        $parameters = [$this->acmeFoods->toString(), $intentId, $this->people[$person]->id()->toString(), $targetKind, $targetId, \sprintf('uploads/%s/%s', $this->acmeFoods->toString(), $intentId)];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO med_upload_intent (organization_id, id, user_id, purpose, target_kind, target_id, max_bytes, mime_allowed, storage_key, expires_at, used_at)
             VALUES (?, ?, ?, 'screenshot', ?, ?, 245760, '{image/png}', ?, TIMESTAMPTZ '2026-11-11 09:00:00+00', NULL)",
            $parameters,
        ));

        return $intentId;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function callTool(string $token, string $name, array $arguments): void
    {
        $this->postJsonRpc($token, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => self::PROTOCOL_VERSION, 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'claude-code', 'version' => '1.0.0']],
        ]);
        self::assertResponseStatusCodeSame(200);
        $sessionId = $this->client->getResponse()->headers->get('Mcp-Session-Id');
        self::assertIsString($sessionId);
        $this->postJsonRpc($token, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $sessionId);
        self::assertResponseStatusCodeSame(202);

        $this->postJsonRpc($token, ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $arguments]], $sessionId);
    }

    /**
     * @param array<string, mixed> $message
     */
    private function postJsonRpc(string $token, array $message, ?string $sessionId = null): void
    {
        $sessionHeaders = null === $sessionId ? [] : ['HTTP_MCP_SESSION_ID' => $sessionId, 'HTTP_MCP_PROTOCOL_VERSION' => self::PROTOCOL_VERSION];
        $this->client->request('POST', self::MCP, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            ...$sessionHeaders,
        ], content: json_encode($message, \JSON_THROW_ON_ERROR));
    }

    private function assertRefused(string $message): void
    {
        self::assertResponseStatusCodeSame(200);
        self::assertTrue($this->responseValueAt('result', 'isError'));
        self::assertSame([['type' => 'text', 'text' => $message]], $this->responseValueAt('result', 'content'));
    }

    /**
     * @return list<list<mixed>> Acme Foods' rows of $sql, read by the migrator
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

    /**
     * @return list<list<mixed>> each tool call's actor kind, token, target type and id, tool and outcome
     */
    private function toolRows(): array
    {
        return array_map(static function (array $row): array {
            self::assertIsString($row[4]);
            $data = json_decode($row[4], true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($data);

            return [$row[0], $row[1], $row[2], $row[3], $data['tool'] ?? null, $data['outcome'] ?? null];
        }, $this->rowsOf(\sprintf("SELECT actor_kind, actor_token_id, target_type, target_id, data::text FROM aud_event WHERE action = '%s' ORDER BY at, id", ToolCallAuditor::ACTION)));
    }

    /**
     * @param list<string> $texts
     */
    private function assertNoAuditRowHolds(array $texts): void
    {
        $rowTexts = array_column($this->rowsOf('SELECT e::text FROM aud_event e'), 0);
        self::assertNotSame([], $rowTexts);
        foreach ($rowTexts as $rowText) {
            self::assertIsString($rowText);
            foreach ($texts as $text) {
                self::assertStringNotContainsString($text, $rowText);
            }
        }
    }

    /**
     * @return mixed null when the last key is missing; fails when a key before it does not hold an array
     */
    private function responseValueAt(string ...$path): mixed
    {
        $value = json_decode($this->client->getInternalResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        foreach ($path as $key) {
            self::assertIsArray($value, \sprintf('no %s in the response', implode('.', $path)));
            $value = $value[$key] ?? null;
        }

        return $value;
    }
}
