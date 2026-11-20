<?php

declare(strict_types=1);

namespace App\Organization\Application;

use App\SharedKernel\Domain\UserId;

/**
 * Where a page of people starts: after the last person shown, in the order OrganizationPeopleQueries::pageOf gives
 * (name key, then user id). It keeps that person's place, not the person, so a rename or a leave since the last page
 * neither skips nor repeats anyone. It is not signed: it holds only what the admin already saw, and a forged one only
 * moves where the page starts.
 */
final readonly class PeoplePageCursor
{
    /**
     * A name has at most 100 characters in Identity and lowercasing turns a few characters into two; the cap leaves
     * room above that.
     */
    public const int NAME_KEY_MAX_LENGTH = 300;

    /**
     * @param string $nameKey the last shown person's nameKeyOf, as it was when the page was shown
     *
     * @throws \InvalidArgumentException when the name key is not UTF-8 or longer than NAME_KEY_MAX_LENGTH characters
     */
    public function __construct(public string $nameKey, public UserId $userId)
    {
        if (!mb_check_encoding($nameKey, 'UTF-8') || mb_strlen($nameKey, 'UTF-8') > self::NAME_KEY_MAX_LENGTH) {
            throw new \InvalidArgumentException(\sprintf('A page of people starts after a name of UTF-8 text of at most %d characters.', self::NAME_KEY_MAX_LENGTH));
        }
    }

    public static function after(OrganizationPerson $lastShown): self
    {
        return new self(self::nameKeyOf($lastShown->displayName), $lastShown->userId);
    }

    /**
     * What the page order sorts a name by: lowercase, so "lena" sits beside "Lena".
     */
    public static function nameKeyOf(string $displayName): string
    {
        return mb_strtolower($displayName, 'UTF-8');
    }
}
