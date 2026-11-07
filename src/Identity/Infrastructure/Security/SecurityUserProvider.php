<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Domain\Credentials;
use App\Identity\Domain\Email;
use App\Identity\Domain\InvalidEmail;
use App\Identity\Domain\Users;
use App\Identity\Domain\UserStatus;
use App\SharedKernel\Domain\UserId;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Loads users for the login form by email, and again by id on every request of a session. Every "not found" is a
 * UserNotFoundException without the email in it, so neither the page nor a log learns which part was missing.
 *
 * @implements UserProviderInterface<SecurityUser>
 */
final readonly class SecurityUserProvider implements UserProviderInterface
{
    public function __construct(private Users $users, private Credentials $credentials)
    {
    }

    /**
     * A blocked user is returned, so the password is checked first and BlockedUserChecker refuses them after it: the
     * same time and the same message as a wrong password. A user without a password counts as not found.
     *
     * @throws UserNotFoundException when no user with a password has this email
     */
    public function loadUserByIdentifier(string $identifier): SecurityUser
    {
        try {
            $email = new Email($identifier);
        } catch (InvalidEmail) {
            throw new UserNotFoundException();
        }

        $user = $this->users->byEmail($email);
        $credential = null === $user ? null : $this->credentials->byUserId($user->id());
        if (null === $user || null === $credential) {
            throw new UserNotFoundException();
        }

        return SecurityUser::of($user, $credential);
    }

    /**
     * @throws UserNotFoundException when the user is gone, blocked or has no password any more, which ends the session
     */
    public function refreshUser(UserInterface $user): SecurityUser
    {
        if (!$user instanceof SecurityUser) {
            throw new UnsupportedUserException(\sprintf('Only %s is supported.', SecurityUser::class));
        }

        $current = $this->users->byId(new UserId($user->id()));
        $credential = null === $current ? null : $this->credentials->byUserId($current->id());
        if (null === $current || null === $credential || UserStatus::Blocked === $current->status()) {
            throw new UserNotFoundException();
        }

        return SecurityUser::of($current, $credential);
    }

    public function supportsClass(string $class): bool
    {
        return SecurityUser::class === $class;
    }
}
