<?php

declare(strict_types=1);

namespace App\Tests\Functional\AgentApi\Prompt;

use App\AgentApi\Infrastructure\Mcp\ToolCallAuditor;
use App\Identity\Domain\ApiToken;
use App\Identity\Domain\ApiTokenHashing;
use App\Identity\Domain\ApiTokenScope;
use App\Identity\Domain\User;
use App\SharedKernel\Domain\OrganizationId;
use Doctrine\DBAL\Connection;

/**
 * The MCP prompts' tests through POST /mcp: Omar's token in Acme Foods, a session started as Claude Code starts it, a
 * prompts/get, and the audit rows read back by the migrator. Next to IssuesApiTokens and LogsInUsers, whose tokens,
 * organizations and committedAs it uses; the class sets $client and freezes the clock at 2026-11-10 09:00:20 UTC.
 */
trait CallsMcpPrompts
{
    /**
     * @param list<ApiTokenScope> $scopes
     *
     * @return array{OrganizationId, string, string, User} Acme Foods, Omar's token id, its plain text and Omar
     */
    private function omarsTokenInAcmeFoods(array $scopes): array
    {
        $omar = $this->createUserWithoutOrganization('Omar Haddad');
        $acmeFoods = $this->createOrganization('Acme Foods');
        $this->addMembership($omar, $acmeFoods);
        $hashing = self::getContainer()->get('test.identity_api_token_hashing');
        \assert($hashing instanceof ApiTokenHashing);
        $issued = ApiToken::issue($this->apiTokens()->nextIdentity(), $acmeFoods, $omar->id(), 'Claude Code', $scopes, $hashing, new \DateTimeImmutable(self::LIVE_TOKEN_ISSUED_AT), ApiToken::DEFAULT_LIFETIME_DAYS);
        $this->inTokenTransactionAs($acmeFoods, fn () => $this->apiTokens()->add($issued->token()));

        return [$acmeFoods, $issued->token()->id(), $issued->reveal(), $omar];
    }

    /**
     * @return string the Mcp-Session-Id
     */
    private function startSession(string $token): string
    {
        $this->postJsonRpc($token, self::initializeMessage());
        self::assertResponseStatusCodeSame(200);

        return $this->confirmSession($token);
    }

    /**
     * Sends notifications/initialized for the session the last initialize started.
     *
     * @return string the Mcp-Session-Id
     */
    private function confirmSession(string $token): string
    {
        $sessionId = $this->client->getResponse()->headers->get('Mcp-Session-Id');
        self::assertIsString($sessionId);
        $this->postJsonRpc($token, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $sessionId);
        self::assertResponseStatusCodeSame(202);

        return $sessionId;
    }

    /**
     * @param ?array<string, string> $arguments left out of the request when null
     */
    private function getPrompt(string $token, string $sessionId, string $name, ?array $arguments = null): void
    {
        $params = null === $arguments ? ['name' => $name] : ['name' => $name, 'arguments' => $arguments];
        $this->postJsonRpc($token, ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'prompts/get', 'params' => $params], $sessionId);
    }

    /**
     * @return string the text of the prompt file, read straight from disk
     */
    private static function promptFileText(string $version): string
    {
        $text = file_get_contents(__DIR__.'/../../../../src/AgentApi/Infrastructure/Prompt/'.$version.'.md');
        self::assertIsString($text);

        return $text;
    }

    /**
     * @return array{string, string, string, string, string, null, string, array<string, string>}
     */
    private function promptRow(User $user, string $tokenId, string $promptName, string $outcome): array
    {
        return ['token', $user->id()->toString(), $tokenId, ToolCallAuditor::ACTION, ToolCallAuditor::PROMPT_TARGET_TYPE, null, '2026-11-10 09:00:20', ['outcome' => $outcome, 'tool' => $promptName]];
    }

    /**
     * @return list<array{mixed, mixed, mixed, mixed, mixed, mixed, mixed, array<mixed>}> actor kind, user, token,
     *                                                                                    action, target type, target,
     *                                                                                    time, data by key
     */
    private function auditRowsOf(OrganizationId $organizationId): array
    {
        $rows = [];
        $this->committedAs($organizationId, static function (Connection $migrator) use ($organizationId, &$rows): int {
            $rows = $migrator->fetchAllNumeric(
                "SELECT actor_kind, actor_user_id, actor_token_id, action, target_type, target_id, to_char(at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS'), data::text FROM aud_event WHERE organization_id = ? ORDER BY at, id",
                [$organizationId->toString()],
            );

            return 0;
        });

        return array_map(static function (array $row): array {
            self::assertIsString($row[7]);
            $data = json_decode($row[7], true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($data);
            ksort($data);

            return [$row[0], $row[1], $row[2], $row[3], $row[4], $row[5], $row[6], $data];
        }, $rows);
    }

    /**
     * Every column of every row, as text, holds none of $secrets.
     *
     * @param list<string> $secrets
     */
    private function assertNoAuditRowHolds(OrganizationId $organizationId, array $secrets): void
    {
        $rowTexts = [];
        $this->committedAs($organizationId, static function (Connection $migrator) use ($organizationId, &$rowTexts): int {
            $rowTexts = $migrator->fetchFirstColumn('SELECT e::text FROM aud_event e WHERE organization_id = ?', [$organizationId->toString()]);

            return 0;
        });
        self::assertNotSame([], $rowTexts);
        foreach ($rowTexts as $rowText) {
            self::assertIsString($rowText);
            foreach ($secrets as $secret) {
                self::assertStringNotContainsString($secret, $rowText);
            }
        }
    }

    private function removeAuditRows(): void
    {
        foreach ($this->createdOrganizationIds as $organizationId) {
            $this->committedAs(new OrganizationId($organizationId), static fn (Connection $migrator): int|string => $migrator->executeStatement('DELETE FROM aud_event WHERE organization_id = ?', [$organizationId]));
        }
    }

    /**
     * @param array<string, mixed> $message
     */
    private function postJsonRpc(?string $token, array $message, ?string $sessionId = null): void
    {
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream'];
        if (null !== $token) {
            $headers['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }
        if (null !== $sessionId) {
            $headers['HTTP_MCP_SESSION_ID'] = $sessionId;
            $headers['HTTP_MCP_PROTOCOL_VERSION'] = '2025-06-18';
        }
        $this->client->request('POST', '/mcp', server: $headers, content: json_encode($message, \JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private static function initializeMessage(): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'claude-code', 'version' => '1.0.0']],
        ];
    }

    /**
     * @return array<mixed>
     */
    private function responseJson(): array
    {
        $json = json_decode($this->client->getInternalResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($json);

        return $json;
    }

    /**
     * @return mixed null when the last key is missing; fails when a key before it does not hold an array
     */
    private function responseValueAt(string ...$path): mixed
    {
        $value = $this->responseJson();
        foreach ($path as $key) {
            self::assertIsArray($value, \sprintf('no %s in the response', implode('.', $path)));
            $value = $value[$key] ?? null;
        }

        return $value;
    }
}
