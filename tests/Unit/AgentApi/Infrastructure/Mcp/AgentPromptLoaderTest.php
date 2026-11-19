<?php

declare(strict_types=1);

namespace App\Tests\Unit\AgentApi\Infrastructure\Mcp;

use App\AgentApi\Infrastructure\Mcp\AgentPromptLoader;
use App\AgentApi\Infrastructure\Prompt\ReportWhileInvestigatingPrompt;
use Mcp\Capability\Registry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * AgentPromptLoader on the SDK's own registry: the prompt is listed, and the handler the registry holds for it only
 * refuses, as nothing but GatedPromptGetHandler may answer a prompts/get.
 */
final class AgentPromptLoaderTest extends TestCase
{
    #[Test]
    public function thePromptIsListedAndTheServerHasPrompts(): void
    {
        $registry = new Registry();
        self::assertFalse($registry->hasPrompts());

        new AgentPromptLoader(new ReportWhileInvestigatingPrompt())->load($registry);

        self::assertTrue($registry->hasPrompts());
        self::assertSame([ReportWhileInvestigatingPrompt::NAME], array_keys($registry->getPrompts()->references));
    }

    #[Test]
    public function theRegistrysHandlerOfThePromptOnlyRefuses(): void
    {
        $registry = new Registry();
        new AgentPromptLoader(new ReportWhileInvestigatingPrompt())->load($registry);
        $handler = $registry->getPrompt(ReportWhileInvestigatingPrompt::NAME)->handler;
        self::assertInstanceOf(\Closure::class, $handler);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('An MCP prompt was read without GatedPromptGetHandler');

        $handler();
    }
}
