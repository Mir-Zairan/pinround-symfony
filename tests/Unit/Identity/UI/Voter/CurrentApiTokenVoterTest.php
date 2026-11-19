<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\UI\Voter;

use App\Identity\Api\ApiTokenAccess;
use App\Identity\Application\AccountPermission;
use App\Identity\Domain\Credential;
use App\Identity\Domain\Email;
use App\Identity\Domain\PasswordHash;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Security\ApiTokenAuthentication;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Identity\UI\Voter\CurrentApiTokenVoter;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\Organization\UI\Voter\FixedLoginAssurance;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class CurrentApiTokenVoterTest extends TestCase
{
    private const string HASH = '$argon2id$v=19$m=65536,t=4,p=1$NUkxU2sxQlFxbXpDaUZUQQ$suVp+0jzKZuqeONGVmUghGQxxI2vd02CMokdMtaQcfA';

    #[Test]
    public function aTokenLoginMayRevokeItselfAndABrowserSessionOrNobodyMayNotAndSaysWhy(): void
    {
        $voter = new CurrentApiTokenVoter(new FixedLoginAssurance(true, 0));
        $vote = new Vote();

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote(self::tokenLogin(), null, [AccountPermission::REVOKE_CURRENT_TOKEN]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(new UsernamePasswordToken(self::mia(), 'main'), null, [AccountPermission::REVOKE_CURRENT_TOKEN], $vote));
        self::assertSame([CurrentApiTokenVoter::SESSION_REFUSAL_REASON], $vote->reasons);
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(new NullToken(), null, [AccountPermission::REVOKE_CURRENT_TOKEN]));
    }

    #[Test]
    public function itAbstainsOnOtherAttributesAndOnASubject(): void
    {
        $voter = new CurrentApiTokenVoter(new FixedLoginAssurance(true, 0));

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote(self::tokenLogin(), null, [AccountPermission::EDIT_OWN]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote(self::tokenLogin(), 'another token', [AccountPermission::REVOKE_CURRENT_TOKEN]));
    }

    private static function tokenLogin(): ApiTokenAuthentication
    {
        $access = new ApiTokenAccess('0197a3c0-0000-7000-8000-0000000f91f1', new OrganizationId('0197a3c0-0000-7000-8000-0000000f910a'), ['read'], new \DateTimeImmutable('2027-01-31 09:30:00 UTC'));

        return new ApiTokenAuthentication(self::mia(), $access, null);
    }

    private static function mia(): SecurityUser
    {
        $user = new User(new UserId('0197a3c0-0000-7000-8000-0000000f9101'), new Email('mia@acme-foods.example'), 'Mia Berg', 'en', 'Europe/Berlin', new \DateTimeImmutable('2026-11-02 09:30:00 UTC'));

        return SecurityUser::of($user, new Credential($user, new PasswordHash(self::HASH), new \DateTimeImmutable('2026-11-02 09:30:00 UTC')));
    }
}
