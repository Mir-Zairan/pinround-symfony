<?php

declare(strict_types=1);

namespace App\Tests\Unit\Audit\Application;

use App\Audit\Api\ActorKind;
use App\Audit\Api\AuditEntry;
use App\Audit\Api\AuditLog;
use App\Audit\Application\RecordIdentityAuditEntry;
use App\Identity\Api\Event\ApiTokenIssued;
use App\Identity\Api\Event\ApiTokenRevoked;
use App\SharedKernel\Application\OnceOnlyHandling;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RecordIdentityAuditEntryTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000006a1';
    private const string OMARS_TOKEN = '0197a3c0-0000-7000-8000-0000000006c1';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000000653';
    private const string SARA = '0197a3c0-0000-7000-8000-000000000656';
    private const string OCCURRED_AT = '2027-03-15 10:00:00 UTC';

    /**
     * @return iterable<string, array{ApiTokenIssued|ApiTokenRevoked, string, string, array<string, mixed>}>
     */
    public static function events(): iterable
    {
        $occurredAt = new \DateTimeImmutable(self::OCCURRED_AT);

        // The expiry in another zone is recorded in UTC, so the row reads the same wherever it was made.
        yield 'token issued' => [
            new ApiTokenIssued(self::ACME_FOODS, self::OMARS_TOKEN, self::OMAR, ['read', 'rounds:write'], new \DateTimeImmutable('2027-04-14 15:30:00 Asia/Kolkata'), self::OMAR, $occurredAt),
            self::OMAR,
            'api_token.issued',
            ['scopes' => ['read', 'rounds:write'], 'expires_at' => '2027-04-14T10:00:00+00:00'],
        ];
        yield 'token revoked by its owner' => [new ApiTokenRevoked(self::ACME_FOODS, self::OMARS_TOKEN, self::OMAR, self::OMAR, $occurredAt), self::OMAR, 'api_token.revoked', []];
        yield 'token revoked by an admin' => [new ApiTokenRevoked(self::ACME_FOODS, self::OMARS_TOKEN, self::OMAR, self::SARA, $occurredAt), self::SARA, 'api_token.revoked', []];
    }

    /**
     * @param array<string, mixed> $expectedData
     */
    #[Test]
    #[DataProvider('events')]
    public function eachTokenEventIsRecordedOnceWithTheTokenAsTargetAndOnlyAllowListedData(ApiTokenIssued|ApiTokenRevoked $event, string $expectedActor, string $expectedAction, array $expectedData): void
    {
        $auditLog = self::recordingAuditLog();

        new RecordIdentityAuditEntry(self::runningOnceOnlyHandling(), $auditLog)($event);

        self::assertCount(1, $auditLog->entries);
        $entry = $auditLog->entries[0];
        self::assertSame(
            [self::ACME_FOODS, ActorKind::User, $expectedActor, null, $expectedAction, 'api_token', self::OMARS_TOKEN, $expectedData],
            [$entry->organizationId->toString(), $entry->actorKind, $entry->actorUserId, $entry->actorTokenId, $entry->action, $entry->targetType, $entry->targetId, $entry->data],
        );
        self::assertSame(RecordIdentityAuditEntry::DATA_KEYS[$expectedAction], array_keys($entry->data));
        self::assertEquals(new \DateTimeImmutable(self::OCCURRED_AT), $entry->occurredAt);
    }

    #[Test]
    public function dataWithExactlyTheAllowListedKeysPasses(): void
    {
        $data = ['expires_at' => '2027-04-14T10:00:00+00:00', 'scopes' => ['read']];

        self::assertSame($data, RecordIdentityAuditEntry::requireAllowListedData('api_token.issued', $data));
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function refusedData(): iterable
    {
        yield 'the token name' => ['api_token.issued', ['scopes' => ['read'], 'expires_at' => '2027-04-14T10:00:00+00:00', 'name' => 'Claude Code']];
        yield 'the token prefix' => ['api_token.revoked', ['prefix' => 'pr_a1b2c3d4']];
        yield 'a listed key missing' => ['api_token.issued', ['scopes' => ['read']]];
        yield 'an action with no list' => ['api_token.used', []];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[Test]
    #[DataProvider('refusedData')]
    public function dataThatIsNotExactlyTheActionsAllowListIsRefused(string $action, array $data): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/^The (audit action|data of the audit action) "'.preg_quote($action, '/').'"/');

        RecordIdentityAuditEntry::requireAllowListedData($action, $data);
    }

    #[Test]
    public function aRedeliveredEventIsSkippedUnderTheHandlersOwnName(): void
    {
        $auditLog = self::recordingAuditLog();
        $skippingOnceOnlyHandling = new class implements OnceOnlyHandling {
            /** @var list<string> */
            public array $handlerNames = [];

            public function runOnce(string $handlerName, \Closure $effect): bool
            {
                $this->handlerNames[] = $handlerName;

                return false;
            }
        };

        new RecordIdentityAuditEntry($skippingOnceOnlyHandling, $auditLog)(new ApiTokenRevoked(self::ACME_FOODS, self::OMARS_TOKEN, self::OMAR, self::OMAR, new \DateTimeImmutable(self::OCCURRED_AT)));

        self::assertSame(['audit.record_identity_event'], $skippingOnceOnlyHandling->handlerNames);
        self::assertSame([], $auditLog->entries);
    }

    private static function runningOnceOnlyHandling(): OnceOnlyHandling
    {
        return new class implements OnceOnlyHandling {
            public function runOnce(string $handlerName, \Closure $effect): bool
            {
                $effect();

                return true;
            }
        };
    }

    /**
     * @return AuditLog&object{entries: list<AuditEntry>}
     */
    private static function recordingAuditLog(): AuditLog
    {
        return new class implements AuditLog {
            /** @var list<AuditEntry> */
            public array $entries = [];

            public function record(AuditEntry $entry): void
            {
                $this->entries[] = $entry;
            }
        };
    }
}
