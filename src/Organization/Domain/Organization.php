<?php

declare(strict_types=1);

namespace App\Organization\Domain;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TenantOwned;

/**
 * A company using pinround: the tenant every other tenant row belongs to. Its own row is a tenant row too, so it is
 * read and written with its own id as the tenant (ADR-0005).
 */
final class Organization implements TenantOwned
{
    // Lowercase letters and digits in groups joined by single hyphens, 63 characters at most (a DNS label).
    private const string SLUG = '/^(?=.{1,63}\z)[a-z0-9]+(?:-[a-z0-9]+)*\z/';

    private readonly string $id;
    private readonly string $name;
    private readonly OrganizationStatus $status;

    /**
     * @throws BlankOrganizationName   when nothing is left of the name after trimming
     * @throws InvalidOrganizationSlug when the slug is not lowercase letters and digits joined by single hyphens, of
     *                                 at most 63 characters
     */
    public function __construct(OrganizationId $id, string $name, private readonly string $slug, private readonly \DateTimeImmutable $createdAt)
    {
        $trimmedName = trim($name);
        if ('' === $trimmedName) {
            throw new BlankOrganizationName('An organization needs a name.');
        }
        if (1 !== preg_match(self::SLUG, $slug)) {
            throw new InvalidOrganizationSlug('A slug is lowercase letters and digits joined by single hyphens, at most 63 characters.');
        }

        $this->id = $id->toString();
        $this->name = $trimmedName;
        $this->status = OrganizationStatus::Active;
    }

    public function id(): OrganizationId
    {
        return new OrganizationId($this->id);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function status(): OrganizationStatus
    {
        return $this->status;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
