<?php

declare(strict_types=1);

namespace App\Tests\Unit\AgentApi\Infrastructure\Mcp;

use App\AgentApi\Infrastructure\Mcp\ToolCallAuditor;
use App\AgentApi\Infrastructure\Mcp\ToolCallOutcome;
use App\AgentApi\Infrastructure\Mcp\ToolTarget;
use App\Audit\Api\ActorKind;
use App\Identity\Api\ApiTokenAccess;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The audit row of an MCP tool call: actor kind token with the token's id and its user, the action mcp.tool_called,
 * the target or the mcp_tool type, and as data exactly the tool and the outcome.
 */
final class ToolCallAuditorTest extends TestCase
{
    private const string ROUND = '019a5f00-0000-7000-8000-00000000f001';

    #[Test]
    public function aRowWithATargetNamesItAndHoldsOnlyTheToolAndTheOutcome(): void
    {
        $auditLog = new RecordingAuditLog(new OrganizationId(GateFixture::ACME_FOODS));
        $target = new ToolTarget('ROUND_VIEW', new \stdClass(), 'round', self::ROUND);

        new ToolCallAuditor($auditLog)->record($this->omarsAccess(), new UserId(GateFixture::OMAR), 'get_round_notes', $target, ToolCallOutcome::ReadOnly);

        self::assertCount(1, $auditLog->entries);
        $entry = $auditLog->entries[0];
        self::assertSame(ActorKind::Token, $entry->actorKind);
        self::assertSame(GateFixture::OMAR, $entry->actorUserId);
        self::assertSame(GateFixture::OMARS_TOKEN, $entry->actorTokenId);
        self::assertSame('mcp.tool_called', $entry->action);
        self::assertSame(['round', self::ROUND], [$entry->targetType, $entry->targetId]);
        self::assertSame(['tool' => 'get_round_notes', 'outcome' => 'read_only'], $entry->data);
        self::assertSame([null, null], [$entry->ipHash, $entry->userAgentHash]);
    }

    #[Test]
    public function aRowWithoutATargetHasTheToolTargetTypeAndNoId(): void
    {
        $auditLog = new RecordingAuditLog(new OrganizationId(GateFixture::ACME_FOODS));

        new ToolCallAuditor($auditLog)->record($this->omarsAccess(), new UserId(GateFixture::OMAR), 'whoami', null, ToolCallOutcome::Ok);

        self::assertCount(1, $auditLog->entries);
        self::assertSame(['mcp_tool', null], [$auditLog->entries[0]->targetType, $auditLog->entries[0]->targetId]);
        self::assertSame(['tool' => 'whoami', 'outcome' => 'ok'], $auditLog->entries[0]->data);
    }

    private function omarsAccess(): ApiTokenAccess
    {
        return new ApiTokenAccess(GateFixture::OMARS_TOKEN, new OrganizationId(GateFixture::ACME_FOODS), ['read']);
    }
}
