<?php

declare(strict_types=1);

namespace App\Tests\Unit\AgentApi\Infrastructure\Mcp\Tool;

use App\Media\Api\CompletedUpload;
use App\Media\Api\UploadCompletions;
use App\Media\Api\UploadIntentNotFound;
use App\Media\Api\UploadTarget;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * Upload intents as DirectUploads answers them: only the organization's intents the asking person began, by an id in
 * any case, and UploadIntentNotFound for any other on completion. A completion answers the scripted picture, or throws
 * the scripted refusal, and is recorded either way.
 */
final class ScriptedUploadCompletions implements UploadCompletions
{
    /** @var array<string, array{OrganizationId, UserId, UploadTarget, CompletedUpload|\Throwable}> by intent id in lowercase */
    private array $intents = [];

    /** @var list<array{string, string, string}> the intent id, user and time of each completeInTransaction, as asked */
    public array $completions = [];

    public function add(OrganizationId $organizationId, UserId $userId, string $intentId, UploadTarget $target, CompletedUpload|\Throwable $completion): void
    {
        $this->intents[strtolower($intentId)] = [$organizationId, $userId, $target, $completion];
    }

    public function targetOf(OrganizationId $organizationId, UserId $userId, string $intentId): ?UploadTarget
    {
        $intent = $this->intentOf($organizationId, $userId, $intentId);

        return null === $intent ? null : $intent[2];
    }

    public function completeInTransaction(OrganizationId $organizationId, UserId $userId, string $intentId, \DateTimeImmutable $at): CompletedUpload
    {
        $this->completions[] = [$intentId, $userId->toString(), $at->format('Y-m-d H:i:s')];
        $intent = $this->intentOf($organizationId, $userId, $intentId) ?? throw new UploadIntentNotFound('No such upload intent.');
        if ($intent[3] instanceof \Throwable) {
            throw $intent[3];
        }

        return $intent[3];
    }

    /**
     * @return array{OrganizationId, UserId, UploadTarget, CompletedUpload|\Throwable}|null
     */
    private function intentOf(OrganizationId $organizationId, UserId $userId, string $intentId): ?array
    {
        $intent = $this->intents[strtolower($intentId)] ?? null;
        if (null === $intent || !$intent[0]->equals($organizationId) || !$intent[1]->equals($userId)) {
            return null;
        }

        return $intent;
    }
}
