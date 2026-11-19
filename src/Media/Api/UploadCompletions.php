<?php

declare(strict_types=1);

namespace App\Media\Api;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * Direct uploads finished inside the caller's own transaction, for a caller that runs in the request transaction and
 * so cannot let Uploads::complete() open one, such as an MCP tool (P09.T5). The intent id is the handle: the picture a
 * completion makes takes the intent's id, so the same id finds it again afterwards. Both calls run under the tenant
 * $organizationId inside an open transaction, and answer only intents $userId began.
 */
interface UploadCompletions
{
    /**
     * What the intent was begun for, in one read and with no write.
     *
     * @return UploadTarget|null null when $intentId is malformed, or the organization has no intent with that id that
     *                           $userId began
     *
     * @throws \LogicException when the tenant is missing or is not $organizationId, or no transaction is open
     */
    public function targetOf(OrganizationId $organizationId, UserId $userId, string $intentId): ?UploadTarget;

    /**
     * The picture of the intent. An unused intent is completed first with the checks and writes of Uploads::complete()
     * (the stored target asked again, the intent used at $at, a pending media object created at $at, its processing
     * written to the outbox), but in the caller's transaction: they commit or roll back with the caller's own writes. A
     * used intent, also one another request used meanwhile, is answered with the picture its completion made, and
     * nothing is written or checked again; so is one used after it expired.
     *
     * @throws UploadIntentNotFound when $intentId is malformed, the organization has no intent with that id that $userId
     *                              began, or the intent was used and has no picture; nothing changes then
     * @throws UploadTargetDenied   when completing, and the MediaTargetAuthorizer of the stored target now refuses
     *                              $userId or no authorizer decides its kind; nothing changes then
     * @throws UploadRefused        when the unused intent has expired by $at ("The page was open too long. Try
     *                              again."); nothing changes then
     * @throws \LogicException      when the tenant is missing or is not $organizationId, or no transaction is open
     */
    public function completeInTransaction(OrganizationId $organizationId, UserId $userId, string $intentId, \DateTimeImmutable $at): CompletedUpload;
}
