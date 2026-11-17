<?php

declare(strict_types=1);

namespace App\Tests\Integration\Assist\LocalAi;

use App\Assist\Api\LocalAiPurpose;
use App\Assist\Api\LocalModelName;
use App\Assist\Api\LocalPackageId;
use App\Assist\Application\LocalAi\IssuedLocalPackage;
use App\Assist\Application\LocalAi\LocalPackageOwner;
use App\Assist\Application\LocalAi\LocalPackages;
use App\Assist\Application\PromptVersion;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * The real packages, with the names of the methods that read or write a package in call order, so a test tells a
 * refusal decided from the resolver's owner from one the store's own filters gave. When a rival result is armed,
 * issuedOf() posts it through the real store after reading, as another tab's post landing between the read and the
 * UPDATE would.
 */
final class WatchedLocalPackages implements LocalPackages
{
    /** @var list<string> */
    private array $packageCalls = [];

    private ?string $rivalText = null;

    private ?LocalModelName $rivalModel = null;

    public function __construct(private readonly LocalPackages $realPackages)
    {
    }

    public function postRivalAfterEachRead(string $rivalText, LocalModelName $rivalModel): void
    {
        $this->rivalText = $rivalText;
        $this->rivalModel = $rivalModel;
    }

    /**
     * @return list<string> 'issuedOf' and 'post', in call order
     */
    public function packageCalls(): array
    {
        return $this->packageCalls;
    }

    public function issue(LocalPackageId $packageId, OrganizationId $organizationId, UserId $requesterId, TicketId $ticketId, LocalAiPurpose $purpose, PromptVersion $promptVersion, int $lifetimeSeconds, int $maxOpenPackages): ?\DateTimeImmutable
    {
        return $this->realPackages->issue($packageId, $organizationId, $requesterId, $ticketId, $purpose, $promptVersion, $lifetimeSeconds, $maxOpenPackages);
    }

    public function ownerOf(LocalPackageId $packageId): ?LocalPackageOwner
    {
        return $this->realPackages->ownerOf($packageId);
    }

    public function issuedOf(OrganizationId $organizationId, LocalPackageId $packageId, UserId $userId): ?IssuedLocalPackage
    {
        $this->packageCalls[] = 'issuedOf';
        $issued = $this->realPackages->issuedOf($organizationId, $packageId, $userId);
        if (null !== $this->rivalText && null !== $this->rivalModel
            && null === $this->realPackages->post($organizationId, $packageId, $userId, $this->rivalText, $this->rivalModel)) {
            throw new \LogicException('The rival result was not kept, so nothing raced.');
        }

        return $issued;
    }

    public function post(OrganizationId $organizationId, LocalPackageId $packageId, UserId $posterId, string $text, LocalModelName $model): ?\DateTimeImmutable
    {
        $this->packageCalls[] = 'post';

        return $this->realPackages->post($organizationId, $packageId, $posterId, $text, $model);
    }
}
