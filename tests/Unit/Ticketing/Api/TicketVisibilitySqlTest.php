<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\Api;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketVisibility;
use App\Ticketing\Api\TicketVisibilityFacts;
use App\Ticketing\Api\TicketVisibilitySql;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TicketVisibilitySqlTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000a1a1';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-00000000a1c1';
    private const string OTHER_TEAM = '0197a3c0-0000-7000-8000-00000000a1c2';
    private const string OMAR = '0197a3c0-0000-7000-8000-00000000a1b2';
    private const string SARA = '0197a3c0-0000-7000-8000-00000000a1b5';

    #[Test]
    public function theParametersAreNamedAfterTheAliasAndCarryTheViewer(): void
    {
        $condition = TicketVisibilitySql::of(
            TicketVisibility::for(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR), [new TeamId(self::SHOP_TEAM), new TeamId(self::OTHER_TEAM)], true, new UserId(self::OMAR)),
            'tt',
        );

        self::assertSame(
            [
                'tt_visible_org' => self::ACME_FOODS,
                'tt_visible_teams' => '{'.self::SHOP_TEAM.','.self::OTHER_TEAM.'}',
                'tt_visible_me' => self::OMAR,
                'tt_visible_is_admin' => 'true',
                'tt_visible_is_triager' => 'true',
            ],
            $condition->parameters(),
        );
        self::assertStringStartsWith(
            "(tt.organization_id = CAST(:tt_visible_org AS uuid) AND (tt.visibility = '".TicketVisibilityFacts::FOR_EVERYONE."' OR tt.team_id = ANY(",
            $condition->sql(),
        );
    }

    /**
     * A caller binds the parameters with no types, so the SQL must give each one its type.
     */
    #[Test]
    public function everyParameterIsCastInTheSql(): void
    {
        $condition = TicketVisibilitySql::of(TicketVisibility::for(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR), [], false, null), 'tt');

        preg_match_all('/:(\w+)/', $condition->sql(), $placeholders);
        self::assertEqualsCanonicalizing(array_keys($condition->parameters()), $placeholders[1]);
        self::assertSame(
            [
                'CAST(:tt_visible_org AS uuid)',
                'CAST(:tt_visible_teams AS uuid[])',
                'CAST(:tt_visible_me AS uuid)',
                'CAST(:tt_visible_is_admin AS boolean)',
                'CAST(:tt_visible_is_triager AS boolean)',
            ],
            array_map(
                static fn (string $name): string => 1 === preg_match('/CAST\(:'.$name.' AS [a-z\[\]]+\)/', $condition->sql(), $cast) ? $cast[0] : $name.' is not cast',
                array_keys($condition->parameters()),
            ),
        );
    }

    #[Test]
    public function aViewerInNoTeamGetsAnEmptyArray(): void
    {
        $condition = TicketVisibilitySql::of(TicketVisibility::for(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR), [], false, new UserId(self::SARA)), 't');

        self::assertSame(['{}', 'false', 'false'], [$condition->parameters()['t_visible_teams'], $condition->parameters()['t_visible_is_admin'], $condition->parameters()['t_visible_is_triager']]);
    }

    #[Test]
    public function theForEveryoneConditionKeepsToTheOrganizationAndTicketsForEveryone(): void
    {
        $condition = TicketVisibilitySql::forEveryoneIn(new OrganizationId(self::ACME_FOODS), 'tt');

        self::assertSame(
            "(tt.organization_id = CAST(:tt_visible_org AS uuid) AND tt.visibility = '".TicketVisibilityFacts::FOR_EVERYONE."')",
            $condition->sql(),
        );
        self::assertSame(['tt_visible_org' => self::ACME_FOODS], $condition->parameters());
    }

    #[Test]
    #[DataProvider('aliasesThatAreNotPlainIdentifiers')]
    public function anAliasThatIsNotAPlainIdentifierIsRefused(string $alias): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A table alias must be a plain lower-case SQL identifier.');

        TicketVisibilitySql::of(TicketVisibility::for(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR), [], false, new UserId(self::SARA)), $alias);
    }

    #[Test]
    #[DataProvider('aliasesThatAreNotPlainIdentifiers')]
    public function theForEveryoneConditionRefusesAnAliasThatIsNotAPlainIdentifier(string $alias): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A table alias must be a plain lower-case SQL identifier.');

        TicketVisibilitySql::forEveryoneIn(new OrganizationId(self::ACME_FOODS), $alias);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function aliasesThatAreNotPlainIdentifiers(): iterable
    {
        yield 'empty' => [''];
        yield 'upper case' => ['T'];
        yield 'leading digit' => ['1t'];
        yield 'injection' => ['t.visibility OR true --'];
        yield 'quoted' => ['"t"'];
    }
}
