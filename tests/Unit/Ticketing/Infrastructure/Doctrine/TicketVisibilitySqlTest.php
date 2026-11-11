<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\Infrastructure\Doctrine;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketVisibility;
use App\Ticketing\Infrastructure\Doctrine\TicketVisibilitySql;
use Doctrine\DBAL\ParameterType;
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
                'tt_visible_is_admin' => true,
                'tt_visible_is_triager' => true,
            ],
            $condition->parameters(),
        );
        self::assertSame(
            [
                'tt_visible_org' => ParameterType::STRING,
                'tt_visible_teams' => ParameterType::STRING,
                'tt_visible_me' => ParameterType::STRING,
                'tt_visible_is_admin' => ParameterType::BOOLEAN,
                'tt_visible_is_triager' => ParameterType::BOOLEAN,
            ],
            $condition->types(),
        );
        foreach (array_keys($condition->parameters()) as $name) {
            self::assertStringContainsString(':'.$name.' ', $condition->sql());
        }
        self::assertStringStartsWith("(tt.organization_id = CAST(:tt_visible_org AS uuid) AND (tt.visibility = 'all' OR tt.team_id = ANY(", $condition->sql());
    }

    #[Test]
    public function aViewerInNoTeamGetsAnEmptyArray(): void
    {
        $condition = TicketVisibilitySql::of(TicketVisibility::for(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR), [], false, new UserId(self::SARA)), 't');

        self::assertSame(['{}', false, false], [$condition->parameters()['t_visible_teams'], $condition->parameters()['t_visible_is_admin'], $condition->parameters()['t_visible_is_triager']]);
    }

    #[Test]
    #[DataProvider('aliasesThatAreNotPlainIdentifiers')]
    public function anAliasThatIsNotAPlainIdentifierIsRefused(string $alias): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TicketVisibilitySql::of(TicketVisibility::for(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR), [], false, new UserId(self::SARA)), $alias);
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
