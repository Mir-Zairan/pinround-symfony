<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\UI\Voter;

use App\Organization\Api\ActorContext;
use App\Organization\Api\OrganizationPermission;
use App\Organization\Api\Roles;
use App\Organization\UI\Voter\OrganizationVoter;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class OrganizationVoterTest extends TestCase
{
    private const string SARA = '0197a3c0-0000-7000-8000-0000000000a1';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000000a2';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000000a';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000000b';

    #[Test]
    public function aMemberViewsTheOrganizationOfTheRequestButIsNoAdmin(): void
    {
        $voter = self::voter();

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote(self::tokenOf(self::OMAR), new OrganizationId(self::ACME_FOODS), [OrganizationPermission::VIEW]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(self::tokenOf(self::OMAR), new OrganizationId(self::ACME_FOODS), [OrganizationPermission::ADMIN]));
    }

    #[Test]
    public function anAdminGetsAdminOfTheOrganizationOfTheRequest(): void
    {
        $voter = self::voter();

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote(self::tokenOf(self::SARA), new OrganizationId(self::ACME_FOODS), [OrganizationPermission::VIEW]));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote(self::tokenOf(self::SARA), new OrganizationId(self::ACME_FOODS), [OrganizationPermission::ADMIN]));
    }

    #[Test]
    public function anotherOrganizationIsDeniedEvenToAnAdmin(): void
    {
        $voter = self::voter();

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(self::tokenOf(self::SARA), new OrganizationId(self::BETA_BAKERY), [OrganizationPermission::VIEW]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(self::tokenOf(self::SARA), new OrganizationId(self::BETA_BAKERY), [OrganizationPermission::ADMIN]));
    }

    #[Test]
    public function withoutAnActorBothAreDenied(): void
    {
        $voter = self::voter();

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(new NullToken(), new OrganizationId(self::ACME_FOODS), [OrganizationPermission::VIEW]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(new NullToken(), new OrganizationId(self::ACME_FOODS), [OrganizationPermission::ADMIN]));
    }

    #[Test]
    public function itAbstainsOnTheOrganizationAttributesOfOtherVotersAndOnOtherSubjects(): void
    {
        $voter = self::voter();
        $token = self::tokenOf(self::SARA);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, new OrganizationId(self::ACME_FOODS), [OrganizationPermission::ENTER]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, new TeamId(self::ACME_FOODS), [OrganizationPermission::VIEW]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, self::ACME_FOODS, [OrganizationPermission::ADMIN]));
    }

    private static function tokenOf(string $userId): TokenInterface
    {
        return new UsernamePasswordToken(new InMemoryUser($userId, null), 'main');
    }

    /**
     * Sara is an Admin and Omar a member with no org role, both of Acme Foods, the organization of the request.
     */
    private static function voter(): OrganizationVoter
    {
        $acmeFoods = new OrganizationId(self::ACME_FOODS);

        return new OrganizationVoter(new FixedCurrentActor([
            self::SARA => new ActorContext(new UserId(self::SARA), $acmeFoods, new Roles(true, false, [], [], [])),
            self::OMAR => new ActorContext(new UserId(self::OMAR), $acmeFoods, Roles::none()),
        ]));
    }
}
