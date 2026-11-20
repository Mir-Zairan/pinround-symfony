<?php

declare(strict_types=1);

namespace App\AgentApi\Infrastructure\Mcp;

use App\Media\Api\CompletedUpload;
use App\Media\Api\UploadCompletions;
use App\Media\Api\UploadIntentNotFound;
use App\Media\Api\UploadRefused;
use App\Media\Api\UploadTargetDenied;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * Finishes the caller's upload for a tool that adds its picture, with Media's refusals as the tool's: one mapping for
 * every such tool, so they never disagree on what a missing or expired upload answers.
 */
final class ToolUploadCompletion
{
    /**
     * Runs in the caller's transaction, the gate's savepoint, so a later refusal takes the completion back.
     *
     * @param string $expiredMessage the refusal for an upload that expired unused, naming the tool to begin a new one
     *
     * @throws ToolRefusal not found when Media does not find the caller's intent or its target now refuses them; a
     *                     plain refusal with $expiredMessage when it expired unused
     */
    public static function completed(UploadCompletions $uploadCompletions, OrganizationId $organizationId, UserId $callerId, string $uploadId, \DateTimeImmutable $now, string $expiredMessage): CompletedUpload
    {
        try {
            return $uploadCompletions->completeInTransaction($organizationId, $callerId, $uploadId, $now);
        } catch (UploadIntentNotFound|UploadTargetDenied) {
            throw ToolRefusal::notFound();
        } catch (UploadRefused) {
            throw ToolRefusal::refused($expiredMessage);
        }
    }
}
