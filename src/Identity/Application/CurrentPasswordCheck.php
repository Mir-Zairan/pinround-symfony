<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Credentials;
use App\Identity\Domain\PasswordHashing;
use App\SharedKernel\Domain\UserId;

/**
 * Checks the password a logged-in person types again, on the security page and at "Confirm it's you". Both share one
 * limiter, so a stolen session gets no extra guesses by switching pages.
 */
final readonly class CurrentPasswordCheck
{
    public function __construct(
        private Credentials $credentials,
        private PasswordHashing $passwordHashing,
        private PasswordChangeLimiter $limiter,
    ) {
    }

    /**
     * Every check counts against the limiter, right or wrong, before the password is compared.
     *
     * @return bool false also for a user without a password
     *
     * @throws TooManyPasswordChangeTries when this user's password was checked too often
     */
    public function matches(UserId $userId, #[\SensitiveParameter] string $password): bool
    {
        $this->limiter->consume($userId);
        $credential = $this->credentials->byUserId($userId);

        return null !== $credential && $this->passwordHashing->matches($credential->passwordHash(), $password);
    }
}
