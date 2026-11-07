<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;

/**
 * For an email no user with a password has, verifies the typed password against a throwaway hash before the login
 * fails, so an unknown email takes as long as a wrong password and response times do not tell which emails exist
 * (ADR-0012). Symfony's own CheckCredentialsListener does the verify for a known email.
 */
final class UnknownEmailPasswordVerifier
{
    // After the throttle (2080) and the CSRF check (512), so neither a throttled nor a forged try costs a hash;
    // before the user checker (256) and the password check (0).
    private const int PRIORITY = 300;

    /** Not reset between requests: it hashes a constant, so one per worker is enough and it is never request data. */
    private ?string $throwawayHash = null;

    public function __construct(private readonly PasswordHasherFactoryInterface $hasherFactory)
    {
    }

    #[AsEventListener(event: CheckPassportEvent::class, priority: self::PRIORITY)]
    public function verifyAgainstAThrowawayHash(CheckPassportEvent $event): void
    {
        $passport = $event->getPassport();
        if (!$passport->hasBadge(PasswordCredentials::class)) {
            return;
        }

        try {
            $passport->getUser();
        } catch (UserNotFoundException $userNotFound) {
            $credentials = $passport->getBadge(PasswordCredentials::class);
            \assert($credentials instanceof PasswordCredentials);
            $hasher = $this->hasherFactory->getPasswordHasher(SecurityUser::class);
            $hasher->verify($this->throwawayHash ??= $hasher->hash(bin2hex(random_bytes(16))), $credentials->getPassword());

            throw $userNotFound;
        }
    }
}
