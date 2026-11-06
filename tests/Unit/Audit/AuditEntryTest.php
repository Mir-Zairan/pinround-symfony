<?php

declare(strict_types=1);

namespace App\Tests\Unit\Audit;

use App\Audit\Api\ActorKind;
use App\Audit\Api\AuditEntry;
use App\SharedKernel\Domain\OrganizationId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AuditEntryTest extends TestCase
{
    private const string ORG_A = '0197a3c0-0000-7000-8000-00000000000a';
    private const string USER = '0197a3c0-0000-7000-8000-0000000000c1';
    private const string TOKEN = '0197a3c0-0000-7000-8000-0000000000d1';
    private const string TARGET = '0197a3c0-0000-7000-8000-0000000000e1';
    private const string MARKER = 'Customer wrote: AUDIT-LEAK-MARKER';

    /** Text goes nowhere but data: action and target type are codes, the ids are UUIDs. */
    #[Test]
    #[DataProvider('entriesCarryingTextOrBadIds')]
    public function anEntryCarryingTextOutsideDataOrABadIdCannotBeBuilt(\Closure $buildEntry): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $buildEntry();
    }

    /** @return iterable<string, array{\Closure(): AuditEntry}> */
    public static function entriesCarryingTextOrBadIds(): iterable
    {
        $org = new OrganizationId(self::ORG_A);

        yield 'text as the action' => [static fn () => AuditEntry::bySystem($org, self::MARKER, 'ticket', null)];
        yield 'text as the target type' => [static fn () => AuditEntry::bySystem($org, 'ticket.visibility_changed', self::MARKER, null)];
        yield 'an uppercase action' => [static fn () => AuditEntry::bySystem($org, 'Role.changed', 'membership', null)];
        yield 'an empty action' => [static fn () => AuditEntry::bySystem($org, '', 'membership', null)];
        yield 'an action with a trailing newline' => [static fn () => AuditEntry::bySystem($org, "role.changed\n", 'membership', null)];
        yield 'an action longer than 63 characters' => [static fn () => AuditEntry::bySystem($org, str_repeat('a', 64), 'membership', null)];
        yield 'text as the target id' => [static fn () => AuditEntry::bySystem($org, 'ticket.closed', 'ticket', self::MARKER)];
        yield 'text as the user id' => [static fn () => AuditEntry::byUser($org, self::MARKER, 'role.changed', 'membership', null)];
        yield 'text as the token id' => [static fn () => AuditEntry::byToken($org, self::USER, self::MARKER, 'mcp.tool_called', 'ticket', null)];
        yield 'an empty ip hash' => [static fn () => AuditEntry::byUser($org, self::USER, 'login.failed', 'user', self::USER, [], '')];
        yield 'an empty user agent hash' => [static fn () => AuditEntry::byUser($org, self::USER, 'login.failed', 'user', self::USER, [], null, '')];
    }

    #[Test]
    public function eachFactorySetsItsActorKind(): void
    {
        $org = new OrganizationId(self::ORG_A);

        self::assertSame(ActorKind::User, AuditEntry::byUser($org, self::USER, 'role.changed', 'membership', null)->actorKind);
        self::assertSame(ActorKind::Token, AuditEntry::byToken($org, self::USER, self::TOKEN, 'mcp.tool_called', 'ticket', null)->actorKind);
        self::assertSame(ActorKind::System, AuditEntry::bySystem($org, 'retention.changed', 'organization', null)->actorKind);
        self::assertSame(self::TARGET, AuditEntry::bySystem($org, 'ticket.closed', 'ticket', strtoupper(self::TARGET))->targetId);
    }
}
