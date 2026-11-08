<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\UI\Voter;

use App\Organization\Api\ActorContext;
use App\Organization\Api\AdminPermission;
use App\Organization\Api\OrganizationPermission;
use App\Organization\Api\Roles;
use App\Organization\UI\Voter\AdminAreaVoter;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\RememberMeToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class AdminAreaVoterTest extends TestCase
{
    private const string SARA = '0197a3c0-0000-7000-8000-0000000000a1';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000000a2';
    private const string RAJ = '0197a3c0-0000-7000-8000-0000000000a3';
    private const string NOBODY = '0197a3c0-0000-7000-8000-0000000000a4';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000000a';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000000b';

    /**
     * @return iterable<string, array{bool}>
     */
    public static function hackathonFlag(): iterable
    {
        yield 'flag on' => [true];
        yield 'flag off' => [false];
    }

    #[Test]
    public function anAdminWithAPasswordSessionOpensTheAdminAreaWithoutTwoStepLoginOnlyWhileTheHackathonFlagIsOn(): void
    {
        $token = self::passwordSessionOf(self::SARA);

        self::assertSame(VoterInterface::ACCESS_GRANTED, self::voter(isTwoStepLoginWaived: true, hasConfirmedTwoStepLogin: false)->vote($token, self::acmeFoods(), [AdminPermission::AREA]));
        self::assertSame(VoterInterface::ACCESS_DENIED, self::voter(isTwoStepLoginWaived: false, hasConfirmedTwoStepLogin: false)->vote($token, self::acmeFoods(), [AdminPermission::AREA]));
    }

    #[Test]
    public function anAdminWithAPasswordSessionAndAConfirmedTwoStepLoginNeedsNoFlag(): void
    {
        $voter = self::voter(isTwoStepLoginWaived: false, hasConfirmedTwoStepLogin: true);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote(self::passwordSessionOf(self::SARA), self::acmeFoods(), [AdminPermission::AREA]));
    }

    /**
     * Remember-me stands for every token that is not a password session, the personal access token of P09 included.
     */
    #[Test]
    #[DataProvider('hackathonFlag')]
    public function anAdminWhoseLoginIsNotAPasswordSessionIsDeniedEvenWithTwoStepLogin(bool $isTwoStepLoginWaived): void
    {
        $voter = self::voter($isTwoStepLoginWaived, hasConfirmedTwoStepLogin: true);
        $rememberMe = new RememberMeToken(new InMemoryUser(self::SARA, null), 'main');

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($rememberMe, self::acmeFoods(), [AdminPermission::AREA]));
    }

    #[Test]
    #[DataProvider('hackathonFlag')]
    public function aManagerAMemberAnOutsiderAndAnAnonymousRequestAreDenied(bool $isTwoStepLoginWaived): void
    {
        $voter = self::voter($isTwoStepLoginWaived, hasConfirmedTwoStepLogin: true);

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(self::passwordSessionOf(self::RAJ), self::acmeFoods(), [AdminPermission::AREA]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(self::passwordSessionOf(self::OMAR), self::acmeFoods(), [AdminPermission::AREA]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(self::passwordSessionOf(self::NOBODY), self::acmeFoods(), [AdminPermission::AREA]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(new NullToken(), self::acmeFoods(), [AdminPermission::AREA]));
    }

    #[Test]
    #[DataProvider('hackathonFlag')]
    public function anAdminIsDeniedTheAdminAreaOfAnotherOrganization(bool $isTwoStepLoginWaived): void
    {
        $voter = self::voter($isTwoStepLoginWaived, hasConfirmedTwoStepLogin: true);

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(self::passwordSessionOf(self::SARA), new OrganizationId(self::BETA_BAKERY), [AdminPermission::AREA]));
    }

    #[Test]
    public function itAbstainsOnOtherAttributesAndOnASubjectThatIsNoOrganization(): void
    {
        $voter = self::voter(isTwoStepLoginWaived: true, hasConfirmedTwoStepLogin: true);
        $token = self::passwordSessionOf(self::SARA);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, self::acmeFoods(), [OrganizationPermission::ADMIN]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, null, [AdminPermission::AREA]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, new TeamId(self::ACME_FOODS), [AdminPermission::AREA]));
    }

    private static function passwordSessionOf(string $userId): TokenInterface
    {
        return new UsernamePasswordToken(new InMemoryUser($userId, null), 'main');
    }

    private static function acmeFoods(): OrganizationId
    {
        return new OrganizationId(self::ACME_FOODS);
    }

    /**
     * Sara is an Admin, Raj a Manager and Omar a member with no org role, all of Acme Foods, the organization of the
     * request; Nobody has no actor.
     */
    private static function voter(bool $isTwoStepLoginWaived, bool $hasConfirmedTwoStepLogin): AdminAreaVoter
    {
        $acmeFoods = self::acmeFoods();
        $currentActor = new FixedCurrentActor([
            self::SARA => new ActorContext(new UserId(self::SARA), $acmeFoods, new Roles(true, false, [], [], [])),
            self::RAJ => new ActorContext(new UserId(self::RAJ), $acmeFoods, new Roles(false, true, [], [], [])),
            self::OMAR => new ActorContext(new UserId(self::OMAR), $acmeFoods, Roles::none()),
        ]);

        return new AdminAreaVoter($currentActor, new FixedLoginAssurance($hasConfirmedTwoStepLogin), $isTwoStepLoginWaived);
    }
}
