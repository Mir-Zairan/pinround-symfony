<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Application;

use App\Organization\Domain\Invitation;
use App\Organization\Domain\InvitationAlreadyOpen;
use App\Organization\Domain\InvitationId;
use App\Organization\Domain\Invitations;
use App\SharedKernel\Domain\OrganizationId;

/**
 * Invitations in memory. add() refuses a second not-accepted invitation of the same organization and email, as the
 * unique index does, and saves a stored one again as Doctrine does; the calls are logged in order.
 */
final class InMemoryInvitations implements Invitations
{
    public const string NEXT_ID = '0197a3c0-0000-7000-8000-0000000006a1';

    /** @var array<string, Invitation> by invitation id */
    private array $invitations = [];
    /** @var list<string> */
    public array $calls = [];
    private int $issuedIds = 0;
    private ?Invitation $addedByAnotherRequest = null;

    public function __construct(Invitation ...$invitations)
    {
        foreach ($invitations as $invitation) {
            $this->invitations[$invitation->id()->toString()] = $invitation;
        }
    }

    /**
     * NEXT_ID first, then the ids that follow it.
     */
    public function nextIdentity(): InvitationId
    {
        return new InvitationId(\sprintf('0197a3c0-0000-7000-8000-%012x', 0x6A1 + $this->issuedIds++));
    }

    /**
     * The next add() first stores $invitation, as another request that commits between removeNotAcceptedFor() and
     * add() would.
     */
    public function letAnotherRequestAddFirst(Invitation $invitation): void
    {
        $this->addedByAnotherRequest = $invitation;
    }

    public function add(Invitation $invitation): void
    {
        $this->calls[] = 'add';
        if (null !== $this->addedByAnotherRequest) {
            $this->invitations[$this->addedByAnotherRequest->id()->toString()] = $this->addedByAnotherRequest;
            $this->addedByAnotherRequest = null;
        }
        foreach ($this->invitations as $stored) {
            if (!$stored->id()->equals($invitation->id()) && null === $stored->acceptedAt() && $stored->organizationId()->equals($invitation->organizationId()) && $stored->email() === $invitation->email()) {
                throw new InvitationAlreadyOpen('The unique index refuses a second invitation of this email.');
            }
        }
        $this->invitations[$invitation->id()->toString()] = $invitation;
    }

    public function save(Invitation $invitation): void
    {
        $this->calls[] = 'save';
        $this->invitations[$invitation->id()->toString()] = $invitation;
    }

    public function byId(InvitationId $id): ?Invitation
    {
        return $this->invitations[$id->toString()] ?? null;
    }

    public function lockById(InvitationId $id): ?Invitation
    {
        $this->calls[] = 'lockById';

        return $this->byId($id);
    }

    public function removeNotAcceptedFor(OrganizationId $organizationId, string $email): void
    {
        $this->calls[] = 'removeNotAcceptedFor';
        $this->invitations = array_filter(
            $this->invitations,
            static fn (Invitation $stored): bool => null !== $stored->acceptedAt()
                || !$stored->organizationId()->equals($organizationId)
                || $stored->email() !== mb_strtolower(trim($email)),
        );
    }

    /**
     * @return list<Invitation>
     */
    public function all(): array
    {
        return array_values($this->invitations);
    }
}
