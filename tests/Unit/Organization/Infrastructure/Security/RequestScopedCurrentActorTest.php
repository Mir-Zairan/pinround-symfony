<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Infrastructure\Security;

use App\Identity\Api\CurrentUser;
use App\Organization\Api\Roles;
use App\Organization\Infrastructure\Security\RequestScopedCurrentActor;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantSwitchGuard;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class RequestScopedCurrentActorTest extends TestCase
{
    private const string LENA = '0197a3c0-0000-7000-8000-0000000000a1';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000000a2';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000000a';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000000b';

    private TenantContext $tenantContext;

    private RecordingMembershipQueries $membershipQueries;

    private RequestScopedCurrentActor $currentActor;

    protected function setUp(): void
    {
        // Lena is an admin of Acme Foods and Omar a manager there; nobody has a role in Beta Bakery.
        $this->membershipQueries = new RecordingMembershipQueries([
            self::LENA.' '.self::ACME_FOODS => new Roles(true, false, [], [], []),
            self::OMAR.' '.self::ACME_FOODS => new Roles(false, true, [], [], []),
        ]);
        $this->tenantContext = new TenantContext(new class implements TenantSwitchGuard {
            public function prepareSwitch(): void
            {
            }
        });
        $this->currentActor = new RequestScopedCurrentActor(self::currentUser(), $this->tenantContext, $this->membershipQueries);
    }

    #[Test]
    public function theActorIsBuiltFromTheTokensUserTheTenantAndTheirRoles(): void
    {
        $this->tenantContext->set(new OrganizationId(self::ACME_FOODS));

        $actor = $this->currentActor->actorFor(new InMemoryUser(self::LENA, null));

        self::assertNotNull($actor);
        self::assertSame(self::LENA, $actor->userId()->toString());
        self::assertSame(self::ACME_FOODS, $actor->organizationId()->toString());
        self::assertTrue($actor->roles()->isAdmin());
        self::assertFalse($actor->roles()->isManager());
    }

    #[Test]
    public function aSecondAskForTheSameUserInTheSameRequestReusesTheFirstActor(): void
    {
        $this->tenantContext->set(new OrganizationId(self::ACME_FOODS));

        $first = $this->currentActor->actorFor(new InMemoryUser(self::LENA, null));
        $second = $this->currentActor->actorFor(new InMemoryUser(self::LENA, null));

        self::assertSame($first, $second);
        self::assertSame([self::LENA.' '.self::ACME_FOODS], $this->membershipQueries->rolesOfCalls);
    }

    #[Test]
    public function anotherUsersTokenGetsItsOwnActorAndRoles(): void
    {
        $this->tenantContext->set(new OrganizationId(self::ACME_FOODS));

        $lena = $this->currentActor->actorFor(new InMemoryUser(self::LENA, null));
        $omar = $this->currentActor->actorFor(new InMemoryUser(self::OMAR, null));

        self::assertSame(self::OMAR, $omar?->userId()->toString());
        self::assertTrue($lena?->roles()->isAdmin());
        self::assertFalse($omar->roles()->isAdmin());
        self::assertTrue($omar->roles()->isManager());
        self::assertSame([self::LENA.' '.self::ACME_FOODS, self::OMAR.' '.self::ACME_FOODS], $this->membershipQueries->rolesOfCalls);
    }

    #[Test]
    public function anotherOrganizationGetsItsOwnActorAndRoles(): void
    {
        $this->tenantContext->set(new OrganizationId(self::ACME_FOODS));
        $this->currentActor->actorFor(new InMemoryUser(self::LENA, null));

        $this->tenantContext->set(new OrganizationId(self::BETA_BAKERY));
        $actor = $this->currentActor->actorFor(new InMemoryUser(self::LENA, null));

        self::assertSame(self::BETA_BAKERY, $actor?->organizationId()->toString());
        self::assertFalse($actor->roles()->isAdmin());
        self::assertSame([self::LENA.' '.self::ACME_FOODS, self::LENA.' '.self::BETA_BAKERY], $this->membershipQueries->rolesOfCalls);
    }

    #[Test]
    public function afterAResetTheRolesAreReadAgain(): void
    {
        $this->tenantContext->set(new OrganizationId(self::ACME_FOODS));
        $before = $this->currentActor->actorFor(new InMemoryUser(self::LENA, null));

        $this->currentActor->reset();
        $after = $this->currentActor->actorFor(new InMemoryUser(self::LENA, null));

        self::assertNotSame($before, $after);
        self::assertSame([self::LENA.' '.self::ACME_FOODS, self::LENA.' '.self::ACME_FOODS], $this->membershipQueries->rolesOfCalls);
    }

    #[Test]
    public function aTokenWithoutALoggedInUserHasNoActor(): void
    {
        $this->tenantContext->set(new OrganizationId(self::ACME_FOODS));

        self::assertNull($this->currentActor->actorFor(null));
        self::assertNull($this->currentActor->actorFor('anon.'));
        self::assertSame([], $this->membershipQueries->rolesOfCalls);
    }

    #[Test]
    public function aRequestWithoutATenantHasNoActorAndReadsNoRoles(): void
    {
        self::assertNull($this->currentActor->actorFor(new InMemoryUser(self::LENA, null)));
        self::assertSame([], $this->membershipQueries->rolesOfCalls);
    }

    private static function currentUser(): CurrentUser
    {
        return new class implements CurrentUser {
            public function id(): ?UserId
            {
                throw new \LogicException('The actor is built for the token user it is given.');
            }

            public function idOf(mixed $tokenUser): ?UserId
            {
                return $tokenUser instanceof InMemoryUser ? new UserId($tokenUser->getUserIdentifier()) : null;
            }
        };
    }
}
