<?php

declare(strict_types=1);

namespace App\Tests\Unit\AgentApi\Infrastructure\Mcp;

use App\AgentApi\Infrastructure\Mcp\TicketVisibilityCheck;
use App\AgentApi\Infrastructure\Mcp\ToolCallOutcome;
use App\AgentApi\Infrastructure\Mcp\ToolRefusal;
use App\Identity\Api\ApiTokenAccess;
use App\Identity\Api\CurrentApiToken;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\TicketTurnSnapshot;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketPermission;
use App\Ticketing\Api\TicketTurnQueries;
use App\Ticketing\Api\TicketVisibilityFacts;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * The ticket check set_verdict, create_upload and add_screenshot share, over fakes of Ticketing's Api and the
 * authorization checker: SHOP-1 is a ticket of Shop, led by Lena, for that team only, reported by Mia and assigned to
 * Omar; the
 * caller's token is of Acme Foods.
 */
final class TicketVisibilityCheckTest extends TestCase
{
    /** Public, for the anonymous fakes. */
    public const string SHOP_TEAM = '019a5f00-0000-7000-8000-00000000e811';
    public const string OMAR = '019a5f00-0000-7000-8000-00000000e821';
    public const string MIA = '019a5f00-0000-7000-8000-00000000e822';
    public const string LENA = '019a5f00-0000-7000-8000-00000000e823';
    public const string SHOP_1 = '019a5f00-0000-7000-8000-00000000e831';
    private const string ACME_FOODS = '019a5f00-0000-7000-8000-00000000e801';
    private const string UNKNOWN_TICKET = '019a5f00-0000-7000-8000-00000000e839';

    private ?ApiTokenAccess $tokenAccess;
    private bool $isTicketHidden = false;

    /** @var \ArrayObject<int, array{mixed, mixed}> the attribute and subject of each isGranted, as asked */
    private \ArrayObject $permissionsAsked;

    protected function setUp(): void
    {
        $this->tokenAccess = new ApiTokenAccess('019a5f00-0000-7000-8000-00000000e841', new OrganizationId(self::ACME_FOODS), ['rounds:write'], new \DateTimeImmutable('2027-02-08 09:00:00 UTC'));
        $this->permissionsAsked = new \ArrayObject();
    }

    #[Test]
    public function aTicketTheVoterGrantsPassesAfterOneViewQuestionOnItsFactsInTheCallersOrganization(): void
    {
        $this->ticketVisibilityCheck()->refuseUnlessVisible(new TicketId(self::SHOP_1));

        $expectedFacts = new TicketVisibilityFacts(new OrganizationId(self::ACME_FOODS), TicketVisibilityFacts::TEAM_ONLY, new TeamId(self::SHOP_TEAM), new UserId(self::MIA), new UserId(self::OMAR));
        self::assertEquals([[TicketPermission::VIEW, $expectedFacts]], $this->permissionsAsked->getArrayCopy());
    }

    #[Test]
    public function aTicketTheVoterDeniesIsNotFound(): void
    {
        $this->isTicketHidden = true;

        $refusal = $this->refusalOf(new TicketId(self::SHOP_1));

        self::assertSame(ToolCallOutcome::NotFound, $refusal->outcome);
        self::assertSame(ToolRefusal::NOT_FOUND, $refusal->getMessage());
        self::assertCount(1, $this->permissionsAsked);
    }

    #[Test]
    public function aTicketTicketingDoesNotReadIsNotFoundWithoutAskingTheVoter(): void
    {
        $refusal = $this->refusalOf(new TicketId(self::UNKNOWN_TICKET));

        self::assertSame(ToolCallOutcome::NotFound, $refusal->outcome);
        self::assertSame(ToolRefusal::NOT_FOUND, $refusal->getMessage());
        self::assertCount(0, $this->permissionsAsked);
    }

    #[Test]
    public function theCallersOrganizationIsTheOneOfTheirToken(): void
    {
        self::assertTrue($this->ticketVisibilityCheck()->callerOrganizationId()->equals(new OrganizationId(self::ACME_FOODS)));
    }

    #[Test]
    public function withoutATokenTheCheckFailsBeforeTheVoterIsAsked(): void
    {
        $this->tokenAccess = null;

        try {
            $this->ticketVisibilityCheck()->refuseUnlessVisible(new TicketId(self::SHOP_1));
            self::fail('The check passed without a token.');
        } catch (\LogicException $failure) {
            self::assertSame('An agent tool ran without a personal access token.', $failure->getMessage());
        }
        self::assertCount(0, $this->permissionsAsked);
    }

    private function refusalOf(TicketId $ticketId): ToolRefusal
    {
        try {
            $this->ticketVisibilityCheck()->refuseUnlessVisible($ticketId);
        } catch (ToolRefusal $refusal) {
            return $refusal;
        }
        self::fail('The check did not refuse.');
    }

    private function ticketVisibilityCheck(): TicketVisibilityCheck
    {
        return new TicketVisibilityCheck(
            new readonly class($this->tokenAccess) implements CurrentApiToken {
                public function __construct(private ?ApiTokenAccess $tokenAccess)
                {
                }

                public function access(): ?ApiTokenAccess
                {
                    return $this->tokenAccess;
                }
            },
            // SHOP-1 alone, by its id, as the real query reads only the tenant's tickets.
            new class implements TicketTurnQueries {
                public function factsOf(TicketId $ticketId): ?TicketTurnSnapshot
                {
                    if (!$ticketId->equals(new TicketId(TicketVisibilityCheckTest::SHOP_1))) {
                        return null;
                    }

                    return new TicketTurnSnapshot($ticketId, '019a5f00-0000-7000-8000-00000000e851', '019a5f00-0000-7000-8000-00000000e852', 'todo', 'todo', new TeamId(TicketVisibilityCheckTest::SHOP_TEAM), new UserId(TicketVisibilityCheckTest::LENA), 'Lena', [], [], TicketVisibilityFacts::TEAM_ONLY, 'major', null, new UserId(TicketVisibilityCheckTest::MIA), new UserId(TicketVisibilityCheckTest::OMAR));
                }
            },
            new readonly class($this->isTicketHidden, $this->permissionsAsked) implements AuthorizationCheckerInterface {
                /**
                 * @param \ArrayObject<int, array{mixed, mixed}> $permissionsAsked
                 */
                public function __construct(private bool $isTicketHidden, private \ArrayObject $permissionsAsked)
                {
                }

                public function isGranted(mixed $attribute, mixed $subject = null, ?AccessDecision $accessDecision = null): bool
                {
                    $this->permissionsAsked[] = [$attribute, $subject];

                    return TicketPermission::VIEW === $attribute && $subject instanceof TicketVisibilityFacts && !$this->isTicketHidden;
                }
            },
        );
    }
}
