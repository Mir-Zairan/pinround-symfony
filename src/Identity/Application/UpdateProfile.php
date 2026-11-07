<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\BlankUserName;
use App\Identity\Domain\InvalidProfile;
use App\Identity\Domain\UnknownTheme;
use App\Identity\Domain\UnknownTimeZone;
use App\Identity\Domain\User;
use App\Identity\Domain\UserNameTooLong;
use App\Identity\Domain\Users;
use App\SharedKernel\Domain\UserId;

/**
 * A logged-in person saves their display name, time zone and theme on the profile page (FR-ID-07). It keeps every
 * session of the user. Its write shares the caller's transaction (RequestTransaction on the web).
 */
final readonly class UpdateProfile
{
    public const int MAXIMUM_NAME_LENGTH = User::MAXIMUM_NAME_LENGTH;

    public function __construct(private Users $users)
    {
    }

    /**
     * Saves all three fields or none.
     *
     * @param string $theme a Theme value: "system", "light" or "dark"
     *
     * @return list<ProfileProblem> the problems in form order; empty when the profile is saved
     *
     * @throws \LogicException when no user has this id
     */
    public function __invoke(UserId $userId, string $name, string $timeZone, string $theme): array
    {
        // The firewall reloads the logged-in user on every request and logs the session out when it is gone.
        $user = $this->users->byId($userId) ?? throw new \LogicException('No user has this id.');
        try {
            $user->changeProfile($name, $timeZone, $theme);
        } catch (InvalidProfile $invalidProfile) {
            return array_map(static fn (BlankUserName|UserNameTooLong|UnknownTimeZone|UnknownTheme $refusal): ProfileProblem => match ($refusal::class) {
                BlankUserName::class => ProfileProblem::NameBlank,
                UserNameTooLong::class => ProfileProblem::NameTooLong,
                UnknownTimeZone::class => ProfileProblem::TimeZoneUnknown,
                UnknownTheme::class => ProfileProblem::ThemeUnknown,
            }, $invalidProfile->refusals);
        }
        $this->users->save($user);

        return [];
    }
}
