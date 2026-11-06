<?php

declare(strict_types=1);

namespace App\Tests\Unit\SharedKernel\Infrastructure\Doctrine\Migration;

use App\SharedKernel\Infrastructure\Doctrine\Migration\TenantTable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TenantTableTest extends TestCase
{
    #[Test]
    public function itEnablesAndForcesRowLevelSecurityAndAddsTheTenantIsolationPolicy(): void
    {
        self::assertSame(
            [
                'ALTER TABLE "tkt_ticket" ENABLE ROW LEVEL SECURITY',
                'ALTER TABLE "tkt_ticket" FORCE ROW LEVEL SECURITY',
                'CREATE POLICY tenant_isolation ON "tkt_ticket" FOR ALL'
                    ." USING (organization_id = NULLIF(current_setting('app.org_id', true), '')::uuid)"
                    ." WITH CHECK (organization_id = NULLIF(current_setting('app.org_id', true), '')::uuid)",
            ],
            TenantTable::enableRls('tkt_ticket'),
        );
    }

    #[Test]
    public function itAcceptsALongestPossibleName(): void
    {
        self::assertCount(3, TenantTable::enableRls('_'.str_repeat('a', 62)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notAPlainLowercaseIdentifier(): iterable
    {
        yield 'empty' => [''];
        yield 'uppercase' => ['Tkt_ticket'];
        yield 'leading digit' => ['1ticket'];
        yield 'schema qualified' => ['public.tkt_ticket'];
        yield 'quote' => ['tkt"; DROP TABLE x; --'];
        yield 'space' => ['tkt ticket'];
        yield 'trailing newline' => ["tkt_ticket\n"];
        yield 'longer than postgres keeps' => [str_repeat('a', 64)];
    }

    #[Test]
    #[DataProvider('notAPlainLowercaseIdentifier')]
    public function itRejectsANameThatIsNotAPlainLowercaseIdentifier(string $table): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TenantTable::enableRls($table);
    }
}
