<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Users;
use App\SharedKernel\Domain\UserId;

/**
 * The saved profile of a user, for the profile page.
 */
final readonly class ShowProfile
{
    public function __construct(private Users $users)
    {
    }

    /**
     * @throws \LogicException when no user has this id
     */
    public function __invoke(UserId $userId): Profile
    {
        // The firewall reloads the logged-in user on every request and logs the session out when it is gone.
        $user = $this->users->byId($userId) ?? throw new \LogicException('No user has this id.');

        return new Profile($user->name(), $user->timeZone(), $user->theme()->value);
    }
}
