<?php

declare(strict_types=1);

namespace App\Tests\Unit\AgentApi\Infrastructure\Mcp;

use App\AgentApi\Infrastructure\Mcp\ToolCallOutcome;
use App\AgentApi\Infrastructure\Mcp\ToolRefusal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A refusal's message for the client and the outcome its audit row keeps; ToolCallGateTest proves the row's name.
 */
final class ToolRefusalTest extends TestCase
{
    #[Test]
    public function aPlainRefusalShowsItsReasonAsItIsAndIsAuditedAsRefused(): void
    {
        $reason = "The picture was rejected: it is too big.\nUpload another one.";

        $refusal = ToolRefusal::refused($reason);

        self::assertSame($reason, $refusal->getMessage());
        self::assertSame(ToolCallOutcome::Refused, $refusal->outcome);
    }

    #[Test]
    public function aPromptArgumentRefusalNamesThePromptsProblemAndIsAuditedAsInvalidInput(): void
    {
        $refusal = ToolRefusal::invalidPromptArguments('"key" must be a ticket key, for example SHOP-12.');

        self::assertSame('The arguments do not match this prompt: "key" must be a ticket key, for example SHOP-12.', $refusal->getMessage());
        self::assertSame(ToolCallOutcome::InvalidInput, $refusal->outcome);
        self::assertStringNotContainsString('input schema of this tool', $refusal->getMessage());
    }
}
