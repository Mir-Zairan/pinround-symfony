<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Infrastructure\Security;

use App\Identity\Domain\Credentials;
use App\Identity\Domain\Users;
use App\Identity\Infrastructure\Security\SecurityUserProvider;
use App\Identity\Infrastructure\Security\SymfonyPendingLogin;
use App\SharedKernel\Domain\UserId;
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
 * The pending login in the session. Its finish, the login itself, runs through the firewall in LoginCodeTest.
 */
final class SymfonyPendingLoginTest extends TestCase
{
    private const string OMAR = '019a5f00-0000-7000-8000-000000000001';
    private const string MIA = '019a5f00-0000-7000-8000-000000000002';

    private Session $session;
    private SymfonyPendingLogin $pendingLogin;

    protected function setUp(): void
    {
        $this->session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($this->session);
        $requestStack = new RequestStack();
        $requestStack->push($request);
        $this->pendingLogin = new SymfonyPendingLogin(
            $requestStack,
            new SecurityUserProvider(self::createStub(Users::class), self::createStub(Credentials::class)),
            new Security(new Container()),
            self::createStub(UrlGeneratorInterface::class),
        );
    }

    #[Test]
    public function aBegunLoginIsStoredAsTheUserIdAndUnixSecondsAndReadBackForFiveMinutes(): void
    {
        $passwordTypedAt = new \DateTimeImmutable('2027-03-15 11:01:15.75 Europe/Berlin');

        $this->pendingLogin->begin(new UserId(self::OMAR), $passwordTypedAt);

        self::assertSame(['identity.pending_login_user_id' => self::OMAR, 'identity.pending_login_at' => 1_805_104_875], $this->session->all());
        self::assertSame(self::OMAR, $this->pendingLogin->userIdAt($passwordTypedAt)?->toString());
        self::assertSame(self::OMAR, $this->pendingLogin->userIdAt(new \DateTimeImmutable('2027-03-15 10:06:15 UTC'))?->toString());
        self::assertNull($this->pendingLogin->userIdAt(new \DateTimeImmutable('2027-03-15 10:06:16 UTC')));
    }

    #[Test]
    public function aNewLoginReplacesTheOneThatWaited(): void
    {
        $this->pendingLogin->begin(new UserId(self::OMAR), new \DateTimeImmutable('2027-03-15 10:00:00 UTC'));

        $this->pendingLogin->begin(new UserId(self::MIA), new \DateTimeImmutable('2027-03-15 10:04:00 UTC'));

        self::assertSame(self::MIA, $this->pendingLogin->userIdAt(new \DateTimeImmutable('2027-03-15 10:08:00 UTC'))?->toString());
    }

    #[Test]
    public function forgettingRemovesOnlyThePendingLogin(): void
    {
        $this->session->set('organization.chosen_id', 'kept');
        $this->pendingLogin->begin(new UserId(self::OMAR), new \DateTimeImmutable('2027-03-15 10:00:00 UTC'));

        $this->pendingLogin->forget();

        self::assertSame(['organization.chosen_id' => 'kept'], $this->session->all());
        self::assertNull($this->pendingLogin->userIdAt(new \DateTimeImmutable('2027-03-15 10:00:00 UTC')));
    }

    #[Test]
    public function aSessionWithoutBothValuesOrWithAnotherKindOfValueHasNoPendingLogin(): void
    {
        $now = new \DateTimeImmutable('2027-03-15 10:00:00 UTC');
        self::assertNull($this->pendingLogin->userIdAt($now));

        $this->session->set('identity.pending_login_user_id', self::OMAR);
        self::assertNull($this->pendingLogin->userIdAt($now), 'without the time');

        $this->session->set('identity.pending_login_at', (string) $now->getTimestamp());
        self::assertNull($this->pendingLogin->userIdAt($now), 'with the time as a string');

        $this->session->set('identity.pending_login_at', $now->getTimestamp());
        $this->session->remove('identity.pending_login_user_id');
        self::assertNull($this->pendingLogin->userIdAt($now), 'without the user');
    }
}
