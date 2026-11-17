<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\UI\Media;

use App\Organization\Api\ActorContext;
use App\Organization\Api\Roles;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\Organization\UI\Voter\FixedCurrentActor;
use App\Ticketing\UI\Media\NewTicketMediaTarget;
use App\Ticketing\UI\Voter\TicketCreationVoter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManager;
use Symfony\Component\Security\Core\Authorization\AuthorizationChecker;
use Symfony\Component\Security\Core\Authorization\Strategy\UnanimousStrategy;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * NewTicketMediaTarget with the real TicketCreationVoter behind Symfony's AuthorizationChecker on the unanimous strategy
 * the app uses (security.yaml). Lena leads Shop, Omar is a member and Mia a viewer of Shop, Raj is a Manager of no team;
 * all of Acme Foods. Ben is an Admin of Beta Bakery.
 */
final class NewTicketMediaTargetTest extends TestCase
{
    private const string LENA = '0197a3c0-0000-7000-8000-0000000017a1';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000017a2';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000017a3';
    private const string RAJ = '0197a3c0-0000-7000-8000-0000000017a4';
    private const string BEN = '0197a3c0-0000-7000-8000-0000000017a5';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000170a';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000170b';
    private const string SHOP = '0197a3c0-0000-7000-8000-0000000017d1';
    private const string MOBILE = '0197a3c0-0000-7000-8000-0000000017d2';

    /**
     * @return iterable<string, array{string}>
     */
    public static function peopleOfTheTeam(): iterable
    {
        yield 'Lena, its lead' => [self::LENA];
        yield 'Omar, a member' => [self::OMAR];
        yield 'Mia, a viewer, who may make tickets too' => [self::MIA];
    }

    #[Test]
    #[DataProvider('peopleOfTheTeam')]
    public function aPersonOfTheTeamMayUploadForANewTicketOfIt(string $person): void
    {
        self::assertTrue(self::targetFor(self::tokenOf($person))->mayAttach(new OrganizationId(self::ACME_FOODS), new UserId($person), self::SHOP));
    }

    #[Test]
    public function aTeamIdInCapitalsIsTheSameTeam(): void
    {
        self::assertTrue(self::targetFor(self::tokenOf(self::OMAR))->mayAttach(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR), strtoupper(self::SHOP)));
    }

    /**
     * The token's user, who is asked about, and the team.
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function refusedUploads(): iterable
    {
        yield 'Raj, a Manager with no team role' => [self::RAJ, self::RAJ, self::SHOP];
        yield 'Omar, for a team he is not in' => [self::OMAR, self::OMAR, self::MOBILE];
        yield 'Omar asked about for Lena' => [self::OMAR, self::LENA, self::SHOP];
        yield 'Lena asked about for Omar' => [self::LENA, self::OMAR, self::SHOP];
        yield 'a team id that is no uuid' => [self::OMAR, self::OMAR, 'SHOP'];
        yield 'a team id with a trailing newline' => [self::OMAR, self::OMAR, self::SHOP."\n"];
        yield 'an empty team id' => [self::OMAR, self::OMAR, ''];
    }

    #[Test]
    #[DataProvider('refusedUploads')]
    public function itRefuses(string $tokenUserId, string $askedUserId, string $teamId): void
    {
        self::assertFalse(self::targetFor(self::tokenOf($tokenUserId))->mayAttach(new OrganizationId(self::ACME_FOODS), new UserId($askedUserId), $teamId));
    }

    #[Test]
    public function anOrganizationOtherThanTheActorsIsRefused(): void
    {
        self::assertFalse(self::targetFor(self::tokenOf(self::OMAR))->mayAttach(new OrganizationId(self::BETA_BAKERY), new UserId(self::OMAR), self::SHOP));
        self::assertFalse(self::targetFor(self::tokenOf(self::BEN))->mayAttach(new OrganizationId(self::ACME_FOODS), new UserId(self::BEN), self::SHOP));
    }

    #[Test]
    public function nobodyLoggedInOrNoTokenAtAllIsRefused(): void
    {
        self::assertFalse(self::targetFor(new NullToken())->mayAttach(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR), self::SHOP));
        self::assertFalse(self::targetFor(null)->mayAttach(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR), self::SHOP));
    }

    #[Test]
    public function itDecidesTheNewTicketKind(): void
    {
        self::assertSame('new_ticket', self::targetFor(null)->targetKind());
    }

    private static function tokenOf(string $userId): TokenInterface
    {
        return new UsernamePasswordToken(new InMemoryUser($userId, null), 'main');
    }

    private static function targetFor(?TokenInterface $token): NewTicketMediaTarget
    {
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken($token);
        $acmeFoods = new OrganizationId(self::ACME_FOODS);
        $shop = new TeamId(self::SHOP);
        $currentActor = new FixedCurrentActor([
            self::LENA => new ActorContext(new UserId(self::LENA), $acmeFoods, new Roles(false, false, [$shop], [], [])),
            self::OMAR => new ActorContext(new UserId(self::OMAR), $acmeFoods, new Roles(false, false, [], [$shop], [])),
            self::MIA => new ActorContext(new UserId(self::MIA), $acmeFoods, new Roles(false, false, [], [], [$shop])),
            self::RAJ => new ActorContext(new UserId(self::RAJ), $acmeFoods, new Roles(false, true, [], [], [])),
            self::BEN => new ActorContext(new UserId(self::BEN), new OrganizationId(self::BETA_BAKERY), new Roles(true, false, [], [], [])),
        ]);

        return new NewTicketMediaTarget(
            $tokenStorage,
            $currentActor,
            new AuthorizationChecker($tokenStorage, new AccessDecisionManager([new TicketCreationVoter($currentActor)], new UnanimousStrategy(false))),
        );
    }
}
