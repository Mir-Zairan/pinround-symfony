<?php

declare(strict_types=1);

namespace App\Tests\Unit\AgentApi\Infrastructure\Prompt;

use App\AgentApi\Infrastructure\Prompt\PromptTemplate;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The placeholders of a prompt file that takes arguments: each filled once, and a template filled with more or fewer
 * values than its placeholders is refused, so a prompt is never sent half filled.
 */
final class PromptTemplateTest extends TestCase
{
    #[Test]
    public function everyPlaceholderIsFilledWithItsValueAndTheRestStaysAsItIs(): void
    {
        $text = PromptTemplate::fill("# Fix {{key}}\n\nCall `get_round_notes` with {{key}}, then {{ticket_title}}.\n{{ key }} {key}", [
            'key' => 'SHOP-12',
            'ticket_title' => 'the cart',
        ]);

        self::assertSame("# Fix SHOP-12\n\nCall `get_round_notes` with SHOP-12, then the cart.\n{{ key }} {key}", $text);
    }

    #[Test]
    public function aValueHoldingAPlaceholderIsNotFilledAgain(): void
    {
        $text = PromptTemplate::fill('{{first}} and {{second}}', ['first' => '{{second}}', 'second' => '{{first}}']);

        self::assertSame('{{second}} and {{first}}', $text);
    }

    #[Test]
    public function aPlaceholderTheValuesDoNotFillIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('The prompt template has the placeholders [key, round], not [key].');

        PromptTemplate::fill('{{key}} in {{round}}', ['key' => 'SHOP-12']);
    }

    #[Test]
    public function aValueTheTemplateHasNoPlaceholderForIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('The prompt template has the placeholders [key], not [key, round].');

        PromptTemplate::fill('{{key}}', ['round' => '2', 'key' => 'SHOP-12']);
    }

    #[Test]
    public function aTemplateWithoutPlaceholdersAndNoValuesIsUnchanged(): void
    {
        self::assertSame("No placeholder here.\n", PromptTemplate::fill("No placeholder here.\n", []));
    }

    #[Test]
    public function aTemplateWithoutPlaceholdersGivenAValueIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('The prompt template has the placeholders [], not [key].');

        PromptTemplate::fill('No placeholder here.', ['key' => 'SHOP-12']);
    }
}
