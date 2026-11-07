<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Users;
use App\SharedKernel\Domain\UserId;

/**
 * "Log out of all devices" (FR-ID-06): rotates the session stamp, so every session of the user ends on its next
 * request, the caller's own included unless it ends that one at once through CurrentSession. Its write shares the
 * caller's transaction (RequestTransaction on the web).
 */
final readonly class LogOutEverywhere
{
    public function __construct(private Users $users)
    {
    }

    /**
     * An unknown user changes nothing: they have no session left to end.
     */
    public function __invoke(UserId $userId): void
    {
        $user = $this->users->byId($userId);
        if (null === $user) {
            return;
        }

        $user->rotateSessionStamp();
        $this->users->save($user);
    }
}
