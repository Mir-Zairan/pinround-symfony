<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Theme;
use App\Identity\Domain\Users;
use App\SharedKernel\Domain\UserId;

/**
 * A logged-in person picks System, Light or Dark in the avatar menu (FR-ID-07). It keeps every session of the user. Its
 * write shares the caller's transaction (RequestTransaction on the web).
 */
final readonly class ChangeTheme
{
    public function __construct(private Users $users)
    {
    }

    /**
     * @param string $theme a Theme value: "system", "light" or "dark"
     *
     * @return bool false when $theme is not a Theme value; nothing is saved then
     *
     * @throws \LogicException when no user has this id
     */
    public function __invoke(UserId $userId, string $theme): bool
    {
        $chosenTheme = Theme::tryFrom($theme);
        if (null === $chosenTheme) {
            return false;
        }

        // The firewall reloads the logged-in user on every request and logs the session out when it is gone.
        $user = $this->users->byId($userId) ?? throw new \LogicException('No user has this id.');
        $user->changeTheme($chosenTheme);
        $this->users->save($user);

        return true;
    }
}
