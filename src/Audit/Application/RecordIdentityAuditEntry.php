<?php

declare(strict_types=1);

namespace App\Audit\Application;

use App\Audit\Api\AuditEntry;
use App\Audit\Api\AuditLog;
use App\Identity\Api\Event\ApiTokenIssued;
use App\Identity\Api\Event\ApiTokenRevoked;
use App\SharedKernel\Application\OnceOnlyHandling;
use App\SharedKernel\Domain\OrganizationId;

/**
 * Writes one aud_event row per personal access token made or revoked (ADR-0026, ADR-0021), once per message however
 * often it is delivered. It runs in worker-notify on event.bus (config/services.yaml), in the bus's transaction under
 * the token's organization, and keeps the event's time. The target is the token; the data holds exactly the keys
 * AuditDataAllowList lists for the action, which AuditLog::record checks, never the token, its hash, its prefix or its
 * name.
 */
final readonly class RecordIdentityAuditEntry
{
    /** The OnceOnlyHandling name; kept when the class is renamed, or redelivered events would be recorded again. */
    public const string HANDLER_NAME = 'audit.record_identity_event';

    public function __construct(private OnceOnlyHandling $onceOnlyHandling, private AuditLog $auditLog)
    {
    }

    public function __invoke(ApiTokenIssued|ApiTokenRevoked $event): void
    {
        $entry = $this->entryFor($event);
        $this->onceOnlyHandling->runOnce(self::HANDLER_NAME, function () use ($entry): void {
            $this->auditLog->record($entry);
        });
    }

    private function entryFor(ApiTokenIssued|ApiTokenRevoked $event): AuditEntry
    {
        [$action, $scopeNamesAndExpiry] = match (true) {
            $event instanceof ApiTokenIssued => ['api_token.issued', [
                'scopes' => $event->scopes,
                'expires_at' => $event->expiresAt->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM),
            ]],
            $event instanceof ApiTokenRevoked => ['api_token.revoked', []],
        };

        return AuditEntry::byUser(
            new OrganizationId($event->organizationId),
            $event->actorId,
            $action,
            'api_token',
            $event->tokenId,
            $scopeNamesAndExpiry,
            occurredAt: $event->occurredAt,
        );
    }
}
