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
use App\Identity\Infrastructure\Security\AskForTwoStepCode;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Identity\Infrastructure\Security\SecurityUserProvider;
use App\Identity\Infrastructure\Security\SymfonyPendingLogin;
use App\Identity\Infrastructure\Security\SymfonySessionConfirmations;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\Identity\Application\InMemoryTwoStepLogins;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * With the session-backed pending login and session times on a mock session; the whole login runs through the
 * firewall in LoginCodeTest. The clock is far from the wall clock, so a time from PHP's time() would differ.
 */
final class AskForTwoStepCodeTest extends TestCase
{
    private const string LENA = '0195a1b2-0000-7000-8000-0000000000aa';
    private const int NOW = 1_805_104_875;

    private Session $session;
    private Request $request;
    private TokenStorage $tokenStorage;
    private InMemoryTwoStepLogins $twoStepLogins;
    private AskForTwoStepCode $listener;

    protected function setUp(): void
    {
        $this->session = new Session(new MockArraySessionStorage());
        $this->request = new Request();
        $this->request->setSession($this->session);
        $requestStack = new RequestStack();
        $requestStack->push($this->request);
        $this->tokenStorage = new TokenStorage();
        $this->twoStepLogins = new InMemoryTwoStepLogins();
        $urlGenerator = self::createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(static fn (string $route): string => 'identity_login_code' === $route ? '/login/code' : '/unexpected');
        $this->listener = new AskForTwoStepCode(
            $this->twoStepLogins,
            new SymfonyPendingLogin(
                $requestStack,
                new SecurityUserProvider(self::createStub(Users::class), self::createStub(Credentials::class)),
                new Security(new Container()),
                $urlGenerator,
            ),
            new SymfonySessionConfirmations($requestStack),
            $this->tokenStorage,
            $urlGenerator,
            new MockClock(new \DateTimeImmutable('@'.self::NOW)),
        );
    }

    #[Test]
    public function withoutTwoStepLoginTheLoginStandsAndItsTimeIsRecorded(): void
    {
        $event = $this->loginOf(self::lena(), new RedirectResponse('/'));

        ($this->listener)($event);

        self::assertSame(['identity.recent_auth_at' => self::NOW], $this->session->all());
        self::assertNotNull($this->tokenStorage->getToken());
        self::assertSame('/', self::targetOf($event->getResponse()));
    }

    #[Test]
    public function aTwoStepLoginStillAwaitingItsFirstCodeCountsAsOff(): void
    {
        $this->twoStepLogins->save(new TwoStepLogin(self::lena(), 'encrypted seed', new \DateTimeImmutable('2027-03-15 09:00:00 UTC')));
        $event = $this->loginOf(self::lena(), new RedirectResponse('/'));

        ($this->listener)($event);

        self::assertSame(['identity.recent_auth_at' => self::NOW], $this->session->all());
        self::assertNotNull($this->tokenStorage->getToken());
    }

    #[Test]
    public function withTwoStepLoginOnNobodyIsLoggedInAndThePersonIsSentToTheCodePage(): void
    {
        $this->turnOnTwoStepLogin();
        $event = $this->loginOf(self::lena(), new RedirectResponse('https://localhost/me/security'));

        ($this->listener)($event);

        self::assertNull($this->tokenStorage->getToken());
        self::assertSame('/login/code', self::targetOf($event->getResponse()));
        self::assertSame([
            'identity.pending_login_user_id' => self::LENA,
            'identity.pending_login_at' => self::NOW,
            '_security.main.target_path' => 'https://localhost/me/security',
        ], $this->session->all());
    }

    #[Test]
    public function withTwoStepLoginOnAndNoRedirectToKeepOnlyThePendingLoginIsStored(): void
    {
        $this->turnOnTwoStepLogin();
        $event = $this->loginOf(self::lena(), null);

        ($this->listener)($event);

        self::assertNull($this->tokenStorage->getToken());
        self::assertSame('/login/code', self::targetOf($event->getResponse()));
        self::assertSame(['identity.pending_login_user_id' => self::LENA, 'identity.pending_login_at' => self::NOW], $this->session->all());
    }

    #[Test]
    public function theLoginThatFinishesWithACodeIsLeftAlone(): void
    {
        $this->turnOnTwoStepLogin();
        $event = $this->loginOf(self::lena(), new RedirectResponse('/'), codeAccepted: true);

        ($this->listener)($event);

        self::assertSame([], $this->session->all());
        self::assertNotNull($this->tokenStorage->getToken());
        self::assertSame('/', self::targetOf($event->getResponse()));
    }

    #[Test]
    public function aLoginOnAnotherFirewallIsLeftAlone(): void
    {
        $this->turnOnTwoStepLogin();
        $event = $this->loginOf(self::lena(), new RedirectResponse('/'), firewallName: 'api');

        ($this->listener)($event);

        self::assertSame([], $this->session->all());
        self::assertNotNull($this->tokenStorage->getToken());
    }

    #[Test]
    public function anotherKindOfUserIsRefused(): void
    {
        $event = $this->loginOf(new InMemoryUser('lena@acme-foods.example', null), new RedirectResponse('/'));

        $this->expectException(\LogicException::class);

        ($this->listener)($event);
    }

    private function turnOnTwoStepLogin(): void
    {
        $twoStepLogin = new TwoStepLogin(self::lena(), 'encrypted seed', new \DateTimeImmutable('2027-03-15 09:00:00 UTC'));
        $twoStepLogin->confirm(60_170_000, new \DateTimeImmutable('2027-03-15 09:01:00 UTC'), ['hash of a recovery code']);
        $this->twoStepLogins->save($twoStepLogin);
    }

    private function loginOf(User|UserInterface $user, ?Response $response, bool $codeAccepted = false, string $firewallName = 'main'): LoginSuccessEvent
    {
        $securityUser = $user instanceof User ? SecurityUser::of($user, new Credential($user, new PasswordHash('$argon2id$v=19$m=10,t=3,p=1$c2FsdA$aGFzaA'), new \DateTimeImmutable('2026-11-02 09:30:00 UTC'))) : $user;
        $passport = new SelfValidatingPassport(new UserBadge($securityUser->getUserIdentifier(), static fn (): UserInterface => $securityUser));
        if ($codeAccepted) {
            $passport->setAttribute(AskForTwoStepCode::CODE_ACCEPTED_ATTRIBUTE, true);
        }
        $token = new UsernamePasswordToken($securityUser, $firewallName, $securityUser->getRoles());
        $this->tokenStorage->setToken($token);

        return new LoginSuccessEvent(self::createStub(AuthenticatorInterface::class), $passport, $token, $this->request, $response, $firewallName);
    }

    private static function lena(): User
    {
        return new User(new UserId(self::LENA), new Email('lena@acme-foods.example'), 'Lena Berg', 'en', 'Europe/Berlin', new \DateTimeImmutable('2026-11-01 08:00:00 UTC'));
    }

    private static function targetOf(?Response $response): ?string
    {
        return $response instanceof RedirectResponse ? $response->getTargetUrl() : null;
    }
}
