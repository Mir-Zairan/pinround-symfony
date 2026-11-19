<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\UI\Voter;

use App\Organization\Api\ActorContext;
use App\Organization\Api\Roles;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\Organization\UI\Voter\FixedCurrentActor;
use App\Tests\Unit\SharedKernel\Application\RecordingTenantSwitchGuard;
use App\Ticketing\Api\TicketPermission;
use App\Ticketing\UI\Voter\TicketReportVoter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * ADR-0038: Raj, a manager in no team, may report in Acme Foods like anyone of it; not in another organization.
 */
final class TicketReportVoterTest extends TestCase
{
    private const string RAJ = '0197a3c0-0000-7000-8000-0000000fa101';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000fa10a';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000fa10b';

    #[Test]
    public function anyoneOfTheOrganizationMayReportThereAndNowhereElse(): void
    {
        $token = new UsernamePasswordToken(new InMemoryUser(self::RAJ, null), 'main');

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->voterUnder(self::ACME_FOODS)->vote($token, new OrganizationId(self::ACME_FOODS), [TicketPermission::REPORT]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voterUnder(self::ACME_FOODS)->vote($token, new OrganizationId(self::BETA_BAKERY), [TicketPermission::REPORT]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voterUnder(self::BETA_BAKERY)->vote($token, new OrganizationId(self::BETA_BAKERY), [TicketPermission::REPORT]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voterUnder(null)->vote($token, new OrganizationId(self::ACME_FOODS), [TicketPermission::REPORT]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voterUnder(self::ACME_FOODS)->vote(new NullToken(), new OrganizationId(self::ACME_FOODS), [TicketPermission::REPORT]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->voterUnder(self::ACME_FOODS)->vote($token, new OrganizationId(self::ACME_FOODS), [TicketPermission::CREATE]));
    }

    private function voterUnder(?string $tenantId): TicketReportVoter
    {
        $tenantContext = new TenantContext(new RecordingTenantSwitchGuard());
        if (null !== $tenantId) {
            $tenantContext->set(new OrganizationId($tenantId));
        }

        return new TicketReportVoter(new FixedCurrentActor([
            self::RAJ => new ActorContext(new UserId(self::RAJ), new OrganizationId(self::ACME_FOODS), new Roles(false, true, [], [], [])),
        ]), $tenantContext);
    }
}
