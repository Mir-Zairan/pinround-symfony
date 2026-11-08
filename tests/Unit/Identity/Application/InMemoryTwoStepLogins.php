<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Domain\TwoStepLogin;
use App\Identity\Domain\TwoStepLogins;
use App\SharedKernel\Domain\UserId;

/**
 * Two-step logins by user id, recording which reads were for a change, every save and every removal.
 */
final class InMemoryTwoStepLogins implements TwoStepLogins
{
    /** @var array<string, TwoStepLogin> */
    private array $byUserId = [];

    /** @var list<string> */
    public array $userIdsReadForChange = [];

    /** @var list<TwoStepLogin> */
    public array $saved = [];

    /** @var list<TwoStepLogin> */
    public array $removed = [];

    public function byUserId(UserId $userId): ?TwoStepLogin
    {
        return $this->byUserId[$userId->toString()] ?? null;
    }

    public function byUserIdForChange(UserId $userId): ?TwoStepLogin
    {
        $this->userIdsReadForChange[] = $userId->toString();

        return $this->byUserId($userId);
    }

    public function save(TwoStepLogin $twoStepLogin): void
    {
        $this->byUserId[$twoStepLogin->userId()->toString()] = $twoStepLogin;
        $this->saved[] = $twoStepLogin;
    }

    public function remove(TwoStepLogin $twoStepLogin): void
    {
        unset($this->byUserId[$twoStepLogin->userId()->toString()]);
        $this->removed[] = $twoStepLogin;
    }
}
