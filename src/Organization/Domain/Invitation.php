<?php

declare(strict_types=1);

namespace App\Organization\Domain;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TenantOwned;
use App\SharedKernel\Domain\UserId;

/**
 * An invitation of one email address to an organization, with the org roles and optionally the team and team role
 * the person gets on accepting it. It is open until it is accepted or LIFETIME_HOURS have passed. The emailed link
 * carries a token whose SHA-256 the email worker attaches; until then the invitation has none.
 */
final class Invitation implements TenantOwned
{
    public const int LIFETIME_HOURS = 72;

    // Kept as strings and wrapped on the way out, like Membership: the mapping stays free of value-object types.
    private readonly string $organizationId;
    private readonly string $id;
    private readonly string $email;
    /** @var list<string> */
    private readonly array $orgRoles;
    private readonly ?string $teamId;
    private readonly ?TeamRole $teamRole;
    /**
     * Doctrine's binary type loads a stream, so a loaded invitation holds a resource here.
     *
     * @var resource|string|null
     */
    private mixed $tokenHash = null;
    private readonly string $invitedBy;
    private readonly \DateTimeImmutable $createdAt;
    private readonly \DateTimeImmutable $expiresAt;
    private ?\DateTimeImmutable $acceptedAt = null;

    private function __construct(InvitationId $id, OrganizationId $organizationId, string $email, OrgRoles $orgRoles, ?TeamId $teamId, ?TeamRole $teamRole, UserId $invitedBy, \DateTimeImmutable $createdAt)
    {
        $this->id = $id->toString();
        $this->organizationId = $organizationId->toString();
        $this->email = $email;
        $this->orgRoles = $orgRoles->values();
        $this->teamId = $teamId?->toString();
        $this->teamRole = $teamRole;
        $this->invitedBy = $invitedBy->toString();
        $this->createdAt = $createdAt;
        $this->expiresAt = $createdAt->add(new \DateInterval('PT'.self::LIFETIME_HOURS.'H'));
    }

    /**
     * A new invitation, open for LIFETIME_HOURS from $createdAt. The email is trimmed and kept in lower case.
     *
     * @param TeamRole|null $teamRole Member or Viewer, given exactly when $teamId is
     *
     * @throws \InvalidArgumentException when the email is blank, or only one of $teamId and $teamRole is given
     * @throws TeamHasOneLead            when $teamRole is Lead
     */
    public static function issue(InvitationId $id, OrganizationId $organizationId, string $email, OrgRoles $orgRoles, ?TeamId $teamId, ?TeamRole $teamRole, UserId $invitedBy, \DateTimeImmutable $createdAt): self
    {
        $normalizedEmail = mb_strtolower(trim($email));
        if ('' === $normalizedEmail) {
            throw new \InvalidArgumentException('An invitation needs an email address.');
        }
        if ((null === $teamId) !== (null === $teamRole)) {
            throw new \InvalidArgumentException('An invitation names a team and a team role together, or neither.');
        }
        if (TeamRole::Lead === $teamRole) {
            throw new TeamHasOneLead('A team has exactly one lead. Invite the person as a member and appoint them later.');
        }

        return new self($id, $organizationId, $normalizedEmail, $orgRoles, $teamId, $teamRole, $invitedBy, $createdAt);
    }

    public function id(): InvitationId
    {
        return new InvitationId($this->id);
    }

    public function organizationId(): OrganizationId
    {
        return new OrganizationId($this->organizationId);
    }

    public function email(): string
    {
        return $this->email;
    }

    public function orgRoles(): OrgRoles
    {
        return OrgRoles::fromValues($this->orgRoles);
    }

    public function teamId(): ?TeamId
    {
        return null === $this->teamId ? null : new TeamId($this->teamId);
    }

    public function teamRole(): ?TeamRole
    {
        return $this->teamRole;
    }

    public function invitedBy(): UserId
    {
        return new UserId($this->invitedBy);
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function expiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function acceptedAt(): ?\DateTimeImmutable
    {
        return $this->acceptedAt;
    }

    public function hasLinkToken(): bool
    {
        return null !== $this->tokenHash;
    }

    /**
     * Stores the SHA-256 of the token in the emailed link, which resolve_invitation looks up. Only once: the link
     * already emailed stays the only one, so a redelivered email message cannot send a second, different link.
     *
     * @param string $sha256 the 32 raw bytes of the token's SHA-256, never the token itself
     *
     * @throws \InvalidArgumentException when $sha256 is not 32 bytes long
     * @throws \LogicException           when the invitation has a link token already
     */
    public function attachLinkTokenHash(string $sha256): void
    {
        if (32 !== \strlen($sha256)) {
            throw new \InvalidArgumentException('A link token hash is the 32 raw bytes of a SHA-256.');
        }
        if ($this->hasLinkToken()) {
            throw new \LogicException('An invitation keeps the link token it was emailed with.');
        }

        $this->tokenHash = $sha256;
    }

    /**
     * Open: not accepted yet and $at is before the expiry; at the expiry itself it is closed, as in resolve_invitation.
     */
    public function isOpenAt(\DateTimeImmutable $at): bool
    {
        return null === $this->acceptedAt && $at < $this->expiresAt;
    }

    /**
     * @throws \LogicException when the invitation is not open at $acceptedAt
     */
    public function accept(\DateTimeImmutable $acceptedAt): void
    {
        if (!$this->isOpenAt($acceptedAt)) {
            throw new \LogicException('Only an open invitation can be accepted.');
        }

        $this->acceptedAt = $acceptedAt;
    }
}
