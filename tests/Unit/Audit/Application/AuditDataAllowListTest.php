<?php

declare(strict_types=1);

namespace App\Tests\Unit\Audit\Application;

use App\Audit\Application\AuditDataAllowList;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AuditDataAllowListTest extends TestCase
{
    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function allowedData(): iterable
    {
        yield 'the listed keys in another order' => ['team_role.changed', ['to' => 'viewer', 'team_id' => '0197a3c0-0000-7000-8000-0000000004c1', 'from' => null]];
        yield 'no data for an action that lists none' => ['member.invited', []];
        yield 'a tool call' => ['mcp.tool_called', ['outcome' => 'ok', 'tool' => 'whoami']];
        yield 'a token issued' => ['api_token.issued', ['expires_at' => '2027-04-14T10:00:00+00:00', 'scopes' => ['read']]];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[Test]
    #[DataProvider('allowedData')]
    public function dataWithExactlyTheListedKeysPasses(string $action, array $data): void
    {
        $this->expectNotToPerformAssertions();

        AuditDataAllowList::refuseUnlessAllowed($action, $data);
    }

    /**
     * @return iterable<string, array{string, array<array-key, mixed>, string}>
     */
    public static function refusedData(): iterable
    {
        yield 'a key outside the list' => ['member.invited', ['email' => 'mia@acme-foods.example'], 'The data of the audit action "member.invited" must hold exactly the keys .'];
        yield 'a listed key missing' => ['team.created', [], 'The data of the audit action "team.created" must hold exactly the keys lead_user_id.'];
        yield 'the token name' => ['api_token.issued', ['scopes' => ['read'], 'expires_at' => '2027-04-14T10:00:00+00:00', 'name' => 'Claude Code'], 'The data of the audit action "api_token.issued" must hold exactly the keys scopes, expires_at.'];
        yield 'the token prefix' => ['api_token.revoked', ['prefix' => 'pr_a1b2c3d4'], 'The data of the audit action "api_token.revoked" must hold exactly the keys .'];
        yield 'a tool call with its input' => ['mcp.tool_called', ['tool' => 'whoami', 'outcome' => 'ok', 'arguments' => 'SHOP-1'], 'The data of the audit action "mcp.tool_called" must hold exactly the keys tool, outcome.'];
        yield 'list-shaped data' => ['local_ai.result_refused', [0 => 'expired', 1 => null], 'The data of the audit action "local_ai.result_refused" must hold exactly the keys reason, model.'];
        yield 'an action with no list' => ['team.renamed', [], 'The audit action "team.renamed" has no data allow-list.'];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    #[Test]
    #[DataProvider('refusedData')]
    public function dataThatIsNotExactlyTheActionsListIsRefused(string $action, array $data, string $expectedMessage): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains($expectedMessage);

        AuditDataAllowList::refuseUnlessAllowed($action, $data);
    }
}
