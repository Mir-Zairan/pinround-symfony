<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;

/**
 * TicketVisibility as a SQL condition with named parameters, for a table that carries the predicate's columns
 * (tkt_ticket, ins_ticket_turn), so every module that filters such a table uses this one rule (ADR-0013). The only
 * place SQL may filter on visibility; HandWrittenVisibilityConditionTest fails on any other. It keeps to the viewer's
 * organization by itself, besides row-level security.
 *
 * Every parameter is a string and the SQL casts each one, so a caller binds parameters() as they are, with no types.
 * The condition is true for a visible row and false or NULL for a hidden one (a NULL people column makes the IN list
 * NULL): use it as a filter (WHERE, ON, FILTER, CASE WHEN), never negated.
 */
final readonly class TicketVisibilitySql
{
    private const string IDENTIFIER = '/^[a-z_][a-z0-9_]*$/';

    /**
     * @param array<string, string> $parameters
     */
    private function __construct(private string $sql, private array $parameters)
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
        $prefix = self::parameterPrefixOf($tableAlias);

        // pdo_pgsql binds no arrays, so the team ids travel as one uuid[] literal; a uuid holds no comma or brace.
        $teamIdsLiteral = '{'.implode(',', array_map(static fn (TeamId $teamId): string => $teamId->toString(), $visibility->teamIds())).'}';

        return new self(
            \sprintf(
                '(%1$s.organization_id = CAST(:%2$sorg AS uuid)'
                ." AND (%1\$s.visibility = '%3\$s'"
                .' OR %1$s.team_id = ANY(CAST(:%2$steams AS uuid[]))'
                .' OR CAST(:%2$sme AS uuid) IN (%1$s.reporter_id, %1$s.assignee_id, %1$s.pending_assignee_id,'
                .' %1$s.reviewer_id, %1$s.tester_id, %1$s.waiting_on_user_id)'
                .' OR CAST(:%2$sis_admin AS boolean)'
                .' OR (%1$s.team_id IS NULL AND CAST(:%2$sis_triager AS boolean))))',
                $tableAlias,
                $prefix,
                TicketVisibilityFacts::FOR_EVERYONE,
            ),
            [
                $prefix.'org' => $visibility->organizationId()->toString(),
                $prefix.'teams' => $teamIdsLiteral,
                $prefix.'me' => $visibility->viewerId()->toString(),
                $prefix.'is_admin' => self::booleanLiteral($visibility->isAdmin()),
                $prefix.'is_triager' => self::booleanLiteral($visibility->isTriager()),
            ],
        );
    }

    /**
     * True only for a row of the organization that is for everyone, so it holds whoever reads the row: for text that
     * leaves the app with no viewer to check, such as a ticket title in an email (CASE WHEN ... THEN title END).
     *
     * @param string $tableAlias the alias of the filtered table in the query, a plain lower-case SQL identifier
     *
     * @throws \InvalidArgumentException when the alias is not a plain lower-case SQL identifier
     */
    public static function forEveryoneIn(OrganizationId $organizationId, string $tableAlias): self
    {
        $prefix = self::parameterPrefixOf($tableAlias);

        return new self(
            \sprintf(
                "(%1\$s.organization_id = CAST(:%2\$sorg AS uuid) AND %1\$s.visibility = '%3\$s')",
                $tableAlias,
                $prefix,
                TicketVisibilityFacts::FOR_EVERYONE,
            ),
            [$prefix.'org' => $organizationId->toString()],
        );
    }

    public function sql(): string
    {
        return $this->sql;
    }

    /**
     * @return array<string, string> by parameter name without the colon, to merge into the query's parameters
     */
    public function parameters(): array
    {
        return $this->parameters;
    }

    private static function parameterPrefixOf(string $tableAlias): string
    {
        if (1 !== preg_match(self::IDENTIFIER, $tableAlias)) {
            throw new \InvalidArgumentException('A table alias must be a plain lower-case SQL identifier.');
        }

        return $tableAlias.'_visible_';
    }

    private static function booleanLiteral(bool $value): string
    {
        return $value ? 'true' : 'false';
    }
}
