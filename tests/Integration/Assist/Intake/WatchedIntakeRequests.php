<?php

declare(strict_types=1);

namespace App\Tests\Integration\Assist\Intake;

use App\Assist\Api\DraftReport;
use App\Assist\Api\IntakeCheckId;
use App\Assist\Api\IntakeCheckView;
use App\Assist\Application\Intake\IntakeRequests;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * The real check states, with a look at what another database connection sees each time a check ends, so a test tells
 * an end written after the job's commit from one written inside its transaction. An armed failure is thrown instead of
 * ending the check, as the real state throws when Valkey cannot be reached, and leaves the state as it was.
 */
final class WatchedIntakeRequests implements IntakeRequests
{
    /** @var list<array{string, mixed}> */
    private array $endings = [];

    private ?\RuntimeException $endFailure = null;

    /**
     * @param \Closure(): mixed $whatAnotherConnectionSees
     */
    public function __construct(private readonly IntakeRequests $realRequests, private readonly \Closure $whatAnotherConnectionSees)
    {
    }

    public function failEveryEndWith(\RuntimeException $endFailure): void
    {
        $this->endFailure = $endFailure;
    }

    /**
     * @return list<array{string, mixed}> each end's method and what another connection saw as it began, in call order
     */
    public function endings(): array
    {
        return $this->endings;
    }

    public function open(OrganizationId $organizationId, IntakeCheckId $checkId, UserId $requesterId, DraftReport $draft): void
    {
        $this->realRequests->open($organizationId, $checkId, $requesterId, $draft);
    }

    public function markAiMayBeAsked(OrganizationId $organizationId, IntakeCheckId $checkId, UserId $requesterId): ?DraftReport
    {
        return $this->realRequests->markAiMayBeAsked($organizationId, $checkId, $requesterId);
    }

    public function recordFailure(OrganizationId $organizationId, IntakeCheckId $checkId, UserId $requesterId, string $sentence): void
    {
        $this->endings[] = ['recordFailure', ($this->whatAnotherConnectionSees)()];
        if (null !== $this->endFailure) {
            throw $this->endFailure;
        }
        $this->realRequests->recordFailure($organizationId, $checkId, $requesterId, $sentence);
    }

    public function release(OrganizationId $organizationId, IntakeCheckId $checkId, UserId $requesterId): void
    {
        $this->endings[] = ['release', ($this->whatAnotherConnectionSees)()];
        if (null !== $this->endFailure) {
            throw $this->endFailure;
        }
        $this->realRequests->release($organizationId, $checkId, $requesterId);
    }

    public function stateOf(OrganizationId $organizationId, IntakeCheckId $checkId, UserId $requesterId): ?IntakeCheckView
    {
        return $this->realRequests->stateOf($organizationId, $checkId, $requesterId);
    }
}
