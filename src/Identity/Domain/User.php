<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * A person who can log in. Global: one user can belong to several organizations (data-model.md, Identity).
 */
final readonly class User
{
    // Kept as strings and wrapped on the way out: Doctrine hashes an id by casting it to a string, and the mapping
    // stays free of value-object types.
    private string $id;
    private string $email;
    private string $name;
    private UserStatus $status;
    private string $timeZone;

    /**
     * A new user is active.
     *
     * @param string $timeZone an IANA time zone identifier, for example "Europe/Berlin"
     *
     * @throws \InvalidArgumentException when the name is blank or the time zone is not an IANA identifier
     */
    public function __construct(UserId $id, Email $email, string $name, private string $locale, string $timeZone, private \DateTimeImmutable $createdAt)
    {
        $trimmedName = trim($name);
        if ('' === $trimmedName) {
            throw new \InvalidArgumentException('A user needs a name.');
        }
        if (!\in_array($timeZone, \DateTimeZone::listIdentifiers(), true)) {
            throw new \InvalidArgumentException('A user time zone must be an IANA time zone identifier.');
        }

        $this->id = $id->toString();
        $this->email = $email->toString();
        $this->name = $trimmedName;
        $this->status = UserStatus::Active;
        $this->timeZone = $timeZone;
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
}
