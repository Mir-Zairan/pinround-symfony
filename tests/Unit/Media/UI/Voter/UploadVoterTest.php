<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\UI\Voter;

use App\Media\Api\MediaPermission;
use App\Media\Api\UploadTarget;
use App\Media\Application\MediaTargetAuthorizers;
use App\Media\UI\Voter\UploadVoter;
use App\Organization\Api\ActorContext;
use App\Organization\Api\OrganizationPermission;
use App\Organization\Api\Roles;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\Media\Application\RecordingMediaTargetAuthorizer;
use App\Tests\Unit\Organization\UI\Voter\FixedCurrentActor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * Omar is a member of Acme Foods, whose ticket authorizer lets him attach to SHOP-1 only; Ben is a member of Beta
 * Bakery, whose authorizer lets nobody attach. No authorizer decides rounds.
 */
final class UploadVoterTest extends TestCase
{
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000017a1';
    private const string BEN = '0197a3c0-0000-7000-8000-0000000017a2';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000170a';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000170b';
    private const string SHOP_1 = '0197a3c0-0000-7000-8000-0000000017e1';
    private const string SHOP_2 = '0197a3c0-0000-7000-8000-0000000017e2';

    private RecordingMediaTargetAuthorizer $ticketAuthorizer;

    protected function setUp(): void
    {
        $this->ticketAuthorizer = new RecordingMediaTargetAuthorizer('ticket');
        $this->ticketAuthorizer->allow(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR), self::SHOP_1);
    }

    #[Test]
    public function itGrantsWhatTheAuthorizerOfTheKindAllowsTheActorInTheirOrganization(): void
    {
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->voter()->vote(self::tokenOf(self::OMAR), new UploadTarget('ticket', self::SHOP_1), [MediaPermission::UPLOAD]));
        self::assertSame([['organizationId' => self::ACME_FOODS, 'userId' => self::OMAR, 'targetId' => self::SHOP_1]], $this->ticketAuthorizer->asked());
    }

    #[Test]
    public function itDeniesATargetTheAuthorizerRefuses(): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter()->vote(self::tokenOf(self::OMAR), new UploadTarget('ticket', self::SHOP_2), [MediaPermission::UPLOAD]));
        self::assertSame([['organizationId' => self::ACME_FOODS, 'userId' => self::OMAR, 'targetId' => self::SHOP_2]], $this->ticketAuthorizer->asked());
    }

    #[Test]
    public function itAsksForTheActorsOwnOrganizationSoAnotherOrganizationsActorIsDenied(): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter()->vote(self::tokenOf(self::BEN), new UploadTarget('ticket', self::SHOP_1), [MediaPermission::UPLOAD]));
        self::assertSame([['organizationId' => self::BETA_BAKERY, 'userId' => self::BEN, 'targetId' => self::SHOP_1]], $this->ticketAuthorizer->asked());
    }

    #[Test]
    public function itDeniesAKindNoAuthorizerDecides(): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter()->vote(self::tokenOf(self::OMAR), new UploadTarget('sketch', self::SHOP_1), [MediaPermission::UPLOAD]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter()->vote(self::tokenOf(self::OMAR), new UploadTarget('Ticket', self::SHOP_1), [MediaPermission::UPLOAD]));
        self::assertSame([], $this->ticketAuthorizer->asked());
    }

    #[Test]
    public function withoutAnActorItDeniesAndAsksNoAuthorizer(): void
    {
        $voter = $this->voter();

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(new NullToken(), new UploadTarget('ticket', self::SHOP_1), [MediaPermission::UPLOAD]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(self::tokenOf('0197a3c0-0000-7000-8000-0000000017ff'), new UploadTarget('ticket', self::SHOP_1), [MediaPermission::UPLOAD]));
        self::assertSame([], $this->ticketAuthorizer->asked());
    }

    #[Test]
    public function itAbstainsOnOtherAttributesAndOnOtherSubjects(): void
    {
        $voter = $this->voter();
        $token = self::tokenOf(self::OMAR);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, new UploadTarget('ticket', self::SHOP_1), [OrganizationPermission::VIEW]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, new UploadTarget('ticket', self::SHOP_1), [strtolower(MediaPermission::UPLOAD)]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, self::SHOP_1, [MediaPermission::UPLOAD]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, new OrganizationId(self::ACME_FOODS), [MediaPermission::UPLOAD]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, null, [MediaPermission::UPLOAD]));
        self::assertSame([], $this->ticketAuthorizer->asked());
    }

    private static function tokenOf(string $userId): TokenInterface
    {
        return new UsernamePasswordToken(new InMemoryUser($userId, null), 'main');
    }

    private function voter(): UploadVoter
    {
        return new UploadVoter(
            new FixedCurrentActor([
                self::OMAR => new ActorContext(new UserId(self::OMAR), new OrganizationId(self::ACME_FOODS), Roles::none()),
                self::BEN => new ActorContext(new UserId(self::BEN), new OrganizationId(self::BETA_BAKERY), Roles::none()),
            ]),
            new MediaTargetAuthorizers([$this->ticketAuthorizer]),
        );
    }
}
