<?php

declare(strict_types=1);

namespace App\Tests\Functional\AgentApi\Tool;

use App\AgentApi\Infrastructure\Mcp\ToolCallAuditor;
use App\Identity\Domain\User;
use App\SharedKernel\Domain\OrganizationId;
use Doctrine\DBAL\Connection;

/**
 * The notebook tools' tests through POST /mcp: a session started as Claude Code starts it, notebooks seeded straight
 * into rvw_notebook as an agent leaves them, and their rows and audit rows read back by the migrator. Next to
 * LogsInUsers and CreatesAcmeFoodsTeams, whose committedAs and Acme Foods it uses; the class sets $client.
 */
trait CallsNotebookTools
{
    /**
     * Starts a session, then calls the tool in it.
     *
     * @param array<string, mixed> $arguments sent as they are, so a test can send what the schema or the rules refuse
     */
    private function callTool(string $token, string $name, array $arguments): void
    {
        $this->postJsonRpc($token, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'claude-code', 'version' => '1.0.0']],
        ]);
        self::assertResponseStatusCodeSame(200);
        $sessionId = $this->client->getResponse()->headers->get('Mcp-Session-Id');
        self::assertIsString($sessionId);
        $this->postJsonRpc($token, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $sessionId);
        self::assertResponseStatusCodeSame(202);

        $this->postJsonRpc($token, ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => [] === $arguments ? new \stdClass() : $arguments]], $sessionId);
    }

    /**
     * @param array<string, mixed> $message
     */
    private function postJsonRpc(string $token, array $message, ?string $sessionId = null): void
    {
        $sessionHeaders = null === $sessionId ? [] : ['HTTP_MCP_SESSION_ID' => $sessionId, 'HTTP_MCP_PROTOCOL_VERSION' => '2025-06-18'];
        $this->client->request('POST', '/mcp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            ...$sessionHeaders,
        ], content: json_encode($message, \JSON_THROW_ON_ERROR));
    }

    /**
     * A tool error result with exactly $message and no structured answer.
     */
    private function assertRefused(string $message): void
    {
        self::assertResponseStatusCodeSame(200);
        self::assertTrue($this->responseValueAt('result', 'isError'));
        self::assertSame([['type' => 'text', 'text' => $message]], $this->responseValueAt('result', 'content'));
        self::assertNull($this->responseValueAt('result', 'structuredContent'));
    }

    /**
     * The answer's structured content, after checking the call was no error and its text block carries the same JSON.
     *
     * @return array<mixed>
     */
    private function answer(): array
    {
        self::assertResponseStatusCodeSame(200);
        self::assertFalse($this->responseValueAt('result', 'isError'));
        $answer = $this->responseValueAt('result', 'structuredContent');
        self::assertIsArray($answer);
        $text = $this->responseValueAt('result', 'content', 0, 'text');
        self::assertIsString($text);
        self::assertSame($answer, json_decode($text, true, flags: \JSON_THROW_ON_ERROR));

        return $answer;
    }

    /**
     * @return mixed null when the last key is missing; fails when a key before it does not hold an array
     */
    private function responseValueAt(string|int ...$path): mixed
    {
        $value = json_decode($this->client->getInternalResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        foreach ($path as $key) {
            self::assertIsArray($value, \sprintf('no %s in the response', implode('.', $path)));
            $value = $value[$key] ?? null;
        }

        return $value;
    }

    /**
     * @param list<array<string, mixed>> $screenshots {media_id, title, summary, pins: [{x_pct, y_pct, note}]} each
     * @param list<array<string, mixed>> $scenarios   in scenario.v1.json's format
     */
    private function addNotebook(OrganizationId $organizationId, User $owner, string $notebookId, string $title, string $lastUsedAt, array $screenshots = [], array $scenarios = [], int $version = 1): void
    {
        $parameters = [
            $organizationId->toString(), $notebookId, $owner->id()->toString(), $title,
            json_encode($screenshots, \JSON_THROW_ON_ERROR), json_encode($scenarios, \JSON_THROW_ON_ERROR), $version, $lastUsedAt, $lastUsedAt,
        ];
        $this->committedAs($organizationId, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO rvw_notebook (organization_id, id, owner_id, title, screenshots, scenarios, version, created_at, updated_at, last_used_at)
             VALUES (?, ?, ?, ?, CAST(? AS jsonb), CAST(? AS jsonb), ?, '2026-11-01 08:00:00+00', ?, ?)",
            $parameters,
        ));
    }

    /**
     * @return array{scenarios: mixed, version: mixed, updated_at: mixed, last_used_at: mixed} the scenarios decoded,
     *                                                                                         the times in UTC to the
     *                                                                                         microsecond
     */
    private function notebookRowOf(OrganizationId $organizationId, string $notebookId): array
    {
        $row = false;
        $this->committedAs($organizationId, static function (Connection $migrator) use ($notebookId, &$row): int {
            $row = $migrator->fetchAssociative(
                "SELECT scenarios::text AS scenarios, version, to_char(updated_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS.US') AS updated_at,"
                ." to_char(last_used_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS.US') AS last_used_at FROM rvw_notebook WHERE id = ?",
                [$notebookId],
            );

            return 0;
        });
        self::assertIsArray($row);
        self::assertIsString($row['scenarios']);

        return [
            'scenarios' => json_decode($row['scenarios'], true, flags: \JSON_THROW_ON_ERROR),
            'version' => $row['version'],
            'updated_at' => $row['updated_at'],
            'last_used_at' => $row['last_used_at'],
        ];
    }

    /**
     * @return list<list<mixed>> each tool call's token, target type and id, tool and outcome, oldest first
     */
    private function toolRowsOf(OrganizationId $organizationId): array
    {
        $rows = [];
        $this->committedAs($organizationId, static function (Connection $migrator) use (&$rows): int {
            $rows = $migrator->fetchAllNumeric(\sprintf("SELECT actor_token_id, target_type, target_id, data::text FROM aud_event WHERE action = '%s' ORDER BY at, id", ToolCallAuditor::ACTION));

            return 0;
        });

        return array_map(static function (array $row): array {
            self::assertIsString($row[3]);
            $data = json_decode($row[3], true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($data);

            return [$row[0], $row[1], $row[2], $data['tool'] ?? null, $data['outcome'] ?? null];
        }, $rows);
    }

    /**
     * Through the migrator, before LogsInUsers' tearDown deletes the organizations.
     */
    private function removeNotebooksAndAuditRows(): void
    {
        foreach ($this->createdOrganizationIds as $organizationId) {
            $this->committedAs(new OrganizationId($organizationId), static function (Connection $migrator) use ($organizationId): int {
                foreach (['aud_event', 'rvw_notebook'] as $table) {
                    $migrator->executeStatement(\sprintf('DELETE FROM %s WHERE organization_id = ?', $table), [$organizationId]);
                }

                return 0;
            });
        }
    }
}
