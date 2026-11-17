<?php

declare(strict_types=1);

namespace App\Tests\Unit\Audit\Application;

use App\Audit\Api\ActorKind;
use App\Audit\Api\AuditEntry;
use App\Audit\Api\AuditLog;
use App\Audit\Application\RecordOrganizationAuditEntry;
use App\Organization\Api\Event\MemberInvited;
use App\Organization\Api\Event\MemberJoined;
use App\Organization\Api\Event\OrganizationEvent;
use App\Organization\Api\Event\OrgRolesChanged;
use App\Organization\Api\Event\SettingsChanged;
use App\Organization\Api\Event\TeamCreated;
use App\Organization\Api\Event\TeamRoleChanged;
use App\SharedKernel\Application\OnceOnlyHandling;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RecordOrganizationAuditEntryTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000005a1';
    private const string MIAS_INVITATION = '0197a3c0-0000-7000-8000-0000000005e1';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-0000000005c1';
    private const string LENA = '0197a3c0-0000-7000-8000-000000000552';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000000553';
    private const string SARA = '0197a3c0-0000-7000-8000-000000000556';

    /**
     * @return iterable<string, array{OrganizationEvent, string, string, string, array<string, mixed>}>
     */
    public static function events(): iterable
    {
        $now = new \DateTimeImmutable('2027-03-15 10:00:00 UTC');

        yield 'member invited' => [new MemberInvited(self::ACME_FOODS, self::MIAS_INVITATION, self::SARA, $now), 'member.invited', 'invitation', self::MIAS_INVITATION, []];
        yield 'member joined' => [new MemberJoined(self::ACME_FOODS, self::OMAR, ['manager'], self::SARA, $now), 'member.joined', 'user', self::OMAR, ['org_roles' => ['manager']]];
        yield 'org roles changed' => [new OrgRolesChanged(self::ACME_FOODS, self::LENA, ['admin'], ['admin', 'manager'], self::SARA, $now), 'org_roles.changed', 'user', self::LENA, ['from' => ['admin'], 'to' => ['admin', 'manager']]];
        yield 'team created' => [new TeamCreated(self::ACME_FOODS, self::SHOP_TEAM, self::LENA, self::SARA, $now), 'team.created', 'team', self::SHOP_TEAM, ['lead_user_id' => self::LENA]];
        yield 'team role changed' => [new TeamRoleChanged(self::ACME_FOODS, self::SHOP_TEAM, self::OMAR, null, 'viewer', self::SARA, $now), 'team_role.changed', 'user', self::OMAR, ['team_id' => self::SHOP_TEAM, 'from' => null, 'to' => 'viewer']];
        yield 'settings changed' => [new SettingsChanged(self::ACME_FOODS, 'ai.where', 'cloud', 'laptop', self::SARA, $now), 'settings.changed', 'organization', self::ACME_FOODS, ['setting' => 'ai.where', 'from' => 'cloud', 'to' => 'laptop']];
        yield 'provider key changed' => [new SettingsChanged(self::ACME_FOODS, 'ai.provider_key', null, 'changed', self::SARA, $now), 'settings.changed', 'organization', self::ACME_FOODS, ['setting' => 'ai.provider_key', 'from' => null, 'to' => 'changed']];
    }

    /**
     * @param array<string, mixed> $expectedData
     */
    #[Test]
    #[DataProvider('events')]
    public function eachEventIsRecordedOnceWithItsActionTargetAndAllowListedData(OrganizationEvent $event, string $expectedAction, string $expectedTargetType, string $expectedTargetId, array $expectedData): void
    {
        $auditLog = self::recordingAuditLog();

        new RecordOrganizationAuditEntry(self::runningOnceOnlyHandling(), $auditLog)($event);

        self::assertCount(1, $auditLog->entries);
        $entry = $auditLog->entries[0];
        self::assertSame(
            [self::ACME_FOODS, ActorKind::User, self::SARA, $expectedAction, $expectedTargetType, $expectedTargetId, $expectedData],
            [$entry->organizationId->toString(), $entry->actorKind, $entry->actorUserId, $entry->action, $entry->targetType, $entry->targetId, $entry->data],
        );
        self::assertSame(RecordOrganizationAuditEntry::DATA_KEYS[$expectedAction], array_keys($entry->data));
        self::assertSame($event->occurredAt, $entry->occurredAt);
    }

    #[Test]
    public function dataWithExactlyTheAllowListedKeysPasses(): void
    {
        $data = ['to' => 'viewer', 'team_id' => self::SHOP_TEAM, 'from' => null];

        self::assertSame($data, RecordOrganizationAuditEntry::requireAllowListedData('team_role.changed', $data));
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function refusedData(): iterable
    {
        yield 'a key outside the list' => ['team.created', ['lead_user_id' => self::LENA, 'name' => 'Shop']];
        yield 'a listed key missing' => ['org_roles.changed', ['to' => ['admin']]];
        yield 'an action with no list' => ['team.renamed', []];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[Test]
    #[DataProvider('refusedData')]
    public function dataThatIsNotExactlyTheActionsAllowListIsRefused(string $action, array $data): void
    {
        $this->expectException(\LogicException::class);

        RecordOrganizationAuditEntry::requireAllowListedData($action, $data);
    }

    #[Test]
    public function anEventTheSystemCausedIsRecordedWithTheSystemAsActor(): void
    {
        $auditLog = self::recordingAuditLog();

        new RecordOrganizationAuditEntry(self::runningOnceOnlyHandling(), $auditLog)(new MemberJoined(self::ACME_FOODS, self::LENA, ['admin'], null, new \DateTimeImmutable('2027-03-15 10:00:00 UTC')));

        self::assertCount(1, $auditLog->entries);
        self::assertSame([ActorKind::System, null], [$auditLog->entries[0]->actorKind, $auditLog->entries[0]->actorUserId]);
        self::assertEquals(new \DateTimeImmutable('2027-03-15 10:00:00 UTC'), $auditLog->entries[0]->occurredAt);
    }

    #[Test]
    public function theEntryIsWrittenInsideTheOnceOnlyEffectUnderItsOwnHandlerName(): void
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

        new RecordOrganizationAuditEntry($skippingOnceOnlyHandling, $auditLog)(new MemberJoined(self::ACME_FOODS, self::LENA, ['admin'], null, new \DateTimeImmutable('2027-03-15 10:00:00 UTC')));

        self::assertSame(['audit.record_organization_event'], $skippingOnceOnlyHandling->handlerNames);
        self::assertSame([], $auditLog->entries);
    }

    #[Test]
    public function anOrganizationEventWithNoAuditActionFailsInsteadOfGoingUnrecorded(): void
    {
        $unknownEvent = new class(self::ACME_FOODS, null, new \DateTimeImmutable('2027-03-15 10:00:00 UTC')) implements OrganizationEvent {
            public function __construct(public string $organizationId, public ?string $actorId, public \DateTimeImmutable $occurredAt)
            {
            }
        };

        $this->expectException(\LogicException::class);
        new RecordOrganizationAuditEntry(self::runningOnceOnlyHandling(), self::recordingAuditLog())($unknownEvent);
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
