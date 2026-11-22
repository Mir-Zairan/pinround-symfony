<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\UI\DraftPage;

use App\Review\UI\DraftPage\ClaudeCodeBox;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ClaudeCodeBoxTest extends TestCase
{
    #[Test]
    public function theAssigneesLineAsksClaudeCodeToFixWhatIsNotRightYet(): void
    {
        $box = ClaudeCodeBox::forDraftOwner('SHOP-12', true, 'https://pinround.acme-foods.example');

        self::assertSame('Read my pinround notes for SHOP-12 and fix what is marked “Not right yet”.', $box->prompt);
    }

    #[Test]
    public function anyoneElsesLineAsksClaudeCodeToCheckAndAddToTheDraftNeverToFix(): void
    {
        $box = ClaudeCodeBox::forDraftOwner('SHOP-12', false, 'https://pinround.acme-foods.example');

        self::assertSame('Check SHOP-12 and add what you find to my pinround draft round.', $box->prompt);
        self::assertStringNotContainsString('fix', $box->prompt);
    }

    #[Test]
    public function theConnectCommandAddsTheMcpAddressOfThePageWithNoToken(): void
    {
        $box = ClaudeCodeBox::forDraftOwner('SHOP-12', true, 'https://pinround.acme-foods.example');

        self::assertSame('claude mcp add --transport http pinround https://pinround.acme-foods.example/mcp', $box->connectCommand);
    }
}
