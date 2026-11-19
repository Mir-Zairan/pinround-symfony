<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\UI\Voter;

use App\Organization\Api\ActorContext;
use App\Organization\Api\Roles;
use App\Review\Api\NotebookPermission;
use App\Review\Api\NotebookView;
use App\Review\UI\Voter\NotebookVoter;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\Organization\UI\Voter\FixedCurrentActor;
use App\Tests\Unit\SharedKernel\Application\RecordingTenantSwitchGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * Lena owns the notebook and leads Shop; Omar is a member of Shop; Sara is an Admin with no team role; all of Acme
 * Foods. Ben is an Admin of Beta Bakery. Lena is a member of Beta Bakery too, in a second actor keyed "lena-at-beta".
 */
final class NotebookVoterTest extends TestCase
{
    private const string LENA = '0197a3c0-0000-7000-8000-0000000f8201';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000f8202';
    private const string SARA = '0197a3c0-0000-7000-8000-0000000f8203';
    private const string BEN = '0197a3c0-0000-7000-8000-0000000f8204';
    private const string LENA_AT_BETA = 'lena-at-beta';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000f820a';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000f820b';
    private const string SHOP = '0197a3c0-0000-7000-8000-0000000f82d1';

    /**
     * @return iterable<string, array{string}>
     */
    public static function attributes(): iterable
    {
        yield 'view' => [NotebookPermission::VIEW];
        yield 'edit' => [NotebookPermission::EDIT];
    }

    #[Test]
    #[DataProvider('attributes')]
    public function theOwnerIsGrantedAndATeamMemberAnAdminAndAnotherOrganizationAreNot(string $attribute): void
    {
        $voter = $this->voterUnder(self::ACME_FOODS);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote(self::tokenOf(self::LENA), self::lenasNotebook(), [$attribute]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(self::tokenOf(self::OMAR), self::lenasNotebook(), [$attribute]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(self::tokenOf(self::SARA), self::lenasNotebook(), [$attribute]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voterUnder(self::BETA_BAKERY)->vote(self::tokenOf(self::BEN), self::lenasNotebook(), [$attribute]));
    }

    /**
     * The owner's id is the same, so only the organization tells the two apart.
     */
    #[Test]
    #[DataProvider('attributes')]
    public function theOwnerActingInAnotherOrganizationIsRefusedHerNotebookThere(string $attribute): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voterUnder(self::BETA_BAKERY)->vote(self::tokenOf(self::LENA_AT_BETA), self::lenasNotebook(), [$attribute]));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->voterUnder(self::BETA_BAKERY)->vote(self::tokenOf(self::LENA_AT_BETA), self::lenasNotebook(self::BETA_BAKERY), [$attribute]));
    }

    #[Test]
    public function nobodyLoggedInAnotherTenantAndNoTenantAreRefused(): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voterUnder(self::ACME_FOODS)->vote(new NullToken(), self::lenasNotebook(), [NotebookPermission::VIEW]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voterUnder(self::BETA_BAKERY)->vote(self::tokenOf(self::LENA), self::lenasNotebook(), [NotebookPermission::VIEW]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voterUnder(null)->vote(self::tokenOf(self::LENA), self::lenasNotebook(), [NotebookPermission::VIEW]));
    }

    #[Test]
    public function itAbstainsOnOtherAttributesAndSubjects(): void
    {
        $voter = $this->voterUnder(self::ACME_FOODS);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote(self::tokenOf(self::LENA), self::lenasNotebook(), ['REPORT_DRAFT_VIEW']));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote(self::tokenOf(self::LENA), null, [NotebookPermission::VIEW]));
    }

    private static function lenasNotebook(string $organizationId = self::ACME_FOODS): NotebookView
    {
        $at = new \DateTimeImmutable('2027-03-15 10:00:00 UTC');

        return new NotebookView('0197a3c0-0000-7000-8000-0000000f8211', new OrganizationId($organizationId), new UserId(self::LENA), 'Order page', [], [], 1, $at, $at, $at);
    }

    private function voterUnder(?string $tenantId): NotebookVoter
    {
        $tenantContext = new TenantContext(new RecordingTenantSwitchGuard());
        if (null !== $tenantId) {
            $tenantContext->set(new OrganizationId($tenantId));
        }
        $acmeFoods = new OrganizationId(self::ACME_FOODS);
        $betaBakery = new OrganizationId(self::BETA_BAKERY);
        $shop = new TeamId(self::SHOP);

        return new NotebookVoter(new FixedCurrentActor([
            self::LENA => new ActorContext(new UserId(self::LENA), $acmeFoods, new Roles(true, false, [$shop], [], [])),
            self::OMAR => new ActorContext(new UserId(self::OMAR), $acmeFoods, new Roles(false, false, [], [$shop], [])),
            self::SARA => new ActorContext(new UserId(self::SARA), $acmeFoods, new Roles(true, false, [], [], [])),
            self::BEN => new ActorContext(new UserId(self::BEN), $betaBakery, new Roles(true, false, [], [], [])),
            self::LENA_AT_BETA => new ActorContext(new UserId(self::LENA), $betaBakery, new Roles(false, false, [], [], [])),
        ]), $tenantContext);
    }

    private static function tokenOf(string $userIdentifier): TokenInterface
    {
        return new UsernamePasswordToken(new InMemoryUser($userIdentifier, null), 'main');
    }
}
