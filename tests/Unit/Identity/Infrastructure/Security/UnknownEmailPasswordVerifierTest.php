<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Infrastructure\Security;

use App\Identity\Domain\Credential;
use App\Identity\Domain\Email;
use App\Identity\Domain\PasswordHash;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Identity\Infrastructure\Security\UnknownEmailPasswordVerifier;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherAwareInterface;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;
use Symfony\Component\Security\Http\EventListener\CheckCredentialsListener;

/**
 * The verifier next to Symfony's CheckCredentialsListener, as the firewall runs them: an unknown email and a wrong
 * password each cost exactly one verify (the call is asserted, not the time, which would be flaky).
 */
final class UnknownEmailPasswordVerifierTest extends TestCase
{
    private const string TYPED_PASSWORD = 'what the person typed';
    private const string STORED_HASH = '$argon2id$v=19$m=10,t=3,p=1$stored';

    private RecordingPasswordHasher $hasher;
    private EventDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->hasher = new RecordingPasswordHasher();
        $hasherFactory = new readonly class($this->hasher) implements PasswordHasherFactoryInterface {
            public function __construct(private PasswordHasherInterface $hasher)
            {
            }

            public function getPasswordHasher(string|PasswordAuthenticatedUserInterface|PasswordHasherAwareInterface $user): PasswordHasherInterface
            {
                return $this->hasher;
            }
        };
        $this->dispatcher = new EventDispatcher();
        $verifier = new UnknownEmailPasswordVerifier($hasherFactory);
        $this->dispatcher->addListener(CheckPassportEvent::class, $verifier->verifyAgainstAThrowawayHash(...), 300);
        $this->dispatcher->addSubscriber(new CheckCredentialsListener($hasherFactory));
    }

    #[Test]
    public function anUnknownEmailVerifiesTheTypedPasswordAgainstAThrowawayHashThenStillFails(): void
    {
        $passport = new Passport(
            new UserBadge('nobody@acme-foods.example', static fn (): never => throw new UserNotFoundException()),
            new PasswordCredentials(self::TYPED_PASSWORD),
        );

        try {
            $this->dispatcher->dispatch(new CheckPassportEvent(self::createStub(AuthenticatorInterface::class), $passport));
            self::fail('An unknown email must fail.');
        } catch (UserNotFoundException) {
        }

        self::assertCount(1, $this->hasher->verifiedPasswords);
        self::assertSame(self::TYPED_PASSWORD, $this->hasher->verifiedPasswords[0]['plainPassword']);
        self::assertSame($this->hasher->hashes[0], $this->hasher->verifiedPasswords[0]['hashedPassword']);
    }

    #[Test]
    public function aWrongPasswordForAKnownEmailIsVerifiedOnceAgainstTheStoredHash(): void
    {
        $user = $this->securityUser();
        $passport = new Passport(new UserBadge($user->getUserIdentifier(), static fn (): SecurityUser => $user), new PasswordCredentials(self::TYPED_PASSWORD));

        try {
            $this->dispatcher->dispatch(new CheckPassportEvent(self::createStub(AuthenticatorInterface::class), $passport));
            self::fail('A wrong password must fail.');
        } catch (BadCredentialsException) {
        }

        self::assertSame([['hashedPassword' => self::STORED_HASH, 'plainPassword' => self::TYPED_PASSWORD]], $this->hasher->verifiedPasswords);
        self::assertSame([], $this->hasher->hashes);
    }

    #[Test]
    public function theThrowawayHashIsMadeOncePerWorker(): void
    {
        for ($try = 1; $try <= 2; ++$try) {
            $passport = new Passport(
                new UserBadge('nobody@acme-foods.example', static fn (): never => throw new UserNotFoundException()),
                new PasswordCredentials(self::TYPED_PASSWORD),
            );
            try {
                $this->dispatcher->dispatch(new CheckPassportEvent(self::createStub(AuthenticatorInterface::class), $passport));
            } catch (UserNotFoundException) {
            }
        }

        self::assertCount(1, $this->hasher->hashes);
        self::assertCount(2, $this->hasher->verifiedPasswords);
    }

    private function securityUser(): SecurityUser
    {
        $user = new User(new UserId('0197a3c0-0000-7000-8000-0000000000aa'), new Email('omar@acme-foods.example'), 'Omar Haddad', 'en', 'Europe/Berlin', new \DateTimeImmutable('2026-11-02 09:30:00 UTC'));

        return SecurityUser::of($user, new Credential($user, new PasswordHash(self::STORED_HASH), new \DateTimeImmutable('2026-11-02 09:30:00 UTC')));
    }
}
