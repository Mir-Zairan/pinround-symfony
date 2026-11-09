<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Api\RegistrationRefusal;
use App\Identity\Api\RegistrationRefused;
use App\Identity\Application\RegisterInvitedPerson;
use App\Identity\Domain\Email;
use App\Identity\Domain\EmailTaken;
use App\Identity\Domain\NewPassword;
use App\Identity\Domain\PasswordHash;
use App\Identity\Domain\PasswordHashing;
use App\Identity\Domain\Theme;
use App\Identity\Domain\User;
use App\Identity\Domain\UserStatus;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RegisterInvitedPersonTest extends TestCase
{
    private const string PASSWORD = 'correct horse battery';

    private InMemoryUsers $users;

    private InMemoryCredentials $credentials;

    private int $hashCount = 0;

    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->users = new InMemoryUsers(
            new User(new UserId('0197a3c0-0000-7000-8000-0000000000aa'), new Email('lena@acme-foods.example'), 'Lena', 'en', 'Europe/Berlin', new \DateTimeImmutable('2026-11-01 08:00:00 UTC')),
        );
        $this->credentials = new InMemoryCredentials();
        $this->now = new \DateTimeImmutable('2026-11-09 14:15:16 UTC');
    }

    #[Test]
    public function itCreatesAnActiveUserWithTheNormalizedEmailTrimmedNameAndAPasswordThatVerifies(): void
    {
        $userId = $this->registration()->register('  Omar@Acme-Foods.Example ', '  Omar Haddad ', self::PASSWORD, $this->now);

        self::assertCount(1, $this->users->added);
        $user = $this->users->added[0];
        self::assertTrue($userId->equals($user->id()));
        self::assertSame('omar@acme-foods.example', $user->email()->toString());
        self::assertSame('Omar Haddad', $user->name());
        self::assertSame(UserStatus::Active, $user->status());
        self::assertSame(Theme::System, $user->theme());
        self::assertSame('UTC', $user->timeZone());
        self::assertSame('en', $user->locale());
        self::assertEquals($this->now, $user->createdAt());

        self::assertCount(1, $this->credentials->added);
        $credential = $this->credentials->added[0];
        self::assertTrue($userId->equals($credential->userId()));
        self::assertTrue($this->hashing()->matches($credential->passwordHash(), self::PASSWORD));
        self::assertFalse($this->hashing()->matches($credential->passwordHash(), 'correct horse battery!'));
        self::assertEquals($this->now, $credential->changedAt());
    }

    #[Test]
    public function itTakesAPasswordOfExactlyTheMinimumLengthAndANameOfExactlyTheMaximum(): void
    {
        $this->registration()->register('omar@acme-foods.example', str_repeat('ö', 100), str_repeat('ü', 12), $this->now);

        self::assertCount(1, $this->users->added);
        self::assertCount(1, $this->credentials->added);
    }

    #[Test]
    public function itGivesTheLimitsTheFormShows(): void
    {
        self::assertSame(12, $this->registration()->minimumPasswordLength());
        self::assertSame(NewPassword::MINIMUM_LENGTH, $this->registration()->minimumPasswordLength());
        self::assertSame(100, $this->registration()->maximumNameLength());
    }

    /**
     * @return iterable<string, array{string, string, string, list<RegistrationRefusal>}>
     */
    public static function refusedInputs(): iterable
    {
        yield 'invalid email' => ['omar-at-acme-foods.example', 'Omar', self::PASSWORD, [RegistrationRefusal::InvalidEmail]];
        yield 'taken email' => ['lena@acme-foods.example', 'Lena', self::PASSWORD, [RegistrationRefusal::EmailTaken]];
        yield 'email taken in another letter case' => [' LENA@Acme-Foods.Example', 'Lena', self::PASSWORD, [RegistrationRefusal::EmailTaken]];
        yield 'blank name' => ['omar@acme-foods.example', "  \t ", self::PASSWORD, [RegistrationRefusal::NameBlank]];
        yield 'name too long' => ['omar@acme-foods.example', str_repeat('ö', 101), self::PASSWORD, [RegistrationRefusal::NameTooLong]];
        yield 'password too short' => ['omar@acme-foods.example', 'Omar', str_repeat('ü', 11), [RegistrationRefusal::PasswordTooShort]];
        yield 'every field of a taken email' => ['lena@acme-foods.example', '', 'short', [RegistrationRefusal::EmailTaken, RegistrationRefusal::NameBlank, RegistrationRefusal::PasswordTooShort]];
        yield 'invalid email and short password' => ['not an email', 'Omar', 'short', [RegistrationRefusal::InvalidEmail, RegistrationRefusal::PasswordTooShort]];
    }

    /**
     * @param list<RegistrationRefusal> $expectedRefusals
     */
    #[Test]
    #[DataProvider('refusedInputs')]
    public function itRefusesBeforeAnyWriteAndListsEveryRefusedInput(string $email, string $name, string $password, array $expectedRefusals): void
    {
        try {
            $this->registration()->register($email, $name, $password, $this->now);
            self::fail('RegistrationRefused expected.');
        } catch (RegistrationRefused $refused) {
            self::assertSame($expectedRefusals, $refused->refusals);
            self::assertStringNotContainsString($password, $refused->getMessage());
        }

        self::assertSame([], $this->users->added);
        self::assertSame([], $this->credentials->added);
        self::assertSame(0, $this->hashCount);
    }

    #[Test]
    public function anEmailAnotherTransactionTookAfterTheCheckIsRefusedAsTakenAndGetsNoCredential(): void
    {
        $this->users->isEmailTakenOnAdd = true;

        try {
            $this->registration()->register('omar@acme-foods.example', 'Omar', self::PASSWORD, $this->now);
            self::fail('RegistrationRefused expected.');
        } catch (RegistrationRefused $refused) {
            self::assertSame([RegistrationRefusal::EmailTaken], $refused->refusals);
            self::assertInstanceOf(EmailTaken::class, $refused->getPrevious());
        }

        self::assertSame([], $this->credentials->added);
    }

    private function registration(): RegisterInvitedPerson
    {
        return new RegisterInvitedPerson($this->users, $this->credentials, $this->hashing());
    }

    private function hashing(): PasswordHashing
    {
        $countHash = function (): void {
            ++$this->hashCount;
        };

        return new readonly class($countHash) implements PasswordHashing {
            /**
             * @param \Closure(): void $countHash
             */
            public function __construct(private \Closure $countHash)
            {
            }

            public function hash(NewPassword $password): PasswordHash
            {
                ($this->countHash)();

                return new PasswordHash('$plain$'.$password->reveal());
            }

            public function matches(PasswordHash $hash, #[\SensitiveParameter] string $typedPassword): bool
            {
                return $hash->encoded() === '$plain$'.$typedPassword;
            }
        };
    }
}
