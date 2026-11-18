<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Api\LoginAssurance;
use App\Identity\Application\RecentAuth;
use App\Identity\Domain\TwoStepLogins;
use App\SharedKernel\Domain\UserId;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Exception\SessionNotFoundException;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Reads the assurance from the token's class. The form login and SymfonyCurrentSession::keepAfterPasswordChange create
 * exactly a UsernamePasswordToken; the class is compared exactly, so its subclass SwitchUserToken (impersonation) and
 * every other token kind, remember-me and the personal access token login (ApiTokenAuthentication) included, are refused. The two-step
 * answers read the user's two-step login and the request's session through RecentAuth, the rule RequiresRecentAuth
 * uses too. Holds no state, so it is safe in worker mode (ADR-0008).
 */
final readonly class SymfonyLoginAssurance implements LoginAssurance
{
    public function __construct(private TwoStepLogins $twoStepLogins, private RecentAuth $recentAuth, private ClockInterface $clock)
    {
    }

    public function isPasswordSession(mixed $token): bool
    {
        return null !== self::passwordSessionUserId($token);
    }

    public function isTwoStepLoginOn(mixed $token): bool
    {
        $userId = self::passwordSessionUserId($token);

        return null !== $userId && true === $this->twoStepLogins->byUserId($userId)?->isOn();
    }

    public function hasConfirmedTwoStepLoginWithin(mixed $token, int $seconds): bool
    {
        $userId = self::passwordSessionUserId($token);
        if (null === $userId) {
            return false;
        }

        try {
            return $this->recentAuth->hasConfirmedTwoStepLoginWithin($userId, $seconds, $this->clock->now());
        } catch (SessionNotFoundException) {
            return false;
        }
    }

    public function isTokenAuthenticated(mixed $token): bool
    {
        return $token instanceof ApiTokenAuthentication;
    }

    private static function passwordSessionUserId(mixed $token): ?UserId
    {
        if (!$token instanceof UsernamePasswordToken || UsernamePasswordToken::class !== $token::class) {
            return null;
        }
        $user = $token->getUser();

        return $user instanceof SecurityUser ? new UserId($user->id()) : null;
    }
}
