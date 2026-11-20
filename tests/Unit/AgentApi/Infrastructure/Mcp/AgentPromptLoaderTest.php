<?php

declare(strict_types=1);

namespace App\Tests\Unit\AgentApi\Infrastructure\Mcp;

use App\AgentApi\Infrastructure\Mcp\AgentPromptLoader;
use App\AgentApi\Infrastructure\Prompt\ExplainMyNotebookPrompt;
use App\AgentApi\Infrastructure\Prompt\ReportWhileInvestigatingPrompt;
use Mcp\Capability\Registry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * AgentPromptLoader on the SDK's own registry: each prompt is listed once, two of one name are refused, and the handler
 * the registry holds for a prompt only refuses, as nothing but GatedPromptGetHandler may answer a prompts/get.
 */
final class AgentPromptLoaderTest extends TestCase
{
    #[Test]
    public function eachPromptIsListedAndTheServerHasPrompts(): void
    {
        $registry = new Registry();
        self::assertFalse($registry->hasPrompts());

        new AgentPromptLoader([new ReportWhileInvestigatingPrompt(), new ExplainMyNotebookPrompt()])->load($registry);

        self::assertTrue($registry->hasPrompts());
        self::assertSame([ReportWhileInvestigatingPrompt::NAME, ExplainMyNotebookPrompt::NAME], array_keys($registry->getPrompts()->references));
    }

    #[Test]
    public function noPromptsLeaveTheServerWithoutPrompts(): void
    {
        $registry = new Registry();

        new AgentPromptLoader([])->load($registry);

        self::assertFalse($registry->hasPrompts());
    }

    #[Test]
    public function twoPromptsOfOneNameAreRefused(): void
    {
        $registry = new Registry();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('Two MCP prompts are named "explain_my_notebook".');

        new AgentPromptLoader([new ExplainMyNotebookPrompt(), new ExplainMyNotebookPrompt()])->load($registry);
    }

    #[Test]
    public function theRegistrysHandlerOfEachPromptOnlyRefuses(): void
    {
        $registry = new Registry();
        new AgentPromptLoader([new ReportWhileInvestigatingPrompt(), new ExplainMyNotebookPrompt()])->load($registry);

        foreach ([ReportWhileInvestigatingPrompt::NAME, ExplainMyNotebookPrompt::NAME] as $promptName) {
            $handler = $registry->getPrompt($promptName)->handler;
            self::assertInstanceOf(\Closure::class, $handler);
            try {
                $handler();
                self::fail(\sprintf('the registry ran %s without GatedPromptGetHandler', $promptName));
            } catch (\LogicException $refusal) {
                self::assertStringContainsString('An MCP prompt was read without GatedPromptGetHandler', $refusal->getMessage());
            }
        }
    }
}
