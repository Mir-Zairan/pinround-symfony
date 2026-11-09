<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Api\InvitedPersonRegistration;
use App\Identity\Api\RegistrationRefusal;
use App\Identity\Api\RegistrationRefused;
use App\Identity\Domain\BlankUserName;
use App\Identity\Domain\Credential;
use App\Identity\Domain\Credentials;
use App\Identity\Domain\Email;
use App\Identity\Domain\EmailTaken;
use App\Identity\Domain\InvalidEmail;
use App\Identity\Domain\NewPassword;
use App\Identity\Domain\PasswordHashing;
use App\Identity\Domain\PasswordTooShort;
use App\Identity\Domain\User;
use App\Identity\Domain\UserNameTooLong;
use App\Identity\Domain\Users;
use App\SharedKernel\Domain\UserId;

/**
 * The account starts in UTC and English; the person sets their own time zone on the profile page.
 */
final readonly class RegisterInvitedPerson implements InvitedPersonRegistration
{
    private const string LOCALE = 'en';

    private const string TIME_ZONE = 'UTC';

    public function __construct(
        private Users $users,
        private Credentials $credentials,
        private PasswordHashing $passwordHashing,
    ) {
    }

    public function minimumPasswordLength(): int
    {
        return NewPassword::MINIMUM_LENGTH;
    }

    public function maximumNameLength(): int
    {
        return User::MAXIMUM_NAME_LENGTH;
    }

    public function register(string $email, string $name, #[\SensitiveParameter] string $password, \DateTimeImmutable $now): UserId
    {
        $refusals = [];
        $user = null;
        try {
            $address = new Email($email);
            if (null !== $this->users->byEmail($address)) {
                $refusals[] = RegistrationRefusal::EmailTaken;
            }
            $user = new User($this->users->nextIdentity(), $address, $name, self::LOCALE, self::TIME_ZONE, $now);
        } catch (InvalidEmail) {
            $refusals[] = RegistrationRefusal::InvalidEmail;
        } catch (BlankUserName) {
            $refusals[] = RegistrationRefusal::NameBlank;
        } catch (UserNameTooLong) {
            $refusals[] = RegistrationRefusal::NameTooLong;
        }
        $newPassword = null;
        try {
            $newPassword = new NewPassword($password);
        } catch (PasswordTooShort) {
            $refusals[] = RegistrationRefusal::PasswordTooShort;
        }
        if ([] !== $refusals) {
            throw new RegistrationRefused($refusals);
        }
        \assert(null !== $user && null !== $newPassword);

        $passwordHash = $this->passwordHashing->hash($newPassword);
        try {
            $this->users->add($user);
        } catch (EmailTaken $emailTaken) {
            throw new RegistrationRefused([RegistrationRefusal::EmailTaken], $emailTaken);
        }
        $this->credentials->add(new Credential($user, $passwordHash, $now));

        return $user->id();
    }
}
