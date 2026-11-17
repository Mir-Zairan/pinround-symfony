<?php

declare(strict_types=1);

namespace App\Assist\Application\LocalAi;

use App\Assist\Api\LocalAiPurpose;
use App\Assist\Api\LocalModelName;
use App\Assist\Api\LocalPackageId;
use App\Assist\Application\PromptVersion;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * The laptop AI prompt packages (ast_local_package). The time a package is issued, expires and is used comes from the
 * application clock. Every method but ownerOf works under row-level security, so it needs an open transaction whose
 * tenant is $organizationId.
 */
interface LocalPackages
{
    /**
     * Counts the person's unused, unexpired packages and the packages they were given in the last hour, and adds this
     * one only when they hold fewer than $maxOpenPackages and were given fewer than $maxPackagesPerHour, under a lock
     * per person held to the end of the transaction, so two at once never both pass.
     *
     * @return \DateTimeImmutable|LocalPackageLimit when the package expires; else the cap the person reached (the open
     *                                              one when both are), and nothing was stored
     *
     * @throws \LogicException when no transaction is open or $organizationId is not its tenant, before any query
     */
    public function issue(LocalPackageId $packageId, OrganizationId $organizationId, UserId $requesterId, TicketId $ticketId, LocalAiPurpose $purpose, PromptVersion $promptVersion, int $lifetimeSeconds, int $maxOpenPackages, int $maxPackagesPerHour): \DateTimeImmutable|LocalPackageLimit;

    /**
     * Through resolve_laptop_package, so it finds the package in any organization; the caller compares the owner
     * with the poster and the tenant. Used and expired packages are found too.
     *
     * @return LocalPackageOwner|null null when no package has this id
     */
    public function ownerOf(LocalPackageId $packageId): ?LocalPackageOwner;

    /**
     * @return IssuedLocalPackage|null null when the organization has no package with this id that $userId holds
     *
     * @throws \LogicException           when no transaction is open or $organizationId is not its tenant, before any query
     * @throws \UnexpectedValueException when the stored purpose, ticket or prompt version is not one issue() writes
     */
    public function issuedOf(OrganizationId $organizationId, LocalPackageId $packageId, UserId $userId): ?IssuedLocalPackage;

    /**
     * Keeps the result on the package and marks it used in one UPDATE that matches only an unused, unexpired package
     * of $posterId, so of two posts at once exactly one is kept.
     *
     * @param string $text the checked text, 1 to 10,000 characters
     *
     * @return \DateTimeImmutable|null when it was kept; null when the package was used or expired meanwhile, and
     *                                 nothing changed
     *
     * @throws \LogicException when no transaction is open or $organizationId is not its tenant, before any query
     */
    public function post(OrganizationId $organizationId, LocalPackageId $packageId, UserId $posterId, string $text, LocalModelName $model): ?\DateTimeImmutable;
}
