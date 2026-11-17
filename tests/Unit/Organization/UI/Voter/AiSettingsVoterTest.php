<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\UI\Voter;

use App\Organization\Api\ActorContext;
use App\Organization\Api\AdminPermission;
use App\Organization\Api\OrganizationPermission;
use App\Organization\Api\Roles;
use App\Organization\UI\Voter\AiSettingsVoter;
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

final class AiSettingsVoterTest extends TestCase
{
    private const string SARA = '0197a3c0-0000-7000-8000-0000000008a1';
    private const string RAJ = '0197a3c0-0000-7000-8000-0000000008a2';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000008a3';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000008a4';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000008a5';
    private const string NOBODY = '0197a3c0-0000-7000-8000-0000000008a6';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000080a';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000080b';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-0000000008c1';

    #[Test]
    public function anAdminOfTheRequestsOrganizationMayChangeItsAiSettings(): void
    {
        self::assertSame(VoterInterface::ACCESS_GRANTED, self::voter()->vote(self::sessionOf(self::SARA), self::acmeFoods(), [AdminPermission::AI]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function peopleBelowAdmin(): iterable
    {
        yield 'Raj, a manager' => [self::RAJ];
        yield 'Lena, the lead of Shop' => [self::LENA];
        yield 'Omar, a member of Shop' => [self::OMAR];
        yield 'Mia, a viewer of Shop' => [self::MIA];
        yield 'nobody: no actor' => [self::NOBODY];
    }

    #[Test]
    #[DataProvider('peopleBelowAdmin')]
    public function anyoneBelowAdminIsDenied(string $userId): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, self::voter()->vote(self::sessionOf($userId), self::acmeFoods(), [AdminPermission::AI]));
    }

    /**
     * @return iterable<string, array{bool, ?int}>
     */
    public static function loginsTheAdminPagesRefuse(): iterable
    {
        yield 'a code over an hour old' => [true, 3601];
        yield 'two-step login on, no code in this session' => [true, null];
        yield 'two-step login off' => [false, 0];
    }

    #[Test]
    #[DataProvider('loginsTheAdminPagesRefuse')]
    public function anAdminWithoutACodeOfTheLastHourIsDenied(bool $isTwoStepLoginOn, ?int $codeProofAgeSeconds): void
    {
        $voter = self::voter($isTwoStepLoginOn, $codeProofAgeSeconds);

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(self::sessionOf(self::SARA), self::acmeFoods(), [AdminPermission::AI]));
    }

    #[Test]
    public function anAdminWithACodeOfExactlyAnHourAgoMayChangeTheAiSettings(): void
    {
        $voter = self::voter(isTwoStepLoginOn: true, codeProofAgeSeconds: 3600);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote(self::sessionOf(self::SARA), self::acmeFoods(), [AdminPermission::AI]));
    }

    /**
     * Remember-me stands for every token that is not a password session, the personal access token of P09 included
     * (personas-and-roles.md note 6).
     */
    #[Test]
    public function anAdminWhoseLoginIsNotAPasswordSessionIsDeniedEvenWithAFreshCode(): void
    {
        $rememberMe = new RememberMeToken(new InMemoryUser(self::SARA, null), 'main');

        self::assertSame(VoterInterface::ACCESS_DENIED, self::voter()->vote($rememberMe, self::acmeFoods(), [AdminPermission::AI]));
    }

    #[Test]
    public function anAnonymousRequestIsDenied(): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, self::voter()->vote(new NullToken(), self::acmeFoods(), [AdminPermission::AI]));
    }

    #[Test]
    public function anAdminIsDeniedTheAiSettingsOfAnotherOrganization(): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, self::voter()->vote(self::sessionOf(self::SARA), new OrganizationId(self::BETA_BAKERY), [AdminPermission::AI]));
    }

    #[Test]
    public function itAbstainsOnOtherAttributesAndOnASubjectThatIsNoOrganization(): void
    {
        $token = self::sessionOf(self::SARA);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, self::voter()->vote($token, self::acmeFoods(), [AdminPermission::AREA]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, self::voter()->vote($token, self::acmeFoods(), [OrganizationPermission::ADMIN]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, self::voter()->vote($token, null, [AdminPermission::AI]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, self::voter()->vote($token, new TeamId(self::ACME_FOODS), [AdminPermission::AI]));
    }

    private static function sessionOf(string $userId): TokenInterface
    {
        return new UsernamePasswordToken(new InMemoryUser($userId, null), 'main');
    }

    private static function acmeFoods(): OrganizationId
    {
        return new OrganizationId(self::ACME_FOODS);
    }

    /**
     * Sara is an Admin, Raj a Manager, Lena the lead, Omar a member and Mia a viewer of Shop, all of Acme Foods, the
     * organization of the request; Nobody has no actor. Unless asked otherwise, two-step login is on and the last code
     * was typed just now.
     */
    private static function voter(bool $isTwoStepLoginOn = true, ?int $codeProofAgeSeconds = 0): AiSettingsVoter
    {
        $acmeFoods = self::acmeFoods();
        $shop = [new TeamId(self::SHOP_TEAM)];

        return new AiSettingsVoter(new FixedCurrentActor([
            self::SARA => new ActorContext(new UserId(self::SARA), $acmeFoods, new Roles(true, false, [], [], [])),
            self::RAJ => new ActorContext(new UserId(self::RAJ), $acmeFoods, new Roles(false, true, [], [], [])),
            self::LENA => new ActorContext(new UserId(self::LENA), $acmeFoods, new Roles(false, false, $shop, [], [])),
            self::OMAR => new ActorContext(new UserId(self::OMAR), $acmeFoods, new Roles(false, false, [], $shop, [])),
            self::MIA => new ActorContext(new UserId(self::MIA), $acmeFoods, new Roles(false, false, [], [], $shop)),
        ]), new FixedLoginAssurance($isTwoStepLoginOn, $codeProofAgeSeconds));
    }
}
