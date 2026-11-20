<?php

declare(strict_types=1);

namespace App\Tests\Unit\AgentApi\Infrastructure\Mcp;

use App\AgentApi\Infrastructure\Mcp\ScreenshotContextArguments;
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
    private const string OMARS_DRAFT = '019a5f00-0000-7000-8000-00000000e741';
    private const string ROUND_EDIT = 'ROUND_EDIT';

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
        $tool = new ScriptedOwnIdentityTool(['type' => 'object', 'properties' => [], 'additionalProperties' => false]);

        $fixture->gate->call($tool, []);

        self::assertSame(1, $tool->calls);
        $this->assertOneRow($fixture, 'ok', ToolCallAuditor::TOOL_TARGET_TYPE, null);
    }

    /**
     * The summary is left out, so the reason names it, not the title before it.
     */
    #[Test]
    public function overMcpAPictureWithoutASummaryIsRefusedWithTheReasonBeforeItsTargetIsLookedUpAndNothingIsWritten(): void
    {
        $fixture = new GateFixture(scopes: ['rounds:write'], grants: [[self::ROUND_EDIT, self::OMARS_DRAFT]]);
        $tool = new ScriptedPictureTool($fixture, self::omarsDraft());

        $refusal = $this->refusalOf(static fn () => $fixture->gate->callOverMcp($tool, ['upload_id' => 'u1', 'title' => 'Cart']));

        self::assertSame(ToolCallOutcome::InvalidInput, $refusal->outcome);
        self::assertSame(\sprintf(ToolRefusal::INVALID_INPUT, \sprintf(ScreenshotContextArguments::MISSING_OR_BLANK, '/summary')), $refusal->getMessage());
        self::assertSame([0, 0], [$tool->targetLookups, $tool->calls]);
        self::assertSame([], $fixture->voterQuestions);
        self::assertSame([], $fixture->writtenLabels());
        self::assertSame([[ToolCallAuditor::TOOL_TARGET_TYPE, null, ['tool' => ScriptedPictureTool::NAME, 'outcome' => 'invalid_input']]], self::targetsAndDataOf($fixture));
    }

    /**
     * A blank title passes the schema's minLength, so only the gate's own check can refuse it.
     */
    #[Test]
    public function overMcpABlankTitleIsRefusedThoughItPassesTheStricterSchema(): void
    {
        $fixture = new GateFixture(scopes: ['rounds:write'], grants: [[self::ROUND_EDIT, self::OMARS_DRAFT]]);
        $tool = new ScriptedPictureTool($fixture, self::omarsDraft());

        $refusal = $this->refusalOf(static fn () => $fixture->gate->callOverMcp($tool, ['upload_id' => 'u1', 'title' => "\r\n ", 'summary' => 'The total.']));

        self::assertSame(\sprintf(ToolRefusal::INVALID_INPUT, \sprintf(ScreenshotContextArguments::MISSING_OR_BLANK, '/title')), $refusal->getMessage());
        self::assertSame([0, 0], [$tool->targetLookups, $tool->calls]);
        self::assertSame([], $fixture->writtenLabels());
    }

    /**
     * The scope is checked first, so a token without it learns nothing of the arguments.
     */
    #[Test]
    public function overMcpATokenWithoutTheScopeIsRefusedForItsScopeBeforeThePictureIsRead(): void
    {
        $fixture = new GateFixture(scopes: ['read'], grants: [[self::ROUND_EDIT, self::OMARS_DRAFT]]);
        $tool = new ScriptedPictureTool($fixture, self::omarsDraft());

        $refusal = $this->refusalOf(static fn () => $fixture->gate->callOverMcp($tool, ['upload_id' => 'u1']));

        self::assertSame(\sprintf(ToolRefusal::MISSING_SCOPE, 'rounds:write'), $refusal->getMessage());
        self::assertSame([], $fixture->writtenLabels());
    }

    #[Test]
    public function overMcpAPictureWithCrLfAndMultibyteTextRunsAndWritesAnOkRow(): void
    {
        $fixture = new GateFixture(scopes: ['rounds:write'], grants: [[self::ROUND_EDIT, self::OMARS_DRAFT]]);
        $tool = new ScriptedPictureTool($fixture, self::omarsDraft());

        $fixture->gate->callOverMcp($tool, ['upload_id' => 'u1', 'title' => "Cart\r\nPanier · 価格", 'summary' => "Der Gesamtbetrag ist doppelt.\r\n合計が二重です。"]);

        self::assertSame([1, 1], [$tool->targetLookups, $tool->calls]);
        self::assertSame(['picture added'], $fixture->writtenLabels());
        self::assertSame([['round', self::OMARS_DRAFT, ['tool' => ScriptedPictureTool::NAME, 'outcome' => 'ok']]], self::targetsAndDataOf($fixture));
    }

    /**
     * call() serves /api/v1, where the browser extension may leave both out (P14.T11).
     */
    #[Test]
    public function outsideMcpTheSamePictureToolRunsWithoutATitleOrASummary(): void
    {
        $fixture = new GateFixture(scopes: ['rounds:write'], grants: [[self::ROUND_EDIT, self::OMARS_DRAFT]]);
        $tool = new ScriptedPictureTool($fixture, self::omarsDraft());

        $fixture->gate->call($tool, ['upload_id' => 'u1']);

        self::assertSame(1, $tool->calls);
        self::assertSame(['picture added'], $fixture->writtenLabels());
    }

    #[Test]
    public function overMcpAToolWithoutPicturesRunsAsCallRunsIt(): void
    {
        $fixture = new GateFixture(grants: [[self::TICKET_VIEW, self::SHOP_42]]);
        $tool = $this->toolOnShop42('read');

        $fixture->gate->callOverMcp($tool, ['ticket' => 'SHOP-42']);

        self::assertSame([1, 1], [$tool->targetLookups, $tool->calls]);
        $this->assertOneRow($fixture, 'ok', 'ticket', self::SHOP_42);
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
    public function anOwnIdentityToolWithoutATargetAsksNoVoter(): void
    {
        $fixture = new GateFixture();
        $tool = new ScriptedOwnIdentityTool();

        $fixture->gate->call($tool, ['ticket' => 'SHOP-42']);

        self::assertSame(1, $tool->calls);
        self::assertSame([], $fixture->voterQuestions);
        $this->assertOneRow($fixture, 'ok', ToolCallAuditor::TOOL_TARGET_TYPE, null);
    }

    /**
     * Deny by default: only an OwnIdentityTool may skip the voter.
     */
    #[Test]
    public function anyOtherToolWithoutATargetIsRefusedBeforeItRunsWithAnErrorRow(): void
    {
        $fixture = new GateFixture();
        $tool = new ScriptedTool('read', static fn (): ?ToolTarget => null);

        $failure = $this->failureOf(static fn () => $fixture->gate->call($tool, ['ticket' => 'SHOP-42']));

        self::assertInstanceOf(\LogicException::class, $failure);
        self::assertStringContainsString('only an OwnIdentityTool may run without one', $failure->getMessage());
        self::assertSame([1, 0], [$tool->targetLookups, $tool->calls]);
        self::assertSame([], $fixture->voterQuestions);
        $this->assertOneRow($fixture, 'error', ToolCallAuditor::TOOL_TARGET_TYPE, null);
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
            [['level' => 'warning', 'message' => 'The audit row of a failed MCP tool call could not be written.', 'context' => ['mcp_tool' => ScriptedTool::NAME, 'exception_class' => \LogicException::class]]],
            $fixture->logger->records,
        );
    }

    #[Test]
    public function aGrantedCallKeepsItsWritesInTheRequestTransactionBesideItsOkRow(): void
    {
        $fixture = new GateFixture(grants: [[self::TICKET_VIEW, self::SHOP_42]]);
        $fixture->writeLabel('before the call');
        $tool = $this->toolOnShop42('read', self::writing($fixture, 'by the tool'));

        $fixture->gate->call($tool, ['ticket' => 'SHOP-42']);

        self::assertSame(['before the call', 'by the tool'], $fixture->writtenLabels());
        self::assertSame(1, $fixture->connection->getTransactionNestingLevel(), 'the savepoint was not released');
        $this->assertOneRow($fixture, 'ok', 'ticket', self::SHOP_42);
    }

    #[Test]
    public function aToolThatFailsAfterItsWriteKeepsNoneOfItAndOnlyAnErrorRow(): void
    {
        $fixture = new GateFixture(grants: [[self::TICKET_VIEW, self::SHOP_42]]);
        $fixture->writeLabel('before the call');
        $failure = new \RuntimeException('the second write failed');
        $tool = $this->toolOnShop42('read', self::writing($fixture, 'by the tool', $failure));

        self::assertSame($failure, $this->failureOf(static fn () => $fixture->gate->call($tool, ['ticket' => 'SHOP-42'])));

        self::assertSame(['before the call'], $fixture->writtenLabels());
        self::assertSame(1, $fixture->connection->getTransactionNestingLevel());
        $this->assertOneRow($fixture, 'error', 'ticket', self::SHOP_42);
    }

    #[Test]
    public function aToolThatRefusesAfterItsWriteKeepsNoneOfItAndOnlyItsRefusalRow(): void
    {
        $fixture = new GateFixture(scopes: ['read', 'rounds:write'], grants: [[self::TICKET_VIEW, self::SHOP_42]]);
        $tool = $this->toolOnShop42('rounds:write', self::writing($fixture, 'by the tool', ToolRefusal::readOnly()));

        $refusal = $this->refusalOf(static fn () => $fixture->gate->call($tool, ['ticket' => 'SHOP-42']));

        self::assertSame(ToolCallOutcome::ReadOnly, $refusal->outcome);
        self::assertSame([], $fixture->writtenLabels());
        self::assertSame(1, $fixture->connection->getTransactionNestingLevel());
        $this->assertOneRow($fixture, 'read_only', 'ticket', self::SHOP_42);
    }

    #[Test]
    public function aToolsPlainRefusalAfterItsWriteKeepsNoneOfItAndIsAuditedAsRefused(): void
    {
        $fixture = new GateFixture(scopes: ['read', 'rounds:write'], grants: [[self::TICKET_VIEW, self::SHOP_42]]);
        $tool = $this->toolOnShop42('rounds:write', self::writing($fixture, 'by the tool', ToolRefusal::refused('The picture was rejected.')));

        $refusal = $this->refusalOf(static fn () => $fixture->gate->call($tool, ['ticket' => 'SHOP-42']));

        self::assertSame(ToolCallOutcome::Refused, $refusal->outcome);
        self::assertSame('The picture was rejected.', $refusal->getMessage());
        self::assertSame([], $fixture->writtenLabels());
        self::assertSame(1, $fixture->connection->getTransactionNestingLevel());
        $this->assertOneRow($fixture, 'refused', 'ticket', self::SHOP_42);
    }

    /**
     * The ok row fails as the error row after it does, so the call ends with neither; what matters is that the tool's
     * write does not stay without its row.
     */
    #[Test]
    public function anOkRowThatCannotBeWrittenTakesTheToolsWriteBackWithIt(): void
    {
        $auditFailure = new \LogicException('the audit write failed');
        $fixture = new GateFixture(grants: [[self::TICKET_VIEW, self::SHOP_42]], auditFailure: $auditFailure);
        $tool = $this->toolOnShop42('read', self::writing($fixture, 'by the tool'));

        self::assertSame($auditFailure, $this->failureOf(static fn () => $fixture->gate->call($tool, ['ticket' => 'SHOP-42'])));

        self::assertSame(1, $tool->calls);
        self::assertSame([], $fixture->writtenLabels());
        self::assertSame(1, $fixture->connection->getTransactionNestingLevel());
        self::assertSame([], $fixture->auditLog->entries);
    }

    #[Test]
    public function aToolThatLeavesATransactionOpenKeepsNoneOfItsWritesAndGetsAnErrorRow(): void
    {
        $fixture = new GateFixture(grants: [[self::TICKET_VIEW, self::SHOP_42]]);
        $tool = $this->toolOnShop42('read', static function () use ($fixture): CallToolResult {
            $fixture->connection->beginTransaction();
            $fixture->writeLabel('left open');

            return new CallToolResult([new TextContent('done')]);
        });

        self::assertInstanceOf(\LogicException::class, $this->failureOf(static fn () => $fixture->gate->call($tool, ['ticket' => 'SHOP-42'])));

        self::assertSame([], $fixture->writtenLabels());
        self::assertSame(1, $fixture->connection->getTransactionNestingLevel());
        $this->assertOneRow($fixture, 'error', 'ticket', self::SHOP_42);
    }

    /**
     * What it committed cannot be taken back, so the connection is closed: the request then fails at its own commit,
     * which finds its transaction gone.
     */
    #[Test]
    public function aToolThatEndsTheRequestTransactionGetsTheConnectionClosed(): void
    {
        $fixture = new GateFixture(grants: [[self::TICKET_VIEW, self::SHOP_42]]);
        $tool = $this->toolOnShop42('read', static function () use ($fixture): CallToolResult {
            $fixture->writeLabel('committed by the tool');
            $fixture->connection->commit();
            $fixture->connection->commit();

            return new CallToolResult([new TextContent('done')]);
        });

        self::assertInstanceOf(\LogicException::class, $this->failureOf(static fn () => $fixture->gate->call($tool, ['ticket' => 'SHOP-42'])));

        self::assertFalse($fixture->connection->isConnected());
        self::assertSame(0, $fixture->connection->getTransactionNestingLevel());
        self::assertSame(
            [
                ['level' => 'warning', 'message' => 'Rolling back the writes of a failed MCP tool call failed; closing the connection instead.', 'context' => ['mcp_tool' => ScriptedTool::NAME, 'exception_class' => \LogicException::class, 'sqlstate' => null, 'code' => 0]],
                ['level' => 'warning', 'message' => 'The audit row of a failed MCP tool call could not be written.', 'context' => ['mcp_tool' => ScriptedTool::NAME, 'exception_class' => \LogicException::class]],
            ],
            $fixture->logger->records,
        );
    }

    #[Test]
    public function aRequestWithoutAnOpenTransactionFailsWithoutARowOrAToolRun(): void
    {
        $fixture = new GateFixture(grants: [[self::TICKET_VIEW, self::SHOP_42]]);
        $fixture->connection->rollBack();
        $tool = $this->toolOnShop42('read');

        $failure = $this->failureOf(static fn () => $fixture->gate->call($tool, ['ticket' => 'SHOP-42']));

        self::assertInstanceOf(\LogicException::class, $failure);
        self::assertStringContainsString('none is open', $failure->getMessage());
        self::assertSame([0, 0], [$tool->targetLookups, $tool->calls]);
        self::assertSame([], $fixture->auditLog->entries);
    }

    #[Test]
    public function aRequestWithoutAUserFailsWithoutARowOrAToolRun(): void
    {
        $fixture = new GateFixture(hasUser: false);
        $tool = $this->toolOnShop42('read');

        $failure = $this->failureOf(static fn () => $fixture->gate->call($tool, ['ticket' => 'SHOP-42']));

        self::assertInstanceOf(\LogicException::class, $failure);
        self::assertStringContainsString('without a user', $failure->getMessage());
        self::assertSame([0, 0], [$tool->targetLookups, $tool->calls]);
        self::assertSame([], $fixture->auditLog->entries);
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
     * A tool step that writes $label and then throws $failure, or answers "done" without one.
     *
     * @return \Closure(): CallToolResult
     */
    private static function writing(GateFixture $fixture, string $label, ?\Throwable $failure = null): \Closure
    {
        return static function () use ($fixture, $label, $failure): CallToolResult {
            $fixture->writeLabel($label);
            if (null !== $failure) {
                throw $failure;
            }

            return new CallToolResult([new TextContent('done')]);
        };
    }

    /**
     * @param \Closure(): mixed $call
     */
    private function failureOf(\Closure $call): \Throwable
    {
        try {
            $call();
        } catch (\Throwable $failure) {
            return $failure;
        }
        self::fail('the call did not fail');
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

    private static function omarsDraft(): ToolTarget
    {
        return new ToolTarget(self::ROUND_EDIT, self::OMARS_DRAFT, 'round', self::OMARS_DRAFT);
    }

    /**
     * @return list<array{?string, ?string, array<string, mixed>}> each audit row's target type, target id and data
     */
    private static function targetsAndDataOf(GateFixture $fixture): array
    {
        return array_map(static fn (AuditEntry $entry): array => [$entry->targetType, $entry->targetId, $entry->data], $fixture->auditLog->entries);
    }

    /**
     * @return list<mixed>
     */
    private static function rowOf(AuditEntry $entry): array
    {
        return [$entry->organizationId->toString(), $entry->actorKind, $entry->actorUserId, $entry->actorTokenId, $entry->action, $entry->targetType, $entry->targetId, $entry->data];
    }
}
