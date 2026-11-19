<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\UI\Media;

use App\Organization\Api\ActorContext;
use App\Organization\Api\Roles;
use App\Review\UI\Media\NewReportMediaTarget;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\Organization\UI\Voter\FixedCurrentActor;
use App\Ticketing\Api\ProjectChoice;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * NewReportMediaTarget over Acme Foods' projects: SHOP, which the Shop team owns, and OLD, which no team owns. Mia has
 * no team role, as anyone may report a problem (ADR-0038); Omar is a member of Shop; all of Acme Foods. Ben is an Admin
 * of Beta Bakery.
 */
final class NewReportMediaTargetTest extends TestCase
{
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000018a2';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000018a3';
    private const string BEN = '0197a3c0-0000-7000-8000-0000000018a5';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000180a';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000180b';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-0000000018d1';

    /**
     * @return iterable<string, array{string}>
     */
    public static function peopleOfTheOrganization(): iterable
    {
        yield 'Mia, with no team role' => [self::MIA];
        yield 'Omar, a member of the team' => [self::OMAR];
    }

    #[Test]
    #[DataProvider('peopleOfTheOrganization')]
    public function anyoneOfTheOrganizationMayUploadForAReportOnAProjectATeamOwns(string $person): void
    {
        self::assertTrue(self::targetFor(self::tokenOf($person))->mayAttach(new OrganizationId(self::ACME_FOODS), new UserId($person), 'SHOP'));
    }

    /**
     * The token's user, who is asked about, and the project key.
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function refusedUploads(): iterable
    {
        yield 'Mia asked about for Omar' => [self::MIA, self::OMAR, 'SHOP'];
        yield 'Omar asked about for Mia' => [self::OMAR, self::MIA, 'SHOP'];
        yield 'an unknown key' => [self::MIA, self::MIA, 'MOBILE'];
        yield 'a project no team owns' => [self::MIA, self::MIA, 'OLD'];
        yield 'a lowercase key' => [self::MIA, self::MIA, 'shop'];
        yield 'a key with a trailing newline' => [self::MIA, self::MIA, "SHOP\n"];
        yield 'an empty key' => [self::MIA, self::MIA, ''];
    }

    #[Test]
    #[DataProvider('refusedUploads')]
    public function itRefuses(string $tokenUserId, string $askedUserId, string $projectKey): void
    {
        self::assertFalse(self::targetFor(self::tokenOf($tokenUserId))->mayAttach(new OrganizationId(self::ACME_FOODS), new UserId($askedUserId), $projectKey));
    }

    #[Test]
    public function anOrganizationOtherThanTheActorsIsRefused(): void
    {
        self::assertFalse(self::targetFor(self::tokenOf(self::MIA))->mayAttach(new OrganizationId(self::BETA_BAKERY), new UserId(self::MIA), 'SHOP'));
        self::assertFalse(self::targetFor(self::tokenOf(self::BEN))->mayAttach(new OrganizationId(self::ACME_FOODS), new UserId(self::BEN), 'SHOP'));
    }

    #[Test]
    public function nobodyLoggedInOrNoTokenAtAllIsRefused(): void
    {
        self::assertFalse(self::targetFor(new NullToken())->mayAttach(new OrganizationId(self::ACME_FOODS), new UserId(self::MIA), 'SHOP'));
        self::assertFalse(self::targetFor(null)->mayAttach(new OrganizationId(self::ACME_FOODS), new UserId(self::MIA), 'SHOP'));
    }

    #[Test]
    public function itDecidesTheNewReportKind(): void
    {
        self::assertSame('new_report', self::targetFor(null)->targetKind());
    }

    private static function tokenOf(string $userId): TokenInterface
    {
        return new UsernamePasswordToken(new InMemoryUser($userId, null), 'main');
    }

    private static function targetFor(?TokenInterface $token): NewReportMediaTarget
    {
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken($token);
        $acmeFoods = new OrganizationId(self::ACME_FOODS);
        $shopTeam = new TeamId(self::SHOP_TEAM);
        $currentActor = new FixedCurrentActor([
            self::OMAR => new ActorContext(new UserId(self::OMAR), $acmeFoods, new Roles(false, false, [], [$shopTeam], [])),
            self::MIA => new ActorContext(new UserId(self::MIA), $acmeFoods, new Roles(false, false, [], [], [])),
            self::BEN => new ActorContext(new UserId(self::BEN), new OrganizationId(self::BETA_BAKERY), new Roles(true, false, [], [], [])),
        ]);

        return new NewReportMediaTarget($tokenStorage, $currentActor, new FixedProjectQueries([
            new ProjectChoice('0197a3c0-0000-7000-8000-0000000018e1', 'SHOP', 'Shop', $shopTeam),
            new ProjectChoice('0197a3c0-0000-7000-8000-0000000018e2', 'OLD', 'Old shop'),
        ]));
    }
}
