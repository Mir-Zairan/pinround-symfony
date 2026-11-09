<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Infrastructure\Security;

use App\Identity\Domain\Credential;
use App\Identity\Domain\Credentials;
use App\Identity\Domain\Email;
use App\Identity\Domain\PasswordHash;
use App\Identity\Domain\TwoStepLogin;
use App\Identity\Domain\User;
use App\Identity\Domain\Users;
use App\Identity\Infrastructure\Security\SecurityUserProvider;
use App\Identity\Infrastructure\Security\SymfonyPendingLogin;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\Identity\Application\InMemoryTwoStepLogins;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The pending login in the session, for users whose two-step login is on. Its finish, the login itself, runs through
 * the firewall in LoginCodeTest.
 */
final class SymfonyPendingLoginTest extends TestCase
{
    private const string OMAR = '019a5f00-0000-7000-8000-000000000001';
    private const string MIA = '019a5f00-0000-7000-8000-000000000002';

    private Session $session;
    /** @var array<string, User> */
    private array $users = [];
    private InMemoryTwoStepLogins $twoStepLogins;
    private SymfonyPendingLogin $pendingLogin;

    protected function setUp(): void
    {
        $this->session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($this->session);
        $this->twoStepLogins = new InMemoryTwoStepLogins();
        foreach ([self::OMAR => 'omar', self::MIA => 'mia'] as $id => $name) {
            $this->users[$id] = new User(new UserId($id), new Email($name.'@acme-foods.example'), ucfirst($name), 'en', 'UTC', new \DateTimeImmutable('2026-11-02 09:30:00 UTC'));
            $this->turnOnTwoStepLogin($this->users[$id]);
        }
        $users = self::createStub(Users::class);
        $users->method('byId')->willReturnCallback(fn (UserId $id): ?User => $this->users[$id->toString()] ?? null);
        $credentials = self::createStub(Credentials::class);
        $credentials->method('byUserId')->willReturnCallback(fn (UserId $id): ?Credential => isset($this->users[$id->toString()])
            ? new Credential($this->users[$id->toString()], new PasswordHash('$argon2id$v=19$m=10,t=3,p=1$c2FsdA$aGFzaA'), new \DateTimeImmutable('2026-11-02 09:30:00 UTC'))
            : null);
        $this->pendingLogin = new SymfonyPendingLogin(
            new RequestStack([$request]),
            new SecurityUserProvider($users, $credentials),
            $this->twoStepLogins,
            new Security(new Container()),
            self::createStub(UrlGeneratorInterface::class),
        );
    }

    #[Test]
    public function aBegunLoginIsStoredWithTheSessionStampAndReadBackForFiveMinutes(): void
    {
        $passwordTypedAt = new \DateTimeImmutable('2027-03-15 11:01:15.75 Europe/Berlin');

        $this->beginFor(self::OMAR, $passwordTypedAt);

        self::assertSame([
            'identity.pending_login_user_id' => self::OMAR,
            'identity.pending_login_session_stamp' => $this->users[self::OMAR]->sessionStamp(),
            'identity.pending_login_at' => 1_805_104_875,
        ], $this->session->all());
        self::assertSame(self::OMAR, $this->pendingLogin->userIdAt($passwordTypedAt)?->toString());
        self::assertSame(self::OMAR, $this->pendingLogin->userIdAt(new \DateTimeImmutable('2027-03-15 10:06:15 UTC'))?->toString());
        self::assertNull($this->pendingLogin->userIdAt(new \DateTimeImmutable('2027-03-15 10:06:16 UTC')));
    }

    #[Test]
    public function aNewLoginReplacesTheOneThatWaited(): void
    {
        $this->beginFor(self::OMAR, new \DateTimeImmutable('2027-03-15 10:00:00 UTC'));

        $this->beginFor(self::MIA, new \DateTimeImmutable('2027-03-15 10:04:00 UTC'));

        self::assertSame(self::MIA, $this->pendingLogin->userIdAt(new \DateTimeImmutable('2027-03-15 10:08:00 UTC'))?->toString());
    }

    /**
     * ChangePassword, ResetPassword and LogOutEverywhere all rotate the stamp.
     */
    #[Test]
    public function anotherSessionStampSinceThePasswordEndsThePendingLogin(): void
    {
        $now = new \DateTimeImmutable('2027-03-15 10:00:00 UTC');
        $this->beginFor(self::OMAR, $now);

        $this->users[self::OMAR]->rotateSessionStamp();

        self::assertNull($this->pendingLogin->userIdAt($now));
        self::assertNull($this->pendingLogin->finish(new UserId(self::OMAR)));
        self::assertSame([], $this->session->all(), 'the refused finish kept the pending login');
    }

    #[Test]
    public function aFinishForAnotherUserThanTheOneWaitingLogsNobodyIn(): void
    {
        $this->beginFor(self::OMAR, new \DateTimeImmutable('2027-03-15 10:00:00 UTC'));

        self::assertNull($this->pendingLogin->finish(new UserId(self::MIA)));
        self::assertSame([], $this->session->all());
    }

    #[Test]
    public function twoStepLoginTurnedOffSinceThePasswordEndsThePendingLogin(): void
    {
        $now = new \DateTimeImmutable('2027-03-15 10:00:00 UTC');
        $this->beginFor(self::OMAR, $now);

        $twoStepLogin = $this->twoStepLogins->byUserId(new UserId(self::OMAR));
        self::assertNotNull($twoStepLogin);
        $this->twoStepLogins->remove($twoStepLogin);

        self::assertNull($this->pendingLogin->userIdAt($now));
    }

    #[Test]
    public function aUserWhoIsGoneEndsThePendingLogin(): void
    {
        $now = new \DateTimeImmutable('2027-03-15 10:00:00 UTC');
        $this->beginFor(self::OMAR, $now);

        unset($this->users[self::OMAR]);

        self::assertNull($this->pendingLogin->userIdAt($now));
    }

    #[Test]
    public function forgettingRemovesOnlyThePendingLogin(): void
    {
        $this->session->set('organization.chosen_id', 'kept');
        $this->beginFor(self::OMAR, new \DateTimeImmutable('2027-03-15 10:00:00 UTC'));

        $this->pendingLogin->forget();

        self::assertSame(['organization.chosen_id' => 'kept'], $this->session->all());
        self::assertNull($this->pendingLogin->userIdAt(new \DateTimeImmutable('2027-03-15 10:00:00 UTC')));
    }

    #[Test]
    public function aSessionWithoutAllThreeValuesOrWithAnotherKindOfValueHasNoPendingLogin(): void
    {
        $now = new \DateTimeImmutable('2027-03-15 10:00:00 UTC');
        self::assertNull($this->pendingLogin->userIdAt($now));
        $this->beginFor(self::OMAR, $now);
        self::assertNotNull($this->pendingLogin->userIdAt($now));

        foreach (['identity.pending_login_user_id', 'identity.pending_login_session_stamp', 'identity.pending_login_at'] as $key) {
            $this->beginFor(self::OMAR, $now);
            $this->session->remove($key);
            self::assertNull($this->pendingLogin->userIdAt($now), 'without '.$key);
        }

        $this->beginFor(self::OMAR, $now);
        $this->session->set('identity.pending_login_at', (string) $now->getTimestamp());
        self::assertNull($this->pendingLogin->userIdAt($now), 'with the time as a string');
    }

    private function beginFor(string $userId, \DateTimeImmutable $now): void
    {
        $this->pendingLogin->begin(new UserId($userId), $this->users[$userId]->sessionStamp(), $now);
    }

    private function turnOnTwoStepLogin(User $user): void
    {
        $twoStepLogin = new TwoStepLogin($user, 'encrypted seed', new \DateTimeImmutable('2027-03-15 09:00:00 UTC'));
        $twoStepLogin->confirm(60_170_000, new \DateTimeImmutable('2027-03-15 09:01:00 UTC'), ['hash of a recovery code']);
        $this->twoStepLogins->save($twoStepLogin);
    }
}
