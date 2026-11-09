<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Application\TwoStepLoginState;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TwoStepLoginStateTest extends TestCase
{
    #[Test]
    public function onlyOnIsOnAndOnlyAwaitingCodeAwaitsACode(): void
    {
        $isOn = [];
        $isAwaitingCode = [];
        foreach (TwoStepLoginState::cases() as $state) {
            $isOn[$state->name] = $state->isOn();
            $isAwaitingCode[$state->name] = $state->isAwaitingCode();
        }

        self::assertSame(['Off' => false, 'AwaitingCode' => false, 'On' => true], $isOn);
        self::assertSame(['Off' => false, 'AwaitingCode' => true, 'On' => false], $isAwaitingCode);
    }
}
