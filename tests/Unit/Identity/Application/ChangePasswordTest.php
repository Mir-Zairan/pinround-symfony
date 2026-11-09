<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Application\ChangePassword;
use App\Identity\Application\CurrentPasswordCheck;
use App\Identity\Application\CurrentPasswordLimiter;
use App\Identity\Application\PasswordChangeOutcome;
use App\Identity\Application\TooManyCurrentPasswordTries;
use App\Identity\Domain\Credential;
use App\Identity\Domain\Credentials;
use App\Identity\Domain\Email;
use App\Identity\Domain\NewPassword;
use App\Identity\Domain\PasswordHash;
use App\Identity\Domain\PasswordHashing;
use App\Identity\Domain\User;
use App\Identity\Domain\Users;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * With in-memory users and credentials and a hashing whose "hash" is the password behind a "$plain$" prefix.
 */
final class ChangePasswordTest extends TestCase
{
    private const string ID = '0197a3c0-0000-7000-8000-0000000000c1';
    private const string CURRENT = 'correct horse battery staple';
    private const string NEW = 'a much longer new passphrase';

    private User $user;
    private Credential $credential;
    /** @var list<string> */
    private array $saved = [];
    private int $triesTaken = 0;
    private bool $isThrottled = false;

    protected function setUp(): void
    {
        $this->user = new User(new UserId(self::ID), new Email('omar@acme-foods.example'), 'Omar Haddad', 'en', 'UTC', new \DateTimeImmutable('2026-11-02 09:30:00 UTC'));
        $this->credential = new Credential($this->user, new PasswordHash('$plain$'.self::CURRENT), new \DateTimeImmutable('2026-11-02 09:30:00 UTC'));
    }

    #[Test]
    public function theRightCurrentPasswordSetsTheNewHashAndRotatesTheSessionStamp(): void
    {
        $stampBefore = $this->user->sessionStamp();
        $now = new \DateTimeImmutable('2026-11-08 10:00:00 UTC');

        $outcome = ($this->changePassword())(new UserId(self::ID), self::CURRENT, self::NEW, $now);

        self::assertSame(PasswordChangeOutcome::PasswordChanged, $outcome);
        self::assertSame('$plain$'.self::NEW, $this->credential->passwordHash()->encoded());
        self::assertSame($now, $this->credential->changedAt());
        self::assertNotSame($stampBefore, $this->user->sessionStamp());
        self::assertSame(['credential', 'user'], $this->saved);
        self::assertSame(1, $this->triesTaken);
    }

    #[Test]
    public function aWrongCurrentPasswordTakesATryAndChangesNothing(): void
    {
        $stampBefore = $this->user->sessionStamp();

        $outcome = ($this->changePassword())(new UserId(self::ID), 'not the current password', self::NEW, new \DateTimeImmutable('2026-11-08 10:00:00 UTC'));

        self::assertSame(PasswordChangeOutcome::WrongCurrentPassword, $outcome);
        $this->assertNothingChanged($stampBefore);
        self::assertSame(1, $this->triesTaken);
    }

    #[Test]
    public function aTooShortNewPasswordIsRefusedBeforeATryIsTaken(): void
    {
        $stampBefore = $this->user->sessionStamp();

        $outcome = ($this->changePassword())(new UserId(self::ID), self::CURRENT, 'eleven char', new \DateTimeImmutable('2026-11-08 10:00:00 UTC'));

        self::assertSame(PasswordChangeOutcome::PasswordTooShort, $outcome);
        $this->assertNothingChanged($stampBefore);
        self::assertSame(0, $this->triesTaken);
    }

    #[Test]
    public function aThrottledTryIsRefusedBeforeTheCurrentPasswordIsChecked(): void
    {
        $this->isThrottled = true;
        $stampBefore = $this->user->sessionStamp();

        try {
            ($this->changePassword())(new UserId(self::ID), self::CURRENT, self::NEW, new \DateTimeImmutable('2026-11-08 10:00:00 UTC'));
            self::fail('The try was not refused.');
        } catch (TooManyCurrentPasswordTries $refusal) {
            self::assertSame(15, $refusal->minutesToWait);
        }

        $this->assertNothingChanged($stampBefore);
    }

    #[Test]
    public function aUserWithoutAPasswordGetsTheWrongPasswordAnswer(): void
    {
        $outcome = ($this->changePassword(hasCredential: false))(new UserId(self::ID), self::CURRENT, self::NEW, new \DateTimeImmutable('2026-11-08 10:00:00 UTC'));

        self::assertSame(PasswordChangeOutcome::WrongCurrentPassword, $outcome);
        self::assertSame([], $this->saved);
    }

    #[Test]
    public function anUnknownUserGetsTheWrongPasswordAnswer(): void
    {
        $outcome = ($this->changePassword(hasUser: false))(new UserId(self::ID), self::CURRENT, self::NEW, new \DateTimeImmutable('2026-11-08 10:00:00 UTC'));

        self::assertSame(PasswordChangeOutcome::WrongCurrentPassword, $outcome);
        self::assertSame([], $this->saved);
    }

    private function assertNothingChanged(string $stampBefore): void
    {
        self::assertSame('$plain$'.self::CURRENT, $this->credential->passwordHash()->encoded());
        self::assertSame($stampBefore, $this->user->sessionStamp());
        self::assertSame([], $this->saved);
    }

    private function changePassword(bool $hasUser = true, bool $hasCredential = true): ChangePassword
    {
        $recordSave = function (string $what): void {
            $this->saved[] = $what;
        };
        $takeTry = function (): void {
            if ($this->isThrottled) {
                throw new TooManyCurrentPasswordTries(15);
            }
            ++$this->triesTaken;
        };
        $user = $hasUser ? $this->user : null;
        $credential = $hasCredential ? $this->credential : null;

        $users = new readonly class($user, $recordSave) implements Users {
            /**
             * @param \Closure(string): void $recordSave
             */
            public function __construct(private ?User $user, private \Closure $recordSave)
            {
            }

            public function nextIdentity(): UserId
            {
                throw new \LogicException('Not used.');
            }

            public function byId(UserId $id): ?User
            {
                return $this->user;
            }

            public function byEmail(Email $email): ?User
            {
                throw new \LogicException('Not used.');
            }

            public function add(User $user): void
            {
                throw new \LogicException('Not used.');
            }

            public function save(User $user): void
            {
                ($this->recordSave)('user');
            }
        };
        $credentials = new readonly class($credential, $recordSave) implements Credentials {
            /**
             * @param \Closure(string): void $recordSave
             */
            public function __construct(private ?Credential $credential, private \Closure $recordSave)
            {
            }

            public function byUserId(UserId $userId): ?Credential
            {
                return $this->credential;
            }

            public function add(Credential $credential): void
            {
                throw new \LogicException('Not used.');
            }

            public function save(Credential $credential): void
            {
                ($this->recordSave)('credential');
            }
        };
        $hashing = new class implements PasswordHashing {
            public function hash(NewPassword $password): PasswordHash
            {
                return new PasswordHash('$plain$'.$password->reveal());
            }

            public function matches(PasswordHash $hash, #[\SensitiveParameter] string $typedPassword): bool
            {
                return $hash->encoded() === '$plain$'.$typedPassword;
            }
        };
        $limiter = new readonly class($takeTry) implements CurrentPasswordLimiter {
            /**
             * @param \Closure(): void $takeTry
             */
            public function __construct(private \Closure $takeTry)
            {
            }

            public function consume(UserId $userId): void
            {
                ($this->takeTry)();
            }
        };

        return new ChangePassword($users, $credentials, $hashing, new CurrentPasswordCheck($credentials, $hashing, $limiter));
    }
}
