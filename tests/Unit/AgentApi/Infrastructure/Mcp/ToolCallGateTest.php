<?php

declare(strict_types=1);

namespace App\Tests\Unit\AgentApi\Infrastructure\Mcp;

use App\AgentApi\Infrastructure\Mcp\ToolCallAuditor;
use App\AgentApi\Infrastructure\Mcp\ToolCallOutcome;
use App\AgentApi\Infrastructure\Mcp\ToolRefusal;
use App\AgentApi\Infrastructure\Mcp\ToolTarget;
use App\Audit\Api\ActorKind;
use App\Audit\Api\AuditEntry;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ToolCallGate on a ticket-shaped tool: each check refuses before the steps after it run, a voter denial reads as not
 * found, and every call that reaches the gate leaves exactly one audit row with the tool and the outcome only.
 */
final class ToolCallGateTest extends TestCase
{
    private const string SHOP_42 = '019a5f00-0000-7000-8000-00000000e042';
    private const string TICKET_VIEW = 'TICKET_VIEW';

    #[Test]
    public function aTokenWithoutTheToolsScopeIsRefusedBeforeAnythingElseRuns(): void
    {
        $fixture = new GateFixture(scopes: ['comments:write'], grants: [[self::TICKET_VIEW, self::SHOP_42]]);
        $tool = $this->toolOnShop42('read');

        $refusal = $this->refusalOf(static fn () => $fixture->gate->call($tool, ['ticket' => 'SHOP-42']));

        self::assertSame(ToolCallOutcome::MissingScope, $refusal->outcome);
        self::assertSame(\sprintf(ToolRefusal::MISSING_SCOPE, 'read'), $refusal->getMessage());
        self::assertSame([0, 0], [$tool->targetLookups, $tool->calls]);
        self::assertSame([], $fixture->voterQuestions);
        $this->assertOneRow($fixture, 'missing_scope', ToolCallAuditor::TOOL_TARGET_TYPE, null);
    }

    #[Test]
    public function argumentsThatBreakTheInputSchemaAreRefusedAsInvalidInputBeforeTheTargetIsLookedUp(): void
    {
        $invalidArguments = [
            'missing ticket' => [],
            'ticket of the wrong type' => ['ticket' => 42],
            'ticket not a key' => ['ticket' => "SHOP-42\n"],
            'an argument the schema does not have' => ['ticket' => 'SHOP-42', 'note' => 'more'],
        ];
        foreach ($invalidArguments as $case => $arguments) {
            $fixture = new GateFixture(grants: [[self::TICKET_VIEW, self::SHOP_42]]);
            $tool = $this->toolOnShop42('read');

            $refusal = $this->refusalOf(static fn () => $fixture->gate->call($tool, $arguments));

            self::assertSame(ToolCallOutcome::InvalidInput, $refusal->outcome, $case);
            self::assertStringStartsWith('The arguments do not match the input schema of this tool: ', $refusal->getMessage(), $case);
            self::assertSame([0, 0], [$tool->targetLookups, $tool->calls], $case);
            $this->assertOneRow($fixture, 'invalid_input', ToolCallAuditor::TOOL_TARGET_TYPE, null);
        }
    }

    #[Test]
    public function anEmptyArgumentObjectPassesASchemaThatRequiresNothing(): void
    {
        $fixture = new GateFixture();
        $tool = new ScriptedTool('read', static fn (): ?ToolTarget => null, inputSchema: ['type' => 'object', 'properties' => [], 'additionalProperties' => false]);

        $fixture->gate->call($tool, []);

        self::assertSame(1, $tool->calls);
        $this->assertOneRow($fixture, 'ok', ToolCallAuditor::TOOL_TARGET_TYPE, null);
    }

    #[Test]
    public function aTargetTheVoterDeniesIsNotFoundAndTheToolDoesNotRun(): void
    {
        $fixture = new GateFixture(grants: [[self::TICKET_VIEW, 'another ticket']]);
        $tool = $this->toolOnShop42('read');

        $refusal = $this->refusalOf(static fn () => $fixture->gate->call($tool, ['ticket' => 'SHOP-42']));

        self::assertSame(ToolCallOutcome::NotFound, $refusal->outcome);
        self::assertSame(ToolRefusal::NOT_FOUND, $refusal->getMessage());
        self::assertSame([[self::TICKET_VIEW, self::SHOP_42]], $fixture->voterQuestions);
        self::assertSame([1, 0], [$tool->targetLookups, $tool->calls]);
        $this->assertOneRow($fixture, 'not_found', 'ticket', self::SHOP_42);
    }

    #[Test]
    public function aTargetTheToolCannotFindIsNotFoundWithNoTargetInTheRow(): void
    {
        $fixture = new GateFixture(grants: [[self::TICKET_VIEW, self::SHOP_42]]);
        $tool = new ScriptedTool('read', static fn (): ToolTarget => throw ToolRefusal::notFound());

        $refusal = $this->refusalOf(static fn () => $fixture->gate->call($tool, ['ticket' => 'SHOP-404']));

        self::assertSame(ToolCallOutcome::NotFound, $refusal->outcome);
        self::assertSame([], $fixture->voterQuestions);
        self::assertSame(0, $tool->calls);
        $this->assertOneRow($fixture, 'not_found', ToolCallAuditor::TOOL_TARGET_TYPE, null);
    }

    #[Test]
    public function theToolsOwnReadOnlyRefusalIsAuditedWithItsTarget(): void
    {
        $fixture = new GateFixture(scopes: ['read', 'rounds:write'], grants: [[self::TICKET_VIEW, self::SHOP_42]]);
        $tool = $this->toolOnShop42('rounds:write', static fn (): CallToolResult => throw ToolRefusal::readOnly());

        $refusal = $this->refusalOf(static fn () => $fixture->gate->call($tool, ['ticket' => 'SHOP-42']));

        self::assertSame(ToolCallOutcome::ReadOnly, $refusal->outcome);
        self::assertSame(ToolRefusal::READ_ONLY, $refusal->getMessage());
        self::assertSame(1, $tool->calls);
        $this->assertOneRow($fixture, 'read_only', 'ticket', self::SHOP_42);
    }

    #[Test]
    public function aGrantedCallReturnsTheToolsResultAndWritesAnOkRowWithTheTarget(): void
    {
        $fixture = new GateFixture(grants: [[self::TICKET_VIEW, self::SHOP_42]]);
        $answer = new CallToolResult([new TextContent('the notes of SHOP-42')]);
        $seenTarget = null;
        $tool = $this->toolOnShop42('read', static function (array $arguments, ?ToolTarget $target) use ($answer, &$seenTarget): CallToolResult {
            $seenTarget = $target;

            return $answer;
        });

        self::assertSame($answer, $fixture->gate->call($tool, ['ticket' => 'SHOP-42']));

        self::assertNotNull($seenTarget);
        self::assertSame(self::SHOP_42, $seenTarget->auditId);
        self::assertSame([[self::TICKET_VIEW, self::SHOP_42]], $fixture->voterQuestions);
        $this->assertOneRow($fixture, 'ok', 'ticket', self::SHOP_42);
    }

    #[Test]
    public function aToolWithoutATargetAsksNoVoter(): void
    {
        $fixture = new GateFixture();
        $tool = new ScriptedTool('read', static fn (): ?ToolTarget => null);

        $fixture->gate->call($tool, ['ticket' => 'SHOP-42']);

        self::assertSame([], $fixture->voterQuestions);
        $this->assertOneRow($fixture, 'ok', ToolCallAuditor::TOOL_TARGET_TYPE, null);
    }

    #[Test]
    public function anUnexpectedFailureGoesOnUnchangedAfterAnErrorRow(): void
    {
        $fixture = new GateFixture(grants: [[self::TICKET_VIEW, self::SHOP_42]]);
        $failure = new \RuntimeException('connection lost while reading SHOP-42');
        $tool = $this->toolOnShop42('read', static fn (): CallToolResult => throw $failure);

        try {
            $fixture->gate->call($tool, ['ticket' => 'SHOP-42']);
            self::fail('the failure did not go on');
        } catch (\RuntimeException $thrown) {
            self::assertSame($failure, $thrown);
        }

        $this->assertOneRow($fixture, 'error', 'ticket', self::SHOP_42);
        self::assertSame([], $fixture->logger->records);
    }

    #[Test]
    public function aFailureWhoseRowCannotBeWrittenStillGoesOnAndLogsOnlyTheAuditFailuresClass(): void
    {
        $fixture = new GateFixture(grants: [[self::TICKET_VIEW, self::SHOP_42]], auditFailure: new \LogicException('no transaction holds SHOP-42'));
        $failure = new \RuntimeException('connection lost');
        $tool = $this->toolOnShop42('read', static fn (): CallToolResult => throw $failure);

        try {
            $fixture->gate->call($tool, ['ticket' => 'SHOP-42']);
            self::fail('the failure did not go on');
        } catch (\RuntimeException $thrown) {
            self::assertSame($failure, $thrown);
        }

        self::assertSame(
            [['level' => 'warning', 'message' => 'The audit row of a failed MCP tool call could not be written.', 'context' => ['tool' => ScriptedTool::NAME, 'exception_class' => \LogicException::class]]],
            $fixture->logger->records,
        );
    }

    #[Test]
    public function aRequestWithoutATokenFailsWithoutARowOrAToolRun(): void
    {
        $fixture = new GateFixture(hasToken: false);
        $tool = $this->toolOnShop42('read');

        try {
            $fixture->gate->call($tool, ['ticket' => 'SHOP-42']);
            self::fail('a call without a token ran');
        } catch (\LogicException $failure) {
            self::assertStringContainsString('without a personal access token', $failure->getMessage());
        }

        self::assertSame([0, 0], [$tool->targetLookups, $tool->calls]);
        self::assertSame([], $fixture->auditLog->entries);
    }

    /**
     * @param (\Closure(array<string, mixed>, ?ToolTarget): CallToolResult)|null $run
     */
    private function toolOnShop42(string $scope, ?\Closure $run = null): ScriptedTool
    {
        return new ScriptedTool($scope, static fn (): ToolTarget => new ToolTarget(self::TICKET_VIEW, self::SHOP_42, 'ticket', self::SHOP_42), $run);
    }

    /**
     * @param \Closure(): mixed $call
     */
    private function refusalOf(\Closure $call): ToolRefusal
    {
        try {
            $call();
        } catch (ToolRefusal $refusal) {
            return $refusal;
        }
        self::fail('the call was not refused');
    }

    private function assertOneRow(GateFixture $fixture, string $outcome, string $targetType, ?string $targetId): void
    {
        self::assertCount(1, $fixture->auditLog->entries);
        $entry = $fixture->auditLog->entries[0];
        self::assertSame(
            [GateFixture::ACME_FOODS, ActorKind::Token, GateFixture::OMAR, GateFixture::OMARS_TOKEN, ToolCallAuditor::ACTION, $targetType, $targetId, ['tool' => ScriptedTool::NAME, 'outcome' => $outcome]],
            self::rowOf($entry),
        );
    }

    /**
     * @return list<mixed>
     */
    private static function rowOf(AuditEntry $entry): array
    {
        return [$entry->organizationId->toString(), $entry->actorKind, $entry->actorUserId, $entry->actorTokenId, $entry->action, $entry->targetType, $entry->targetId, $entry->data];
    }
}
