<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Application;

use App\Identity\Api\InvitedPersonRegistration;
use App\Identity\Api\RegistrationRefusal;
use App\Identity\Api\RegistrationRefused;
use App\SharedKernel\Domain\UserId;

/**
 * Registers every person as NEW_USER and records the email and name. Refuses a taken email or a password shorter than
 * the minimum, as RegisterInvitedPerson does, before recording anything.
 */
final class RecordingInvitedPersonRegistration implements InvitedPersonRegistration
{
    public const string NEW_USER = '0197a3c0-0000-7000-8000-0000000006f1';

    /** @var list<array{string, string}> email and name of each registration */
    public array $registered = [];

    /**
     * @param list<string> $takenEmails
     */
    public function __construct(private readonly array $takenEmails = [])
    {
    }

    public function minimumPasswordLength(): int
    {
        return 12;
    }

    public function maximumNameLength(): int
    {
        return 100;
    }

    public function register(string $email, string $name, #[\SensitiveParameter] string $password, \DateTimeImmutable $now): UserId
    {
        if (\in_array($email, $this->takenEmails, true)) {
            throw new RegistrationRefused([RegistrationRefusal::EmailTaken]);
        }
        if (mb_strlen($password) < $this->minimumPasswordLength()) {
            throw new RegistrationRefused([RegistrationRefusal::PasswordTooShort]);
        }
        $this->registered[] = [$email, $name];

        return new UserId(self::NEW_USER);
    }
}
