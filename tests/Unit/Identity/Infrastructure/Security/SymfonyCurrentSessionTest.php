<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Infrastructure\Security;

use App\Identity\Domain\Credentials;
use App\Identity\Domain\Users;
use App\Identity\Infrastructure\Security\SecurityUserProvider;
use App\Identity\Infrastructure\Security\SymfonyCurrentSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * Only what needs no firewall: without a logged-in SecurityUser every method refuses before it touches the session.
 * SecurityPageTest covers the logged-in paths through the real firewall.
 */
final class SymfonyCurrentSessionTest extends TestCase
{
    /**
     * @return iterable<string, array{\Closure(SymfonyCurrentSession): mixed}>
     */
    public static function methods(): iterable
    {
        yield 'userId' => [static fn (SymfonyCurrentSession $session): mixed => $session->userId()];
        yield 'keepAfterPasswordChange' => [static function (SymfonyCurrentSession $session): void {
            $session->keepAfterPasswordChange();
        }];
        yield 'end' => [static function (SymfonyCurrentSession $session): void {
            $session->end();
        }];
    }

    #[Test]
    #[DataProvider('methods')]
    public function withoutATokenItRefuses(\Closure $call): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Nobody is logged in.');

        $call($this->currentSession(new TokenStorage()));
    }

    #[Test]
    #[DataProvider('methods')]
    public function withAnotherKindOfUserItRefuses(\Closure $call): void
    {
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken(new InMemoryUser('omar@acme-foods.example', null), 'main'));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Nobody is logged in.');

        $call($this->currentSession($tokenStorage));
    }

    private function currentSession(TokenStorage $tokenStorage): SymfonyCurrentSession
    {
        $security = $this->createMock(Security::class);
        $security->expects(self::never())->method('logout');

        return new SymfonyCurrentSession(
            $tokenStorage,
            new SecurityUserProvider(self::createStub(Users::class), self::createStub(Credentials::class)),
            new RequestStack(),
            $security,
        );
    }
}
