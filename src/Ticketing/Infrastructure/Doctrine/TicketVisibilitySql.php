<?php

declare(strict_types=1);

namespace App\Ticketing\Infrastructure\Doctrine;

use App\SharedKernel\Domain\TeamId;
use App\Ticketing\Api\TicketVisibility;
use Doctrine\DBAL\ParameterType;

/**
 * TicketVisibility as a SQL condition with named parameters, for a table that carries the predicate's columns
 * (tkt_ticket, ins_ticket_turn). The only place SQL may filter on visibility; HandWrittenVisibilityConditionTest
 * fails on any other.
 *
 * The condition is true for a visible row and false or NULL for a hidden one (a NULL people column makes the IN list
 * NULL): use it as a filter (WHERE, ON, FILTER), never negated.
 */
final readonly class TicketVisibilitySql
{
    private const string IDENTIFIER = '/^[a-z_][a-z0-9_]*$/';

    /**
     * @param array<string, string|bool>   $parameters
     * @param array<string, ParameterType> $types
     */
    private function __construct(private string $sql, private array $parameters, private array $types)
    {
    }

    /**
     * Parameter names start with the alias (:t_visible_me), so two uses on two aliases in one query do not collide.
     *
     * @param string $tableAlias the alias of the filtered table in the query, a plain lower-case SQL identifier
     *
     * @throws \InvalidArgumentException when the alias is not a plain lower-case SQL identifier
     */
    public static function of(TicketVisibility $visibility, string $tableAlias): self
    {
        if (1 !== preg_match(self::IDENTIFIER, $tableAlias)) {
            throw new \InvalidArgumentException('A table alias must be a plain lower-case SQL identifier.');
        }
        $prefix = $tableAlias.'_visible_';

        // pdo_pgsql binds no arrays, so the team ids travel as one uuid[] literal; a uuid holds no comma or brace.
        $teamIdsLiteral = '{'.implode(',', array_map(static fn (TeamId $teamId): string => $teamId->toString(), $visibility->teamIds())).'}';

        return new self(
            \sprintf(
                "(%1\$s.visibility = 'all'"
                .' OR %1$s.team_id = ANY(CAST(:%2$steams AS uuid[]))'
                .' OR CAST(:%2$sme AS uuid) IN (%1$s.reporter_id, %1$s.assignee_id, %1$s.pending_assignee_id,'
                .' %1$s.reviewer_id, %1$s.tester_id, %1$s.waiting_on_user_id)'
                .' OR CAST(:%2$sis_admin AS boolean)'
                .' OR (%1$s.team_id IS NULL AND CAST(:%2$sis_triager AS boolean)))',
                $tableAlias,
                $prefix,
            ),
            [
                $prefix.'teams' => $teamIdsLiteral,
                $prefix.'me' => $visibility->viewerId()->toString(),
                $prefix.'is_admin' => $visibility->isAdmin(),
                $prefix.'is_triager' => $visibility->isTriager(),
            ],
            [
                $prefix.'teams' => ParameterType::STRING,
                $prefix.'me' => ParameterType::STRING,
                $prefix.'is_admin' => ParameterType::BOOLEAN,
                $prefix.'is_triager' => ParameterType::BOOLEAN,
            ],
        );
    }

    public function sql(): string
    {
        return $this->sql;
    }

    /**
     * @return array<string, string|bool> by parameter name without the colon, to merge into the query's parameters
     */
    public function parameters(): array
    {
        return $this->parameters;
    }

    /**
     * @return array<string, ParameterType> by parameter name, to merge into the query's types
     */
    public function types(): array
    {
        return $this->types;
    }
}
