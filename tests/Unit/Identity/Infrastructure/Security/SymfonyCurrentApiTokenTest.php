<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Infrastructure\Security;

use App\Identity\Api\ApiTokenAccess;
use App\Identity\Domain\Credential;
use App\Identity\Domain\Email;
use App\Identity\Domain\PasswordHash;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Security\ApiTokenAuthentication;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Identity\Infrastructure\Security\SymfonyCurrentApiToken;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\RememberMeToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

final class SymfonyCurrentApiTokenTest extends TestCase
{
    private const string LENA = '0197a3c0-0000-7000-8000-0000000000aa';

    #[Test]
    public function aRequestAPersonalAccessTokenAuthenticatedHasThatTokensAccess(): void
    {
        $access = new ApiTokenAccess('0197a3c0-0000-7000-8000-0000000000f1', new OrganizationId('0197a3c0-0000-7000-8000-0000000000b1'), ['read', 'rounds:write']);
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new ApiTokenAuthentication(self::lena(), $access, null));

        self::assertSame($access, new SymfonyCurrentApiToken($tokenStorage)->access());
    }

    #[Test]
    public function aRequestWithAnyOtherOrNoSecurityTokenHasNoAccess(): void
    {
        $lena = self::lena();
        $otherTokens = [
            'password session' => new UsernamePasswordToken($lena, 'main', $lena->getRoles()),
            'remember-me' => new RememberMeToken($lena, 'main'),
            'another authenticator' => new PostAuthenticationToken($lena, 'api_token', $lena->getRoles()),
            'anonymous' => new NullToken(),
            'none' => null,
        ];

        foreach ($otherTokens as $name => $token) {
            $tokenStorage = new TokenStorage();
            $tokenStorage->setToken($token);

            self::assertNull(new SymfonyCurrentApiToken($tokenStorage)->access(), $name);
        }
    }

    private static function lena(): SecurityUser
    {
        $user = new User(new UserId(self::LENA), new Email('lena@acme-foods.example'), 'Lena Berg', 'en', 'Europe/Berlin', new \DateTimeImmutable('2026-11-02 09:30:00 UTC'));

        return SecurityUser::of($user, new Credential($user, new PasswordHash('$argon2id$v=19$m=10,t=3,p=1$stored'), new \DateTimeImmutable('2026-11-02 09:30:00 UTC')));
    }
}
