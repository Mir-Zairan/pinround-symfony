<?php

declare(strict_types=1);

namespace App\Tests\Unit\Audit\Application;

use App\Audit\Application\AuditDataAllowList;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AuditDataAllowListTest extends TestCase
{
    private const array DATA_KEYS = [
        'team.created' => ['lead_user_id', 'team_id'],
        'member.invited' => [],
    ];

    #[Test]
    public function dataWithExactlyTheListedKeysInAnyOrderIsReturnedUnchanged(): void
    {
        $data = ['team_id' => '0197a3c0-0000-7000-8000-0000000004c1', 'lead_user_id' => '0197a3c0-0000-7000-8000-000000000452'];

        self::assertSame($data, AuditDataAllowList::requireExactKeys(self::DATA_KEYS, 'team.created', $data));
        self::assertSame([], AuditDataAllowList::requireExactKeys(self::DATA_KEYS, 'member.invited', []));
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, string}>
     */
    public static function refusedData(): iterable
    {
        yield 'a key outside the list' => ['member.invited', ['email' => 'mia@acme-foods.example'], 'The data of the audit action "member.invited" must hold exactly the keys .'];
        yield 'a listed key missing' => ['team.created', ['team_id' => '0197a3c0-0000-7000-8000-0000000004c1'], 'The data of the audit action "team.created" must hold exactly the keys lead_user_id, team_id.'];
        yield 'an action with no list' => ['team.renamed', [], 'The audit action "team.renamed" has no data allow-list.'];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[Test]
    #[DataProvider('refusedData')]
    public function dataThatIsNotExactlyTheActionsListIsRefused(string $action, array $data, string $expectedMessage): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage($expectedMessage);

        AuditDataAllowList::requireExactKeys(self::DATA_KEYS, $action, $data);
    }
}
