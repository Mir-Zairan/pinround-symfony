<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use App\SharedKernel\Domain\UserId;

/**
 * A person who can log in. Global: one user can belong to several organizations (data-model.md, Identity).
 */
final class User
{
    // Kept as strings and wrapped on the way out: Doctrine hashes an id by casting it to a string, and the mapping
    // stays free of value-object types.
    private readonly string $id;
    private readonly string $email;
    private string $name;
    private readonly UserStatus $status;
    private string $timeZone;
    private Theme $theme;
    private string $sessionStamp;

    /**
     * A new user is active, follows the device's theme and has a fresh session stamp.
     *
     * @param string $timeZone an IANA time zone identifier, for example "Europe/Berlin"; old names that browsers still
     *                         report, such as "Asia/Calcutta" or "US/Pacific", are kept as given
     *
     * @throws BlankUserName   when the name is blank
     * @throws UnknownTimeZone when the time zone is not an IANA identifier
     */
    public function __construct(UserId $id, Email $email, string $name, private readonly string $locale, string $timeZone, private readonly \DateTimeImmutable $createdAt)
    {
        $this->id = $id->toString();
        $this->email = $email->toString();
        $this->name = self::trimmedName($name);
        $this->status = UserStatus::Active;
        $this->timeZone = self::ianaTimeZone($timeZone);
        $this->theme = Theme::System;
        $this->sessionStamp = self::newSessionStamp();
    }

    /**
     * The name as a user keeps it. Public so a command can check every field of a form before it changes any.
     *
     * @throws BlankUserName when nothing is left after trimming
     */
    public static function trimmedName(string $name): string
    {
        $trimmedName = trim($name);
        if ('' === $trimmedName) {
            throw new BlankUserName('A user needs a name.');
        }

        return $trimmedName;
    }

    /**
     * The time zone as given, old IANA names included. Public for the same reason as trimmedName().
     *
     * @throws UnknownTimeZone when it is not an IANA identifier
     */
    public static function ianaTimeZone(string $timeZone): string
    {
        if (!\in_array($timeZone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)) {
            throw new UnknownTimeZone('A user time zone must be an IANA time zone identifier.');
        }

        return $timeZone;
    }

    public function id(): UserId
    {
        return new UserId($this->id);
    }

    public function email(): Email
    {
        return new Email($this->email);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function status(): UserStatus
    {
        return $this->status;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function timeZone(): string
    {
        return $this->timeZone;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function theme(): Theme
    {
        return $this->theme;
    }

    /**
     * @throws BlankUserName when nothing is left after trimming
     */
    public function rename(string $name): void
    {
        $this->name = self::trimmedName($name);
    }

    /**
     * @throws UnknownTimeZone when it is not an IANA identifier
     */
    public function changeTimeZone(string $timeZone): void
    {
        $this->timeZone = self::ianaTimeZone($timeZone);
    }

    public function changeTheme(Theme $theme): void
    {
        $this->theme = $theme;
    }

    /**
     * 32 hex digits that every session of this user carries; a session whose copy differs is logged out on its next
     * request.
     */
    public function sessionStamp(): string
    {
        return $this->sessionStamp;
    }

    /**
     * Ends every session of this user once the change is saved.
     */
    public function rotateSessionStamp(): void
    {
        $this->sessionStamp = self::newSessionStamp();
    }

    private static function newSessionStamp(): string
    {
        return bin2hex(random_bytes(16));
    }
}
