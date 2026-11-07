<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\BlankUserName;
use App\Identity\Domain\Theme;
use App\Identity\Domain\UnknownTimeZone;
use App\Identity\Domain\User;
use App\Identity\Domain\Users;
use App\SharedKernel\Domain\UserId;

/**
 * A logged-in person saves their display name, time zone and theme on the profile page (FR-ID-07). It keeps every
 * session of the user. Its write shares the caller's transaction (RequestTransaction on the web).
 */
final readonly class UpdateProfile
{
    public function __construct(private Users $users)
    {
    }

    /**
     * @throws \LogicException when no user has this id
     */
    public function current(UserId $userId): Profile
    {
        $user = $this->userOf($userId);

        return new Profile($user->name(), $user->timeZone(), $user->theme()->value);
    }

    /**
     * Saves all three fields or none: every field is checked before the user changes.
     *
     * @param string $theme a Theme value: "system", "light" or "dark"
     *
     * @return list<ProfileProblem> the problems in form order; empty when the profile is saved
     *
     * @throws \LogicException when no user has this id
     */
    public function __invoke(UserId $userId, string $name, string $timeZone, string $theme): array
    {
        $problems = [];
        try {
            User::trimmedName($name);
        } catch (BlankUserName) {
            $problems[] = ProfileProblem::NameBlank;
        }
        try {
            User::ianaTimeZone($timeZone);
        } catch (UnknownTimeZone) {
            $problems[] = ProfileProblem::TimeZoneUnknown;
        }
        $chosenTheme = Theme::tryFrom($theme);
        if (null === $chosenTheme) {
            $problems[] = ProfileProblem::ThemeUnknown;
        }
        if ([] !== $problems || null === $chosenTheme) {
            return $problems;
        }

        $user = $this->userOf($userId);
        $user->rename($name);
        $user->changeTimeZone($timeZone);
        $user->changeTheme($chosenTheme);
        $this->users->save($user);

        return [];
    }

    private function userOf(UserId $userId): User
    {
        // The firewall reloads the logged-in user on every request and logs the session out when it is gone.
        return $this->users->byId($userId) ?? throw new \LogicException('No user has this id.');
    }
}
