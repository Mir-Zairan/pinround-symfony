<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Infrastructure\Security;

use App\Identity\Api\ApiTokenAccess;
use App\Identity\Application\RecentAuth;
use App\Identity\Domain\Credential;
use App\Identity\Domain\Email;
use App\Identity\Domain\PasswordHash;
use App\Identity\Domain\TwoStepLogin;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Security\ApiTokenAuthentication;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Identity\Infrastructure\Security\SymfonyLoginAssurance;
use App\Identity\Infrastructure\Security\SymfonySessionConfirmations;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\Identity\Application\InMemoryTwoStepLogins;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Test\TestBrowserToken;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\RememberMeToken;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

/**
 * With an in-memory two-step login store, a request whose session the real SymfonySessionConfirmations reads, and a
 * clock fixed away from the wall clock.
 */
final class SymfonyLoginAssuranceTest extends TestCase
{
    private const string LENA = '0197a3c0-0000-7000-8000-0000000000aa';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000000bb';
    private const string HASH = '$argon2id$v=19$m=65536,t=4,p=1$NUkxU2sxQlFxbXpDaUZUQQ$suVp+0jzKZuqeONGVmUghGQxxI2vd02CMokdMtaQcfA';
    private const string NOW = '2027-03-15 10:00:00 UTC';
    private const string TURNED_ON_AT = '2027-03-15 08:00:00 UTC';

    private InMemoryTwoStepLogins $twoStepLogins;
    private RequestStack $requestStack;
    private SymfonySessionConfirmations $sessionConfirmations;

    protected function setUp(): void
    {
        $this->twoStepLogins = new InMemoryTwoStepLogins();
        $this->requestStack = new RequestStack();
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $this->requestStack->push($request);
        $this->sessionConfirmations = new SymfonySessionConfirmations($this->requestStack);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function tokensThatAreNoPasswordSession(): iterable
    {
        $lena = self::lena();

        yield 'remember-me' => [new RememberMeToken($lena, 'main')];
        yield 'a personal access token login' => [self::tokenLoginOfLena()];
        yield 'an authenticator without a token class of its own, as an access token login' => [new PostAuthenticationToken($lena, 'main', $lena->getRoles())];
        yield 'impersonation' => [new SwitchUserToken($lena, 'main', $lena->getRoles(), new UsernamePasswordToken($lena, 'main'))];
        yield 'the test browser login' => [new TestBrowserToken($lena->getRoles(), $lena, 'main')];
        yield 'a form login of a user Identity did not log in' => [new UsernamePasswordToken(new InMemoryUser('lena@acme-foods.example', null), 'main')];
        yield 'anonymous' => [new NullToken()];
        yield 'no token' => [null];
    }

    #[Test]
    public function theFormLoginOfAUserIsAPasswordSession(): void
    {
        self::assertTrue($this->assurance()->isPasswordSession(self::passwordSessionOfLena()));
    }

    /**
     * Lena has two-step login on and a fresh code proof, so only the token kind refuses.
     */
    #[Test]
    #[DataProvider('tokensThatAreNoPasswordSession')]
    public function everyOtherTokenKindIsNoPasswordSessionAndGetsNoTwoStepAnswer(mixed $token): void
    {
        $this->turnOnTwoStepLogin(self::LENA, self::TURNED_ON_AT);
        $this->sessionConfirmations->recordTwoStepConfirmation(new UserId(self::LENA), self::now());
        $assurance = $this->assurance();

        self::assertFalse($assurance->isPasswordSession($token));
        self::assertFalse($assurance->isTwoStepLoginOn($token));
        self::assertFalse($assurance->hasConfirmedTwoStepLoginWithin($token, 3600));
    }

    #[Test]
    public function onlyAPersonalAccessTokenLoginIsTokenAuthenticated(): void
    {
        $assurance = $this->assurance();

        self::assertTrue($assurance->isTokenAuthenticated(self::tokenLoginOfLena()));
        foreach (self::tokensThatAreNoPasswordSession() as $name => [$token]) {
            if ($token instanceof ApiTokenAuthentication) {
                continue;
            }
            self::assertFalse($assurance->isTokenAuthenticated($token), $name);
        }
        self::assertFalse($assurance->isTokenAuthenticated(self::passwordSessionOfLena()));
    }

    #[Test]
    public function twoStepLoginIsOnOnlyOnceConfirmed(): void
    {
        self::assertFalse($this->assurance()->isTwoStepLoginOn(self::passwordSessionOfLena()), 'none');

        $awaitingCode = new TwoStepLogin(self::lenaUser(), 'encrypted seed', new \DateTimeImmutable(self::TURNED_ON_AT));
        $this->twoStepLogins->save($awaitingCode);
        self::assertFalse($this->assurance()->isTwoStepLoginOn(self::passwordSessionOfLena()), 'awaiting its first code');

        $awaitingCode->confirm(1, new \DateTimeImmutable(self::TURNED_ON_AT), ['$argon2id$hash']);
        self::assertTrue($this->assurance()->isTwoStepLoginOn(self::passwordSessionOfLena()));
    }

    #[Test]
    public function aCodeOfThisSessionCountsWithinTheSecondsAsked(): void
    {
        $this->turnOnTwoStepLogin(self::LENA, self::TURNED_ON_AT);
        $this->sessionConfirmations->recordTwoStepConfirmation(new UserId(self::LENA), self::now()->modify('-1 hour'));
        $assurance = $this->assurance();

        self::assertTrue($assurance->hasConfirmedTwoStepLoginWithin(self::passwordSessionOfLena(), 3600));
        self::assertFalse($assurance->hasConfirmedTwoStepLoginWithin(self::passwordSessionOfLena(), 3599));
    }

    #[Test]
    public function aCodeRecordedForAnotherUserOfTheSessionDoesNotCount(): void
    {
        $this->turnOnTwoStepLogin(self::LENA, self::TURNED_ON_AT);
        $this->sessionConfirmations->recordTwoStepConfirmation(new UserId(self::OMAR), self::now());

        self::assertFalse($this->assurance()->hasConfirmedTwoStepLoginWithin(self::passwordSessionOfLena(), 3600));
    }

    #[Test]
    public function aCodeFromBeforeTwoStepLoginWasLastTurnedOnDoesNotCount(): void
    {
        $this->turnOnTwoStepLogin(self::LENA, '2027-03-15 09:30:01 UTC');
        $this->sessionConfirmations->recordTwoStepConfirmation(new UserId(self::LENA), new \DateTimeImmutable('2027-03-15 09:30:00 UTC'));

        self::assertFalse($this->assurance()->hasConfirmedTwoStepLoginWithin(self::passwordSessionOfLena(), 3600));
    }

    #[Test]
    public function aCodeDoesNotCountOnceTwoStepLoginIsOff(): void
    {
        $twoStepLogin = $this->turnOnTwoStepLogin(self::LENA, self::TURNED_ON_AT);
        $this->sessionConfirmations->recordTwoStepConfirmation(new UserId(self::LENA), self::now());
        self::assertTrue($this->assurance()->hasConfirmedTwoStepLoginWithin(self::passwordSessionOfLena(), 3600));

        $this->twoStepLogins->remove($twoStepLogin);

        self::assertFalse($this->assurance()->isTwoStepLoginOn(self::passwordSessionOfLena()));
        self::assertFalse($this->assurance()->hasConfirmedTwoStepLoginWithin(self::passwordSessionOfLena(), 3600));
    }

    #[Test]
    public function withoutARequestSessionThereIsNoCodeProof(): void
    {
        $this->turnOnTwoStepLogin(self::LENA, self::TURNED_ON_AT);
        $this->requestStack->pop();

        self::assertFalse($this->assurance()->hasConfirmedTwoStepLoginWithin(self::passwordSessionOfLena(), 3600));
        self::assertTrue($this->assurance()->isTwoStepLoginOn(self::passwordSessionOfLena()));
    }

    private function assurance(): SymfonyLoginAssurance
    {
        return new SymfonyLoginAssurance($this->twoStepLogins, new RecentAuth($this->sessionConfirmations, $this->twoStepLogins), new MockClock(self::NOW));
    }

    private function turnOnTwoStepLogin(string $userId, string $turnedOnAt): TwoStepLogin
    {
        $user = new User(new UserId($userId), new Email('person-'.substr($userId, -2).'@acme-foods.example'), 'A person', 'en', 'Europe/Berlin', new \DateTimeImmutable('2026-11-02 09:30:00 UTC'));
        $twoStepLogin = new TwoStepLogin($user, 'encrypted seed', new \DateTimeImmutable($turnedOnAt));
        $twoStepLogin->confirm(1, new \DateTimeImmutable($turnedOnAt), ['$argon2id$hash']);
        $this->twoStepLogins->save($twoStepLogin);

        return $twoStepLogin;
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW);
    }

    private static function passwordSessionOfLena(): UsernamePasswordToken
    {
        return new UsernamePasswordToken(self::lena(), 'main');
    }

    private static function tokenLoginOfLena(): ApiTokenAuthentication
    {
        return new ApiTokenAuthentication(self::lena(), new ApiTokenAccess('0197a3c0-0000-7000-8000-0000000000f1', new OrganizationId('0197a3c0-0000-7000-8000-0000000000a1'), ['read', 'rounds:write'], new \DateTimeImmutable('2027-01-31 09:30:00 UTC')), null);
    }

    private static function lenaUser(): User
    {
        return new User(new UserId(self::LENA), new Email('lena@acme-foods.example'), 'Lena Berg', 'en', 'Europe/Berlin', new \DateTimeImmutable('2026-11-02 09:30:00 UTC'));
    }

    private static function lena(): SecurityUser
    {
        $user = self::lenaUser();

        return SecurityUser::of($user, new Credential($user, new PasswordHash(self::HASH), new \DateTimeImmutable('2026-11-02 09:30:00 UTC')));
    }
}
