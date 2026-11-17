<?php

declare(strict_types=1);

namespace App\Tests\Unit\Assist\Infrastructure\Messenger;

use App\Assist\Api\DraftReport;
use App\Assist\Api\IntakeCheckId;
use App\Assist\Api\IntakeCheckView;
use App\Assist\Application\Intake\IntakeRequests;
use App\Assist\Application\Intake\TooManyOpenIntakeChecks;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * Check states in memory, kept as ValkeyIntakeRequests keeps them: the state and the draft each by "organization:check",
 * and each requester's open checks by "organization:requester", at most MAX_OPEN_CHECKS_PER_PERSON of them. A failure
 * replaces the state, drops the draft and closes the check; a release forgets all three. An armed failure is thrown
 * instead of writing, as the real state throws when Valkey cannot be reached, and leaves everything as it was. Nothing
 * expires here, and the mark and the read refuse, as no test here needs them.
 */
final class RememberedIntakeRequests implements IntakeRequests
{
    /** @var array<string, array{string, string, ?string}> status, requester and sentence by "organization:check" */
    public array $states = [];

    /** @var array<string, string> the draft's title by "organization:check" */
    public array $drafts = [];

    /** @var array<string, list<string>> the open check ids by "organization:requester", in the order they were opened */
    public array $openChecks = [];

    public ?\RuntimeException $failure = null;

    public function open(OrganizationId $organizationId, IntakeCheckId $checkId, UserId $requesterId, DraftReport $draft): void
    {
        $this->throwArmedFailure();
        $checkKey = self::checkKeyOf($organizationId, $checkId);
        if (isset($this->states[$checkKey])) {
            throw new \RuntimeException('The intake check state could not be opened.');
        }
        $openKey = self::openKeyOf($organizationId, $requesterId);
        if (\count($this->openChecks[$openKey] ?? []) >= self::MAX_OPEN_CHECKS_PER_PERSON) {
            throw new TooManyOpenIntakeChecks();
        }
        $this->states[$checkKey] = [IntakeCheckView::QUEUED, $requesterId->toString(), null];
        $this->drafts[$checkKey] = $draft->title;
        $this->openChecks[$openKey][] = $checkId->toString();
    }

    public function markAiMayBeAsked(OrganizationId $organizationId, IntakeCheckId $checkId, UserId $requesterId): ?DraftReport
    {
        throw new \LogicException('Not kept by this double.');
    }

    public function recordFailure(OrganizationId $organizationId, IntakeCheckId $checkId, UserId $requesterId, string $sentence): void
    {
        $this->throwArmedFailure();
        $checkKey = self::checkKeyOf($organizationId, $checkId);
        $this->states[$checkKey] = [IntakeCheckView::FAILED, $requesterId->toString(), $sentence];
        unset($this->drafts[$checkKey]);
        $this->close($organizationId, $checkId, $requesterId);
    }

    public function release(OrganizationId $organizationId, IntakeCheckId $checkId, UserId $requesterId): void
    {
        $this->throwArmedFailure();
        $checkKey = self::checkKeyOf($organizationId, $checkId);
        unset($this->states[$checkKey], $this->drafts[$checkKey]);
        $this->close($organizationId, $checkId, $requesterId);
    }

    public function stateOf(OrganizationId $organizationId, IntakeCheckId $checkId, UserId $requesterId): ?IntakeCheckView
    {
        throw new \LogicException('Not kept by this double.');
    }

    private function throwArmedFailure(): void
    {
        if (null !== $this->failure) {
            throw $this->failure;
        }
    }

    /**
     * Drops an empty list, as Valkey drops an empty sorted set.
     */
    private function close(OrganizationId $organizationId, IntakeCheckId $checkId, UserId $requesterId): void
    {
        $openKey = self::openKeyOf($organizationId, $requesterId);
        $stillOpen = array_values(array_diff($this->openChecks[$openKey] ?? [], [$checkId->toString()]));
        if ([] === $stillOpen) {
            unset($this->openChecks[$openKey]);

            return;
        }
        $this->openChecks[$openKey] = $stillOpen;
    }

    private static function checkKeyOf(OrganizationId $organizationId, IntakeCheckId $checkId): string
    {
        return $organizationId->toString().':'.$checkId->toString();
    }

    private static function openKeyOf(OrganizationId $organizationId, UserId $requesterId): string
    {
        return $organizationId->toString().':'.$requesterId->toString();
    }
}
