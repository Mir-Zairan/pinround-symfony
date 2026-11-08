<?php

declare(strict_types=1);

namespace App\Identity\Application;

enum TwoStepLoginState
{
    case Off;
    /** Turned on, but the user has not typed a code of the new seed yet; it is still off for login. */
    case AwaitingCode;
    case On;

    public function isOn(): bool
    {
        return self::On === $this;
    }

    public function isAwaitingCode(): bool
    {
        return self::AwaitingCode === $this;
    }
}
